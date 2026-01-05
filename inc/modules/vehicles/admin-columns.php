<?php
if (!defined('ABSPATH')) {
  exit;
}

add_filter(
  'manage_vehicles_posts_columns',
  function ($cols) {
    $new = [];
    foreach ($cols as $k => $v) {
      $new[$k] = $v;
      if ($k === 'title') {
        $new['auction_date_latest'] = __('Auction Date', 'textdomain');
      }
    }
    return $new;
  }
);

add_action(
  'manage_vehicles_posts_custom_column',
  function ($col, $post_id) {
    if ($col !== 'auction_date_latest')
      return;
    $raw = get_post_meta($post_id, 'auction_date_latest', true); // ACF/meta key
    echo $raw ? esc_html($raw) : '—';
  },
  10,
  2
);

/**
 * Orden por defecto y ordenación desde cabecera para Vehicles
 * Campo ACF: auction_date_latest (formato 'Y-m-d H:i:s' o 'Y-m-d H:i')
 */
add_action(
  'pre_get_posts',
  function ($query) {
    if (!is_admin() || !$query->is_main_query())
      return;

    // Solo en el listado del CPT vehicles
    if ($query->get('post_type') !== 'vehicles')
      return;

    // 1) Orden por defecto (cuando no se pide otro orderby)
    if (!isset($_GET['orderby'])) {
      $query->set('meta_key', 'auction_date_latest');
      $query->set('meta_type', 'DATETIME');     // fuerza CAST para ordenar bien
      $query->set('orderby', 'meta_value');     // ordena por el meta (string/datetime)
      $query->set('order', 'DESC');             // más reciente primero
      return;
    }

    // 2) Si el usuario hace clic en la columna "Auction Date" (ver filtro de columnas abajo)
    if (isset($_GET['orderby']) && $_GET['orderby'] === 'auction_date_latest') {
      $query->set('meta_key', 'auction_date_latest');
      $query->set('meta_type', 'DATETIME');
      $query->set('orderby', 'meta_value');
      // respeta el parámetro &order=ASC|DESC que manda WP al hacer clic
    }
  }
);

/**
 * Hacer la columna de fecha de subasta "sortable" (si tienes una columna para ello)
 * Cambia 'auction_date_latest' por el ID de la columna que uses en el listado.
 */
add_filter(
  'manage_edit-vehicles_sortable_columns',
  function ($columns) {
    // clave = ID de la columna; valor = 'orderby' que envia WP
    $columns['auction_date_latest'] = 'auction_date_latest';
    return $columns;
  }
);