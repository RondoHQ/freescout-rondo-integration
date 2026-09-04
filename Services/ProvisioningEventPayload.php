<?php

namespace Modules\RondoIntegration\Services;

class ProvisioningEventPayload
{
    public function validate(array $payload, $expectedIssuer)
    {
        $keys = array_keys($payload);
        $expected = ['version', 'eventId', 'issuer', 'subject'];
        sort($keys);
        sort($expected);
        if ($keys !== $expected
            || !is_int($payload['version']) || $payload['version'] !== 1
            || !is_string($payload['eventId']) || !$this->validUuid($payload['eventId'])
            || !is_string($payload['issuer']) || !hash_equals((string) $expectedIssuer, $payload['issuer'])
            || !is_string($payload['subject']) || !preg_match('/^[A-Za-z0-9_-]{43}$/', $payload['subject'])
        ) {
            throw new ProvisioningRequestException('event_schema_invalid', 400);
        }

        return [
            'version' => 1,
            'eventId' => strtolower($payload['eventId']),
            'issuer' => $payload['issuer'],
            'subject' => $payload['subject'],
        ];
    }

    private function validUuid($value)
    {
        return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', (string) $value) === 1;
    }
}
