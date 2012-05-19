<?php
/*
Plugin Name: WooCommerce PagSeguro
Plugin URI: http://felipematos.com/loja
Description: Adiciona o gateway de pagamento do PagSeguro no WooCommerce
Version: 0.1
Author: Felipe Matos <chucky_ath@yahoo.com.br>
Author URI: http://felipematos.com
License: GPLv2
Requires at least: 3.0
Tested up to: 3.3.1
*/

//hook to include the payment gateway function
add_action('plugins_loaded', 'gateway_pagseguro', 0);

//hook function
function gateway_pagseguro(){
  
  //classe de verificação do retorno de pagamento
  class PagSeguroNpi {
    
    private $timeout = 20; // Timeout em segundos
    private $tokenid = ''; //Token do pagseguro
    private $npi_url = ''; //Url do NPI do PagSeguro
    
    public function setTokenID($token){
      $this->tokenid = $token;
    }
    
    public function setNpiUrl($url){
      $this->npi_url = $url;
    }

    public function notificationPost() {
      $postdata = 'Comando=validar&Token='.$this->tokenid;
      foreach ($_POST as $key => $value) {
        $valued    = $this->clearStr($value);
        $postdata .= "&$key=$valued";
      }
      return $this->verify($postdata);
    }
    
    private function clearStr($str) {
      if (!get_magic_quotes_gpc()) {
        $str = addslashes($str);
      }
      return $str;
    }
    
    private function verify($data) {
      $curl = curl_init();
      curl_setopt($curl, CURLOPT_URL, $this->npi_url);
      curl_setopt($curl, CURLOPT_POST, true);
      curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
      curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($curl, CURLOPT_HEADER, false);
      curl_setopt($curl, CURLOPT_TIMEOUT, $this->timeout);
      curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
      $result = trim(curl_exec($curl));
      curl_close($curl);
      return $result;
    }

  }


  //PagSeguro payment gateway class
  class woocommerce_pagseguro extends woocommerce_payment_gateway {

    public function __construct() { 
      global $woocommerce;
      
      $this->id      = 'pagseguro';
      $this->icon     = apply_filters('woocommerce_pagseguro_icon', $url = plugins_url('woocommerce-pagseguro-0.1/pagseguro.png'));
      $this->has_fields   = false;
      $this->devurlchk  = 'http://localhost:9090/checkout/checkout.jhtml';
      $this->devurlnpi  = 'http://localhost:9090/pagseguro-ws/checkout/NPI.jhtml';
      $this->prdurlnpi  = 'https://pagseguro.uol.com.br/pagseguro-ws/checkout/NPI.jhtml';
      $this->prdurlchk  = 'https://pagseguro.uol.com.br/security/webpagamentos/webpagto.aspx';
      
      // Load the form fields.
      $this->init_form_fields();
      
      // Load the settings.
      $this->init_settings();
      
      // Define user set variables
      $this->title = $this->settings['title'];
      $this->description = $this->settings['description'];
      $this->email = $this->settings['email'];
      $this->tokenid = $this->settings['tokenid'];
      $this->debug = $this->settings['debug'];  
      $this->testmode  = $this->settings['testmode'];    
      
      // Logs
      if ($this->debug=='yes') $this->log = $woocommerce->logger();
      
      // Actions
      add_action( 'init', array(&$this, 'check_ipn_response') );
      add_action('valid-pagseguro-standard-ipn-request', array(&$this, 'successful_request') );
      //add_action('woocommerce_thankyou_pagseguro', array(&$this, 'thankyou_page'));
      add_action('woocommerce_receipt_pagseguro', array(&$this, 'receipt_page'));
      add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
      
      if ( !$this->is_valid_for_use() ) $this->enabled = false;
    } 
    
     //Check if this gateway is enabled and available in the user's country
    function is_valid_for_use() {
      if (!in_array(get_option('woocommerce_currency'), array('AUD', 'BRL', 'CAD', 'MXN', 'NZD', 'HKD', 'SGD', 'USD', 'EUR', 'JPY', 'TRY', 'NOK', 'CZK', 'DKK', 'HUF', 'ILS', 'MYR', 'PHP', 'PLN', 'SEK', 'CHF', 'TWD', 'THB', 'GBP'))) return false;
      return true;
    }

    //Initialise Gateway Settings Form Fields
    function init_form_fields() {
      $this->form_fields = array(
        'enabled' => array(
          'title' => __( 'Enable/Disable', 'woothemes' ),
          'type' => 'checkbox',
          'label' => __( 'Enable Cheque Payment', 'woothemes' ),
          'default' => 'yes'
        ),
        'title' => array(
          'title' => __( 'Title', 'woothemes' ),
          'type' => 'text',
          'description' => __( 'This controls the title which the user sees during checkout.', 'woothemes' ),
          'default' => __( 'Pague com PagSeguro', 'woothemes' )
        ),
        'description' => array(
          'title' => __( 'Customer Message', 'woothemes' ),
          'type' => 'textarea',
          'description' => __( 'Let the customer know the payee and where they should be sending the cheque to and that their order won\'t be shipping until you receive it.', 'woothemes' ),
          'default' => 'O PagSeguro é o meio de pagamento mais completo e eficiente na proteção contra fraudes em compras online.'
        ),
        'email' => array(
          'title' => __( 'E-Mail', 'woothemes' ),
          'type' => 'text',
          'description' => __( 'E-mail registered into PagSeguro to receive the payments', 'woothemes' )
        ),
        'tokenid' => array(
          'title' => __( 'Token', 'woothemes' ),
          'type' => 'text',
          'description' => __( 'Token gerado pelo PagSeguro para pagamento via API', 'woothemes' )
        ),
        'testmode' => array(
          'title' => __( 'PagSeguro sandbox', 'woothemes' ), 
          'type' => 'checkbox', 
          'label' => __( 'Enable PagSeguro sandbox', 'woothemes' ), 
          'default' => 'yes'
        ),
        'debug' => array(
          'title' => __( 'Debug', 'woothemes' ), 
          'type' => 'checkbox', 
          'label' => __( 'Enable logging (<code>woocommerce/logs/pagseguro.txt</code>)', 'woothemes' ), 
          'default' => 'yes'
        )
      );
    } // End init_form_fields()
    
    //Admin Panel Options
    //Options for bits like 'title' and availability on a country-by-country basis
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
    } // End admin_options()
    
    // There are no payment fields for paypal, but we want to show the description if set.
    function payment_fields() {
      if ($this->description) echo wpautop(wptexturize($this->description));
    }
    
    //generate the form to send to pagseguro
    function generate_pagseguro_form($order_id){
      global $woocommerce;
      $order = &new woocommerce_order( $order_id );
      
      if ( $this->testmode == 'yes' ):
        $pagseguro_url = $this->devurlchk;
      else :
        $pagseguro_url = $this->prdurlchk;
      endif;
      
      //create array used to store order data for the payment request
      $pagseguro_args = array();
      $pagseguro_args['email_cobranca']  = $this->email;
      $pagseguro_args['tipo']  = 'CP';
      $pagseguro_args['moeda']  = "BRL";
      $pagseguro_args['ref_transacao']  = $order_id;
      $pagseguro_args['cliente_nome']  = $order->billing_first_name . " " . $order->billing_last_name;
      $pagseguro_args['cliente_ddd']  = substr($order->billing_phone,0,2);
      $pagseguro_args['cliente_tel']  = substr($order->billing_phone,2,10);
      $pagseguro_args['cliente_email']  = $order->billing_email;
      
      // Add the items for this payment request
      //if (sizeof($order->items)>0){
      //  foreach ($order->items as $item){
      //    if ($item['qty']){
      //      $item_loop++;
      //      
      //      $pagseguro_args['item_id_'.$item_loop]  = $item['id'];
      //      $pagseguro_args['item_descr_'.$item_loop]  = $item['name'];
      //      $pagseguro_args['item_valor_'.$item_loop]  = $item['cost'];
      //      $pagseguro_args['item_quant_'.$item_loop]  = $item['qty'];
      //    }
      //  }
      //}
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
            
            $pagseguro_args['item_id_'.$item_loop]  = $product->get_sku();
            $pagseguro_args['item_descr_'.$item_loop]  = $item_name;
            $pagseguro_args['item_valor_'.$item_loop]  = $order->get_item_total( $item, false );
            $pagseguro_args['item_quant_'.$item_loop]  = $item['qty'];
          }
        }
      }
      $pagseguro_args['item_frete_1'] = $order->get_total_tax();
      $pagseguro_args_array = array();

      foreach ($pagseguro_args as $key => $value) {
        $pagseguro_args_array[] = '<input type="hidden" name="'.esc_attr( $key ).'" value="'.esc_attr( $value ).'" />';
      }
      
      
      $woocommerce->add_inline_js('
        jQuery("body").block({ 
            message: "<img src=\"'.esc_url( $woocommerce->plugin_url() ).'/assets/images/ajax-loader.gif\" alt=\"Redirecting...\" style=\"float:left; margin-right: 10px;\" />'.__('Obrigado pela compra. Estamos transferindo para o PagSeguro para realizar o pagamento.', 'woothemes').'", 
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
              lineHeight:    "32px"
            } 
          });
        jQuery("#submit_pagseguro_payment_form").click();
      ');
      
      
      
      $payment_form = '<form action="'.esc_url( $pagseguro_url ).'" method="post" id="paypal_payment_form">
              ' . implode('', $pagseguro_args_array) . '
              <input type="submit" class="button" id="submit_pagseguro_payment_form" value="'.__('Pague com PagSeguro', 'woothemes').'" /> <a class="button cancel" href="'.esc_url( $order->get_cancel_order_url() ).'">'.__('Cancelar pedido', 'woothemes').'</a>
              </form>';
          //    ' . $this->get_return_url( $order ) . '

      if ($this->debug=='yes') $this->log->add( 'pagseguro', "Pedido gerado com sucesso. Abaixo código HTML do formulário:");
      if ($this->debug=='yes') $this->log->add( 'pagseguro', $payment_form);
      
      return $payment_form;
    }
    
    // Process the payment and return the result
    function process_payment( $order_id ) {
      
      $order = &new woocommerce_order( $order_id );
      
      return array(
        'result'   => 'success',
        'redirect'  => add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(get_option('woocommerce_pay_page_id'))))
      );
      
    }    
    
    // receipt_page
    function receipt_page( $order ) {
      
      echo '<p>'.__('Thank you for your order, please click the button below to pay with PagSeguro.', 'woothemes').'</p>';
      
      echo $this->generate_pagseguro_form( $order );
    }
    
    //thank you page (where user will be redirected from pagseguro
    //function thankyou_page($order_id) {
    //  $order = &new woocommerce_order( $order_id );
    //  if ($this->description) echo wpautop(wptexturize($this->get_return_url( $order )));
    //  
    //}

    // Check PagSeguro IPN validity
    function check_ipn_request_is_valid() {
      global $woocommerce;
      
      if ( $this->testmode == 'yes' ):
        $pagseguro_url = $this->devurlnpi;
      else :
        $pagseguro_url = $this->prdurlnpi;
      endif;
      if ($this->debug=='yes') $this->log->add( 'pagseguro', 'NPI URL: '. $pagseguro_url);

      if ($this->debug=='yes') $this->log->add( 'pagseguro', 'Verificando se a resposta do NPI é válida' );

      if (count($_POST) > 0) {
        
        // POST recebido, indica que é a requisição do NPI.
        if ($this->debug=='yes') $this->log->add( 'pagseguro', 'POST recebido, indica que é a requisição do NPI.');
        
        $npi = new PagSeguroNpi();
        $npi->setTokenID($this->tokenid);
        $npi->setNpiUrl($pagseguro_url);
        $result = $npi->notificationPost();
        
        $transacaoID = isset($_POST['TransacaoID']) ? $_POST['TransacaoID'] : '';

        if ($this->debug=='yes') $this->log->add( 'pagseguro', 'Número da Transação = '. $transacaoID);
        
        if ($result == "VERIFICADO") {
          if ($this->debug=='yes') $this->log->add( 'pagseguro', 'POST Validado pelo PagSeguro');
          return true;
          //O post foi validado pelo PagSeguro.
        } else if ($result == "FALSO") {
          //O post não foi validado pelo PagSeguro.
        } else {
          //Erro na integração com o PagSeguro.
        }
        
      } else {
        // POST não recebido, indica que a requisição é o retorno do Checkout PagSeguro.
        // No término do checkout o usuário é redirecionado para este bloco.
      }

    }
    
    // Check for PayPal IPN Response
    function check_ipn_response() {
        
      if ( !empty($_POST['Referencia']) && !empty($_POST['TransacaoID']) ) {
      
        $_POST = stripslashes_deep($_POST);
        
        if ($this->check_ipn_request_is_valid()){
        
          if ($this->debug=='yes') $this->log->add( 'pagseguro', 'Analisando Reposta');
          do_action("valid-pagseguro-standard-ipn-request", $_POST);

        }
        
      }
        
    }
        
    // Successful Payment!
    function successful_request( $posted ) {
      
      if ($this->debug=='yes') $this->log->add( 'pagseguro', 'Pedido = '.$posted['Referencia'].' / Status = '.$posted['StatusTransacao']);
      // Custom holds post ID
      if ( !empty($posted['Referencia']) && !empty($posted['TransacaoID']) ) {
        
        $order = new woocommerce_order( (int) $posted['Referencia'] );

        // Check order not already completed
        if ($order->status == 'completed') {
          if ($this->debug=='yes') $this->log->add( 'pagseguro', 'Pedido '.$posted['Referencia'].' já se encontra completado no sistema!');
          exit;
        }

        // We are here so lets check status and do actions
        switch (strtolower($posted['StatusTransacao'])){
          case 'completo':
            // Check valid txn_type
            //$accepted_types = array('cart', 'instant', 'express_checkout', 'web_accept', 'masspay', 'send_money');
            //if (!in_array(strtolower($posted['txn_type']), $accepted_types)) exit;
            
            // Payment completed
            $order->add_order_note( __('Pagamento via PagSeguro Completado', 'woothemes') );
            $order->payment_complete();
            
            // Store PP Details
            update_post_meta( (int) $posted['Referencia'], 'Nome', $posted['CliNome']);
            update_post_meta( (int) $posted['Referencia'], 'E-Mail PagSeguro', $posted['CliEmail']);
            update_post_meta( (int) $posted['Referencia'], 'Código Transação', $posted['TransacaoID']);
            update_post_meta( (int) $posted['Referencia'], 'Método Pagamento', $posted['TipoPagamento']);
            update_post_meta( (int) $posted['Referencia'], 'Data Transação', $posted['DataTransacao']); 
            if ($this->debug=='yes') $this->log->add( 'pagseguro', 'Pagamento confirmado e pedido atualizado');
            
            break;
          case 'aguardando pagto':
            $order->update_status('on-hold','Aguardando confirmação de pagamento do PagSeguro');
            if ($this->debug=='yes') $this->log->add( 'pagseguro', 'Aguardando confirmação de pagamento do PagSeguro');
            break;
          case 'aprovado':
            $order->update_status('processing','Pagamento aprovado pelo PagSeguro. Aguardando compensação.');
            if ($this->debug=='yes') $this->log->add( 'pagseguro', 'Pagamento aprovado pelo PagSeguro. Aguardando compensação.');
            break;
          case utf8_decode('em análise'):
            $order->update_status('processing','Pagamento aprovado pelo PagSeguro. Aguardando análise.');
            if ($this->debug=='yes') $this->log->add( 'pagseguro', 'Pagamento aprovado pelo PagSeguro. Aguardando análise.');
            break;
          case 'cancelado':
            $order->update_status('failed', sprintf(__('Pagamento %s foi recusado pelo Pagseguro.', 'woothemes'), strtolower($posted['StatusTransacao']) ) );
            if ($this->debug=='yes') $this->log->add( 'pagseguro', 'Pagamento recusado pelo PagSeguro');
            break;
          default:
            // No action
            break;
        }
      }
    }
  }

  //Add the gateway to WooCommerce
  function add_pagseguro_gateway( $methods ) {
    $methods[] = 'woocommerce_pagseguro'; return $methods;
  }

  add_filter('woocommerce_payment_gateways', 'add_pagseguro_gateway' );
}
