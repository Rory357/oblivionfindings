<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientMedication;

class MedicationScanVerificationService
{
    /**
     * @return array<int, array{source: string, label: string, value: string}>
     */
    public function expectedCodes(Client $client, ClientMedication $medication): array
    {
        $codes = [
            [
                'source' => 'vendor_barcode',
                'label' => 'Pack barcode',
                'value' => trim((string) $medication->getAttribute('barcode')),
            ],
            [
                'source' => 'nzulm',
                'label' => 'NZULM code',
                'value' => trim((string) $medication->getAttribute('nzulm_code')),
            ],
            [
                'source' => 'internal_emar',
                'label' => 'Internal eMAR code',
                'value' => $this->internalCode($client, $medication),
            ],
        ];

        $seen = [];

        return collect($codes)
            ->filter(function (array $code) {
                return $code['value'] !== '';
            })
            ->filter(function (array $code) use (&$seen) {
                $normalized = $this->normalize($code['value']);
                if ($normalized === '' || isset($seen[$normalized])) {
                    return false;
                }

                $seen[$normalized] = true;

                return true;
            })
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     primary_code: string,
     *     primary_label: string,
     *     primary_source: string,
     *     internal_code: string,
     *     vendor_barcode: string|null,
     *     nzulm_code: string|null,
     *     requires_internal_code: bool,
     *     qr_value: string,
     *     code_options: array<int, array{source: string, label: string, value: string}>
     * }
     */
    public function payload(Client $client, ClientMedication $medication): array
    {
        $codes = $this->expectedCodes($client, $medication);
        $primary = $codes[0] ?? [
            'source' => 'internal_emar',
            'label' => 'Internal eMAR code',
            'value' => $this->internalCode($client, $medication),
        ];

        return [
            'primary_code' => $primary['value'],
            'primary_label' => $primary['label'],
            'primary_source' => $primary['source'],
            'internal_code' => $this->internalCode($client, $medication),
            'vendor_barcode' => collect($codes)->firstWhere('source', 'vendor_barcode')['value'] ?? null,
            'nzulm_code' => collect($codes)->firstWhere('source', 'nzulm')['value'] ?? null,
            'requires_internal_code' => ! collect($codes)->contains(fn (array $code) => in_array($code['source'], ['vendor_barcode', 'nzulm'], true)),
            'qr_value' => $this->internalCode($client, $medication),
            'code_options' => $codes,
        ];
    }

    /**
     * @return array{
     *     matched: bool,
     *     match_source: string|null,
     *     match_label: string|null,
     *     message: string
     * }
     */
    public function verify(Client $client, ClientMedication $medication, string $candidate): array
    {
        $normalizedCandidate = $this->normalize($candidate);

        $match = collect($this->expectedCodes($client, $medication))
            ->first(fn (array $code) => $this->normalize($code['value']) === $normalizedCandidate);

        if (! $match) {
            return [
                'matched' => false,
                'match_source' => null,
                'match_label' => null,
                'message' => 'The scanned code does not match this medication.',
            ];
        }

        return [
            'matched' => true,
            'match_source' => $match['source'],
            'match_label' => $match['label'],
            'message' => $match['label'].' verified for this medication.',
        ];
    }

    public function internalCode(Client $client, ClientMedication $medication): string
    {
        $signature = strtoupper(substr(hash_hmac(
            'sha256',
            implode('|', [
                $client->id,
                $medication->id,
                (string) $medication->name,
                (string) $medication->dosage,
            ]),
            (string) config('app.key'),
        ), 0, 8));

        return "EMAR-{$client->id}-{$medication->id}-{$signature}";
    }

    public function normalize(?string $value): string
    {
        $value = strtoupper(trim((string) $value));
        $value = preg_replace('/[^A-Z0-9]/', '', $value) ?? '';

        return $value;
    }
}
