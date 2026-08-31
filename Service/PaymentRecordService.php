<?php

namespace MoneroIntegrations\Custompayment\Service;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Model\Order;
use MoneroIntegrations\Custompayment\Helper\Data;
use MoneroIntegrations\Custompayment\Model\PaymentRecord;
use MoneroIntegrations\Custompayment\Model\PaymentRecordFactory;
use MoneroIntegrations\Custompayment\Model\ResourceModel\PaymentRecord as PaymentRecordResource;
use MoneroIntegrations\Custompayment\Model\Wallet\MoneroClient;
use Throwable;

/**
 * Creates and loads the one Monero payment record per order, allocating a subaddress under a lock.
 */
class PaymentRecordService
{
    const MAX_CONFIRMATIONS = 1000;
    const MAX_PAYMENT_WINDOW_MINUTES = 10080;

    private $helper;
    private $recordFactory;
    private $recordResource;
    private $resourceConnection;
    private $lock;

    public function __construct(
        Data $helper,
        PaymentRecordFactory $recordFactory,
        PaymentRecordResource $recordResource,
        ResourceConnection $resourceConnection,
        AdvisoryLock $lock
    ) {
        $this->helper = $helper;
        $this->recordFactory = $recordFactory;
        $this->recordResource = $recordResource;
        $this->resourceConnection = $resourceConnection;
        $this->lock = $lock;
    }

    public function getOrCreate(Order $order, MoneroClient $monero)
    {
        if (!$order->getId()) {
            throw new LocalizedException(__('Cannot create a Monero payment for an unsaved order.'));
        }

        $record = $this->ensureRecordExists($order);
        if (!$record->hasPaymentDetails()) {
            $lockName = 'monero_payment_' . (int) $order->getEntityId();
            $this->lock->acquire($lockName);
            try {
                $record = $this->getByOrderId((int) $order->getEntityId());
                if (!$record->hasPaymentDetails()) {
                    $this->addPaymentDetails($record, $order, $monero);
                    $this->recordResource->save($record);
                }
            } finally {
                $this->lock->release($lockName);
            }
        }

        return $record;
    }

    public function getByOrderId($orderId)
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->recordResource->getMainTable();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($table)
                ->where('order_id = ?', (int) $orderId)
        );

        if (!$row) {
            throw new NoSuchEntityException(__('Monero payment record does not exist.'));
        }

        $record = $this->recordFactory->create();
        $record->setData($row);
        return $record;
    }

    public function getByPaymentId($paymentId)
    {
        $record = $this->recordFactory->create();
        $this->recordResource->load($record, (int) $paymentId);
        if (!$record->getId()) {
            throw new NoSuchEntityException(__('Monero payment record does not exist.'));
        }

        return $record;
    }

    private function ensureRecordExists(Order $order)
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->beginTransaction();

        try {
            $record = $this->loadForUpdate((int) $order->getEntityId(), $connection);
            if (!$record->getId()) {
                $record = $this->createEmptyRecord($order);
                $this->recordResource->save($record);
            }
            $connection->commit();
        } catch (AlreadyExistsException $exception) {
            $connection->rollBack();
            return $this->getByOrderId((int) $order->getEntityId());
        } catch (Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }

        return $record;
    }

    private function loadForUpdate($orderId, AdapterInterface $connection)
    {
        $table = $this->recordResource->getMainTable();
        $select = $connection->select()
            ->from($table)
            ->where('order_id = ?', (int) $orderId);
        $select->forUpdate(true);

        $record = $this->recordFactory->create();
        $row = $connection->fetchRow($select);
        if ($row) {
            $record->setData($row);
        }

        return $record;
    }

    private function createEmptyRecord(Order $order)
    {
        $record = $this->recordFactory->create();
        $record->setOrderId((int) $order->getEntityId());
        $record->setStoreId((int) $order->getStoreId());
        $record->setIncrementId((string) $order->getIncrementId());
        $record->setOrderCurrency((string) $order->getOrderCurrencyCode());
        $record->setConfirmationsRequired($this->getConfiguredConfirmations((int) $order->getStoreId()));
        $record->setStatus(PaymentRecord::STATUS_PENDING);
        $record->setAmountAtomic(0);
        $record->setTotalReceivedAtomic(0);
        $record->setOrderSynced(false);

        return $record;
    }

    private function addPaymentDetails(PaymentRecord $record, Order $order, MoneroClient $monero)
    {
        $currency = $record->getOrderCurrency() ?: (string) $order->getOrderCurrencyCode();
        $atomic = $monero->fiatToAtomicUnits($this->canonicalGrandTotal($order), $currency);

        $record->setOrderCurrency($currency);
        $record->setAmountAtomic($atomic);
        $record->setAmountXmr($monero->atomicUnitsToXmr($atomic));
        if ($record->getSubaddress() === '') {
            $record->setSubaddress($monero->createSubaddress('Magento order ' . $order->getIncrementId()));
        }
        $record->setExpiresAt(gmdate(
            'Y-m-d H:i:s',
            time() + ($this->getConfiguredPaymentWindow((int) $order->getStoreId()) * 60)
        ));
    }

    private function getConfiguredConfirmations($storeId)
    {
        $value = trim((string) $this->helper->getConfig('payment/custompayment/num_confirmations', $storeId));
        if ($value === '') {
            return 5;
        }
        if (!ctype_digit($value)) {
            throw new LocalizedException(
                __('The configured number of Monero confirmations must be a non-negative integer.')
            );
        }

        $confirmations = (int) $value;
        if ($confirmations > self::MAX_CONFIRMATIONS) {
            throw new LocalizedException(
                __('The configured number of Monero confirmations is unreasonably high (max %1).', self::MAX_CONFIRMATIONS)
            );
        }

        return $confirmations;
    }

    private function getConfiguredPaymentWindow($storeId)
    {
        $value = trim((string) $this->helper->getConfig('payment/custompayment/payment_window_minutes', $storeId));
        if ($value === '') {
            return 60;
        }
        if (!ctype_digit($value) || (int) $value < 1 || (int) $value > self::MAX_PAYMENT_WINDOW_MINUTES) {
            throw new LocalizedException(
                __('The configured Monero payment window must be between 1 and %1 minutes.', self::MAX_PAYMENT_WINDOW_MINUTES)
            );
        }

        return (int) $value;
    }

    private function canonicalGrandTotal(Order $order)
    {
        $raw = $order->getData('grand_total');
        if (is_string($raw) && preg_match('/^\d+(\.\d+)?$/', $raw)) {
            return $raw;
        }

        return number_format((float) $order->getGrandTotal(), 4, '.', '');
    }
}
