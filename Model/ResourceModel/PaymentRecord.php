<?php

namespace MoneroIntegrations\Custompayment\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Resource model for the monero_integrations_payment table.
 */
class PaymentRecord extends AbstractDb
{
    const TABLE = 'monero_integrations_payment';

    protected function _construct()
    {
        $this->_init(self::TABLE, 'payment_id');
    }
}
