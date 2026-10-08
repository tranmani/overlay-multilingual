<?php
/**
 * In-place translation of posts and terms.
 *
 * Only display values are filtered. Nothing here changes what is saved, so an
 * object updated during a secondary-language request (a stock change at
 * checkout, for example) still writes its original-language data.
 *
 * @package OverlayML
 */

namespace OverlayML;

defined( 'ABSPATH' ) || exit;

class Content {

	public static function init() {
		if ( ! ovml_enabled() ) {
			return;
		}
		add_filter( 'the_title', [ __CLASS__, 'title' ], 5, 2 );
		add_filter( 'single_post_title', [ __CLASS__, 'single_title' ], 5, 2 );
		add_filter( 'the_content', [ __CLASS__, 'content' ], 1 );
		add_filter( 'get_the_excerpt', [ __CLASS__, 'excerpt' ], 5, 2 );

		add_filter( 'get_term', [ __CLASS__, 'term' ], 5 );
		add_filter( 'get_terms', [ __CLASS__, 'terms' ], 5 );
		add_filter( 'get_the_terms', [ __CLASS__, 'terms' ], 5 );
		add_filter( 'wp_get_object_terms', [ __CLASS__, 'terms' ], 5 );
		add_filter( 'single_term_title', [ __CLASS__, 'single_term_title' ], 5 );
	}

	public static function title( $title, $id = 0 ) {
		return ovml_post_tr( $id, 'title' ) ?? $title;
	}

	public static function single_title( $title, $post ) {
		return ovml_post_tr( $post->ID ?? 0, 'title' ) ?? $title;
	}

	/**
	 * Replace the body only when the string being filtered is the current post's
	 * own raw content, so other text passed through the_content (widgets,
	 * builders) is left alone.
	 */
	public static function content( $content ) {
		$id = get_the_ID();
		if ( ! $id || ovml_is_default() ) {
			return $content;
		}
		$tr = ovml_post_tr( $id, 'content' );
		if ( null === $tr ) {
			return $content;
		}
		return trim( $content ) === trim( (string) get_post_field( 'post_content', $id, 'raw' ) ) ? $tr : $content;
	}

	public static function excerpt( $excerpt, $post = null ) {
		return ovml_post_tr( $post->ID ?? get_the_ID(), 'excerpt' ) ?? $excerpt;
	}

	public static function term( $term ) {
		if ( $term instanceof \WP_Term && ! ovml_is_default() ) {
			$term->name        = ovml_term_tr( $term->term_id, 'name' ) ?? $term->name;
			$term->description = ovml_term_tr( $term->term_id, 'description' ) ?? $term->description;
		}
		return $term;
	}

	public static function terms( $terms ) {
		if ( is_array( $terms ) && ! ovml_is_default() ) {
			foreach ( $terms as $i => $term ) {
				$terms[ $i ] = self::term( $term );
			}
		}
		return $terms;
	}

	public static function single_term_title( $title ) {
		$term = get_queried_object();
		return $term instanceof \WP_Term ? ( ovml_term_tr( $term->term_id, 'h1' ) ?? ovml_term_tr( $term->term_id, 'name' ) ?? $title ) : $title;
	}
}
