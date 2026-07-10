<?php

namespace MoneroIntegrations\Custompayment\Model\Wallet;

use RuntimeException;

/**
 * Wallet-RPC operations: create subaddress, read transfers, verify payment, and convert fiat to atomic XMR.
 */
class MoneroClient
{
    const ATOMIC_UNITS = 1000000000000;

    private $rpc;
    private $priceApiKey;
    private $manualPriceRate;
    private $manualPriceRateCurrency;

    public function __construct(
        $rpcAddress,
        $rpcPort,
        $rpcUsername = '',
        $rpcPassword = '',
        $priceApiKey = '',
        $manualPriceRate = null,
        $manualPriceRateCurrency = '',
        $useHttps = false
    )
    {
        $scheme = $useHttps ? 'https' : 'http';
        $rpcAddress = RpcValidator::bracketHost($rpcAddress);
        $this->rpc = new JsonRpcClient($scheme . '://' . $rpcAddress . ':' . $rpcPort . '/json_rpc');
        $this->priceApiKey = trim((string) $priceApiKey);
        $this->manualPriceRate = null;
        $this->manualPriceRateCurrency = '';
        if ($manualPriceRate !== null) {
            $rate = $this->normalizeDecimal($manualPriceRate);
            if ($rate === null || bccomp($rate, '0', 12) <= 0) {
                throw new RuntimeException('Manual XMR price rate must be a positive decimal.');
            }
            $currency = strtoupper(trim((string) $manualPriceRateCurrency));
            if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                throw new RuntimeException('Manual XMR price rate currency must be configured.');
            }
            $this->manualPriceRate = $rate;
            $this->manualPriceRateCurrency = $currency;
        }
        if ($rpcUsername !== '') {
            $this->rpc->setCurlOptions(array(
                CURLOPT_HTTPAUTH => CURLAUTH_DIGEST,
                CURLOPT_USERPWD => $rpcUsername . ':' . $rpcPassword
            ));
        }
    }

    public function createSubaddress($label = '')
    {
        $address = $this->rpc->run('create_address', array(
            'account_index' => 0,
            'label' => (string) $label
        ));
        $this->rpc->run('store');

        if (!isset($address['address']) || !$this->isValidSubaddress($address['address'])) {
            throw new RuntimeException('Wallet RPC returned an invalid subaddress.');
        }

        return $address['address'];
    }

    public function fiatToAtomicUnits($amount, $currency)
    {
        $amountString = $this->normalizeDecimal($amount);
        if ($amountString === null || bccomp($amountString, '0', 12) <= 0) {
            throw new RuntimeException('Invalid order amount.');
        }

        $rate = $this->retrievePrice($currency);
        $numerator = bcmul($amountString, (string) self::ATOMIC_UNITS, 12);
        if (bccomp($numerator, $rate, 12) < 0) {
            throw new RuntimeException('XMR amount is below piconero precision.');
        }
        $atomic = bcdiv($numerator, $rate, 0);
        if (bccomp(bcmul($atomic, $rate, 12), $numerator, 12) < 0) {
            $atomic = bcadd($atomic, '1');
        }

        if (bccomp($atomic, (string) PHP_INT_MAX, 0) > 0) {
            throw new RuntimeException('XMR amount is too large.');
        }

        return (int) $atomic;
    }

    public function atomicUnitsToXmr($atomic)
    {
        $atomic = (string) (int) $atomic;
        $padded = str_pad($atomic, 13, '0', STR_PAD_LEFT);
        $whole = substr($padded, 0, -12);
        $fraction = rtrim(substr($padded, -12), '0');

        return $fraction === '' ? $whole : $whole . '.' . $fraction;
    }

    public function buildPaymentUri($subaddress, $remainingAtomic, $description)
    {
        $params = array();
        if ((int) $remainingAtomic > 0) {
            $params['tx_amount'] = $this->atomicUnitsToXmr($remainingAtomic);
        }
        $params['tx_description'] = $description;

        return 'monero:' . $subaddress . '?' . http_build_query($params);
    }

    public function verifyPayment($subaddress, $amountAtomic, $confirmationsRequired)
    {
        $confirmationsRequired = max(0, (int) $confirmationsRequired);
        $amountAtomic = (int) $amountAtomic;
        $totalReceived = 0;
        $txids = array();
        $eligibleTransfers = array();
        $currentHeight = null;
        $transfers = $this->getTransfers($subaddress, true);

        if ($confirmationsRequired > 0) {
            $height = $this->rpc->run('get_height');
            if (!isset($height['height'])) {
                throw new RuntimeException('Wallet RPC returned an invalid height.');
            }
            $currentHeight = (int) $height['height'];
        }

        $confirmingReceived = 0;
        foreach ($transfers as $transfer) {
            if (!$this->isTransferEligible($transfer)) {
                continue;
            }
            if ($confirmationsRequired > 0 && !$this->hasConfirmations($transfer, $currentHeight, $confirmationsRequired)) {
                $confirmingReceived += $transfer['amount'];
                continue;
            }

            $totalReceived += $transfer['amount'];
            $txids[] = $transfer['txid'];
            $eligibleTransfers[] = $transfer;
        }

        $detectedReceived = $this->sumDetectedPoolTransfers($transfers) + $confirmingReceived;
        $paid = $totalReceived >= $amountAtomic;
        return array(
            'paid' => $paid,
            'total_received_atomic' => $totalReceived,
            'detected_received_atomic' => $detectedReceived,
            'txids' => $txids,
            'transfers' => $eligibleTransfers
        );
    }

    private function retrievePrice($currency)
    {
        $currency = strtoupper((string) $currency);
        if ($currency === 'XMR') {
            return '1';
        }
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new RuntimeException('Unsupported currency code.');
        }
        if ($this->manualPriceRate !== null) {
            if ($currency !== $this->manualPriceRateCurrency) {
                throw new RuntimeException('Manual XMR price rate currency does not match the order currency.');
            }
            return $this->manualPriceRate;
        }

        $priceResponse = $this->fetchPriceResponse($currency);

        $price = json_decode($priceResponse, true);
        if (!is_array($price) || !array_key_exists($currency, $price)) {
            throw new RuntimeException('Unsupported or invalid currency rate.');
        }

        $rate = $this->normalizeDecimal($price[$currency]);
        if ($rate === null || bccomp($rate, '0', 12) <= 0) {
            throw new RuntimeException('Unsupported or invalid currency rate.');
        }

        return $rate;
    }

    private function fetchPriceResponse($currency)
    {
        $url = 'https://min-api.cryptocompare.com/data/price?fsym=XMR&tsyms='
            . rawurlencode($currency)
            . '&extraParams=monero_magento';
        $headers = array('Accept: application/json');
        if ($this->priceApiKey !== '') {
            $headers[] = 'authorization: Apikey ' . $this->priceApiKey;
        }

        $ch = curl_init();
        if (!$ch) {
            throw new RuntimeException('Could not initialize a cURL session.');
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'moneromagento/1.0');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);

        if ($curlErrno > 0) {
            throw new RuntimeException('Unable to fetch XMR price: ' . $curlError);
        }
        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException('Unable to fetch XMR price. HTTP status: ' . (int) $httpCode);
        }

        return $response;
    }

    private function getTransfers($subaddress, $includePool = false)
    {
        $addressIndex = $this->rpc->run('get_address_index', array('address' => $subaddress));
        if (!isset($addressIndex['index']['major'], $addressIndex['index']['minor'])) {
            throw new RuntimeException('Wallet RPC does not recognize the payment subaddress.');
        }

        $payments = $this->rpc->run('get_transfers', array(
            'in' => true,
            'pool' => (bool) $includePool,
            'account_index' => (int) $addressIndex['index']['major'],
            'subaddr_indices' => array((int) $addressIndex['index']['minor'])
        ));

        $transfers = array();
        if (isset($payments['in']) && is_array($payments['in'])) {
            foreach ($payments['in'] as $payment) {
                $transfers[] = $this->normalizeTransfer($payment, false);
            }
        }
        if ($includePool && isset($payments['pool']) && is_array($payments['pool'])) {
            foreach ($payments['pool'] as $payment) {
                $transfers[] = $this->normalizeTransfer($payment, true);
            }
        }

        return $transfers;
    }

    private function normalizeTransfer(array $payment, $inPool)
    {
        $complete = isset(
            $payment['amount'],
            $payment['txid'],
            $payment['height'],
            $payment['unlock_time'],
            $payment['double_spend_seen']
        );

        return array(
            'amount' => isset($payment['amount']) ? (int) $payment['amount'] : 0,
            'txid' => isset($payment['txid']) ? (string) $payment['txid'] : '',
            'height' => isset($payment['height']) ? (int) $payment['height'] : 0,
            'confirmations' => isset($payment['confirmations']) ? (int) $payment['confirmations'] : null,
            'unlock_time' => isset($payment['unlock_time']) ? (int) $payment['unlock_time'] : null,
            'double_spend_seen' => isset($payment['double_spend_seen']) ? (bool) $payment['double_spend_seen'] : null,
            'in_pool' => (bool) $inPool,
            'complete' => $complete
        );
    }

    private function isTransferEligible(array $transfer)
    {
        return $transfer['complete']
            && !$transfer['in_pool']
            && $transfer['amount'] > 0
            && $transfer['txid'] !== ''
            && $transfer['height'] > 0
            && $transfer['unlock_time'] === 0
            && $transfer['double_spend_seen'] === false;
    }

    private function sumDetectedPoolTransfers(array $transfers)
    {
        $total = 0;
        foreach ($transfers as $transfer) {
            if (!$this->isPoolTransferDetected($transfer)) {
                continue;
            }
            $total += $transfer['amount'];
        }

        return $total;
    }

    private function isPoolTransferDetected(array $transfer)
    {
        return $transfer['in_pool']
            && $transfer['amount'] > 0
            && $transfer['txid'] !== ''
            && $transfer['unlock_time'] === 0
            && $transfer['double_spend_seen'] === false;
    }

    private function hasConfirmations(array $transfer, $currentHeight, $requiredConfirmations)
    {
        $confirmations = $transfer['confirmations'];
        if ($confirmations === null || $confirmations <= 0) {
            $confirmations = max(0, (int) $currentHeight - (int) $transfer['height']);
        }

        return $confirmations >= (int) $requiredConfirmations;
    }

    private function normalizeDecimal($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $value = str_replace(',', '.', $value);
        if (!preg_match('/^\d+(\.\d+)?$/', $value)) {
            return null;
        }

        return $value;
    }

    private function isValidSubaddress($subaddress)
    {
        return is_string($subaddress) && preg_match('/^[0-9A-Za-z]{95}$/', $subaddress);
    }
}
