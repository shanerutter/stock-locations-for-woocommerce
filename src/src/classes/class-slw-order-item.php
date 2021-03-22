<?php
/**
 * SLW Order Item Class
 *
 * @since 1.0.0
 */

namespace SLW\SRC\Classes;

use SLW\SRC\Classes\SlwAdminNotice;
use SLW\SRC\Helpers\SlwOrderItemHelper;
use SLW\SRC\Helpers\SlwStockAllocationHelper;
use SLW\SRC\Helpers\SlwWpmlHelper;

if ( !defined( 'WPINC' ) ) {
	die;
}

if( !class_exists('SlwOrderItem') ) {

	class SlwOrderItem
	{
		private $items;
		private $plugin_settings;
		private $show_in_cart;
		private $wc_manage_stock;
		private $wc_hold_stock_minutes;

		/**
		 * Construct.
		 *
		 * @since 1.1.0
		 */
		public function __construct()
		{
			add_action('woocommerce_admin_order_item_headers', array($this, 'add_stock_location_column_wc_order'), 10, 1);
			add_action('woocommerce_admin_order_item_values', array($this, 'add_stock_location_inputs_wc_order'), 10, 3);
			add_action('woocommerce_before_save_order_item', array($this, 'disable_wc_order_adjust_line_item_product_stock'), 99, 1);
			add_filter('woocommerce_hidden_order_itemmeta', array($this, 'hide_stock_locations_itemmeta_wc_order'), 10, 1);
			add_action('woocommerce_new_order_item', array($this, 'newOrderItemAllocateStock'), 10, 3);

			// get plugin settings
			$this->plugin_settings = get_option( 'slw_settings' );

			// get show in cart value from settings
			if( isset($this->plugin_settings['show_in_cart']) ) {
				$this->show_in_cart = $this->plugin_settings['show_in_cart'];
			}

			// check if we can include location data in formatted item meta
			if( isset($this->plugin_settings['include_location_data_in_formatted_item_meta']) && $this->plugin_settings['include_location_data_in_formatted_item_meta'] == 'yes' ) {
				add_filter( 'woocommerce_order_item_get_formatted_meta_data', array($this, 'include_location_data_in_formatted_item_meta'), 10, 2 );
			}

			// WC manage stock
			$this->wc_manage_stock = get_option( 'woocommerce_manage_stock' );
			// WC hold stock minutes
			$this->wc_hold_stock_minutes = get_option( 'woocommerce_hold_stock_minutes' );

			// Send copy of WC New Order email to location address
			if( isset($this->plugin_settings['wc_new_order_location_copy']) ) {
				add_filter( 'woocommerce_email_headers', array($this, 'wc_new_order_email_copy_to_locations_email'), 10, 3);
			}

			if( $this->wc_manage_stock === 'yes' ) {
				add_action( 'woocommerce_reduce_order_stock', array( $this, 'reduce_order_items_locations_stock' ), 10, 1 );
				add_action( 'woocommerce_restore_order_stock', array( $this, 'restore_order_items_locations_stock' ), 10, 1 );
			} else {
				// on maybe reduce stock levels
				add_action( 'woocommerce_payment_complete', array( $this, 'reduce_order_items_locations_stock' ), 10, 1 );
				add_action( 'woocommerce_order_status_completed', array( $this, 'reduce_order_items_locations_stock' ), 10, 1 );
				add_action( 'woocommerce_order_status_processing', array( $this, 'reduce_order_items_locations_stock' ), 10, 1 );
				add_action( 'woocommerce_order_status_on-hold', array( $this, 'reduce_order_items_locations_stock' ), 10, 1 );
				// on maybe restore stock levels
				add_action( 'woocommerce_order_status_cancelled', array( $this, 'restore_order_items_locations_stock' ), 10, 1 );
				add_action( 'woocommerce_order_status_pending', array( $this, 'restore_order_items_locations_stock' ), 10, 1 );
			}
		}

		public function disable_wc_order_adjust_line_item_product_stock( $item )
		{
			add_filter( 'woocommerce_prevent_adjust_line_item_product_stock', '__return_true' );
		}

		/**
		 * Adds custom column for Stock Location in WC Order items.
		 *
		 * @param $order
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public function add_stock_location_column_wc_order( $order )
		{
			if( empty($order) ) return;
			
			// display the column name
			echo '<th>' . __('Stock Locations', 'stock-locations-for-woocommerce') . '</th>';

			// Declare variable as array type
			$items = [];
			// Loop through order items
			foreach ( $order->get_items() as $item_id => $item ) {
				$product_id = $item['variation_id'] != 0 ? $item['variation_id'] : $item['product_id'];
				$product_id = SlwWpmlHelper::object_id( $product_id, get_post_type( $product_id ) );

				$items[] = [
					'product_id'    => $product_id,
					'order_item_id' => $item_id,
				];

				// Check if the stock locations are already updated in items of this order and show warning if necessary
				if( empty( wc_get_order_item_meta($item_id, '_item_stock_locations_updated', true) ) && $order->get_status() != 'completed' ) {
					SlwAdminNotice::displayWarning(__('Partial or total stock in locations is missing in this order. Please fill the remaining stock.', 'stock-locations-for-woocommerce'));
				}
			}
			// Assign variable to the class property
			$this->items = $items;
		}

		/**
		 * Adds inputs to custom column for Stock Locations in WC Order items.
		 *
		 * @param $product
		 * @param $item
		 * @param $item_id
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public function add_stock_location_inputs_wc_order( $product, $item, $item_id )
		{
			if( empty($item) || empty($product) || empty($item_id) ) return;

			// Add the missing stock location column to item shipping and others
			if( $item->get_type() == 'shipping' ) {
				echo '<td></td>';
			}

			$product_id = SlwWpmlHelper::object_id( $product->get_id(), $product->get_type() );
			$product    = wc_get_product( $product_id );
			if( empty($product) ) return;

			if( is_object($product) ) {

				// Check if product is a variation
				if( $product->get_type() === 'variation' ) {

					// Get variation parent id
					$parent_id = $item->get_product_id();

					// Get the variation id
					$variation_id = $product->get_ID();

					// Get the parent location terms
					$product_stock_location_terms = SlwStockAllocationHelper::getProductStockLocations($parent_id, true, null);

					// If parent doesn't have terms show message
					if(!$product_stock_location_terms) {
						echo '<td width="15%">';
						echo '<div display="block">' . __('To be able to manage the stock for this product, please add it to a <b>Stock location</b>!', 'stock-locations-for-woocommerce') . '</div>';
						echo '</td>';
					} else {
						// Add stock location inputs
						$this->product_stock_location_inputs($variation_id, $product_stock_location_terms, $item, $item_id);
					}

				} else {

					// Get the product id
					$product_id = $item->get_product_id();

					// Product location terms
					$product_stock_location_terms = SlwStockAllocationHelper::getProductStockLocations($product_id, true, null);

					// If product doesn't have terms show message
					if(!$product_stock_location_terms) {
						echo '<td width="15%">';
						echo '<div display="block">' . __('To be able to manage the stock for this product, please add it to a <b>Stock location</b>!', 'stock-locations-for-woocommerce') . '</div>';
						echo '</td>';
					} else {
						// Add stock location inputs
						$this->product_stock_location_inputs($product_id, $product_stock_location_terms, $item, $item_id);
					}

				}

			}

		}

		/**
		 * Creates the inputs for Stock Locations in WC Order items.
		 *
		 * @param $id
		 * @param $product_stock_location_terms
		 * @param $item
		 * @param $item_id
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public function product_stock_location_inputs( $id, $product_stock_location_terms, $item, $item_id )
		{
			$product_id = SlwWpmlHelper::object_id( $id, get_post_type( $id ) );
			$product    = wc_get_product( $product_id );
			if( empty($product) ) return;
			if( empty($item) ) return;

            // Add previous stock locations to view, this is so users can see how stock was previous allocated on past orders,
            // for example if 2 items where allocated to location 2, but location 2 is no longer a valid location for this stock item,
            // for this order the stock was stilled fulfilled by location 2 at the time of the order being processed.
            $product_stock_location_terms = SlwOrderItemHelper::productStockLocationsInputsAddPreviousStock($product_stock_location_terms, $item);

            // If product allows stock management
			if( $product->get_manage_stock() == 'true' ) {

				// Add the input field to values table
				echo '<td width="15%">';

					// Loop throw location terms
					foreach($product_stock_location_terms as $term) {

						// Define $args_1 as array type
						$args_1 = array(
							'type' => 'number'
						);

						// Get the item meta
						$postmeta_stock_at_term = $product->get_meta('_stock_at_' . $term->term_id);
						if(!$postmeta_stock_at_term) {
							$postmeta_stock_at_term = 0;
						}

						// Get the item meta
						$itemmeta_stock_update_at_term = wc_get_order_item_meta($item_id, '_item_stock_updated_at_' . $term->term_id, true);

						// If the order item has the stock locations updated, show the quantity already subtracted
						if( wc_get_order_item_meta($item_id, '_item_stock_locations_updated', true) === 'yes' ) {
							$args_1['custom_attributes'] = array('readonly' => 'readonly');
							$args_1['type'] = 'hidden';

							if($itemmeta_stock_update_at_term) {
								$args_1['label'] = $term->name . ' <b>(' . $postmeta_stock_at_term . ')</b> <span style="color:green;">-' . $itemmeta_stock_update_at_term . '</span>';
							} else {
								$args_1['label'] = $term->name . ' <b>(' . $postmeta_stock_at_term . ')</b>';
							}

						} else {
							$args_1['label'] = $term->name . ' <b>(' . $postmeta_stock_at_term . ')</b>';
						}

						// If this location doesn't have stock don't show the input
						if( empty($postmeta_stock_at_term) || $postmeta_stock_at_term <= 0 ) {
							$args_1['description'] = __("This location doesn't have stock and can't be subtracted.", 'stock-locations-for-woocommerce');
							$args_1['type'] = 'hidden';
						} else {
							$args_1['description'] = __( 'Enter the stock amount you want to subtract from this location.', 'stock-locations-for-woocommerce' );
						}

						// Define $args_2 array
						$args_2 = array(
							'id'                => SLW_PLUGIN_SLUG . '_oitem_' . $item_id . '_' . $id . '_' . $term->term_id,
							'desc_tip'          => true,
							'class'             => 'woocommerce ' . SLW_PLUGIN_SLUG . '_oitem_' . $id . ' ' . SLW_PLUGIN_SLUG . '_oitem',
							'value'             => '0',
						);

						// Merge the two arrays
						$args = array_merge($args_1, $args_2);

						// Create the input
						if( function_exists('woocommerce_wp_text_input') ) {
							woocommerce_wp_text_input($args);
						}
						
						// Show location choosed by client in cart
						if( !empty($this->show_in_cart) && $this->show_in_cart == 'yes' ) {
							$client_item_stock_location_id = $item->get_meta('_stock_location');
							$stock_location = SlwStockAllocationHelper::get_product_stock_location( $id, $client_item_stock_location_id );
							if( $term->term_id == $client_item_stock_location_id ) {
								echo '<span class="slw-client-choosed-location">✔ <strong>'.__('Client choosed: ', 'stock-locations-for-woocommerce').'</strong><u>'.$stock_location[$client_item_stock_location_id]->name.'</u></span>';
							}
						}

					}

				echo '</td>';

			} else {

				// Show message if the product/variant doesn't allow stock management
				echo '<td width="15%">';
				echo '<div display="block">' . __("This product/variation don't have stock management activated.", 'stock-locations-for-woocommerce') . '</div>';
				echo '</td>';

			}

		}

		/**
		 * Reduces order items locations stock.
		 *
		 * @param $order  can be the order ID in some hooks
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public function reduce_order_items_locations_stock( $order )
		{
			if( empty( $order ) ) return;

			// some actions provide the order_id directly instead of the order object
			if( ! is_object( $order ) ) {
				$order_id = $order;
				$order    = wc_get_order( $order_id );
			}

			// Loop through order items
			foreach ( $order->get_items() as $item => $item_data ) {
				// Product ID
				$pid = ($item_data->get_variation_id()) ? $item_data->get_variation_id() : $item_data->get_product_id();
				$pid = SlwWpmlHelper::object_id( $pid, get_post_type( $pid ) );

				// Not managed stock
				if (!SlwStockAllocationHelper::isManagedStock($pid)) {
					continue;
				}

				// Get locations
				$locations = SlwStockAllocationHelper::getProductStockLocations($pid, false);

				// No locations set
				if (empty($locations)) {
					continue;
				}

				// Convert POST data to array
				$simpleLocationAllocations = array();
				foreach ($locations as $location) {
					$productId = $item_data->get_product()->get_id();
					$productId = SlwWpmlHelper::object_id( $productId, get_post_type( $productId ) );
					$postIdx   = SLW_PLUGIN_SLUG . '_oitem_' . $item_data->get_id() . '_' . $productId . '_' . $location->term_id;

					if (!isset($_POST[$postIdx])) {
						continue;
					}

					$simpleLocationAllocations[$location->term_id] = $_POST[$postIdx];
				}

				// No location stock data for line
				if (empty($simpleLocationAllocations)) {
					continue;
				}

				// Allocate stock to locations
				$locationStockAllocationResponse = SlwOrderItemHelper::allocateLocationStock( $item_data->get_id(), $simpleLocationAllocations, $allocationType = 'manual' );

				// Check if stock in locations are updated for this item
				if(!$locationStockAllocationResponse) {
					SlwAdminNotice::displayWarning(__('Partial or total stock in locations is missing in this order. Please fill the remaining stock.', 'stock-locations-for-woocommerce'));
				} else {
					SlwAdminNotice::displaySuccess(__('Stock in locations updated successfully!', 'stock-locations-for-woocommerce'));
				}
			}

		}

		/**
		 * Hides Stock Location item meta from WC Order.
		 *
		 * @since 1.0.1
		 * @return array
		 */
		public function hide_stock_locations_itemmeta_wc_order( $arr )
		{
			// Get an instance of the WC_Order object
			$order = wc_get_order( get_the_id() );

			if( !empty($order) && !empty($order->get_items()) ) {
				// Loop through order items
				foreach ( $order->get_items() as $item_id => $item ) {
					$product_id = $item['variation_id'] != 0 ? $item['variation_id'] : $item['product_id'];
					$product_id = SlwWpmlHelper::object_id( $product_id, get_post_type( $product_id ) );
					$product    = wc_get_product( $product_id );
					if( empty( $product ) ) continue;

					// Get item location terms
					$item_stock_location_terms = SlwStockAllocationHelper::getProductStockLocations($product_id, true, null);

					if( !empty($item_stock_location_terms) ) {
						// Loop through location terms
						foreach ( $item_stock_location_terms as $term ) {
							$arr[] = '_item_stock_updated_at_' . $term->term_id;
							$arr[] = '_stock_location';
							$arr[] = '_slw_notification_mail_output';
							$arr[] = 'stock_location_' . $term->term_id;
						}
					}

				}

				$arr[] = '_item_stock_locations_updated';
			}

			return $arr;
		}

		/**
		 * New orders allocate stock to items if required
		 *
		 * @param $item_id
		 * @param $item
		 * @param $order_id
		 */
		public function newOrderItemAllocateStock( $item_id, $item, $order_id )
		{
			// add exception to third party plugins
			$disallow = apply_filters( 'slw_disallow_third_party_allocate_order_item_stock', true );
			if( is_admin() && $disallow ) {
				return;
			}

			// This is not the correct product
			if( !($item instanceof \WC_Order_Item_Product) ) {
				return;
			}

			// Get product ID
			$productId  = $item->get_variation_id() != 0 ? $item->get_variation_id() : $item->get_product_id();
			$productId  = SlwWpmlHelper::object_id( $productId, get_post_type( $productId ) );
			
			// Get item quantity
			$itemQuantity = $item->get_quantity();
			
			// Check if customer selected a location
			$userLocationChoiceId = null;
			$userStockLocation = null;
			if( !empty($userLocationChoiceId = $item->get_meta('_stock_location')) ) {
				if( !empty($userStockLocation = SlwStockAllocationHelper::get_product_stock_location($productId, $userLocationChoiceId)) ) {
					// get location meta
					$location_meta = SlwStockAllocationHelper::getLocationMeta($userLocationChoiceId);
					// check if location has auto allocation enabled
					if( isset($location_meta['slw_auto_allocate']) && $location_meta['slw_auto_allocate'] == 1 ) {
						if( $userStockLocation[$userLocationChoiceId]->quantity > $itemQuantity ) {
							$userStockLocation[$userLocationChoiceId]->allocated_quantity = $itemQuantity;
						} else {
							$itemQuantity = $itemQuantity - $userStockLocation[$userLocationChoiceId]->quantity;
							$userStockLocation[$userLocationChoiceId]->allocated_quantity = $userStockLocation[$userLocationChoiceId]->quantity;
						}
					} else {
						return; // user selected location doesn't have auto allocation enabled so finish here and let the admin choose from the order
					}
				}
			}

			// Get product stock allocation locations if customer haven't select a location
			if( is_null($userStockLocation) ) {
				$stockAllocation = SlwStockAllocationHelper::getStockAllocation($productId, $itemQuantity, null);
			}

			// define stock allocation
			if( !is_null($userStockLocation) ) {
				// if user selected a location and has auto allocation enabled
				$stockAllocation = $userStockLocation;
			} elseif( is_null($userStockLocation) && isset($stockAllocation) && is_array($stockAllocation) ) {
				// if user haven't selected a location define by available locations for this product
				$stockAllocation = $stockAllocation;
			} else {
				// finish here if we don't have stock allocation set
				return;
			}
			
			// If WC manage stock is enabled
			if ( $this->wc_manage_stock == 'yes' && !empty($this->wc_hold_stock_minutes) ) {
				// Allocations exist, disable WC hold stock
				add_filter( 'woocommerce_hold_stock_for_checkout', '__return_false' );
			}

			// Build simple location term to stock quantity allocation array
			$simpleLocationAllocations = array();
			foreach ($stockAllocation as $allocation) {
				$simpleLocationAllocations[$allocation->term_id] = $allocation->allocated_quantity;
			}

			// Allocate order item stock to locations
			SlwOrderItemHelper::allocateLocationStock( $item->get_id(), $simpleLocationAllocations, $allocationType = 'auto' );

		}
		
		/**
		 * Adds stock location data to item formatted meta.
		 *
		 * @since 1.2.4
		 * @return array
		 */
		public function include_location_data_in_formatted_item_meta( $formatted_meta, $item )
		{
			if( !empty($item) ) {
				if( !empty($item_location_data = $item->get_meta('_slw_data')) ) {
					foreach( $item_location_data as $location_id => $data ) {
						$value = $data['location_name'].' (-'.$data['quantity_subtracted'].')';
						$formatted_meta[] = (object) array(
							'key' 			=> 'stock_location_' . $location_id,
							'display_key'	=> __('Stock location', 'stock-locations-for-woocommerce'),
							'value'			=> $value,
							'display_value'	=> '<p>'.$value.'</p>'
						);
					}
				}
			}
			return $formatted_meta;
		}

		/**
		 * Adds stock location email address to WC new order email.
		 *
		 * @since 1.3.0
		 * @return array
		 */
		public function wc_new_order_email_copy_to_locations_email( $headers, $email_id, $order, $email = null )
		{
			if( $email_id == 'new_order' && !empty($order) ) {
				$emails = array();
				foreach( $order->get_items() as $item_id => $item ) {
					if( $item->get_type() == 'line_item' ) {
						$item_slw_data = $item->get_meta('_slw_data');
						if( !empty($item_slw_data) ) {
							foreach( $item_slw_data as $location_id => $location ) {
								$location_meta = SlwStockAllocationHelper::getLocationMeta( $location_id );
								if( !empty($location_meta) && isset($location_meta['slw_location_email']) && is_email($location_meta['slw_location_email']) ) {
									$location_term = get_term_by('id', $location_id, SlwLocationTaxonomy::$tax_singular_name);
									$emails[$location_id]['name'] = $location_term->name;
									$emails[$location_id]['email'] = $location_meta['slw_location_email'];
								}
							}
						}
					}
				}
				// add email addresses to headers
				if( !empty($emails) ) {
					foreach( $emails as $key => $value ) {
						$headers .= 'BCC: '.$value['name'].' <'.$value['email'].'>' . "\r\n";
					}
				}
			}
			return $headers; 
		}

		/**
		 * Restore locations stock on WC restore
		 *
		 * @since 1.3.3
		 * @return void
		 */
		public function restore_order_items_locations_stock( $order )
		{
			if( empty($order) || ! is_object($order) ) return;

			$wc_order_stock_reduced = get_post_meta( $order->get_id(), '_order_stock_reduced', true );
			if( ! $wc_order_stock_reduced ) return;

			foreach( $order->get_items( 'line_item' ) as $item_id => $item ) {
				$product_id = $item['variation_id'] != 0 ? $item['variation_id'] : $item['product_id'];
				$product_id = SlwWpmlHelper::object_id( $product_id, get_post_type( $product_id ) );
				$product    = wc_get_product( $product_id );
				if( empty($product) ) continue;

				if ( ! SlwStockAllocationHelper::isManagedStock( $product_id ) ) continue;

				$itemStockLocationTerms = SlwStockAllocationHelper::getProductStockLocations( $product_id, false );
				if( empty($itemStockLocationTerms) ) continue;

				$slw_data = wc_get_order_item_meta( $item_id, '_slw_data', true );
				if( empty($slw_data) ) continue;

				foreach( $itemStockLocationTerms as $location_id => $location ) {
					if( isset( $slw_data[$location_id] ) ) {
						// update the product location stock
						update_post_meta( $product_id, '_stock_at_' . $location_id, $location->quantity + $slw_data[$location_id]['quantity_subtracted'] );

						// delete the order item meta
						wc_delete_order_item_meta( $item_id, '_item_stock_locations_updated' );
						wc_delete_order_item_meta( $item_id, '_item_stock_updated_at_' . $location_id );
						wc_delete_order_item_meta( $item_id, '_slw_data' );

						// add order note
						$order->add_order_note(
							sprintf( __('The stock in the location %1$s was restores in %2$d for the product %3$s', 'stock-locations-for-woocommerce'), $location->name, $slw_data[$location_id]['quantity_subtracted'], $product->get_name() )
						);
					}
				}
			}
		}

	}

}
