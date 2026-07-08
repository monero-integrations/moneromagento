<?php

namespace MoneroIntegrations\Custompayment\Model;

use Magento\Framework\Model\AbstractModel;

class PaymentRecord extends AbstractModel
{
    const STATUS_PENDING = 'pending';
    const STATUS_PAID = 'paid';

    protected function _construct()
    {
        $this->_init(\MoneroIntegrations\Custompayment\Model\ResourceModel\PaymentRecord::class);
    }

    public function getPaymentId()
    {
        return (int) $this->getId();
    }

    public function getOrderId()
    {
        return (int) $this->getData('order_id');
    }

    public function setOrderId($orderId)
    {
        return $this->setData('order_id', (int) $orderId);
    }

    public function getStoreId()
    {
        return (int) $this->getData('store_id');
    }

    public function setStoreId($storeId)
    {
        return $this->setData('store_id', (int) $storeId);
    }

    public function getIncrementId()
    {
        return (string) $this->getData('increment_id');
    }

    public function setIncrementId($incrementId)
    {
        return $this->setData('increment_id', (string) $incrementId);
    }

    public function getSubaddress()
    {
        return (string) $this->getData('subaddress');
    }

    public function setSubaddress($subaddress)
    {
        return $this->setData('subaddress', (string) $subaddress);
    }

    public function getAmountXmr()
    {
        return (string) $this->getData('amount_xmr');
    }

    public function setAmountXmr($amount)
    {
        return $this->setData('amount_xmr', (string) $amount);
    }

    public function getAmountAtomic()
    {
        return (int) $this->getData('amount_atomic');
    }

    public function setAmountAtomic($amount)
    {
        return $this->setData('amount_atomic', (int) $amount);
    }

    public function getOrderCurrency()
    {
        return (string) $this->getData('order_currency');
    }

    public function setOrderCurrency($currency)
    {
        return $this->setData('order_currency', (string) $currency);
    }

    public function getConfirmationsRequired()
    {
        return (int) $this->getData('confirmations_required');
    }

    public function setConfirmationsRequired($confirmations)
    {
        return $this->setData('confirmations_required', (int) $confirmations);
    }

    public function getStatus()
    {
        return (string) $this->getData('status');
    }

    public function setStatus($status)
    {
        return $this->setData('status', (string) $status);
    }

    public function getTotalReceivedAtomic()
    {
        return (int) $this->getData('total_received_atomic');
    }

    public function setTotalReceivedAtomic($amount)
    {
        return $this->setData('total_received_atomic', (int) $amount);
    }

    public function getOrderSynced()
    {
        return (bool) $this->getData('order_synced');
    }

    public function setOrderSynced($synced)
    {
        return $this->setData('order_synced', $synced ? 1 : 0);
    }

    public function setPaidAt($paidAt)
    {
        return $this->setData('paid_at', $paidAt);
    }

    public function hasPaymentDetails()
    {
        return $this->getSubaddress() !== ''
            && $this->getAmountXmr() !== ''
            && $this->getAmountAtomic() > 0;
    }
}
