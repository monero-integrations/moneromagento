<?php

namespace MoneroIntegrations\Custompayment\Model\ResourceModel\PaymentTransaction;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Collection of Monero payment transactions.
 */
class Collection extends AbstractCollection
{
    protected $_idFieldName = 'transaction_id';

    protected function _construct()
    {
        $this->_init(
            \MoneroIntegrations\Custompayment\Model\PaymentTransaction::class,
            \MoneroIntegrations\Custompayment\Model\ResourceModel\PaymentTransaction::class
        );
    }
}
