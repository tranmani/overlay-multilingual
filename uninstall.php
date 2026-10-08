<?php
/**
 * Uninstall: remove settings, dictionaries and the early loader.
 *
 * Translations stored on posts and terms are kept on purpose, so reinstalling
 * does not lose work. Define OVML_REMOVE_ALL_DATA as true in wp-config.php to
 * delete them too.
 *
 * @package OverlayML
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$ovml_settings = get_option( 'ovml_settings', [] );
foreach ( array_keys( (array) ( $ovml_settings['languages'] ?? [] ) ) as $ovml_lang ) {
	delete_option( "ovml_dict_$ovml_lang" );
}
foreach ( [ 'ovml_settings', 'ovml_missing', 'ovml_scan_before' ] as $ovml_option ) {
	delete_option( $ovml_option );
}
delete_transient( 'ovml_scan_urls' );

$ovml_stub = WPMU_PLUGIN_DIR . '/overlay-multilingual-early.php';
if ( file_exists( $ovml_stub ) ) {
	wp_delete_file( $ovml_stub );
}

if ( defined( 'OVML_REMOVE_ALL_DATA' ) && OVML_REMOVE_ALL_DATA ) {
	$wpdb->delete( $wpdb->postmeta, [ 'meta_key' => '_ovml' ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
	$wpdb->delete( $wpdb->termmeta, [ 'meta_key' => '_ovml' ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
	$wpdb->delete( $wpdb->postmeta, [ 'meta_key' => '_ovml_lang' ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
}
