<?php
/**
 * Dependency-free tests for the rule engine. Run: php tests/run-tests.php
 *
 * @package Qtyguard
 */

define( 'QTYGUARD_TESTING', true );
require dirname( __DIR__ ) . '/includes/class-rules.php';

$failures = 0;
$count    = 0;

function check( $condition, $label ) {
	global $failures, $count;
	++$count;
	if ( $condition ) {
		echo "  ok    $label\n";
	} else {
		echo "  FAIL  $label\n";
		++$failures;
	}
}

function same( $expected, $actual, $label ) {
	check( $expected === $actual, $label . ( $expected === $actual ? '' : ' (expected ' . json_encode( $expected ) . ', got ' . json_encode( $actual ) . ')' ) );
}

echo "resolve: precedence\n";
$rule = Qtyguard_Rules::resolve(
	array(
		array( 'min' => 0, 'max' => 5, 'step' => 0 ),   // variation.
		array( 'min' => 2, 'max' => 9, 'step' => 0 ),   // product.
		array( 'min' => 1, 'max' => 20, 'step' => 2 ),  // category.
		array( 'min' => 0, 'max' => 0, 'step' => 0 ),   // defaults.
	)
);
same( array( 'min' => 2, 'max' => 4, 'step' => 2 ), $rule, 'each field comes from the most specific layer that sets it' );

same( array( 'min' => 0, 'max' => 0, 'step' => 0 ), Qtyguard_Rules::resolve( array() ), 'no layers = no rule' );
same( false, Qtyguard_Rules::has_rule( Qtyguard_Rules::resolve( array( array() ) ) ), 'empty layer has no rule' );

echo "normalize\n";
same( array( 'min' => 6, 'max' => 12, 'step' => 6 ), Qtyguard_Rules::normalize( array( 'min' => 4, 'max' => 14, 'step' => 6 ) ), 'min rounds up, max rounds down to a multiple' );
same( array( 'min' => 6, 'max' => 0, 'step' => 6 ), Qtyguard_Rules::normalize( array( 'min' => 0, 'max' => 0, 'step' => 6 ) ), 'step with no min makes min = step' );
same( array( 'min' => 10, 'max' => 10, 'step' => 0 ), Qtyguard_Rules::normalize( array( 'min' => 10, 'max' => 5, 'step' => 0 ) ), 'min wins when min > max' );
same( array( 'min' => 6, 'max' => 6, 'step' => 6 ), Qtyguard_Rules::normalize( array( 'min' => 0, 'max' => 3, 'step' => 6 ) ), 'max below one step collapses to min' );
same( array( 'min' => 0, 'max' => 0, 'step' => 0 ), Qtyguard_Rules::normalize( array( 'min' => -3, 'max' => -1, 'step' => 1 ) ), 'negatives and step 1 are ignored' );

echo "validate\n";
$r = Qtyguard_Rules::normalize( array( 'min' => 3, 'max' => 12, 'step' => 3 ) );
same( 'min', Qtyguard_Rules::validate( $r, 2 ), '2 is below min 3' );
same( null, Qtyguard_Rules::validate( $r, 3 ), '3 is valid' );
same( 'step', Qtyguard_Rules::validate( $r, 4 ), '4 is not a multiple of 3' );
same( null, Qtyguard_Rules::validate( $r, 12 ), '12 is valid (max)' );
same( 'max', Qtyguard_Rules::validate( $r, 15 ), '15 is above max 12' );
same( null, Qtyguard_Rules::validate( $r, 0, false ), 'min can be skipped' );
same( null, Qtyguard_Rules::validate( array( 'min' => 0, 'max' => 0, 'step' => 0 ), 999 ), 'no rule accepts anything' );

echo "nearest\n";
same( 6, Qtyguard_Rules::nearest( Qtyguard_Rules::normalize( array( 'min' => 3, 'max' => 12, 'step' => 3 ) ), 4 ), '4 rounds up to 6' );
same( 3, Qtyguard_Rules::nearest( Qtyguard_Rules::normalize( array( 'min' => 3, 'max' => 12, 'step' => 3 ) ), 1 ), '1 is raised to min 3' );
same( 12, Qtyguard_Rules::nearest( Qtyguard_Rules::normalize( array( 'min' => 3, 'max' => 12, 'step' => 3 ) ), 40 ), '40 is clamped to max 12' );
same( 1, Qtyguard_Rules::nearest( array( 'min' => 0, 'max' => 0, 'step' => 0 ), 0 ), 'no rule returns at least 1' );

echo "merge_restrictive (multiple categories)\n";
same(
	array( 'min' => 5, 'max' => 8, 'step' => 4 ),
	Qtyguard_Rules::merge_restrictive(
		array(
			array( 'min' => 2, 'max' => 10, 'step' => 2 ),
			array( 'min' => 5, 'max' => 8, 'step' => 4 ),
			array( 'min' => 0, 'max' => 0, 'step' => 0 ),
		)
	),
	'largest min, smallest non-zero max, largest step'
);

echo "cart_errors\n";
$limits = array( 'min_items' => 3, 'max_items' => 10, 'min_amount' => 100 );
same( array(), Qtyguard_Rules::cart_errors( 0, 0, $limits ), 'empty cart: no errors' );
same( array( 'cart_min_items', 'cart_min_amount' ), Qtyguard_Rules::cart_errors( 2, 50, $limits ), 'too few items and too low amount' );
same( array( 'cart_max_items' ), Qtyguard_Rules::cart_errors( 11, 500, $limits ), 'too many items' );
same( array(), Qtyguard_Rules::cart_errors( 5, 150, $limits ), 'within limits' );

echo "render\n";
same( 'Min 3 for "Ring"', Qtyguard_Rules::render( 'Min {min} for "{product}"', array( 'min' => 3, 'product' => 'Ring' ) ), 'placeholders are replaced' );
same( 'Keep {unknown}', Qtyguard_Rules::render( 'Keep {unknown}', array( 'min' => 3 ) ), 'unknown placeholders stay' );

echo "\n$count checks\n";
if ( $failures > 0 ) {
	echo "$failures failed\n";
	exit( 1 );
}
echo "All passed\n";
