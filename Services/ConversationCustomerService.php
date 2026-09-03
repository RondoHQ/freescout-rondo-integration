<?php

namespace Modules\RondoIntegration\Services;

class ConversationCustomerService
{
    private $emails;
    private $findCustomer;

    public function __construct(CustomerEmailService $emails, $findCustomer = null)
    {
        $this->emails = $emails;
        $this->findCustomer = $findCustomer ?: function ($email) {
            return \App\Customer::getByEmail($email);
        };
    }

    public function reconcile($conversation, $thread, $mailbox = null)
    {
        if (!$conversation || !$thread) {
            return ['status' => 'unavailable'];
        }
        $mailbox = $mailbox ?: ($conversation->mailbox ?? null);
        $recipients = $this->emails->mailboxDomainRecipients($thread, $mailbox);
        if ($recipients === null) {
            return ['status' => 'external_sender'];
        }
        if (count($recipients) !== 1) {
            return ['status' => 'recipient_count_' . count($recipients)];
        }

        $targetEmail = $recipients[0];
        $targetCustomer = call_user_func($this->findCustomer, $targetEmail);
        if (!$targetCustomer || empty($targetCustomer->id)) {
            return ['status' => 'customer_not_found', 'target_email' => $targetEmail];
        }

        $currentCc = method_exists($conversation, 'getCcArray') ? $conversation->getCcArray() : [];
        $nextCc = $this->withoutEmail($currentCc, $targetEmail);
        $sender = $this->emails->normalize([(string) ($thread->from ?? '')]);
        if ($sender && !$this->containsEmail($nextCc, $sender[0])) {
            $nextCc[] = $sender[0];
        }

        $changed = (int) ($conversation->customer_id ?? 0) !== (int) $targetCustomer->id
            || strtolower(trim((string) ($conversation->customer_email ?? ''))) !== $targetEmail
            || $this->normalizedCc($currentCc) !== $this->normalizedCc($nextCc);

        $conversation->customer_id = (int) $targetCustomer->id;
        $conversation->customer_email = $targetEmail;
        if (method_exists($conversation, 'setCc')) {
            $conversation->setCc($nextCc);
        }

        return [
            'status' => $changed ? 'changed' : 'unchanged',
            'target_customer_id' => (int) $targetCustomer->id,
            'target_email' => $targetEmail,
        ];
    }

    private function withoutEmail(array $emails, $excluded)
    {
        return array_values(array_filter($emails, function ($email) use ($excluded) {
            return strtolower(trim((string) $email)) !== $excluded;
        }));
    }

    private function containsEmail(array $emails, $needle)
    {
        foreach ($emails as $email) {
            if (strtolower(trim((string) $email)) === $needle) {
                return true;
            }
        }
        return false;
    }

    private function normalizedCc(array $emails)
    {
        $normalized = array_map(function ($email) {
            return strtolower(trim((string) $email));
        }, $emails);
        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_STRING);
        return $normalized;
    }
}
