<?php

include_once('settings/class-wc-checkoutcom-cards-settings.php');

class WC_Gateway_Checkout_Com_Apple_Pay extends WC_Payment_Gateway
{
    /**
     * WC_Gateway_Checkout_Com_Apple_Pay constructor.
     */
    public function __construct()
    {
        $this->id                   = 'wc_checkout_com_apple_pay';
        $this->method_title         = __("Checkout.com", 'wc_checkout_com');
        $this->method_description   = __("The Checkout.com extension allows shop owners to process online payments through the <a href=\"https://www.checkout.com\">Checkout.com Payment Gateway.</a>", 'wc_checkout_com');
        $this->title                = __("Apple Pay", 'wc_checkout_com');
        $this->has_fields = true;
        $this->supports = array( 'products' );

        $this->init_form_fields();
        $this->init_settings();

        // Turn these settings into variables we can use
        foreach ( $this->settings as $setting_key => $value ) {
            $this->$setting_key = $value;
        }

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

        // Redirection hook
        add_action( 'woocommerce_api_wc_checkoutcom_session', array( $this, 'applepay_sesion' ) );


        add_action( 'woocommerce_api_wc_checkoutcom_generate_token', array( $this, 'applepay_token' ) );

    }

    /**
     * Show module configuration in backend
     *
     * @return string|void
     */
    public function init_form_fields()
    {
        $this->form_fields = WC_Checkoutcom_Cards_Settings::apple_settings();
        $this->form_fields = array_merge( $this->form_fields, array(
            'screen_button' => array(
                'id'    => 'screen_button',
                'type'  => 'screen_button',
                'title' => __( 'Other Settings', 'wc_checkout_com' ),
            )
        ));
    }

    /**
     * @param $key
     * @param $value
     */
    public function generate_screen_button_html( $key, $value )
    {
        WC_Checkoutcom_Admin::generate_links($key, $value);
    }

    /**
     * Show frames js on checkout page
     */
    public function payment_fields()
    {
        global $woocommerce;

        $chosen_methods = wc_get_chosen_shipping_method_ids();
        $chosen_shipping = $chosen_methods[0];
        $shipping_amount = WC()->cart->get_shipping_total();
        $checkoutFields = json_encode($woocommerce->checkout->checkout_fields,JSON_HEX_APOS);
        $session_url = str_replace( 'https:', 'https:', add_query_arg( 'wc-api', 'wc_checkoutcom_session', home_url( '/' ) ) );
        $generate_token_url = str_replace( 'https:', 'https:', add_query_arg( 'wc-api', 'wc_checkoutcom_generate_token', home_url( '/' ) ) );
        $apple_settings = get_option('woocommerce_wc_checkout_com_apple_pay_settings');
        $mada_enabled = $apple_settings['enable_mada_apple_pay'] == 'yes' ? true : false;

        if(!empty($this->get_option( 'description' ))){
            echo  $this->get_option( 'description' );
        }

        // get country of current user
        $country_code = WC()->customer->get_billing_country();
        $supportedNetworks = ['amex', 'masterCard', 'visa'];

        if ($mada_enabled) {
            array_push($supportedNetworks, 'mada');
            $country_code = 'SA';
        }

        ?>
        <!-- Input needed to sent the card token -->
        <input type="hidden" id="cko-apple-card-token" name="cko-apple-card-token" value="" />

        <!-- ApplePay warnings -->
        <p style="display:none" id="ckocom_applePay_not_actived">ApplePay is possible on this browser, but not currently activated.</p>
        <p style="display:none" id="ckocom_applePay_not_possible">ApplePay is not available on this browser</p>

        <script type="text/javascript">
            // Magic strings used in file
            var applePayOptionSelector = '.payment_method_wc_checkout_com_apple_pay';
            var applePayButtonId = 'ckocom_applePay';

            // Warning messages for ApplePay
            var applePayNotActivated = document.getElementById('ckocom_applePay_not_actived');
            var applePayNotPossible = document.getElementById('ckocom_applePay_not_possible');

            // Initially hide the ApplePay as a payment option
             hideAppleApplePayOption();
            // If ApplePay is available as a payment option, and enabled on the checkout page, un-hide the payment option
            if (window.ApplePaySession) {
                var promise = ApplePaySession.canMakePaymentsWithActiveCard("<?php echo $this->get_option( 'ckocom_apple_mercahnt_id' ); ?>");

                promise.then(function (canMakePayments) {
                   if (canMakePayments) {
                      showAppleApplePayOption();
                   } else {
                      displayApplePayNotPossible();
                   }
                });
            } else {
                displayApplePayNotPossible();
            }

            // Display the button and remove the default place order
            checkoutInitialiseApplePay = function () {
               jQuery('#payment').append('<div id="' + applePayButtonId + '" class="apple-pay-button '
                + "<?php echo $this->get_option( 'ckocom_apple_type' ); ?>" + " "
                + "<?php echo $this->get_option( 'ckocom_apple_theme' ); ?>"  + '" lang="'
                + "<?php echo $this->get_option( 'ckocom_apple_language' ); ?>" + '"></div>');

               jQuery('#ckocom_applePay').hide();
            };

            // Listen for when the ApplePay button is pressed
            jQuery(document).unbind("click").on('click', '#' + applePayButtonId, function () {

              var checkoutFields = '<?php echo $checkoutFields?>';
              var result = isValidFormField(checkoutFields);

              if(result){
                var applePaySession = new ApplePaySession(3, getApplePayConfig());
                handleApplePayEvents(applePaySession);
                applePaySession.begin();
              }

            });

            /**
             *Get the configuration needed to initialise the ApplePay session
             *
             * @param {function} callback
             */
            function getApplePayConfig() {

                var networksSupported = <?php echo json_encode($supportedNetworks); ?>;

                return {
                  
                   currencyCode: "<?php echo get_woocommerce_currency(); ?>",
                   countryCode: "<?php echo $country_code; ?>",
                   merchantCapabilities: ['supports3DS', 'supportsEMV', 'supportsCredit', 'supportsDebit'],
                   supportedNetworks: networksSupported,
                   total: {
                       label: window.location.host,
                       amount: "<?php echo $woocommerce->cart->total ?>",
                       type: 'final'
                   }
                }    
            }

            /**
            * Handle ApplePay events
            */
            function handleApplePayEvents(session) {
               /**
               * An event handler that is called when the payment sheet is displayed.
               *
               * @param {object} event - The event contains the validationURL
               */
               session.onvalidatemerchant = function (event) {
                   performAppleUrlValidation(event.validationURL, function (merchantSession) {
                       session.completeMerchantValidation(merchantSession);
                   });
               };
            
            
               /**
               * An event handler that is called when a new payment method is selected.
               *
               * @param {object} event - The event contains the payment method selected
               */
               session.onpaymentmethodselected = function (event) {
                   // base on the card selected the total can be change, if for example you
                   // plan to charge a fee for credit cards for example
                   var newTotal = {
                       type: 'final',
                       label: window.location.host,
                       amount: "<?php echo $woocommerce->cart->total ?>",
                   };
            
                   var newLineItems = [
                       {
                           type: 'final',
                           label: 'Subtotal',
                           amount: "<?php echo $woocommerce->cart->subtotal ?>"
                       },
                       {
                           type: 'final',
                           label: 'Shipping - ' + "<?php echo $chosen_shipping ?>",
                           amount: "<?php echo $shipping_amount ?>"
                       }
                   ];
                   // if (<?php //echo $this->getPaymentInfo()['discounts'] ?>// > 0) {
                   //     newLineItems.push({
                   //         type: 'final',
                   //         label: 'Discount',
                   //         amount: "-<?php //echo $this->getPaymentInfo()['discounts'] ?>//"
                   //     })
                   // }
            
                   session.completePaymentMethodSelection(newTotal, newLineItems);
               };
            
               /**
               * An event handler that is called when the user has authorized the Apple Pay payment
               *  with Touch ID, Face ID, or passcode.
               */
               session.onpaymentauthorized = function (event) {
                   generateCheckoutToken(event.payment.token.paymentData, function (outcome) {

                      if (outcome) {
                        document.getElementById('cko-apple-card-token').value = outcome;
                        status = ApplePaySession.STATUS_SUCCESS;
                        // jQuery('#place_order').prop("disabled",false);
                        jQuery('#place_order').prop("disabled",false);
                        jQuery('#place_order').trigger('click');
                      } else {
                        status = ApplePaySession.STATUS_FAILURE;
                      }

                      session.completePayment(status);
                   });
               };
            
               /**
               * An event handler that is automatically called when the payment UI is dismissed.
               */
               session.oncancel = function (event) {
                   // popup dismissed
               };

            }

            /**
             *Perform the session validation
             *
             * @param {string} valURL validation URL from Apple
             * @param {function} callback
             */
            function performAppleUrlValidation(valURL, callback) {
               jQuery.ajax({
                   type: 'POST',
                   url: "<?php echo $session_url ?>",
                   data: {
                       url: valURL,
                       merchantId: "<?php echo $this->get_option( 'ckocom_apple_mercahnt_id' ); ?>",
                       domain: window.location.host,
                       displayName: window.location.host,
                   },
                   success: function (outcome) {

                       var data = JSON.parse(outcome);
                       callback(data);
                   }
               });
            }

            /**
             * Generate the checkout.com token based on the ApplePAy payload
             *
             * @param {function} callback
             */
            function generateCheckoutToken(token, callback) {
               jQuery.ajax({
                   type: 'POST',
                   url: "<?php echo $generate_token_url; ?>",
                   data: {
                       token: token
                   },
                   success: function (outcome) {
                       callback(outcome);
                   },
                   error: function () {
                       callback('');
                   }
               });
            }

            /**
            * This will display the ApplePay not activated message
            */
            function displayApplePayNotActivated() {
                applePayNotActivated.style.display = '';
            }

            /**
            * This will display the ApplePay not possible message
            */
            function displayApplePayNotPossible() {
                applePayNotPossible.style.display = '';
            }

            /**
            * Hide the ApplePay payment option from the checkout page
            */
            function hideAppleApplePayOption() {
                jQuery(applePayOptionSelector).hide();
                // jQuery('#ckocom_applePay').hide();
                // jQuery(applePayOptionBodySelector).hide();
            }

            /**
            * Show the ApplePay payment option on the checkout page
            */
            function showAppleApplePayOption() {
                jQuery(applePayOptionSelector).show();
                // jQuery('.apple-pay-button').show();
                // jQuery(applePayOptionBodySelector).show();

                if(jQuery('.payment_method_wc_checkout_com_apple_pay').is(':visible')){
                  
                  console.log('here');

                  //check if apple pay method is check
                  if(jQuery('#payment_method_wc_checkout_com_apple_pay').is(':checked')){
                      // Show apple pay button
                      // disable place order button
                      // jQuery('#place_order').prop("disabled",true);
                      jQuery('#place_order').hide();
                      jQuery('#ckocom_applePay').show();
                  } else {
                      // hide apple pay button
                      // show default place order button
                      // jQuery('#place_order').prop("disabled",false);
                      jQuery('#place_order').show();
                      jQuery('#ckocom_applePay').hide();
                  }

                  // On payment radio button click
                  jQuery("input[name='payment_method']").click(function(){
                      // Check if payment method is google pay
                      if(this.value == 'wc_checkout_com_apple_pay'){
                          // Show apple pay button
                          // hide default place order button
                          // jQuery('#place_order').prop("disabled",true);
                          jQuery('#place_order').hide();
                          jQuery('#ckocom_applePay').show();
                          
                      } else {
                          // hide apple pay button
                          // enable place order button
                          // jQuery('#place_order').prop("disabled",false);
                          jQuery('#place_order').show();
                          jQuery('#ckocom_applePay').hide();
                      }
                  })
                } else {
                  jQuery('#place_order').prop("disabled",false);
                }
            }

            // Initialise apple pay when page is ready
            jQuery( document ).ready(function() {
                checkoutInitialiseApplePay();
            });

            // Validate checkout form before submitting order
            function isValidFormField(fieldList) {
              var result = {error: false, messages: []};
              var fields = JSON.parse(fieldList);

              if(jQuery('#terms').length === 1 && jQuery('#terms:checked').length === 0){ 
                  result.error = true;
                  result.messages.push({target: 'terms', message : 'You must accept our Terms & Conditions.'});
              }
              
              if (fields) {
                  jQuery.each(fields, function(group, groupValue) {
                      if (group === 'shipping' && jQuery('#ship-to-different-address-checkbox:checked').length === 0) {
                          return true;
                      }

                      jQuery.each(groupValue, function(name, value ) {
                          if (!value.hasOwnProperty('required')) {
                              return true;
                          }

                          if (name === 'account_password' && jQuery('#createaccount:checked').length === 0) {
                              return true;
                          }

                          var inputValue = jQuery('#' + name).length > 0 && jQuery('#' + name).val().length > 0 ? jQuery('#' + name).val() : '';

                          if (value.required && jQuery('#' + name).length > 0 && jQuery('#' + name).val().length === 0) {
                              result.error = true;
                              result.messages.push({target: name, message : value.label + ' is a required field.'});
                          }

                          if (value.hasOwnProperty('type')) {
                              switch (value.type) {
                                  case 'email':
                                      var reg     = /^\w+([\.-]?\w+)*@\w+([\.-]?\w+)*(\.\w{2,3})+$/;
                                      var correct = reg.test(inputValue);

                                      if (!correct) {
                                          result.error = true;
                                          result.messages.push({target: name, message : value.label + ' is not correct email.'});
                                      }

                                      break;
                                  case 'tel':
                                      var tel         = inputValue;
                                      var filtered    = tel.replace(/[\s\#0-9_\-\+\(\)]/g, '').trim();

                                      if (filtered.length > 0) {
                                          result.error = true;
                                          result.messages.push({target: name, message : value.label + ' is not correct phone number.'});
                                      }

                                      break;
                              }
                          }
                      });
                  });
              } else {
                  result.error = true;
                  result.messages.push({target: false, message : 'Empty form data.'});
              }

              if (!result.error) {
                  return true;
              }

              jQuery('.woocommerce-error, .woocommerce-message').remove();

              jQuery.each(result.messages, function(index, value) {
                  jQuery('form.checkout').prepend('<div class="woocommerce-error">' + value.message + '</div>');
              });

              jQuery('html, body').animate({
                  scrollTop: (jQuery('form.checkout').offset().top - 100 )
              }, 1000 );

              jQuery(document.body).trigger('checkout_error');

              return false;
            }

        </script>
    <?php
  }

  public function applepay_sesion()
  {
      $url = $_POST["url"];
      $domain = $_POST["domain"];
      $displayName = $_POST["displayName"];

      $merchantId = $this->get_option( 'ckocom_apple_mercahnt_id' );
      $certificate = $this->get_option( 'ckocom_apple_certificate' );
      $certificateKey = $this->get_option( 'ckocom_apple_key' );

      if (
          "https" == parse_url($url, PHP_URL_SCHEME) &&
          substr(parse_url($url, PHP_URL_HOST), -10) == ".apple.com"
      ) {
          $ch = curl_init();

          $data =
              '{
                  "merchantIdentifier":"' . $merchantId . '",
                  "domainName":"' . $domain . '",
                  "displayName":"' . $displayName . '"
              }';

          curl_setopt($ch, CURLOPT_URL, $url);
          curl_setopt($ch, CURLOPT_SSLCERT, $certificate);
          curl_setopt($ch, CURLOPT_SSLKEY, $certificateKey);

          curl_setopt($ch, CURLOPT_POST, 1);
          curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

          // TODO: throw error and log it
          if (curl_exec($ch) === false) {
              echo '{"curlError":"' . curl_error($ch) . '"}';
          }

          // close cURL resource, and free up system resources
          curl_close($ch);

          exit();
      }
  }

  public function applepay_token()
  {
      // Generate apple token
      $token = WC_Checkoutcom_Api_request::generate_apple_token();

      echo $token;

      exit();
  }

 /**
   * Process payment with apple pay
   *
   * @param int $order_id
   * @return array
   */
  public function process_payment( $order_id )
  {
      if (!session_id()) session_start();

      global $woocommerce;
      $order = new WC_Order( $order_id );

      // create google token from google payment data
      $apple_token = $_POST['cko-apple-card-token'];

      // Check if apple token is not empty
      if(empty($apple_token)) {
          WC_Checkoutcom_Utility::wc_add_notice_self(__('There was an issue completing the payment.', 'wc_checkout_com'), 'error');
          return;
      }

      // Create payment with google token
      $result = (array) (new WC_Checkoutcom_Api_request)->create_payment($order, $apple_token);

      // check if result has error and return error message
      if (isset($result['error']) && !empty($result['error'])) {
          WC_Checkoutcom_Utility::wc_add_notice_self(__($result['error']), 'error');
          return;
      }

      // Set action id as woo transaction id
      update_post_meta($order_id, '_transaction_id', $result['action_id']);
      update_post_meta($order_id, '_cko_payment_id', $result['id']);

      // Get cko auth status configured in admin
      $status = WC_Admin_Settings::get_option('ckocom_order_authorised');
      $message = __("Checkout.com Payment Authorised " ."</br>". " Action ID : {$result['action_id']} ", 'wc_checkout_com');

      // check if payment was flagged
      if ($result['risk']['flagged']) {
          // Get cko auth status configured in admin
          $status = WC_Admin_Settings::get_option('ckocom_order_flagged');
          $message = __("Checkout.com Payment Flagged " ."</br>". " Action ID : {$result['action_id']} ", 'wc_checkout_com');
      }

      // add notes for the order and update status
      $order->add_order_note($message);
      $order->update_status($status);

      // Reduce stock levels
      wc_reduce_stock_levels( $order_id );

      // Remove cart
      $woocommerce->cart->empty_cart();

      // Return thank you page
      return array(
          'result' => 'success',
          'redirect' => $this->get_return_url( $order )
      );
  }
}