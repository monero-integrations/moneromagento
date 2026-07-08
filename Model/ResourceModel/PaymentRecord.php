<?php

namespace MoneroIntegrations\Custompayment\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class PaymentRecord extends AbstractDb
{
    const TABLE = 'monero_integrations_payment';

    protected function _construct()
    {
        $this->_init(self::TABLE, 'payment_id');
    }
}
