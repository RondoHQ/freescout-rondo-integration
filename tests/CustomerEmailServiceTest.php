<?php

use Modules\RondoIntegration\Services\CustomerEmailService;
use PHPUnit\Framework\TestCase;

class CustomerEmailServiceTest extends TestCase
{
    public function testEmailsAreNormalizedDeduplicatedAndSorted()
    {
        $service = new CustomerEmailService();
        $this->assertSame(
            ['alpha@example.test', 'zulu@example.test'],
            $service->normalize([' ZULU@example.test ', 'alpha@example.test', 'zulu@example.test'])
        );
    }

    public function testSyntheticAndMalformedEmailsAreDiscarded()
    {
        $service = new CustomerEmailService();
        $this->assertSame([], $service->normalize(['123@members.rondo.invalid', 'not-an-email', '']));
    }

    public function testAtMostTenEmailsAreSent()
    {
        $values = [];
        for ($index = 0; $index < 12; $index++) {
            $values[] = sprintf('person%02d@example.test', $index);
        }
        $this->assertCount(10, (new CustomerEmailService())->normalize($values));
    }
}
