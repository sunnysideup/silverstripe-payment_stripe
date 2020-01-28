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
class EcommercePayment_Stripe_RecordACustomer extends EcommercePayment_Stripe
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
        $this->retrieveVariables();
        if (
            $this->_processing_member &&
            $this->_processing_member->CreditCardHasBeenRecorded()
        ) {
            //save basic info
            //no idea why we need this!!!
            if ($this->_processing_amount) {
                $this->Amount->Amount = $this->_processing_amount / 100;
            }
            $this->Status = "Pending";
            $returnObject = EcommercePayment_Success::create();
        } else {
            $billingAddress = $this->_processing_order->CreateOrReturnExistingAddress("BillingAddress");
            $this->instantiateAPI();
            //first create the customer
            $requestData = array(
                "description" => $this->_processing_statement_description,
                "metadata" => $this->_processing_metadata,
                "email" => $billingAddress->Email,
                "source" => array(
                    "object" => "card",
                    "exp_month" => $this->_processing_card['exp_month'],
                    "exp_year" => $this->_processing_card['exp_year'],
                    "number" => $this->_processing_card['number'],
                    "name" => $this->_processing_card['name'],
                    "address_line1" => $billingAddress->Address,
                    "address_line2" => $billingAddress->Address2,
                    "address_state" => $billingAddress->RegionCode,
                    "address_city" => $billingAddress->City,
                    "address_zip" => $billingAddress->PostalCode,
                    "address_country" => $billingAddress->RegionCode
                )
            );

            $responseData = \Stripe\Customer::create(
                $requestData,
                $this->_processing_idempotency_key
            );
            $this->removeCardDetails();
            $requestData["card"]["number"] = $this->_processing_truncated_card;
            $this->recordTransaction($requestData, $responseData);

            //save basic info
            //no idea why we need this!!!
            if ($this->_processing_amount) {
                $this->Amount->Amount = $this->_processing_amount / 100;
            }
            if (
                $responseData &&
                isset($responseData->id) &&
                $this->_processing_member instanceof Member
            ) {
                $this->Status = "Pending";
                $this->_processing_member->StripeCustomerID = $responseData->id;
                $this->_processing_member->CreditCardDescription = $this->_processing_card_description;
                $this->_processing_member->write();
                $returnObject = EcommercePayment_Success::create();
            } else {
                $this->Status = "Failure";
                $returnObject = EcommercePayment_Failure::create();
            }
        }
        $this->write();
        return $returnObject;
    }

    /**
     * Return the payment form fields that should
     * be shown on the checkout order form for the
     * payment type. Example: for {@link DPSPayment},
     * this would be a set of fields to enter your
     * credit card details.
     *
     * @return FieldList
     */
    public function getPaymentFormFields($amount = 0, $order = null)
    {
        $this->retrieveVariables();
        if ($this->_processing_member && $this->_processing_member->CreditCardHasBeenRecorded()) {
            return FieldList::create(
                ReadonlyField::create("CreditCardOnFile", _t("Stripe.CREDIT_CARD_ON_FILE", "Credit Card on file"), $this->memberCurrentCardDescription())
            );
        } else {
            return parent::getPaymentFormFields($amount, $order);
        }
    }

    /**
     * return null | string
     */
    public function memberCurrentCardDescription()
    {
        $this->retrieveVariables();
        if ($this->_processing_member && $this->_processing_member->CreditCardHasBeenRecorded()) {
            $this->instantiateAPI();
            $customer = \Stripe\Customer::retrieve($this->_processing_member->StripeCustomerID);
            if ($customer && (!isset($customer->deleted) || (isset($customer->deleted) && !$customer->deleted))) {
                if ($customer->id == $this->_processing_member->StripeCustomerID) {
                    return $this->_processing_member->CreditCardDescription;
                } else {
                    return _t("Stripe.ERROR_RETRIEVING_DETAILS #2", "Error retrieving details");
                }
            } else {
                return _t("Stripe.ERROR_RETRIEVING_DETAILS #1", "Error retrieving details");
            }
        }
    }
}
