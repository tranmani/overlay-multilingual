<?php
/**
 * Translation panels on post, product and term edit screens, plus the
 * language/links box for content written per language.
 *
 * @package OverlayML
 */

namespace OverlayML\Admin;

use OverlayML\PolylangData;

defined( 'ABSPATH' ) || exit;

class Editor {

	const POST_FIELDS = [
		'title'     => [ 'type' => 'text' ],
		'excerpt'   => [ 'type' => 'textarea', 'rows' => 3 ],
		'content'   => [ 'type' => 'code', 'rows' => 10 ],
		'seo_title' => [ 'type' => 'text', 'max' => 60 ],
		'seo_desc'  => [ 'type' => 'textarea', 'rows' => 2, 'max' => 160 ],
	];

	const TERM_FIELDS = [
		'name'        => [ 'type' => 'text' ],
		'h1'          => [ 'type' => 'text' ],
		'description' => [ 'type' => 'code', 'rows' => 6 ],
		'seo_title'   => [ 'type' => 'text', 'max' => 60 ],
		'seo_desc'    => [ 'type' => 'textarea', 'rows' => 2, 'max' => 160 ],
	];

	public static function init() {
		add_action( 'add_meta_boxes', [ __CLASS__, 'boxes' ], 10, 2 );
		add_action( 'save_post', [ __CLASS__, 'save_post' ], 10, 2 );
		add_action( 'admin_init', [ __CLASS__, 'term_hooks' ] );
	}

	private static function labels() {
		return [
			'title'       => __( 'Title', 'overlay-multilingual' ),
			'excerpt'     => __( 'Excerpt / short description', 'overlay-multilingual' ),
			'content'     => __( 'Content (HTML)', 'overlay-multilingual' ),
			'seo_title'   => __( 'SEO title', 'overlay-multilingual' ),
			'seo_desc'    => __( 'Meta description', 'overlay-multilingual' ),
			'name'        => __( 'Name', 'overlay-multilingual' ),
			'h1'          => __( 'Page heading', 'overlay-multilingual' ),
			'description' => __( 'Description (HTML)', 'overlay-multilingual' ),
		];
	}

	/* ---------------------------------------------------------- shared ui -- */

	/**
	 * Tabbed fields for every secondary language.
	 *
	 * @param array $values   [lang => [field => value]]
	 * @param array $original [field => original-language value] for "Copy original"
	 */
	private static function panel( $fields, $values, $original, $id_prefix, $object_type = 'post', $object_id = 0 ) {
		$langs  = ovml_secondary_languages();
		$many   = count( $langs ) > 6; // tabs show language codes instead of names
		$labels = self::labels();
		$main   = in_array( 'title', array_keys( $fields ), true ) ? 'title' : 'name';
		echo '<div class="ovml ovml-editor" data-ovml-tabs style="margin:0;max-width:none">';
		printf( '<script type="application/json" id="%s-originals">%s</script>', esc_attr( $id_prefix ), wp_json_encode( array_map( 'strval', array_filter( $original, 'strlen' ) ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON with HTML-significant characters hex-escaped
		echo '<div class="ovml-editor__tabs" role="tablist">';
		foreach ( $langs as $i => $lang ) {
			$done = ! empty( $values[ $lang ][ $main ] );
			printf(
				'<button type="button" role="tab" id="%1$s-tab-%2$s" aria-controls="%1$s-panel-%2$s" aria-selected="%3$s" title="%7$s">%4$s <span class="ovml-pill ovml-pill--%5$s" data-ovml-tab-status>%6$s</span></button>',
				esc_attr( $id_prefix ),
				esc_attr( $lang ),
				0 === $i ? 'true' : 'false',
				esc_html( $many ? strtoupper( $lang ) : ovml_languages()[ $lang ]['name'] ),
				$done ? 'ok' : 'warn',
				$many ? ( $done ? '✓' : '•' ) : ( $done ? esc_html__( 'Translated', 'overlay-multilingual' ) : esc_html__( 'Missing', 'overlay-multilingual' ) ),
				esc_attr( ovml_languages()[ $lang ]['name'] )
			);
		}
		echo '</div>';
		foreach ( $langs as $i => $lang ) {
			printf( '<div class="ovml-editor__panel" role="tabpanel" id="%1$s-panel-%2$s" aria-labelledby="%1$s-tab-%2$s" lang="%2$s"%3$s>', esc_attr( $id_prefix ), esc_attr( $lang ), 0 === $i ? '' : ' hidden' );
			if ( $object_id && \OverlayML\AI::configured() ) {
				printf(
					'<p class="ovml-ai-row"><button type="button" class="button" data-ovml-ai-fill data-type="%s" data-id="%d" data-lang="%s" data-prefix="%s">%s</button> <span class="ovml-hint" data-ovml-ai-fill-status></span></p>',
					esc_attr( $object_type ),
					(int) $object_id,
					esc_attr( $lang ),
					esc_attr( $id_prefix ),
					/* translators: %s: language name */
					esc_html( sprintf( __( 'Auto-translate into %s', 'overlay-multilingual' ), ovml_languages()[ $lang ]['name'] ) )
				);
			}
			foreach ( $fields as $field => $cfg ) {
				$id    = "$id_prefix-$lang-$field";
				$name  = "ovml[$lang][$field]";
				$value = (string) ( $values[ $lang ][ $field ] ?? '' );
				echo '<div class="ovml-field"><div class="ovml-field-head">';
				printf( '<label for="%s">%s</label>', esc_attr( $id ), esc_html( $labels[ $field ] ) );
				echo '<span>';
				if ( isset( $cfg['max'] ) ) {
					printf( '<span class="ovml-count" data-ovml-count-for="%s" data-max="%d"></span> ', esc_attr( $id ), (int) $cfg['max'] );
				}
				if ( '' !== (string) ( $original[ $field ] ?? '' ) ) {
					printf( '<button type="button" class="button-link" data-ovml-fill="%s" data-field="%s" data-originals="%s-originals">%s</button>', esc_attr( $id ), esc_attr( $field ), esc_attr( $id_prefix ), esc_html__( 'Copy original', 'overlay-multilingual' ) );
				}
				echo '</span></div>';
				if ( 'text' === $cfg['type'] ) {
					printf( '<input type="text" id="%s" name="%s" value="%s" %s>', esc_attr( $id ), esc_attr( $name ), esc_attr( $value ), $field === $main ? 'data-ovml-main' : '' );
				} else {
					printf( '<textarea id="%s" name="%s" rows="%d" class="%s">%s</textarea>', esc_attr( $id ), esc_attr( $name ), (int) ( $cfg['rows'] ?? 3 ), 'code' === $cfg['type'] ? 'code' : '', esc_textarea( $value ) );
				}
				echo '</div>';
			}
			echo '</div>';
		}
		echo '</div>';
	}

	/** Keep only non-empty, sanitised fields. */
	private static function clean( $input, $fields ) {
		$out = [];
		foreach ( ovml_secondary_languages() as $lang ) {
			foreach ( array_keys( $fields ) as $field ) {
				$value = (string) wp_unslash( $input[ $lang ][ $field ] ?? '' );
				$value = in_array( $field, [ 'content', 'description' ], true ) ? wp_kses_post( $value ) : sanitize_textarea_field( $value );
				if ( '' !== trim( $value ) ) {
					$out[ $lang ][ $field ] = $value;
				}
			}
		}
		return $out;
	}

	/* -------------------------------------------------------------- posts -- */

	public static function boxes( $post_type, $post ) {
		if ( ! ovml_secondary_languages() || ! $post instanceof \WP_Post ) {
			return;
		}
		if ( in_array( $post_type, (array) ovml_settings()['separate_posts'], true ) ) {
			add_meta_box( 'ovml-language', __( 'Language', 'overlay-multilingual' ), [ __CLASS__, 'language_box' ], $post_type, 'side', 'high' );
		} elseif ( ovml_translatable_post_type( $post_type ) ) {
			add_meta_box( 'ovml-translations', __( 'Translations', 'overlay-multilingual' ), [ __CLASS__, 'post_box' ], $post_type, 'normal', 'high' );
		}
	}

	public static function post_box( $post ) {
		wp_nonce_field( 'ovml_post', 'ovml_post_nonce' );
		$fields = self::POST_FIELDS;
		if ( function_exists( 'get_post_meta' ) && class_exists( 'RankMath' ) ) {
			$seo_title = (string) get_post_meta( $post->ID, 'rank_math_title', true );
			$seo_desc  = (string) get_post_meta( $post->ID, 'rank_math_description', true );
		} else {
			$seo_title = (string) get_post_meta( $post->ID, '_yoast_wpseo_title', true );
			$seo_desc  = (string) get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
		}
		$original = [
			'title'     => $post->post_title,
			'excerpt'   => $post->post_excerpt,
			'content'   => $post->post_content,
			'seo_title' => false === strpos( $seo_title, '%' ) ? $seo_title : '',
			'seo_desc'  => false === strpos( $seo_desc, '%' ) ? $seo_desc : '',
		];
		self::panel( $fields, (array) get_post_meta( $post->ID, OVML_META, true ), $original, 'ovml-post', 'post', $post->ID );
	}

	public static function language_box( $post ) {
		wp_nonce_field( 'ovml_post', 'ovml_post_nonce' );
		$own   = PolylangData::language( $post->ID ) ?: ovml_default_language();
		$group = PolylangData::group( $post->ID );
		echo '<div class="ovml" style="margin:0">';
		echo '<div class="ovml-field"><label for="ovml-own-lang">' . esc_html__( 'This post is in', 'overlay-multilingual' ) . '</label><select id="ovml-own-lang" name="ovml_own_lang">';
		foreach ( ovml_languages() as $code => $l ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $code ), selected( $own, $code, false ), esc_html( $l['name'] ) );
		}
		echo '</select></div>';
		echo '<p class="ovml-label" style="font-weight:600;margin:0 0 6px">' . esc_html__( 'Translations', 'overlay-multilingual' ) . '</p>';
		foreach ( ovml_languages() as $code => $l ) {
			if ( $code === $own ) {
				continue;
			}
			$options = get_posts( [ 'post_type' => $post->post_type, 'post_status' => [ 'publish', 'draft', 'future', 'private' ], 'posts_per_page' => 200, 'post__in' => PolylangData::ids_in( $code, $post->post_type ), 'orderby' => 'title', 'order' => 'ASC' ] );
			printf( '<div class="ovml-field"><label for="ovml-link-%1$s">%2$s</label><select id="ovml-link-%1$s" name="ovml_links[%1$s]" style="width:100%%"><option value="">%3$s</option>', esc_attr( $code ), esc_html( $l['name'] ), esc_html__( '— none —', 'overlay-multilingual' ) );
			foreach ( $options as $option ) {
				printf( '<option value="%d" %s>%s</option>', (int) $option->ID, selected( $group[ $code ] ?? 0, $option->ID, false ), esc_html( $option->post_title ) );
			}
			echo '</select></div>';
		}
		echo '</div>';
	}

	public static function save_post( $post_id, $post ) {
		if ( ! isset( $_POST['ovml_post_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['ovml_post_nonce'] ), 'ovml_post' ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( isset( $_POST['ovml_own_lang'] ) && PolylangData::is_separate( $post ) ) {
			$lang = sanitize_key( $_POST['ovml_own_lang'] );
			if ( isset( ovml_languages()[ $lang ] ) ) {
				PolylangData::save( $post_id, $lang, array_map( 'absint', (array) ( $_POST['ovml_links'] ?? [] ) ) );
			}
			return;
		}
		if ( isset( $_POST['ovml'] ) ) {
			update_post_meta( $post_id, OVML_META, self::clean( $_POST['ovml'], self::POST_FIELDS ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in clean()
		}
	}

	/* -------------------------------------------------------------- terms -- */

	public static function term_hooks() {
		foreach ( ovml_translatable_taxonomies() as $taxonomy ) {
			add_action( "{$taxonomy}_edit_form", [ __CLASS__, 'term_box' ], 20, 2 );
			add_action( "edited_{$taxonomy}", [ __CLASS__, 'save_term' ] );
		}
	}

	public static function term_box( $term ) {
		if ( ! ovml_secondary_languages() ) {
			return;
		}
		wp_nonce_field( 'ovml_term', 'ovml_term_nonce' );
		echo '<div class="postbox ovml-term-panel" style="margin-top:24px"><div class="postbox-header"><h2 style="padding:0 12px">' . esc_html__( 'Translations', 'overlay-multilingual' ) . '</h2></div><div class="inside">';
		self::panel( self::TERM_FIELDS, (array) get_term_meta( $term->term_id, OVML_META, true ), [ 'name' => $term->name, 'description' => $term->description ], 'ovml-term', 'term', $term->term_id );
		echo '</div></div>';
	}

	public static function save_term( $term_id ) {
		if ( ! isset( $_POST['ovml_term_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['ovml_term_nonce'] ), 'ovml_term' ) ) {
			return;
		}
		$taxonomy = get_term( $term_id )->taxonomy ?? '';
		$tax      = get_taxonomy( $taxonomy );
		if ( ! $tax || ! current_user_can( $tax->cap->edit_terms ) ) {
			return;
		}
		update_term_meta( $term_id, OVML_META, self::clean( $_POST['ovml'] ?? [], self::TERM_FIELDS ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in clean()
	}
}
