<?php
/**
 * Gravity Forms Customizations
 * - Botón de envío con iconos personalizados
 * - Wrapper de UI para campo de carga de archivos
 *
 * @package HandH
 */
if (!defined('ABSPATH')) {
  exit;
}

// === Personalización del botón de envío ===
add_filter(
  'gform_submit_button',
  'hnh_custom_submit_button',
  10,
  2
);

// === Personalización del campo de carga de archivos ===
add_filter(
  'gform_field_content_3',
  'hnh_wrap_file_upload',
  10,
  5
);
add_filter(
  'gform_field_content_4',
  'hnh_wrap_gform_fileupload_field',
  10,
  5
);


function hnh_custom_submit_button(
  $button_html,
  $form
) {
  if (in_array((int) $form['id'], [2, 3, 4, 5], true)) {
    // Extrae attrs del input original
    preg_match('/id="([^"]+)"/', $button_html, $mId);
    preg_match('/class="([^"]+)"/', $button_html, $mClass);
    preg_match('/onclick="([^"]+)"/', $button_html, $mOnclick);
    preg_match('/value="([^"]+)"/', $button_html, $mValue);

    $id = $mId[1] ?? '';
    $class = $mClass[1] ?? 'gform_button button';
    $onclick = isset($mOnclick[1]) ? ' onclick="' . esc_attr($mOnclick[1]) . '"' : '';
    $label = $mValue[1] ?? __('Submit', 'gravityforms');

    // SVG (hereda color del texto)
    $svg = '<img src="' . IMG . '/arrow.png">';

    return sprintf(
      '<button type="submit" id="%s" class="%s custom-submit"%s>
                %s %s
            </button>',
      esc_attr($id),
      esc_attr($class . ' has-icon'),
      $onclick,
      esc_html($label),
      $svg
    );
  }

  if (in_array((int) $form['id'], [1], true)) {
    // Extrae attrs del input original
    preg_match('/id="([^"]+)"/', $button_html, $mId);
    preg_match('/class="([^"]+)"/', $button_html, $mClass);
    preg_match('/onclick="([^"]+)"/', $button_html, $mOnclick);
    preg_match('/value="([^"]+)"/', $button_html, $mValue);

    $id = $mId[1] ?? '';
    $class = $mClass[1] ?? 'gform_button button';
    $onclick = isset($mOnclick[1]) ? ' onclick="' . esc_attr($mOnclick[1]) . '"' : '';
    $label = $mValue[1] ?? __('Submit', 'gravityforms');

    // SVG (hereda color del texto)
    $svg = '<img src="' . IMG . '/arrow-brown.png" alt="arrow">';

    return sprintf(
      '<button type="submit" id="%s" class="%s custom-submit"%s>
                %s %s
            </button>',
      esc_attr($id),
      esc_attr($class . ' has-icon'),
      $onclick,
      esc_html($label),
      $svg
    );
  }

  // <- IMPORTANTÍSIMO: devolver el HTML original si no aplica
  return $button_html;
}

function hnh_wrap_file_upload(
  $content,
  $field,
  $value,
  $entry_id,
  $form_id
) {
  if ((int) $field->id === 8 && $field->type === 'fileupload' && !is_admin()) {
    return '<div class="my-filewrap">'
      . $content .
      '<img src="' . IMG . '/upload.png">
            <p>Drag and drop files here to upload, or click to select.</p>
            <span class="browse_file">Browse File</span>
        </div>';
  }
  return $content;
}

function hnh_wrap_gform_fileupload_field(
  $content,
  $field,
  $value,
  $entry_id,
  $form_id
) {
  if ((int) $field->id === 8 && $field->type === 'fileupload' && !is_admin()) {
    return '<div class="my-filewrap">'
      . $content .
      '<img src="' . IMG . '/upload.png">
            <p>Drag and drop files here to upload, or click to select.</p>
            <span class="browse_file">Browse File</span>
        </div>';
  }
  return $content;
}

/*add_filter('gform_field_content_8', function ($content, $field, $value, $entry_id, $form_id) {
    if ((int) $field->id === 21 && $field->type === 'fileupload') {
        return '<div class="my-filewrap">'
            . $content .
            '<img src="' . IMG . '/upload.png">
            <p>Drag and drop files here to upload, or click to select.</p>
            <span class="browse_file">Browse File</span>
        </div>';
    }
    return $content;
}, 10, 5);

add_filter('gform_field_content_10', function ($content, $field, $value, $entry_id, $form_id) {
    if ((int) $field->id === 21 && $field->type === 'fileupload') {
        return '<div class="my-filewrap">'
            . $content .
            '<img src="' . IMG . '/upload.png">
            <p>Drag and drop files here to upload, or click to select.</p>
            <span class="browse_file">Browse File</span>
        </div>';
    }
    return $content;
}, 10, 5);

add_filter('gform_field_content_11', function ($content, $field, $value, $entry_id, $form_id) {
    if ((int) $field->id === 21 && $field->type === 'fileupload') {
        return '<div class="my-filewrap">'
            . $content .
            '<img src="' . IMG . '/upload.png">
            <p>Drag and drop files here to upload, or click to select.</p>
            <span class="browse_file">Browse File</span>
        </div>';
    }
    return $content;
}, 10, 5);*/