<?php

use Modules\RondoIntegration\Services\ActivityDeliveryPolicy;
use PHPUnit\Framework\TestCase;

class ActivityDeliveryPolicyTest extends TestCase
{
    public function testSuccessfulAndUnmatchedResponsesAreClassified()
    {
        $policy = new ActivityDeliveryPolicy();
        foreach (['created', 'confirmed', 'moved', 'restored'] as $status) {
            $this->assertSame('complete', $policy->outcome(['status' => $status, 'conversation_id' => 42], 42));
        }
        foreach (['no_match', 'ambiguous'] as $status) {
            $this->assertSame('retry', $policy->outcome(['status' => $status, 'conversation_id' => 42], 42));
        }
    }

    public function testInvalidOrMismatchedResponsesAreRejected()
    {
        $policy = new ActivityDeliveryPolicy();
        foreach ([
            [],
            ['status' => 'created'],
            ['status' => 'unexpected', 'conversation_id' => 42],
            ['status' => 'created', 'conversation_id' => 43],
        ] as $response) {
            try {
                $policy->outcome($response, 42);
                $this->fail('Invalid response was accepted.');
            } catch (RuntimeException $failure) {
                $this->assertSame('activity_response_invalid', $failure->getMessage());
            }
        }
    }

    public function testRetryDelaysBackOffAndThenRemainHourly()
    {
        $policy = new ActivityDeliveryPolicy();
        $this->assertSame(60, $policy->retryDelaySeconds(1));
        $this->assertSame(300, $policy->retryDelaySeconds(2));
        $this->assertSame(900, $policy->retryDelaySeconds(3));
        $this->assertSame(3600, $policy->retryDelaySeconds(4));
        $this->assertSame(3600, $policy->retryDelaySeconds(40));
    }

    public function testOnlySafeReasonCodesAreStored()
    {
        $policy = new ActivityDeliveryPolicy();
        $this->assertSame('http_503', $policy->errorCode(new RuntimeException('http_503')));
        $this->assertSame('delivery_failed', $policy->errorCode(new RuntimeException('Email foo@example.test failed')));
    }
}
