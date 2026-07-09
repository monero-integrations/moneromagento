<?php

namespace MoneroIntegrations\Custompayment\Controller\Gateway;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use MoneroIntegrations\Custompayment\Model\PaymentMethod;
use MoneroIntegrations\Custompayment\Model\Wallet\ClientFactory;
use MoneroIntegrations\Custompayment\Service\CheckoutPaymentSession;
use MoneroIntegrations\Custompayment\Service\PaymentRecordService;
use MoneroIntegrations\Custompayment\Service\PaymentSettlementService;
use Psr\Log\LoggerInterface;

/**
 * JSON status endpoint polled by the payment page: re-runs settlement and returns the current payment state.
 */
class Status extends Action implements HttpGetActionInterface
{
    private $clientFactory;
    private $checkoutPaymentSession;
    private $paymentRecordService;
    private $paymentSettlementService;
    private $orderRepository;
    private $checkoutSession;
    private $urlBuilder;
    private $logger;

    public function __construct(
        ClientFactory $clientFactory,
        CheckoutPaymentSession $checkoutPaymentSession,
        PaymentRecordService $paymentRecordService,
        PaymentSettlementService $paymentSettlementService,
        OrderRepositoryInterface $orderRepository,
        CheckoutSession $checkoutSession,
        UrlInterface $urlBuilder,
        LoggerInterface $logger,
        Context $context
    ) {
        $this->clientFactory = $clientFactory;
        $this->checkoutPaymentSession = $checkoutPaymentSession;
        $this->paymentRecordService = $paymentRecordService;
        $this->paymentSettlementService = $paymentSettlementService;
        $this->orderRepository = $orderRepository;
        $this->checkoutSession = $checkoutSession;
        $this->urlBuilder = $urlBuilder;
        $this->logger = $logger;
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
        $result->setHeader('Pragma', 'no-cache', true);

        $rawPaymentId = $this->getRequest()->getParam('payment_id');
        $paymentId = is_scalar($rawPaymentId) && ctype_digit((string) $rawPaymentId) ? (int) $rawPaymentId : 0;
        if ($paymentId <= 0 || !$this->checkoutPaymentSession->hasPaymentId($paymentId)) {
            return $result->setData(array('is_error' => true));
        }

        try {
            $record = $this->paymentRecordService->getByPaymentId($paymentId);
            $order = $this->orderRepository->get($record->getOrderId());
            if (!$this->checkoutPaymentSession->isAllowed($record, $order)) {
                return $result->setData(array('is_error' => true));
            }
            if (!$order->getPayment() || $order->getPayment()->getMethod() !== PaymentMethod::METHOD_CODE) {
                return $result->setData(array('is_error' => true));
            }

            $monero = $this->clientFactory->create((int) $order->getStoreId());
            $status = $this->paymentSettlementService->settle($order, $record, $monero);
            if (!empty($status['paid'])) {
                $this->restoreSuccessSession($order);
            }

            return $result->setData($this->buildStatusData($order, $record, $status, $monero));
        } catch (\Throwable $exception) {
            $this->logger->error('Unable to refresh Monero payment status.', array(
                'exception' => $exception,
                'payment_id' => $paymentId
            ));
            return $result->setData(array('is_error' => true));
        }
    }

    private function buildStatusData(Order $order, $record, array $status, $monero)
    {
        return array(
            'is_error' => false,
            'paid' => !empty($status['paid']),
            'state' => $status['state'],
            'received_xmr' => $monero->atomicUnitsToXmr($status['received_atomic']),
            'remaining_xmr' => $monero->atomicUnitsToXmr($status['remaining_atomic']),
            'monero_uri' => (empty($status['paid']) && $status['state'] !== 'detected')
                ? $monero->buildPaymentUri(
                    $record->getSubaddress(),
                    $status['remaining_atomic'],
                    'Order ' . $order->getIncrementId()
                )
                : '',
            'success_url' => $this->urlBuilder->getUrl('checkout/onepage/success')
        );
    }

    private function restoreSuccessSession(Order $order)
    {
        $this->checkoutSession->setLastQuoteId((int) $order->getQuoteId());
        $this->checkoutSession->setLastSuccessQuoteId((int) $order->getQuoteId());
        $this->checkoutSession->setLastOrderId((int) $order->getEntityId());
        $this->checkoutSession->setLastRealOrderId((string) $order->getIncrementId());
    }

}
