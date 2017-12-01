<?php

namespace Raveinfosys\Linkpoint\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\ScopeInterface;

class Data extends AbstractHelper
{
    public $storeManager;
    public $objectManager;
    const XML_PATH_LINKPOINT = 'payment/linkpoint/';
    public function __construct(
        Context $context,
        ObjectManagerInterface $objectManager,
        StoreManagerInterface $storeManager
    ) {
        $this->objectManager = $objectManager;
        $this->storeManager  = $storeManager;
        parent::__construct($context);
    }

    public function getConfigValue($field, $storeId = null)
    {
        return $this->scopeConfig
                    ->getValue($field, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getGeneralConfig($code)
    {
        return $this->getConfigValue(self::XML_PATH_LINKPOINT . $code);
    }
}
