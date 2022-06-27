<?php
/*
 * Plugin Name:  Debug WooCommerce Order Item Meta Data
 * Plugin URI: https://github.com/lucasstark/woocommerce-debug-order-item-meta-data
 * Description: Activate this plugin to record all cart values and order item meta values when an order is submitted to a log.   Helps with troubleshooting issues with order item metadata not properly being added to an order.
 * Version: 1.0.2
 * Author: Lucas Stark
 * Author URI: https://www.elementstark.com/
 * Requires at least: 3.1
 * Tested up to: 6.0

 * Copyright: © 2009-2022 Lucas Stark.
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html

 * WC requires at least: 6.6
 * WC tested up to: 6.6
 */

class WC_Debug_Order_Item_Meta_Data {
	private static $instance = null;

	public static function register() {
		if ( self::$instance === null ) {
			self::$instance = new WC_Debug_Order_Item_Meta_Data();
		}
	}

	private $all_values = [];
	private $gravity_forms_values = [];

	protected function __construct() {
		add_action( 'woocommerce_checkout_create_order_line_item', [
			$this,
			'on_checkout_create_order_line_item'
		], 10, 4 );
		add_action( 'woocommerce_checkout_order_created', [ $this, 'on_checkout_order_created' ], 10, 1 );

		add_action( 'woocommerce_checkout_order_created', [ $this, 'on_woocommerce_checkout_order_created' ], 10, 1 );

		add_filter( 'woocommerce_gforms_order_item_meta', [
			$this,
			'on_get_woocommerce_gforms_order_item_meta'
		], 10, 6 );

		add_action( 'admin_menu', array( $this, 'on_admin_menu' ), 99 );

	}

	/**
	 * @param WC_Order_Item $item
	 * @param string $cart_item_key
	 * @param array $values
	 * @param WC_Order $order
	 *
	 * @return void
	 */
	public function on_checkout_create_order_line_item( $item, $cart_item_key, $values, $order ) {
		$item->add_meta_data( $this->get_order_item_meta_key(), $cart_item_key );
		$item->add_meta_data( '__wc_debug_order_cart_item_key', $cart_item_key );
		unset( $values['data'] );
		$this->all_values[ $cart_item_key ][] = $values;
	}

	public function on_get_woocommerce_gforms_order_item_meta( $order_item_meta, $field, $lead, $form_meta, $item_id, $cart_item ) {
		$this->gravity_forms_values[ $cart_item['key'] ][] = $order_item_meta;

		return $order_item_meta;
	}

	/**
	 * @param WC_Order $order
	 *
	 * @return void
	 */
	public function on_checkout_order_created( WC_Order $order ) {
		$order_items = $order->get_items();
		if ( $order_items ) {
			$valid    = false;
			$log_data = [];
			foreach ( $order_items as $order_item ) {
				$cart_item_key    = '';
				$order_item_id    = $order_item->get_id();
				$meta_data        = $order_item->get_meta_data();
				$meta_data_values = [];
				foreach ( $meta_data as $meta_data_item ) {
					$d                  = $meta_data_item->get_data();
					$meta_data_values[] = $d;
					if ( $d['key'] == '__wc_debug_order_cart_item_key' ) {
						$cart_item_key = $d['value'];
					}

					if ( $d['key'] == 'false_value' ) {
						$valid = true;
					}
				}

				if ( ! $valid ) {
					$log_data[] = [
						'valid'             => false,
						'order_item_id'     => $order_item_id,
						'meta_data'         => $meta_data_values,
						'cart_item_data'    => $this->all_values[ $cart_item_key ] ?? '',
						'gravity_form_data' => $this->gravity_forms_values[ $cart_item_key ] ?? ''
					];
				}
			}

			if ( ! empty( $log_data ) ) {
				$this->log_error( $order->get_id(), $log_data );
			}

		}
	}

	public function on_woocommerce_checkout_order_created( $original_order ) {
		wp_cache_flush();
		$order       = wc_get_order( $original_order->get_id() );
		$order_items = $order->get_items();
		$log_entry   = $this->get_debug_entry_by_order( $order->get_id() );
		if ( $order_items ) {

			$valid    = true;
			$log_data = [];
			foreach ( $order_items as $order_item ) {
				$logged_order_item = wp_list_filter( $log_entry['data'], [ 'order_item_id' => $order_item->get_id() ] );
				if ( $logged_order_item ) {
					$logged_order_item = array_shift( $logged_order_item );
					$logged_keys       = wp_list_pluck( $logged_order_item['meta_data'], 'key' );
					$meta_data         = $order_item->get_meta_data();
					$ordered_keys      = [];
					foreach ( $meta_data as $meta_data_item ) {
						$d                  = $meta_data_item->get_data();
						$meta_data_values[] = $d;
						$ordered_keys[]     = $d['key'];
					}

					foreach ( $logged_keys as $logged_key ) {
						if ( ! in_array( $logged_key, $ordered_keys ) ) {
							error_log( 'Order Item Meta Data Error ' . $logged_key . ' is not part of the order ' . $order->get_id() );
							$log_data[] = 'Order Item Meta Data Error ' . $logged_key . ' is not part of the order ' . $order->get_id();
							$valid      = false;
						}
					}

				}
			}

			if ( ! $valid ) {
				$message = '<p>Error on Order Number: ' . $order->get_id() . '</p>';
				$message .= implode( '<br />', $log_data );
				$message .= '<p><a href="' . trailingslashit( get_admin_url() ) . 'admin.php?page=wc_debug_order_item_meta_data&wc_debug_log_entry_id=' . $log_entry['option_id'] . '">View Log</a>';
				wp_mail( get_bloginfo( 'admin_email' ), 'WooCommerce Order Error', $message );

				throw new Exception( 'Order Item Meta Data Error ' . $logged_key . ' is not part of the order ' . $order->get_id() );

			}
		}
	}

	public function get_order_item_meta_key() {
		return '_wc_debug_order_item_key';
	}

	public function log_error( $order_id, $data ) {

		$log_data = [
			'order_id'        => $order_id,
			'timestamp'       => time(),
			'current_user_id' => WC()->session->get_customer_id(),
			'data'            => $data
		];

		update_option( 'wc_debug_item_meta_' . $order_id, $log_data, false );
	}

	public function on_admin_menu() {
		$show_in_menu = current_user_can( 'manage_woocommerce' ) ? 'woocommerce' : false;
		$slug         = add_submenu_page( $show_in_menu, __( 'Debug Orders' ), __( 'Debug Orders' ), 'manage_woocommerce', 'wc_debug_order_item_meta_data', array(
			$this,
			'display_error_log'
		) );
	}

	public function display_error_log() {
		if ( isset( $_GET['wc_debug_log_entry_id'] ) ) {
			$log_entry = $this->get_debug_entry( intval( $_GET['wc_debug_log_entry_id'] ) );
			$order     = wc_get_order( intval( $log_entry['order_id'] ) );
			?>
            <div class="wrap woocommerce">
                <div class="icon32 woocommerce-dynamic-pricing" id="icon-woocommerce"><br></div>
                <h2 class="nav-tab-wrapper woo-nav-tab-wrapper">Order Item Meta Data Error Log</h2>

				<?php foreach ( $log_entry['data'] as $order_item_data ): ?>

                    <h3>Order Item ID: <?php echo $order_item_data['order_item_id']; ?></h3>

                    <h3>Cart Values</h3>
					<?php echo print_r( $order_item_data['cart_item_data'] ); ?>

                    <h3>Submitted Values</h3>
					<?php echo print_r( $order_item_data['meta_data'] ); ?>
                    <hr/>

                    <table>
                        <tr>
                            <th>Submitted Key</th>
                            <th>Submitted Value</th>
                            <th>Gravity Form Value</th>
                        </tr>
						<?php

						$order_items = $order->get_items();
						$log_entry   = $this->get_debug_entry_by_order( $order->get_id() );
						if ( $order_items ) {

							$valid    = true;
							$log_data = [];
							foreach ( $order_items as $order_item ) {
								$logged_order_item = wp_list_filter( $log_entry['data'], [ 'order_item_id' => $order_item->get_id() ] );
								if ( $logged_order_item ) {
									$logged_order_item          = array_shift( $logged_order_item );
									$logged_keys                = wp_list_pluck( $logged_order_item['meta_data'], 'key' );
									$logged_values              = wp_list_pluck( $logged_order_item['meta_data'], 'value', 'key' );
									$logged_gravity_form_values = wp_list_pluck( $logged_order_item['gravity_form_data'], 'value', 'name' );
									$meta_data                  = $order_item->get_meta_data();
									$ordered_keys               = [];
									foreach ( $meta_data as $meta_data_item ) {
										$d                  = $meta_data_item->get_data();
										$meta_data_values[] = $d;
										$ordered_keys[]     = $d['key'];
									}

									foreach ( $logged_keys as $logged_key ) {
										if ( ! in_array( $logged_key, $ordered_keys ) ) {
											echo '<tr><td>' . $logged_key . '</td><td>' . $logged_values[ $logged_key ] . '</td><td>' . $logged_gravity_form_values[ $logged_key ] . '</td></tr>';
										}
									}
								}
							}
						}

						?>
                    </table>
				<?php endforeach; ?>
            </div>
			<?php
		} else {
			require 'admin-table.php';
			?>

            <div class="wrap woocommerce">
                <div class="icon32 woocommerce-dynamic-pricing" id="icon-woocommerce"><br></div>
                <h2 class="nav-tab-wrapper woo-nav-tab-wrapper">Order Item Meta Data Errors</h2>

				<?php
				//Prepare Table of elements
				$wp_list_table = new WC_Debug_Order_Item_Meta_Data_Table();
				$wp_list_table->prepare_items();
				$wp_list_table->display();
				?>
            </div>

			<?php
		}
	}

	public function get_debug_entry( $entry_id ) {
		global $wpdb;
		$sql    = $wpdb->prepare( "SELECT option_value FROM {$wpdb->prefix}options WHERE option_id = %d", $entry_id );
		$result = $wpdb->get_var( $sql );
		$result = maybe_unserialize( $result );

		return $result;
	}

	public function get_debug_entry_by_order( $order_id ) {
		global $wpdb;
		$sql    = $wpdb->prepare( "SELECT option_id, option_value FROM {$wpdb->prefix}options WHERE option_name = %s", 'wc_debug_item_meta_' . $order_id );
		$result = $wpdb->get_row( $sql );

		$final_result              = maybe_unserialize( $result->option_value );
		$final_result['option_id'] = $result->option_id;

		return $final_result;
	}


}

WC_Debug_Order_Item_Meta_Data::register();
