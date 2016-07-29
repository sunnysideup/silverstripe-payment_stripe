<?php


class EcommercePayment_Stripe_CustomerDetails extends DataExtension {

    private static $db = array(
        "StripeCustomerID" => "Varchar(32)",
        "CreditCardDescription" => "Varchar(64)"
    );

    private static $indexes = array(
        "StripeCustomerID" => true
    );

    private static $casting = array(
        "CreditCardHasBeenRecorded" => "boolean"
    );

    private static $field_labels = array(
        "StripeCustomerID" => "Customer ID for Stripe Payments",
        "CreditCardDescription" => "Credit Card Description",
        "CreditCardHasBeenRecorded" => "Credit Card on File"
    );

    /**
     *
     * @return Boolean
     */
    public function CreditCardHasBeenRecorded(){return $this->getCreditCardHasBeenRecorded();}
    public function getCreditCardHasBeenRecorded(){
        return $this->owner->StripeCustomerID ? true : false;
    }


    function updateCMSFields(FieldList $fields){
        $fieldLabels = $this->owner->FieldLabels();
        $fields->addFieldToTab(
            "Root.StripePayments",
            ReadonlyField::create("CreditCardHasBeenRecordedNice", $fieldLabels["CreditCardHasBeenRecorded"], $this->owner->obj("CreditCardHasBeenRecorded")->nice())
        );
        if($this->owner->StripeCustomerID) {
            $dropdownArray = array(
                $this->owner->StripeCustomerID => $this->owner->CreditCardDescription,
                null => _t("EcommercePayment_Stripe_CustomerDetails.REMOVE_CARD", "- REMOVE CARD -")
            );
            $fields->addFieldToTab(
                "Root.StripePayments",
                DropdownField::create("StripeCustomerID", $fieldLabels["CreditCardDescription"], $dropdownArray)
            );
        }
    }

    function augmentEcommerceFields($fields) {
        $fieldLabels = $this->owner->FieldLabels();
        $fields->push(
            ReadonlyField::create("CreditCardHasBeenRecordedNice", $fieldLabels["CreditCardHasBeenRecorded"], $this->owner->obj("CreditCardHasBeenRecorded")->nice())
        );
    }

    function onBeforeWrite(){
        if(!$this->owner->StripeCustomerID) {
            $this->owner->CreditCardDescription = null;
        }
    }

}
