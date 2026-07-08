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

class MoneroPayment extends Action implements HttpGetActionInterface
{
    private $checkoutSession;
    private $clientFactory;
    private $checkoutPaymentSession;
    private $paymentRecordService;
    private $paymentSettlementService;
    private $orderRepository;
    private $urlBuilder;
    private $logger;

    public function __construct(
        CheckoutSession $checkoutSession,
        ClientFactory $clientFactory,
        CheckoutPaymentSession $checkoutPaymentSession,
        PaymentRecordService $paymentRecordService,
        PaymentSettlementService $paymentSettlementService,
        OrderRepositoryInterface $orderRepository,
        UrlInterface $urlBuilder,
        LoggerInterface $logger,
        Context $context
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->clientFactory = $clientFactory;
        $this->checkoutPaymentSession = $checkoutPaymentSession;
        $this->paymentRecordService = $paymentRecordService;
        $this->paymentSettlementService = $paymentSettlementService;
        $this->orderRepository = $orderRepository;
        $this->urlBuilder = $urlBuilder;
        $this->logger = $logger;
        parent::__construct($context);
    }

    public function execute()
    {
        $paymentId = $this->readPaymentId();
        $record = null;
        $order = null;

        try {
            if ($paymentId > 0) {
                $record = $this->paymentRecordService->getByPaymentId($paymentId);
                $order = $this->orderRepository->get($record->getOrderId());
                if (!$this->isAllowedOrder($order) || !$this->checkoutPaymentSession->isAllowed($record, $order)) {
                    return $this->resultRedirectFactory->create()->setPath('checkout/cart');
                }
            } else {
                $order = $this->checkoutSession->getLastRealOrder();
                if (!$this->isAllowedOrder($order)) {
                    return $this->resultRedirectFactory->create()->setPath('checkout/cart');
                }
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('Unable to resolve the Monero payment order.', array(
                'exception' => $exception,
                'payment_id' => $paymentId
            ));

            return $this->resultRedirectFactory->create()->setPath('checkout/cart');
        }

        $result = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
        $result->setHeader('Pragma', 'no-cache', true);
        $block = $result->getLayout()->getBlock('monero.payment');
        if (!$block) {
            return $this->resultRedirectFactory->create()->setPath('checkout/cart');
        }

        try {
            $monero = $this->clientFactory->create((int) $order->getStoreId());
            $record = $record ?: $this->paymentRecordService->getOrCreate($order, $monero);
            $this->checkoutPaymentSession->remember($record, $order);
            if ($paymentId !== $record->getPaymentId()) {
                return $this->resultRedirectFactory->create()->setPath(
                    'moneropayment/Gateway/MoneroPayment',
                    array('_query' => array('payment_id' => $record->getPaymentId()))
                );
            }
            $status = $this->paymentSettlementService->settle($order, $record, $monero);

            $block->addData($this->buildViewData($order, $record, $status, $monero));
        } catch (\Throwable $exception) {
            $this->logger->error('Unable to prepare Monero payment.', array(
                'exception' => $exception,
                'order_id' => $order->getId()
            ));
            $block->setData('is_error', true);
            $block->setData('increment_id', $order->getIncrementId());
        }

        return $result;
    }

    private function readPaymentId()
    {
        $raw = $this->getRequest()->getParam('payment_id');

        return is_scalar($raw) && ctype_digit((string) $raw) ? (int) $raw : 0;
    }

    private function isAllowedOrder($order)
    {
        return $order
            && $order->getId()
            && $order->getPayment()
            && $order->getPayment()->getMethod() === PaymentMethod::METHOD_CODE;
    }

    private function buildViewData(Order $order, $record, array $status, $monero)
    {
        return array(
            'is_error' => false,
            'increment_id' => $order->getIncrementId(),
            'subaddress' => $record->getSubaddress(),
            'amount_xmr' => $record->getAmountXmr(),
            'received_xmr' => $monero->atomicUnitsToXmr($status['received_atomic']),
            'remaining_xmr' => $monero->atomicUnitsToXmr($status['remaining_atomic']),
            'confirmations_required' => $record->getConfirmationsRequired(),
            'state' => $status['state'],
            'monero_uri' => empty($status['paid'])
                ? $monero->buildPaymentUri(
                    $record->getSubaddress(),
                    $status['remaining_atomic'],
                    'Order ' . $order->getIncrementId()
                )
                : '',
            'status_url' => $this->urlBuilder->getUrl(
                'moneropayment/Gateway/Status',
                array('_query' => array('payment_id' => $record->getPaymentId()))
            ),
            'success_url' => $this->urlBuilder->getUrl('checkout/onepage/success')
        );
    }

}
