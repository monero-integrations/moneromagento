<?php

namespace MoneroIntegrations\Custompayment\Test\Unit\Model\Wallet;

use MoneroIntegrations\Custompayment\Model\Wallet\MoneroClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Tests Monero amount conversion logic. */
class MoneroClientTest extends TestCase
{
    public function testFiatConversionRoundsUpToAtomicUnit()
    {
        $client = new MoneroClient('localhost', 18082, '', '', '', '3', 'USD');

        $this->assertSame(333333333334, $client->fiatToAtomicUnits('1', 'USD'));
    }

    public function testSubPiconeroAmountIsRejected()
    {
        $client = new MoneroClient('localhost', 18082, '', '', '', '1000000000001', 'USD');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('XMR amount is below piconero precision.');
        $client->fiatToAtomicUnits('1', 'USD');
    }

    public function testAmountAbovePhpIntegerMaximumIsRejected()
    {
        $client = new MoneroClient('localhost', 18082);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('XMR amount is too large.');
        $client->fiatToAtomicUnits((string) PHP_INT_MAX, 'XMR');
    }

    public function testAtomicUnitsAreFormattedAsXmr()
    {
        $client = new MoneroClient('localhost', 18082);

        $this->assertSame('1', $client->atomicUnitsToXmr(1000000000000));
        $this->assertSame('0.000000000001', $client->atomicUnitsToXmr(1));
        $this->assertSame('1.23', $client->atomicUnitsToXmr(1230000000000));
    }
}
