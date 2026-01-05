<?php

/**
 * HandH Theme Functions
 * @package WordPress
 * @subpackage HandH
 * @since 1.0.0
 * @version 1.0.0
 */

// === Constants ===
define('URL', get_stylesheet_directory_uri());
define('IMG', URL . '/images');
define('JS', URL . '/libraries/js');
define('CSS', URL . '/libraries/css');
define('NOT_APPEAR', false);
define('THEME_VERSION', '1.0.1');

// === Enqueue Styles and Scripts ===
require_once get_template_directory() . '/inc/assets.php';
// === Theme Support ===
require_once get_template_directory() . '/inc/theme-setup.php';

// === Template Tags ===
require_once get_template_directory() . '/inc/template-tags/banners.php';
require_once get_template_directory() . '/inc/template-tags/product-card.php';

// === Integrations ===
require_once get_template_directory() . '/inc/integrations/gravity-forms.php';
require_once get_template_directory() . '/inc/integrations/woocommerce-account-menu.php';

// === Modules ===
require_once get_template_directory() . '/inc/modules/auctions/cpt.php';
require_once get_template_directory() . '/inc/modules/auctions/import.php';
require_once get_template_directory() . '/inc/modules/auctions/structure.php';
require_once get_template_directory() . '/inc/modules/auctions/admin-columns.php';

require_once get_template_directory() . '/inc/modules/vehicles/cpt.php';
require_once get_template_directory() . '/inc/modules/vehicles/import.php';
require_once get_template_directory() . '/inc/modules/vehicles/structure.php';
require_once get_template_directory() . '/inc/modules/vehicles/admin-columns.php';
require_once get_template_directory() . '/inc/modules/vehicles/export.php';

require_once get_template_directory() . '/inc/modules/cpt_venues.php';
require_once get_template_directory() . '/inc/modules/cpt_models.php';
require_once get_template_directory() . '/inc/modules/cpt_testimonials.php';

require_once get_template_directory() . '/inc/hooks.php';
require_once get_template_directory() . '/inc/social_information.php';

require_once get_template_directory() . '/autocomplete_search_auction_date.php';

// TRACKING SYSTEM
require_once get_template_directory() . '/tracking/index.php';

// ADMIN 
require_once get_template_directory() . '/inc/admin/admin-ui.php';
require_once get_template_directory() . '/inc/admin/auction-search.php';

// -----------------------------------------------------------------------
require_once get_template_directory() . '/inc/admin/export-hide.php';
// FEATURES - MEMBER TEAM
require_once get_template_directory() . '/inc/features/member-team.php';
// FEED
require_once get_template_directory() . '/inc/feeds/vehicles-feed.php';

// LAST - LO DEJO PORSEACASO
function hnh_remove_editor_from_vehicle()
{
    remove_post_type_support('vehicle', 'editor');
}
add_action('init', 'hnh_remove_editor_from_vehicle');