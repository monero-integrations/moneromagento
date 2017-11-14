<?php

namespace MoneroIntegrations\Custompayment\Helper;

class Data extends \Magento\Framework\App\Helper\AbstractHelper
{
    public function __construct(\Magento\Framework\App\Helper\Context $context, \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig)
    {
        parent::__construct($context);
        $this->_scopeConfig = $scopeConfig;
    }
    
    public function address()
    {
        return $this->_scopeConfig->getValue('payment/custompayment/xmr_address', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }
}
