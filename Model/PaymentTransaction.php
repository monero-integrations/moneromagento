<?php

namespace MoneroIntegrations\Custompayment\Model;

use Magento\Framework\Model\AbstractModel;

/**
 * A stored incoming wallet transfer (txid) belonging to a payment record.
 */
class PaymentTransaction extends AbstractModel
{
    protected function _construct()
    {
        $this->_init(\MoneroIntegrations\Custompayment\Model\ResourceModel\PaymentTransaction::class);
    }

    public function setPaymentId($paymentId)
    {
        return $this->setData('payment_id', (int) $paymentId);
    }

    public function getPaymentId()
    {
        return (int) $this->getData('payment_id');
    }

    public function setTxid($txid)
    {
        return $this->setData('txid', (string) $txid);
    }

    public function getTxid()
    {
        return (string) $this->getData('txid');
    }

    public function setAmountAtomic($amount)
    {
        return $this->setData('amount_atomic', (int) $amount);
    }

    public function setBlockHeight($height)
    {
        return $this->setData('block_height', (int) $height);
    }

    public function setConfirmations($confirmations)
    {
        return $this->setData('confirmations', (int) $confirmations);
    }
}
