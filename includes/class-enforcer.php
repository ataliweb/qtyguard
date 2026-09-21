<?php
/**
 * Applies the rules on the storefront: quantity inputs, add to cart, cart
 * updates, cart/checkout validation and the block-based Store API.
 *
 * @package Qtyguard
 */

defined( 'ABSPATH' ) || exit;

/**
 * Storefront enforcement.
 */
final class Qtyguard_Enforcer {

	/**
	 * Per-request rule cache, keyed by product ID.
	 *
	 * @var array
	 */
	private $rule_cache = array();

	/**
	 * Hook everything.
	 */
	public function __construct() {
		// Classic quantity inputs and variation data.
		add_filter( 'woocommerce_quantity_input_args', array( $this, 'quantity_input_args' ), 20, 2 );
		add_filter( 'woocommerce_available_variation', array( $this, 'variation_data' ), 20, 3 );

		// Validation.
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add' ), 20, 4 );
		add_filter( 'woocommerce_update_cart_validation', array( $this, 'validate_update' ), 20, 4 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart' ) );

		// Block cart / checkout (Store API) limits.
		add_filter( 'woocommerce_store_api_product_quantity_minimum', array( $this, 'api_minimum' ), 20, 3 );
		add_filter( 'woocommerce_store_api_product_quantity_maximum', array( $this, 'api_maximum' ), 20, 3 );
		add_filter( 'woocommerce_store_api_product_quantity_multiple_of', array( $this, 'api_multiple_of' ), 20, 3 );

		// Product page note.
		add_action( 'woocommerce_single_product_summary', array( $this, 'product_note' ), 29 );
	}

	/* ------------------------------------------------------------------ rules */

	/**
	 * Whether the current user ignores all rules.
	 *
	 * @return bool
	 */
	private function is_exempt() {
		$exempt = (array) Qtyguard_Settings::get( 'exempt_roles' );
		if ( empty( $exempt ) || ! is_user_logged_in() ) {
			return false;
		}
		$user = wp_get_current_user();
		return (bool) array_intersect( $exempt, (array) $user->roles );
	}

	/**
	 * Partial rule stored in post meta.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private function meta_layer( $post_id ) {
		return array(
			'min'  => (int) get_post_meta( $post_id, Qtyguard_Admin::META_MIN, true ),
			'max'  => (int) get_post_meta( $post_id, Qtyguard_Admin::META_MAX, true ),
			'step' => (int) get_post_meta( $post_id, Qtyguard_Admin::META_STEP, true ),
		);
	}

	/**
	 * Rule from the product's categories (and their parents), most restrictive wins.
	 *
	 * @param int $product_id Parent product ID.
	 * @return array
	 */
	private function category_layer( $product_id ) {
		$terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}
		$ids = array();
		foreach ( $terms as $term_id ) {
			$ids[] = (int) $term_id;
			foreach ( get_ancestors( $term_id, 'product_cat', 'taxonomy' ) as $ancestor ) {
				$ids[] = (int) $ancestor;
			}
		}
		$layers = array();
		foreach ( array_unique( $ids ) as $term_id ) {
			$layers[] = array(
				'min'  => (int) get_term_meta( $term_id, '_qtyguard_min', true ),
				'max'  => (int) get_term_meta( $term_id, '_qtyguard_max', true ),
				'step' => (int) get_term_meta( $term_id, '_qtyguard_step', true ),
			);
		}
		return Qtyguard_Rules::merge_restrictive( $layers );
	}

	/**
	 * The effective rule for a product or variation.
	 *
	 * @param WC_Product $product Product.
	 * @return array
	 */
	public function rule_for( $product ) {
		$id = $product->get_id();
		if ( isset( $this->rule_cache[ $id ] ) ) {
			return $this->rule_cache[ $id ];
		}

		$layers    = array();
		$parent_id = $id;
		if ( $product->is_type( 'variation' ) ) {
			$layers[]  = $this->meta_layer( $id );
			$parent_id = $product->get_parent_id();
		}
		$layers[] = $this->meta_layer( $parent_id );
		$layers[] = $this->category_layer( $parent_id );
		$layers[] = array(
			'min'  => (int) Qtyguard_Settings::get( 'default_min' ),
			'max'  => (int) Qtyguard_Settings::get( 'default_max' ),
			'step' => (int) Qtyguard_Settings::get( 'default_step' ),
		);

		$this->rule_cache[ $id ] = Qtyguard_Rules::resolve( $layers );
		return $this->rule_cache[ $id ];
	}

	/**
	 * Whether the parent product counts all its variations together.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return bool
	 */
	private function is_together( $product ) {
		$parent_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		return $parent_id && (bool) get_post_meta( $parent_id, Qtyguard_Admin::META_TOGETHER, true );
	}

	/**
	 * Quantity already in the cart for this product, optionally skipping one line.
	 *
	 * @param WC_Product  $product      Product or variation.
	 * @param string|null $exclude_key  Cart item key to ignore.
	 * @return int
	 */
	private function cart_qty( $product, $exclude_key = null ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return 0;
		}
		$together  = $this->is_together( $product );
		$parent_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$total     = 0;
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( null !== $exclude_key && (string) $key === (string) $exclude_key ) {
				continue;
			}
			if ( $together ) {
				$match = (int) $item['product_id'] === (int) $parent_id;
			} else {
				$line_id = ! empty( $item['variation_id'] ) ? (int) $item['variation_id'] : (int) $item['product_id'];
				$match   = $line_id === (int) $product->get_id();
			}
			if ( $match ) {
				$total += (int) $item['quantity'];
			}
		}
		return $total;
	}

	/* --------------------------------------------------------------- messages */

	/**
	 * Build an error message.
	 *
	 * @param string     $code    min, max or step.
	 * @param WC_Product $product Product.
	 * @param array      $rule    Rule.
	 * @param int        $qty     Quantity that failed.
	 * @return string
	 */
	private function message( $code, $product, $rule, $qty ) {
		$name = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() )->get_name() : $product->get_name();
		return Qtyguard_Rules::render(
			Qtyguard_Settings::message( $code ),
			array(
				'product' => $name,
				'qty'     => $qty,
				'min'     => $rule['min'],
				'max'     => $rule['max'],
				'step'    => $rule['step'],
			)
		);
	}

	/* ------------------------------------------------------- classic storefront */

	/**
	 * Set min / max / step on quantity inputs.
	 *
	 * @param array      $args    Input arguments.
	 * @param WC_Product $product Product.
	 * @return array
	 */
	public function quantity_input_args( $args, $product ) {
		if ( ! $product instanceof WC_Product || $this->is_exempt() ) {
			return $args;
		}
		$rule = $this->rule_for( $product );
		if ( ! Qtyguard_Rules::has_rule( $rule ) ) {
			return $args;
		}
		$together = $this->is_together( $product );

		if ( ! $together ) {
			if ( $rule['min'] > 0 ) {
				$args['min_value'] = $rule['min'];
			}
			if ( $rule['step'] > 0 ) {
				$args['step'] = $rule['step'];
			}
			$args['input_value'] = Qtyguard_Rules::nearest( $rule, isset( $args['input_value'] ) ? $args['input_value'] : 1 );
		}
		if ( $rule['max'] > 0 ) {
			$current = isset( $args['max_value'] ) ? (int) $args['max_value'] : 0;
			$args['max_value'] = ( $current > 0 ) ? min( $current, $rule['max'] ) : $rule['max'];
		}
		return $args;
	}

	/**
	 * Expose limits to the variation script.
	 *
	 * @param array                $data      Variation data.
	 * @param WC_Product           $product   Variable product.
	 * @param WC_Product_Variation $variation Variation.
	 * @return array
	 */
	public function variation_data( $data, $product, $variation ) {
		unset( $product );
		if ( $this->is_exempt() || $this->is_together( $variation ) ) {
			return $data;
		}
		$rule = $this->rule_for( $variation );
		if ( $rule['min'] > 0 ) {
			$data['min_qty'] = $rule['min'];
		}
		if ( $rule['max'] > 0 ) {
			$current         = isset( $data['max_qty'] ) && '' !== $data['max_qty'] ? (int) $data['max_qty'] : 0;
			$data['max_qty'] = ( $current > 0 ) ? min( $current, $rule['max'] ) : $rule['max'];
		}
		if ( $rule['step'] > 0 ) {
			$data['step'] = $rule['step'];
		}
		return $data;
	}

	/**
	 * Add to cart.
	 *
	 * @param bool $passed       Validation result so far.
	 * @param int  $product_id   Product ID.
	 * @param int  $quantity     Quantity being added.
	 * @param int  $variation_id Variation ID.
	 * @return bool
	 */
	public function validate_add( $passed, $product_id, $quantity, $variation_id = 0 ) {
		if ( ! $passed || $this->is_exempt() ) {
			return $passed;
		}
		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		if ( ! $product ) {
			return $passed;
		}
		$rule = $this->rule_for( $product );
		if ( ! Qtyguard_Rules::has_rule( $rule ) ) {
			return $passed;
		}
		$total = $this->cart_qty( $product ) + (int) $quantity;
		// With combined variations the minimum is only enforced at checkout, so
		// a customer can still add the variations one after another.
		$error = Qtyguard_Rules::validate( $rule, $total, ! $this->is_together( $product ) );
		if ( $error ) {
			wc_add_notice( $this->message( $error, $product, $rule, $total ), 'error' );
			return false;
		}
		return $passed;
	}

	/**
	 * Cart quantity update.
	 *
	 * @param bool   $passed        Validation result so far.
	 * @param string $cart_item_key Cart item key.
	 * @param array  $values        Cart item.
	 * @param int    $quantity      New quantity.
	 * @return bool
	 */
	public function validate_update( $passed, $cart_item_key, $values, $quantity ) {
		if ( ! $passed || $this->is_exempt() || (int) $quantity < 1 || empty( $values['data'] ) ) {
			return $passed;
		}
		$product = $values['data'];
		$rule    = $this->rule_for( $product );
		if ( ! Qtyguard_Rules::has_rule( $rule ) ) {
			return $passed;
		}
		$total = (int) $quantity;
		if ( $this->is_together( $product ) ) {
			$total += $this->cart_qty( $product, $cart_item_key );
		}
		$error = Qtyguard_Rules::validate( $rule, $total, true );
		if ( $error ) {
			wc_add_notice( $this->message( $error, $product, $rule, $total ), 'error' );
			return false;
		}
		return $passed;
	}

	/**
	 * Cart and checkout: block the order while any rule is broken. This also runs
	 * for the block-based checkout.
	 */
	public function check_cart() {
		if ( $this->is_exempt() || ! WC()->cart ) {
			return;
		}
		$seen = array();
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			$product = $item['data'];
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$rule = $this->rule_for( $product );
			if ( ! Qtyguard_Rules::has_rule( $rule ) ) {
				continue;
			}
			$group = $this->is_together( $product ) ? 'p' . (int) $item['product_id'] : 'l' . $key;
			if ( isset( $seen[ $group ] ) ) {
				continue;
			}
			$seen[ $group ] = true;

			$total = $this->is_together( $product ) ? $this->cart_qty( $product ) : (int) $item['quantity'];
			$error = Qtyguard_Rules::validate( $rule, $total, true );
			if ( $error ) {
				wc_add_notice( $this->message( $error, $product, $rule, $total ), 'error' );
			}
		}

		$items  = (int) WC()->cart->get_cart_contents_count();
		$errors = Qtyguard_Rules::cart_errors(
			$items,
			(float) WC()->cart->get_subtotal(),
			array(
				'min_items'  => Qtyguard_Settings::get( 'cart_min_items' ),
				'max_items'  => Qtyguard_Settings::get( 'cart_max_items' ),
				'min_amount' => Qtyguard_Settings::get( 'cart_min_amount' ),
			)
		);
		foreach ( $errors as $code ) {
			wc_add_notice(
				Qtyguard_Rules::render(
					Qtyguard_Settings::message( $code ),
					array(
						'min'      => Qtyguard_Settings::get( 'cart_min_items' ),
						'max'      => Qtyguard_Settings::get( 'cart_max_items' ),
						'total'    => $items,
						'amount'   => wp_strip_all_tags( wc_price( (float) Qtyguard_Settings::get( 'cart_min_amount' ) ) ),
						'subtotal' => wp_strip_all_tags( wc_price( (float) WC()->cart->get_subtotal() ) ),
					)
				),
				'error'
			);
		}
	}

	/* --------------------------------------------------------- block Store API */

	/**
	 * Store API minimum.
	 *
	 * @param int        $value     Current value.
	 * @param WC_Product $product   Product.
	 * @param array|null $cart_item Cart item.
	 * @return int
	 */
	public function api_minimum( $value, $product, $cart_item = null ) {
		unset( $cart_item );
		if ( $this->is_exempt() || $this->is_together( $product ) ) {
			return $value;
		}
		$rule = $this->rule_for( $product );
		return $rule['min'] > 0 ? max( (int) $value, $rule['min'] ) : $value;
	}

	/**
	 * Store API maximum.
	 *
	 * @param int        $value     Current value (0 or less means unlimited).
	 * @param WC_Product $product   Product.
	 * @param array|null $cart_item Cart item.
	 * @return int
	 */
	public function api_maximum( $value, $product, $cart_item = null ) {
		unset( $cart_item );
		if ( $this->is_exempt() ) {
			return $value;
		}
		$rule = $this->rule_for( $product );
		if ( $rule['max'] < 1 ) {
			return $value;
		}
		return ( (int) $value > 0 ) ? min( (int) $value, $rule['max'] ) : $rule['max'];
	}

	/**
	 * Store API step.
	 *
	 * @param int        $value     Current value.
	 * @param WC_Product $product   Product.
	 * @param array|null $cart_item Cart item.
	 * @return int
	 */
	public function api_multiple_of( $value, $product, $cart_item = null ) {
		unset( $cart_item );
		if ( $this->is_exempt() || $this->is_together( $product ) ) {
			return $value;
		}
		$rule = $this->rule_for( $product );
		return $rule['step'] > 0 ? $rule['step'] : $value;
	}

	/* ------------------------------------------------------------ product page */

	/**
	 * Short note about the rule on the product page.
	 */
	public function product_note() {
		if ( ! Qtyguard_Settings::get( 'show_notice' ) || $this->is_exempt() ) {
			return;
		}
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$rule  = $this->rule_for( $product );
		$parts = array();
		if ( $rule['min'] > 0 ) {
			/* translators: %d: minimum quantity */
			$parts[] = sprintf( __( 'Minimum: %d', 'qtyguard' ), $rule['min'] );
		}
		if ( $rule['max'] > 0 ) {
			/* translators: %d: maximum quantity */
			$parts[] = sprintf( __( 'Maximum: %d', 'qtyguard' ), $rule['max'] );
		}
		if ( $rule['step'] > 0 ) {
			/* translators: %d: quantity step */
			$parts[] = sprintf( __( 'Multiples of %d', 'qtyguard' ), $rule['step'] );
		}
		if ( empty( $parts ) ) {
			return;
		}
		echo '<p class="qtyguard-note">' . esc_html( implode( ' · ', $parts ) ) . '</p>';
	}
}
