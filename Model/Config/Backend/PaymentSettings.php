<?php

namespace MoneroIntegrations\Custompayment\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use MoneroIntegrations\Custompayment\Model\Wallet\RpcValidator;
use MoneroIntegrations\Custompayment\Service\PaymentRecordService;

/**
 * Admin config backend that validates wallet-RPC, pricing and confirmation settings on save.
 */
class PaymentSettings extends Value
{
    public function beforeSave()
    {
        $manualRate = trim(str_replace(',', '.', (string) $this->getSiblingValue('manual_xmr_rate')));
        $manualCurrency = strtoupper(trim((string) $this->getSiblingValue('manual_xmr_rate_currency')));

        if ($manualRate !== '') {
            if (!$this->isPositiveDecimal($manualRate)) {
                throw new LocalizedException(__('Manual XMR Rate must be a positive decimal number.'));
            }
            if (!preg_match('/^[A-Z]{3}$/', $manualCurrency)) {
                throw new LocalizedException(
                    __('Manual XMR Rate Currency must be a three-letter code when Manual XMR Rate is set.')
                );
            }
        }

        $confirmations = trim((string) $this->getSiblingValue('num_confirmations'));
        if ($confirmations !== '' && (!ctype_digit($confirmations) || (int) $confirmations > PaymentRecordService::MAX_CONFIRMATIONS)) {
            throw new LocalizedException(
                __('Number of confirmations must be an integer between 0 and %1.', PaymentRecordService::MAX_CONFIRMATIONS)
            );
        }

        $paymentWindow = trim((string) $this->getSiblingValue('payment_window_minutes'));
        if ($paymentWindow !== '' && (!ctype_digit($paymentWindow) || (int) $paymentWindow < 1
            || (int) $paymentWindow > PaymentRecordService::MAX_PAYMENT_WINDOW_MINUTES)) {
            throw new LocalizedException(
                __('Payment Window must be an integer between 1 and %1 minutes.', PaymentRecordService::MAX_PAYMENT_WINDOW_MINUTES)
            );
        }

        if ((string) $this->getSiblingValue('active') === '1') {
            $rpcAddress = trim((string) $this->getSiblingValue('rpc_address'));
            $rpcPort = trim((string) $this->getSiblingValue('rpc_port'));

            if (!RpcValidator::isValidHost($rpcAddress)) {
                throw new LocalizedException(__('Wallet-RPC Host must be a host name or IP address.'));
            }
            if (!RpcValidator::isValidPort($rpcPort)) {
                throw new LocalizedException(__('Wallet-RPC Port must be between 1 and 65535.'));
            }
            $rpcUsername = trim((string) $this->getSiblingValue('rpc_username'));
            $rpcPassword = trim((string) $this->getSiblingValue('rpc_password'));
            if (($rpcUsername === '') !== ($rpcPassword === '')) {
                throw new LocalizedException(__('Wallet-RPC username and password must be configured together.'));
            }
            if (!RpcValidator::isLoopbackHost($rpcAddress)) {
                if ((string) $this->getSiblingValue('rpc_use_https') !== '1') {
                    throw new LocalizedException(__('A remote Wallet-RPC host requires HTTPS.'));
                }
                if ($rpcUsername === '') {
                    throw new LocalizedException(__('A remote Wallet-RPC host requires a username and password.'));
                }
            }
        }

        return parent::beforeSave();
    }

    private function getSiblingValue($key)
    {
        $value = $this->getFieldsetDataValue($key);
        if ($value === null && substr((string) $this->getPath(), -strlen('/' . $key)) === '/' . $key) {
            $value = $this->getValue();
        }
        if ($value === null) {
            $value = $this->_config->getValue(
                $this->getSiblingPath($key),
                $this->getScope() ?: ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
                $this->getScopeCode()
            );
        }
        if (is_array($value)) {
            return '';
        }

        return $value;
    }

    private function getSiblingPath($key)
    {
        $path = (string) $this->getPath();
        $lastSlash = strrpos($path, '/');
        if ($lastSlash === false) {
            return $key;
        }

        return substr($path, 0, $lastSlash + 1) . $key;
    }

    private function isPositiveDecimal($value)
    {
        return (bool) preg_match('/^\d+(\.\d+)?$/', $value)
            && (bool) preg_match('/[1-9]/', $value);
    }
}
