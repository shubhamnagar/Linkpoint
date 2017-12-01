<?php
namespace Raveinfosys\Linkpoint\Model\Payment;

use Magento\Quote\Api\Data\PaymentMethodInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order\Payment\Transaction;
use Raveinfosys\Linkpoint\Model\Payment\Soapclienthmac;

class Linkpoint extends \Magento\Payment\Model\Method\Cc
{
    const CODE = 'linkpoint';

    public $_code = self::CODE;
    public $encryptor;
    public $Soapclienthmac;
    public $_isGateway = true;
    public $_canCapture = true;
    public $_canCapturePartial = true;
    public $_canRefund = true;
    public $_canRefundInvoicePartial = true;
    public $_stripeApi = false;
    public $_countryFactory;
    public $_minAmount = null;
    public $_maxAmount = null;
    public $_supportedCurrencyCodes = ['USD'];
    public $_debugReplacePrivateDataKeys
        = ['number', 'exp_month', 'exp_year', 'cvc'];
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        \Magento\Framework\Module\ModuleListInterface $moduleList,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate,
        \Magento\Directory\Model\CountryFactory $countryFactory,
        \Magento\Framework\Encryption\EncryptorInterface $encryptor,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $moduleList,
            $localeDate,
            null,
            null,
            $data
        );
        $this->encryptor = $encryptor;
        $this->_countryFactory = $countryFactory;
        $this->_minAmount = $this->getConfigData('min_order_total');
        $this->_maxAmount = $this->getConfigData('max_order_total');
    }

    public function authorize(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        if ($amount <= 0) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Invalid amount for transaction.'));
        }
        $payment->setAmount($amount);
        $data = $this->_prepareData();
        $data['trans_type'] = "01";
        $creditcard = [
            'cardnumber' => $payment->getCcNumber(),
            'cardexpmonth' => $payment->getCcExpMonth(),
            'ccname' => $payment->getCcOwner(),
            'cardexpyear' => substr($payment->getCcExpYear(), -2),
        ];
        if ($this->getConfigData('useccv') == 1) {
            $creditcard["cvmindicator"] = "provided";
            $creditcard["cvmvalue"] = $payment->getCcCid();
            $creditcard["cvv2indicator"] = 1;
            $creditcard["cvv2value"] = $payment->getCcCid();
        }

        $shipping = [];
        $billing = [];
        $order = $payment->getOrder();
        if (!empty($order)) {
            $BillingAddress = $order->getBillingAddress();

            $billing['name'] = $BillingAddress->getFirstname() . " " . $BillingAddress->getLastname();
            $billing['company'] = $BillingAddress->getCompany();
            $billing['address'] = $BillingAddress->getStreet(1);
            $billing['city'] = $BillingAddress->getCity();
            $billing['state'] = $BillingAddress->getRegion();
            $billing['zip'] = $BillingAddress->getPostcode();
            $billing['country'] = $BillingAddress->getCountry();
            $billing['email'] = $order->getCustomerEmail();
            $billing['phone'] = $BillingAddress->getTelephone();
            $billing['fax'] = $BillingAddress->getFax();

            $ShippingAddress = $order->getShippingAddress();
            if (!empty($shipping)) {
                $shipping['sname'] = $ShippingAddress->getFirstname() . " " . $ShippingAddress->getLastname();
                $shipping['saddress1'] = $ShippingAddress->getStreet(1);
                $shipping['scity'] = $ShippingAddress->getCity();
                $shipping['sstate'] = $ShippingAddress->getRegion();
                $shipping['szip'] = $ShippingAddress->getPostcode();
                $shipping['scountry'] = $ShippingAddress->getCountry();
            }
        }

        $merchantinfo = [];
        $merchantinfo['gatewayId'] = $data['gatewayId'];
        $merchantinfo['gatewayPass'] = $data['gatewayPass'];
        $paymentdetails = [];
        $paymentdetails['chargetotal'] = $payment->getAmount();

        $data = array_merge($data, $creditcard, $billing, $shipping, $merchantinfo, $paymentdetails);

        $result = $this->_postRequest($data);

        if (is_array($result) && !empty($result)) {
            if (array_key_exists("Bank_Message", $result)) {
                if ($result["Bank_Message"] != "Approved") {
                    $payment->setStatus(self::STATUS_ERROR);
                    throw new \Magento\Framework\Exception\LocalizedException(__
                        ("Gateway error : {" . (string) $result["EXact_Message"] . "}")
                    );
                } elseif ($result['Transaction_Error']) {
                    throw new \Magento\Framework\Exception\LocalizedException(__
                        ("Returned Error Message: " . $result['Transaction_Error'])
                    );
                } else {
                    $payment->setStatus(self::STATUS_APPROVED);
                    $payment->setAdditionalInformation('payment_type', $this->getConfigData('payment_action'));
                    $payment->setLastTransId((string) $result["Authorization_Num"]);
                    $payment->setTransactionTag((string) $result["Transaction_Tag"]);
                    if (!$payment->getParentTransactionId() || (string) $result["Authorization_Num"] != $payment->getParentTransactionId()) {
                        $payment->setTransactionId((string) $result["Authorization_Num"]);
                    }
                    $this->_addTransaction(
                        $payment,
                        $result["Authorization_Num"],
                        \Magento\Sales\Model\Order\Payment\Transaction::TYPE_AUTH,
                        ['is_transaction_closed' => 0]
                    );
                    $payment->setSkipTransactionCreation(true);
                    return $this;
                }
            } else {
                throw new \Magento\Framework\Exception\LocalizedException(__("No approval found"));
            }
        } else {
            throw new \Magento\Framework\Exception\LocalizedException(__("No response found"));
        }
    }

    public function capture(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        if ($amount <= 0) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Invalid amount for transaction.'));
        }

        if ($payment->getTransactionTag() != '') {
            return $this->authorizePayment($payment, number_format($amount, 2, '.', ''));
        }
        
        $payment->setAmount($amount);
        $data = $this->_prepareData();
        $data['trans_type'] = "00";
        $creditcard = [
            'cardnumber' => $payment->getCcNumber(),
            'cardexpmonth' => $payment->getCcExpMonth(),
            'cardexpyear' => substr($payment->getCcExpYear(), -2),
            'ccname' => $payment->getCcOwner()
        ];
        if ($this->getConfigData('useccv') == 1) {
            $creditcard["cvmindicator"] = "provided";
            $creditcard["cvmvalue"] = $payment->getCcCid();
            $creditcard["cvv2indicator"] = 1;
            $creditcard["cvv2value"] = $payment->getCcCid();
        }

        $shipping = [];
        $billing = [];
        $order = $payment->getOrder();
        if (!empty($order)) {
            $BillingAddress = $order->getBillingAddress();

            $billing['name'] = $BillingAddress->getFirstname() . " " . $BillingAddress->getLastname();
            $billing['company'] = $BillingAddress->getCompany();
            $billing['address'] = $BillingAddress->getStreet(1);
            $billing['city'] = $BillingAddress->getCity();
            $billing['state'] = $BillingAddress->getRegion();
            $billing['zip'] = $BillingAddress->getPostcode();
            $billing['country'] = $BillingAddress->getCountry();
            $billing['email'] = $order->getCustomerEmail();
            $billing['phone'] = $BillingAddress->getTelephone();
            $billing['fax'] = $BillingAddress->getFax();

            $ShippingAddress = $order->getShippingAddress();
            if (!empty($shipping)) {
                $shipping['sname'] = $ShippingAddress->getFirstname() . " " . $ShippingAddress->getLastname();
                $shipping['saddress1'] = $ShippingAddress->getStreet(1);
                $shipping['scity'] = $ShippingAddress->getCity();
                $shipping['sstate'] = $ShippingAddress->getRegion();
                $shipping['szip'] = $ShippingAddress->getPostcode();
                $shipping['scountry'] = $ShippingAddress->getCountry();
            }
        }

        $merchantinfo = [];
        $merchantinfo['gatewayId'] = $data['gatewayId'];
        $merchantinfo['gatewayPass'] = $data['gatewayPass'];
        $paymentdetails = [];
        $paymentdetails['chargetotal'] = $payment->getAmount();

        $data = array_merge($data, $creditcard, $billing, $shipping, $merchantinfo, $paymentdetails);

        $result = $this->_postRequest($data);

        if (is_array($result) && count($result) > 0) {
            if (array_key_exists("Bank_Message", $result)) {
                if ($result["Bank_Message"] != "Approved") {
                    $payment->setStatus(self::STATUS_ERROR);
                    throw new \Magento\Framework\Exception\LocalizedException(__("Gateway error : {" . (string) $result["EXact_Message"] . "}"));
                } elseif ($result['Transaction_Error']) {
                    throw new \Magento\Framework\Exception\LocalizedException(__("Returned Error Message: " . $result['Transaction_Error']));
                } else {
                    $payment->setStatus(self::STATUS_APPROVED);
                    $payment->setLastTransId((string) $result["Authorization_Num"]);
                    $payment->setTransactionTag((string) $result["Transaction_Tag"]);
                    if (!$payment->getParentTransactionId() || (string) $result["Authorization_Num"] != $payment->getParentTransactionId()) {
                        $payment->setTransactionId((string) $result["Authorization_Num"]);
                    }
                    return $this;
                }
            } else {
                throw new \Magento\Framework\Exception\LocalizedException(__("No approval found"));
            }
        } else {
            throw new \Magento\Framework\Exception\LocalizedException(__("No response found"));
        }
    }

    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        if ($payment->getRefundTransactionId() && $amount > 0) {
            $data = $this->_prepareData();
            $data["trans_type"] = '34';
            $data["oid"] = $payment->getRefundTransactionId();
            $data['transaction_tag'] = $payment->getTransactionTag();
            $data['authorization_num'] = $payment->getParentTransactionId();

            $instance = $payment->getMethodInstance()->getInfoInstance();
            $paymentdetails = [];
            $paymentdetails['chargetotal'] = $amount;
            $paymentdetails['cardnumber'] = $instance->getCcNumber();
            $paymentdetails['ccname'] = $instance->getCcOwner();
            $paymentdetails['cardexpmonth'] = $instance->getCcExpMonth();
            $paymentdetails['cardexpyear'] = substr($instance->getCcExpYear(), -2);
            $shipping = [];
            $billing = [];

            $data = array_merge($data, $paymentdetails);
            $result = $this->_postRequest($data);
            if (is_array($result) && count($result) > 0) {
                if (array_key_exists("Bank_Message", $result)) {
                    if ($result["Bank_Message"] != "Approved") {
                        throw new \Magento\Framework\Exception\LocalizedException(__("Gateway error : {" . (string) $result["EXact_Message"] . "}"));
                    } else {
                        $payment->setStatus(self::STATUS_SUCCESS);
                        $payment->setLastTransId((string) $result["Authorization_Num"]);
                        if (!$payment->getParentTransactionId() || (string) $result["Authorization_Num"] != $payment->getParentTransactionId()) {
                            $payment->setTransactionId((string) $result["Authorization_Num"]);
                        }
                        return $this;
                    }
                } else {
                    throw new \Magento\Framework\Exception\LocalizedException(__("No approval found"));
                }
            } else {
                throw new \Magento\Framework\Exception\LocalizedException(__("No response found"));
            }
        }
        throw new \Magento\Framework\Exception\LocalizedException(__('Error in refunding the payment.'));
    }

    public function _prepareData()
    {
        $data = [
            'keyId' => $this->encryptor->decrypt($this->getConfigData('key_id')),
            'hmacKey' => $this->encryptor->decrypt($this->getConfigData('hmac_key')),
            'wsdlUrl' => $this->getConfigData('wsdl_url'),
            'gatewayId' => $this->encryptor->decrypt($this->getConfigData('gateway_id')),
            'gatewayPass' => $this->encryptor->decrypt($this->getConfigData('gateway_pass')),
        ];

        if ($this->getConfigData('mode')) {
            $data['wsdlUrl'] = "https://api.demo.globalgatewaye4.firstdata.com/transaction/v12/wsdl";
        }

        if (empty($data['keyId']) || empty($data['hmacKey']) || empty($data['wsdlUrl']) || empty($data['gatewayId']) || empty($data['gatewayPass'])) {
            throw new \Magento\Framework\Exception\LocalizedException(__("Gateway Parameters Missing"));
        }
        return $data;
    }

    public function _postRequest($data)
    {
        $debugData = ['request' => $data];
        $trxnProperties = '';
        $trxnProperties = $this->_buildRequest($data);
        try {
            $client = new Soapclienthmac(['url' => $data["wsdlUrl"]]);
            $response = $client->SendAndCommit($trxnProperties);
        } catch (Exception $e) {
            $debugData['response'] = $e->getMessage();
            $this->_debug($debugData);
            throw new \Magento\Framework\Exception\LocalizedException(__("Link point authorization failed"));
        }
        if (!$response) {
            $debugData['response'] = $response;
            $this->_debug($debugData);
            throw new \Magento\Framework\Exception\LocalizedException(__(ucwords("error in $response")));
        }

        if (@$client->fault) {
            throw new \Magento\Framework\Exception\LocalizedException(__("FAULT:  Code: {$client->faultcode} <BR /> String: {$client->faultstring} </B>"));
            $response["CTR"] = "There was an error while processing. No TRANSACTION DATA IN CTR!";
        }
        $result = $this->_readResponse($response);
        $debugData['response'] = $result;
        $this->_debug($debugData);
        return $result;
    }

    public function _readResponse($trxnResult)
    {
        foreach ($trxnResult as $key => $value) {
            $value = nl2br($value);
            $retarr[$key] = $value;
        }
        return $retarr;
    }

    public function _buildRequest($req)
    {
        $name = (isset($req['transaction_tag'])) ? '' : $req["name"];
        $request = [
            "User_Name" => "",
            "Secure_AuthResult" => "",
            "Ecommerce_Flag" => "",
            "XID" => isset($req["oid"]) ? $req["oid"] : false,
            "ExactID" => $req["gatewayId"],
            "CAVV" => "",
            "Password" => $req["gatewayPass"],
            "CAVV_Algorithm" => "",
            "Transaction_Type" => $req["trans_type"],
            "Reference_No" => "",
            "Customer_Ref" => "",
            "Reference_3" => "",
            "Client_IP" => isset($req["ip"]) ? $req["ip"] : false,
            "Client_Email" => isset($req["email"]) ? $req["email"] : '',
            "Language" => "en",
            "Card_Number" => isset($req["cardnumber"]) ? $req["cardnumber"] : '',
            "Expiry_Date" => isset($req['cardexpmonth']) ? sprintf("%02d", $req['cardexpmonth']) . $req['cardexpyear'] : '',
            "CardHoldersName" => isset($req["ccname"]) ? $req["ccname"] : $name,
            "Track1" => "",
            "Track2" => "",
            "Authorization_Num" => isset($req["authorization_num"]) ? $req["authorization_num"] : false,
            "Transaction_Tag" => isset($req["transaction_tag"]) ? $req["transaction_tag"] : false,
            "DollarAmount" => $req["chargetotal"],
            "VerificationStr1" => "",
            "VerificationStr2" => isset($req["cvv2value"]) ? $req["cvv2value"] : '',
            "CVD_Presence_Ind" => isset($req["cvv2indicator"]) ? $req["cvv2indicator"] : '',
            "Secure_AuthRequired" => "",
            "Currency" => "",
            "PartialRedemption" => "",
            "ZipCode" => isset($req["zip"]) ? $req["zip"] : '',
            "Tax1Amount" => "",
            "Tax1Number" => "",
            "Tax2Amount" => "",
            "Tax2Number" => "",
            "SurchargeAmount" => "",
            "PAN" => ""
        ];

        return $request;
    }

    public function _addTransaction(\Magento\Payment\Model\InfoInterface $payment, $transactionId, $transactionType, array $transactionDetails = [], $message = false)
    {

        $payment->setTransactionId($transactionId);
        $payment->resetTransactionAdditionalInfo();
        foreach ($transactionDetails as $key => $value) {
            $payment->setData($key, $value);
        }

        $transaction = $payment->addTransaction($transactionType, null, false, $message);
        foreach ($transactionDetails as $key => $value) {
            $payment->unsetData($key);
        }
        $payment->unsLastTransId();

        $transaction->setMessage($message);

        return $transaction;
    }

    public function authorizePayment(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $payment->setAmount($amount);
        $data = $this->_prepareData();
        $data['trans_type'] = "32";
        $data['transaction_tag'] = $payment->getTransactionTag();
        $data['authorization_num'] = $payment->getParentTransactionId();
        $data['chargetotal'] = $amount;
        $result = $this->_postRequest($data);
        if (is_array($result) && count($result) > 0) {
            if (array_key_exists("Bank_Message", $result)) {
                if ($result["Bank_Message"] != "Approved") {
                    $payment->setStatus(self::STATUS_ERROR);
                    throw new \Magento\Framework\Exception\LocalizedException(__("Gateway error : {" . (string) $result["EXact_Message"] . "}"));
                } elseif ($result['Transaction_Error']) {
                    throw new \Magento\Framework\Exception\LocalizedException(__("Returned Error Message: " . $result['Transaction_Error']));
                } else {
                    $payment->setStatus(self::STATUS_APPROVED);
                    $payment->setLastTransId((string) $result["Authorization_Num"]);
                    $payment->setTransactionTag((string) $result["Transaction_Tag"]);
                    if (!$payment->getParentTransactionId() || (string) $result["Authorization_Num"] != $payment->getParentTransactionId()) {
                        $payment->setTransactionId((string) $result["Authorization_Num"]);
                    }
                    return $this;
                }
            } else {
                throw new \Magento\Framework\Exception\LocalizedException(__("No approval found"));
            }
        } else {
            throw new \Magento\Framework\Exception\LocalizedException(__("No response found"));
        }
    }
}
