<?php
if (!defined('ABSPATH')) {
  exit;
}
add_action('wp_enqueue_scripts', 'enqueue_assets');
function enqueue_assets(): void
{
  enqueue_base_assets();
  enqueue_accordion_assets();
}

function enqueue_base_assets(): void
{
  wp_enqueue_style(
    'main-css',
    get_template_directory_uri() . '/public/css/app.min.css',
    [],
    THEME_VERSION,
    'all'
  );

  wp_enqueue_style(
    'style',
    get_stylesheet_uri(),
    [],
    THEME_VERSION,
    'all'
  );

  wp_enqueue_script(
    'main-js',
    get_template_directory_uri() . '/public/js/main.min.js',
    [],
    THEME_VERSION,
    true
  );
}

function enqueue_accordion_assets(): void
{
  if (!should_load_accordion_assets()) {
    return;
  }

  wp_enqueue_style(
    'accordioncss',
    CSS . '/accordion.css',
    [],
    THEME_VERSION,
    'all'
  );
  wp_enqueue_script(
    'jquerycustom',
    JS . '/jquery.min.js',
    [],
    THEME_VERSION,
    true
  );
  wp_enqueue_script(
    'accordionjs',
    JS . '/accordion.min.js',
    ['jquery', 'jquerycustom'],
    THEME_VERSION,
    true
  );
}

function should_load_accordion_assets(): bool
{
  $pages = [
    'our-services',
    'frequently-asked-questions',
    'faq',
    'careers',
    'insurance',
    'get-a-valuation',
    'ways-to-bid',
    'selling-at-auction',
  ];

  $post_types = [
    'vehicles',
    'auction',
    'model'
  ];

  return is_page($pages) || is_singular($post_types);
}