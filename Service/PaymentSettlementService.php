<?php

namespace MoneroIntegrations\Custompayment\Service;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\TransactionFactory as DbTransactionFactory;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Magento\Sales\Model\Service\InvoiceService;
use MoneroIntegrations\Custompayment\Model\PaymentRecord;
use MoneroIntegrations\Custompayment\Model\PaymentTransactionFactory;
use MoneroIntegrations\Custompayment\Model\ResourceModel\PaymentRecord as PaymentRecordResource;
use MoneroIntegrations\Custompayment\Model\ResourceModel\PaymentTransaction as PaymentTransactionResource;
use MoneroIntegrations\Custompayment\Model\Wallet\MoneroClient;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Verifies wallet transfers and settles the Magento order (mark paid, invoice, cron repair) idempotently and locked.
 */
class PaymentSettlementService
{
    private $transactionFactory;
    private $transactionResource;
    private $recordResource;
    private $resourceConnection;
    private $dbTransactionFactory;
    private $orderFactory;
    private $orderResource;
    private $invoiceService;
    private $lock;
    private $logger;

    public function __construct(
        PaymentTransactionFactory $transactionFactory,
        PaymentTransactionResource $transactionResource,
        PaymentRecordResource $recordResource,
        ResourceConnection $resourceConnection,
        DbTransactionFactory $dbTransactionFactory,
        OrderFactory $orderFactory,
        OrderResource $orderResource,
        InvoiceService $invoiceService,
        AdvisoryLock $lock,
        LoggerInterface $logger
    ) {
        $this->transactionFactory = $transactionFactory;
        $this->transactionResource = $transactionResource;
        $this->recordResource = $recordResource;
        $this->resourceConnection = $resourceConnection;
        $this->dbTransactionFactory = $dbTransactionFactory;
        $this->orderFactory = $orderFactory;
        $this->orderResource = $orderResource;
        $this->invoiceService = $invoiceService;
        $this->lock = $lock;
        $this->logger = $logger;
    }

    public function settle(Order $order, PaymentRecord $record, MoneroClient $monero)
    {
        return $this->withSettlementLock($record, function () use ($order, $record, $monero) {
            $this->reloadRecord($record);
            $order = $this->reloadOrder($order, $record);

            return $this->settleLocked($order, $record, $monero);
        });
    }

    private function settleLocked(Order $order, PaymentRecord $record, MoneroClient $monero)
    {
        $this->assertPaymentDetails($record);
        $expected = (int) $record->getAmountAtomic();
        $detectedReceived = 0;

        if ($record->getStatus() !== PaymentRecord::STATUS_PAID) {
            try {
                $verification = $monero->verifyPayment(
                    $record->getSubaddress(),
                    $expected,
                    $record->getConfirmationsRequired()
                );
            } catch (Throwable $rpcException) {
                $this->logger->warning('Monero wallet RPC unavailable during settlement.', array(
                    'payment_id' => $record->getPaymentId(),
                    'order_id' => $record->getOrderId()
                ));
                throw $rpcException;
            }
            $this->storeTransfers($record, $verification['transfers']);

            $received = (int) $verification['total_received_atomic'];
            $detectedReceived = (int) $verification['detected_received_atomic'];
            $txids = $verification['txids'];

            if ($received >= $expected) {
                $this->markPaid($order, $record, $received, $txids);
            } else {
                $record->setTotalReceivedAtomic($received);
                $this->recordResource->save($record);
            }
        } else {
            $received = (int) $record->getTotalReceivedAtomic();
            $txids = $this->getStoredTxids($record);
            $this->applyPaidSync($order, $record, $received, $txids, false);
        }

        return $this->buildStatus($record, $expected, $received, $txids, $detectedReceived);
    }

    private function buildStatus(PaymentRecord $record, $expected, $received, array $txids, $detectedReceived = 0)
    {
        $paid = $record->getStatus() === PaymentRecord::STATUS_PAID;
        $remaining = max(0, $expected - $received);
        if ($paid && $received > $expected) {
            $state = 'overpaid';
        } elseif ($paid) {
            $state = 'paid';
        } elseif ($detectedReceived > 0 && $received + $detectedReceived >= $expected) {
            $state = 'detected';
        } elseif ($received > 0) {
            $state = 'partial';
        } else {
            $state = 'pending';
        }

        return array(
            'paid' => $paid,
            'state' => $state,
            'expected_atomic' => $expected,
            'received_atomic' => $received,
            'detected_received_atomic' => (int) $detectedReceived,
            'remaining_atomic' => $remaining,
            'txids' => $txids
        );
    }

    private function storeTransfers(PaymentRecord $record, array $transfers)
    {
        if (empty($transfers)) {
            return;
        }

        $stored = array_flip($this->getStoredTxids($record));
        foreach ($transfers as $transfer) {
            if (isset($stored[$transfer['txid']])) {
                continue;
            }

            $transaction = $this->transactionFactory->create();
            $transaction->setPaymentId($record->getPaymentId());
            $transaction->setTxid($transfer['txid']);
            $transaction->setAmountAtomic($transfer['amount']);
            $transaction->setBlockHeight($transfer['height']);
            $transaction->setConfirmations((int) $transfer['confirmations']);

            try {
                $this->transactionResource->save($transaction);
                $stored[$transfer['txid']] = true;
            } catch (AlreadyExistsException $exception) {
                $this->logger->debug('Duplicate Monero transfer ignored.', array(
                    'payment_id' => $record->getPaymentId(),
                    'txid' => $transfer['txid']
                ));
            }
        }
    }

    private function getStoredTxids(PaymentRecord $record)
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->transactionResource->getMainTable();

        return $connection->fetchCol(
            $connection->select()
                ->from($table, 'txid')
                ->where('payment_id = ?', $record->getPaymentId())
                ->order('transaction_id ASC')
        );
    }

    private function markPaid(Order $order, PaymentRecord $record, $total, array $txids)
    {
        $markedPaid = $this->markRecordPaid($record, $total);
        if (!$markedPaid) {
            $total = (int) $record->getTotalReceivedAtomic();
            $txids = $this->getStoredTxids($record);
        }
        $this->applyPaidSync($order, $record, $total, $txids, $markedPaid);
    }

    public function syncPaidRecord(Order $order, PaymentRecord $record, $total = null, ?array $txids = null, $registerPayment = false)
    {
        return $this->withSettlementLock($record, function () use ($order, $record, $total, $txids, $registerPayment) {
            $this->reloadRecord($record);
            $order = $this->reloadOrder($order, $record);

            return $this->applyPaidSync($order, $record, $total, $txids, $registerPayment);
        });
    }

    private function applyPaidSync(Order $order, PaymentRecord $record, $total = null, ?array $txids = null, $registerPayment = false)
    {
        if ($record->getStatus() !== PaymentRecord::STATUS_PAID) {
            return;
        }
        $this->assertPaymentDetails($record);
        if ($record->getOrderSynced()) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $connection->beginTransaction();
        try {
            $this->lockOrderRow($record);
            $this->reloadRecord($record);
            if ($record->getStatus() !== PaymentRecord::STATUS_PAID || $record->getOrderSynced()) {
                $connection->commit();
                return;
            }

            $order = $this->reloadOrder($order, $record);
            if ($total === null) {
                $total = (int) $record->getTotalReceivedAtomic();
            }
            if ($txids === null) {
                $txids = $this->getStoredTxids($record);
            }

            $this->applyPaymentInformation($order, $total, $txids);

            $invoice = null;
            $orderSynced = true;
            if ($this->isTerminalOrder($order)) {
                $this->flagTerminalPaidOrder($order, $record, $total, $txids);
            } elseif ($this->isTemporarilyNonInvoiceableOrder($order)) {
                $orderSynced = false;
            } else {
                $invoice = $this->prepareInvoice($order);
                if ($invoice === null) {
                    $invoice = $this->prepareExistingInvoicePayment($order);
                }
                if ($invoice !== null) {
                    $order->setState(Order::STATE_PROCESSING);
                    $order->setStatus($order->getConfig()->getStateDefaultStatus(Order::STATE_PROCESSING));

                    if ($registerPayment) {
                        $order->addCommentToStatusHistory('Monero payment received.', false, false);
                    }
                } elseif ($order->hasInvoices()) {
                    if (!$this->hasPaidFullInvoice($order)) {
                        $this->flagNonInvoiceablePaidOrder($order, $record, $total, $txids);
                    }
                } else {
                    $this->flagNonInvoiceablePaidOrder($order, $record, $total, $txids);
                }
            }

            $transaction = $this->dbTransactionFactory->create();
            if ($invoice !== null) {
                $transaction->addObject($invoice);
            }
            $transaction->addObject($order);
            $transaction->save();

            if ($orderSynced) {
                $this->markOrderSynced($record);
            }
            $connection->commit();
        } catch (Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
    }

    private function applyPaymentInformation(Order $order, $total, array $txids)
    {
        $payment = $order->getPayment();
        if ($payment) {
            if (!empty($txids)) {
                $payment->setTransactionId($txids[0]);
            }
            $payment->setIsTransactionPending(false);
            $payment->setIsTransactionClosed(true);
            $payment->setAdditionalInformation('monero_paid', true);
            $payment->setAdditionalInformation('monero_txids', $txids);
            $payment->setAdditionalInformation('monero_total_received_atomic', (int) $total);
        }
    }

    private function prepareInvoice(Order $order)
    {
        if (!$order->canInvoice()) {
            return null;
        }

        $invoice = $this->invoiceService->prepareInvoice($order);
        if (!$invoice->getTotalQty()) {
            return null;
        }

        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
        $invoice->register();

        return $invoice;
    }

    private function prepareExistingInvoicePayment(Order $order)
    {
        foreach ($order->getInvoiceCollection() as $invoice) {
            if ((int) $invoice->getState() !== Invoice::STATE_OPEN || !$this->isFullInvoice($order, $invoice)) {
                continue;
            }

            $invoice->pay();
            return $invoice;
        }

        return null;
    }

    private function hasPaidFullInvoice(Order $order)
    {
        foreach ($order->getInvoiceCollection() as $invoice) {
            if ((int) $invoice->getState() === Invoice::STATE_PAID && $this->isFullInvoice($order, $invoice)) {
                return true;
            }
        }

        return false;
    }

    private function isFullInvoice(Order $order, Invoice $invoice)
    {
        return (float) $invoice->getGrandTotal() >= (float) $order->getGrandTotal()
            && (float) $invoice->getBaseGrandTotal() >= (float) $order->getBaseGrandTotal();
    }

    private function markRecordPaid(PaymentRecord $record, $total)
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->recordResource->getMainTable();
        $paidAt = gmdate('Y-m-d H:i:s');
        $updated = $connection->update(
            $table,
            array(
                'status' => PaymentRecord::STATUS_PAID,
                'total_received_atomic' => (int) $total,
                'order_synced' => 0,
                'paid_at' => $paidAt
            ),
            array(
                'payment_id = ?' => $record->getPaymentId(),
                'status = ?' => PaymentRecord::STATUS_PENDING
            )
        );

        if (!$updated) {
            $this->reloadRecord($record);
            return false;
        }

        $record->setStatus(PaymentRecord::STATUS_PAID);
        $record->setTotalReceivedAtomic($total);
        $record->setPaidAt($paidAt);

        return true;
    }

    private function reloadRecord(PaymentRecord $record)
    {
        $connection = $this->resourceConnection->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->recordResource->getMainTable())
                ->where('payment_id = ?', $record->getPaymentId())
        );
        if ($row) {
            $record->addData($row);
        }
    }

    private function reloadOrder(Order $order, PaymentRecord $record)
    {
        $orderId = $record->getOrderId();
        if ($orderId <= 0) {
            return $order;
        }

        $freshOrder = $this->orderFactory->create();
        $this->orderResource->load($freshOrder, $orderId);
        if (!$freshOrder->getId()) {
            throw new RuntimeException('Cannot sync a Monero payment for a missing order.');
        }

        return $freshOrder;
    }

    private function lockOrderRow(PaymentRecord $record)
    {
        $orderId = $record->getOrderId();
        if ($orderId <= 0) {
            throw new RuntimeException('Cannot sync a Monero payment without an order id.');
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName('sales_order'), 'entity_id')
            ->where('entity_id = ?', $orderId)
            ->limit(1);
        $select->forUpdate(true);

        if (!$connection->fetchOne($select)) {
            throw new RuntimeException('Cannot sync a Monero payment for a missing order.');
        }
    }

    private function withSettlementLock(PaymentRecord $record, callable $callback)
    {
        $lockName = 'monero_settle_' . (int) $record->getOrderId();
        $this->lock->acquire($lockName);
        try {
            return $callback();
        } finally {
            $this->lock->release($lockName);
        }
    }

    private function isTerminalOrder(Order $order)
    {
        return in_array($order->getState(), array(
            Order::STATE_CANCELED,
            Order::STATE_CLOSED,
            Order::STATE_COMPLETE
        ), true);
    }

    private function isTemporarilyNonInvoiceableOrder(Order $order)
    {
        return in_array($order->getState(), array(
            Order::STATE_HOLDED,
            Order::STATE_PAYMENT_REVIEW
        ), true);
    }

    private function flagTerminalPaidOrder(Order $order, PaymentRecord $record, $total, array $txids)
    {
        $payment = $order->getPayment();
        if ($payment) {
            $payment->setAdditionalInformation('monero_manual_review_required', true);
            $payment->setAdditionalInformation('monero_terminal_state', $order->getState());
        }

        $order->addCommentToStatusHistory(
            'Monero payment received after the order was already in a terminal state. Manual review required.',
            false,
            false
        );
        $this->logger->warning('Monero payment received for a terminal order.', array(
            'payment_id' => $record->getPaymentId(),
            'order_id' => $record->getOrderId(),
            'order_state' => $order->getState(),
            'total_received_atomic' => (int) $total,
            'txids' => $txids
        ));
    }

    private function flagNonInvoiceablePaidOrder(Order $order, PaymentRecord $record, $total, array $txids)
    {
        $payment = $order->getPayment();
        if ($payment) {
            $payment->setAdditionalInformation('monero_manual_review_required', true);
            $payment->setAdditionalInformation('monero_non_invoiceable_state', $order->getState());
        }

        $order->addCommentToStatusHistory(
            'Monero payment received, but Magento could not create an invoice. Manual review required.',
            false,
            false
        );
        $this->logger->warning('Monero payment received for a non-invoiceable order.', array(
            'payment_id' => $record->getPaymentId(),
            'order_id' => $record->getOrderId(),
            'order_state' => $order->getState(),
            'total_received_atomic' => (int) $total,
            'txids' => $txids
        ));
    }

    private function markOrderSynced(PaymentRecord $record)
    {
        $connection = $this->resourceConnection->getConnection();
        $updated = $connection->update(
            $this->recordResource->getMainTable(),
            array('order_synced' => 1),
            array(
                'payment_id = ?' => $record->getPaymentId(),
                'status = ?' => PaymentRecord::STATUS_PAID,
                'order_synced = ?' => 0
            )
        );
        if ((int) $updated !== 1) {
            $this->reloadRecord($record);
            if ($record->getOrderSynced()) {
                return;
            }
            throw new RuntimeException('Unable to mark the Monero payment as synced.');
        }
        $record->setOrderSynced(true);
    }

    private function assertPaymentDetails(PaymentRecord $record)
    {
        if (!$record->hasPaymentDetails()) {
            throw new RuntimeException('Cannot settle a Monero payment without complete payment details.');
        }
    }

}
