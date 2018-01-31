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
class EcommercePayment_Stripe_ChargeRecordedAndAuthorizedCustomer extends EcommercePayment_Stripe
{

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
    public function processPayment($data, $form)
    {
        //get variables
        $responseData = null;
        //get variables
        $this->retrieveVariables();
        $this->instantiateAPI();
        //less than fifty cents then it is fine ...
        if ($this->_processing_amount < 50) {
            $this->Status = "Success";
            $returnObject = EcommercePayment_Success::create();
        } elseif ($this->_processing_member && $this->_processing_member->CreditCardHasBeenRecorded()) {
            $authorizedCharges = EcommercePayment_Stripe_AuthorizeRecordedCustomer::get()
                ->filter(array("OrderID" => $this->_processing_order->ID));
            $ch = null;
            $responseData = null;
            foreach ($authorizedCharges as $authorizedCharge) {
                if (
                    $authorizedCharge->Amount->Amount == $this->_processing_amount / 100 &&
                    strtolower($authorizedCharge->Amount->Currency) == strtolower($this->_processing_currency)
                ) {
                    $ch = \Stripe\Charge::retrieve($authorizedCharge->ChargeID);
                    $responseData = $ch->capture();
                    //save basic info
                    $this->recordTransaction($chargeID, $responseData);
                    break;
                }
            }
        }
        if (
            $ch && 
            $responseData && 
            (
                isset($responseData->captured) && $responseData->captured == true
            ) 
            &&
            (
                isset($responseData->status) && $responseData->status == "succeeded"
            )
        ) {
            $this->Status = "Success";
            $returnObject = EcommercePayment_Success::create();
        } else {
            $this->Status = "Failure";
            $returnObject = EcommercePayment_Failure::create();
        }
        $this->write();
        return $returnObject;
    }
}
