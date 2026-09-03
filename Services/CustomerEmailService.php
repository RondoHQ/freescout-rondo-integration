<?php

namespace Modules\RondoIntegration\Services;

class CustomerEmailService
{
    const MAX_EMAILS = 10;

    public function forConversation($customer, $firstIncomingThread, $mailbox)
    {
        $recipients = $this->mailboxDomainRecipients($firstIncomingThread, $mailbox);
        if ($recipients === null) {
            return $this->fromCustomer($customer);
        }

        return $recipients;
    }

    public function mailboxDomainRecipients($thread, $mailbox)
    {
        if (!$this->isMailboxDomainSender($thread, $mailbox)) {
            return null;
        }

        $recipients = method_exists($thread, 'getToArray')
            ? $this->normalized($thread->getToArray())
            : [];
        $excluded = [$thread->from];
        if ($mailbox && method_exists($mailbox, 'getEmails')) {
            $excluded = array_merge($excluded, $mailbox->getEmails());
        }

        return array_slice(
            array_values(array_diff($recipients, $this->normalized($excluded))),
            0,
            self::MAX_EMAILS
        );
    }

    public function fromCustomer($customer)
    {
        $values = [];
        if (!$customer || !$customer->emails) {
            return $values;
        }
        foreach ($customer->emails as $email) {
            $values[] = is_object($email) ? $email->email : $email;
        }
        return $this->normalize($values);
    }

    public function normalize(array $values)
    {
        return array_slice($this->normalized($values), 0, self::MAX_EMAILS);
    }

    private function normalized(array $values)
    {
        $emails = [];
        foreach ($values as $value) {
            $normalized = strtolower(trim((string) $value));
            if (!filter_var($normalized, FILTER_VALIDATE_EMAIL)
                || substr($normalized, -22) === '@members.rondo.invalid'
            ) {
                continue;
            }
            $emails[$normalized] = true;
        }
        $emails = array_keys($emails);
        sort($emails, SORT_STRING);
        return $emails;
    }

    private function isMailboxDomainSender($thread, $mailbox)
    {
        if (!$thread || !$mailbox || !method_exists($mailbox, 'getEmails')) {
            return false;
        }
        $sender = strtolower(trim((string) $thread->from));
        if (!filter_var($sender, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $senderDomain = $this->domain($sender);
        foreach ($this->normalized($mailbox->getEmails()) as $mailboxEmail) {
            if (hash_equals($this->domain($mailboxEmail), $senderDomain)) {
                return true;
            }
        }

        return false;
    }

    private function domain($email)
    {
        return substr($email, strrpos($email, '@') + 1);
    }
}
