<?php include('checksum.php');?>
<?php
/*
Plugin Name: WooCommerce Zaakpay gateway
Plugin URI: http://zaakpay.com/
Description: Used to integrate the Zaakpay payment gateway with your website.
Version: 0.1
Author: Varun


    License: GNU General Public License v3.0
    License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

add_action('plugins_loaded', 'woocommerce_Zaakpay_init', 0);

function woocommerce_Zaakpay_init() {

    if ( !class_exists( 'WC_Payment_Gateway' ) ) return;

    /**
     * Localisation
     */
    load_plugin_textdomain('wc-Zaakpay', false, dirname( plugin_basename( __FILE__ ) ) . '/languages');
    if($_GET['msg']!=''){
        add_action('the_content', 'showMessage');
    }
   
     function showMessage($content){
            return '<div class="box '.htmlentities($_GET['type']).'-box">'.htmlentities(urldecode($_GET['msg'])).'</div>'.$content;
    }
    /**
     * Gateway class
     */
    class WC_Zaakpay extends WC_Payment_Gateway {
	protected $msg = array();
        public function __construct(){  // construct form //
            // Go wild in here
            $this -> id = 'Zaakpay';
            $this -> method_title = __('Zaakpay');
            $this -> icon = WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/images/logo.gif';
            $this -> has_fields = false;
            $this -> init_form_fields();
            $this -> init_settings();
            $this -> title = $this -> settings['title'];
            $this -> description = $this -> settings['description'];
            $this -> merchantIdentifier = $this -> settings['merchantIdentifier'];
            $this -> secret_key = $this -> settings['secret_key'];
            $this -> redirect_page_id = $this -> settings['redirect_page_id'];
			$this -> mode = $this -> settings['mode'];
			$this -> log = $this -> settings['log'];
			$this -> liveurl = "https:/api.zaakpay.com/transact?v=2";
            $this -> msg['message'] = "";
            $this -> msg['class'] = "";	
			
			add_action('init', array(&$this, 'check_Zaakpay_response'));
            //update for woocommerce >2.0
			add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'check_Zaakpay_response' ) );
            add_action('valid-Zaakpay-request', array(&$this, 'successful_request')); // this save
            if ( version_compare( WOOCOMMERCE_VERSION, '2.0.0', '>=' ) ) {
                add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( &$this, 'process_admin_options' ) );
             } else {
                add_action( 'woocommerce_update_options_payment_gateways', array( &$this, 'process_admin_options' ) );
            }
            add_action('woocommerce_receipt_Zaakpay', array(&$this, 'receipt_page'));
            add_action('woocommerce_thankyou_Zaakpay',array(&$this, 'thankyou_page'));
        }
        function init_form_fields(){   

            $this -> form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable'),
                    'type' => 'checkbox',
                    'label' => __('Enable Zaakpay Payment Module.'),
                    'default' => 'no'),
                'title' => array(
                    'title' => __('Title:'),
                    'type'=> 'text',
                    'description' => __('This controls the title which the user sees during checkout.'),
                    'default' => __('Zaakpay')),
                'description' => array(
                    'title' => __('Description:'),
                    'type' => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.'),
                    'default' => __('The best payment gateway provider in India for e-payment through credit card, debit card & netbanking.')),

                'merchantIdentifier' => array(
                    'title' => __('Merchant Identifier'),
                    'type' => 'text',
                    'description' => __('This id(USER ID) available at "Generate Secret Key" of "Integration -> Card payments integration at Zaakpay."')),
                'secret_key' => array(
                    'title' => __('Secret Key'),
                    'type' => 'text',
                    'description' =>  __('Given to Merchant by Zaakpay'),
					),
                'redirect_page_id' => array(
                    'title' => __('Return Page'),
                    'type' => 'select',
                    'options' => $this -> get_pages('Select Page'),
                    'description' => "URL of success page"
                ),
				'mode' => array(
                    'title' => __('Mode'),
                    'type' => 'text',
                    'options' => 'text',
                    'description' => "Mode of transaction. (1=LIVE, 0=TEST)"
                ),
				'log' => array(
                    'title' => __('Do you want to log'),
                    'type' => 'text',
                    'options' => 'text',
                    'description' => "(yes/no)"
                )
            );


        }
		
		
        /**
         * Admin Panel Options
         * - Options for bits like 'title' and availability on a country-by-country basis
         **/
        public function admin_options(){
            echo '<h3>'.__('Zaakpay Payment Gateway').'</h3>';
            echo '<p>'.__('India online payment solutions for all your transactions by Zaakpay').'</p>';
            echo '<table class="form-table">';
            $this -> generate_settings_html();
            echo '</table>';

        }
        /**
         *  There are no payment fields for Zaakpay, but we want to show the description if set.
         **/
        function payment_fields(){
            if($this -> description) echo wpautop(wptexturize($this -> description));
        }
        /**
         * Receipt Page
         **/
        function receipt_page($order){
            echo '<p>'.__('Thank you for your order, please click the button below to pay with Zaakpay.').'</p>';
            echo $this -> generate_Zaakpay_form($order);
        }
        /**
         * Process the payment and return the result
         **/
        function process_payment($order_id){
            if ( version_compare( WOOCOMMERCE_VERSION, '2.0.0', '>=' ) ) {
                $order = &new WC_Order($order_id);
             } else {
                $order = &new woocommerce_order($order_id);
            }
            return array('result' => 'success', 'redirect' => add_query_arg('order',
                $order->id, add_query_arg('key', $order->order_key, get_permalink(get_option('woocommerce_pay_page_id'))))
            );
        }
		
		
		/**
         * Check for valid Zaakpay server callback // response processing //
         **/
        function check_Zaakpay_response(){	
		    global $woocommerce;			
			if(isset($_REQUEST['orderId']) && isset($_REQUEST['responseCode'])){
			    $order_sent = $_REQUEST['orderId'];
			    $responseDescription = $_REQUEST['responseDescription'];
				if ( version_compare( WOOCOMMERCE_VERSION, '2.0.0', '>=' ) ) {
                $order = new WC_Order($_REQUEST['orderId']);
				} else {
					$order = new woocommerce_order($_REQUEST['orderId']);
				}
				if($this -> log == "yes"){error_log("Response Code = " . $_REQUEST['responseCode']);}
			    $redirect_url = ($this -> redirect_page_id=="" || $this -> redirect_page_id==0)?get_site_url() . "/":get_permalink($this -> redirect_page_id);
				$this -> msg['class'] = 'error';
                $this -> msg['message'] = "Thank you for shopping with us. However, the transaction has been Failed For Reason  : " . $responseDescription;
				if($_REQUEST['responseCode'] == 100) { // success
					
					$order_amount = $order -> order_total;
					$order_amount = (int)($order_amount * 100);
					if(($_REQUEST['amount']	== $order_amount)){
						if($this -> log == "yes"){error_log("amount matched");}
						$all = Checksum::getAllParams();
						if($this -> log == "yes"){error_log("received parameters = " . $all);}
						$newcheck = Checksum::calculateChecksum($this->secret_key, $all);
						if($this -> log == "yes"){error_log("calculated checksum = " . $newch . " and checksum received = " . $_REQUEST['checksum']);}
                        if($newcheck == $_REQUEST['checksum']){							
							if($order -> status !=='completed'){
							    error_log("SUCCESS");
								$this -> msg['message'] = "Thank you for shopping with us. Your account has been charged and your transaction is successful.";
								$this -> msg['class'] = 'success';
								if($order -> status == 'processing'){

								} else {
									$order -> payment_complete();
									$order -> add_order_note('Mobile Wallet payment successful');
									$order -> add_order_note($this->msg['message']);
									$woocommerce -> cart -> empty_cart();

								}
							}						
						}
						else{
							// server to server failed while call//
							//error_log("api process failed");	
							$this -> msg['class'] = 'error';
							$this -> msg['message'] = "Severe Error Occur.";
							$order -> update_status('failed');
							$order -> add_order_note('Failed');
							$order -> add_order_note($this->msg['message']);
						}
						
					}
                    else{
						// Order mismatch occur //
						//error_log("order mismatch");	
						$this -> msg['class'] = 'error';
						$this -> msg['message'] = "Order Mismatch Occur";
						$order -> update_status('failed');
						$order -> add_order_note('Failed');
						$order -> add_order_note($this->msg['message']);
						
					}					
				}
				else{
					$order -> update_status('failed');
					$order -> add_order_note('Failed');
					$order -> add_order_note($responseDescription);
					$order -> add_order_note($this->msg['message']);
				
				}
				add_action('the_content', array(&$this, 'showMessage'));
				
				$redirect_url = ($this -> redirect_page_id=="" || $this -> redirect_page_id==0)?get_site_url() . "/":get_permalink($this -> redirect_page_id);
                //For wooCoomerce 2.0
                $redirect_url = add_query_arg( array('msg'=> urlencode($this -> msg['message']), 'type'=>$this -> msg['class']), $redirect_url );

                wp_redirect( $redirect_url );
                exit;		
			} 
		}
		
		
		/**
         * Generate Zaakpay button link
         **/
        public function generate_Zaakpay_form($order_id){
            global $woocommerce;
			$txnDate=date('Y-m-d');			
			$milliseconds = (int) (1000 * (strtotime(date('Y-m-d'))));
			if ( version_compare( WOOCOMMERCE_VERSION, '2.0.0', '>=' ) ) {
                $order = &new WC_Order($order_id);
             } else {
                $order = &new woocommerce_order($order_id);
            }
            $redirect_url = ($this -> redirect_page_id=="" || $this -> redirect_page_id==0)?get_site_url() . "/":get_permalink($this -> redirect_page_id);
			// pretty url check //
			$a = strstr($redirect_url,"?");
			if($a){ $redirect_url .= "&wc-api=WC_Zaakpay";}
			else {$redirect_url .= "?wc-api=WC_Zaakpay";}			
			error_log("redirect url = this {$redirect_url}");
			//////////////
            $order_id = $order_id;
			$amt=	(int) (100 * ($order -> order_total));
			$txntype='1';
			$zpayoption='1';
			$currency="INR";
			$purpose="1";
			$productDescription='Zaakpay';
			$ip=$_SERVER['REMOTE_ADDR'];
			$post_variables = Array(
			"merchantIdentifier" => $this -> merchantIdentifier,
			"orderId" => $order_id,
			"returnUrl" => $redirect_url,
			"buyerEmail" => $order -> billing_email,
			"buyerFirstName" => $order -> billing_first_name,
			"buyerLastName" => $order -> billing_last_name,
			"buyerAddress" => $order -> billing_address_1,
			"buyerCity" => $order -> billing_city,
			"buyerState" => $order -> billing_state,
			"buyerCountry" => $order -> billing_country,
			"buyerPincode" => $order -> billing_postcode,
			"buyerPhoneNumber" => $order -> billing_phone,
			"txnType" => $txntype,
			"zpPayOption" => $zpayoption,
			"mode" => $this -> mode,
			"currency" => $currency,
			"amount" => $amt, //Amount should be in paisa
			"merchantIpAddress" => $ip,
			"purpose" => $purpose,
			"productDescription" => $productDescription,
			"txnDate" => $txnDate

			);

			$all = '';
			foreach($post_variables as $name => $value) {
			if($name != 'checksum') {
			$all .= "'";
			if ($name == 'returnUrl') {
			$all .= Checksum::sanitizedURL($value);
			} else {

			$all .= Checksum::sanitizedParam($value);
			}
			$all .= "'";
			}
			}
			if($this->log == "yes")
			{			
				error_log("AllParams : ".$all);
				error_log("Secret Key : ".$this -> secret_key);
			}
			$checksum = Checksum::calculateChecksum($this->secret_key, $all);
			
            $Zaakpay_args = array(
               'merchantIdentifier' => $this -> merchantIdentifier,
                'orderId' => $order_id,
		        'returnUrl' => $redirect_url,
				'buyerEmail' => $order -> billing_email,
				'buyerFirstName' => $order -> billing_first_name,
				'buyerLastName' => $order -> billing_last_name,
				'buyerAddress' => $order -> billing_address_1,
				'buyerCity' => $order -> billing_city,
				'buyerState' => $order -> billing_state,
				'buyerCountry' => $order -> billing_country,
				'buyerPincode' => $order -> billing_postcode,
				'buyerPhoneNumber' => $order -> billing_phone,
				'txnType' => $txntype,
				'zpPayOption' => $zpayoption,
				'mode' => $this -> mode,
				'currency' => $currency,
				'amount' => $amt,
				'merchantIpAddress' => $ip,
				'purpose' => $purpose,
				'productDescription' => $productDescription,
				'txnDate' =>  $txnDate,
                'checksum' => $checksum
				);
				foreach($Zaakpay_args as $name => $value) {
			if($name != 'checksum') {
			if ($name == 'returnUrl') {
			$value = Checksum::sanitizedURL($value);
			
			} else {
			$value = Checksum::sanitizedParam($value);
			
			}
			}
			}

			
			
            $Zaakpay_args_array = array();
            foreach($Zaakpay_args as $key => $value){
						if($key != 'checksum') {
			if ($key == 'returnUrl') {
                $Zaakpay_args_array[] = "<input type='hidden' name='$key' value='". Checksum::sanitizedURL($value) ."'/>";
				} else {
				$Zaakpay_args_array[] = "<input type='hidden' name='$key' value='". Checksum::sanitizedParam($value) ."'/>";
				}
				} else {
				$Zaakpay_args_array[] = "<input type='hidden' name='$key' value='$value'/>";
				}
            }
            return '<form action="'.$this -> liveurl.'" method="post" id="Zaakpay_payment_form">
                ' . implode('', $Zaakpay_args_array) . '
                <input type="submit" class="button-alt" id="submit_Zaakpay_payment_form" value="'.__('Pay via Zaakpay').'" /> <a class="button cancel" href="'.$order->get_cancel_order_url().'">'.__('Cancel order &amp; restore cart').'</a>
                <script type="text/javascript">
jQuery(function(){
    jQuery("body").block(
            {
                message: "<img src=\"'.$woocommerce->plugin_url().'/assets/images/ajax-loader.gif\" alt=\"Redirecting…\" style=\"float:left; margin-right: 10px;\" />'.__('Thank you for your order. We are now redirecting you to Zaakpay to make payment.').'",
                    overlayCSS:
            {
                background: "#fff",
                    opacity: 0.6
        },
        css: {
            padding:        20,
                textAlign:      "center",
                color:          "#555",
                border:         "3px solid #aaa",
                backgroundColor:"#fff",
                cursor:         "wait",
                lineHeight:"32px"
        }
        });
        jQuery("#submit_Zaakpay_payment_form").click();

        });
                    </script>
                </form>';


        }


        /*
         * End Zaakpay Essential Functions
         **/
        // get all pages
        
        function get_pages($title = false, $indent = true) {
            $wp_pages = get_pages('sort_column=menu_order');
            $page_list = array();
            if ($title) $page_list[] = $title;
            foreach ($wp_pages as $page) {
                $prefix = '';
                // show indented child pages?
                if ($indent) {
                    $has_parent = $page->post_parent;
                    while($has_parent) {
                        $prefix .=  ' - ';
                        $next_page = get_page($has_parent);
                        $has_parent = $next_page->post_parent;
                    }
                }
                // add to page list array array
                $page_list[$page->ID] = $prefix . $page->post_title;
            }
            return $page_list;
        }

    }


    /**
     * Add the Gateway to WooCommerce
     **/
    function woocommerce_add_Zaakpay_gateway($methods) {
        $methods[] = 'WC_Zaakpay';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_Zaakpay_gateway' );
}

?>