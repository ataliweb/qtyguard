<?php
/**
 * Plugin settings: storage, defaults, sanitizing and the admin page.
 *
 * @package Qtyguard
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings.
 */
final class Qtyguard_Settings {

	const OPTION = 'qtyguard_settings';

	/**
	 * Cached settings.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Hook the admin page.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
	}

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'default_min'     => 0,
			'default_max'     => 0,
			'default_step'    => 0,
			'cart_min_items'  => 0,
			'cart_max_items'  => 0,
			'cart_min_amount' => 0,
			'exempt_roles'    => array(),
			'show_notice'     => 1,
			'messages'        => array(),
		);
	}

	/**
	 * Default message templates (translatable).
	 *
	 * @return array
	 */
	public static function default_messages() {
		return array(
			/* translators: {product} product name, {min} minimum, {qty} quantity */
			'min'             => __( 'The minimum quantity for "{product}" is {min} (you have {qty}).', 'qtyguard' ),
			/* translators: {product} product name, {max} maximum, {qty} quantity */
			'max'             => __( 'The maximum quantity for "{product}" is {max} (you have {qty}).', 'qtyguard' ),
			/* translators: {product} product name, {step} step */
			'step'            => __( '"{product}" can only be bought in multiples of {step} (you have {qty}).', 'qtyguard' ),
			/* translators: {min} minimum item count, {total} current item count */
			'cart_min_items'  => __( 'Your order needs at least {min} items (you have {total}).', 'qtyguard' ),
			/* translators: {max} maximum item count, {total} current item count */
			'cart_max_items'  => __( 'Your order can contain at most {max} items (you have {total}).', 'qtyguard' ),
			/* translators: {amount} minimum order amount, {subtotal} current subtotal */
			'cart_min_amount' => __( 'The minimum order amount is {amount} (your subtotal is {subtotal}).', 'qtyguard' ),
		);
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		return self::$cache;
	}

	/**
	 * Single setting.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Message template: the admin override when set, otherwise the default.
	 *
	 * @param string $key Message key.
	 * @return string
	 */
	public static function message( $key ) {
		$custom = self::get( 'messages' );
		if ( is_array( $custom ) && ! empty( $custom[ $key ] ) ) {
			return $custom[ $key ];
		}
		$defaults = self::default_messages();
		return isset( $defaults[ $key ] ) ? $defaults[ $key ] : '';
	}

	/**
	 * Register the option.
	 */
	public function register() {
		register_setting(
			'qtyguard',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanitize submitted settings.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = self::defaults();

		foreach ( array( 'default_min', 'default_max', 'default_step', 'cart_min_items', 'cart_max_items' ) as $key ) {
			$out[ $key ] = isset( $input[ $key ] ) ? absint( $input[ $key ] ) : 0;
		}
		$out['cart_min_amount'] = isset( $input['cart_min_amount'] ) ? max( 0, (float) $input['cart_min_amount'] ) : 0;
		$out['show_notice']     = empty( $input['show_notice'] ) ? 0 : 1;

		$roles                = array_keys( wp_roles()->get_names() );
		$picked               = isset( $input['exempt_roles'] ) && is_array( $input['exempt_roles'] ) ? $input['exempt_roles'] : array();
		$out['exempt_roles']  = array_values( array_intersect( array_map( 'sanitize_key', $picked ), $roles ) );

		$defaults = self::default_messages();
		foreach ( array_keys( $defaults ) as $key ) {
			$text = isset( $input['messages'][ $key ] ) ? sanitize_text_field( $input['messages'][ $key ] ) : '';
			if ( '' !== $text && $text !== $defaults[ $key ] ) {
				$out['messages'][ $key ] = $text;
			}
		}

		self::$cache = null;
		return $out;
	}

	/**
	 * Menu entry under WooCommerce.
	 */
	public function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Quantity Rules', 'qtyguard' ),
			__( 'Quantity Rules', 'qtyguard' ),
			'manage_woocommerce',
			'qtyguard',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s        = self::all();
		$name     = self::OPTION;
		$defaults = self::default_messages();
		$labels   = array(
			'min'             => __( 'Below minimum', 'qtyguard' ),
			'max'             => __( 'Above maximum', 'qtyguard' ),
			'step'            => __( 'Not a valid multiple', 'qtyguard' ),
			'cart_min_items'  => __( 'Too few items in cart', 'qtyguard' ),
			'cart_max_items'  => __( 'Too many items in cart', 'qtyguard' ),
			'cart_min_amount' => __( 'Order amount too low', 'qtyguard' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Quantity Rules', 'qtyguard' ); ?></h1>
			<p><?php esc_html_e( 'Rules are applied in this order: variation, product, product category, then the store-wide defaults below. Leave a value at 0 for "no limit".', 'qtyguard' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'qtyguard' ); ?>

				<h2><?php esc_html_e( 'Store-wide defaults (per product)', 'qtyguard' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$this->number_row( $name . '[default_min]', __( 'Minimum quantity', 'qtyguard' ), $s['default_min'] );
					$this->number_row( $name . '[default_max]', __( 'Maximum quantity', 'qtyguard' ), $s['default_max'] );
					$this->number_row( $name . '[default_step]', __( 'Sold in multiples of', 'qtyguard' ), $s['default_step'] );
					?>
				</table>

				<h2><?php esc_html_e( 'Whole order limits', 'qtyguard' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$this->number_row( $name . '[cart_min_items]', __( 'Minimum items in cart', 'qtyguard' ), $s['cart_min_items'] );
					$this->number_row( $name . '[cart_max_items]', __( 'Maximum items in cart', 'qtyguard' ), $s['cart_max_items'] );
					$this->number_row( $name . '[cart_min_amount]', __( 'Minimum order amount (subtotal)', 'qtyguard' ), $s['cart_min_amount'], '0.01' );
					?>
				</table>

				<h2><?php esc_html_e( 'Exceptions and display', 'qtyguard' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Exempt roles', 'qtyguard' ); ?></th>
						<td>
							<?php foreach ( wp_roles()->get_names() as $role => $label ) : ?>
								<label style="display:block">
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[exempt_roles][]" value="<?php echo esc_attr( $role ); ?>" <?php checked( in_array( $role, $s['exempt_roles'], true ) ); ?> />
									<?php echo esc_html( translate_user_role( $label ) ); ?>
								</label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Users with these roles ignore all quantity rules (for example wholesale customers).', 'qtyguard' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Product page note', 'qtyguard' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[show_notice]" value="1" <?php checked( ! empty( $s['show_notice'] ) ); ?> />
								<?php esc_html_e( 'Show the rule (for example "Minimum: 3 · Multiples of 3") on the product page', 'qtyguard' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Messages', 'qtyguard' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Leave a field unchanged to use the default text. Placeholders: {product} {qty} {min} {max} {step} {total} {amount} {subtotal}', 'qtyguard' ); ?></p>
				<table class="form-table" role="presentation">
					<?php foreach ( $labels as $key => $label ) : ?>
						<tr>
							<th scope="row"><label for="qtyguard-msg-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
							<td>
								<input type="text" class="large-text" id="qtyguard-msg-<?php echo esc_attr( $key ); ?>"
									name="<?php echo esc_attr( $name ); ?>[messages][<?php echo esc_attr( $key ); ?>]"
									value="<?php echo esc_attr( self::message( $key ) ); ?>"
									placeholder="<?php echo esc_attr( $defaults[ $key ] ); ?>" />
							</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * One numeric settings row.
	 *
	 * @param string $name  Input name.
	 * @param string $label Label.
	 * @param mixed  $value Value.
	 * @param string $step  Step attribute.
	 */
	private function number_row( $name, $label, $value, $step = '1' ) {
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td><input type="number" min="0" step="<?php echo esc_attr( $step ); ?>" class="small-text" id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" /></td>
		</tr>
		<?php
	}
}
