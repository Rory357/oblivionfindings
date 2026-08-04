<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\Monitoring\Contracts\EnvelopeSigner;
use App\Domain\SecurityDevices\Management\Data\CommandSigningPayload;
use JsonException;
use UnexpectedValueException;

final class CommandRequestSigner
{
    public function __construct(private readonly EnvelopeSigner $signer) {}

    /** @return array{key_id: string, signature: string} */
    public function sign(CommandSigningPayload $payload): array
    {
        $keyId = $this->signer->activeKeyId();

        return [
            'key_id' => $keyId,
            'signature' => base64_encode($this->signer->sign($keyId, $this->canonicalJson($payload->toArray()))),
        ];
    }

    public function verify(CommandSigningPayload $payload, string $keyId, string $encodedSignature): bool
    {
        $signature = base64_decode($encodedSignature, true);

        return is_string($signature)
            && $this->signer->verify($keyId, $this->canonicalJson($payload->toArray()), $signature);
    }

    /** @param array<string, mixed> $parameters */
    public function parametersHash(array $parameters): string
    {
        return hash('sha256', $this->canonicalJson($parameters));
    }

    public function reasonHash(string $reason): string
    {
        return hash('sha256', trim($reason));
    }

    /** @param array<string|int, mixed> $document */
    private function canonicalJson(array $document): string
    {
        try {
            return json_encode(
                $this->canonicalize($document),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('Device command signing payload is not valid JSON.', 0, $exception);
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            if (is_scalar($value) || $value === null) {
                return $value;
            }

            throw new UnexpectedValueException('Device command signing payload contains an unsupported value.');
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
