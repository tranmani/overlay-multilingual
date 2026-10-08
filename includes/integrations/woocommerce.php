<?php
/**
 * WooCommerce: translated products, variations and attributes; orders that
 * remember their language; customer emails in that language.
 *
 * There is only ever one product, so stock, prices and orders are shared by all
 * languages. Order line items are stored with the default-language name (the
 * admin, invoices and reports stay in one language) and translated only when
 * shown to the customer.
 *
 * @package OverlayML
 */

namespace OverlayML\Integrations;

use OverlayML\Dictionary;

defined( 'ABSPATH' ) || exit;

class WooCommerce {

	/** Customer notification actions that carry the order (id) as first argument. */
	const EMAIL_ACTIONS = [
		'woocommerce_order_status_pending_to_processing_notification',
		'woocommerce_order_status_pending_to_on-hold_notification',
		'woocommerce_order_status_failed_to_processing_notification',
		'woocommerce_order_status_failed_to_on-hold_notification',
		'woocommerce_order_status_cancelled_to_processing_notification',
		'woocommerce_order_status_cancelled_to_on-hold_notification',
		'woocommerce_order_status_on-hold_to_processing_notification',
		'woocommerce_order_status_completed_notification',
		'woocommerce_order_fully_refunded_notification',
		'woocommerce_order_partially_refunded_notification',
		'woocommerce_new_customer_note_notification',
	];

	public static function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		$s = ovml_settings()['woocommerce'];
		// Orders and emails work in every mode once an order carries a language.
		add_action( 'woocommerce_checkout_create_order', [ __CLASS__, 'remember_language' ] );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', [ __CLASS__, 'remember_language' ] );
		if ( ! empty( $s['default_items'] ) ) {
			add_action( 'woocommerce_checkout_create_order_line_item', [ __CLASS__, 'store_default_name' ], 99, 3 );
		}
		if ( ! empty( $s['emails'] ) ) {
			foreach ( self::EMAIL_ACTIONS as $action ) {
				add_action( $action, [ __CLASS__, 'email_begin' ], 1 );
				add_action( $action, [ __CLASS__, 'email_end' ], 999 );
			}
		}

		if ( ! ovml_enabled() ) {
			return;
		}
		add_filter( 'woocommerce_product_get_name', [ __CLASS__, 'name' ], 5, 2 );
		add_filter( 'woocommerce_product_variation_get_name', [ __CLASS__, 'variation_name' ], 5, 2 );
		add_filter( 'woocommerce_product_get_description', [ __CLASS__, 'description' ], 5, 2 );
		add_filter( 'woocommerce_product_variation_get_description', [ __CLASS__, 'description' ], 5, 2 );
		add_filter( 'woocommerce_product_get_short_description', [ __CLASS__, 'short_description' ], 5, 2 );
		add_filter( 'woocommerce_short_description', [ __CLASS__, 'short_description_html' ], 1 );
		add_filter( 'woocommerce_order_item_get_name', [ __CLASS__, 'order_item_name' ], 5, 2 );
		add_filter( 'woocommerce_attribute_label', static fn( $label ) => ovml_t( $label ), 20 );
		add_filter( 'woocommerce_variation_option_name', static fn( $name ) => ovml_t( $name ), 20 );
		add_filter( 'woocommerce_add_to_cart_fragments', [ __CLASS__, 'fragments' ], 99 );
		add_filter( 'woocommerce_mail_content', static fn( $html ) => ovml_is_default() ? $html : Dictionary::translate_html( $html ), 99 );
		add_filter( 'woocommerce_mail_callback_params', [ __CLASS__, 'mail_subject' ], 99 );
	}

	/* ------------------------------------------------------------ product -- */

	public static function name( $name, $product ) {
		return ovml_post_tr( $product->get_id(), 'title' ) ?? $name;
	}

	public static function description( $value, $product ) {
		return ovml_post_tr( $product->get_id(), 'content' ) ?? $value;
	}

	public static function short_description( $value, $product ) {
		return ovml_post_tr( $product->get_id(), 'excerpt' ) ?? $value;
	}

	/** The single-product template passes the raw excerpt; swap it only if it is that product's. */
	public static function short_description_html( $excerpt ) {
		$id = get_the_ID();
		if ( ! $id || ovml_is_default() ) {
			return $excerpt;
		}
		$tr = ovml_post_tr( $id, 'excerpt' );
		return ( null !== $tr && trim( $excerpt ) === trim( (string) get_post_field( 'post_excerpt', $id, 'raw' ) ) ) ? $tr : $excerpt;
	}

	/**
	 * A variation name is stored as "Parent title - attribute values". Rebuild it
	 * from the translated parent title and dictionary-translated values.
	 */
	public static function variation_name( $name, $variation ) {
		if ( ovml_is_default() ) {
			return $name;
		}
		$parent_id = $variation->get_parent_id();
		$original  = (string) get_post_field( 'post_title', $parent_id, 'raw' );
		$parent    = ovml_post_tr( $parent_id, 'title' );
		if ( null === $parent || 0 !== strpos( $name, $original ) ) {
			return $name;
		}
		$tail = trim( preg_replace( '/^\s*[-–]\s*/u', '', substr( $name, strlen( $original ) ) ) );
		if ( '' === $tail ) {
			return $parent;
		}
		return $parent . ' - ' . implode( ', ', array_map( 'ovml_t', array_map( 'trim', explode( ',', $tail ) ) ) );
	}

	public static function order_item_name( $name, $item ) {
		if ( ovml_is_default() || ! $item instanceof \WC_Order_Item_Product ) {
			return $name;
		}
		$product = $item->get_product();
		if ( ! $product ) {
			return $name;
		}
		return $product->is_type( 'variation' ) ? self::variation_name( $name, $product ) : ( ovml_post_tr( $product->get_id(), 'title' ) ?? $name );
	}

	public static function fragments( $fragments ) {
		if ( ! ovml_is_default() ) {
			foreach ( $fragments as $key => $html ) {
				if ( is_string( $html ) ) {
					$fragments[ $key ] = Dictionary::translate_html( $html );
				}
			}
		}
		return $fragments;
	}

	/* -------------------------------------------------------------- orders -- */

	public static function remember_language( $order ) {
		if ( ! ovml_is_default( ovml_lang() ) ) {
			$order->update_meta_data( '_ovml_lang', ovml_lang() );
		}
	}

	public static function store_default_name( $item, $cart_item_key, $values ) {
		$product = $values['data'] ?? null;
		if ( $product instanceof \WC_Product ) {
			$item->set_name( $product->get_name( 'edit' ) );
		}
	}

	/* -------------------------------------------------------------- emails -- */

	/**
	 * WooCommerce switches customer emails to the site locale before the email
	 * knows its order, so the order's language is applied up front instead.
	 */
	public static function email_begin( $order ) {
		if ( is_array( $order ) ) {
			$order = $order['order_id'] ?? 0; // woocommerce_new_customer_note passes args
		}
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order );
		$lang  = $order ? (string) $order->get_meta( '_ovml_lang' ) : '';
		if ( ! isset( ovml_languages()[ $lang ] ) || ovml_is_default( $lang ) ) {
			return;
		}
		$GLOBALS['ovml_tlang'] = $lang;
		switch_to_locale( ovml_locale( $lang ) );
		add_filter( 'woocommerce_email_setup_locale', '__return_false' );
		add_filter( 'woocommerce_email_restore_locale', '__return_false' );
	}

	public static function email_end() {
		if ( ! isset( $GLOBALS['ovml_tlang'] ) ) {
			return;
		}
		unset( $GLOBALS['ovml_tlang'] );
		restore_previous_locale();
		remove_filter( 'woocommerce_email_setup_locale', '__return_false' );
		remove_filter( 'woocommerce_email_restore_locale', '__return_false' );
	}

	/** Subjects from settings go through the dictionary. */
	public static function mail_subject( $params ) {
		if ( ! ovml_is_default() && isset( $params[1] ) ) {
			$params[1] = ovml_t( $params[1] );
		}
		return $params;
	}
}
