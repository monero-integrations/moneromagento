<?php

namespace MoneroIntegrations\Custompayment\Service;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;

/**
 * MySQL advisory-lock (GET_LOCK) wrapper that serializes per-order settlement on one connection.
 */
class AdvisoryLock
{
    private $resourceConnection;
    private $connections = array();

    public function __construct(ResourceConnection $resourceConnection)
    {
        $this->resourceConnection = $resourceConnection;
    }

    public function acquire($name, $timeout = 10)
    {
        $connection = $this->resourceConnection->getConnection();
        $locked = $connection->fetchOne('SELECT GET_LOCK(?, ?)', array($name, (int) $timeout));
        if ((int) $locked !== 1) {
            throw new LocalizedException(__('Unable to acquire the Monero payment lock.'));
        }
        $this->connections[$name] = $connection;
    }

    public function release($name)
    {
        $connection = isset($this->connections[$name])
            ? $this->connections[$name]
            : $this->resourceConnection->getConnection();
        $connection->fetchOne('SELECT RELEASE_LOCK(?)', array($name));
        unset($this->connections[$name]);
    }
}
