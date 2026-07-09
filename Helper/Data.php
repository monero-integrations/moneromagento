<?php

namespace MoneroIntegrations\Custompayment\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\ScopeInterface;

/**
 * Reads module configuration values from store scope.
 */
class Data extends AbstractHelper
{
    public function getConfig($path, $storeId = null)
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
