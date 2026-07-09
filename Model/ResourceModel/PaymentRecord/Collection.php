<?php

namespace MoneroIntegrations\Custompayment\Model\ResourceModel\PaymentRecord;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Collection of Monero payment records.
 */
class Collection extends AbstractCollection
{
    protected $_idFieldName = 'payment_id';

    protected function _construct()
    {
        $this->_init(
            \MoneroIntegrations\Custompayment\Model\PaymentRecord::class,
            \MoneroIntegrations\Custompayment\Model\ResourceModel\PaymentRecord::class
        );
    }
}
