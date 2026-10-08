<?php
/**
 * "Separate posts per language" mode, using Polylang's data model.
 *
 * Some content (typically blog articles) is better written per language than
 * translated in place. For the post types listed in the `separate_posts`
 * setting, each post carries a language (a `language` term whose slug is the
 * language code) and posts are linked through a `post_translations` term whose
 * description is a serialized [lang => post_id] map — exactly how Polylang
 * stores it, so sites migrating from Polylang keep their data.
 *
 * @package OverlayML
 */

namespace OverlayML;

defined( 'ABSPATH' ) || exit;

class PolylangData {

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register' ], 5 );
	}

	/** Register the taxonomies privately when Polylang itself is not active. */
	public static function register() {
		$types = (array) ovml_settings()['separate_posts'];
		foreach ( [ 'language', 'post_translations' ] as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) && $types ) {
				register_taxonomy( $taxonomy, $types, [
					'public'       => false,
					'rewrite'      => false,
					'query_var'    => false,
					'show_ui'      => false,
					'show_in_rest' => false,
				] );
			}
		}
	}

	public static function is_separate( $post ) {
		$post = get_post( $post );
		return $post && in_array( $post->post_type, (array) ovml_settings()['separate_posts'], true );
	}

	/** Language code of a separate-mode post ('' when it has none). */
	public static function language( $post_id ) {
		static $cache = [];
		$post_id = (int) $post_id;
		if ( ! isset( $cache[ $post_id ] ) ) {
			global $wpdb;
			$cache[ $post_id ] = (string) $wpdb->get_var( $wpdb->prepare(
				"SELECT t.slug FROM {$wpdb->term_relationships} r
				 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = r.term_taxonomy_id AND tt.taxonomy = 'language'
				 JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
				 WHERE r.object_id = %d LIMIT 1",
				$post_id
			) );
		}
		return $cache[ $post_id ];
	}

	/** Language of a separate-mode post, falling back to the default language. */
	public static function language_or_default( $post_id ) {
		$lang = self::language( $post_id );
		return isset( ovml_languages()[ $lang ] ) ? $lang : ovml_default_language();
	}

	/** Published members of a post's translation group: [lang => post_id]. */
	public static function group( $post_id ) {
		global $wpdb;
		$map = maybe_unserialize( $wpdb->get_var( $wpdb->prepare(
			"SELECT tt.description FROM {$wpdb->term_relationships} r
			 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = r.term_taxonomy_id AND tt.taxonomy = 'post_translations'
			 WHERE r.object_id = %d LIMIT 1",
			$post_id
		) ) );
		$out = [];
		foreach ( is_array( $map ) ? $map : [] as $lang => $id ) {
			if ( isset( ovml_languages()[ $lang ] ) && 'publish' === get_post_status( $id ) ) {
				$out[ $lang ] = (int) $id;
			}
		}
		if ( ! $out && 'publish' === get_post_status( $post_id ) ) {
			$out[ self::language_or_default( $post_id ) ] = (int) $post_id;
		}
		return $out;
	}

	/** IDs of published posts of a type in a language (untagged posts count as default). */
	public static function ids_in( $lang, $post_type = 'post' ) {
		static $cache = [];
		$key = "$post_type:$lang";
		if ( ! isset( $cache[ $key ] ) ) {
			global $wpdb;
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT p.ID id, COALESCE(t.slug, '') lang FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->term_relationships} r ON r.object_id = p.ID
				   AND r.term_taxonomy_id IN (SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'language')
				 LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = r.term_taxonomy_id
				 LEFT JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
				 WHERE p.post_type = %s AND p.post_status = 'publish'",
				$post_type
			) );
			$ids = [];
			foreach ( $rows as $row ) {
				$row_lang = isset( ovml_languages()[ $row->lang ] ) ? $row->lang : ovml_default_language();
				if ( $row_lang === $lang ) {
					$ids[] = (int) $row->id;
				}
			}
			$cache[ $key ] = $ids ?: [ 0 ];
		}
		return $cache[ $key ];
	}

	/** Set a post's language and translation links (used by the editor and CLI). */
	public static function save( $post_id, $lang, array $links ) {
		self::register();
		if ( ! term_exists( $lang, 'language' ) ) {
			wp_insert_term( $lang, 'language', [ 'slug' => $lang ] );
		}
		wp_set_object_terms( $post_id, $lang, 'language' );

		$group = array_filter( array_map( 'intval', $links ) );
		$group[ $lang ] = (int) $post_id;
		$existing       = wp_get_object_terms( $post_id, 'post_translations' );
		if ( $existing && ! is_wp_error( $existing ) ) {
			$term = $existing[0];
		} else {
			$name = 'pll_' . uniqid();
			$new  = wp_insert_term( $name, 'post_translations', [ 'slug' => $name ] );
			if ( is_wp_error( $new ) ) {
				return;
			}
			$term = get_term( $new['term_id'], 'post_translations' );
		}
		wp_update_term( $term->term_id, 'post_translations', [ 'description' => serialize( $group ) ] );
		foreach ( $group as $id ) {
			wp_set_object_terms( $id, [ (int) $term->term_id ], 'post_translations' );
		}
	}
}

PolylangData::init();
