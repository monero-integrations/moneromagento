<?php

namespace MoneroIntegrations\Custompayment\Model\Wallet;

use Magento\Framework\Encryption\EncryptorInterface;
use MoneroIntegrations\Custompayment\Helper\Data;
use RuntimeException;

/**
 * Builds a MoneroClient from validated per-store wallet-RPC and pricing configuration.
 */
class ClientFactory
{
    private $helper;
    private $encryptor;

    public function __construct(
        Data $helper,
        EncryptorInterface $encryptor
    ) {
        $this->helper = $helper;
        $this->encryptor = $encryptor;
    }

    public function create($storeId = null)
    {
        $rpcAddress = trim((string) $this->helper->getConfig('payment/custompayment/rpc_address', $storeId));
        $rpcPort = trim((string) $this->helper->getConfig('payment/custompayment/rpc_port', $storeId));
        $rpcUsername = trim((string) $this->helper->getConfig('payment/custompayment/rpc_username', $storeId));
        $rpcPassword = $this->getSecret('payment/custompayment/rpc_password', $storeId);
        $useHttps = (bool) $this->helper->getConfig('payment/custompayment/rpc_use_https', $storeId);

        if (!RpcValidator::isValidHost($rpcAddress)) {
            throw new RuntimeException('Invalid wallet RPC address.');
        }
        if (!RpcValidator::isValidPort($rpcPort)) {
            throw new RuntimeException('Invalid wallet RPC port.');
        }
        if (($rpcUsername === '') !== ($rpcPassword === '')) {
            throw new RuntimeException('Wallet RPC username and password must be configured together.');
        }
        if (!RpcValidator::isLoopbackHost($rpcAddress)) {
            if ($rpcUsername === '') {
                throw new RuntimeException('Remote wallet RPC requires username and password.');
            }
            if (!$useHttps) {
                throw new RuntimeException('Remote wallet RPC requires HTTPS. Use localhost or an SSH tunnel for plain HTTP.');
            }
        }

        return new MoneroClient(
            $rpcAddress,
            (int) $rpcPort,
            $rpcUsername,
            $rpcPassword,
            $this->getSecret('payment/custompayment/price_api_key', $storeId),
            $this->getManualPriceRate($storeId),
            $this->getManualPriceRateCurrency($storeId),
            $useHttps
        );
    }

    private function getSecret($path, $storeId = null)
    {
        $value = trim((string) $this->helper->getConfig($path, $storeId));
        if ($value === '') {
            return '';
        }

        try {
            $decrypted = (string) $this->encryptor->decrypt($value);
        } catch (\Exception $exception) {
            throw new RuntimeException('Unable to decrypt a configured Monero credential. Re-save it in the admin.');
        }

        if ($decrypted === '') {
            throw new RuntimeException('Unable to decrypt a configured Monero credential. Re-save it in the admin.');
        }

        return $decrypted;
    }

    private function getManualPriceRate($storeId = null)
    {
        $value = trim((string) $this->helper->getConfig('payment/custompayment/manual_xmr_rate', $storeId));
        if ($value === '') {
            return null;
        }

        $value = str_replace(',', '.', $value);
        if (!$this->isPositiveDecimal($value)) {
            throw new RuntimeException('Manual XMR price rate must be a positive number.');
        }

        return $value;
    }

    private function getManualPriceRateCurrency($storeId = null)
    {
        $value = strtoupper(trim((string) $this->helper->getConfig(
            'payment/custompayment/manual_xmr_rate_currency',
            $storeId
        )));
        if ($value === '') {
            return '';
        }
        if (!preg_match('/^[A-Z]{3}$/', $value)) {
            throw new RuntimeException('Manual XMR price rate currency must be a three-letter code.');
        }

        return $value;
    }

    private function isPositiveDecimal($value)
    {
        return (bool) preg_match('/^\d+(\.\d+)?$/', $value)
            && (bool) preg_match('/[1-9]/', $value);
    }
}
