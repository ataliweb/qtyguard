=== Qtyguard ===
Contributors: ataliweb
Tags: woocommerce, minimum quantity, maximum quantity, quantity step, order limits
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Minimum, maximum and multiple-of quantity rules for WooCommerce, per product, variation, category and for the whole order.

== Description ==

Qtyguard lets you decide how many of a product a customer can buy.

* **Minimum, maximum and "multiples of"** for every product (for example: sold in boxes of 6, at least 12, at most 60).
* **Per variation**, **per category** and **store-wide defaults**. The most specific rule wins: variation, product, category, default.
* **Count variations together** for variable products, so the rule applies to the combined quantity.
* **Whole-order limits**: minimum / maximum items in the cart and a minimum order amount.
* **Exempt roles**, for example wholesale customers.
* **Your own messages** with placeholders, and an optional note on the product page.
* Quantity fields get the right min / max / step automatically, and the cart and checkout are blocked until the order is valid.
* Works with the classic and the block cart and checkout. Compatible with High-Performance Order Storage.
* Turkish translation included, ready for more.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install the zip from Plugins > Add New.
2. Activate it. WooCommerce must be active.
3. Open WooCommerce > Quantity Rules for store-wide settings, or edit a product (Inventory tab), a variation or a product category.

== Frequently Asked Questions ==

= Which rule wins when several are set? =
Variation, then product, then category, then the store-wide default. Each field (minimum, maximum, multiples) is picked separately.

= What if a product is in two categories? =
The most restrictive values are used: the larger minimum, the smaller maximum, the larger step.

= Does it work with the block checkout? =
Yes. Quantity limits are exposed to the block cart, and the order is checked before payment.

== Changelog ==

= 1.0.2 =
* Plugin Check fixes: direct access protection, no manual text domain loading.

= 1.0.1 =
* Documentation and metadata fixes for the wordpress.org directory.

= 1.0.0 =
* First release.
