<?php

namespace MoneroIntegrations\Custompayment\Model\Wallet;

/**
 * Stateless validation of wallet-RPC host, port and loopback rules.
 */
class RpcValidator
{
    public static function isValidHost($host)
    {
        $host = (string) $host;
        if ($host === '') {
            return false;
        }
        if (filter_var(self::unbracket($host), FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z0-9.-]+$/', $host);
    }

    public static function isLoopbackHost($host)
    {
        $host = self::unbracket(strtolower(trim((string) $host)));
        if ($host === 'localhost') {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return $host === '::1';
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return strpos($host, '127.') === 0;
        }

        return false;
    }

    public static function isValidPort($port)
    {
        $port = (string) $port;

        return ctype_digit($port) && (int) $port >= 1 && (int) $port <= 65535;
    }

    private static function unbracket($host)
    {
        if (strlen($host) >= 2 && $host[0] === '[' && substr($host, -1) === ']') {
            return substr($host, 1, -1);
        }

        return $host;
    }
}
