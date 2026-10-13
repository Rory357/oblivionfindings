<?php

namespace Tests\Support;

use App\Models\ClientMedication;
use App\Services\Medication\Downtime\PaperEntryService;

/**
 * Review item 3 (10 Oct): /meds/today/record and /meds/today/prn require the
 * order a "given" was recorded from (order_fingerprint), and the dialog
 * always sends the one it showed. Tests written before that rule post the
 * dialog's other fields; this adds the fingerprint of the order as it is
 * when the request is sent — the order the dialog would have shown. A test
 * that sets order_fingerprint itself (even to null) is left alone.
 */
trait RecordsDosesLikeTheDialog
{
    public function post($uri, array $data = [], array $headers = [])
    {
        return parent::post($uri, $this->withDialogOrderFingerprint($uri, $data), $headers);
    }

    public function postJson($uri, array $data = [], array $headers = [], $options = 0)
    {
        return parent::postJson($uri, $this->withDialogOrderFingerprint($uri, $data), $headers, $options);
    }

    /** @param  array<string, mixed>  $data */
    private function withDialogOrderFingerprint(mixed $uri, array $data): array
    {
        $path = is_string($uri) ? (parse_url($uri, PHP_URL_PATH) ?? $uri) : '';
        if (! in_array($path, ['/meds/today/record', '/meds/today/prn'], true)
            || array_key_exists('order_fingerprint', $data)
            || ! is_numeric($data['client_medication_id'] ?? null)) {
            return $data;
        }
        $order = ClientMedication::withTrashed()->find((int) $data['client_medication_id']);

        return $order === null ? $data : [...$data, 'order_fingerprint' => PaperEntryService::orderFingerprint($order)];
    }
}
