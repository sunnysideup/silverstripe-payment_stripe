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
class EcommercePayment_Stripe extends EcommercePayment
{

    /**
     * @var string
     */
    private static $api_key_public = "";

    /**
     * @var string
     */
    private static $api_key_private = "";

    /**
     * set the required privacy link as you see fit...
     * also see: https://www.paymentexpress.com/About/Artwork_Downloads
     * also see: https://www.paymentexpress.com/About/About_DPS/Privacy_Policy
     * @var String
     */
    private static $stripe_logo_and_link = '
    <div>Stripe Logos go here...</div>
    ';

    /**
     * we use yes / no as this is more reliable than a boolean value
     * for configs
     * @var String
     */
    private static $is_test = "yes";

    /**
     * we use yes / no as this is more reliable than a boolean value
     * for configs
     * @var boolean
     */
    private static $is_live = "no";

    /**
     * Incomplete (default): Payment created but nothing confirmed as successful
     * Success: Payment successful
     * Failure: Payment failed during process
     * Pending: Payment awaiting receipt/bank transfer etc
     */
    private static $db = array(
        "StripeID" => "Varchar(64)",
        "CardNumber" => "Varchar(19)",
        "NameOnCard" => "Varchar(40)",
        "ExpiryDate" => "Varchar(4)",
        "CVVNumber" => "Varchar(3)",
        "Request" => "Text",
        "Response" => "Text",
        "IdemPotencyKey" => "Text"
    );

    private static $casting = array(
        "RequestDetails" => "HTMLText",
        "ResponseDetails" => "HTMLText"
    );

    private static $indexes = array(
        "StripeID" => true
    );

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        $fields->addFieldToTab("Root.Debug", new ReadonlyField("ClassName"));
        $fields->addFieldToTab("Root.Debug", new LiteralField("Request", "<h2>Request</h2>".$this->getRequestDetails()));
        $fields->addFieldToTab("Root.Debug", new LiteralField("SeparatorForRequest", "<hr />"));
        $fields->addFieldToTab("Root.Debug", new LiteralField("Response", "<h2>Response</h2>".$this->myResponseDetails()));
        return $fields;
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
        $formHelper = $this->ecommercePaymentFormSetupAndValidationObject();
        $fieldList = $formHelper->getCreditCardPaymentFormFields($this);
        $fieldList->insertBefore(
            new LiteralField("Stripe_Logo", $this->Config()->get("stripe_logo_and_link")),
            "EcommercePayment_Stripe_CreditCard"
        );
        return $fieldList;
    }

    /**
     * Define what fields defined in {@link Order->getPaymentFormFields()}
     * should be required.
     *
     * @see DPSPayment->getPaymentFormRequirements() for an example on how
     * this is implemented.
     *
     * @return array
     */
    public function getPaymentFormRequirements()
    {
        $formHelper = $this->ecommercePaymentFormSetupAndValidationObject();
        return $formHelper->getCreditCardPaymentFormFieldsRequired($this);
    }

    /**
     * returns true if all the data is correct.
     *
     * @param array $data The form request data - see OrderForm
     * @param OrderForm $form The form object submitted on
     *
     * @return Boolean
     */
    public function validatePayment($data, $form)
    {
        $formHelper = $this->ecommercePaymentFormSetupAndValidationObject();
        return $formHelper->validateAndSaveCreditCardInformation($data, $form, $this);
    }

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
        $this->instantiateAPI();


        $requestData = array(
            'card' => $this->_processing_card,
            'amount' => $this->_processing_amount,
            'currency' => $this->_processing_currency,
            'statement_descriptor' => $this->_processing_statement_description,
            'metadata' => $this->_processing_metadata
        );

        //do stripe bit

        $responseData = \Stripe\Charge::create($requestData, $this->_processing_idempotency_key);

        //remove card for security reasons
        $this->removeCardDetails();
        $allDetails["card"]["number"] = $this->_processing_truncated_card;

        //now we can save the details:
        $this->recordTransaction($requestData, $responseData);

        if (
            $responseData &&
            $responseData->status == "succeeded"
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

    /**
     *
     * @return string (HTML)
     */
    public function getRequestDetails()
    {
        return "<pre>".print_r(unserialize($this->Request), 1)."</pre>";
    }

    /**
     *
     * @return string (HTML)
     */
    public function myResponseDetails()
    {
        return "<pre>".print_r(unserialize($this->Response), 1)."</pre>";
    }

    /**
     * @var Order
     */
    protected $_processing_order = null;

    /**
     * @var float
     */
    protected $_processing_amount = 0;

    /**
     * @var string
     */
    protected $_processing_currency = 0;

    /**
     * @var int
     */
    protected $_processing_year = 0;

    /**
     * @var int
     */
    protected $_processing_month = 0;

    /**
     * @var string
     */
    protected $_processing_statement_description = "";

    /**
     * @var array
     */
    protected $_processing_metadata = array();

    /**
     *
     *
     */
    protected function retrieveVariables()
    {
        if (!$this->_processing_idempotency_key) {
            if ($this->ID) {
                $hash = hash("SHA1", $this->ID."_".$this->ClassName);
            } else {
                $hash = hash("SHA1", rand(0, 9999999999999999));
            }
            $this->_processing_idempotency_key =  array("idempotency_key" => $hash);
            $this->_processing_check_code = substr($hash, 0, 22);
            if ($this->OrderID) {
                $order = $this->Order();
            } else {
                $order = ShoppingCart::current_order();
            }
            $this->_processing_order = $order;
            $this->_processing_member = $this->_processing_order->Member();
            $this->_processing_currency = strtolower($this->Amount->Currency);
            $this->_processing_amount = $this->Amount->Amount * 100;
            $this->_processing_month = substr($this->ExpiryDate, 0, 2);
            $this->_processing_year = substr($this->ExpiryDate, 2, 2);
            $this->_processing_metadata = array(
                "OrderID" => $this->_processing_order->ID,
                "EcommercePaymentID" => $this->ID
            );
            $this->_processing_statement_description = $this->_processing_check_code;
            if ($this->hasFullCardNumber()) {
                $this->_processing_card = array(
                    'number' => $this->CardNumber,
                    'exp_month' => $this->_processing_month,
                    'exp_year' => $this->_processing_year,
                    'name' => $this->NameOnCard
                );
                $this->_processing_truncated_card = substr($this->CardNumber, 12, 4);
                $this->_processing_card_description =
                    "...".$this->_processing_truncated_card.
                    "; exp: ".$this->_processing_month."-".$this->_processing_year.
                    "; name: ".$this->NameOnCard;
            }
        }
    }

    /**
     *
     *
     */
    protected function instantiateAPI()
    {
        $api = $this->Config()->get("api_key_private");
        \Stripe\Stripe::setApiKey($api);
    }

    /**
     * remove the card details
     * for securityu reasons
     */
    protected function removeCardDetails()
    {
        if ($this->hasFullCardNumber()) {
            $this->CardNumber = $this->_processing_truncated_card;
            $this->_processing_card = null;
        }
    }

    /**
     * is the full credit card recorded?
     * @return boolean
     */
    protected function hasFullCardNumber()
    {
        return strlen($this->CardNumber) > 12;
    }

    /**
     * @param mixed $requestData;
     * @param mixed $responseData;
     */
    protected function recordTransaction($requestData, $responseData)
    {
        $this->Request = serialize($requestData);
        $this->Response = serialize($responseData);
        if ($responseData && isset($responseData->id)) {
            $this->StripeID = $responseData->id;
            //$this->IdemPotencyKey = $responseData->getLastReponse()->header["Idempotency-Key"];
        }
        if ($this->_processing_amount) {
            //no idea why we need this!!!
            $this->Amount->Amount = $this->_processing_amount / 100;
        }
        $this->Message = _t("EcommercePayment_Stripe", "TRANSACTION_CODE").": ".$this->_processing_check_code;
    }
}
