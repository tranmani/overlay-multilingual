<?php
/**
 * Brave Popup Builder: translate popups.
 *
 * Brave loads popup markup over admin-ajax (bravepop_ajax_load_popup_content)
 * after the page has rendered, so it never passes through the page's output
 * buffer. The calling page's language is known (from the Referer, or from the
 * current_url Brave posts), so the AJAX response is run through the same
 * phrase dictionary and link rewriting as a page.
 *
 * @package OverlayML
 */

namespace OverlayML\Integrations;

use OverlayML\Dictionary;

defined( 'ABSPATH' ) || exit;

class BravePop {

	const ACTION = 'bravepop_ajax_load_popup_content';

	public static function init() {
		if ( ! ovml_enabled() ) {
			return;
		}
		add_action( 'wp_ajax_' . self::ACTION, [ __CLASS__, 'start' ], 0 );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, [ __CLASS__, 'start' ], 0 );
	}

	public static function start() {
		$lang = ovml_lang();
		if ( ovml_is_default( $lang ) ) {
			// No language from the Referer: fall back to the page URL Brave sends.
			$path  = (string) wp_parse_url( esc_url_raw( wp_unslash( $_POST['current_url'] ?? '' ) ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.NonceVerification -- Brave verifies its own nonce
			$codes = implode( '|', array_map( 'preg_quote', ovml_secondary_languages() ) );
			if ( $codes && preg_match( '#^/(' . $codes . ')(?=/|$)#', $path, $m ) ) {
				$lang = $m[1];
			}
		}
		if ( ovml_is_default( $lang ) ) {
			return;
		}
		ob_start( static fn( $html ) => Dictionary::translate_html( $html, $lang ) );
	}
}
