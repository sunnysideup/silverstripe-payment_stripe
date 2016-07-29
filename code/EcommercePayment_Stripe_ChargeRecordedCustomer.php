<?php
/**
 * "Abstract" class for a number of different payment
 * types allowing a user to pay for something on a site.
 *
 *
 * This can't be an abstract class because sapphire doesn't
 * support abstract DataObject classes.
 *
 * @package payment
 */
class EcommercePayment_Stripe_ChargeRecordedCustomer extends EcommercePayment_Stripe  {

    /**
     * Perform payment processing for the type of
     * payment. For example, if this was a credit card
     * payment type, you would perform the data send
     * off to the payment gateway on this function for
     * your payment subclass.
     *
     * This is used by {@link OrderForm} when it is
     * submitted.
     *
     * @param array $data The form request data - see OrderForm
     * @param OrderForm $form The form object submitted on
     *
     * @return EcommercePaymentResult
     */
    function processPayment($data, $form){
        //get variables
        $responseData = null;
        //get variables
        $this->retrieveVariables();
        $this->instantiateAPI();
        //less than fifty cents then it is fine ...
        if($this->_processing_amount < 50) {
            $this->Status = "Success";
            $returnObject = EcommercePayment_Success::create();
        }
        elseif($this->_processing_member && $this->_processing_member->CreditCardHasBeenRecorded()) {
            //if currency has been pre-set use this
            $requestData = array(
                'customer' => $this->_processing_member->StripeCustomerID,
                'amount' => $this->_processing_amount,
                'currency' => $this->_processing_currency,
                'capture' => true,
                'statement_descriptor' => $this->_processing_statement_description,
                'metadata' => $this->_processing_metadata
            );
            $responseData = \Stripe\Charge::create($requestData, $this->_processing_idempotency_key);
            $this->removeCardDetails();

            //save basic info
            $this->recordTransaction($requestData, $responseData);

            //no idea why we need this!!!
            $this->Amount->Amount = $this->_processing_amount / 100;
        }
        if(
            $responseData &&
            $responseData->status == "succeeded"
        ) {
            $this->Status = "Success";
            $returnObject = EcommercePayment_Success::create();
        }
        else {
            $this->Status = "Failure";
            $returnObject = EcommercePayment_Failure::create();
        }
        $this->write();
        return $returnObject;
    }



}
