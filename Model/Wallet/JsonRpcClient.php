<?php

namespace MoneroIntegrations\Custompayment\Model\Wallet;

use InvalidArgumentException;
use RuntimeException;

class JsonRpcClient
{
    private $url;
    private $curlOptions = array(
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_USERAGENT => 'moneromagento/1.0'
    );
    private $httpErrors = array(
        400 => '400 Bad Request',
        401 => '401 Unauthorized',
        403 => '403 Forbidden',
        404 => '404 Not Found',
        405 => '405 Method Not Allowed',
        406 => '406 Not Acceptable',
        408 => '408 Request Timeout',
        500 => '500 Internal Server Error',
        502 => '502 Bad Gateway',
        503 => '503 Service Unavailable'
    );

    public function __construct($url)
    {
        $this->validate(false === extension_loaded('curl'), 'The curl extension must be loaded.');
        $this->validate(false === extension_loaded('json'), 'The json extension must be loaded.');

        $this->url = $url;
    }

    public function setCurlOptions($options)
    {
        if (!is_array($options)) {
            throw new InvalidArgumentException('Invalid options type.');
        }

        $this->curlOptions = $options + $this->curlOptions;
        return $this;
    }

    public function run($method, $params = null)
    {
        static $requestId = 0;

        $requestId++;
        $this->validate(false === is_scalar($method), 'Method name has no scalar value.');

        $request = json_encode(array(
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
            'id' => $requestId
        ));
        $this->validate(false === $request, 'Unable to encode JSON-RPC request.');

        $responseMessage = $this->getResponse($request);
        $responseDecoded = json_decode($responseMessage, true, 512, JSON_BIGINT_AS_STRING);

        $this->validate(json_last_error() !== JSON_ERROR_NONE, 'Invalid JSON in wallet RPC response.');
        $this->validate(!is_array($responseDecoded), 'Invalid wallet RPC response structure.');
        $this->validate(empty($responseDecoded['id']), 'Invalid wallet RPC response id.');
        $this->validate(
            (int) $responseDecoded['id'] !== $requestId,
            'Wallet RPC response id did not match the request id.'
        );

        if (isset($responseDecoded['error'])) {
            $message = isset($responseDecoded['error']['message'])
                ? (string) $responseDecoded['error']['message']
                : 'Unknown wallet RPC error.';
            throw new RuntimeException('Wallet RPC error: ' . $message);
        }

        return isset($responseDecoded['result']) ? $responseDecoded['result'] : array();
    }

    private function getResponse($request)
    {
        $ch = curl_init();
        if (!$ch) {
            throw new RuntimeException('Could not initialize a cURL session.');
        }

        curl_setopt($ch, CURLOPT_URL, $this->url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-type: application/json'));
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip,deflate');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        if (!curl_setopt_array($ch, $this->curlOptions)) {
            throw new RuntimeException('Error while setting curl options.');
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);

        if (0 < $curlErrno) {
            throw new RuntimeException('Unable to connect to ' . $this->url . ' Error: ' . $curlError);
        }
        if (false === $response) {
            throw new RuntimeException('Empty wallet RPC response.');
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            $httpError = isset($this->httpErrors[$httpCode])
                ? $this->httpErrors[$httpCode]
                : (string) (int) $httpCode;
            throw new RuntimeException('Response HTTP error - ' . $httpError);
        }

        return $response;
    }

    private function validate($failed, $message)
    {
        if ($failed) {
            throw new RuntimeException($message);
        }
    }
}
