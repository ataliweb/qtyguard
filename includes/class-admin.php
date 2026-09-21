<?php
/**
 * Admin fields: product, variation and product category.
 *
 * @package Qtyguard
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI for per-product / per-variation / per-category rules.
 */
final class Qtyguard_Admin {

	const META_MIN      = '_qtyguard_min';
	const META_MAX      = '_qtyguard_max';
	const META_STEP     = '_qtyguard_step';
	const META_TOGETHER = '_qtyguard_together';

	/**
	 * Hook everything.
	 */
	public function __construct() {
		add_action( 'woocommerce_product_options_inventory_product_data', array( $this, 'product_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product' ) );

		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation' ), 10, 2 );

		add_action( 'product_cat_add_form_fields', array( $this, 'category_add_fields' ) );
		add_action( 'product_cat_edit_form_fields', array( $this, 'category_edit_fields' ) );
		add_action( 'created_product_cat', array( $this, 'save_category' ) );
		add_action( 'edited_product_cat', array( $this, 'save_category' ) );

		add_filter( 'plugin_action_links_' . plugin_basename( QTYGUARD_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * "Settings" link on the plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=qtyguard' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'qtyguard' ) . '</a>' );
		return $links;
	}

	/**
	 * Fields on the product Inventory tab.
	 */
	public function product_fields() {
		echo '<div class="options_group">';
		woocommerce_wp_text_input(
			array(
				'id'                => self::META_MIN,
				'label'             => __( 'Minimum quantity', 'qtyguard' ),
				'type'              => 'number',
				'desc_tip'          => true,
				'description'       => __( 'Smallest quantity a customer can buy. 0 = no minimum.', 'qtyguard' ),
				'custom_attributes' => array(
					'min'  => 0,
					'step' => 1,
				),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => self::META_MAX,
				'label'             => __( 'Maximum quantity', 'qtyguard' ),
				'type'              => 'number',
				'desc_tip'          => true,
				'description'       => __( 'Largest quantity per order. 0 = no maximum.', 'qtyguard' ),
				'custom_attributes' => array(
					'min'  => 0,
					'step' => 1,
				),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => self::META_STEP,
				'label'             => __( 'Sold in multiples of', 'qtyguard' ),
				'type'              => 'number',
				'desc_tip'          => true,
				'description'       => __( 'Quantity must be a multiple of this number (for example 6 for a box of six). 0 = any.', 'qtyguard' ),
				'custom_attributes' => array(
					'min'  => 0,
					'step' => 1,
				),
			)
		);
		woocommerce_wp_checkbox(
			array(
				'id'          => self::META_TOGETHER,
				'label'       => __( 'Count variations together', 'qtyguard' ),
				'description' => __( 'Variable products: apply the rule to the combined quantity of all variations instead of each variation on its own.', 'qtyguard' ),
			)
		);
		echo '</div>';
	}

	/**
	 * Save product fields. WooCommerce has already verified its nonce.
	 *
	 * @param int $post_id Product ID.
	 */
	public function save_product( $post_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		foreach ( array( self::META_MIN, self::META_MAX, self::META_STEP ) as $key ) {
			$value = isset( $_POST[ $key ] ) ? absint( wp_unslash( $_POST[ $key ] ) ) : 0;
			$this->write_meta( $post_id, $key, $value );
		}
		$together = isset( $_POST[ self::META_TOGETHER ] ) ? 1 : 0;
		$this->write_meta( $post_id, self::META_TOGETHER, $together );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Fields inside each variation panel.
	 *
	 * @param int     $loop           Position in the variation list.
	 * @param array   $variation_data Variation data.
	 * @param WP_Post $variation      Variation post.
	 */
	public function variation_fields( $loop, $variation_data, $variation ) {
		unset( $variation_data );
		$fields = array(
			'min'  => __( 'Min quantity', 'qtyguard' ),
			'max'  => __( 'Max quantity', 'qtyguard' ),
			'step' => __( 'Multiples of', 'qtyguard' ),
		);
		echo '<div class="qtyguard-variation-fields" style="display:flex;gap:12px;flex-wrap:wrap">';
		foreach ( $fields as $key => $label ) {
			$meta = '_qtyguard_' . $key;
			woocommerce_wp_text_input(
				array(
					'id'                => 'qtyguard_' . $key . '_' . $loop,
					'name'              => 'qtyguard_' . $key . '[' . $loop . ']',
					'label'             => $label,
					'type'              => 'number',
					'value'             => (int) get_post_meta( $variation->ID, $meta, true ),
					'wrapper_class'     => 'form-row',
					'custom_attributes' => array(
						'min'  => 0,
						'step' => 1,
					),
				)
			);
		}
		echo '</div>';
	}

	/**
	 * Save variation fields.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $loop         Position in the variation list.
	 */
	public function save_variation( $variation_id, $loop ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		foreach ( array( 'min', 'max', 'step' ) as $key ) {
			$field = 'qtyguard_' . $key;
			$value = isset( $_POST[ $field ][ $loop ] ) ? absint( wp_unslash( $_POST[ $field ][ $loop ] ) ) : 0;
			$this->write_meta( $variation_id, '_qtyguard_' . $key, $value );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Category "add" form.
	 */
	public function category_add_fields() {
		?>
		<div class="form-field">
			<label for="qtyguard_term_min"><?php esc_html_e( 'Minimum quantity', 'qtyguard' ); ?></label>
			<input type="number" min="0" step="1" id="qtyguard_term_min" name="qtyguard_term_min" value="0" />
		</div>
		<div class="form-field">
			<label for="qtyguard_term_max"><?php esc_html_e( 'Maximum quantity', 'qtyguard' ); ?></label>
			<input type="number" min="0" step="1" id="qtyguard_term_max" name="qtyguard_term_max" value="0" />
		</div>
		<div class="form-field">
			<label for="qtyguard_term_step"><?php esc_html_e( 'Sold in multiples of', 'qtyguard' ); ?></label>
			<input type="number" min="0" step="1" id="qtyguard_term_step" name="qtyguard_term_step" value="0" />
			<p><?php esc_html_e( 'Quantity rules for every product in this category. A rule set on the product itself takes priority. 0 = not set.', 'qtyguard' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Category "edit" form.
	 *
	 * @param WP_Term $term Term.
	 */
	public function category_edit_fields( $term ) {
		$fields = array(
			'min'  => __( 'Minimum quantity', 'qtyguard' ),
			'max'  => __( 'Maximum quantity', 'qtyguard' ),
			'step' => __( 'Sold in multiples of', 'qtyguard' ),
		);
		foreach ( $fields as $key => $label ) {
			$value = (int) get_term_meta( $term->term_id, '_qtyguard_' . $key, true );
			?>
			<tr class="form-field">
				<th scope="row"><label for="qtyguard_term_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
				<td>
					<input type="number" min="0" step="1" id="qtyguard_term_<?php echo esc_attr( $key ); ?>" name="qtyguard_term_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>" />
					<?php if ( 'step' === $key ) : ?>
						<p class="description"><?php esc_html_e( 'Applies to every product in this category. A rule set on the product itself takes priority. 0 = not set.', 'qtyguard' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<?php
		}
	}

	/**
	 * Save category fields. WordPress has already verified the term nonce.
	 *
	 * @param int $term_id Term ID.
	 */
	public function save_category( $term_id ) {
		if ( ! current_user_can( 'manage_product_terms' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		foreach ( array( 'min', 'max', 'step' ) as $key ) {
			$field = 'qtyguard_term_' . $key;
			if ( ! isset( $_POST[ $field ] ) ) {
				continue;
			}
			$value = absint( wp_unslash( $_POST[ $field ] ) );
			if ( $value > 0 ) {
				update_term_meta( $term_id, '_qtyguard_' . $key, $value );
			} else {
				delete_term_meta( $term_id, '_qtyguard_' . $key );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Write a positive integer, or remove the meta when it is 0.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param int    $value   Value.
	 */
	private function write_meta( $post_id, $key, $value ) {
		if ( $value > 0 ) {
			update_post_meta( $post_id, $key, $value );
		} else {
			delete_post_meta( $post_id, $key );
		}
	}
}
