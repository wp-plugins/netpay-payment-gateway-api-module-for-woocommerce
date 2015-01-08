<?php
/*
   Plugin Name: NetPay API Payment Method For WooCommerce
   Description: Extends WooCommerce to Process Payments with NetPay's API Method. 'When deactivating this module, it will remove all stored token'.
   Version: 1.0.3
   Plugin URI: http://netpay.co.uk
   Author: NetPay
   Author URI: http://www.netpay.co.uk/
   License: Under GPL2 
   Note: Tested with WP3.8.2 and WP4.1 , WooCommerce version 2.0.20 and compatible with version 2.2.10
*/

register_activation_hook(__FILE__,'netpay_install');
register_deactivation_hook(__FILE__,'netpay_uninstall');

// remove stored token when plugin deactivate
function netpay_uninstall(){
	global $wpdb;
	$query = "delete from ".$wpdb->prefix."usermeta where meta_key = 'netpayapi_token' ";
	$wpdb->query($query);
}

// create temporary memory table to store encrypted values for payment process
function netpay_install(){
global $wpdb;	
	$structure = "CREATE TABLE IF NOT EXISTS ".$wpdb->prefix."np_token (
        id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
		token VARCHAR(255) NOT NULL,
        security_code VARCHAR(255) NOT NULL,
		ddd_secure_id VARCHAR(255) NOT NULL,
		order_id INT(11) NOT NULL,
		tokenType VARCHAR(20) NOT NULL,
		tokenMode VARCHAR(10) NOT NULL,
		session_id VARCHAR(255) NOT NULL
    ) ENGINE = MEMORY CHARACTER SET utf8 COLLATE utf8_general_ci; ";	
    $wpdb->query($structure);
	
	// generate hash key and write in config file
	$fp = fopen(dirname(  __FILE__ ).'/config.php','w');
	$enc_key = '<?php $enckey="'.md5(uniqid(rand(), TRUE)).'";';
	$enc_iv  = '$enciv="'.md5(uniqid(rand(), TRUE)).'";?>';
	fwrite($fp,trim($enc_key));
	fwrite($fp, PHP_EOL);
	fwrite($fp,trim($enc_iv));
	fclose($fp);
	
}
 
add_action('plugins_loaded', 'woocommerce_tech_netpayapi_init', 0);

function woocommerce_tech_netpayapi_init() {

   	if ( !class_exists( 'WC_Payment_Gateway' ) ) 
      	return;

   	/**
   	* Localisation
   	*/
   	load_plugin_textdomain('wc-tech-netpayapi', false, dirname( plugin_basename( __FILE__ ) ) . '/languages');
   	session_start();
   	/**
   	* NetPay Payment Gateway class
   	*/
   	class WC_Tech_Netpayapi extends WC_Payment_Gateway 
   	{
      	protected $msg = array();
      	
      	public function __construct(){
			
			$this->method = 'AES-128-CBC'; // Encryption method, IT SHOULD NOT BE CHANGED
		
			$this->id               = 'netpayapi';
			$this->method_title     = __('NetPay API', 'tech');
			$this->icon             = WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/images/logo.gif';
			$this->has_fields       = false;
			
			$this->init_form_fields();
			$this->init_settings();
			
			include_once(dirname(  __FILE__ ).'/config.php');
			
			if($this->settings['netpayapi_working_mode'] == 'test'){
				$this->title        = $this->settings['netpayapi_title']." - <b>Test Mode</b>";
			} else {
				$this->title        = $this->settings['netpayapi_title'];
			}
			
			$this->description      		= 	$this->settings['netpayapi_description'];
			$this->netpayapi_merchant_id	= 	$this->settings['netpayapi_merchant_id'];
			$this->netpayapi_username  		= 	$this->settings['netpayapi_username'];
			$this->netpayapi_password  		= 	$this->settings['netpayapi_password'];
			$this->mode             		= 	$this->settings['netpayapi_working_mode'];
			$this->enc_key 					= 	$enckey;
			$this->enc_iv 					= 	$enciv;
			$this->netpayapi_cardtypes 		= 	$this->settings['netpayapi_cardtypes'];
			$this->liveurl          		= 	'https://integration.revolution.netpay.co.uk/v1/';
			$this->testurl          		= 	'https://integrationtest.revolution.netpay.co.uk/v1/';
			$this->netpayapi_uses_token     = 	$this->settings['netpayapi_uses_token'];
			$this->netpayapi_tds_auth       = 	$this->settings['netpayapi_tds_auth'];
			$this->netpayapi_enrolment_fail = 	$this->settings['netpayapi_enrolment_fail'];
			$this->accept             		= 	'json';
			$this->content_type          	= 	'json';
    		$this->msg['message']   		= 	"";
			$this->msg['class']     		= 	"";
			
			add_action('init', array(&$this, 'process_card_enrolled'));
			add_action( 'woocommerce_api_wc_tech_netpayapi' , array( $this, 'process_card_enrolled' ) );
			
         	if ( version_compare( WOOCOMMERCE_VERSION, '2.0.0', '>=' ) ) {
            	add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( &$this, 'process_admin_options' ) );
          	} else {
             	add_action( 'woocommerce_update_options_payment_gateways', array( &$this, 'process_admin_options' ) );
         	}

			add_action('woocommerce_receipt_netpayapi', array(&$this, 'receipt_page'));
			add_action('woocommerce_thankyou_netpayapi',array(&$this, 'thankyou_page'));
		}

		// function to show fields in admin configuration form
      	function init_form_fields(){
         	$this->form_fields = array(
            	'enabled'      => array(
                	'title'        => __('Enable/Disable', 'tech'),
                  	'type'         => 'checkbox',
                  	'label'        => __('Enable NetPay API Payment Module.', 'tech'),
                  	'default'      => 'no'),
            	'netpayapi_title'        => array(
                  	'title'        => __('Title:', 'tech'),
                  	'type'         => 'text',
                  	'description'  => __('This controls the title which the user sees during checkout.', 'tech'),
                  	'default'      => __('NetPay', 'tech')),
            	'netpayapi_description'  => array(
                  	'title'        => __('Description:', 'tech'),
                  	'type'         => 'textarea',
                  	'description'  => __('This controls the description which the user sees during checkout.', 'tech'),
                  	'default'      => __('Pay securely by Credit or Debit Card through NetPay Secure Servers.', 'tech')),
            	'netpayapi_merchant_id'     => array(
                  	'title'        => __('Merchant ID', 'tech'),
                  	'type'         => 'text',
                  	'description'  => __('This is your merchant account ID')),
            	'netpayapi_username' => array(
                  	'title'        => __('Username', 'tech'),
                  	'type'         => 'text',
                  	'description'  =>  __('This is your integration NetPay API Username to authenticate your request', 'tech')),
				'netpayapi_password' => array(
                  	'title'        => __('Password', 'tech'),
                  	'type'         => 'text',
                  	'description'  =>  __('This is your integration NetPay API Password to authenticate your request', 'tech')),
            	'netpayapi_working_mode'    => array(
                  	'title'        => __('Payment Mode'),
                  	'type'         => 'select',
            	  	'options'      => array('live'=>'Live Mode', 'test'=>'Test/Sandbox Mode'),
                  	'description'  => "Live/Test Mode" ),
				'netpayapi_uses_token'    => array(
                  	'title'        => __('Enable Tokenization'),
                  	'type'         => 'select',
            	  	'options'      => array('yes'=>'Yes', 'test'=>'No'),
                  	'description'  => "Request for the gateway to store card information against a token." ),
				'netpayapi_tds_auth'    => array(
                  	'title'        => __('Enable 3DS Authentication'),
                  	'type'         => 'select',
            	  	'options'      => array('yes'=>'Yes', 'test'=>'No'),
                  	'description'  => "Request to check a cardholder's enrollment in the 3DSecure scheme." ),
				'netpayapi_enrolment_fail'    => array(
                  	'title'        => __('Continue with Enrolment Fail'),
                  	'type'         => 'select',
            	  	'options'      => array('yes'=>'Yes', 'no'=>'No'),
                  	'description'  => "Anyway continue with payment if user's card is not 3DS Enrolled." ),
				'netpayapi_cardtypes'   => array(
	        		'title'       => __( 'Accepted Cards', 'tech' ),
	        		'type'        => 'multiselect',
	        		'description' => __( 'Select which card types to accept.', 'tech' ),
	        		'default'     => '',
	        		'options'     => array(
										'VISA'		=> 	'Visa',
										'VISAUK'	=> 	'Visa Debit UK',
										'ELEC'		=> 	'Visa Electron',
										'MCRD'		=> 	'MasterCard',
										'MCDB'		=> 	'MasterCard Debit',
										'MSTO'		=> 	'Maestro',
										'AMEX'  	=> 	'American Express',
										'DINE'		=> 	'Diners'
									),
	        	)
         	);
		}
      
      	/**
      	 * Admin Panel Options
      	 * - Options for bits like 'title' and availability on a country-by-country basis
      	**/
		public function admin_options(){
			echo '<h3>'.__('NetPay API Payment Method Configuration', 'tech').'</h3>';
			echo '<p>'.__('NetPay is most popular payment gateway for online payment processing').'</p>';
			echo '<table class="form-table">';
			$this->generate_settings_html();
			echo '<tr><td>(Module Version 1.0.3)</td></tr></table>';
		}
      
		/* Returns url */
		public function gateway_url() {
			if($this->mode == 'live')
				return $this->liveurl;
			else
				return $this->testurl;
		}
	
		/* Returns Operation Mode */
		public function operation_mode() {
			if($this->mode == 'live')
				return '1';
			else
				return '2';
		}
	
		/*	MCRYPT DECRYPTION  MODE CBC */
		public function mcrypt_decrypt_cbc($input, $key, $iv) {
			$decrypted= mcrypt_decrypt(MCRYPT_RIJNDAEL_128, pack('H*', $key), pack('H*', $input), MCRYPT_MODE_CBC, pack('H*', $iv));
			
			return $this->remove_pkcs5_padding($decrypted);
		}
		
		/* REMOVE PKCS5 PADDING */
		public function remove_pkcs5_padding($decrypted) {
			$dec_s = strlen($decrypted); 
			$padding = ord($decrypted[$dec_s-1]); 
			$decrypted = substr($decrypted, 0, -$padding);
	 		
			return $decrypted;
		}
		
		/* ADD PKCS5 PADDING */
		public function add_pkcs5_padding($text, $blocksize) { 
			$pad = $blocksize - (strlen($text) % $blocksize); 
			return $text . str_repeat(chr($pad), $pad); 
		}
		
		/*	MCRYPT ENCRYPTION MODE CBC */
		function mcrypt_encrypt_cbc($input, $key, $iv) {
			$size = mcrypt_get_block_size(MCRYPT_RIJNDAEL_128, MCRYPT_MODE_CBC); 
			$input = $this->add_pkcs5_padding($input, $size); 
			$td = mcrypt_module_open(MCRYPT_RIJNDAEL_128, '', MCRYPT_MODE_CBC, ''); 
	
			mcrypt_generic_init($td, pack('H*',$key), pack('H*', $iv)); 
			$data = mcrypt_generic($td, $input); 
			mcrypt_generic_deinit($td); 
			mcrypt_module_close($td); 
			$data = bin2hex($data);
	
			return $data; 
		}
		
		/* Request to retrieve the card details saved against the specified token */
		function retrieveTokenCardDetails($token){
			// include api class
			include_once("netpay_api.php");
			$api_method	= 	'gateway/token';
			$token_params  = array();
			$token_params['merchant']['merchant_id'] 	= $this->netpayapi_merchant_id;
			$token_params['merchant']['operation_type'] = 'RETRIEVE_TOKEN';
			$token_params['merchant']['operation_mode']	= $this->operation_mode();
			
			$token_params['transaction']['source']	 	= "INTERNET";
			$token_params['payment_source']['type']		= "TOKEN";
			$token_params['payment_source']['token']	= $this->mcrypt_decrypt_cbc($token,$this->enc_key,$this->enc_iv);
			
			$rest   = new Connection($this->gateway_url(), $this->get_headers_array());
			$token_result = $rest->put($api_method, $token_params);

			return $token_result;
			
		}
		
      	/**
      	*  There are no payment fields for NetPay, but want to show the description if set.
      	**/
      	function payment_fields(){
        	if ( $this->description ) {echo wpautop(wptexturize($this->description))."<br>";}
			
			$currentUser =  wp_get_current_user();
			$userId = $currentUser->ID;
			
			// get user's stored token
			$userToken = get_user_meta( $userId, 'netpayapi_token', true);
			
			// card form goes here 
			?>
			
			<fieldset>
				<fieldset>
					<!-- Show input boxes for new data -->
					<div id="netpay-new-info">
						<link href="<?php echo plugins_url( 'css/netpay.css' , __FILE__ );?>" rel="stylesheet" type="text/css" />
						<?php
						if($this->netpayapi_uses_token == 'yes' && $userId != ''){
								
							// Show user tokens
							if($userToken != ''){
								$userTokenArray = explode("-@#@-",$userToken);
								for($i=0; $i<count($userTokenArray); $i++){
									$tokenInfo = $this->retrieveTokenCardDetails($userTokenArray[$i]);
						?>
									<div class="section_div">
										<div class="optdiv">
											<input type="radio" name="optToken" value="t<?php echo $i;?>">
										</div>
										<div class="right_info">
											<div class="field_row">
												<label class="cc_label">
													<?php echo __( 'Card Holder', 'woocommerce' ) ?>: 
												</label>
												<span class="cc_field">
													<?php echo $tokenInfo->payment_source->card->holder->fullname;?>
												</span>
											</div>
											<div class="field_row">
												<label class="cc_label">
													<?php echo __( 'Card Number', 'woocommerce' ) ?>: 
												</label>
												<span class="cc_field">
													<?php echo $tokenInfo->payment_source->card->number;?>
												</span>
											</div>
											<div class="field_row">
												<label class="cc_label">
													<?php echo __( 'Expiry Date', 'woocommerce' ) ?>
												</label>
												<span class="cc_field">
													<?php echo $tokenInfo->payment_source->card->expiry_month."/".$tokenInfo->payment_source->card->expiry_year;?>
												</span>
											</div>
											<div class="field_row">
												<label class="cc_label">
													<?php echo __( 'Card security code*:', 'woocommerce' ) ?>
												</label>
												<span class="cc_field">
													<input type="text" class="input-text" id="t<?php echo $i;?>_cvv" name="t<?php echo $i;?>_cvv" maxlength="4" style="width:45px" />
													<?php _e( '3 or 4 digits on the signature strip.', 'woocommerce' ) ?></span>
												</span>
											</div>
											
											 
										</div>
									</div>
						<?php
								}
							}
						} // end to check if token enabled by system administrator
						?>
						
						<div class="section_div">
							<?php if($userToken != ''){ ?>
								<div class="optdiv">
									<input type="radio" name="optToken" value="new_card" checked="checked">
								</div>
							<?php } ?>
							
							<div class="right_info">
								<?php if($userToken != ''){ ?>
									<?php echo __( 'User Another Card', 'woocommerce' ) ?><br><br>
								<?php } ?>
								
								<div class="field_row">
									<label class="cc_label">
										<?php echo __( 'Title', 'woocommerce' ) ?><span class="required">*</span>: 
									</label>
									<span class="cc_field">
										<select name="cc_title">
											<option value="">Select</option>
											<option value="Mr">Mr</option>
											<option value="Mr">Mrs</option>
											<option value="Miss">Miss</option>
										</select>
									</span>
								</div>
								
								<div class="field_row">
									<label class="cc_label">
										<?php echo __( 'Card Holder First Name', 'woocommerce' ) ?><span class="required">*</span>: 
									</label>
									<span class="cc_field">
										<input type="text" class="input-text" id="cc_fname" name="cc_fname" maxlength="33">
									</span>
								</div>
								
								<div class="field_row">
									<label class="cc_label">
										<?php echo __( 'Card Holder Middle Name', 'woocommerce' ) ?>: 
									</label>
									<span class="cc_field">
										<input type="text" class="input-text" id="cc_mname" name="cc_mname" maxlength="33">
									</span>
								</div>
								
								<div class="field_row">
									<label class="cc_label">
										<?php echo __( 'Card Holder Last Name', 'woocommerce' ) ?><span class="required">*</span>: 
									</label>
									<span class="cc_field">
										<input type="text" class="input-text" id="cc_lname" name="cc_lname" maxlength="33">
									</span>
								</div>
								
								<div class="field_row">
									<label class="cc_label">
										<?php echo __( 'Card Number', 'woocommerce' ) ?><span class="required">*</span>: 
									</label>
									<span class="cc_field">
										<input type="text" class="input-text" id="ccnum" name="ccnum" maxlength="20" />
									</span>
								</div>
								
								<div class="field_row">
									<label class="cc_label">
										<?php echo __( 'Card Type', 'woocommerce' ) ?><span class="required">*</span>: 
									</label>
									<span class="cc_field">
										<select name="cardtype" id="cardtype" class="woocommerce-select">
											<?php  foreach( $this->netpayapi_cardtypes as $netpayapi_type ) { ?>
												<option value="<?php echo $netpayapi_type ?>"><?php echo $this->cardFullName($netpayapi_type);?></option>
											<?php } ?>
										</select>
									</span>
								</div>
								
								<div class="field_row">
									<label class="cc_label">
										<?php echo __( 'Expiry Date', 'woocommerce' ) ?><span class="required">*</span>: 
									</label>
									<span class="cc_field">
										<select name="expmonth" id="expmonth" class="woocommerce-select woocommerce-cc-month">
											<option value=""><?php _e( 'Month', 'woocommerce' ) ?></option><?php
											$months = array();
											for ( $i = 1; $i <= 12; $i ++ ) {
											  $timestamp = mktime( 0, 0, 0, $i, 1 );
											  $months[ date( 'n', $timestamp ) ] = date( 'F', $timestamp );
											}
											foreach ( $months as $num => $name ) {
											  printf( '<option value="%u">%s</option>', $num, $name );
											} ?>
										</select>
										<select name="expyear" id="expyear" class="woocommerce-select woocommerce-cc-year">
											<option value=""><?php _e( 'Year', 'woocommerce' ) ?></option><?php
											$years = array();
											for ( $i = date( 'y' ); $i <= date( 'y' ) + 15; $i ++ ) {
											  printf( '<option value="20%u">20%u</option>', $i, $i );
											} ?>
										</select>
									</span>
								</div>
								
								<div class="field_row">
									<label class="cc_label">
										<?php echo __( 'Card security code', 'woocommerce' ) ?><span class="required">*</span>: 
									</label>
									<span class="cc_field">
										<input type="text" class="input-text" id="cvv" name="cvv" maxlength="4" style="width:45px" />
									</span>
								</div>
								
								<?php
									if($this->netpayapi_uses_token == 'yes' && $userId != ''){
								?>
								<div class="field_row">
									<input type="checkbox" class="input-checkbox" id="create_token" name="create_token" value="yes" />
									Would you like to store your card information for next purchase?
								</div>
								<?php } ?>
								
							</div>
						</div>
						
					</div>
				</fieldset>
			</fieldset> 
			<?php
      	}
         
      	function thankyou_page($order_id) {
      	     
      	}
        
	  	/**
      	* Receipt Page 
      	**/ 
      	function receipt_page($order){ 
      	   	echo '<p>'.__('Thank you for your order, please click the button below to pay with NetPay.', 'tech').'</p>';
      	}
      
	  	/**
       	* Process the payment and return the result
      	**/
		function process_payment($order_id){
			global $woocommerce;
			
			$order = new WC_Order( $order_id );
			
			// include api class
			include("netpay_api.php");
			
			$api_method = 'gateway/transaction';
			
			// if 3DS is not enabled, use additional parameters.
			if(strtoupper($this->netpayapi_tds_auth) == 'YES'){
			
				$params = $this->prepare_3ds_post_array($order_id);

				$rest = new Connection($this->gateway_url(), $this->get_headers_array());
			
				// Send request and get response from server
				$response = $rest->put($api_method, $params);
				
				// if card enroled is valid and result is success go for PROCESS_ACS_RESULT
				if(strtoupper($response->result) == 'SUCCESS'){
					$cardStatus = $response->ddd_secure->summary_status;
					switch($cardStatus){
						case 'CARD_ENROLLED':
								global $wpdb;
								$paymentOption = $this->get_post('optToken');
								
								if(!isset($paymentOption) || $paymentOption == 'new_card' ){
									if(isset($_POST['create_token'])){
										$token = $this->_createCardToken('PERMANENT');
										$tokenType = "permanent";
									} else {
										$token = $this->_createCardToken('TEMPORARY');
										$tokenType = "temporary";
									}
									$cvv = $this->get_post('cvv');
									$tokenMode = 'card';
								} else {
									$postField = $this->mcrypt_encrypt_cbc($response->payment_source->token, $this->enc_key, $this->enc_iv);
									$token 	= $response->payment_source->token;
									
									$tokenType = "permanent";
									$cvv = $_POST[$paymentOption."_cvv"];
									$tokenMode = 'token';
								}
								
								if(strtoupper($token) != 'ERROR'){ // temporary token created
									// Storing token and reference details in temporary table.
									$token 			= $this->mcrypt_encrypt_cbc($token, $this->enc_key, $this->enc_iv);
									$security_code 	= $this->mcrypt_encrypt_cbc($cvv, $this->enc_key, $this->enc_iv);
									$ddd_secure_id 	= $this->mcrypt_encrypt_cbc($response->ddd_secure_id, $this->enc_key, $this->enc_iv);
									
									$insertQuery = "insert into ".$wpdb->prefix."np_token set token 		= '".$token."',
																							  security_code = '".$security_code."',
																							  ddd_secure_id = '".$ddd_secure_id."',
																							  order_id 		= '".$order_id."',
																							  tokenType		= '".$tokenType."',
																							  tokenMode		= '".$tokenMode."',
																							  session_id	= '".session_id()."' ";
									$wpdb->query($insertQuery);
									$form = "<div id='npAuthFrm'>";
									$form .= base64_decode($response->ddd_secure_redirect->simple->html_body_content);
									$form .= "</div>";
									$form .= '<script type="text/javascript">document.getElementById("npAuthFrm").getElementsByTagName(\'form\')[0].submit();</script>';
									echo $form;
									exit; 
									
								} else {  // error when creating temporary token
									// No response or unexpected response
									$order->add_order_note( __( "NetPay API payment failed. Card Enrolment failed", 'woocommerce' ) );
									$woocommerce->add_error(__('Sorry, the transaction was declined. Try again later or contact the site administrator.','woocommerce'));
								}
									
								break;
								
						case 'CARD_NOT_ENROLLED':
							 	$this->process_card_not_enrolled($order_id);
							 	break;
								
						case 'CARD_DOES_NOT_SUPPORT_3DS':
							 	$this->process_final_card_payment($order_id);
							 	break;
						
					}
					
				} else if(strtoupper($response->result) == 'ERROR'){
					$order->add_order_note( __( 'NetPay API payment failed. Error Code: '.$response->error->code.', Error Text: '.$response->error->explanation, 'woocommerce' ) );
					$woocommerce->add_error( __( $response->error->explanation, 'woocommerce' ) );
				} else {
					// No response or unexpected response
					$order->add_order_note( __( "NetPay API payment failed. Couldn't connect to gateway server.", 'woocommerce' ) );
					$woocommerce->add_error(__('No response from payment gateway server. Try again later or contact the site administrator.','woocommerce'));
				}
				
			} else  {// otherwise use normal API Card payment / Token payment
				$params = $this->prepare_post_array($order_id);
				
				$rest = new Connection($this->gateway_url(), $this->get_headers_array());
				
				// Send request and get response from server
				$response = $rest->put($api_method, $params);
				
				// Check response
				if ( strtoupper($response->result) == 'SUCCESS' ) {
					// check if create token required
					if(isset($_POST['create_token'])){
						// create token for user
						$token = $this->_createCardToken('PERMANENT');
						
						if(strtoupper($token) != "ERROR"){
							// update user token
							$this->update_user_token($token);
						}
						
					}
	
					// updating extra information in databaes corresponding to placed order.
					update_post_meta($order_id, 'netpay_order_id', $response->order->order_id);
					update_post_meta($order_id, 'netpay_transaction_id', $response->transaction->transaction_id);
					update_post_meta($order_id, 'netpay_payment_status', $response->result);
	
					// Success
					$orderNote = "NetPay API payment completed.<br>
									NetPay Transaction ID: ".$response->transaction->transaction_id."<br>
									NetPay Order Id: ".$response->order->order_id;
					$order->add_order_note(__($orderNote, 'woocommerce' ) );
					
					$order->payment_complete();
					$woocommerce->cart->empty_cart();
					// Return thank you redirect
					$order = new WC_Order($order_id);
                    if (version_compare(WOOCOMMERCE_VERSION, '2.1', '<')) {
						$redirect_url =  add_query_arg(	'order',
														$order->id, 
														add_query_arg('key', $order->order_key, 
														get_permalink(get_option('woocommerce_thanks_page_id'))));

		            } else {
						$redirect_url =  add_query_arg('key', $order->order_key, $this->get_return_url( $order ) );
				    }

					$this->web_redirect( $redirect_url);
					exit;
	
				} else if ( strtoupper($response->result) == 'ERROR' ) {
					// Other transaction error
					$order->add_order_note( __( 'NetPay API payment failed. Error Code: '.$response->error->code.', Error Text: '.$response->error->explanation, 'woocommerce' ) );
					$woocommerce->add_error( __( 'Sorry, the transaction was declined.<br>'.$response->error->explanation.'.', 'woocommerce' ) );
	
				} else if ( strtoupper($response->result) == 'FAILURE' ) {
					// Decline
					$errorDescription = $this->getErrorDescription($response->response->gateway_code);
					$order->add_order_note( __( 'NetPay API payment Declined. Error:'.$errorDescription, 'woocommerce' ) );
					$woocommerce->add_error( __( 'Sorry, the transaction was declined. <br>Error:'.$errorDescription.'. ', 'woocommerce' ) . $response->error->explanation );
					
				} else {
					// No response or unexpected response
					$order->add_order_note( __( "NetPay API payment failed. Couldn't connect to gateway server.", 'woocommerce' ) );
					$woocommerce->add_error( __( 'No response from payment gateway server. Try again later or contact the site administrator.', 'woocommerce'));
				}
			}
			
		}
		
		// function to update token in user's account
		function update_user_token($token){
			// user details
			$currentUser =  wp_get_current_user();
			$userId = $currentUser->ID;
		
			// success save token for respective user
			$encToken = $this->mcrypt_encrypt_cbc($token, $this->enc_key, $this->enc_iv);
			
			// Get existing token for user
			$storedToken = get_user_meta( $userId, 'netpayapi_token', true);
			
			// insert token to user's account.
			if($storedToken == ''){
				update_user_meta( $userId, 'netpayapi_token', $encToken );
			} else {
				$tokenString = $storedToken."-@#@-".$encToken;
				update_user_meta( $userId, 'netpayapi_token', $tokenString );
			}
		}


		// function to process as card is enrolled
		function process_card_enrolled(){
			// include api class
			global $wpdb,$woocommerce;
			include("netpay_api.php");
			
			// fetch temporary tonken and cvv from table.
			$fetchTempToken = "select * from ".$wpdb->prefix."np_token where session_id = '".session_id()."' ";
			
			$tempTokenDetail = $wpdb->get_row($fetchTempToken,ARRAY_A);
			
			
			$storedToken 	= 	$this->mcrypt_decrypt_cbc($tempTokenDetail['token'], $this->enc_key, $this->enc_iv);
			$storedCVV 		= 	$this->mcrypt_decrypt_cbc($tempTokenDetail['security_code'], $this->enc_key, $this->enc_iv);
			$storedSecureId = 	$this->mcrypt_decrypt_cbc($tempTokenDetail['ddd_secure_id'], $this->enc_key, $this->enc_iv);
			$order_id 		= 	$tempTokenDetail['order_id'];
			$tokenType 		= 	$tempTokenDetail['tokenType'];
			$tokenMode 		= 	$tempTokenDetail['tokenMode'];
			
			$order = new WC_Order( $order_id );
	
			$acs_params['merchant']['merchant_id'] 	 	= 	$this->netpayapi_merchant_id;
			$acs_params['merchant']['operation_type'] 	= 	"PROCESS_ACS_RESULT";
			$acs_params['merchant']['operation_mode'] 	= 	$this->operation_mode();
			$acs_params['transaction']['source'] 		= 	"INTERNET";
			$acs_params['ddd_secure']['pares'] 			= 	$_REQUEST['PaRes'];
			$acs_params['ddd_secure_id'] 				= 	$storedSecureId;

			
			
			$api_method = 'gateway/transaction';
			
			$rest = new Connection($this->gateway_url(), $this->get_headers_array());

			// Send PROCESS ACS RESULT request and get response from server
			$response = $rest->put($api_method, $acs_params);
			
			// if Process ACS result is success
			if(strtoupper($response->result) == 'SUCCESS'){
				if(strtoupper($response->ddd_secure->summary_status) == 'AUTHENTICATION_SUCCESSFUL'){
					// authentication is completed. Now proceed for final payment using temp token.
					$ddd_secure_id = $this->mcrypt_encrypt_cbc($response->ddd_secure_id, $this->enc_key, $this->enc_iv);
					
					$params = $this->prepare_post_array($order_id);
					
					unset($params['payment_source']);
					$params['ddd_secure_id'] = $storedSecureId;
					$params['payment_source']['type']  	= 'TOKEN';
					$params['payment_source']['token'] 	= $storedToken;
					$params['payment_source']['card']['security_code']= $storedCVV;

					$paymentResponse = $this->process_token_payment($order_id, $params);
					
					if(strtoupper($paymentResponse->result) == "SUCCESS"){
						
						$orderNote = "NetPay API payment completed.<br>
									NetPay Transaction ID: ".$paymentResponse->transaction->transaction_id."<br>
									NetPay Order Id: ".$paymentResponse->order->order_id;
						
						// updating extra information in databaes corresponding to placed order.
						update_post_meta($order_id, 'netpay_order_id', $paymentResponse->order->order_id);
						update_post_meta($order_id, 'netpay_transaction_id', $paymentResponse->transaction->transaction_id);
						update_post_meta($order_id, 'netpay_payment_status', $paymentResponse->result);
						
						
						$order->add_order_note( __( $orderNote , 'woocommerce' ) );
						$order->payment_complete();
						$woocommerce->cart->empty_cart();
						
						// if create token requested by user, update token for user
						if($tokenMode == 'card' && $tokenType == 'permanent'){
							// update user token
							$this->update_user_token($storedToken);
						}
						
						// remvoe data from temp table
						$this->delete_from_memory_table();
						
						$order = new WC_Order($order_id);
						if (version_compare(WOOCOMMERCE_VERSION, '2.1', '<')) {
							$redirect_url =  add_query_arg(	'order',
											$order->id, 
											add_query_arg('key', $order->order_key, 
											get_permalink(get_option('woocommerce_thanks_page_id'))));
						} else {
							$redirect_url =  add_query_arg('key', $order->order_key, $this->get_return_url( $order ) );
						}
						$this->web_redirect( $redirect_url);
						exit;
					} else {
						// remvoe data from temp table
						$this->delete_from_memory_table();
						
						// Request to delete token from server as token created was pernament and payment failed.
						if($tokenMode == 'card'){
							$this->request_delete_token($storedToken);
						}
						
						$msg = "Sorry, the transaction was declined. Try again later or contact the site administrator.<br>
								<a href='".get_bloginfo('url')."/checkout/'>Click to continue</a>.";
						$this->show_error($msg);
						exit;
					}
					
					
				} else {
					//echo $response->response->gateway_code;exit;
					$errorDescription = $this->getErrorDescription($response->response->gateway_code);
					
					// remvoe data from temp table
					$this->delete_from_memory_table();
				
					// Request to delete token from server as token created was pernament and payment failed.
					if($tokenMode == 'card'){
						$this->request_delete_token($storedToken);
					}
						
					$msg = "Sorry, the transaction was declined. <br> ".$errorDescription."<br>
									<a href='".get_bloginfo('url')."/checkout/'>Click to continue</a>.";
					$this->show_error($msg);
					exit;
				}
			} else { 
				// remove data from temp table
				$this->delete_from_memory_table();
				
				// Request to delete token from server as token created was pernament and payment failed.
				if($tokenMode == 'card'){
					$this->request_delete_token($storedToken);
				}
				if( strtoupper($response->result) == 'ERROR') {
				    $msg = "Following error occured:<br>".$response->error->explanation."<br>
                                        <a href='".get_bloginfo('url')."/checkout/'>Click to continue</a>.";
				} else {
				    $msg = "Following error occured:<br>".strtoupper($response->result)." CURL:".$response->error_code." ".$response->error_string. "<br>
					    				<a href='".get_bloginfo('url')."/checkout/'>Click to continue</a>.";
				}
				$this->show_error($msg);
				exit;
				
			}
			
		}
		
		// functoin to delete record from temporary table when 3ds payment complete
		function delete_from_memory_table(){
			global $wpdb;
			$wpdb->query("delete from ".$wpdb->prefix."np_token where session_id = '".session_id()."' ");
		}
				
		// function to send headers array
		function get_headers_array(){
			$header = array(
				'username' => $this->netpayapi_username,
				'password' => $this->netpayapi_password,
				'accept' => $this->accept,
				'content_type' => $this->content_type
			);
			return $header;
		}
		
		// function to delete token from NetPay
		function request_delete_token($token){
			// include api class
			$rest = new Connection($this->gateway_url(), $this->get_headers_array());
			
			$params = array();
			
			/* merchant parameters */
			$params['merchant']['merchant_id'] 	 	= 	$this->netpayapi_merchant_id;
			$params['merchant']['operation_type'] 	= 	"DELETE_TOKEN";
			$params['merchant']['operation_mode'] 	= 	$this->operation_mode();
			
			/* transaction parameters*/
			$params['transaction']['source'] 		= 	"INTERNET";
			
			/* payment source parameters*/
			$params['payment_source']['type'] 		= 	"TOKEN";
			$params['payment_source']['token'] 		= 	$token;
			
			$api_method = 'gateway/token';
			$response = $rest->put($api_method, $params);
			
		}
		
		// function to process payment using Token
		function process_token_payment($order_id, $params){
			global $wpdb, $woocommerce;
			
			$api_method = 'gateway/transaction';
			$order = new WC_Order( $order_id );
			
			$rest = new Connection($this->gateway_url(), $this->get_headers_array());
			
			// Send request and get response from server
			$response = $rest->put($api_method, $params);
			
			return $response;
			
		}
		
		// function to process final card payment
		function process_final_card_payment($order_id){
			global $woocommerce;
			$order = new WC_Order( $order_id );
			$params = $this->prepare_post_array($order_id);
			
			$rest = new Connection($this->gateway_url(), $this->get_headers_array());
			
			$api_method = 'gateway/transaction';
			// Send request and get response from server
			$response = $rest->put($api_method, $params);
			
			// Check response
			if ( strtoupper($response->result) == 'SUCCESS' ) {
				// check if create token required
				if(isset($_POST['create_token'])){
					$token = $this->_createCardToken('PERMANENT');
					$this->update_user_token($token);
				}

				// Success
				$orderNote = "NetPay API payment completed.<br>
							  NetPay Transaction ID: ".$response->transaction->transaction_id."<br>
							  NetPay Order Id: ".$response->order->order_id;
				
				// updating extra information in databaes corresponding to placed order.
				update_post_meta($order_id, 'netpay_order_id', $response->order->order_id);
				update_post_meta($order_id, 'netpay_transaction_id', $response->transaction->transaction_id);
				update_post_meta($order_id, 'netpay_payment_status', $response->result);
										
				$order->add_order_note( __( $orderNote , 'woocommerce' ) );
				$order->payment_complete();
				$woocommerce->cart->empty_cart();
				// Return thank you redirect
				if (version_compare(WOOCOMMERCE_VERSION, '2.1', '<')) {
					$redirect_url =  add_query_arg(	'order',
									$order->id, 
									add_query_arg('key', $order->order_key, 
									get_permalink(get_option('woocommerce_thanks_page_id'))));
				} else {
					$redirect_url =  add_query_arg('key', $order->order_key, $this->get_return_url( $order ) );
				}
				$this->web_redirect( $redirect_url);
				exit;

			} else if ( strtoupper($response->result) == 'ERROR' ) {
				// Other transaction error
				$order->add_order_note( __( 'NetPay API payment failed. Error Code: '.$response->error->code.', Error Text: '.$response->error->explanation, 'woocommerce' ) );
				$woocommerce->add_error( __( 'Sorry, the transaction was declined. '.$response->error->explanation, 'woocommerce' ) );

			} else if ( strtoupper($response->result) == 'FAILURE' ) {
				// Decline
				// updating extra information in databaes corresponding to placed order.
				update_post_meta($order_id, 'netpay_order_id', $response->order->order_id);
				update_post_meta($order_id, 'netpay_transaction_id', $response->transaction->transaction_id);
				update_post_meta($order_id, 'netpay_payment_status', $response->result);
				
				$errorDescr = $this->getErrorDescription($response->response->gateway_code);
				$order->add_order_note(__( 'NetPay API payment failed., Error Text: '.$response->response->gateway_code." - ".$errorDescr,'woocommerce' ) );
				$woocommerce->add_error(__( 'Sorry, the transaction was declined.<br>'.$errorDescr.'.', 'woocommerce' ) );
				
				
			} else {
				// No response or unexpected response
				$order->add_order_note( __( "NetPay API payment failed. Couldn't connect to gateway server.", 'woocommerce' ) );
				$woocommerce->add_error( __( 'No response from payment gateway server. Try again later or contact the site administrator.', 'woocommerce'));
			}
		}
		
		// show error
		public function show_error($msg){
			get_header();
			echo '<div id="main-content" class="main-content">
					<div id="primary" class="content-area">
						<div id="content" class="site-content" role="main">
							<ul class="woocommerce-error">
								<li>'.$msg.'</li>
							</ul>
						</div>
					</div>';
			get_sidebar( 'content' );
				echo '</div>';
			get_sidebar();
			get_footer();
			exit;
		}
		
		// function to process card not enrolled case
		public function process_card_not_enrolled($order_id){
			global $woocommerce;
			$order = new WC_Order( $order_id );
			
			// check if admin allows for payment if card is not 3DS enrolled.
			// show error if not allowed
			if(strtoupper($this->netpayapi_enrolment_fail) == 'NO'){
				$order->add_order_note( __( 'NetPay API payment failed. Error Text: 3DS Authentication failed.','woocommerce'));
				$msg = "Sorry, the transaction was declined. Try again later or contact the site administrator.";
				$this->show_error($msg);
				exit;
			} else {
				// proceed for payment if allowed
				$this->process_final_card_payment($order_id);
			}
			
		}

		// function used to prepare 3DS parameters array 
		public function prepare_3ds_post_array($order_id){
			global $woocommerce;
			$order = new WC_Order( $order_id );
			$params = array();

			// Change for 2.1
			if (version_compare(WOOCOMMERCE_VERSION, '2.1', '<')) {
				$currency = $order->order_custom_fields['_order_currency'][0];
			} else {
				$order_meta = get_post_custom( $order_id );
				$currency = $order_meta['_order_currency'][0];
			}
			
			/* merchant parameters */
			$params['merchant']['merchant_id'] 	 	= 	$this->netpayapi_merchant_id;
			$params['merchant']['operation_type'] 	= 	"CHECK_3DS_ENROLLMENT";
			$params['merchant']['operation_mode'] 	= 	$this->operation_mode();
			
			/* transaction parameters*/
			$params['transaction']['amount'] 		= 	$order->order_total;
			$params['transaction']['currency'] 		= 	$currency;
			$params['transaction']['source'] 		= 	"INTERNET";
			
			$paymentOption = $this->get_post('optToken');
			if( isset($paymentOption) && $paymentOption != 'new_card') {
				// get user token for corresponding selected option button.
				$currentUser =  wp_get_current_user();
				$userId = $currentUser->ID;
				
				// get user's stored token
				$userToken = get_user_meta( $userId, 'netpayapi_token', true);
				$userTokenArray = explode("-@#@-",$userToken);
				
				$tokenIndex = substr($paymentOption,1);
				
				$params['payment_source']['type']  				  = 'TOKEN';
				$params['payment_source']['token'] 				  = $this->mcrypt_decrypt_cbc($userTokenArray[$tokenIndex],$this->enc_key,$this->enc_iv);
			}else{
				$expMonth = $this->get_post('expmonth');
				$expMonth = (strlen($expMonth) == 1)? '0'.$expMonth:$expMonth;

				$params['payment_source']['type'] 						 	= 	'CARD';
				$params['payment_source']['card']['card_type'] 			 	= 	strtoupper($this->get_post('cardtype'));
				$params['payment_source']['card']['number'] 				= 	$this->get_post('ccnum');
				$params['payment_source']['card']['expiry_month'] 		 	= 	$expMonth;
				$params['payment_source']['card']['expiry_year'] 		 	= 	substr($this->get_post('expyear'),2,2);
				
			}
			
			/* parameters for 3DS payment */
			$ddd_secure_id = $order_id.time();
			
			if (version_compare(WOOCOMMERCE_VERSION, '2.1', '<')) {
				$redirect_url=(get_option('woocommerce_thanks_page_id')!='') ? get_permalink(get_option('woocommerce_thanks_page_id')): get_site_url().'/' ;
				$relay_url = add_query_arg( array('wc-api' => get_class( $this ) ,'order_id' => $order_id  ), $redirect_url );
			} else {
                $redirect_url=$this->get_return_url( $order );
                $relay_url = add_query_arg( 'wc-api',  get_class( $this ), $redirect_url );
			}
			
			$params['ddd_secure_id'] 	 = $ddd_secure_id;
			$params['ddd_secure_redirect']['page_generation_mode']	= 'SIMPLE';
			$params['ddd_secure_redirect']['response_url']	 		= $relay_url;
			$params['ddd_secure_redirect']['goods_description']	 	= 'Payment for '.get_bloginfo('name');
			
			return $params;
		}

		public function prepare_post_array($order_id){
			global $woocommerce;
			$order = new WC_Order( $order_id );
			
			$params = array();
			
			// Change for 2.1
			if (version_compare(WOOCOMMERCE_VERSION, '2.1', '<')) {
				$currency = $order->order_custom_fields['_order_currency'][0];
			} else {
				$order_meta = get_post_custom( $order_id );
				$currency = $order_meta['_order_currency'][0];
			}
			
			/* merchant parameters */
			$params['merchant']['merchant_id'] 	 	= 	$this->netpayapi_merchant_id;
			$params['merchant']['operation_type'] 	= 	"PURCHASE";
			$params['merchant']['operation_mode'] 	= 	$this->operation_mode();
			
			/* transaction parameters*/
			$params['transaction']['transaction_id'] = 	$order_id.time();
			$params['transaction']['description'] 	= 	"New order with order id ".$order_id." and amount ".$currency." ".$order->order_total." has been placed.";
			$params['transaction']['amount'] 		= 	$order->order_total;
			$params['transaction']['currency'] 		= 	$currency;
			$params['transaction']['source'] 		= 	"INTERNET";
			
			$paymentOption = $this->get_post('optToken');
			
			if( isset($paymentOption) && $paymentOption != 'new_card'){
				
				// get user token for corresponding selected option button.
				$currentUser =  wp_get_current_user();
				$userId = $currentUser->ID;
				
				// get user's stored token
				$userToken = get_user_meta( $userId, 'netpayapi_token', true);
				$userTokenArray = explode("-@#@-",$userToken);
				
				$tokenIndex = substr($paymentOption,1);
				
				$params['payment_source']['type']  				  = 'TOKEN';
				$params['payment_source']['token'] 				  = $this->mcrypt_decrypt_cbc($userTokenArray[$tokenIndex],$this->enc_key,$this->enc_iv);
				$tokenCvv = $this->get_post($this->get_post('optToken').'_cvv');
				
				$params['payment_source']['card']['security_code']= $tokenCvv;
			}else{
				$expMonth = $this->get_post('expmonth');
				$expMonth = (strlen($expMonth) == 1)? '0'.$expMonth:$expMonth;
				
				$params['payment_source']['type'] 						 	= 	'CARD';
				$params['payment_source']['card']['card_type'] 			 	= 	strtoupper($this->get_post('cardtype'));
				$params['payment_source']['card']['number'] 				= 	$this->get_post('ccnum');
				$params['payment_source']['card']['expiry_month'] 		 	= 	$expMonth;
				$params['payment_source']['card']['expiry_year'] 		 	= 	substr($this->get_post('expyear'),2,2);
				$params['payment_source']['card']['security_code'] 		 	= 	$this->get_post('cvv');
				if($this->get_post('cc_title') != ''){
					$params['payment_source']['card']['holder']['title'] 	= 	$this->get_post('cc_title');
				}
				$params['payment_source']['card']['holder']['firstname']  	= 	$this->get_post('cc_fname');
				if($this->get_post('cc_mname') != ''){
					$params['payment_source']['card']['holder']['middlename']= 	$this->get_post('cc_mname');
				}
				$params['payment_source']['card']['holder']['lastname'] 	= 	$this->get_post('cc_lname');
				$params['payment_source']['card']['holder']['fullname'] 	= 	$this->get_post('cc_title')." ".$this->get_post('cc_fname')." ".$this->get_post('cc_mname')." ".$this->get_post('cc_lname');
			}
			
			/* billing information parameters*/
			$params['billing']['bill_to_company'] 	 = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', strip_tags($order->billing_company));
			$params['billing']['bill_to_address']	 = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', strip_tags($order->billing_address_1.' '.$order->billing_address_2));
			$params['billing']['bill_to_town_city']  = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', strip_tags($order->billing_city));
			$params['billing']['bill_to_county'] 	 = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', strip_tags($order->billing_state));
			$params['billing']['bill_to_postcode'] 	 = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', strip_tags($order->billing_postcode));
			$params['billing']['bill_to_country'] 	 = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', strip_tags($this->getValidCountryCode($order->billing_country)));
			$params['billing'] = array_filter($params['billing']);
			
			$items = array();
			$params['order']['order_reference'] = time();
			$itemCnt=0;
			foreach($woocommerce->cart->cart_contents as $cartItem){
				$params['order']['order_items'][$itemCnt]['item_id'] 		  = preg_replace( "/[^0-9]/", "", $cartItem['product_id'] );
				$params['order']['order_items'][$itemCnt]['item_name'] 		  = preg_replace('/[\x00-\x1F\x80-\xFF\|\}\{]/', '', strip_tags($cartItem['data']->post->post_name));
				$params['order']['order_items'][$itemCnt]['item_description'] = preg_replace('/[\x00-\x1F\x80-\xFF\|\}\{]/', '', strip_tags(substr($cartItem['data']->post->post_content,0,97)."..."));//max 100 character
				if( trim($cartItem['quantity']) == '' ) {
					$params['order']['order_items'][$itemCnt]['item_quantity'] 	  = "0";				
				} else {
					$params['order']['order_items'][$itemCnt]['item_quantity'] 	  = preg_replace( "/[^0-9]/", "", $cartItem['quantity'] );
				}
				
                if (version_compare(WOOCOMMERCE_VERSION, '2.1', '<')) {
					// check if sale price is available otherwise, assign regular price
					if(get_post_meta( $cartItem['product_id'], '_sale_price', true) != ''){
						$productPrice = get_post_meta( $cartItem['product_id'], '_sale_price', true);
					} else {
						$productPrice = get_post_meta( $cartItem['product_id'], '_regular_price', true);
					}
				} else {
					// check if sale price is available otherwise, assign regular price
					if($cartItem['data']->sale_price != ''){
						$productPrice = $cartItem['data']->sale_price;
					} else {
						$productPrice = $cartItem['data']->regular_price;
					}
				}
				
				if(trim($productPrice) == '') {
					$productPrice ="0.00";
				}
				
				$params['order']['order_items'][$itemCnt]['item_price'] = $productPrice;
				
				$itemCnt++;
			}
			
			/* shipping information parameters*/
			// If there is no shipping address use billing by default
			if( trim($order->get_shipping_address()) == '' ) {
				$params['shipping']['ship_to_firstname']	= preg_replace('/[\x00-\x1F\x80-\xFF]/', '', strip_tags($order->billing_first_name));
				$params['shipping']['ship_to_lastname'] 	= preg_replace('/[\x00-\x1F\x80-\xFF]/', '', strip_tags($order->billing_last_name));
				$params['shipping']['ship_to_fullname'] 	= preg_replace('/[\x00-\x1F\x80-\xFF]/', '', strip_tags($order->billing_first_name.' '.$order->billing_last_name));
				$params['shipping']['ship_to_company'] 		= preg_replace('/[\x00-\x1F\x80-\xFF]/', '', strip_tags($order->billing_company));
				$params['shipping']['ship_to_address'] 		= preg_replace('/[\x00-\x1F\x80-\xFF]/', '', strip_tags($order->billing_address_1.' '.$order->billing_address_2));
				$params['shipping']['ship_to_town_city']	= preg_replace('/[\x00-\x1F\x80-\xFF]/', '', strip_tags($order->billing_city));
				$params['shipping']['ship_to_county'] 		= preg_replace('/[\x00-\x1F\x80-\xFF]/', '', strip_tags($order->billing_state));
				$params['shipping']['ship_to_postcode'] 	= preg_replace('/[\x00-\x1F\x80-\xFF]/', '', strip_tags($order->billing_postcode));
				$params['shipping']['ship_to_country'] 		= preg_replace('/[\x00-\x1F\x80-\xFF]/', '', strip_tags($this->getValidCountryCode($order->billing_country)));
			} else {
				$params['shipping']['ship_to_firstname']	= preg_replace('/[\x00-\x1F\x80-\xFF]/', '', strip_tags($order->shipping_first_name));
				$params['shipping']['ship_to_lastname'] 	= preg_replace('/[\x00-\x1F\x80-\xFF]/', '', strip_tags($order->shipping_last_name));
				$params['shipping']['ship_to_fullname'] 	= preg_replace('/[\x00-\x1F\x80-\xFF]/', '', strip_tags($order->shipping_first_name.' '.$order->shipping_last_name));
				$params['shipping']['ship_to_company'] 		= preg_replace('/[\x00-\x1F\x80-\xFF]/', '', strip_tags($order->shipping_company));
				$params['shipping']['ship_to_address'] 		= preg_replace('/[\x00-\x1F\x80-\xFF]/', '', strip_tags($order->shipping_address_1.' '.$order->shipping_address_2));
				$params['shipping']['ship_to_town_city']	= preg_replace('/[\x00-\x1F\x80-\xFF]/', '', strip_tags($order->shipping_city));
				$params['shipping']['ship_to_county'] 		= preg_replace('/[\x00-\x1F\x80-\xFF]/', '', strip_tags($order->shipping_state));
				$params['shipping']['ship_to_postcode'] 	= preg_replace('/[\x00-\x1F\x80-\xFF]/', '', strip_tags($order->shipping_postcode));
				$params['shipping']['ship_to_country'] 		= preg_replace('/[\x00-\x1F\x80-\xFF]/', '', strip_tags($this->getValidCountryCode($order->shipping_country)));
			}
			$params['shipping']['ship_to_method'] 		= $order->shipping_method;
			$params['shipping'] = array_filter($params['shipping']);
			
			/* customer information parameters*/
			$uagent	= $this->getBrowser();	
			$params['customer']['customer_email'] 		= preg_replace('/[\x00-\x1F\x80-\xFF]/', '', strip_tags($order->billing_email));
			$params['customer']['customer_phone'] 		= preg_replace('/[^0-9]/', '', strip_tags($order->billing_phone));
			$params['customer']['customer_ip_address'] 	= $_SERVER['REMOTE_ADDR'];
			$params['customer']['customer_hostname']   	= $_SERVER['HTTP_HOST'];
			$params['customer']['customer_browser'] 	= $uagent['name'];
			$params['customer'] = array_filter($params['customer']);
		
			return $params;
		}
		
		
		/*	function to create token   */
		function _createCardToken($tokenType){
			// include api class
			include_once("netpay_api.php");
			
			$version  	= 	'v1';
			$api_method	= 	'gateway/token';
			
			$token_params  = array();
			$token_params['merchant']['merchant_id'] 	= $this->netpayapi_merchant_id;
			$token_params['merchant']['operation_type'] = 'CREATE_TOKEN';
			$token_params['merchant']['operation_mode']	= $this->operation_mode();
			
			$token_params['transaction']['source']	 	= "INTERNET";
						
			$expMonth = $this->get_post('expmonth');
			$expMonth = (strlen($expMonth) == 1)? '0'.$expMonth:$expMonth;

			$fullname = '';
			if($this->get_post('cc_title') != ''){
				$params['payment_source']['card']['holder']['title'] 	= 	$this->get_post('cc_title');
			}
			$params['payment_source']['card']['holder']['firstname']  	= 	$this->get_post('cc_fname');
			$fullname = $this->get_post('cc_fname');
			if($this->get_post('cc_mname') != ''){
				$params['payment_source']['card']['holder']['middlename']= 	$this->get_post('cc_mname');
				$fullname .= $this->get_post('cc_fname').' '.$this->get_post('cc_mname');
			}
			$params['payment_source']['card']['holder']['lastname'] 	= 	$this->get_post('cc_lname');
			$fullname .= ' '.$this->get_post('cc_lname');
			
			$token_params['payment_source']['type']	 							=  	'CARD';
			$token_params['payment_source']['card']['card_type'] 				=  	strtoupper($this->get_post('cardtype'));
			$token_params['payment_source']['card']['number'] 					=  	$this->get_post('ccnum');
			$token_params['payment_source']['card']['expiry_month'] 			=  	$expMonth;
			$token_params['payment_source']['card']['expiry_year']  			=  	substr($this->get_post('expyear'),2,2);
			if($this->get_post('cc_title') != ''){
				$token_params['payment_source']['card']['holder']['title']		= 	$this->get_post('cc_title');
			}
			$token_params['payment_source']['card']['holder']['firstname'] 		= 	$this->get_post('cc_fname');
			if($this->get_post('cc_mname') != ''){
				$token_params['payment_source']['card']['holder']['middlename']	= 	$this->get_post('cc_mname');
			}
			$token_params['payment_source']['card']['holder']['lastname'] 		= 	$this->get_post('cc_lname');
			$token_params['payment_source']['card']['holder']['fullname'] 		= 	$fullname;
			$token_params['token_mode']											= 	$tokenType;	
			
			$rest   = new Connection($this->gateway_url(), $this->get_headers_array());
			$token_result = $rest->put($api_method, $token_params);
			
			$token = $token_result->token;
			//echo "In Token: <pre>";print_r($token_result);exit;
			if(strtoupper($token_result->result) == "SUCCESS"){
				// success return token 
				return $token;
			} else {
				$error = "ERROR";
				return $error;
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
    	 * Check payment details for valid format
    	*/
		function validate_fields() {
      		global $woocommerce;
			$paymentOption = $this->get_post('optToken');
			
			if(isset($paymentOption) && $paymentOption != 'new_card'){
				// Check security code
				$tokenField = $this->get_post('optToken')."_cvv";
				$cardType = '';
				$cardCSC = $_POST[$tokenField];
				if(!isset($cardCSC)){
					$woocommerce->add_error( __( 'Card security code is invalid (only digits are allowed).', 'woocommerce' ) );
					return false;
				}
				
				
				if ( ! ctype_digit( $cardCSC ) ) {
					$woocommerce->add_error( __( 'Card security code is invalid (only digits are allowed).', 'woocommerce' ) );
					return false;
				}
				if(( strlen( $cardCSC ) != 3 && in_array( $cardType, array( 'Visa', 'MasterCard', 'Discover' ))) || ( strlen($cardCSC) != 4 && $cardType == 'American Express')){
					$woocommerce->add_error( __( 'Card security code is invalid (wrong length).', 'woocommerce' ) );
					return false;
				}
				return true;
				exit;
			}
			
			$fname 					=	$this->get_post( 'cc_fname' );
			$lname 					=	$this->get_post( 'cc_lname' );
			$cardType            	= 	$this->get_post( 'cardtype' );
			$cardNumber          	= 	$this->get_post( 'ccnum' );
			$cardCSC             	= 	$this->get_post( 'cvv' );
			$cardExpirationMonth 	= 	$this->get_post( 'expmonth' );
			$cardExpirationYear  	= 	$this->get_post( 'expyear' );
			$payby  				= 	$this->get_post( 'payby' );
			$tokenValue  			= 	$this->get_post( 'token' );
			
			// Check if payment by token selected and token selected
			if($payby == 'bytoken' && $tokenValue == ''){
				$woocommerce->add_error( __( 'No Token selected.', 'woocommerce' ) );
				return false;
			}

			// Check Card Hodlder First Name
			if ( empty( $fname ) ) {
				$woocommerce->add_error( __( 'Card Hodlder First Name is required.', 'woocommerce' ) );
				return false;
			}
			
			// Check Card Hodlder First Name
			if ( empty( $lname ) ) {
				$woocommerce->add_error( __( 'Card Hodlder Last Name is required.', 'woocommerce' ) );
				return false;
			}

			// Check card number
			if ( empty( $cardNumber ) || ! ctype_digit( $cardNumber ) ) {
				$woocommerce->add_error( __( 'Card number is invalid.', 'woocommerce' ) );
				return false;
			}

			// Check security code
			if ( ! ctype_digit( $cardCSC ) ) {
				$woocommerce->add_error( __( 'Card security code is invalid (only digits are allowed).', 'woocommerce' ) );
				return false;
			}
			if(( strlen( $cardCSC ) != 3 && in_array( $cardType, array( 'Visa', 'MasterCard', 'Discover' ))) || ( strlen($cardCSC) != 4 && $cardType == 'American Express')){
				$woocommerce->add_error( __( 'Card security code is invalid (wrong length).', 'woocommerce' ) );
				return false;
			}
			
			// Check expiration data
			$currentYear = date( 'Y' );

			if ( ! ctype_digit( $cardExpirationMonth ) || ! ctype_digit( $cardExpirationYear ) ||
				 $cardExpirationMonth > 12 ||
				 $cardExpirationMonth < 1 ||
				 $cardExpirationYear < $currentYear ||
				 $cardExpirationYear > $currentYear + 20
			) {
				$woocommerce->add_error( __( 'Card expiration date is invalid', 'woocommerce' ) );
				return false;
			}

			// Strip spaces and dashes
			$cardNumber = str_replace( array( ' ', '-' ), '', $cardNumber );

			return true;
		
		}

		/* function to get form post values */
		function get_post( $name ) {
			if ( isset( $_POST[ $name ] ) ) {
				return $_POST[ $name ];
			}
			return null	;
		}
		
		/* Get User Browser */
		function getBrowser(){
		  	$u_agent = $_SERVER['HTTP_USER_AGENT'];
		  	$bname = 'Unknown';
		  	$platform = 'Unknown';
		  	$version= "";
		 
		  	//First get the platform?
		  	if (preg_match('/linux/i', $u_agent)) {
		   		$platform = 'linux';
		  	} elseif (preg_match('/macintosh|mac os x/i', $u_agent)) {
		   		$platform = 'mac';
		  	} elseif (preg_match('/windows|win32/i', $u_agent)) {
		   		$platform = 'windows';
		  	}
		 
		  	// Next get the name of the useragent yes seperately and for good reason
		  	if(preg_match('/MSIE/i',$u_agent) && !preg_match('/Opera/i',$u_agent)){
		   		$bname = 'Internet Explorer';
		   		$ub = "MSIE";
		  	} elseif(preg_match('/Firefox/i',$u_agent)) {
		   		$bname = 'Mozilla Firefox';
		   		$ub = "Firefox";
		  	} elseif(preg_match('/Chrome/i',$u_agent)){
		   		$bname = 'Google Chrome';
		   		$ub = "Chrome";
		  	} elseif (preg_match('/Safari/i',$u_agent)){
		   		$bname = 'Apple Safari';
		   		$ub = "Safari";
		  	} elseif(preg_match('/Opera/i',$u_agent)){
			   	$bname = 'Opera';
			   	$ub = "Opera";
		  	} elseif(preg_match('/Netscape/i',$u_agent)) {
				$bname = 'Netscape';
				$ub = "Netscape";
		  	}
		 
		  	// finally get the correct version number
		  	$known = array('Version', $ub, 'other');
		  	$pattern = '#(?<browser>' . join('|', $known) .
		  	')[/ ]+(?<version>[0-9.|a-zA-Z.]*)#';
		  	if (!preg_match_all($pattern, $u_agent, $matches)) {
		  		// we have no matching number just continue
		  	}
		 
		  	// see how many we have
		  	$i = count($matches['browser']);
		  	if ($i != 1) {
		   		//we will have two since we are not using 'other' argument yet
		   		//see if version is before or after the name
		   		if (strripos($u_agent,"Version") < strripos($u_agent,$ub)){
					$version= $matches['version'][0];
		   		} else {
					$version= $matches['version'][1];
		   		}
		  	} else {
		   		$version= $matches['version'][0];
		  	}
		 
		  	// check if we have a number
		  	if ($version==null || $version=="") {$version="?";}
		 
		  	return array(
		   				'userAgent' => $u_agent,
						'name'      => $bname,
						'version'   => $version,
						'platform'  => $platform,
						'pattern'    => $pattern
		  			);
		}
		 
		// Function to get ISO Country Code for the 2 character country code
		function getValidCountryCode($code){
		 	$countries = array(
				'AF' => 'AFG',
				'AL' => 'ALB',
				'DZ' => 'DZA',
				'AD' => 'AND',
				'AO' => 'AGO',
				'AI' => 'AIA',
				'AQ' => 'ATA',
				'AG' => 'ATG',
				'AR' => 'ARG',
				'AM' => 'ARM',
				'AW' => 'ABW',
				'AU' => 'AUS',
				'AT' => 'AUT',
				'AZ' => 'AZE',
				'BS' => 'BHS',
				'BH' => 'BHR',
				'BD' => 'BGD',
				'BB' => 'BRB',
				'BY' => 'BLR',
				'BE' => 'BEL',
				'BZ' => 'BLZ',
				'BJ' => 'BEN',
				'BM' => 'BMU',
				'BT' => 'BTN',
				'BO' => 'BOL',
				'BA' => 'BIH',
				'BW' => 'BWA',
				'BV' => 'BVT',
				'BR' => 'BRA',
				'IO' => 'IOT',
				'VG' => 'VGB',
				'BN' => 'BRN',
				'BG' => 'BGR',
				'BF' => 'BFA',
				'BI' => 'BDI',
				'KH' => 'KHM',
				'CM' => 'CMR',
				'CA' => 'CAN',
				'CV' => 'CPV',
				'KY' => 'CYM',
				'CF' => 'CAF',
				'TD' => 'TCD',
				'CL' => 'CHL',
				'CN' => 'CHN',
				'CX' => 'CXR',
				'CC' => 'CCK',
				'CO' => 'COL',
				'KM' => 'COM',
				'CG' => 'COG',
				'CD' => 'COD',
				'CK' => 'COK',
				'CR' => 'CRI',
				'HR' => 'HRV',
				'CU' => 'CUB',
				'CY' => 'CYP',
				'CZ' => 'CZE',
				'DK' => 'DNK',
				'DJ' => 'DJI',
				'DM' => 'DMA',
				'DO' => 'DOM',
				'EC' => 'ECU',
				'EG' => 'EGY',
				'SV' => 'SLV',
				'GQ' => 'GNQ',
				'ER' => 'ERI',
				'EE' => 'EST',
				'ET' => 'ETH ',
				'FK' => 'FLK',
				'FO' => 'FRO',
				'FJ' => 'FJI',
				'FI' => 'FIN',
				'FR' => 'FRA',
				'GF' => 'GUF',
				'PF' => 'PYF',
				'TF' => 'ATF',
				'GA' => 'GAB',
				'GM' => 'GMB',
				'GE' => 'GEO',
				'DE' => 'DEU',
				'GH' => 'GHA',
				'GI' => 'GIB',
				'GR' => 'GRC',
				'GL' => 'GRL',
				'GD' => 'GRD',
				'GP' => 'GLP',
				'GT' => 'GTM',
				'GN' => 'GIN',
				'GW' => 'GNB',
				'GY' => 'GUY',
				'HT' => 'HTI',
				'HM' => 'HMD',
				'HN' => 'VAT',
				'HK' => 'HKG',
				'HU' => 'HUN',
				'IS' => 'ISL',
				'IN' => 'IND',
				'ID' => 'IDN',
				'IR' => 'IRN',
				'IQ' => 'IRQ',
				'IE' => 'IRL',
				'IL' => 'ISR',
				'IT' => 'ITA',
				'CI' => 'CIV',
				'JM' => 'JAM',
				'JP' => 'JPN',
				'JO' => 'JOR',
				'KZ' => 'KAZ',
				'KE' => 'KEN',
				'KI' => 'KIR',
				'KW' => 'KWT',
				'KG' => 'KGZ',
				'LA' => 'LAO',
				'LV' => 'LVA',
				'LB' => 'LBN',
				'LS' => 'LSO',
				'LR' => 'LBR',
				'LY' => 'LBY',
				'LI' => 'LIE',
				'LT' => 'LTU',
				'LU' => 'LUX',
				'MO' => 'MAC',
				'MK' => 'MKD',
				'MG' => 'MDG',
				'MW' => 'MWI',
				'MY' => 'MYS',
				'MV' => 'MDV',
				'ML' => 'MLI',
				'MT' => 'MLT',
				'MH' => 'MHL',
				'MQ' => 'MTQ',
				'MR' => 'MRT',
				'MU' => 'MUS',
				'YT' => 'MYT',
				'MX' => 'MEX',
				'FM' => 'FSM',
				'MD' => 'MDA',
				'MC' => 'MCO',
				'MN' => 'MNG',
				'ME' => 'MNE',
				'MS' => 'MSR',
				'MA' => 'MAR',
				'MZ' => 'MOZ',
				'MM' => 'MMR',
				'NA' => 'NAM',
				'NR' => 'NRU',
				'NP' => 'NPL',
				'NL' => 'NLD',
				'AN' => 'ANT',
				'NC' => 'NCL',
				'NZ' => 'NZL',
				'NI' => 'NIC',
				'NE' => 'NER',
				'NG' => 'NGA',
				'NU' => 'NIU',
				'NF' => 'NFK',
				'KP' => 'PRK',
				'NO' => 'NOR',
				'OM' => 'OMN',
				'PK' => 'PAK',
				'PS' => 'PSE',
				'PA' => 'PAN',
				'PG' => 'PNG',
				'PY' => 'PRY',
				'PE' => 'PER',
				'PH' => 'PHL',
				'PN' => 'PCN',
				'PL' => 'POL',
				'PT' => 'PRT',
				'QA' => 'QAT',
				'RE' => 'REU',
				'RO' => 'ROM',
				'RU' => 'RUS',
				'RW' => 'RWA',
				'BL' => 'BLM',
				'SH' => 'SHN',
				'KN' => 'KNA',
				'LC' => 'LCA',
				'MF' => 'MAF',
				'PM' => 'SPM',
				'VC' => 'VCT',
				'SM' => 'SMR',
				'ST' => 'STP',
				'SA' => 'SAU',
				'SN' => 'SEN',
				'RS' => 'SRB',
				'SC' => 'SYC',
				'SL' => 'SLE',
				'SG' => 'SGP',
				'SK' => 'SVK',
				'SI' => 'SVN',
				'SB' => 'SLB',
				'SO' => 'SOM',
				'ZA' => 'ZAF',
				'GS' => 'SGS',
				'KR' => 'KOR',
				'SS' => 'SSD',
				'ES' => 'ESP',
				'LK' => 'LKA',
				'SD' => 'SDN',
				'SR' => 'SUR',
				'SJ' => 'SJM',
				'SZ' => 'SWZ',
				'SE' => 'SWE',
				'CH' => 'CHE',
				'SY' => 'SYR',
				'TW' => 'TWN',
				'TJ' => 'TJK',
				'TZ' => 'TZA',
				'TH' => 'THA',
				'TL' => 'TLS',
				'TG' => 'TGO',
				'TK' => 'TKL',
				'TO' => 'TON',
				'TT' => 'TTO',
				'TN' => 'TUN',
				'TR' => 'TUR',
				'TM' => 'TKM',
				'TC' => 'TCA',
				'TV' => 'TUV',
				'UG' => 'UGA',
				'UA' => 'UKR',
				'AE' => 'ARE',
				'GB' => 'GBR',
				'US' => 'USA',
				'UY' => 'URY',
				'UZ' => 'UZB',
				'VU' => 'VUT',
				'VA' => 'VAT',
				'VE' => 'VEN',
				'VN' => 'VNM',
				'WF' => 'WLF',
				'EH' => 'ESH',
				'WS' => 'WSM',
				'YE' => 'YEM',
				'ZM' => 'ZMB',
				'ZW' => 'ZWE',
				'PW' => 'PLW',
				'BQ' => 'BES',
				'CW' => 'CUW',
				'GG' => 'GGY',
				'IM' => 'IMN',
				'JE' => 'JEY',
				'SX' => 'SXM'
				);
				
			return $countries[$code];
				
		}
		 
		// function to return card full name
		function cardFullName($cardType){
			$cardArray = array(
							'VISA'		=> 	'Visa',
							'VISAUK'	=> 	'Visa Debit UK',
							'ELEC'		=> 	'Visa Electron',
							'MCRD'		=> 	'MasterCard',
							'MCDB'		=> 	'MasterCard Debit',
							'MSTO'		=> 	'Maestro',
							'AMEX'  	=> 	'American Express',
							'DINE'		=> 	'Diners'
						);
			return $cardArray[$cardType];
		}
		
		// function to return error description retuned from NetPay
		function getErrorDescription($errCode){
			$errorMsgDetail = array(
								'APPROVED' => 'Transaction Approved',
								'SUBMITTED' => 'Transaction submitted - response has not yet been received',
								'PENDING' => 'Transaction is pending',
								'APPROVED_PENDING_SETTLEMENT' => 'Transaction Approved - pending batch settlement',
								'UNSPECIFIED_FAILURE' => 'Transaction could not be processed',
								'DECLINED' => ' Transaction declined by issuer',
								'TIMED_OUT' => ' Response timed out',
								'EXPIRED_CARD' => 'Transaction declined due to expired card',
								'INSUFFICIENT_FUNDS' => 'Transaction declined due to insufficient funds',
								'ACQUIRER_SYSTEM_ERROR' => 'Acquirer system error occurred processing the transaction',
								'SYSTEM_ERROR' => 'Internal system error occurred processing the transaction',
								'NOT_SUPPORTED' => 'Transaction type not supported',
								'DECLINED_DO_NOT_CONTACT' => 'Transaction declined - do not contact issuer',
								'ABORTED' => 'Transaction aborted by payer',
								'BLOCKED' => 'Transaction blocked due to Risk or 3D Secure blocking rules',
								'CANCELLED' => 'Transaction cancelled by payer',
								'DEFERRED_TRANSACTION_RECEIVED' => 'Deferred transaction received and awaiting processing',
								'REFERRED' => 'Transaction declined - refer to issuer',
								'AUTHENTICATION_FAILED' => '3D Secure authentication failed',
								'INVALID_CSC' => 'Invalid card security code',
								'LOCK_FAILURE' => 'Order locked - another transaction is in progress for this order',
								'NOT_ENROLLED_3D_SECURE' => 'Card holder is not enrolled in 3D Secure',
								'EXCEEDED_RETRY_LIMIT' => 'Transaction retry limit exceeded',
								'DUPLICATE_BATCH' => 'Transaction declined due to duplicate batch',
								'DECLINED_AVS' => 'Transaction declined due to address verification',
								'DECLINED_CSC' => 'Transaction declined due to card security code',
								'DECLINED_AVS_CSC' => 'Transaction declined due to address verification and card security code',
								'DECLINED_PAYMENT_PLAN' => 'Transaction declined due to payment plan',
								'UNKNOWN' => 'Response unknown',
								'CARD_NOT_ENROLLED' => 'The card is not enrolled for 3DS authentication',
								'AUTHENTICATION_NOT_AVAILABLE' => 'Authentication is not currently available',
								'AUTHENTICATION_FAILED' => '3DS authentication failed',
								'AUTHENTICATION_ATTEMPTED' => 'Authentication was attempted but the card issuer did not perform the authentication',
								'CARD_DOES_NOT_SUPPORT_3DS' => 'The card does not support 3DS authentication'
							);
			return $errorMsgDetail[$errCode];
		}
		
   }

   /**
    * Add this Gateway to WooCommerce
   **/
   	function woocommerce_add_tech_netpayapi_gateway($methods) 
   	{
      	$methods[] = 'WC_Tech_Netpayapi';
      	return $methods;
   	}

   	add_filter('woocommerce_payment_gateways', 'woocommerce_add_tech_netpayapi_gateway' );
	
	
}