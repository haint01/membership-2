<?php
/**
 * @copyright Incsub (http://incsub.com/)
 *
 * @license http://opensource.org/licenses/GPL-2.0 GNU General Public License, version 2 (GPL-2.0)
 * 
 * This program is free software; you can redistribute it and/or modify 
 * it under the terms of the GNU General Public License, version 2, as  
 * published by the Free Software Foundation.                           
 *
 * This program is distributed in the hope that it will be useful,      
 * but WITHOUT ANY WARRANTY; without even the implied warranty of       
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the        
 * GNU General Public License for more details.                         
 *
 * You should have received a copy of the GNU General Public License    
 * along with this program; if not, write to the Free Software          
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston,               
 * MA 02110-1301 USA                                                    
 *
*/

class MS_Model_Gateway_Authorize extends MS_Model_Gateway {
	
	const AUTHORIZE_CIM_ID_USER_META = 'ms_authorize_cim_id';
	
	const AUTHORIZE_CIM_PAYMENT_PROFILE_ID_USER_META = 'ms_authorize_cim_payment_profile_id';
	
	protected static $CLASS_NAME = __CLASS__;
	
	protected static $instance;
	
	protected $id = self::GATEWAY_AUTHORIZE;
	
	protected $name = 'Authorize.net Gateway';
	
	protected $description = 'Authorize.net gateway integration';
	
	protected $manual_payment = false;
	
	protected $pro_rate = true;
	
	protected $active = false;
	
	protected $api_login_id;
	
	protected $api_transaction_key;
	
	protected $log_file;
	
	protected $mode;
	
	protected $cim_profile_id;
	
	protected $cim_payment_profile_id;
	
	protected $payment_result;
	
	public function purchase_button( $ms_relationship = false ) {
		$membership = $ms_relationship->get_membership();
		if( 0 == $membership->price ) {
			return;
		}
		
		$fields = array(
				'gateway' => array(
						'id' => 'gateway',
						'type' => MS_Helper_Html::INPUT_TYPE_HIDDEN,
						'value' => $this->id,
				),
				'ms_relationship_id' => array(
						'id' => 'ms_relationship_id',
						'type' => MS_Helper_Html::INPUT_TYPE_HIDDEN,
						'value' => $ms_relationship->id,
				),
				'step' => array(
						'id' => 'step',
						'type' => MS_Helper_Html::INPUT_TYPE_HIDDEN,
						'value' => 'gateway_form',
				),
		);
		if( strpos( $this->pay_button_url, 'http' ) === 0 ) {
			$fields['submit'] = array(
					'id' => 'submit',
					'type' => MS_Helper_Html::INPUT_TYPE_IMAGE,
					'value' =>  $this->pay_button_url,
			);
		}
		else {
			$fields['submit'] = array(
					'id' => 'submit',
					'type' => MS_Helper_Html::INPUT_TYPE_SUBMIT,
					'value' =>  $this->pay_button_url ? $this->pay_button_url : __( 'Signup', MS_TEXT_DOMAIN ),
			);
		}
		$actionurl = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
		?>
			<tr>
				<td class='ms-buy-now-column' colspan='2' >
					<form action="<?php echo $actionurl; ?>" method="post">
						<?php wp_nonce_field( "{$this->id}_{$ms_relationship->membership_id}" ); ?>
						<?php MS_Helper_Html::html_input( $fields['gateway'] ); ?>
						<?php MS_Helper_Html::html_input( $fields['ms_relationship_id'] ); ?>
						<?php MS_Helper_Html::html_input( $fields['step'] ); ?>
						<?php MS_Helper_Html::html_input( $fields['submit'] ); ?>
					</form>
				</td>
			</tr>
		<?php 
	}
	
	/**
	 * Loads Authorize.net lib.
	 *
	 * @since 4.0.0
	 *
	 * @access protected
	 */
	protected function load_authorize_lib(){
		require_once MS_Plugin::instance()->dir . '/lib/authorize.net/autoload.php';
	} 
	
	/**
	 * Returns the instance of AuthorizeNetCIM class.
	 *
	 * @since 4.0.0
	 *
	 * @access protected
	 * @staticvar AuthorizeNetCIM $cim The instance of AuthorizeNetCIM class.
	 * @return AuthorizeNetCIM The instance of AuthorizeNetCIM class.
	 */
	protected function get_cim() {
		static $cim = null;
	
		if ( !is_null( $cim ) ) {
			return $cim;
		}
	
		$this->load_authorize_lib();
	
		$cim = new AuthorizeNetCIM( $this->api_login_id, $this->api_transaction_key );
		$cim->setSandbox( $this->mode != self::MODE_LIVE );
		if ( $this->log_file ) {
			$cim->setLogFile( $this->log_file );
		}
	
		return $cim;
	}
	
	/**
	 * Get customer information manager profile id from user meta.
	 * 
	 * @since 4.0.0
	 *
	 * @access protected
	 * @param int $user_id The user Id.
	 */
	protected function get_cim_profile_id( $user_id ) {
		$this->cim_profile_id = apply_filters( 'ms_model_gateway_authorize_get_cim_profile_id', get_user_meta( $user_id, self::AUTHORIZE_CIM_ID_USER_META, true ), $user_id );
		return $this->cim_profile_id;
	}
	
	/**
	 * Get customer information manager payment profile id from user meta.
	 *
	 * @since 4.0.0
	 *
	 * @param int $user_id The user Id.
	 */
	public function get_cim_payment_profile_id( $user_id ) {
		$this->cim_payment_profile_id = apply_filters( 'ms_model_gateway_authorize_get_cim_payment_profile_id', get_user_meta( $user_id, self::AUTHORIZE_CIM_PAYMENT_PROFILE_ID_USER_META, true ), $user_id );
		return $this->cim_payment_profile_id;
	}
	
	/**
	 * Save customer information manager profile to user meta.
	 *
	 * @since 4.0.0
	 *
	 * @access protected
	 * @param int $user_id The user Id.
	 */
	protected function save_cim_profile( $user_id ) {
		update_user_meta( $user_id, self::AUTHORIZE_CIM_ID_USER_META, $this->cim_profile_id );
		update_user_meta( $user_id, self::AUTHORIZE_CIM_PAYMENT_PROFILE_ID_USER_META, $this->cim_payment_profile_id );
	}
	
	/**
	 * Get customer information manager profile.
	 * 
	 * @since 4.0.0
	 *
	 * @access protected
	 * @param int $user_id The user Id.
	 * @param int $membership_id The membership Id.
	 */
	public function get_cim_profile( $user_id, $membership_id ) {

		$cim_profiles = array();
		$this->get_cim_profile_id( $user_id );
		$membership = MS_Model_Membership::load( $membership_id );
		
		if( $this->cim_profile_id ) {
			
			$response = $this->get_cim()->getCustomerProfile( $this->cim_profile_id );
			if ( $response->isOk() ) {
				$cim_profiles = json_decode( json_encode( $response->xml->profile ), true );
				if( is_array( $cim_profiles ) && !empty( $cim_profiles['paymentProfiles'] ) && is_array( $cim_profiles['paymentProfiles'] ) ) {
					$cim_profiles = $cim_profiles['paymentProfiles'];
				}
			}
		}
		return $cim_profiles;
	}
	
	/**
	 * Creates Authorize.net CIM profile for current user.
	 *
	 * @since 4.0.0
	 *
	 * @access protected
	 * @return int Customer profile ID on success, otherwise FALSE.
	 */
	protected function create_cim_profile( $member ) {

		$this->load_authorize_lib();
	
		$customer = new AuthorizeNetCustomer();
		$customer->merchantCustomerId = $member->id;
		$customer->email = $member->email;
		$customer->paymentProfiles[] = $this->create_cim_payment_profile();
	
		$response = $this->get_cim()->createCustomerProfile( $customer );
		if ( $response->isError() ) {
			throw new Exception( __( 'Payment failed due to CIM profile not created: ', MS_TEXT_DOMAIN ) . $response->getMessageText() );
		}
	
		$this->cim_profile_id = $response->getCustomerProfileId();
		$this->cim_payment_profile_id = $response->getCustomerPaymentProfileIds();
		
		$this->save_cim_profile( $member->id );
		
		return $this->cim_profile_id;
	}
	
	/**
	 * Updates CIM profile by adding a new credit card.
	 *
	 * @since 4.0.0
	 *
	 * @access protected
	 * @return boolean TRUE on success, otherwise FALSE.
	 */
	protected function update_cim_profile( $member ) {
		$response = $this->get_cim()->createCustomerPaymentProfile( $this->cim_profile_id, $this->create_cim_payment_profile() );
		/** If the error is not due to a duplicate customer payment profile.*/
		if ( $response->isError() && 'E00039' != $response->xml->messages->message->code ) {
			throw new Exception( __( 'Payment failed due to CIM profile not updated: ', MS_TEXT_DOMAIN ) . $response->getMessageText() );
		}
	
		$this->cim_profile_id = $response->getCustomerProfileId();
		$this->cim_payment_profile_id = $response->getCustomerPaymentProfileIds();
		
		$this->save_cim_profile( $member->id );
		return true;
	}
	
	/**
	 * Creates CIM payment profile and fills it with posted credit card data.
	 *
	 * @since 4.0.0
	 *
	 * @access protected
	 * @return AuthorizeNetPaymentProfile The instance of AuthorizeNetPaymentProfile class.
	 */
	protected function create_cim_payment_profile() {

		$this->load_authorize_lib();
	
		$payment = new AuthorizeNetPaymentProfile();
	
		// billing information
		$payment->billTo->firstName = substr( trim( filter_input( INPUT_POST, 'first_name' ) ), 0, 50 );
		$payment->billTo->lastName = substr( trim( filter_input( INPUT_POST, 'last_name' ) ), 0, 50 );
		$payment->billTo->company = substr( trim( filter_input( INPUT_POST, 'company' ) ), 0, 50 );
		$payment->billTo->address = substr( trim( filter_input( INPUT_POST, 'address' ) ), 0, 60 );
		$payment->billTo->city = substr( trim( filter_input( INPUT_POST, 'city' ) ), 0, 40 );
		$payment->billTo->state = substr( trim( filter_input( INPUT_POST, 'state' ) ), 0, 40 );
		$payment->billTo->zip = substr( trim( filter_input( INPUT_POST, 'zip' ) ), 0, 20 );
		$payment->billTo->country = substr( trim( filter_input( INPUT_POST, 'country' ) ), 0, 60 );
		$payment->billTo->phoneNumber = substr( trim( filter_input( INPUT_POST, 'phone' ) ), 0, 25 );
	
		// card information
		$payment->payment->creditCard->cardNumber = preg_replace( '/\D/', '', filter_input( INPUT_POST, 'card_num' ) );
		$payment->payment->creditCard->cardCode = trim( filter_input( INPUT_POST, 'card_code' ) );
		$payment->payment->creditCard->expirationDate = sprintf( '%04d-%02d', filter_input( INPUT_POST, 'exp_year', FILTER_VALIDATE_INT ), substr( filter_input( INPUT_POST, 'exp_month', FILTER_VALIDATE_INT ), -2 ) );
	
		return $payment;
	}
	
	/**
	 * Processes purchase action.
	 *
	 * @since 4.0
	 *
	 * @access public
	 */
	public function process_purchase( $ms_relationship ) {
		if ( ! is_ssl() ) {
			wp_die( __( 'You must use HTTPS in order to do this', 'membership' ) );
			exit;
		}
	
		$member = MS_Model_Member::load( $ms_relationship->user_id );
		$invoice = $ms_relationship->get_current_invoice();
	
		$this->get_cim_profile_id( $member->id );
		$this->cim_payment_profile_id = null;
		/** Fetch for user selected cim profile */
		if( $this->cim_payment_profile_id = trim( filter_input( INPUT_POST, 'profile' ) ) ) {
			$response = $this->get_cim()->getCustomerPaymentProfile( $this->cim_profile_id, $this->cim_payment_profile_id );
			if ( $response->isError() ) {
				$this->cim_payment_profile_id = null;
			}
			else {
				$this->save_cim_profile( $member->id );
			}
		}
		
		if( ! $this->cim_profile_id ) {
			$this->create_cim_profile( $member );
		}
		elseif ( empty( $this->cim_payment_profile_id ) ) {
			$this->update_cim_profile( $member );
		}
		
		$this->online_purchase( $invoice );
		
		return $invoice;
	}
		
	/**
	 * Processes online payments.
	 * 
	 * Send to Authorize.net to process the payment immediatly.
	 *
	 * @since 4.0.0
	 *
	 * @access protected
	 * @param MS_Model_Invoice $invoice The invoice to pay.
	 * @return MS_Model_Invoice transaction information on success, otherwise throws an exception.
	 */
	protected function online_purchase( $invoice ) {
		if ( 0 == $invoice->total ) {
			$invoice->status = MS_Model_Invoice::STATUS_PAID;
			$invoice->add_notes( __( 'Total is zero. Payment aproved. Not sent to gateway.', MS_TEXT_DOMAIN ) ); 
			$invoice->save();
			$this->process_transaction( $invoice );
			return $invoice;
		}
		$amount = number_format( $invoice->total, 2, '.', '' );
		
		if( $this->mode == self::MODE_SANDBOX ) {
			$invoice->add_notes( __( 'Sandbox', MS_TEXT_DOMAIN ) );
		}
		
		if ( ! empty( $this->cim_profile_id ) && ! empty( $this->cim_payment_profile_id ) ) {
			$cim_transaction = $this->get_cim_transaction();
			$cim_transaction->amount = $amount;
			$cim_transaction->order->invoiceNumber = $invoice->id;
			
			$invoice->timestamp = time();
			$invoice->save();
				
			$response = $this->get_cim()->createCustomerProfileTransaction( 'AuthCapture', $cim_transaction );
			if ( $response->isOk() ) {
				$transaction_response = $response->getTransactionResponse();
				if( $transaction_response->approved ) {
					$invoice->external_id = $response->getTransactionResponse()->transaction_id;
					$invoice->status = MS_Model_Invoice::STATUS_PAID;
					$invoice->save();
					
					$this->process_transaction( $invoice );
				}
				else {
					throw new Exception( sprintf( __( 'Payment Failed: code %s, subcode %s, reason code %, reason %s', MS_TEXT_DOMAIN ),
							$transaction_response->response_code,
							$transaction_response->response_subcode,
							$transaction_response->response_reason_code,
							$transaction_response->response_reason
					) );
				}
			} 
			else {
				throw new Exception( __( 'Payment Failed: ', MS_TEXT_DOMAIN ) . $response->getMessageText() );
			}
		} 
		else {
			throw new Exception( __( 'Payment failed: CIM Profile not found.', MS_TEXT_DOMAIN ) );
		}
		
		return $invoice;
	}
	
	/**
	 * Schedule a purchase to a future date.
	 * 
	 * Handles recurring payments and pay once schedule.
	 * It is not online. 
	 * Authorize.net gateway only process this in the following day. 
	 *
	 * @since 4.0.0
	 *
	 * @deprecated Not using ARB anymore. Cron used in conjunction with CIM.
	 * @access protected
	 * @param MS_Model_Invoice $invoice The invoice to pay.
	 * @param array $period The period information to schedule.
	 * @param optional boolean $recurring The schedule recurring information. 
	 * @return MS_Model_Invoice transaction information on success, otherwise throws an exception.
	 */
	protected function schedule_purchase( $invoice, $period, $recurring = true ) {
		if ( 0 == $invoice->total ) {
			$invoice->status = MS_Model_Invoice::STATUS_PAID;
			$invoice->add_notes( __( 'Total is zero. Payment aproved. Not sent to gateway.', MS_TEXT_DOMAIN ) );
			$invoice->save();
			return array( $invoice );
		}
		
		$subscription = $this->get_arb_subscription();
		$subscription->amount = number_format( $invoice->total, 2, '.', '' );
		$subscription->startDate = $invoice->due_date;
		/** serial ocurrency with on going subscription (no end date) = 9999 */
		if( $recurring ) {
			$subscription->totalOccurrences = 9999;
		}
		else {
			$subscription->totalOccurrences = 1;
		}
		/** only days or months period types are allowed */
		if( MS_Helper_Period::PERIOD_TYPE_YEARS == $period['period_type'] ) {
			$subscription->intervalLength = $period['period_unit'] * 12;
			$subscription->intervalUnit = MS_Helper_Period::PERIOD_TYPE_MONTHS;
		}
		else {
			$subscription->intervalLength = $period['period_unit'];
			$subscription->intervalUnit = $period['period_type'];
		}
		$subscription->name = $invoice->name;
		$subscription->invoiceNumber = $invoice->invoice_number;
		
		$arb = $this->get_arb();
		$response = $arb->createSubscription( $subscription );
		
		$external_id = $invoice->external_id;
		$external_id['arb'] = $response->getSubscriptionId();
		$invoice->external_id = $external_id;
		
		if( $this->mode == self::MODE_SANDBOX ) {
			$invoice->add_notes( __( 'Sandbox', MS_TEXT_DOMAIN ) );
		}
		$invoice->gateway_id = $this->id;
		
		$invoice->save();

		if( ! $response->isOk() ) {
			$invoice->add_notes( 'Error: '. $response->getMessageText() );
			$invoice->save();
			throw new Exception( $response->getMessageText() );
		}

		return $invoice;			
	}
	
	/**
	 * Initializes and returns Authorize.net CIM transaction object.
	 *
	 * @since 4.0.0
	 *
	 * @access protected
	 * @return AuthorizeNetTransaction The instance of AuthorizeNetTransaction class.
	 */
	protected function get_cim_transaction() {
		$this->load_authorize_lib();
	
		$transaction = new AuthorizeNetTransaction();
		$transaction->customerProfileId = $this->cim_profile_id;
		$transaction->customerPaymentProfileId = $this->cim_payment_profile_id;
	
		return $transaction;
	}
	
	/**
	 * Initializes and returns AuthorizeNetAIM object.
	 *
	 * @since 4.0.0
	 *
	 * @access protected
	 * @staticvar AuthorizeNetAIM $aim The instance of AuthorizeNetAIM class.
	 * @param boolean $refresh Determines whether we need to refresh $aim object or not.
	 * @param boolean $pre_fill Determines whether we need to pre fill AIM object with posted data or not.
	 * @return AuthorizeNetAIM The instance of AuthorizeNetAIM class.
	 */
	protected function get_aim( $refresh = false, $pre_fill = true ) {
		static $aim = null;
	
		if ( ! $refresh && !is_null( $aim ) ) {
			return $aim;
		}
	
		$this->load_authorize_lib();
	
		// create new AIM
		$aim = new AuthorizeNetAIM( $this->api_login_id, $this->api_transaction_key );
		$aim->setSandbox( $this->mode != self::MODE_LIVE );
		if ( $this->log_file ) {
			$aim->setLogFile( $this->log_file );
		}
	
		if ( $pre_fill ) {
			$member = MS_Model_Member::get_current_member();
			// card information
			$aim->card_num = preg_replace( '/\D/', '', filter_input( INPUT_POST, 'card_num' ) );
			$aim->card_code = trim( filter_input( INPUT_POST, 'card_code' ) );
			$aim->exp_date = sprintf( '%02d/%02d', filter_input( INPUT_POST, 'exp_month', FILTER_VALIDATE_INT ), substr( filter_input( INPUT_POST, 'exp_year', FILTER_VALIDATE_INT ), -2 ) );
			$aim->duplicate_window = MINUTE_IN_SECONDS;
	
			// customer information
			$aim->cust_id = $member->id;
			$aim->customer_ip = self::get_remote_ip();
			$aim->email = $member->email;
	
			// billing information
			$aim->first_name = substr( trim( filter_input( INPUT_POST, 'first_name' ) ), 0, 50 );
			$aim->last_name = substr( trim( filter_input( INPUT_POST, 'last_name' ) ), 0, 50 );
			$aim->company = substr( trim( filter_input( INPUT_POST, 'company' ) ), 0, 50 );
			$aim->address = substr( trim( filter_input( INPUT_POST, 'address' ) ), 0, 60 );
			$aim->city = substr( trim( filter_input( INPUT_POST, 'city' ) ), 0, 40 );
			$aim->state = substr( trim( filter_input( INPUT_POST, 'state' ) ), 0, 40 );
			$aim->zip = substr( trim( filter_input( INPUT_POST, 'zip' ) ), 0, 20 );
			$aim->country = substr( trim( filter_input( INPUT_POST, 'country' ) ), 0, 60 );
			$aim->phone = substr( trim( filter_input( INPUT_POST, 'phone' ) ), 0, 25 );
		}
	
		return $aim;
	}
	
	/**
	 * Initializes and returns AuthorizeNetARB object.
	 *
	 * @since 4.0.0
	 *
	 * @access protected
	 * @return AuthorizeNetARB The instance of AuthorizeNetARB class.
	 */
	protected function get_arb() {
	
		$this->load_authorize_lib();
		
		// create new AIM
		$arb = new AuthorizeNetARB( $this->api_login_id, $this->api_transaction_key );
		$arb->setSandbox( $this->mode != self::MODE_LIVE );
		if ( $this->log_file ) {
			$arb->setLogFile( $this->log_file );
		}
			
		return $arb;
	}
	
	/**
	 * Initializes and returns AuthorizeNet_Subscription object.
	 *
	 * @since 4.0.0
	 *
	 * @access protected
	 * @return AuthorizeNet_Subscription The instance of AuthorizeNet_Subscription class.
	 */
	protected function get_arb_subscription() {

		$this->load_authorize_lib();
		
		$member = MS_Model_Member::get_current_member();
		
		// create new subscription
		$subscription = new AuthorizeNet_Subscription();
		$subscription->customerId = $member->id;
		$subscription->customerEmail = $member->email;
		$subscription->customerPhoneNumber = substr( trim( filter_input( INPUT_POST, 'phone' ) ), 0, 25 );
	
		// card information
		$subscription->creditCardCardNumber = preg_replace( '/\D/', '', filter_input( INPUT_POST, 'card_num' ) );
		$subscription->creditCardCardCode = trim( filter_input( INPUT_POST, 'card_code' ) );
		$subscription->creditCardExpirationDate = sprintf( '%04d-%02d', filter_input( INPUT_POST, 'exp_year', FILTER_VALIDATE_INT ), filter_input( INPUT_POST, 'exp_month', FILTER_VALIDATE_INT ) );
	
		// billing information
		$subscription->billToFirstName = substr( trim( filter_input( INPUT_POST, 'first_name' ) ), 0, 50 );
		$subscription->billToLastName = substr( trim( filter_input( INPUT_POST, 'last_name' ) ), 0, 50 );
		$subscription->billToCompany = substr( trim( filter_input( INPUT_POST, 'company' ) ), 0, 50 );
		$subscription->billToAddress = substr( trim( filter_input( INPUT_POST, 'address' ) ), 0, 60 );
		$subscription->billToCity = substr( trim( filter_input( INPUT_POST, 'city' ) ), 0, 40 );
		$subscription->billToState = substr( trim( filter_input( INPUT_POST, 'state' ) ), 0, 40 );
		$subscription->billToZip = substr( trim( filter_input( INPUT_POST, 'zip' ) ), 0, 20 );
		$subscription->billToCountry = substr( trim( filter_input( INPUT_POST, 'country' ) ), 0, 60 );
	
		return $subscription;
	}
	
	public function request_payment( $ms_relationship ) {

		$member = MS_Model_Member::load( $ms_relationship->user_id );
		$invoice = $ms_relationship->get_current_invoice();
		
		$this->get_cim_profile_id( $member->id );
		$this->get_cim_payment_profile_id( $member->id );
		
		try {
			$this->online_purchase( $invoice );
		}
		catch( Exception $e ) {
			MS_Helper_Debug::log( $e->getMessage() );
		}
	}
}
