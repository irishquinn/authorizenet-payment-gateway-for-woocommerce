<?php
/*
   Plugin Name: Authorize.net Payment Gateway For WooCommerce
   Description: Extends WooCommerce to Process Payments with Authorize.net gateway.
   Version: 1.2
   Plugin URI: http://www.phptubelight.com?source=woocomautho
   Author: Ishan Verma 
   Author URI: http://www.phptubelight.com?source=woocomautho
   License: Under GPL2

*/

add_action('plugins_loaded', 'woocommerce_tech_autho_init', 0);

function woocommerce_tech_autho_init() {

   if ( !class_exists( 'WC_Payment_Gateway' ) ) 
      return;

   /**
   * Localisation
   */
   load_plugin_textdomain('wc-tech-autho', false, dirname( plugin_basename( __FILE__ ) ) . '/languages');
   
   /**
   * Authorize.net Payment Gateway class
   */
   class WC_Tech_Autho extends WC_Payment_Gateway 
   {
      protected $msg = array();
      
      public function __construct(){

         $this->id               = 'authorize';
         $this->method_title     = __('Authorize.net', 'tech');
         $this->icon             = WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/images/logo.gif';
         $this->has_fields       = false;
         $this->init_form_fields();
         $this->init_settings();
         $this->title            = $this->settings['title'];
         $this->description      = $this->settings['description'];
         $this->login            = $this->settings['login_id'];
         $this->mode             = $this->settings['working_mode'];
         $this->transaction_key  = $this->settings['transaction_key'];
         $this->success_message  = $this->settings['success_message'];
         $this->failed_message   = $this->settings['failed_message'];
         $this->liveurl          = 'https://secure.authorize.net/gateway/transact.dll';
         $this->testurl          = 'https://test.authorize.net/gateway/transact.dll';
         $this->powerpay         = 'https://verifi.powerpay.biz/cart/ausi.php';
         $this->msg['message']   = "";
         $this->msg['class']     = "";
        
         add_action('init', array(&$this, 'check_authorize_response'));
         //update for woocommerce >2.0
         add_action( 'woocommerce_api_wc_tech_autho' , array( $this, 'check_authorize_response' ) );
         add_action('valid-authorize-request', array(&$this, 'successful_request'));
         
         if ( version_compare( WOOCOMMERCE_VERSION, '2.0.0', '>=' ) ) {
             add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( &$this, 'process_admin_options' ) );
          } else {
             add_action( 'woocommerce_update_options_payment_gateways', array( &$this, 'process_admin_options' ) );
         }

         add_action('woocommerce_receipt_authorize', array(&$this, 'receipt_page'));
         add_action('woocommerce_thankyou_authorize',array(&$this, 'thankyou_page'));
      }

      function init_form_fields()
      {

         $this->form_fields = array(
            'enabled'      => array(
                  'title'        => __('Enable/Disable', 'tech'),
                  'type'         => 'checkbox',
                  'label'        => __('Enable Authorize.net Payment Module.', 'tech'),
                  'default'      => 'no'),
            'title'        => array(
                  'title'        => __('Title:', 'tech'),
                  'type'         => 'text',
                  'description'  => __('This controls the title which the user sees during checkout.', 'tech'),
                  'default'      => __('Authorize.net', 'tech')),
            'description'  => array(
                  'title'        => __('Description:', 'tech'),
                  'type'         => 'textarea',
                  'description'  => __('This controls the description which the user sees during checkout.', 'tech'),
                  'default'      => __('Pay securely by Credit or Debit Card through Authorize.net Secure Servers.', 'tech')),
            'login_id'     => array(
                  'title'        => __('Login ID', 'tech'),
                  'type'         => 'text',
                  'description'  => __('This is API Login ID')),
            'transaction_key' => array(
                  'title'        => __('Transaction Key', 'tech'),
                  'type'         => 'text',
                  'description'  =>  __('API Transaction Key', 'tech')),
            'success_message' => array(
                  'title'        => __('Transaction Success Message', 'tech'),
                  'type'         => 'textarea',
                  'description'=>  __('Message to be displayed on successful transaction.', 'tech'),
                  'default'      => __('Your payment has been procssed successfully.', 'tech')),
            'failed_message'  => array(
                  'title'        => __('Transaction Failed Message', 'tech'),
                  'type'         => 'textarea',
                  'description'  =>  __('Message to be displayed on failed transaction.', 'tech'),
                  'default'      => __('Your transaction has been declined.', 'tech')),
            'working_mode'    => array(
                  'title'        => __('API Mode'),
                  'type'         => 'select',
            'options'      => array('false'=>'Live Mode', 'true'=>'Test/Sandbox Mode', 'powerpay' => 'PowerPay Payment Gateway Emulator'),
                  'description'  => "Live/Test Mode" )
         );
      }
      
      /**
       * Admin Panel Options
       * - Options for bits like 'title' and availability on a country-by-country basis
      **/
      public function admin_options()
      {
         echo '<h3>'.__('Authorize.net Payment Gateway', 'tech').'</h3>';
         echo '<p>'.__('Authorize.net is most popular payment gateway for online payment processing').'</p>';
         echo '<table class="form-table">';
         $this->generate_settings_html();
         echo '</table>';

      }
      
      /**
      *  There are no payment fields for Authorize.net, but want to show the description if set.
      **/
      function payment_fields()
      {
         if ( $this->description ) 
            echo wpautop(wptexturize($this->description));
      }
      
      public function thankyou_page($order_id) 
      {
       
      }
      /**
      * Receipt Page
      **/
      function receipt_page($order)
      {
         echo '<p>'.__('Thank you for your order, please click the button below to pay with Authorize.net.', 'tech').'</p>';
         echo $this->generate_authorize_form($order);
      }
      
      /**
       * Process the payment and return the result
      **/
      function process_payment($order_id)
      {
         $order = new WC_Order($order_id);
         return array('result'   => 'success',
                     'redirect'  => add_query_arg('order',
                                    $order->id, 
                                    add_query_arg('key', $order->order_key, get_permalink(get_option('woocommerce_pay_page_id'))))
         );
      }
      
      /**
       * Check for valid Authorize.net server callback to validate the transaction response.
      **/
      function check_authorize_response()
      {
        
         global $woocommerce;
         
         if ( count($_POST) ){
         
            $redirect_url = '';
            $this->msg['class']     = 'error';
            $this->msg['message']   = $this->failed_message;

            if ( $_POST['x_response_code'] != '' ){
               try{
               
                  $order            = new WC_Order($_POST['x_invoice_num']);
                  $amount           = $_POST['x_amount'];
                  $hash             = $_POST['x_MD5_Hash'];
                  $transauthorised  = false;
                     
                  if ( $order->status != 'completed'){
                     
                     if ( $_POST['x_response_code'] == 1 ){
                        $transauthorised        = true;
                        $this->msg['message']   = $this->success_message;
                        $this->msg['class']     = 'success';
                        
                        if ( $order->status == 'processing' ){
                           
                        }
                        else{
                            $order->payment_complete();
                            $order->add_order_note('Autorize.net payment successful<br/>Ref Number/Transaction ID: '.$_REQUEST['x_trans_id']);
                            $order->add_order_note($this->msg['message']);
                            $woocommerce->cart->empty_cart();

                        }
                     }
                     else{
                        $this->msg['class'] = 'error';
                        $this->msg['message'] = $this->failed_message;
                        $order->add_order_note($this->msg['message']);
                        $order->update_status('failed');
                        //extra code can be added here such as sending an email to customer on transaction fail
                     }
                  }
                  if ( $transauthorised==false ){
                    $order->update_status('failed');
                    $order->add_order_note($this->msg['message']);
                  }

               }
               catch(Exception $e){
                         // $errorOccurred = true;
                         $msg = "Error";

               }

            }
            $redirect_url =  add_query_arg('order',
                                                  $order->id, 
                                                  add_query_arg('key', $order->order_key, 
                                                  get_permalink(get_option('woocommerce_thanks_page_id'))));
            $this->web_redirect( $redirect_url); exit;
         }
         else{
            
            $redirect_url =  add_query_arg('order',
                                                  $order->id, 
                                                  add_query_arg('key', $order->order_key, 
                                                  get_permalink(get_option('woocommerce_thanks_page_id'))));
            $this->web_redirect($redirect_url.'?msg=Unknown_error_occured');
            exit;
         }
      }
      
      
      public function web_redirect($url){
      
         echo "<html><head><script language=\"javascript\">
                <!--
                window.location=\"{$url}\";
                //-->
                </script>
                </head><body><noscript><meta http-equiv=\"refresh\" content=\"0;url={$url}\"></noscript></body></html>";
      
      }
      /**
      * Generate authorize.net button link
      **/
      public function generate_authorize_form($order_id)
      {
         global $woocommerce;
         
         $order      = new WC_Order($order_id);
         $sequence   = rand(1, 1000);
         $timeStamp  = time();

         if( phpversion() >= '5.1.2' ) { 
            $fingerprint = hash_hmac("md5", $this->login . "^" . $sequence . "^" . $timeStamp . "^" . $order->order_total . "^", $this->transaction_key); }
         else { 
            $fingerprint = bin2hex(mhash(MHASH_MD5,  $this->login . "^" . $sequence . "^" . $timeStamp . "^" . $order->order_total . "^", $this->transaction_key)); 
         }
         $redirect_url = (get_option('woocommerce_thanks_page_id') != '' ) ? get_permalink(get_option('woocommerce_thanks_page_id')): get_site_url().'/' ;
         $relay_url = add_query_arg( array('wc-api' => get_class( $this ) ,'order_id' => $order_id ), $redirect_url );
         
         $authorize_args = array(
            'x_login'                  => $this->login,
            'x_amount'                 => $order->order_total,
            'x_invoice_num'            => $order_id,
            'x_relay_response'         => "TRUE",
            'x_relay_url'              => $relay_url,
            'x_fp_sequence'            => $sequence,
            'x_fp_hash'                => $fingerprint,
            'x_show_form'              => 'PAYMENT_FORM',
            'x_test_request'           => $this->mode,
            'x_fp_timestamp'           => $timeStamp,
            'x_first_name'             => $order->billing_first_name ,
            'x_last_name'              => $order->billing_last_name ,
            'x_company'                => $order->billing_company ,
            'x_address'                => $order->billing_address_1 .' '. $order->billing_address_2,
            'x_country'                => $order->billing_country,
            'x_state'                  => $order->billing_state,
            'x_city'                   => $order->billing_city,
            'x_zip'                    => $order->billing_postcode,
            'x_phone'                  => $order->billing_phone,
            'x_email'                  => $order->billing_email,
            'x_ship_to_first_name'     => $order->shipping_first_name ,
            'x_ship_to_last_name'      => $order->shipping_last_name ,
            'x_ship_to_company'        => $order->shipping_company ,
            'x_ship_to_address'        => $order->shipping_address_1 .' '. $order->shipping_address_2,
            'x_ship_to_country'        => $order->shipping_country,
            'x_ship_to_state'          => $order->shipping_state,
            'x_ship_to_city'           => $order->shipping_city,
            'x_ship_to_zip'            => $order->shipping_postcode,
            );

         $authorize_args_array = array();
         
         foreach($authorize_args as $key => $value){
           $authorize_args_array[] = "<input type='hidden' name='$key' value='$value'/>";
         }
         
        if($this->mode == 'true'){
           $processURI = $this->testurl;
         }
         else if($this->mode == 'powerpay'){
           $processURI = $this->powerpay;
         }
         else{
             $processURI = $this->liveurl;
         }
         
         $html_form    = '<form action="'.$processURI.'" method="post" id="authorize_payment_form">' 
               . implode('', $authorize_args_array) 
               . '<input type="submit" class="button" id="submit_authorize_payment_form" value="'.__('Pay via Authorize.net', 'tech').'" /> <a class="button cancel" href="'.$order->get_cancel_order_url().'">'.__('Cancel order &amp; restore cart', 'tech').'</a>'
               . '<script type="text/javascript">
                  jQuery(function(){
                     jQuery("body").block({
                           message: "<img src=\"'.$woocommerce->plugin_url().'/assets/images/ajax-loader.gif\" alt=\"Redirecting…\" style=\"float:left; margin-right: 10px;\" />'.__('Thank you for your order. We are now redirecting you to Authorize.net to make payment.', 'tech').'",
                           overlayCSS:
                        {
                           background:       "#ccc",
                           opacity:          0.6,
                           "z-index": "99999999999999999999999999999999"
                        },
                     css: {
                           padding:          20,
                           textAlign:        "center",
                           color:            "#555",
                           border:           "3px solid #aaa",
                           backgroundColor:  "#fff",
                           cursor:           "wait",
                           lineHeight:       "32px",
                           "z-index": "999999999999999999999999999999999"
                     }
                     });
                  jQuery("#submit_authorize_payment_form").click();
               });
               </script>
               </form>';

         return $html_form;
      }

      
   }

   /**
    * Add this Gateway to WooCommerce
   **/
   function woocommerce_add_tech_autho_gateway($methods) 
   {
      $methods[] = 'WC_Tech_Autho';
      return $methods;
   }

   add_filter('woocommerce_payment_gateways', 'woocommerce_add_tech_autho_gateway' );
}

