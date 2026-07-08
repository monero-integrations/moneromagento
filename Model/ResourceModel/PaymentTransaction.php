<?php

namespace MoneroIntegrations\Custompayment\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class PaymentTransaction extends AbstractDb
{
    const TABLE = 'monero_integrations_transaction';

    protected function _construct()
    {
        $this->_init(self::TABLE, 'transaction_id');
    }
}
