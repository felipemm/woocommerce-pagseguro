<?php
/*
Plugin Name: WooCommerce PagSeguro
Plugin URI: http://felipematos.com/loja
Description: Adiciona o gateway de pagamento do PagSeguro no WooCommerce
Version: 1.3
Author: Felipe Matos <chucky_ath@yahoo.com.br>
Author URI: http://felipematos.com
License: Commercial
Requires at least: 3.3
Tested up to: 3.4.1
*/

//hook to include the payment gateway function
add_action('plugins_loaded', 'gateway_pagseguro', 0);


//hook function
function gateway_pagseguro(){


	require_once "PagSeguroLibrary/PagSeguroLibrary.php";

	//---------------------------------------------------------------------------------------------------
	//Classe: woocommerce_pagseguro
  	//Descrição: classe de implementação do gateway PagSeguro
  	//---------------------------------------------------------------------------------------------------
  	class woocommerce_pagseguro extends woocommerce_payment_gateway {

  		//---------------------------------------------------------------------------------------------------
  		//Função: __construct
  		//Descrição: cria e inicializa o objeto da classe
  		//---------------------------------------------------------------------------------------------------
  		public function __construct() {
      		global $woocommerce;

      		$this->id         = 'pagseguro';
      		//$this->icon       = apply_filters('woocommerce_pagseguro_icon', $url = plugins_url('woocommerce-pagseguro/pagseguro.png'));
			$this->icon       = apply_filters('woocommerce_pagseguro_icon', $url = plugin_dir_url(__FILE__).'pagseguro.png');
      		$this->has_fields = false;

      		// Load the form fields.
      		$this->init_form_fields();

      		// Load the settings.
      		$this->init_settings();

      		// Define user set variables
      		$this->title       = $this->settings['title'];
      		$this->description = $this->settings['description'];
      		$this->email       = $this->settings['email'];
      		$this->tokenid     = $this->settings['tokenid'];
      		$this->debug       = $this->settings['debug'];

      		// Logs
      		if ($this->debug=='yes') $this->log = $woocommerce->logger();

      		// Actions
      		add_action('init', array(&$this, 'check_ipn_response') );
      		add_action('valid-pagseguro-standard-ipn-request', array(&$this, 'successful_request') );
      		add_action('woocommerce_receipt_pagseguro', array(&$this, 'receipt_page'));
      		add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));

      		if ( !$this->is_valid_for_use() ) $this->enabled = false;
  		} //Fim da função __construct



  		//---------------------------------------------------------------------------------------------------
  		//Função: is_valid_for_use
  		//Descrição: checa se o gateway está habilitado a disponível para o país do usuário
  		//---------------------------------------------------------------------------------------------------
  		function is_valid_for_use() {
      		if (!in_array(get_option('woocommerce_currency'), array('AUD', 'BRL', 'CAD', 'MXN', 'NZD', 'HKD', 'SGD', 'USD', 'EUR', 'JPY', 'TRY', 'NOK', 'CZK', 'DKK', 'HUF', 'ILS', 'MYR', 'PHP', 'PLN', 'SEK', 'CHF', 'TWD', 'THB', 'GBP')))
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
	        		// Generate the HTML For the settings form.
	        		$this->generate_settings_html();
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

      		//Cria um objeto de requisição de pagamento e popula os dados
      		$paymentRequest = new PagSeguroPaymentRequest();
      		$paymentRequest->setCurrency("BRL");
      		$paymentRequest->setReference($order->id);
      		$paymentRequest->setRedirectUrl($this->get_return_url($order));

			//telefone sempre estará no formato (99)9999-9999 ou (99)99999-9999
			$telefone_ddd = substr($order->billing_phone,1,2); //retrieve ddd from parenthesis
			$telefone = str_replace('-', '', substr($order->billing_phone,4)); //retrieve the rest of the telephone without the '-'
			$nome_completo = utf8_decode($order->billing_first_name . " " . $order->billing_last_name);

			$paymentRequest->setSender($nome_completo, $order->billing_email, $telefone_ddd, $telefone);

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

			$CODIGO_PAC = PagSeguroShippingType::getCodeByType('PAC'); // 1
			$paymentRequest->setShippingType($CODIGO_PAC);

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
    		echo '<p>'.__('Obrigado pelo seu pedido. Por favor clique no botão "Pagar com PagSeguro" para finalizar o pagamento.', 'woothemes').'</p>';
    		echo $this->generate_pagseguro_form( $order );
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
  	} //Fim da classe woocommerce_pagseguro



  	//Add the gateway to WooCommerce
  	function add_pagseguro_gateway( $methods ) {
  		$methods[] = 'woocommerce_pagseguro';
  		return $methods;
  	}



  	add_filter('woocommerce_payment_gateways', 'add_pagseguro_gateway' );
}
?>