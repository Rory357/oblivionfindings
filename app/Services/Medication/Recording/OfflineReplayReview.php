<?php

namespace App\Services\Medication\Recording;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationFollowup;
use App\Models\User;
use App\Services\MarScheduleService;
use App\Services\Medication\Followups\MedicationFollowupService;
use Carbon\CarbonImmutable;

/**
 * eMAR audit Lane A3 (EA-004, EA-049, EA-050, EA-131): a dose saved on a
 * device that did not reach the chart is never just discarded. Each one
 * gets ONE lead follow-up ("Dose saved on a device, not recorded") with what
 * the device captured, so a lead can check the person, the MAR and the
 * paper record. The follow-up is keyed by the request, so the server and
 * the device reporting the same dose never make two.
 */
final class OfflineReplayReview
{
    public const TYPE = 'offline_review';

    /** Why the dose didn't reach the chart. */
    public const DUPLICATE = 'duplicate';

    public const REFUSED = 'refused';

    public const TOO_OLD = 'too_old';

    public const ORDER_CHANGED = 'order_changed';

    public const ACCESS = 'access';

    public const NOT_RECORDED = 'not_recorded';

    public function __construct(
        private readonly MedicationFollowupService $followups,
        private readonly MarScheduleService $schedule,
    ) {}

    /** Hours after which a dose saved on a device is too old to send (EA-131). */
    public static function maxAgeHours(): int
    {
        return max(1, (int) config('medications.offline_replay_max_hours', 12));
    }

    /**
     * EA-131: a queued dose older than the policy is not accepted as a new
     * administration; it goes to reviewed paper recovery. Its clinical time
     * (or, failing that, when the device captured it) decides.
     *
     * @param  array<string, mixed>  $data
     */
    public function tooOld(array $data): bool
    {
        if (! (bool) ($data['queued_offline'] ?? false)) {
            return false;
        }
        $raw = $data['administered_at'] ?? $data['captured_offline_at'] ?? null;
        if (! filled($raw)) {
            return false;
        }
        try {
            $at = CarbonImmutable::instance($this->schedule->parseWorkerDateTime((string) $raw))->utc();
        } catch (\Throwable) {
            return false;
        }

        return $at->lt(CarbonImmutable::now('UTC')->subHours(self::maxAgeHours()));
    }

    public static function tooOldMessage(): string
    {
        return 'Too old to sync — this dose was saved on the device more than '.self::maxAgeHours()
            .' hours ago, so it wasn’t added to the chart. Use reviewed paper recovery; your lead has a follow-up.';
    }

    /**
     * Raise (once per request) the lead follow-up. Call inside the source
     * transaction, with the person locked.
     *
     * @param  array<string, mixed>  $data  the submission as the device sent it
     */
    public function raise(
        Client $client,
        ClientMedication $medication,
        User $capturedBy,
        array $data,
        string $reasonKey,
        string $reason,
        ?ClientMedicationAdministration $existing = null,
    ): MedicationFollowup {
        $uuid = (string) $data['client_request_uuid'];

        return $this->followups->ensure(
            'offline-review:'.$uuid,
            self::TYPE,
            $client,
            $medication,
            $existing,
            null,
            CarbonImmutable::now('UTC'),
            array_filter([
                'reason_key' => $reasonKey,
                'reason' => mb_substr($reason, 0, 500),
                'client_request_uuid' => $uuid,
                'outcome' => (string) ($data['status'] ?? 'given'),
                'captured_by' => (int) $capturedBy->id,
                'captured_by_name' => $capturedBy->name,
                'captured_offline_at' => $this->text($data['captured_offline_at'] ?? null),
                'administered_at' => $this->text($data['administered_at'] ?? null),
                'scheduled_for' => $this->text($data['scheduled_for'] ?? null),
                'origin_device_id' => $this->text($data['origin_device_id'] ?? null),
                'duplicate_of_administration_id' => $existing?->id,
            ], fn ($value) => $value !== null && $value !== ''),
        );
    }

    /** The reason key for a refusal the device reports by its HTTP status. */
    public static function reasonForStatus(?int $status, ?string $errorField = null): string
    {
        return match (true) {
            $errorField === 'order_changed' => self::ORDER_CHANGED,
            $errorField === 'queued_offline' => self::TOO_OLD,
            $status === 409 => self::DUPLICATE,
            $status === 403 => self::ACCESS,
            $status === 404, $status === 410 => self::ORDER_CHANGED,
            $status === 422, $status === 200, $status === 400 => self::REFUSED,
            default => self::NOT_RECORDED,
        };
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, 64) : null;
    }
}
