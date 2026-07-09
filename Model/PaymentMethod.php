<?php

namespace MoneroIntegrations\Custompayment\Model;

use Magento\Payment\Model\InfoInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;
use MoneroIntegrations\Custompayment\Model\Wallet\RpcValidator;

/**
 * Monero offline payment method; hidden at checkout until the wallet RPC host and port are configured.
 */
class PaymentMethod extends \Magento\Payment\Model\Method\AbstractMethod
{
    const METHOD_CODE = 'custompayment';

    protected $_code = self::METHOD_CODE;

    protected $_isOffline = false;

    protected $_isGateway = true;

    protected $_canAuthorize = true;

    protected $_isInitializeNeeded = true;

    public function authorize(InfoInterface $payment, $amount)
    {
        $payment->setIsTransactionPending(true);
        return $this;
    }

    public function initialize($paymentAction, $stateObject)
    {
        $stateObject->setState(Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus(Order::STATE_PENDING_PAYMENT);
        $stateObject->setIsNotified(false);

        return $this;
    }

    public function isAvailable(?CartInterface $quote = null)
    {
        if (!parent::isAvailable($quote)) {
            return false;
        }

        $storeId = $quote ? $quote->getStoreId() : null;
        $host = trim((string) $this->getMoneroConfig('rpc_address', $storeId));
        $port = trim((string) $this->getMoneroConfig('rpc_port', $storeId));

        if (!RpcValidator::isValidHost($host) || !RpcValidator::isValidPort($port)) {
            return false;
        }
        if (!RpcValidator::isLoopbackHost($host)) {
            if (!$this->getMoneroConfig('rpc_use_https', $storeId)) {
                return false;
            }
            $username = trim((string) $this->getMoneroConfig('rpc_username', $storeId));
            $password = trim((string) $this->getMoneroConfig('rpc_password', $storeId));
            if ($username === '' || $password === '') {
                return false;
            }
        }

        return true;
    }

    private function getMoneroConfig($field, $storeId)
    {
        return $this->_scopeConfig->getValue(
            'payment/' . self::METHOD_CODE . '/' . $field,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}
