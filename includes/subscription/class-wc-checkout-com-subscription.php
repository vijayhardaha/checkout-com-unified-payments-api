<?php

require_once __DIR__ . '/../api/class-wc-checkoutcom-api-request.php';

/**
 *  This class handles the payment for subscription renewal
 */
class WC_Checkoutcom_Subscription {

	public static function renewal_payment( $renewal_total, $renewal_order ) {

		// Get renewal order ID
		$order_id = $renewal_order->get_id();

		$args = array();

		// Get subscription object from the order
		if ( wcs_order_contains_subscription( $renewal_order, 'renewal' ) ) {
			$subscriptions_arr = wcs_get_subscriptions_for_order( $renewal_order, array( 'order_type' => 'renewal' ) );
		}

		foreach ( $subscriptions_arr as $subscriptions_obj ) {
			$args['source_id']       = get_post_meta( $subscriptions_obj->get_id(), '_cko_source_id', true );
			$args['parent_order_id'] = $subscriptions_obj->data['parent_id'];
		}

		$payment_result = (array) WC_Checkoutcom_Api_request::create_payment( $renewal_order, $args, 'renewal' );

		// Update renewal order status based on payment result
		if ( ! isset( $payment_result['error'] ) && empty( $payment_result['error'] ) ) {
			self::update_order_status( $payment_result, $renewal_order, $order_id );
		}
	}

	/**
	 *  Update status of renewal order and add notes
	 *
	 *  @param array  $payment_result
	 *  @param object $renewal_order
	 */
	public static function update_order_status( $payment_result, $renewal_order, $order_id ) {

		// Set action id as woo transaction id
		update_post_meta( $order_id, '_transaction_id', $payment_result['action_id'] );
		update_post_meta( $order_id, '_cko_payment_id', $payment_result['id'] );

		// Get cko auth status configured in admin
		$status  = WC_Admin_Settings::get_option( 'ckocom_order_authorised' );
		$message = __( 'Checkout.com Payment Authorised ' . '</br>' . " Action ID : {$payment_result['action_id']} ", 'wc_checkout_com' );

		// check if payment was flagged
		if ( $payment_result['risk']['flagged'] ) {
			// Get cko auth status configured in admin
			$status  = WC_Admin_Settings::get_option( 'ckocom_order_flagged' );
			$message = __( 'Checkout.com Payment Flagged ' . '</br>' . " Action ID : {$payment_result['action_id']} ", 'wc_checkout_com' );
		}

		// add notes for the order
		$renewal_order->add_order_note( $message );

		$order_status = $renewal_order->get_status();

		if ( $order_status == 'pending' ) {
			update_post_meta( $order_id, 'cko_payment_authorized', true );
			$renewal_order->update_status( $status );
		}

		// Reduce stock levels
		wc_reduce_stock_levels( $order_id );

	}

	/**
	 *  Save source id for each order containing subscription
	 *
	 *  @param $order_id
	 *  @param object   $order
	 *  @param string   $source_id
	 */
	public static function save_source_id( $order_id, $order, $source_id ) {

		// update source id for subscription payment method change
		if ( $order instanceof WC_Subscription ) {
			update_post_meta( $order->get_id(), '_cko_source_id', $source_id );
		}

		// check for subscription and save source id
		if ( class_exists( 'WC_Subscriptions_Order' ) ) {
			if ( WC_Subscriptions_Order::order_contains_subscription( $order_id ) ) {
				$subscriptions = wcs_get_subscriptions_for_order( $order );

				foreach ( $subscriptions as $subscription_obj ) {
					update_post_meta( $subscription_obj->get_id(), '_cko_source_id', $source_id );
				}
			}
		}

		return false;
	}
}
