<?php

use Modules\RondoIntegration\Services\CustomerEmailService;
use PHPUnit\Framework\TestCase;

class CustomerEmailServiceTest extends TestCase
{
    public function testInternalSenderUsesToRecipientsAndExcludesMailboxAddressesAndSender()
    {
        $service = new CustomerEmailService();
        $customer = $this->customer(['sender@svawc.nl']);
        $thread = $this->thread('Sender@SVAWC.nl', [
            'member@example.test',
            'ledenadministratie@svawc.nl',
            'alias@svawc.nl',
            'sender@svawc.nl',
        ]);
        $mailbox = $this->mailbox(['ledenadministratie@svawc.nl', 'alias@svawc.nl']);

        $this->assertSame(
            ['member@example.test'],
            $service->forConversation($customer, $thread, $mailbox)
        );
    }

    public function testInternalSenderDoesNotFallBackToTheSenderWhenNoEligibleRecipientExists()
    {
        $service = new CustomerEmailService();

        $this->assertSame(
            [],
            $service->forConversation(
                $this->customer(['sender@svawc.nl']),
                $this->thread('sender@svawc.nl', ['ledenadministratie@svawc.nl']),
                $this->mailbox(['ledenadministratie@svawc.nl'])
            )
        );
    }

    public function testExternalAndSubdomainSendersKeepUsingTheConversationCustomer()
    {
        $service = new CustomerEmailService();
        $customer = $this->customer(['member@example.test']);
        $mailbox = $this->mailbox(['ledenadministratie@svawc.nl']);

        $this->assertSame(
            ['member@example.test'],
            $service->forConversation($customer, $this->thread('sender@example.test', ['other@example.test']), $mailbox)
        );
        $this->assertSame(
            ['member@example.test'],
            $service->forConversation($customer, $this->thread('sender@mail.svawc.nl', ['other@example.test']), $mailbox)
        );
    }

    public function testMailboxAliasDomainAlsoDefinesAnInternalSender()
    {
        $service = new CustomerEmailService();

        $this->assertSame(
            ['member@example.test'],
            $service->forConversation(
                $this->customer(['sender@alias.test']),
                $this->thread('sender@alias.test', ['member@example.test']),
                $this->mailbox(['box@club.test', 'box@alias.test'])
            )
        );
    }

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

    private function customer(array $emails)
    {
        return (object) [
            'emails' => array_map(function ($email) {
                return (object) ['email' => $email];
            }, $emails),
        ];
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
