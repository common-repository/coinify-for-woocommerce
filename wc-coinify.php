<?php
/*
Plugin Name: WooCommerce Coinify
Plugin URI: https://coinify.com/developers/plugins
Description: Extends WooCommerce with an bitcoin gateway.
Version: 1.2
Author: Coinify Tech
Author URI: https://coinify.com
*/
require_once 'CoinifyAPI.php';
require_once 'CoinifyCallback.php';

/**
 * Returns current plugin data.
 * 
 * @return array Plugin data
 */
function plugin_get_data() {
	if ( ! function_exists( 'get_plugins' ) )
		require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
	$plugin_folder = get_plugins( '/' . plugin_basename( dirname( __FILE__ ) ) );
	$plugin_file = basename( ( __FILE__ ) );
	return $plugin_folder[$plugin_file];
}

if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) 
{
	add_filter('woocommerce_payment_gateways', 'woocommerce_add_coinify_gateway');
	add_action('plugins_loaded', 'woocommerce_coinify_init', 0, 0);

	/**
	* Add the Gateway to WooCommerce
	**/
	function woocommerce_add_coinify_gateway($methods)
	{
		$methods[] = 'WC_coinify';
		return $methods;
	}

	function woocommerce_coinify_init()
	{
		if (!class_exists('WC_Payment_Gateway'))
		{
			return;
		}	

		class WC_coinify extends WC_Payment_Gateway
		{
			public function __construct()
			{
				$this->id = 'coinify';
				$this->icon = plugins_url( 'images/bitcoin.png', __FILE__ );
				$this->has_fields = false;

				$this->init_form_fields();
				$this->init_settings();

				$this->title = $this->settings['title'];
				$this->description = $this->settings['description'];
				$this->apikey = $this->settings['apikey'];
				$this->secret = $this->settings['secret'];
				$this->secretipn = $this->settings['secretipn'];
				$this->debug = $this->settings['debug'];

				$this->msg['message'] = "";
				$this->msg['class'] = "";

				add_action('init', array(&$this, 'check_coinify_response'));

				add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(&$this, 'process_admin_options'));

				add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'check_coinify_response' ) );

				// Valid for use.
				$this->enabled = (($this->settings['enabled'] && !empty($this->apikey) && !empty($this->secret)) ? 'yes' : 'no');

				// Checking if apikey is not empty.
				$this->apikey == '' ? add_action( 'admin_notices', array( &$this, 'apikey_missing_message' ) ) : '';

				// Checking if app_secret is not empty.
				$this->secret == '' ? add_action( 'admin_notices', array( &$this, 'secret_missing_message' ) ) : '';
			}

			function init_form_fields()
			{
				$this->form_fields = array(
					'enabled' => array(
						'title' => __( 'Enable/Disable', 'coinify' ),
						'type' => 'checkbox',
						'label' => __( 'Enable coinify', 'coinify' ),
						'default' => 'yes'
					),
					'title' => array(
						'title' => __( 'Title', 'coinify' ),
						'type' => 'text',
						'description' => __( 'This controls the title which the user sees during checkout.', 'coinify' ),
						'default' => __( 'coinify', 'coinify' )
					),
					'description' => array(
						'title' => __( 'Description', 'coinify' ),
						'type' => 'textarea',
						'description' => __( 'This controls the description which the user sees during checkout.', 'coinify' ),
						'default' => __( 'You will be redirected to coinify.com to complete your purchase.', 'coinify' )
					),
					'apikey' => array(
						'title' => __( 'API Key', 'coinify' ),
						'type' => 'password',
						'description' => __( 'Please enter your Coinify API key', 'coinify' ) . ' ' . sprintf( __( 'You can get this information in: %sCoinify Account%s.', 'coinify' ), '<a href="https://www.coinify.com/merchant/api" target="_blank">', '</a>' ),
						'default' => ''
					),
					'secret' => array(
						'title' => __( 'API Secret', 'coinify' ),
						'type' => 'password',
						'description' => __( 'Please enter your Coinify API Secret', 'coinify' ) . ' ' . sprintf( __( 'You can get this information in: %sCoinify Account%s.', 'coinify' ), '<a href="https://www.coinify.com/merchant/api" target="_blank">', '</a>' ),
						'default' => ''
					),
					'secretipn' => array(
						'title' => __( 'IPN Secret', 'coinify' ),
						'type' => 'password',
						'description' => __( 'Please enter your Coinify IPN Secret', 'coinify' ) . ' ' . sprintf( __( 'You can get this information in: %sCoinify Account%s.', 'coinify' ), '<a href="https://www.coinify.com/merchant/ipn" target="_blank">', '</a>' ),
						'default' => ''
					),
					'debug' => array(
						'title' => __( 'Debug Log', 'coinify' ),
						'type' => 'checkbox',
						'label' => __( 'Enable logging', 'coinify' ),
						'default' => 'no',
						'description' => __( 'Log coinify events, such as API requests, inside <code>woocommerce/logs/coinify.txt</code>', 'coinify'  ),
					)
				);
			}

			public function admin_options()
			{
				?>
				<h3><?php _e('Coinify Checkout', 'coinify');?></h3>

				<div id="wc_get_started">
					<span class="main"><?php _e('Provides a secure way to accept bitcoins.', 'coinify'); ?></span>
					<p><a href="https://coinify.com/signup" target="_blank" class="button button-primary"><?php _e('Join for free', 'coinify'); ?></a> <a href="https://coinify.com/developers/plugins" target="_blank" class="button"><?php _e('Learn more about WooCommerce and coinify', 'coinify'); ?></a></p>
				</div>

				<table class="form-table">
					<?php $this->generate_settings_html(); ?>
				</table>
				<?php
			}

			/**
			*  There are no payment fields for coinify, but we want to show the description if set.
			**/
			function payment_fields()
			{
				if ($this->description)
					echo wpautop(wptexturize($this->description));
			}


			/**
			* Process the payment and return the result
			**/
			function process_payment($order_id)
			{				
				$order = new WC_Order($order_id);

				$item_names = array();

				if (sizeof($order->get_items()) > 0) : foreach ($order->get_items() as $item) :
					if ($item['qty']) $item_names[] = $item['name'] . ' x ' . $item['qty'];
				endforeach; endif;

				$item_name = sprintf( __('Order %s' , 'woocommerce'), $order->get_order_number() ) . " - " . implode(', ', $item_names);

				$custom_data = array(
					'email' => $order->billing_email,
					'order_id' => $order->id,
					'order_key' => $order->order_key
				);

				$plugin_data = plugin_get_data();
				$api = new CoinifyAPI($this->apikey, $this->secret);

				$amount = number_format($order->order_total, 2, '.', '');
				$currency = get_woocommerce_currency();
				
				$plugin_name = $plugin_data['Name'];
				$plugin_version = $plugin_data['Version'];
				$description = " ";
				$custom = $custom_data;
				$callback_url = get_site_url() . '/?wc-api=WC_coinify';
				$callback_email = null;
				$return_url = $this->get_return_url($order);
				$cancel_url = $order->get_cancel_order_url();

				$result = $api->invoiceCreate($amount, $currency, 
						$plugin_name, $plugin_version,
						$description, $custom_data, 
						$callback_url, $callback_email, 
						$return_url, $cancel_url);

				if($result['success']) {
					return array(
						'result' => 'success',
						'redirect' => $result['data']['payment_url']
					);

				}else{
					if ($this->debug=='yes') 
						error_log(json_encode($result) . json_encode($plugin_data));
					return array('result'=>'failed');
				}
			}

			/**
			* Check for valid coinify server callback
			**/
			function check_coinify_response()
			{
				$signature = $_SERVER['HTTP_X_COINIFY_CALLBACK_SIGNATURE'];
				$body = file_get_contents('php://input');
				$arr = json_decode($body, true);

				// Always reply with a HTTP 200 OK status code and an empty body, 
				// regardless of the result of validating the callback
				header('HTTP/1.1 200 OK');
				
				$callback = new CoinifyCallback($this->secretipn);

				if (!$callback->validateCallback($body, $signature)) {
					if ($this->debug=='yes'){
						error_log('Coinify Payment error: Signature does not match'. json_encode($body) ." ".json_encode($signature));
					}
					exit;
				}

				$order_id = intval($arr["data"]['custom']['order_id']);
				$order_key = $arr["data"]['custom']['order_key'];

				$order = new WC_Order($order_id);

				// Checking if we should process the order
				if ($order->order_key !== $order_key) {
					if ($this->debug=='yes') 
						error_log('Coinify: Error. Order Key does not match invoice. '. json_encode($arr). '!='. json_encode($order) );
					exit;
				}

				if ($order->status == 'completed') {
					if ($this->debug=='yes') 
						error_log('coinify: Aborting, Order #' . $order_id . ' is already complete.' );
					exit;
				}
				
				// Validate Amount
				if ($arr["data"]['native']['amount'] < $order->get_total())
				{
					if ($this->debug=='yes') 
						error_log('Coinify: Payment error. Amounts do not match (gross ' . $arr["data"]['native']['amount'] . ')' );
					// Put this order on-hold for manual checking
					$order->update_status( 'on-hold', sprintf( __( 'Coinify IPN: Validation error, amounts do not match (gross %s).', 'woocommerce' ), $arr["data"]['native']['amount'] ) );

					exit;
				}

				switch ($arr['data']['state']) {
					case 'paid':
						$order->add_order_note( __('Coinify IPN: Payment received, waiting to be completed', 'woocommerce') );
						break;
					case 'complete': {
						// Payment completed
						$order->add_order_note( __('Coinify IPN: Payment completed', 'woocommerce') );
						$order->payment_complete();

						if ($this->debug=='yes') error_log('Coinify: '.'Payment complete.' );
						break;
					}
					case 'expired': {
						$order->update_status( 'failed', __( 'Coinify IPN: Payment has expired', 'woocommerce' ) );
						break;
					}
				}
			}

			/**
			 * Adds error message when not configured the api key.
			 *
			 * @return string Error Mensage.
			 */
			public function apikey_missing_message() {
				$message = '<div class="error">';
					$message .= '<p>' . sprintf( __( '<strong>Gateway Disabled</strong> You should enter your Invoice API key in coinify configuration. %sClick here to configure!%s' , 'wccoinify' ), '<a href="' . get_admin_url() . 'admin.php?page=wc-settings&amp;tab=checkout&amp;section=wc_coinify">', '</a>' ) . '</p>';
				$message .= '</div>';

				echo $message;
			}

			/**
			 * Adds error message when not configured the secret.
			 *
			 * @return String Error Mensage.
			 */
			public function secret_missing_message() {
				$message = '<div class="error">';
					$message .= '<p>' . sprintf( __( '<strong>Gateway Disabled</strong> You should enter your IPN secret in coinify configuration. %sClick here to configure!%s' , 'wccoinify' ), '<a href="' . get_admin_url() . 'admin.php?page=wc-settings&amp;tab=checkout&amp;section=wc_coinify">', '</a>' ) . '</p>';
				$message .= '</div>';

				echo $message;
			}
		}
	}
}