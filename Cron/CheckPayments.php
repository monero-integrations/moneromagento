<?php

namespace MoneroIntegrations\Custompayment\Cron;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use MoneroIntegrations\Custompayment\Model\PaymentRecord;
use MoneroIntegrations\Custompayment\Model\ResourceModel\PaymentRecord\CollectionFactory as PaymentRecordCollectionFactory;
use MoneroIntegrations\Custompayment\Model\Wallet\ClientFactory;
use MoneroIntegrations\Custompayment\Service\PaymentRecordService;
use MoneroIntegrations\Custompayment\Service\PaymentSettlementService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Cron job that settles pending Monero payments and repairs paid-but-unsynced orders.
 */
class CheckPayments
{
    private $recordCollectionFactory;
    private $clientFactory;
    private $recordService;
    private $settlementService;
    private $orderRepository;
    private $logger;

    public function __construct(
        PaymentRecordCollectionFactory $recordCollectionFactory,
        ClientFactory $clientFactory,
        PaymentRecordService $recordService,
        PaymentSettlementService $settlementService,
        OrderRepositoryInterface $orderRepository,
        LoggerInterface $logger
    ) {
        $this->recordCollectionFactory = $recordCollectionFactory;
        $this->clientFactory = $clientFactory;
        $this->recordService = $recordService;
        $this->settlementService = $settlementService;
        $this->orderRepository = $orderRepository;
        $this->logger = $logger;
    }

    public function execute()
    {
        foreach (array_chunk($this->getPayableIds(), 50) as $chunk) {
            $batch = $this->recordCollectionFactory->create();
            $batch->addFieldToFilter('payment_id', array('in' => $chunk));

            foreach ($batch as $record) {
                $this->settleRecord($record);
            }
        }
    }

    private function getPayableIds()
    {
        $collection = $this->recordCollectionFactory->create();
        $connection = $collection->getConnection();
        $collection->getSelect()->where(
            '(status = ' . $connection->quote(PaymentRecord::STATUS_PENDING)
            . ' OR (status = ' . $connection->quote(PaymentRecord::STATUS_PAID) . ' AND order_synced = 0))'
        );
        return $collection->getAllIds();
    }

    private function settleRecord(PaymentRecord $record)
    {
        try {
            $order = $this->orderRepository->get($record->getOrderId());
            if ($record->getStatus() === PaymentRecord::STATUS_PAID) {
                $this->settlementService->syncPaidRecord($order, $record);
                return;
            }

            $monero = $this->clientFactory->create($record->getStoreId() ?: (int) $order->getStoreId());
            if (!$record->hasPaymentDetails()) {
                $record = $this->recordService->getOrCreate($order, $monero);
            }
            $this->settlementService->settle($order, $record, $monero);
        } catch (NoSuchEntityException $exception) {
            $this->logger->warning('Monero payment references a missing order.', array(
                'payment_id' => $record->getPaymentId(),
                'order_id' => $record->getOrderId()
            ));
        } catch (Throwable $exception) {
            $this->logger->error('Unable to settle Monero payment from cron.', array(
                'exception' => $exception,
                'payment_id' => $record->getPaymentId(),
                'order_id' => $record->getOrderId()
            ));
        }
    }
}
