<?php
/**
 * Pure quantity-rule logic. No WordPress or WooCommerce calls in here,
 * so it can be unit-tested with plain PHP (see tests/run-tests.php).
 *
 * @package Qtyguard
 */

defined( 'ABSPATH' ) || exit;

/**
 * A rule is an array: [ 'min' => int, 'max' => int, 'step' => int ]. 0 means "not set".
 */
final class Qtyguard_Rules {

	const FIELDS = array( 'min', 'max', 'step' );

	/**
	 * Pick each field from the first layer that defines it (layers are ordered
	 * from most specific to least specific), then normalize the result.
	 *
	 * @param array $layers List of partial rules.
	 * @return array
	 */
	public static function resolve( array $layers ) {
		$rule = array(
			'min'  => 0,
			'max'  => 0,
			'step' => 0,
		);
		foreach ( self::FIELDS as $field ) {
			foreach ( $layers as $layer ) {
				$value = isset( $layer[ $field ] ) ? (int) $layer[ $field ] : 0;
				if ( $value > 0 ) {
					$rule[ $field ] = $value;
					break;
				}
			}
		}
		return self::normalize( $rule );
	}

	/**
	 * Merge several rules of the same level (e.g. a product in two categories)
	 * by taking the most restrictive value of each field.
	 *
	 * @param array $rules List of partial rules.
	 * @return array
	 */
	public static function merge_restrictive( array $rules ) {
		$out = array(
			'min'  => 0,
			'max'  => 0,
			'step' => 0,
		);
		foreach ( $rules as $r ) {
			$min  = isset( $r['min'] ) ? (int) $r['min'] : 0;
			$max  = isset( $r['max'] ) ? (int) $r['max'] : 0;
			$step = isset( $r['step'] ) ? (int) $r['step'] : 0;
			if ( $min > $out['min'] ) {
				$out['min'] = $min;
			}
			if ( $max > 0 && ( 0 === $out['max'] || $max < $out['max'] ) ) {
				$out['max'] = $max;
			}
			if ( $step > $out['step'] ) {
				$out['step'] = $step;
			}
		}
		return $out;
	}

	/**
	 * Make a rule self-consistent: valid quantities are multiples of "step",
	 * at least "min" and at most "max". Min wins if min and max conflict.
	 *
	 * @param array $rule Partial rule.
	 * @return array
	 */
	public static function normalize( array $rule ) {
		$min  = isset( $rule['min'] ) ? max( 0, (int) $rule['min'] ) : 0;
		$max  = isset( $rule['max'] ) ? max( 0, (int) $rule['max'] ) : 0;
		$step = isset( $rule['step'] ) ? max( 0, (int) $rule['step'] ) : 0;

		if ( $step > 1 ) {
			if ( $min < $step ) {
				$min = $step;
			} elseif ( 0 !== $min % $step ) {
				$min = (int) ( ceil( $min / $step ) * $step );
			}
			if ( $max > 0 ) {
				$max = (int) ( floor( $max / $step ) * $step );
				if ( $max < $min ) {
					$max = $min;
				}
			}
		} else {
			$step = 0;
		}

		if ( $max > 0 && $min > $max ) {
			$max = $min;
		}

		return array(
			'min'  => $min,
			'max'  => $max,
			'step' => $step,
		);
	}

	/**
	 * Whether the rule restricts anything.
	 *
	 * @param array $rule Rule.
	 * @return bool
	 */
	public static function has_rule( array $rule ) {
		return $rule['min'] > 0 || $rule['max'] > 0 || $rule['step'] > 0;
	}

	/**
	 * Check a quantity against a rule.
	 *
	 * @param array $rule      Rule.
	 * @param int   $qty       Quantity to check.
	 * @param bool  $check_min Whether to enforce the minimum.
	 * @return string|null 'min', 'max', 'step' or null when valid.
	 */
	public static function validate( array $rule, $qty, $check_min = true ) {
		$qty = (int) $qty;
		if ( $check_min && $rule['min'] > 0 && $qty < $rule['min'] ) {
			return 'min';
		}
		if ( $rule['max'] > 0 && $qty > $rule['max'] ) {
			return 'max';
		}
		if ( $rule['step'] > 0 && 0 !== $qty % $rule['step'] ) {
			return 'step';
		}
		return null;
	}

	/**
	 * Closest valid quantity at or above the given one (clamped to max).
	 *
	 * @param array $rule Rule.
	 * @param int   $qty  Quantity.
	 * @return int
	 */
	public static function nearest( array $rule, $qty ) {
		$q = max( 1, (int) $qty );
		if ( $rule['step'] > 0 ) {
			$q = (int) ( ceil( $q / $rule['step'] ) * $rule['step'] );
		}
		if ( $rule['min'] > 0 && $q < $rule['min'] ) {
			$q = $rule['min'];
		}
		if ( $rule['max'] > 0 && $q > $rule['max'] ) {
			$q = $rule['max'];
		}
		return $q;
	}

	/**
	 * Cart-wide limits.
	 *
	 * @param int   $items    Total item count in the cart.
	 * @param float $subtotal Cart subtotal.
	 * @param array $limits   Keys: min_items, max_items, min_amount.
	 * @return string[] Error codes.
	 */
	public static function cart_errors( $items, $subtotal, array $limits ) {
		$errors = array();
		if ( $items < 1 ) {
			return $errors;
		}
		$min_items  = isset( $limits['min_items'] ) ? (int) $limits['min_items'] : 0;
		$max_items  = isset( $limits['max_items'] ) ? (int) $limits['max_items'] : 0;
		$min_amount = isset( $limits['min_amount'] ) ? (float) $limits['min_amount'] : 0.0;

		if ( $min_items > 0 && $items < $min_items ) {
			$errors[] = 'cart_min_items';
		}
		if ( $max_items > 0 && $items > $max_items ) {
			$errors[] = 'cart_max_items';
		}
		if ( $min_amount > 0 && (float) $subtotal < $min_amount ) {
			$errors[] = 'cart_min_amount';
		}
		return $errors;
	}

	/**
	 * Replace {placeholders} in a message template.
	 *
	 * @param string $template Template.
	 * @param array  $vars     Placeholder => value.
	 * @return string
	 */
	public static function render( $template, array $vars ) {
		$pairs = array();
		foreach ( $vars as $key => $value ) {
			$pairs[ '{' . $key . '}' ] = (string) $value;
		}
		return strtr( (string) $template, $pairs );
	}
}
