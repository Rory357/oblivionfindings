<?php

namespace App\Domain\SecurityDevices\Management\Data;

use UnexpectedValueException;

/** Server-owned identity carried by the immutable, signed command contract. */
final readonly class ClientLocationCommandOrigin
{
    private function __construct(
        public int $clientId,
        public int $assignmentId,
        public int $consentId,
        public string $accessFingerprint,
    ) {}

    public static function fromArray(array $context): self
    {
        $keys = array_keys($context);
        sort($keys);
        if ($keys !== ['access_fingerprint', 'assignment_id', 'client_id', 'consent_id', 'kind', 'version']
            || $context['kind'] !== 'client_location' || $context['version'] !== 1
            || ! is_int($context['client_id']) || $context['client_id'] < 1
            || ! is_int($context['assignment_id']) || $context['assignment_id'] < 1
            || ! is_int($context['consent_id']) || $context['consent_id'] < 1
            || ! is_string($context['access_fingerprint'])
            || preg_match('/^[a-f0-9]{64}$/D', $context['access_fingerprint']) !== 1) {
            throw new UnexpectedValueException('The client location command context is invalid.');
        }

        return new self($context['client_id'], $context['assignment_id'], $context['consent_id'], $context['access_fingerprint']);
    }

    public function toArray(): array
    {
        return [
            'kind' => 'client_location', 'version' => 1, 'client_id' => $this->clientId,
            'assignment_id' => $this->assignmentId, 'consent_id' => $this->consentId,
            'access_fingerprint' => $this->accessFingerprint,
        ];
    }
}
