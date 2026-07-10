<?php

namespace MoneroIntegrations\Custompayment\Test\Unit\Model\Wallet;

use MoneroIntegrations\Custompayment\Model\Wallet\RpcValidator;
use PHPUnit\Framework\TestCase;

/** Tests wallet RPC host and port validation. */
class RpcValidatorTest extends TestCase
{
    public function testValidHosts()
    {
        $this->assertTrue(RpcValidator::isValidHost('localhost'));
        $this->assertTrue(RpcValidator::isValidHost('127.0.0.1'));
        $this->assertTrue(RpcValidator::isValidHost('::1'));
        $this->assertTrue(RpcValidator::isValidHost('[::1]'));
        $this->assertFalse(RpcValidator::isValidHost(''));
        $this->assertFalse(RpcValidator::isValidHost('bad host'));
    }

    public function testLoopbackHosts()
    {
        $this->assertTrue(RpcValidator::isLoopbackHost('localhost'));
        $this->assertTrue(RpcValidator::isLoopbackHost('127.0.0.2'));
        $this->assertTrue(RpcValidator::isLoopbackHost('::1'));
        $this->assertTrue(RpcValidator::isLoopbackHost('[::1]'));
        $this->assertFalse(RpcValidator::isLoopbackHost('192.168.1.1'));
        $this->assertFalse(RpcValidator::isLoopbackHost('2001:db8::1'));
    }

    public function testValidPorts()
    {
        $this->assertTrue(RpcValidator::isValidPort('1'));
        $this->assertTrue(RpcValidator::isValidPort(18082));
        $this->assertTrue(RpcValidator::isValidPort('65535'));
        $this->assertFalse(RpcValidator::isValidPort('0'));
        $this->assertFalse(RpcValidator::isValidPort('65536'));
        $this->assertFalse(RpcValidator::isValidPort('18082.0'));
    }

    public function testBracketHost()
    {
        $this->assertSame('[::1]', RpcValidator::bracketHost('::1'));
        $this->assertSame('[::1]', RpcValidator::bracketHost('[::1]'));
        $this->assertSame('[2001:db8::1]', RpcValidator::bracketHost('2001:db8::1'));
        $this->assertSame('127.0.0.1', RpcValidator::bracketHost('127.0.0.1'));
        $this->assertSame('localhost', RpcValidator::bracketHost('localhost'));
    }
}
