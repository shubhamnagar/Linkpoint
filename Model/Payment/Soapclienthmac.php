<?php
namespace Raveinfosys\Linkpoint\Model\Payment;

use SoapClient;

class Soapclienthmac extends SoapClient
{

    public $encryptor;
    public function __construct($wsdl, $options = null)
    {
        global $context;
        $context = stream_context_create();
        $options['stream_context'] = $context;
        return parent::SoapClient($wsdl['url'], $options);
    }

    public function __doRequest($request, $location, $action, $version, $one_way = null)
    {
        global $context;
        $object_manager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->encryptor = $object_manager->create('\Magento\Framework\Encryption\EncryptorInterface');
        $helper = $object_manager->get('\Raveinfosys\Linkpoint\Helper\Data');
        $hmackey = $this->encryptor->decrypt($helper->getGeneralConfig('hmac_key'));
        $keyid = $this->encryptor->decrypt($helper->getGeneralConfig('key_id'));
        $hashtime = date("c");
        $hashstr = "POST\ntext/xml; charset=utf-8\n" . sha1($request) . "\n" . $hashtime . "\n" . parse_url($location, PHP_URL_PATH);
        $authstr = base64_encode(hash_hmac("sha1", $hashstr, $hmackey, true));
        if (version_compare(PHP_VERSION, '5.3.11') == -1) {
            ini_set("user_agent", "PHP-SOAP/" . PHP_VERSION . "\r\nAuthorization: GGE4_API " . $keyid . ":" . $authstr . "\r\nx-gge4-date: " . $hashtime . "\r\nx-gge4-content-sha1: " . sha1($request));
        } else {
            stream_context_set_option($context, ["http" => ["header" => "authorization: GGE4_API " . $keyid . ":" . $authstr . "\r\nx-gge4-date: " . $hashtime . "\r\nx-gge4-content-sha1: " . sha1($request)]]);
        }
        return parent::__doRequest($request, $location, $action, $version, $one_way);
    }
}
