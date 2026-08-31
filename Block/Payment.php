<?php

namespace MoneroIntegrations\Custompayment\Block;

use Magento\Framework\View\Element\Template;

/**
 * Payment-page view block: exposes the order's Monero amount, subaddress, state and QR URI to the template.
 */
class Payment extends Template
{
    public function isError()
    {
        return (bool) $this->getData('is_error');
    }

    public function getIncrementId()
    {
        return (string) $this->getData('increment_id');
    }

    public function getSubaddress()
    {
        return (string) $this->getData('subaddress');
    }

    public function getAmountXmr()
    {
        return (string) $this->getData('amount_xmr');
    }

    public function getReceivedXmr()
    {
        return (string) $this->getData('received_xmr');
    }

    public function getDetectedXmr()
    {
        return (string) $this->getData('detected_xmr');
    }

    public function getRemainingXmr()
    {
        return (string) $this->getData('remaining_xmr');
    }

    public function getConfirmationsRequired()
    {
        return (int) $this->getData('confirmations_required');
    }

    public function getState()
    {
        return (string) $this->getData('state');
    }

    public function isPaid()
    {
        return in_array($this->getState(), array('paid', 'overpaid'), true);
    }

    public function isPartial()
    {
        return $this->getState() === 'partial';
    }

    public function isDetected()
    {
        return $this->getState() === 'detected';
    }

    public function isExpired()
    {
        return $this->getState() === 'expired';
    }

    public function getMoneroUri()
    {
        return (string) $this->getData('monero_uri');
    }

    public function getSuccessUrl()
    {
        return (string) $this->getData('success_url');
    }

    public function getStatusUrl()
    {
        return (string) $this->getData('status_url');
    }
}
