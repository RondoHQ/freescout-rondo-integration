<?php

namespace Modules\RondoIntegration\Services;

class CustomerEmailService
{
    const MAX_EMAILS = 10;

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
        return array_slice($emails, 0, self::MAX_EMAILS);
    }
}
