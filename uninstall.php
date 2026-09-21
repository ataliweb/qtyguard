<?php
/**
 * Removes all plugin data when the plugin is deleted.
 *
 * @package Qtyguard
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'qtyguard_settings' );

foreach ( array( '_qtyguard_min', '_qtyguard_max', '_qtyguard_step', '_qtyguard_together' ) as $qtyguard_key ) {
	delete_post_meta_by_key( $qtyguard_key );
	delete_metadata( 'term', 0, $qtyguard_key, '', true );
}
