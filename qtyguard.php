<?php
/**
 * Plugin Name:       Qtyguard
 * Plugin URI:        https://github.com/ataliweb/qtyguard
 * Description:       Minimum, maximum and multiple-of quantity rules per product, per variation, per category and for the whole order. Works with the classic and the block cart and checkout.
 * Version:           1.0.2
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.0
 * Author:            ataliweb
 * Author URI:        https://github.com/ataliweb
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       qtyguard
 * Domain Path:       /languages
 *
 * @package Qtyguard
 */

defined( 'ABSPATH' ) || exit;

define( 'QTYGUARD_VERSION', '1.0.2' );
define( 'QTYGUARD_FILE', __FILE__ );
define( 'QTYGUARD_DIR', plugin_dir_path( __FILE__ ) );

require_once QTYGUARD_DIR . 'includes/class-rules.php';
require_once QTYGUARD_DIR . 'includes/class-settings.php';
require_once QTYGUARD_DIR . 'includes/class-admin.php';
require_once QTYGUARD_DIR . 'includes/class-enforcer.php';

// Declare compatibility with High-Performance Order Storage and the block cart/checkout.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', QTYGUARD_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', QTYGUARD_FILE, true );
		}
	}
);

// Use the bundled translation when WordPress has no language pack for this plugin yet.
add_filter(
	'load_textdomain_mofile',
	static function ( $mofile, $domain ) {
		if ( 'qtyguard' === $domain && ! is_readable( $mofile ) ) {
			$bundled = QTYGUARD_DIR . 'languages/qtyguard-' . determine_locale() . '.mo';
			if ( is_readable( $bundled ) ) {
				return $bundled;
			}
		}
		return $mofile;
	},
	10,
	2
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Qtyguard needs WooCommerce to be installed and active.', 'qtyguard' ) . '</p></div>';
				}
			);
			return;
		}

		new Qtyguard_Settings();
		new Qtyguard_Enforcer();
		if ( is_admin() ) {
			new Qtyguard_Admin();
		}
	}
);
