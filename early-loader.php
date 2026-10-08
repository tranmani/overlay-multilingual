<?php
/**
 * Early request handling: settings, status gate, language detection, locale.
 *
 * Loaded by a must-use stub (installed on activation) so it runs before regular
 * plugins call any translation function, or by the main plugin file as a
 * fallback. A secondary-language URL prefix ("/fr/…") is stripped from
 * REQUEST_URI, so WordPress routes the request exactly like the default-language
 * URL, and the locale switches so core, plugins and theme load their language
 * packs.
 *
 * @package OverlayML
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'OVML_EARLY_LOADED' ) ) {
	return;
}
define( 'OVML_EARLY_LOADED', true );

function ovml_default_settings() {
	return [
		'status'           => 'off', // off | preview | live
		'preview_key'      => '', // generated on activation; pluggable functions are not loaded this early
		'default_language' => 'en',
		'languages'        => [
			'en' => [ 'locale' => 'en_US', 'name' => 'English', 'og_locale' => 'en_US', 'replace' => [] ],
		],
		'post_types'       => [ 'page', 'product' ],
		'taxonomies'       => [ 'product_cat', 'product_tag' ],
		'separate_posts'   => [], // post types stored as one post per language (Polylang data model)
		'detect_language'  => false, // send first-time visitors to their device language
		'detect_scope'     => 'any', // any | home
		'cookie_days'      => 365, // how long the visitor's language is remembered
		'preview_badge'    => true, // small front-end badge while previewing
		'switcher'         => [
			'placement'    => 'none', // none | hook
			'hook'         => '',
			'priority'     => 10,
			'style'        => 'dropdown', // dropdown | inline | theme
			'label'        => 'name', // name | code | both
			'untranslated' => 'show', // show | home | hide — languages the current page is not translated into
			'hide_current' => false,
		],
		'seo'              => [
			'hreflang'             => true,
			'x_default'            => true,
			'noindex_untranslated' => true,
			'sitemap'              => true,
			'title_format'         => '%title% - %site%',
		],
		'woocommerce'      => [
			'emails'        => true, // customer emails in the order's language
			'default_items' => true, // store order lines in the default language
		],
		'advanced'         => [
			'translate_attributes' => true, // alt, title, placeholder, aria-label
			'excluded_paths'       => '', // one path prefix per line, never translated
		],
	];
}

/** Settings merged over defaults. Cached per request. */
function ovml_settings( $refresh = false ) {
	static $settings = null;
	if ( null === $settings || $refresh ) {
		$saved    = get_option( 'ovml_settings', [] );
		$settings = array_replace_recursive( ovml_default_settings(), is_array( $saved ) ? $saved : [] );
		// Languages are a list, not something to merge into the defaults.
		if ( ! empty( $saved['languages'] ) ) {
			$settings['languages'] = $saved['languages'];
		}
	}
	return $settings;
}

/** @return array<string, array> Languages keyed by URL code, default first. */
function ovml_languages() {
	return ovml_settings()['languages'];
}

function ovml_default_language() {
	return ovml_settings()['default_language'];
}

/** Secondary language codes (the ones that get a URL prefix). */
function ovml_secondary_languages() {
	return array_values( array_diff( array_keys( ovml_languages() ), [ ovml_default_language() ] ) );
}

/**
 * Whether translations are served on this request: always when live; in preview
 * mode only for a browser holding the preview cookie (set by visiting any URL
 * with ?ovml_preview=KEY, cleared with ?ovml_preview=off).
 */
function ovml_enabled() {
	static $enabled = null;
	if ( null !== $enabled ) {
		return $enabled;
	}
	$settings = ovml_settings();
	if ( 'live' === $settings['status'] ) {
		return $enabled = true;
	}
	if ( 'preview' !== $settings['status'] || '' === (string) $settings['preview_key'] ) {
		return $enabled = false;
	}
	$key = (string) $settings['preview_key'];
	if ( isset( $_GET['ovml_preview'] ) ) {
		$value = sanitize_text_field( wp_unslash( $_GET['ovml_preview'] ) );
		if ( 'off' === $value ) {
			setcookie( 'ovml_preview', '', time() - HOUR_IN_SECONDS, '/', '', is_ssl(), true );
			unset( $_COOKIE['ovml_preview'] );
		} elseif ( hash_equals( $key, $value ) ) {
			setcookie( 'ovml_preview', $key, time() + 30 * DAY_IN_SECONDS, '/', '', is_ssl(), true );
			$_COOKIE['ovml_preview'] = $key;
		}
	}
	return $enabled = isset( $_COOKIE['ovml_preview'] ) && hash_equals( $key, (string) $_COOKIE['ovml_preview'] );
}

function ovml_is_preview() {
	return ovml_enabled() && 'live' !== ovml_settings()['status'];
}

/** Language of the current request. */
function ovml_lang() {
	return $GLOBALS['ovml_lang'] ?? ovml_default_language();
}

/** Language used for translating output (emails may override the request language). */
function ovml_tlang() {
	return $GLOBALS['ovml_tlang'] ?? ovml_lang();
}

function ovml_is_default( $lang = null ) {
	return ( $lang ?? ovml_tlang() ) === ovml_default_language();
}

function ovml_locale( $lang ) {
	return ovml_languages()[ $lang ]['locale'] ?? get_option( 'WPLANG' ) ?: 'en_US';
}

$GLOBALS['ovml_lang'] = ovml_default_language();

if ( ovml_enabled() && ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! wp_doing_cron() && ovml_secondary_languages() ) {
	$ovml_codes = implode( '|', array_map( 'preg_quote', ovml_secondary_languages() ) );
	$ovml_uri   = $_SERVER['REQUEST_URI'] ?? '/';

	if ( ! is_admin() && preg_match( '#^/(' . $ovml_codes . ')(?=/|\?|$)#', $ovml_uri, $ovml_m ) ) {
		$GLOBALS['ovml_lang']         = $ovml_m[1];
		$_SERVER['OVML_ORIGINAL_URI'] = $ovml_uri;
		$ovml_rest                    = (string) substr( $ovml_uri, strlen( $ovml_m[0] ) );
		$_SERVER['REQUEST_URI']       = ( '' === $ovml_rest || '?' === $ovml_rest[0] ) ? '/' . $ovml_rest : $ovml_rest;
	} elseif ( wp_doing_ajax() || isset( $_GET['wc-ajax'] ) ) {
		// AJAX endpoints carry no prefix of their own: use the calling page's language.
		$ovml_ref = (string) wp_parse_url( $_SERVER['HTTP_REFERER'] ?? '', PHP_URL_PATH );
		if ( preg_match( '#^/(' . $ovml_codes . ')(?=/|$)#', $ovml_ref, $ovml_m ) ) {
			$GLOBALS['ovml_lang'] = $ovml_m[1];
		}
	}

	if ( ! ovml_is_default( ovml_lang() ) ) {
		$ovml_locale = static fn() => ovml_locale( ovml_lang() );
		add_filter( 'locale', $ovml_locale, 1 );
		add_filter( 'determine_locale', $ovml_locale, 1 );
	}

	// Preview responses must never be stored by a CDN and served to the public.
	if ( ovml_is_preview() ) {
		add_action( 'send_headers', static function () {
			header( 'Cache-Control: no-store, private', true );
			header( 'CDN-Cache-Control: no-store' );
			header( 'Cloudflare-CDN-Cache-Control: no-store' );
		} );
	}
}

/* ------------------------------------------------- must-use stub install -- */

function ovml_early_loader_path() {
	return WPMU_PLUGIN_DIR . '/overlay-multilingual-early.php';
}

function ovml_install_early_loader() {
	$stub = "<?php\n// Created by Overlay Multilingual: runs its language detection before other plugins load.\n"
		. "if ( file_exists( WP_PLUGIN_DIR . '/overlay-multilingual/early-loader.php' ) ) {\n\trequire_once WP_PLUGIN_DIR . '/overlay-multilingual/early-loader.php';\n}\n";
	if ( ! is_dir( WPMU_PLUGIN_DIR ) ) {
		wp_mkdir_p( WPMU_PLUGIN_DIR );
	}
	return is_writable( WPMU_PLUGIN_DIR ) && false !== file_put_contents( ovml_early_loader_path(), $stub );
}

function ovml_remove_early_loader() {
	if ( file_exists( ovml_early_loader_path() ) ) {
		wp_delete_file( ovml_early_loader_path() );
	}
}
