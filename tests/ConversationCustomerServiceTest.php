<?php

use Modules\RondoIntegration\Services\ConversationCustomerService;
use Modules\RondoIntegration\Services\CustomerEmailService;
use PHPUnit\Framework\TestCase;

class ConversationCustomerServiceTest extends TestCase
{
    public function testUniqueExistingRecipientBecomesConversationCustomerAndSenderMovesToCc()
    {
        $service = $this->service(['member@example.test' => (object) ['id' => 42]]);
        $conversation = $this->conversation(7, 'sender@club.test', ['member@example.test', 'other@example.test']);

        $result = $service->reconcile(
            $conversation,
            $this->thread('sender@club.test', ['member@example.test', 'box@club.test']),
            $this->mailbox(['box@club.test'])
        );

        $this->assertSame('changed', $result['status']);
        $this->assertSame(42, $conversation->customer_id);
        $this->assertSame('member@example.test', $conversation->customer_email);
        $this->assertSame(['other@example.test', 'sender@club.test'], $conversation->cc);
    }

    public function testExternalSenderDoesNotChangeConversation()
    {
        $conversation = $this->conversation(7, 'customer@example.test', []);
        $result = $this->service(['member@example.test' => (object) ['id' => 42]])->reconcile(
            $conversation,
            $this->thread('external@example.test', ['member@example.test']),
            $this->mailbox(['box@club.test'])
        );

        $this->assertSame('external_sender', $result['status']);
        $this->assertSame(7, $conversation->customer_id);
    }

    public function testZeroOrMultipleEligibleRecipientsDoNotChangeConversation()
    {
        $service = $this->service([]);
        $mailbox = $this->mailbox(['box@club.test']);

        foreach ([
            ['box@club.test'],
            ['one@example.test', 'two@example.test'],
        ] as $recipients) {
            $conversation = $this->conversation(7, 'sender@club.test', []);
            $result = $service->reconcile($conversation, $this->thread('sender@club.test', $recipients), $mailbox);
            $this->assertStringStartsWith('recipient_count_', $result['status']);
            $this->assertSame(7, $conversation->customer_id);
        }
    }

    public function testMissingFreeScoutCustomerDoesNotCreateOrChangeAnything()
    {
        $lookups = 0;
        $service = new ConversationCustomerService(new CustomerEmailService(), function () use (&$lookups) {
            $lookups++;
            return null;
        });
        $conversation = $this->conversation(7, 'sender@club.test', ['member@example.test']);
        $result = $service->reconcile(
            $conversation,
            $this->thread('sender@club.test', ['member@example.test']),
            $this->mailbox(['box@club.test'])
        );

        $this->assertSame('customer_not_found', $result['status']);
        $this->assertSame(1, $lookups);
        $this->assertSame(7, $conversation->customer_id);
        $this->assertSame(['member@example.test'], $conversation->cc);
    }

    public function testReconciliationIsIdempotent()
    {
        $service = $this->service(['member@example.test' => (object) ['id' => 42]]);
        $conversation = $this->conversation(42, 'member@example.test', ['sender@club.test']);
        $result = $service->reconcile(
            $conversation,
            $this->thread('sender@club.test', ['member@example.test']),
            $this->mailbox(['box@club.test'])
        );

        $this->assertSame('unchanged', $result['status']);
        $this->assertSame(['sender@club.test'], $conversation->cc);
    }

    private function service(array $customers)
    {
        return new ConversationCustomerService(new CustomerEmailService(), function ($email) use ($customers) {
            return $customers[$email] ?? null;
        });
    }

    private function conversation($customerId, $customerEmail, array $cc)
    {
        return new class($customerId, $customerEmail, $cc) {
            public $customer_id;
            public $customer_email;
            public $cc;

            public function __construct($customerId, $customerEmail, array $cc)
            {
                $this->customer_id = $customerId;
                $this->customer_email = $customerEmail;
                $this->cc = $cc;
            }

            public function getCcArray()
            {
                return $this->cc;
            }

            public function setCc($cc)
            {
                $this->cc = $cc;
            }
        };
    }

    private function thread($from, array $to)
    {
        return new class($from, $to) {
            public $from;
            private $to;

            public function __construct($from, array $to)
            {
                $this->from = $from;
                $this->to = $to;
            }

            public function getToArray()
            {
                return $this->to;
            }
        };
    }

    private function mailbox(array $emails)
    {
        return new class($emails) {
            private $emails;

            public function __construct(array $emails)
            {
                $this->emails = $emails;
            }

            public function getEmails()
            {
                return $this->emails;
            }
        };
    }
}
