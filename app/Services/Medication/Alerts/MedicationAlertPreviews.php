<?php

namespace App\Services\Medication\Alerts;

use App\Models\MedicationAlert;
use App\Notifications\MedicationAlertNotification;

/**
 * Settings › Alerts & access › Message preview (P11 v5): what each alert
 * looks like in the bell, in an email and on a lock screen, with "Keep client
 * names and medicines out of email and push" on and off.
 *
 * Rendered by the real MedicationAlertNotification — toArray, toMail and
 * toPush — from a synthetic record that is never saved: v5's sample people,
 * medicines and houses, worded the way each alert's source words it. No real
 * person or record is used, and nothing is sent.
 */
class MedicationAlertPreviews
{
    /** Per alert: title, message, short message, controlled (v5 MESSAGE_SAMPLE). */
    private const SAMPLES = [
        MedicationAlertCatalogue::OVERDUE => ['Overdue dose', 'Aroha N. — Metformin 500 mg, 8:00 am dose — has no outcome 60 minutes after it was due. Kōwhai House.', 'A dose at Kōwhai House has no outcome yet.', false],
        MedicationAlertCatalogue::FOLLOW_UPS => ['Follow-up overdue', 'Tama W. — refusal follow-up for Digoxin was due 2 Oct 2026, 10:15 am. Kōwhai House.', 'A follow-up at Kōwhai House is overdue.', false],
        MedicationAlertCatalogue::STOCK => ['Stock running low', 'Salbutamol inhaler for Aroha N. is below its reorder level (2 left). Kōwhai House.', 'A medicine at Kōwhai House is running low.', false],
        MedicationAlertCatalogue::EXPIRY => ['Stock expiring', 'Enoxaparin 40 mg for Tama W. expires on 6 Oct 2026. Kōwhai House.', 'A medicine at Kōwhai House expires soon.', false],
        MedicationAlertCatalogue::REFUSALS => ['Repeated refusals', 'Grace L. — Digoxin refused or withheld 3 times in 7 days. Kōwhai House.', 'Repeated refusals at Kōwhai House.', false],
        MedicationAlertCatalogue::RENEWALS => ['Competency renewal due', 'Daniel Ahn’s medication competency ends on 14 Oct 2026. Book a reassessment.', 'A competency renewal is due.', false],
        MedicationAlertCatalogue::ERRORS => ['Medication error reported', 'A medication error was reported at Rimu House: Ben C., Insulin glargine — wrong time.', 'A medication error was reported at Rimu House.', false],
        MedicationAlertCatalogue::CD_DISCREPANCY => ['Controlled-drug count doesn’t match', 'Controlled-drug count doesn’t match — Methylphenidate at Kōwhai House: 1 short.', 'A controlled-drug count doesn’t match at Kōwhai House.', true],
        MedicationAlertCatalogue::PRN_LIMIT => ['As-needed dose over the limit', 'Tama W. — as-needed Paracetamol has reached the order’s daily limit. Kōwhai House.', 'An as-needed dose at Kōwhai House is over its limit.', false],
        MedicationAlertCatalogue::OUT_OF_STOCK => ['Out of stock', 'Levetiracetam for Tama W. is out of stock. Kōwhai House.', 'A medicine at Kōwhai House is out of stock.', false],
        MedicationAlertCatalogue::CD_CHECK => ['Controlled-drug balance check overdue', 'No controlled-drug balance check at Rimu House for 7 days (2 medicines).', 'A controlled-drug check at Rimu House is overdue.', true],
        MedicationAlertCatalogue::REVIEW_DUE => ['Medication review due', 'Aroha N.’s medication chart review is due by 5 Oct 2026. Kōwhai House.', 'A medication review is due.', false],
    ];

    /**
     * @return array<string, array{controlled: bool, inapp: array{title: string, message: string}, private: array<string, mixed>, open: array<string, mixed>}>
     */
    public function all(): array
    {
        $out = [];
        foreach (MedicationAlertCatalogue::built() as $type) {
            if (! isset(self::SAMPLES[$type])) {
                continue;
            }
            [$title, $message, $short, $controlled] = self::SAMPLES[$type];
            $alert = (new MedicationAlert)->forceFill([
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'short_message' => $short,
                'action_url' => '/emar',
                'severity' => 'warning',
                'controlled' => $controlled,
                'subject' => [],
            ]);
            $inapp = (new MedicationAlertNotification($alert, ['inapp']))->toArray(new \stdClass);
            $out[$type] = [
                'controlled' => $controlled,
                'inapp' => ['title' => (string) $inapp['title'], 'message' => (string) $inapp['message']],
                'private' => $this->outside($alert, true),
                'open' => $this->outside($alert, false),
            ];
        }

        return $out;
    }

    /** @return array{email: array{subject: string, lines: list<string>, action: string}, push: array{title: string, body: string}} */
    private function outside(MedicationAlert $alert, bool $private): array
    {
        $notification = new MedicationAlertNotification($alert, ['email', 'push'], $private);
        $mail = $notification->toMail(new \stdClass);
        $push = $notification->toPush(new \stdClass);

        return [
            'email' => [
                'subject' => (string) $mail->subject,
                'lines' => array_values(array_map('strval', [...$mail->introLines, ...$mail->outroLines])),
                'action' => (string) $mail->actionText,
            ],
            'push' => ['title' => $push['title'], 'body' => $push['body']],
        ];
    }
}
