<?php
/*
Plugin Name: WooCommerce PagSeguro
Plugin URI: http://wooplugins.com.br/loja/pagseguro-gateway/
Description: Adiciona o gateway de pagamento do PagSeguro no WooCommerce
Version: 2.0
Author: Felipe Matos <chucky_ath@yahoo.com.br>
Author URI: http://felipematos.com
License: Commercial
Requires at least: 3.4
Tested up to: 3.5
*/


//-------------------------------------------------------------------------------------------
// ##### PLUGIN AUTO UPDATE CODE #####
//-------------------------------------------------------------------------------------------

//Making sure wordpress does not check this plugin into their repository
add_filter( 'http_request_args', 'pagseguro_prevent_update_check', 10, 2 );
function pagseguro_prevent_update_check( $r, $url ) {
    if ( 0 === strpos( $url, 'http://api.wordpress.org/plugins/update-check/' ) ) {
        $my_plugin = plugin_basename( __FILE__ );
        $plugins = unserialize( $r['body']['plugins'] );
        unset( $plugins->plugins[$my_plugin] );
        unset( $plugins->active[array_search( $my_plugin, $plugins->active )] );
        $r['body']['plugins'] = serialize( $plugins );
    }
    return $r;
}


// TEMP: Enable update check on every request. Normally you don't need this! This is for testing only!
// NOTE: The 
//	if (empty($checked_data->checked))
//		return $checked_data; 
// lines will need to be commented in the check_for_plugin_update function as well.
get_site_transient( 'update_plugins' ); // unset the plugin
set_site_transient( 'update_plugins', '' ); // reset plugin database information
// TEMP: Show which variables are being requested when query plugin API
//add_filter('plugins_api_result', 'pagseguro_result', 10, 3);
//function pagseguro_result($res, $action, $args) {
//	print_r($res);
//	return $res;
//}
// NOTE: All variables and functions will need to be prefixed properly to allow multiple plugins to be updated

$api_url = 'http://update.wooplugins.com.br';
$plugin_slug = basename(dirname(__FILE__));

// Take over the update check
add_filter('pre_set_site_transient_update_plugins', 'pagseguro_check_for_plugin_update');

function pagseguro_check_for_plugin_update($checked_data) {
	global $api_url, $plugin_slug;
	
	//Comment out these two lines during testing.
	if (empty($checked_data->checked))
		return $checked_data;
	
	$args = array(
		'slug' => $plugin_slug,
		'version' => $checked_data->checked[$plugin_slug .'/'. $plugin_slug .'.php'],
	);
	$request_string = array(
			'body' => array(
				'action' => 'basic_check', 
				'request' => serialize($args),
				'api-key' => md5(get_bloginfo('url'))
			),
			'user-agent' => 'WordPress/' . $wp_version . '; ' . get_bloginfo('url')
		);
	
	// Start checking for an update
	$raw_response = wp_remote_post($api_url, $request_string);
	
	if (!is_wp_error($raw_response) && ($raw_response['response']['code'] == 200))
		$response = unserialize($raw_response['body']);
	
	if (is_object($response) && !empty($response)) // Feed the update data into WP updater
		$checked_data->response[$plugin_slug .'/'. $plugin_slug .'.php'] = $response;
	
	return $checked_data;
}


// Take over the Plugin info screen
add_filter('plugins_api', 'pagseguro_plugin_api_call', 10, 3);

function pagseguro_plugin_api_call($def, $action, $args) {
	global $plugin_slug, $api_url;
	
	if ($args->slug != $plugin_slug)
		return false;
	
	// Get the current version
	$plugin_info = get_site_transient('update_plugins');
	$current_version = $plugin_info->checked[$plugin_slug .'/'. $plugin_slug .'.php'];
	$args->version = $current_version;
	
	$request_string = array(
			'body' => array(
				'action' => $action, 
				'request' => serialize($args),
				'api-key' => md5(get_bloginfo('url'))
			),
			'user-agent' => 'WordPress/' . $wp_version . '; ' . get_bloginfo('url')
		);
	
	$request = wp_remote_post($api_url, $request_string);
	
	if (is_wp_error($request)) {
		$res = new WP_Error('plugins_api_failed', __('An Unexpected HTTP Error occurred during the API request.</p> <p><a href="?" onclick="document.location.reload(); return false;">Try again</a>'), $request->get_error_message());
	} else {
		$res = unserialize($request['body']);
		
		if ($res === false)
			$res = new WP_Error('plugins_api_failed', __('An unknown error occurred'), $request['body']);
	}
	
	return $res;
}
//-------------------------------------------------------------------------------------------
// ##### PLUGIN AUTO UPDATE CODE #####
//-------------------------------------------------------------------------------------------




/**
 * WooCommerce fallback notice.
 */
function pagseguro_woocommerce_fallback_notice() {
    $message = '<div class="error">';
	$message .= '<p>' . __( 'WooCommerce PagSeguro Gateway depends on the last version of <a href="http://wordpress.org/extend/plugins/woocommerce/">WooCommerce</a> to work!' , 'pagseguro' ) . '</p>';
    $message .= '</div>';

    echo $message;
}



//hook to include the payment gateway function
add_action('plugins_loaded', 'gateway_pagseguro', 0);


//hook function
function gateway_pagseguro(){


    if ( !class_exists( 'WC_Payment_Gateway' ) || !class_exists( 'WC_Order_Item_Meta' ) ) {
        add_action( 'admin_notices', 'pagseguro_woocommerce_fallback_notice' );

        return;
    }


	require_once "PagSeguroLibrary/PagSeguroLibrary.php";

	//---------------------------------------------------------------------------------------------------
	//Classe: woocommerce_pagseguro
  	//Descrição: classe de implementação do gateway PagSeguro
  	//---------------------------------------------------------------------------------------------------
  	class woocommerce_pagseguro extends WC_Payment_Gateway {

  		//---------------------------------------------------------------------------------------------------
  		//Função: __construct
  		//Descrição: cria e inicializa o objeto da classe
  		//---------------------------------------------------------------------------------------------------
  		public function __construct() {
      		global $woocommerce;

      		$this->id           = 'pagseguro';
            $this->method_title = __( 'PagSeguro', 'woothemes' );
			$this->icon         = apply_filters('woocommerce_pagseguro_icon', $url = plugin_dir_url(__FILE__).'pagseguro.png');
      		$this->has_fields   = false;

      		// Load the form fields.
      		$this->init_form_fields();

      		// Load the settings.
      		$this->init_settings();

      		// Define user set variables
      		$this->title       = $this->settings['title'];
      		$this->description = $this->settings['description'];
      		$this->email       = $this->settings['email'];
      		$this->tokenid     = $this->settings['tokenid'];
      		$this->usewcfields = $this->settings['usewcfields'];
            $this->valid_address  = $this->settings['valid_address'];
      		$this->debug       = $this->settings['debug'];

      		// Logs
      		if ($this->debug=='yes') $this->log = $woocommerce->logger();

      		// Actions
      		add_action('init', array(&$this, 'check_ipn_response') );
      		add_action('valid-'.$this->id.'-standard-ipn-request', array(&$this, 'successful_request') );
      		add_action('woocommerce_receipt_'.$this->id, array(&$this, 'receipt_page'));
      		add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));


            if ( $this->valid_address == 'yes' && !function_exists('custom_override_billing_fields')) {
                add_action( 'woocommerce_checkout_process', array( &$this, 'valid_address' ) );
            }
			
			//check if woocommerce-fields extension is available, it will force to no even in the admin is yes
			if (!function_exists('custom_override_billing_fields')) {
				$this->usewcfields = 'no';
			}
			
            // Valid for use.
      		//if ( !$this->is_valid_for_use() ) $this->enabled = false;
            $this->enabled = ( 'yes' == $this->settings['enabled'] ) && !empty( $this->email ) && !empty( $this->tokenid ) && $this->is_valid_for_use();

            // Checks if email is not empty.
            $this->email == '' ? add_action( 'admin_notices', array( &$this, 'mail_missing_message' ) ) : '';

            // Checks if token is not empty.
            $this->tokenid == '' ? add_action( 'admin_notices', array( &$this, 'token_missing_message' ) ) : '';

            // Filters.
            add_filter( 'woocommerce_available_payment_gateways', array( &$this, 'hides_when_is_outside_brazil' ) );
			
  		} //Fim da função __construct



  		//---------------------------------------------------------------------------------------------------
  		//Função: is_valid_for_use
  		//Descrição: checa se o gateway está habilitado a disponível para o país do usuário
  		//---------------------------------------------------------------------------------------------------
  		function is_valid_for_use() {
      		if (!in_array(get_option('woocommerce_currency'), array('BRL')))
      			return false;
      		return true;
  		} //Fim da função is_valid_for_use



  		//---------------------------------------------------------------------------------------------------
  		//Função: init_form_fields
  		//Descrição: função do woocommerce que inicializa as variáveis a serem exibidas no painel de
  		//           configuração do woocommerce.
  		//---------------------------------------------------------------------------------------------------
  		function init_form_fields() {
  			$this->form_fields = array(
  					'enabled' => array(
  						'title' => __( 'Habilita/Desabilita', 'woothemes' ),
  						'type' => 'checkbox',
  						'label' => __( 'Habilita o PagSeguro', 'woothemes' ),
  						'default' => 'yes'
  					),
					'title' => array(
						'title' => __( 'Título', 'woothemes' ),
						'type' => 'text',
						'description' => __( 'Título que será exibido da forma de pagamento durante o checkout.', 'woothemes' ),
						'default' => __( 'Pague com PagSeguro', 'woothemes' )
					),
					'description' => array(
						'title' => __( 'Mensagem', 'woothemes' ),
						'type' => 'textarea',
						'description' => __( 'Exibe uma mensagem de texto ao selecionar o meio de pagamento (opcional).', 'woothemes' ),
						'default' => ''
					),
					'email' => array(
						'title' => __( 'E-Mail', 'woothemes' ),
						'type' => 'text',
						'description' => __( 'e-Mail da conta do PagSeguro que receberá os pagamentos.', 'woothemes' )
					),
					'tokenid' => array(
						'title' => __( 'Token', 'woothemes' ),
						'type' => 'text',
						'description' => __( 'Token gerado pelo PagSeguro para pagamento via API', 'woothemes' )
					),
					'usewcfields' => array(
  						'title' => __( 'Utilizar Campos do WooCommerce Fields?', 'woothemes' ),
  						'type' => 'checkbox',
  						'label' => __( 'Se você instalou o <a href=\'http://wooplugins.com.br/loja/woocommerce-fields/\'>WooCommerceFields</a>, habilite este campo para permitir o uso dos campos adicionais.', 'woothemes' ),
  						'default' => 'yes'
  					),
					'valid_address' => array(
						'title' => __( 'Validate Address', 'woothemes' ),
						'type' => 'checkbox',
						'label' => __( 'Enable validation', 'woothemes' ),
						'default' => 'yes',
						'description' => __( 'Validates the customer\'s address in the format "street example, number".', 'woothemes' ),
					),
					'debug' => array(
						'title' => __( 'Debug', 'woothemes' ),
						'type' => 'checkbox',
						'label' => __( 'Habilita a escrita de log (<code>woocommerce/logs/pagseguro.txt</code>)', 'woothemes' ),
						'default' => 'no'
					)
			);
  		} //Fim da função init_form_fields



  		//---------------------------------------------------------------------------------------------------
  		//Função: admin_options
  		//Descrição: gera o formulário a ser exibido no painel deconfiguração
  		//---------------------------------------------------------------------------------------------------
  		public function admin_options() {
  			?>
	      		<h3><?php _e('PagSeguro', 'woothemes'); ?></h3>
	      		<p><?php _e('Opção para pagamento através do PagSeguro', 'woothemes'); ?></p>
	      		<table class="form-table">
	      		<?php
					if ( !$this->is_valid_for_use() ) {

						// Valid currency.
						echo '<div class="inline error"><p><strong>' . __( 'Gateway Disabled', 'woothemes' ) . '</strong>: ' . __( 'PagSeguro não suporte esta moeda.', 'woothemes' ) . '</p></div>';

					} else {

						// Generate the HTML For the settings form.
						$this->generate_settings_html();
					}
	      		?>
	      		</table><!--/.form-table-->
      		<?php
    	} //Fim da função admin_options



    	//---------------------------------------------------------------------------------------------------
  		//Função: payment_fields
  		//Descrição: Exibe a Mensagem ao selecionar a forma de pagamento se ela estiver definida
  		//---------------------------------------------------------------------------------------------------
  		function payment_fields() {
      		if ($this->description)
      			echo wpautop(wptexturize($this->description));
    	} //Fim da função payment_fields



    	//---------------------------------------------------------------------------------------------------
  		//Função: generate_pagseguro_form
  		//Descrição: gera o formulário de pagamento e envia os dados para o PagSeguro
  		//---------------------------------------------------------------------------------------------------
    	function generate_pagseguro_form($order_id){
      		global $woocommerce;
      		$order = &new woocommerce_order( $order_id );

            // Fixed postal code.
            $order->billing_postcode = str_replace( array( '-', ' ' ), '', $order->billing_postcode );

            // Fixed Country.
            if ( $order->billing_country == 'BR' ) {
                $order->billing_country = 'BRA';
            }
			
			
      		//Cria um objeto de requisição de pagamento e popula os dados
      		$paymentRequest = new PagSeguroPaymentRequest();
      		$paymentRequest->setCurrency("BRL");
      		$paymentRequest->setReference($order->id);
			$paymentRequest->setRedirectUrl(urlencode(htmlspecialchars($this->get_return_url($order))));
			//workaround to get tested in localhost
      		if (in_array($_SERVER['REMOTE_ADDR'], array('127.0.0.1', '::1'))) {
				$paymentRequest->setRedirectUrl(htmlspecialchars('http://wooplugins.com.br/'));
			}


      		$item_loop = 0;
      		if (sizeof($order->get_items())>0){
        		foreach ($order->get_items() as $item){
          			if ($item['qty']){
            			$item_loop++;
        				$product = $order->get_product_from_item($item);
            			$item_name   = $item['name'];
	            		$item_meta = new order_item_meta( $item['item_meta'] );

            			if ($meta = $item_meta->display( true, true )) :
              				$item_name .= ' ('.$meta.')';
              			endif;

              			$shipping = 0;
              			if ($item_loop == 1)
              				$shipping = $order->get_shipping();

              			$paymentRequest->addItem($product->get_sku(), utf8_decode($item_name), $item['qty'], number_format($order->get_item_total( $item, false ), 2, ".", ""), 0, $shipping);
          			}
        		}
      		}
			
			//pagseguro api demands a shipping type, even if we are not using their shipping method
			$CODIGO_PAC = PagSeguroShippingType::getCodeByType('PAC'); // 1
			$paymentRequest->setShippingType($CODIGO_PAC);

			//Sender information
			$nome_completo = utf8_decode($order->billing_first_name . " " . $order->billing_last_name);
			if($this->usewcfields == 'yes'){
				//telefone sempre estará no formato (99)9999-9999 ou (99)99999-9999
				$telefone_ddd = substr($order->billing_phone,1,2); //retrieve ddd from parenthesis
				$telefone = str_replace('-', '', substr($order->billing_phone,4)); //retrieve the rest of the telephone without the '-'
			} else {
				//since we have no idea what's coming, strip everything and try to fit into the phone fields
				$order->billing_phone = str_replace( array( '(', '-', ' ', ')' ), '', $order->billing_phone );
				$telefone_ddd = substr( $order->billing_phone, 0, 2 );
				$telefone = substr( $order->billing_phone, 2 );			
			}
			echo $telefone;
			$paymentRequest->setSender($nome_completo, $order->billing_email, $telefone_ddd, $telefone);
			
			
			if($this->usewcfields == 'yes'){
			
				//endereco sempre será validado como <logradouro>, <numero>, <bairro>
				$endereco = explode(', ',$order->billing_address_1);
				$paymentRequest->setShippingAddress(
					$order->billing_postcode,
					$order->billing_address_1,                              //logradouro
					get_post_meta($order->id, '_billing_number', true),     //numero da casa
					$order->billing_address_2,                              //complemento
					get_post_meta($order->id, '_billing_district', true),   //bairro
					$order->billing_city,
					$order->billing_state,
					$order->billing_country
				);
			} else {

				// Fixed Address.
				if ( $this->valid_address == 'yes' ) {
					$order->billing_address_1 = explode( ',', $order->billing_address_1 );
					
					$paymentRequest->setShippingAddress(
						$order->billing_postcode,
						$order->billing_address_1[0],                           //logradouro
						(int) $order->billing_address_1[1],                     //numero da casa
						$order->billing_address_2,                              //complemento
						'',                                                     //bairro
						$order->billing_city,
						$order->billing_state,
						$order->billing_country
					);
					$address = array(
						'shippingAddressStreet'     => $order->billing_address_1[0],
						'shippingAddressNumber'     => (int) $order->billing_address_1[1],
					);
				} else {
					$paymentRequest->setShippingAddress(
						$order->billing_postcode,
						$order->billing_address_1,                              //logradouro
						0,                                                      //numero da casa
						$order->billing_address_2,                              //complemento
						'',                                                     //bairro
						$order->billing_city,
						$order->billing_state,
						$order->billing_country
					);
				}


			}

      		$paymentRequest->setExtraAmount(number_format($order->get_total_discount(),2,".","")*-1);
      		$credentials = new PagSeguroAccountCredentials($this->email, $this->tokenid);
      		$pagseguro_url = $paymentRequest->register($credentials);
      		if ($this->debug=='yes') $this->log->add( 'pagseguro', "pagseguro_url ". $pagseguro_url);


      		$woocommerce->add_inline_js('
        		jQuery("body").block({
            		message: "<img src=\"'.esc_url( $woocommerce->plugin_url() ).'/assets/images/ajax-loader.gif\" alt=\"Redirecting...\" style=\"float:left; margin-right: 10px;\" />'.__('Obrigado pela compra. Estamos transferindo para o PagSeguro para realizar o pagamento.', 'woothemes').'",
	            		overlayCSS: {
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
	              			lineHeight:    "32px"
	            		}
	          		});
				jQuery("#submit_pagseguro_payment_form").click();
			');



      		$payment_form = '<form action="'.esc_url( $pagseguro_url ).'" method="post" id="paypal_payment_form">
              					<input type="submit" class="button" id="submit_pagseguro_payment_form" value="'.__('Pague com PagSeguro', 'woothemes').'" />
  								<a class="button cancel" href="'.esc_url( $order->get_cancel_order_url() ).'">'.__('Cancelar pedido', 'woothemes').'</a>
              				</form>';

      		if ($this->debug=='yes') $this->log->add( 'pagseguro', "Pedido gerado com sucesso. Abaixo código HTML do formulário:");
      		if ($this->debug=='yes') $this->log->add( 'pagseguro', $payment_form);

      		return $payment_form;
    	} //Fim da função generate_pagseguro_form



    	//---------------------------------------------------------------------------------------------------
  		//Função: process_payment
  		//Descrição: processa o pagamento e retorna o resultado
  		//---------------------------------------------------------------------------------------------------
    	function process_payment( $order_id ) {

      		$order = &new woocommerce_order( $order_id );

      		return array(
        		'result'    => 'success',
        		'redirect'  => add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(get_option('woocommerce_pay_page_id'))))
      		);
    	} //Fim da função process_payment



    	//---------------------------------------------------------------------------------------------------
  		//Função: receipt_page
  		//Descrição: Página final antes de redirecionar para a página de pagamento do PagSeguro
  		//---------------------------------------------------------------------------------------------------
    	function receipt_page( $order ) {
            global $woocommerce;
    		echo '<p>'.__('Obrigado pelo seu pedido. Por favor clique no botão "Pagar com PagSeguro" para finalizar o pagamento.', 'woothemes').'</p>';
    		echo $this->generate_pagseguro_form( $order );
            // Remove cart.
            $woocommerce->cart->empty_cart();
    	}



    	//---------------------------------------------------------------------------------------------------
  		//Função: check_ipn_response
  		//Descrição: Verifica se o retorno do pagseguro é válido, se for, atualiza o pedido com o novo status
  		//---------------------------------------------------------------------------------------------------
    	function check_ipn_response() {
      		global $woocommerce;

      		$code = (isset($_POST['notificationCode']) && trim($_POST['notificationCode']) !== ""  ? trim($_POST['notificationCode']) : null);
      		$type = (isset($_POST['notificationType']) && trim($_POST['notificationType']) !== ""  ? trim($_POST['notificationType']) : null);

			//if ($this->debug=='yes') $this->log->add( 'pagseguro', "Verificando tipo de retorno do PagSeguro...");

      		if ( $code && $type ) {

				if ($this->debug=='yes') $this->log->add( 'pagseguro', "Retorno possui POST. Validando...");

      			$notificationType = new PagSeguroNotificationType($type);
      			$strType = $notificationType->getTypeFromValue();

      			switch(strtoupper($strType)) {

      				case 'TRANSACTION':
						if ($this->debug=='yes') $this->log->add( 'pagseguro', "POST to tipo TRANSACTION detectado. Processando...");
          				$credentials = new PagSeguroAccountCredentials($this->email, $this->tokenid);

				    	try {
				    		$transaction = PagSeguroNotificationService::checkTransaction($credentials, $code);
				    	} catch (PagSeguroServiceException $e) {
				    		if ($this->debug=='yes') $this->log->add( 'pagseguro', "Erro: ". $e->getMessage());
							//die($e->getMessage());
				    	}

				    	do_action("valid-pagseguro-standard-ipn-request", $transaction);

				    	break;

      				default:
      					//LogPagSeguro::error("Unknown notification type [".$notificationType->getValue()."]");
						if ($this->debug=='yes') $this->log->add( 'pagseguro', "Unknown notification type [".$notificationType->getValue()."]");

      			}

      			//self::printLog($strType);

      		} else {

				//if ($this->debug=='yes') $this->log->add( 'pagseguro', "Retorno não possui POST, é somente o retorno da página de pagamento.");
      			//LogPagSeguro::error("Invalid notification parameters.");
      			//self::printLog();

      		}
    	} //Fim da função check_ipn_response



    	//---------------------------------------------------------------------------------------------------
  		//Função: successful_request
  		//Descrição: Atualiza o pedido com a notificação enviada pelo pagseguro. Se a notificação for de
  		//           transação concluída, finaliza o pedido (status = completo para downloads e processing
  		//           para produtos físicos (o produto já pode ser enviado pela transportadora)
  		//---------------------------------------------------------------------------------------------------
    	function successful_request( $transaction ) {

    		$reference = $transaction->getReference();
    		$transactionID = $transaction->getCode();
    		$status = $transaction->getStatus();
    		$sender = $transaction->getSender();
    		$paymentMethod = $transaction->getPaymentMethod();
    		$code = $paymentMethod->getCode();


      		if ($this->debug=='yes') $this->log->add( 'pagseguro', 'Pedido = '.$reference.' / Status = '.$status->getTypeFromValue());

      		if (!empty($reference) && !empty($transactionID)) {

        		$order = new woocommerce_order( (int) $reference );

        		//Check order not already completed
        		if ($order->status == 'completed') {
          			if ($this->debug=='yes') $this->log->add( 'pagseguro', 'Pedido '.$reference.' já se encontra completado no sistema!');
          			exit;
        		}


        		// We are here so lets check status and do actions
        		switch ($status->getValue()){

					case 1: //WAITING_PAYMENT
          				$order->add_order_note( __('O comprador iniciou a transação, mas até o momento o PagSeguro não recebeu nenhuma informação sobre o pagamento.', 'woothemes') );
          				if ($this->debug=='yes') $this->log->add( 'pagseguro', 'Pedido '.$order->id.': O comprador iniciou a transação, mas até o momento o PagSeguro não recebeu nenhuma informação sobre o pagamento.');
          				break;


          			case 2: //IN_ANALYSIS
          				$order->update_status('on-hold', __('O comprador optou por pagar com um cartão de crédito e o PagSeguro está analisando o risco da transação.', 'woothemes') );
          				if ($this->debug=='yes') $this->log->add( 'pagseguro', 'Pedido '.$order->id.': O comprador optou por pagar com um cartão de crédito e o PagSeguro está analisando o risco da transação.');
          				break;


          			case 3: //PAID
          				$order->add_order_note( __('A transação foi paga pelo comprador e o PagSeguro já recebeu uma confirmação da instituição financeira responsável pelo processamento.', 'woothemes') );
          				$order->payment_complete();

          				update_post_meta($order->id, 'Nome do cliente' , $sender->getName());
          				update_post_meta($order->id, 'E-Mail PagSeguro', $sender->getEmail());
          				update_post_meta($order->id, 'Código Transação', $transacao->getCode());
          				update_post_meta($order->id, 'Método Pagamento', $code->getTypeFromValue());
          				update_post_meta($order->id, 'Data Transação'  , $transacao->getLastEventDate());
          				if ($this->debug=='yes') $this->log->add( 'pagseguro', 'Pedido '.$order->id.': A transação foi paga pelo comprador e o PagSeguro já recebeu uma confirmação da instituição financeira responsável pelo processamento.');

          				break;


          			case 4: //AVAILABLE
            			$order->add_order_note( __('A transação foi paga e chegou ao final de seu prazo de liberação sem ter sido retornada e sem que haja nenhuma disputa aberta.', 'woothemes') );
          				if ($this->debug=='yes') $this->log->add( 'pagseguro', 'Pedido '.$order->id.': A transação foi paga e chegou ao final de seu prazo de liberação sem ter sido retornada e sem que haja nenhuma disputa aberta.');
          				break;


          			case 5: //IN_DISPUTE
            			$order->add_order_note( __('O comprador, dentro do prazo de liberação da transação, abriu uma disputa.', 'woothemes') );
          				if ($this->debug=='yes') $this->log->add( 'pagseguro', 'Pedido '.$order->id.': O comprador, dentro do prazo de liberação da transação, abriu uma disputa.');
          				break;


          			case 6: //REFUNDED
          				$order->update_status('failed', __('O valor da transação foi devolvido para o comprador.', 'woothemes') );
          				if ($this->debug=='yes') $this->log->add( 'pagseguro', 'Pedido '.$order->id.': O valor da transação foi devolvido para o comprador.');
          				break;


          			case 7: //CANCELLED
          				$order->update_status('cancelled', __('A transação foi cancelada sem ter sido finalizada.', 'woothemes') );
          				if ($this->debug=='yes') $this->log->add( 'pagseguro', 'Pedido '.$order->id.': A transação foi cancelada sem ter sido finalizada.');
          				break;


          			default:
          				break;

        		}
      		}
    	} //Fim da função successful_request
        
		
		
		/**
         * Adds error message when not configured the email.
         *
         * @return string Error Mensage.
         */
        public function mail_missing_message() {
            $message = '<div class="error">';
                $message .= '<p>' . sprintf( __( '<strong>Gateway Disabled</strong> You should inform your email address in PagSeguro. %sClick here to configure!%s' , 'wcpagseguro' ), '<a href="' . get_admin_url() . 'admin.php?page=woocommerce_settings&amp;tab=payment_gateways">', '</a>' ) . '</p>';
            $message .= '</div>';

            echo $message;
        }

		
		
        /**
         * Adds error message when not configured the token.
         *
         * @return string Error Mensage.
         */
        public function token_missing_message() {
            $message = '<div class="error">';
                $message .= '<p>' .sprintf( __( '<strong>Gateway Disabled</strong> You should inform your token in PagSeguro. %sClick here to configure!%s' , 'wcpagseguro' ), '<a href="' . get_admin_url() . 'admin.php?page=woocommerce_settings&amp;tab=payment_gateways">', '</a>' ) . '</p>';
            $message .= '</div>';

            echo $message;
        }

		
		
        /**
         * Hides the PagSeguro with payment method with the customer lives outside Brazil
         *
         * @param  array $available_gateways Default Available Gateways.
         *
         * @return array                    New Available Gateways.
         */
        function hides_when_is_outside_brazil( $available_gateways ) {

            if ( isset( $_REQUEST['country'] ) && $_REQUEST['country'] != 'BR' ) {

                // Remove standard shipping option.
                unset( $available_gateways['pagseguro'] );
            }

            return $available_gateways;
        }

		
		
        /**
         * Valid address for street and number.
         *
         * @return void
         */
        function valid_address() {
            global $woocommerce;

            // Valid address format.
            if ( $_POST['billing_address_1'] ) {

                $address = $_POST['billing_address_1'];
                $address = str_replace( ' ', '', $address );
                $pattern = '/([^\,\d]*),([0-9]*)/';
                $results = preg_match_all($pattern, $address, $out);

                if ( empty( $out[2] ) || !is_numeric( $out[2][0] ) ) {
                    $woocommerce->add_error( __( '<strong>Address</strong> format is invalid. Example of correct format: "Av. Paulista, 460"', 'wcpagseguro' ) );
                }

            }
        }
	} //Fim da classe woocommerce_pagseguro



  	//Add the gateway to WooCommerce
  	function add_pagseguro_gateway( $methods ) {
  		$methods[] = 'woocommerce_pagseguro';
  		return $methods;
  	}



  	add_filter('woocommerce_payment_gateways', 'add_pagseguro_gateway' );
}
?>