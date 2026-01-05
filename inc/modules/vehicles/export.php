<?php
if (!defined('ABSPATH')) {
  exit;
}

/**
 * Módulo de exportación de Vehicles (solo admin).
 *
 * La idea es simple: elegir un rango y descargar un XLSX sin reventar memoria/tiempo.
 * Lo dejamos en un solo archivo porque así está definido en este proyecto.
 */
final class Vehicles_Export_Module
{
  // ==================================================
  // Config
  // ==================================================
  private const POST_TYPE = 'vehicles';
  private const CAPABILITY = 'manage_options';
  private const MENU_SLUG = 'export-vehicles';

  private const NONCE_ACTION = 'vehicles_export_nonce';
  private const NONCE_FIELD = 'vehicles_export_nonce_f';

  private const DEFAULT_CHUNK_SIZE = 500;

  /**
   * Tope de seguridad: si alguien manipula el POST no dejamos exportar rangos absurdos.
   * La UI exporta por chunks, esto es el “cinturón de seguridad”.
   */
  private const MAX_EXPORT_ROWS = 10000;

  // Taxonomías usadas en export
  private const TAX_BRAND = 'vehicle_brand';
  private const TAX_CATEGORY = 'vehicle_category';

  /**
   * Estos meta keys guardan IDs de posts relacionados (ACF relationship/post_object).
   * Los usamos para resolver títulos sin hacer queries por cada fila.
   */
  private const RELATED_META_KEYS = [
    'contact_rep',
    'assigned_to',
    'model_vehicle',
  ];

  /**
   * Cache en memoria para títulos de relaciones.
   * Sin esto, cada fila dispara consultas y el export se vuelve lento (N+1).
   * @var array<int, string>
   */
  private static array $related_titles_cache = [];

  /**
   * Cache en memoria de términos: taxonomy => [term_id => name].
   * Sirve para traducir term_id a name en batch y no llamar get_term() por cada item.
   * @var array<string, array<int, string>>
   */
  private static array $term_name_cache = [];

  // ==================================================
  // Bootstrap
  // ==================================================
  public static function init(): void
  {
    add_action('admin_menu', [__CLASS__, 'register_menu']);
    add_action('admin_init', [__CLASS__, 'maybe_handle_export']);
  }

  // ==================================================
  // Hooks (Admin)
  // ==================================================
  public static function register_menu(): void
  {
    add_submenu_page(
      'edit.php?post_type=' . self::POST_TYPE,
      'Export Vehicles',
      'Export Vehicles',
      self::CAPABILITY,
      self::MENU_SLUG,
      [__CLASS__, 'render_page']
    );
  }

  /**
   * Ojo: Esto corre antes de renderizar la pantalla.
   * Si dejamos que WP imprima HTML, el XLSX se corrompe (por eso lo manejamos acá).
   */
  public static function maybe_handle_export(): void
  {
    if (!is_admin()) {
      return;
    }

    if (!current_user_can(self::CAPABILITY)) {
      return;
    }

    $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
    if (self::MENU_SLUG !== $page) {
      return;
    }

    if (empty($_POST['vehicles_export'])) {
      return;
    }

    // Seguridad: nonce obligatorio. Si falla, WordPress corta acá mismo (wp_die).
    check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);

    self::handle_export();
  }

  // ==================================================
  // UI
  // ==================================================
  public static function render_page(): void
  {
    $chunk_size = self::DEFAULT_CHUNK_SIZE;
    $total = self::count_total_posts();
    $ranges = self::build_ranges($total, $chunk_size);
    ?>
    <div class="wrap">
      <h1>Export Vehicles</h1>

      <p>
        This will export Vehicles to an Excel (.xlsx) file.<br>
        <strong>No gallery data included.</strong>
      </p>

      <form method="post" action="">
        <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
        <input type="hidden" name="vehicles_export" value="1">

        <table class="form-table" role="presentation">
          <tbody>
            <tr>
              <th scope="row">
                <label for="vehicles_export_range">Range</label>
              </th>
              <td>
                <select name="vehicles_export_range" id="vehicles_export_range">
                  <?php foreach ($ranges as $r): ?>
                    <option value="<?php echo esc_attr($r['start'] . '-' . $r['end']); ?>">
                      <?php echo esc_html($r['label']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <p class="description">
                  Exports in chunks of <?php echo esc_html($chunk_size); ?> rows.
                </p>
              </td>
            </tr>
          </tbody>
        </table>

        <p>
          <button class="button button-primary" type="submit">Export XLSX</button>
        </p>
      </form>
    </div>
    <?php
  }

  // =========================
  // Export (Command)
  // =========================
  private static function handle_export(): void
  {
    if (!current_user_can(self::CAPABILITY)) {
      wp_die('You do not have permission.');
    }

    // Queremos que el export no se muera por timeout (si el hosting lo permite).
    @set_time_limit(0);

    // Importantísimo: no puede salir nada antes del XLSX (ni espacios, ni warnings).
    while (ob_get_level()) {
      ob_end_clean();
    }

    self::require_composer_autoload();

    [$start, $end] = self::get_validated_range_from_request();

    $limit = ($end - $start) + 1;
    $offset = $start - 1;

    $ids = self::get_vehicle_ids_by_offset($offset, $limit);

    if (empty($ids)) {
      wp_die('No vehicles found in the selected range.');
    }

    /**
     * Rendimiento:
     * WP_Query ya dejó listo el cache de meta/terms.
     * Acá pre-cargamos lo "caro" para evitar el clásico N+1:
     * - títulos de posts relacionados
     * - nombres de términos cuando ACF guarda term_id(s) en meta
     */
    self::prime_related_titles_cache($ids, self::RELATED_META_KEYS);
    self::prime_term_name_cache_from_acf_tax_meta($ids, [
      ['meta_key' => 'artist_maker_brand', 'taxonomy' => self::TAX_BRAND],
    ]);

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Vehicles');

    $columns = self::columns();
    $sheet->fromArray(array_keys($columns), null, 'A1');

    $row_index = 2;
    foreach ($ids as $post_id) {
      $row = self::resolve_row((int) $post_id, $columns);
      $sheet->fromArray($row, null, 'A' . $row_index);
      $row_index++;
    }

    self::stream_xlsx($spreadsheet, $start, $end);
  }

  private static function stream_xlsx(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, int $start, int $end): void
  {
    // Desde acá en adelante NO se puede hacer echo/var_dump ni dejar warnings:
    // cualquier salida rompe el archivo y Excel lo marca como corrupto.
    nocache_headers();

    $filename = sprintf(
      'vehicles-%d-%d-%s.xlsx',
      $start,
      $end,
      gmdate('Y-m-d-His')
    );

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
  }

  // ==================================================
  // Queries
  // ==================================================
  private static function count_total_posts(): int
  {
    $count = wp_count_posts(self::POST_TYPE);
    return isset($count->publish) ? (int) $count->publish : 0;
  }

  /**
   * Trae IDs por offset/limit ordenados por ID.
   * Esto nos asegura que el "rango" sea consistente y repetible.
   */
  private static function get_vehicle_ids_by_offset(int $offset, int $limit): array
  {
    $q = new WP_Query([
      'post_type' => self::POST_TYPE,
      'post_status' => 'publish',
      'posts_per_page' => $limit,
      'offset' => $offset,
      'fields' => 'ids',
      'orderby' => 'ID',
      'order' => 'ASC',
      'no_found_rows' => true,
      'update_post_meta_cache' => true,
      'update_post_term_cache' => true,
    ]);

    return is_array($q->posts) ? $q->posts : [];
  }

  // ==================================================
  // Ranges (UI Helper)
  // ==================================================
  private static function build_ranges(int $total, int $chunk_size): array
  {
    if ($chunk_size <= 0) {
      $chunk_size = self::DEFAULT_CHUNK_SIZE;
    }

    if ($total <= 0) {
      return [
        [
          'start' => 1,
          'end' => 0,
          'label' => 'No vehicles found',
        ]
      ];
    }

    $ranges = [];
    $start = 1;

    while ($start <= $total) {
      $end = min($start + $chunk_size - 1, $total);
      $ranges[] = [
        'start' => $start,
        'end' => $end,
        'label' => "{$start} – {$end}",
      ];
      $start = $end + 1;
    }

    return $ranges;
  }

  private static function parse_range(string $raw): array
  {
    if ('' === $raw || false === strpos($raw, '-')) {
      return [0, 0];
    }

    [$a, $b] = array_map('trim', explode('-', $raw, 2));
    return [(int) $a, (int) $b];
  }

  /**
   * Normaliza el rango que llega por POST.
   * Si viene roto o manipulado, lo reacomodamos y además:
   * - lo limitamos con MAX_EXPORT_ROWS
   * - y lo ajustamos al total real publicado
   */
  private static function get_validated_range_from_request(): array
  {
    $range_raw = isset($_POST['vehicles_export_range'])
      ? sanitize_text_field(wp_unslash($_POST['vehicles_export_range']))
      : '';

    [$start, $end] = self::parse_range($range_raw);

    if ($start <= 0 || $end <= 0 || $end < $start) {
      $start = 1;
      $end = self::DEFAULT_CHUNK_SIZE;
    }

    $total = self::count_total_posts();
    if ($total > 0) {
      $end = min($end, $total);
    }

    $rows = ($end - $start) + 1;
    if ($rows > self::MAX_EXPORT_ROWS) {
      wp_die(sprintf('Maximum export size is %d rows. Please select a smaller range.', self::MAX_EXPORT_ROWS));
    }

    return [$start, $end];
  }

  // ==================================================
  // Columns (Single Source of Truth)
  // ==================================================

  /**
   * Mapa de columnas:
   * La key es el header del Excel y el value describe de dónde sale el dato.
   * Así evitamos lógica duplicada o columnas "hardcodeadas" por todos lados.
   */
  private static function columns(): array
  {
    return [
      'ID' => ['id'],

      'Title' => ['post_field', 'post_title'],
      'Slug' => ['post_field', 'post_name'],
      'WP Status' => ['post_field', 'post_status'],

      'Description' => ['meta', 'description', 'single_line' => true],

      'Auction Date Latest' => ['meta', 'auction_date_latest'],
      'Auction Number Latest' => ['meta', 'auction_number_latest'],
      'Lot Number Latest' => ['meta', 'lot_number_latest'],

      'Status' => ['meta', 'status'],
      'Contact Rep' => ['relation_title', 'contact_rep'],
      'Sold Price' => ['meta', 'sold_price'],
      'Assigned To' => ['relation_title', 'assigned_to'],

      'Footnote' => ['meta', 'footnote', 'single_line' => true],

      'Stock Number' => ['meta', 'stock_number'],
      'Estimate High' => ['meta', 'estimate_high'],
      'Estimate Low' => ['meta', 'estimate_low'],

      'Place to Bid' => ['meta', 'lot_link'],
      'Title (sub)' => ['meta', 'title_sub'],

      'Gallery - Type of Vehicle' => ['meta', 'type_of_vehicle'],
      'Gallery - Registration No' => ['meta', 'registration_no'],
      'Gallery - Chassis No' => ['meta', 'chassis_no'],
      'Gallery - MOT' => ['meta', 'mot'],

      'Makes' => ['tax', self::TAX_BRAND],

      'Vehicle - Make' => ['acf_tax_names', 'artist_maker_brand', self::TAX_BRAND],
      'Vehicle - Model' => ['relation_title', 'model_vehicle'],
      'Vehicle - Year' => ['meta', 'year_vehicle'],

      'Vehicle Categories' => ['tax', self::TAX_CATEGORY],
    ];
  }

  private static function resolve_row(int $post_id, array $columns): array
  {
    $row = [];
    foreach ($columns as $header => $descriptor) {
      $row[] = self::resolve_value($post_id, $descriptor);
    }
    return $row;
  }

  private static function resolve_value(int $post_id, array $descriptor): string
  {
    $type = $descriptor[0] ?? '';

    switch ($type) {
      case 'id':
        return (string) $post_id;

      case 'post_field':
        $field = $descriptor[1] ?? '';
        return $field ? (string) get_post_field($field, $post_id) : '';

      case 'meta':
        $key = $descriptor[1] ?? '';
        if (!$key) {
          return '';
        }
        $value = self::meta_string($post_id, $key);
        $single = (bool) ($descriptor['single_line'] ?? false);
        return $single ? self::to_single_line($value) : $value;

      case 'tax':
        $taxonomy = $descriptor[1] ?? '';
        return $taxonomy ? self::term_names($post_id, $taxonomy) : '';

      case 'relation_title':
        $meta_key = $descriptor[1] ?? '';
        return $meta_key ? self::related_title_from_meta($post_id, $meta_key) : '';

      case 'acf_tax_names':
        $meta_key = $descriptor[1] ?? '';
        $taxonomy = $descriptor[2] ?? '';
        return ($meta_key && $taxonomy) ? self::taxonomy_names_from_meta_cached($post_id, $meta_key, $taxonomy) : '';
    }

    return '';
  }

  // ==================================================
  // Helpers (Performance + claridad)
  // ==================================================
  private static function require_composer_autoload(): void
  {
    $autoload = get_stylesheet_directory() . '/vendor/autoload.php';
    if (file_exists($autoload)) {
      require_once $autoload;
      return;
    }

    wp_die('Composer autoload not found: ' . esc_html($autoload));
  }

  private static function meta_string(int $post_id, string $meta_key): string
  {
    $value = get_post_meta($post_id, $meta_key, true);
    return is_scalar($value) ? (string) $value : '';
  }

  private static function to_single_line(string $value): string
  {
    return str_replace(["\r\n", "\r", "\n"], ' ', $value);
  }

  /**
   * Nombres de términos asignados al post.
   * En general esto ya viene cacheado por WP_Query, así que es barato.
   */
  private static function term_names(int $post_id, string $taxonomy): string
  {
    $names = wp_get_post_terms($post_id, $taxonomy, ['fields' => 'names']);
    if (empty($names) || is_wp_error($names)) {
      return '';
    }
    return implode(', ', $names);
  }

  /**
   * Resuelve el título de la relación usando cache.
   * Si no está en cache (caso raro), cae al get_post_field como fallback.
   */
  private static function related_title_from_meta(int $post_id, string $meta_key): string
  {
    $related_id = (int) get_post_meta($post_id, $meta_key, true);
    if ($related_id <= 0) {
      return '';
    }
    return self::$related_titles_cache[$related_id] ?? (string) get_post_field('post_title', $related_id);
  }

  /**
   * ACF a veces guarda taxonomías como term_id(s) en post_meta.
   * Acá los convertimos a nombres usando cache para no hacer queries por fila.
   */
  private static function taxonomy_names_from_meta_cached(int $post_id, string $meta_key, string $taxonomy): string
  {
    $value = get_post_meta($post_id, $meta_key, true);
    $term_ids = self::normalize_term_ids($value);

    if (empty($term_ids)) {
      return '';
    }

    $cache = self::$term_name_cache[$taxonomy] ?? [];
    $names = [];

    foreach ($term_ids as $term_id) {
      if (isset($cache[$term_id])) {
        $names[] = $cache[$term_id];
      }
    }

    $names = array_filter(array_unique($names));
    return implode(', ', $names);
  }

  private static function normalize_term_ids($value): array
  {
    if (empty($value)) {
      return [];
    }

    if (is_string($value) && is_serialized($value)) {
      $value = maybe_unserialize($value);
    }

    $items = is_array($value) ? $value : [$value];

    $ids = [];
    foreach ($items as $item) {
      if (is_numeric($item)) {
        $ids[] = (int) $item;
        continue;
      }
      if (is_array($item) && isset($item['term_id']) && is_numeric($item['term_id'])) {
        $ids[] = (int) $item['term_id'];
      }
    }

    $ids = array_filter(array_unique($ids));
    return array_values($ids);
  }

  // ==================================================
  // Priming caches (evita N+1)
  // ==================================================

  /**
   * Pre-cargamos títulos de posts relacionados en un solo batch.
   * La meta ya está cacheada, así que juntamos IDs y primeamos posts una vez.
   * Resultado: export rápido y sin N+1.
   */
  private static function prime_related_titles_cache(array $post_ids, array $meta_keys): void
  {
    $related_ids = [];

    foreach ($post_ids as $post_id) {
      foreach ($meta_keys as $key) {
        $related_id = (int) get_post_meta((int) $post_id, (string) $key, true);
        if ($related_id > 0) {
          $related_ids[$related_id] = true;
        }
      }
    }

    $related_ids = array_keys($related_ids);
    if (empty($related_ids)) {
      return;
    }

    // Prime post object cache para esos IDs
    _prime_post_caches($related_ids, false, false);

    foreach ($related_ids as $rid) {
      self::$related_titles_cache[(int) $rid] = (string) get_post_field('post_title', (int) $rid);
    }
  }

  /**
   * Pre-cargamos nombres de términos para los term_id que ACF guardó en meta.
   * Hacemos una consulta por taxonomía (en lugar de una por fila).
   */
  private static function prime_term_name_cache_from_acf_tax_meta(array $post_ids, array $targets): void
  {
    foreach ($targets as $t) {
      $meta_key = $t['meta_key'] ?? '';
      $taxonomy = $t['taxonomy'] ?? '';

      if (!$meta_key || !$taxonomy) {
        continue;
      }

      $term_ids = [];

      foreach ($post_ids as $post_id) {
        $value = get_post_meta((int) $post_id, (string) $meta_key, true);
        foreach (self::normalize_term_ids($value) as $tid) {
          $term_ids[$tid] = true;
        }
      }

      $term_ids = array_keys($term_ids);
      if (empty($term_ids)) {
        continue;
      }

      $terms = get_terms([
        'taxonomy' => $taxonomy,
        'include' => $term_ids,
        'hide_empty' => false,
      ]);

      if (is_wp_error($terms) || empty($terms)) {
        continue;
      }

      foreach ($terms as $term) {
        if ($term instanceof WP_Term) {
          self::$term_name_cache[$taxonomy][(int) $term->term_id] = (string) $term->name;
        }
      }
    }
  }
}

Vehicles_Export_Module::init();