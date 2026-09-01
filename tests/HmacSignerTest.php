<?php

use Modules\RondoIntegration\Services\HmacSigner;
use PHPUnit\Framework\TestCase;

class HmacSignerTest extends TestCase
{
    public function testSignatureCoversTimestampNonceAndExactBody()
    {
        $body = '{"version":1,"value":"exact"}';
        $key = str_repeat('k', 32);
        $headers = (new HmacSigner())->headers($body, $key);
        $this->assertMatchesRegularExpression('/^[0-9]+$/', $headers['X-Rondo-Timestamp']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{32}$/', $headers['X-Rondo-Nonce']);
        $expected = hash_hmac('sha256', $headers['X-Rondo-Timestamp'] . "\n" . $headers['X-Rondo-Nonce'] . "\n" . $body, $key);
        $this->assertSame('v1=' . $expected, $headers['X-Rondo-Signature']);
        $this->assertNotSame($headers['X-Rondo-Signature'], (new HmacSigner())->headers($body . ' ', $key)['X-Rondo-Signature']);
    }

    public function testShortSigningKeysAreRejected()
    {
        $this->expectException(RuntimeException::class);
        (new HmacSigner())->headers('{}', 'short');
    }
}
