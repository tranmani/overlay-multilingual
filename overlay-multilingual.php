<?php
/**
 * Plugin Name:       Overlay Multilingual
 * Plugin URI:        https://github.com/tranmani/overlay-multilingual
 * Description:       Make a WordPress or WooCommerce site multilingual without duplicating content. Translations are layered over the original posts, products and terms, so there is one product, one stock level and one order flow in every language.
 * Version:           1.4.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Overlay Multilingual contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       overlay-multilingual
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'OVML_VERSION', '1.4.0' );
define( 'OVML_FILE', __FILE__ );
define( 'OVML_DIR', __DIR__ );
define( 'OVML_URL', plugin_dir_url( __FILE__ ) );

// Language detection must happen before WordPress routes the request and before
// text domains load. The optional must-use copy runs earliest; otherwise run now.
require_once OVML_DIR . '/early-loader.php';

require_once OVML_DIR . '/includes/functions.php';
require_once OVML_DIR . '/includes/class-polylang-data.php';
require_once OVML_DIR . '/includes/class-router.php';
require_once OVML_DIR . '/includes/class-content.php';
require_once OVML_DIR . '/includes/class-dictionary.php';
require_once OVML_DIR . '/includes/class-seo.php';
require_once OVML_DIR . '/includes/class-switcher.php';
require_once OVML_DIR . '/includes/class-detect.php';
require_once OVML_DIR . '/includes/class-updater.php';
require_once OVML_DIR . '/includes/class-ai.php';
require_once OVML_DIR . '/includes/integrations/woocommerce.php';
require_once OVML_DIR . '/includes/integrations/rank-math.php';
require_once OVML_DIR . '/includes/integrations/yoast.php';
require_once OVML_DIR . '/includes/integrations/elessi.php';
require_once OVML_DIR . '/includes/integrations/bravepop.php';

if ( is_admin() ) {
	require_once OVML_DIR . '/admin/class-admin.php';
	require_once OVML_DIR . '/admin/class-editor.php';
	require_once OVML_DIR . '/admin/class-ai-admin.php';
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once OVML_DIR . '/includes/class-cli.php';
}

add_action( 'plugins_loaded', static function () {
	load_plugin_textdomain( 'overlay-multilingual', false, dirname( plugin_basename( OVML_FILE ) ) . '/languages' );
	OverlayML\Router::init();
	OverlayML\Content::init();
	OverlayML\Dictionary::init();
	OverlayML\SEO::init();
	OverlayML\Switcher::init();
	OverlayML\Detect::init();
	OverlayML\Updater::init();
	OverlayML\Integrations\WooCommerce::init();
	OverlayML\Integrations\RankMath::init();
	OverlayML\Integrations\Yoast::init();
	OverlayML\Integrations\Elessi::init();
	OverlayML\Integrations\BravePop::init();
	if ( is_admin() ) {
		OverlayML\Admin\Admin::init();
		OverlayML\Admin\Editor::init();
		OverlayML\Admin\AI_Admin::init();
	}
}, 5 );

register_activation_hook( __FILE__, static function () {
	if ( false === get_option( 'ovml_settings' ) ) {
		$settings                = ovml_default_settings();
		$settings['preview_key'] = wp_generate_password( 24, false );
		add_option( 'ovml_settings', $settings, '', true );
	}
	ovml_install_early_loader();
} );

register_deactivation_hook( __FILE__, 'ovml_remove_early_loader' );
