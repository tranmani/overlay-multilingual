<?php
/**
 * Public API and shared helpers.
 *
 * Translations are stored beside the original object:
 *   post meta `_ovml` and term meta `_ovml`, shaped [lang => [field => text]].
 * Post fields: title, content, excerpt, seo_title, seo_desc.
 * Term fields: name, description, h1, seo_title, seo_desc.
 *
 * @package OverlayML
 */

defined( 'ABSPATH' ) || exit;

const OVML_META = '_ovml';

/* ------------------------------------------------------------------ URLs -- */

/** Site root without a language prefix (reads the option, so no filters apply). */
function ovml_root() {
	return untrailingslashit( (string) get_option( 'home' ) );
}

/** Paths that never get a language prefix: admin, APIs, feeds and files. */
function ovml_skip_path( $path ) {
	$skip = (bool) preg_match( '#^/(wp-admin|wp-login\.php|wp-json|wp-content|wp-includes|wp-cron\.php|xmlrpc\.php|[^?]*/feed/?(\?|$)|[^?]*\.(xml|txt|xsl|png|jpe?g|gif|webp|avif|svg|ico|css|js|map|pdf|zip|woff2?))#i', $path );
	if ( ! $skip ) {
		foreach ( preg_split( '/\r\n|\n/', (string) ( ovml_settings()['advanced']['excluded_paths'] ?? '' ) ) as $prefix ) {
			$prefix = trim( $prefix );
			if ( '' !== $prefix && 0 === strpos( $path, '/' . ltrim( $prefix, '/' ) ) ) {
				$skip = true;
				break;
			}
		}
	}
	/**
	 * Filters whether a path is excluded from language prefixes.
	 *
	 * @param bool   $skip
	 * @param string $path Path including query string.
	 */
	return (bool) apply_filters( 'ovml_skip_path', $skip, $path );
}

/**
 * Convert a URL on this site to the given language. Off-site URLs and file
 * URLs are returned unchanged.
 */
function ovml_url( $url, $lang ) {
	$root = ovml_root();
	$host = (string) wp_parse_url( $root, PHP_URL_HOST );
	$url  = preg_replace( '#^(https?:)?//' . preg_quote( $host, '#' ) . '(?=/|\?|\#|$)#i', $root, (string) $url );
	if ( 0 !== strpos( $url, $root ) ) {
		return $url;
	}
	$path  = (string) substr( $url, strlen( $root ) );
	$codes = implode( '|', array_map( 'preg_quote', ovml_secondary_languages() ) );
	if ( '' === $path || in_array( $path[0], [ '?', '#' ], true ) ) {
		$path = '/' . $path;
	}
	if ( $codes ) {
		$path = preg_replace( '#^/(' . $codes . ')(?=/|\?|\#|$)#', '', $path );
	}
	if ( '' === $path || in_array( $path[0], [ '?', '#' ], true ) ) {
		$path = '/' . $path;
	}
	if ( ovml_skip_path( $path ) || ovml_is_default( $lang ) || ! isset( ovml_languages()[ $lang ] ) ) {
		return $root . $path;
	}
	return $root . '/' . $lang . $path;
}

/** The URL the visitor requested, including its language prefix. */
function ovml_current_url() {
	return ovml_root() . ( $_SERVER['OVML_ORIGINAL_URI'] ?? ( $_SERVER['REQUEST_URI'] ?? '/' ) );
}

/** Current URL without query arguments that should not travel between languages. */
function ovml_clean_url( $url ) {
	$drop = apply_filters( 'ovml_switcher_drop_query_args', [ 'ovml_preview', 'ovml_collect', 'add-to-cart', '_wpnonce' ] );
	return remove_query_arg( $drop, $url );
}

/** [lang => url] of the current page in every language (used by the switcher). */
function ovml_language_urls() {
	return OverlayML\Switcher::urls();
}

/** Print the language switcher anywhere (template tag). */
function ovml_switcher( $args = [] ) {
	echo OverlayML\Switcher::render( $args ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in render().
}

/* ---------------------------------------------------------- translations -- */

function ovml_translatable_post_type( $post_type ) {
	return in_array( $post_type, (array) ovml_settings()['post_types'], true );
}

function ovml_translatable_taxonomies() {
	$taxonomies = (array) ovml_settings()['taxonomies'];
	if ( ovml_translatable_post_type( 'product' ) && function_exists( 'wc_get_attribute_taxonomy_names' ) ) {
		$taxonomies = array_merge( $taxonomies, wc_get_attribute_taxonomy_names() );
	}
	return array_values( array_unique( $taxonomies ) );
}

function ovml_post_tr( $post_id, $field, $lang = null ) {
	$lang = $lang ?? ovml_tlang();
	if ( ovml_is_default( $lang ) || ! $post_id ) {
		return null;
	}
	$tr    = get_post_meta( (int) $post_id, OVML_META, true );
	$value = is_array( $tr ) ? ( $tr[ $lang ][ $field ] ?? '' ) : '';
	return '' === $value ? null : ovml_replace_chars( $value, $lang );
}

function ovml_term_tr( $term_id, $field, $lang = null ) {
	$lang = $lang ?? ovml_tlang();
	if ( ovml_is_default( $lang ) || ! $term_id ) {
		return null;
	}
	$tr    = get_term_meta( (int) $term_id, OVML_META, true );
	$value = is_array( $tr ) ? ( $tr[ $lang ][ $field ] ?? '' ) : '';
	return '' === $value ? null : ovml_replace_chars( $value, $lang );
}

/** Whether a post or term has a translation in a language. */
function ovml_has_tr( $object, $lang ) {
	if ( ovml_is_default( $lang ) ) {
		return true;
	}
	if ( $object instanceof WP_Term ) {
		return null !== ovml_term_tr( $object->term_id, 'name', $lang );
	}
	if ( $object instanceof WP_Post ) {
		if ( OverlayML\PolylangData::is_separate( $object ) ) {
			return isset( OverlayML\PolylangData::group( $object->ID )[ $lang ] );
		}
		return null !== ovml_post_tr( $object->ID, 'title', $lang );
	}
	return false;
}

/** Per-language character replacements (e.g. ß → ss for Swiss German). */
function ovml_replace_chars( $text, $lang ) {
	$map = ovml_languages()[ $lang ]['replace'] ?? [];
	return $map ? strtr( (string) $text, $map ) : $text;
}

/* ------------------------------------------------------------ dictionary -- */

function ovml_normalise( $text ) {
	return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
}

/** Translate a phrase through the dictionary; unknown phrases are returned unchanged. */
function ovml_t( $text, $lang = null ) {
	$lang = $lang ?? ovml_tlang();
	if ( ovml_is_default( $lang ) || ! is_string( $text ) ) {
		return $text;
	}
	$dict = OverlayML\Dictionary::get( $lang );
	return ovml_replace_chars( $dict[ ovml_normalise( $text ) ] ?? $text, $lang );
}
