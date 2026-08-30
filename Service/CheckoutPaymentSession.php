<?php

namespace MoneroIntegrations\Custompayment\Service;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Sales\Model\Order;
use MoneroIntegrations\Custompayment\Model\PaymentRecord;

/**
 * Binds Monero payment_ids to the buyer's checkout session so the status endpoint can authorize polling.
 */
class CheckoutPaymentSession
{
    const SESSION_KEY = 'monero_payment_ids';
    const MAX_AGE_SECONDS = 7 * 24 * 60 * 60;
    const MAX_REMEMBERED = 20;

    private $checkoutSession;

    public function __construct(CheckoutSession $checkoutSession)
    {
        $this->checkoutSession = $checkoutSession;
    }

    public function remember(PaymentRecord $record, Order $order)
    {
        $paymentId = $record->getPaymentId();
        if ($paymentId <= 0) {
            return;
        }

        $records = $this->getRecords();
        $records[$paymentId] = array(
            'order_id' => (int) $order->getEntityId(),
            'quote_id' => (int) $order->getQuoteId(),
            'created_at' => time()
        );

        $this->checkoutSession->setData(self::SESSION_KEY, $this->prune($records));
    }

    public function hasPaymentId($paymentId)
    {
        $records = $this->getRecords();
        return isset($records[(int) $paymentId]);
    }

    public function isAllowed(PaymentRecord $record, Order $order)
    {
        $records = $this->getRecords();
        $paymentId = $record->getPaymentId();
        if (!isset($records[$paymentId])) {
            return false;
        }

        $stored = $records[$paymentId];
        if ((int) $stored['order_id'] !== $record->getOrderId()) {
            return false;
        }

        $storedQuoteId = (int) $stored['quote_id'];
        return $storedQuoteId <= 0 || $storedQuoteId === (int) $order->getQuoteId();
    }

    private function getRecords()
    {
        $sessionRecords = $this->checkoutSession->getData(self::SESSION_KEY);
        if (!is_array($sessionRecords)) {
            return array();
        }

        $records = array();
        foreach ($sessionRecords as $key => $value) {
            if (is_array($value)) {
                $paymentId = (int) $key;
                $records[$paymentId] = array(
                    'order_id' => isset($value['order_id']) ? (int) $value['order_id'] : 0,
                    'quote_id' => isset($value['quote_id']) ? (int) $value['quote_id'] : 0,
                    'created_at' => isset($value['created_at']) ? (int) $value['created_at'] : time()
                );
                continue;
            }

            $paymentId = (int) $value;
            if ($paymentId > 0) {
                $records[$paymentId] = array(
                    'order_id' => 0,
                    'quote_id' => 0,
                    'created_at' => time()
                );
            }
        }

        return $this->prune($records);
    }

    private function prune(array $records)
    {
        $now = time();
        foreach ($records as $paymentId => $record) {
            if ((int) $paymentId <= 0 || $now - (int) $record['created_at'] > self::MAX_AGE_SECONDS) {
                unset($records[$paymentId]);
            }
        }

        if (count($records) > self::MAX_REMEMBERED) {
            $records = array_slice($records, -self::MAX_REMEMBERED, null, true);
        }

        return $records;
    }
}
