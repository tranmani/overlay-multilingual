<?php
/**
 * WP-CLI: wp ovml status | import <file> | export [--file=<file>] | missing [--clear]
 *
 * Import/export use one JSON shape, convenient for translation agencies or
 * machine-translation pipelines:
 *   {"posts": {"<id>": {"fr": {"title": "...", ...}}},
 *    "terms": {"<id>": {"fr": {"name": "...", ...}}},
 *    "strings": {"fr": {"Source text": "Texte"}}}
 * Imports merge into existing translations.
 *
 * @package OverlayML
 */

namespace OverlayML;

defined( 'ABSPATH' ) || exit;

class CLI {

	/** Show status and translation coverage. */
	public function status() {
		global $wpdb;
		$s = ovml_settings();
		\WP_CLI::log( sprintf( 'Status: %s   Default: %s   Languages: %s', $s['status'], $s['default_language'], implode( ', ', array_keys( $s['languages'] ) ) ) );
		\WP_CLI::log( sprintf( 'Translated posts: %d   Translated terms: %d', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", OVML_META ) ), $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key = %s", OVML_META ) ) ) );
		foreach ( ovml_secondary_languages() as $lang ) {
			\WP_CLI::log( sprintf( 'Strings %s: %d', $lang, count( Dictionary::get( $lang ) ) ) );
		}
		\WP_CLI::log( 'Early loader: ' . ( file_exists( ovml_early_loader_path() ) ? 'installed' : 'missing' ) );
	}

	/**
	 * Import translations from a JSON file (merged into existing ones).
	 *
	 * ## OPTIONS
	 * <file>
	 */
	public function import( $args ) {
		$data = json_decode( (string) file_get_contents( $args[0] ), true );
		if ( ! is_array( $data ) ) {
			\WP_CLI::error( 'Not a valid JSON file.' );
		}
		$langs = ovml_secondary_languages();
		$keep  = static fn( $tr ) => array_intersect_key( (array) $tr, array_flip( $langs ) );
		$n     = [ 0, 0, 0 ];
		foreach ( (array) ( $data['posts'] ?? [] ) as $id => $tr ) {
			if ( get_post( (int) $id ) ) {
				update_post_meta( (int) $id, OVML_META, array_replace_recursive( (array) get_post_meta( (int) $id, OVML_META, true ), $keep( $tr ) ) );
				++$n[0];
			}
		}
		foreach ( (array) ( $data['terms'] ?? [] ) as $id => $tr ) {
			if ( get_term( (int) $id ) ) {
				update_term_meta( (int) $id, OVML_META, array_replace_recursive( (array) get_term_meta( (int) $id, OVML_META, true ), $keep( $tr ) ) );
				++$n[1];
			}
		}
		foreach ( $keep( $data['strings'] ?? [] ) as $lang => $pairs ) {
			$dict = Dictionary::get( $lang );
			foreach ( (array) $pairs as $source => $value ) {
				if ( '' !== (string) $value ) {
					$dict[ ovml_normalise( $source ) ] = (string) $value;
					++$n[2];
				}
			}
			Dictionary::save( $lang, $dict );
		}
		\WP_CLI::success( sprintf( 'Imported %d posts, %d terms, %d strings.', ...$n ) );
	}

	/**
	 * Export all translations as JSON.
	 *
	 * ## OPTIONS
	 * [--file=<file>]
	 * : Write to a file instead of stdout.
	 */
	public function export( $args, $assoc ) {
		global $wpdb;
		$out = [ 'posts' => [], 'terms' => [], 'strings' => [] ];
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT post_id id, meta_value v FROM {$wpdb->postmeta} WHERE meta_key = %s", OVML_META ) ) as $row ) {
			$out['posts'][ $row->id ] = maybe_unserialize( $row->v );
		}
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT term_id id, meta_value v FROM {$wpdb->termmeta} WHERE meta_key = %s", OVML_META ) ) as $row ) {
			$out['terms'][ $row->id ] = maybe_unserialize( $row->v );
		}
		foreach ( ovml_secondary_languages() as $lang ) {
			$out['strings'][ $lang ] = Dictionary::get( $lang );
		}
		$json = wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! empty( $assoc['file'] ) ) {
			file_put_contents( $assoc['file'], $json );
			\WP_CLI::success( 'Written to ' . $assoc['file'] );
			return;
		}
		\WP_CLI::line( $json );
	}

	/**
	 * Print phrases found by scans that have no translation yet.
	 *
	 * ## OPTIONS
	 * [--lang=<lang>]
	 * : Language to check (default: first secondary language).
	 *
	 * [--clear]
	 * : Empty the collected list afterwards.
	 */
	public function missing( $args, $assoc ) {
		$lang    = $assoc['lang'] ?? ( ovml_secondary_languages()[0] ?? '' );
		$dict    = Dictionary::get( $lang );
		$missing = array_values( array_filter( array_map( 'strval', array_keys( (array) get_option( 'ovml_missing', [] ) ) ), static fn( $k ) => empty( $dict[ $k ] ) ) );
		\WP_CLI::line( wp_json_encode( $missing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		if ( ! empty( $assoc['clear'] ) ) {
			delete_option( 'ovml_missing' );
		}
	}
}

\WP_CLI::add_command( 'ovml', CLI::class );
