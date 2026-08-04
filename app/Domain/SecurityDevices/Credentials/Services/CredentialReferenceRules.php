<?php

namespace App\Domain\SecurityDevices\Credentials\Services;

use InvalidArgumentException;

final class CredentialReferenceRules
{
    public function referenceKey(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^[a-z][a-z0-9._-]{1,31}:[A-Za-z0-9._\/:@-]{1,158}$/', $value) !== 1
            || str_contains($value, '://')) {
            throw new InvalidArgumentException('Credential reference key is invalid.');
        }

        return $value;
    }

    public function provider(string $value): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/', $value) !== 1) {
            throw new InvalidArgumentException('Credential provider is invalid.');
        }

        return $value;
    }

    public function purpose(string $value): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/', $value) !== 1) {
            throw new InvalidArgumentException('Credential purpose is invalid.');
        }

        return $value;
    }

    /** @param array<int, mixed> $values @return list<string> */
    public function capabilities(array $values): array
    {
        if ($values === [] || count($values) > 64) {
            throw new InvalidArgumentException('Credential capabilities are invalid.');
        }
        $capabilities = [];
        foreach ($values as $value) {
            if (! is_string($value)) {
                throw new InvalidArgumentException('Credential capabilities are invalid.');
            }
            $value = strtolower(trim($value));
            if (preg_match('/^[a-z][a-z0-9._:-]{1,119}$/', $value) !== 1) {
                throw new InvalidArgumentException('Credential capabilities are invalid.');
            }
            $capabilities[] = $value;
        }
        $capabilities = array_values(array_unique($capabilities));
        sort($capabilities, SORT_STRING);

        return $capabilities;
    }

    public function externalReference(#[\SensitiveParameter] string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 512 || str_contains($value, '://')
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1
            || preg_match('#(?:^|/)\.\.(?:/|$)#', $value) === 1) {
            throw new InvalidArgumentException('Secret manager reference is invalid.');
        }

        return $value;
    }

    public function fingerprint(string $value): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new InvalidArgumentException('Application fingerprint key is unavailable.');
        }

        return hash_hmac('sha256', $value, $key);
    }
}
