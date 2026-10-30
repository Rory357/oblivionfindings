<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P11 B2 C1 (review): no burst of repeat notifications at deploy.
 *
 * Before this update, four medication checks told people themselves and kept
 * their own record of who had been told. The new alert log only knows what
 * it raised, so its first run would tell everyone again about things already
 * told. This writes an open alert-log record — carried over at deploy, sending
 * nothing — for each subject people were already told about and that may
 * still be true:
 *
 *  - overdue doses: each overdue spell notified in the last 2 days (the
 *    overdue job looks at yesterday and today);
 *  - stock running low: stock still at or below its reorder level that was
 *    alerted (it re-alerted daily, so any still-low stock was);
 *  - repeated refusals: each person and medicine notified in the last 2 days
 *    (an open cluster was re-notified daily);
 *  - competency renewals: each assessment told about that hasn't ended yet.
 *
 * A carried-over record closes like any other — the next check finds the
 * subject no longer true and deals with it — and only then can it alert
 * again. down() removes the records this wrote.
 */
return new class extends Migration
{
    public const EVENT = 'carried_over_at_deploy';

    private const NOTE = 'Carried over at deploy: people were told about this before Medication Settings chose who is told. Nothing was sent again.';

    public function up(): void
    {
        if (! Schema::hasTable('medication_alerts') || ! Schema::hasTable('medication_alert_events')) {
            return;
        }
        $since = now()->subDays(2);

        if (Schema::hasTable('notifications')) {
            $this->overdue($since);
            $this->refusals($since);
            $this->renewals();
        }
        if (Schema::hasTable('client_medication_stocks')) {
            $this->lowStock();
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('medication_alerts') || ! Schema::hasTable('medication_alert_events')) {
            return;
        }
        $ids = DB::table('medication_alert_events')->where('event', self::EVENT)->pluck('medication_alert_id')->all();
        foreach (array_chunk($ids, 500) as $chunk) {
            DB::table('medication_alert_events')->whereIn('medication_alert_id', $chunk)->delete();
            DB::table('medication_alert_recipients')->whereIn('medication_alert_id', $chunk)->delete();
            DB::table('medication_alerts')->whereIn('id', $chunk)->delete();
        }
    }

    private function overdue(Carbon $since): void
    {
        foreach ($this->notified('App\\Notifications\\MedicationOverdueNotification', $since) as [$data, $at]) {
            $doseKey = is_string($data['dose_key'] ?? null) ? $data['dose_key'] : '';
            // "order@2026-10-02T19:00Z~spell"; notifications from before doses
            // carried a key can't be matched, so they aren't carried.
            if (preg_match('/^(\d+)@[^~]+~\d+$/', $doseKey, $match) !== 1) {
                continue;
            }
            $order = $this->order((int) $match[1]);
            if ($order === null) {
                continue;
            }
            $this->carry('overdue', $doseKey, $at, 'Overdue dose', '/emar/mar?client_id='.$order->client_id, $order, [
                'client_id' => (int) $order->client_id,
                'client_medication_id' => (int) $order->id,
                'dose_key' => $doseKey,
            ]);
        }
    }

    private function refusals(Carbon $since): void
    {
        foreach ($this->notified('App\\Notifications\\MedicationRefusalClusterNotification', $since) as [$data, $at]) {
            $order = is_numeric($data['client_medication_id'] ?? null) ? $this->order((int) $data['client_medication_id']) : null;
            if ($order === null || (int) $order->client_id !== (int) ($data['client_id'] ?? 0)) {
                continue;
            }
            $this->carry('refusals', 'refusals:'.$order->id, $at, 'Repeated refusals', '/emar/mar?client_id='.$order->client_id, $order, [
                'client_id' => (int) $order->client_id,
                'client_medication_id' => (int) $order->id,
            ]);
        }
    }

    private function renewals(): void
    {
        $today = now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();
        foreach ($this->notified('App\\Notifications\\MedicationCompetencyExpiringNotification', null) as [$data, $at, $userId]) {
            $assessmentId = $data['assessment_id'] ?? null;
            try {
                $expiry = is_string($data['expiry_date'] ?? null)
                    ? Carbon::createFromFormat('!d/m/Y', $data['expiry_date'])
                    : false;
            } catch (Throwable) {
                $expiry = false;
            }
            if (! is_numeric($assessmentId) || ! $expiry instanceof Carbon || $expiry->toDateString() < $today) {
                continue;
            }
            $siteId = Schema::hasTable('hr_employee_profiles')
                ? DB::table('hr_employee_profiles')->where('user_id', $userId)->whereNull('deleted_at')->value('primary_site_id')
                : null;
            $this->carry('renewals', 'renewal:'.(int) $assessmentId.':'.$expiry->toDateString(), $at, 'Competency renewal due', '/emar/safety/eligibility?view=renewals', null, [
                'assessment_id' => (int) $assessmentId,
            ], staffUserId: (int) $userId, siteId: $siteId !== null ? (int) $siteId : null);
        }
    }

    private function lowStock(): void
    {
        $rows = DB::table('client_medication_stocks as stock')
            ->join('client_medications as orders', 'orders.id', '=', 'stock.client_medication_id')
            ->join('clients', 'clients.id', '=', 'orders.client_id')
            ->whereNotNull('stock.last_reorder_alert_at')
            ->where('stock.reorder_level', '>', 0)
            ->whereColumn('stock.on_hand', '<=', 'stock.reorder_level')
            ->whereNotNull('clients.site_id')
            ->get(['stock.id', 'stock.last_reorder_alert_at', 'orders.id as order_id', 'orders.client_id', 'orders.controlled_drug', 'clients.site_id']);
        foreach ($rows as $row) {
            $order = (object) ['id' => $row->order_id, 'client_id' => $row->client_id, 'controlled_drug' => $row->controlled_drug, 'site_id' => $row->site_id];
            $this->carry('stock', 'stock:'.$row->id, Carbon::parse($row->last_reorder_alert_at), 'Stock running low', '/emar/stock', $order, [
                'client_id' => (int) $row->client_id,
                'client_medication_id' => (int) $row->order_id,
                'stock_id' => (int) $row->id,
            ]);
        }
    }

    /**
     * Stored notifications of one kind: [data, sent at, user id].
     *
     * @return iterable<int, array{0: array<string, mixed>, 1: Carbon, 2: int}>
     */
    private function notified(string $type, ?Carbon $since): iterable
    {
        $rows = DB::table('notifications')
            ->where('type', $type)
            ->when($since, fn ($query) => $query->where('created_at', '>=', $since))
            ->orderBy('created_at')
            ->cursor();
        foreach ($rows as $row) {
            $data = json_decode((string) $row->data, true);
            if (is_array($data)) {
                yield [$data, Carbon::parse($row->created_at), (int) $row->notifiable_id];
            }
        }
    }

    private function order(int $id): ?object
    {
        return DB::table('client_medications as orders')
            ->join('clients', 'clients.id', '=', 'orders.client_id')
            ->where('orders.id', $id)
            ->whereNotNull('clients.site_id')
            ->first(['orders.id', 'orders.client_id', 'orders.controlled_drug', 'clients.site_id']);
    }

    /**
     * One open record, unless one is already open for the subject.
     *
     * @param  array<string, mixed>  $subject
     */
    private function carry(
        string $type,
        string $subjectKey,
        Carbon $at,
        string $title,
        string $actionUrl,
        ?object $order,
        array $subject,
        ?int $staffUserId = null,
        ?int $siteId = null,
    ): void {
        $key = mb_substr($type.':'.$subjectKey, 0, 188);
        $now = now();
        $inserted = DB::table('medication_alerts')->insertOrIgnore([
            'type' => $type,
            'dedupe_key' => $key,
            'open_key' => $key,
            'site_id' => $order !== null ? (int) $order->site_id : $siteId,
            'client_id' => $order !== null ? (int) $order->client_id : null,
            'staff_user_id' => $staffUserId,
            'controlled' => (bool) ($order->controlled_drug ?? false),
            'title' => $title,
            'message' => self::NOTE,
            'short_message' => 'Carried over at deploy.',
            'action_url' => $actionUrl,
            'severity' => 'warning',
            'subject' => json_encode([...$subject, 'carried_over_at_deploy' => true]),
            'follow_up' => false,
            'status' => 'open',
            'raised_at' => $at,
            'reached_nobody' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($inserted === 0) {
            return;
        }
        $alertId = DB::table('medication_alerts')->where('open_key', $key)->value('id');
        DB::table('medication_alert_events')->insert([
            'medication_alert_id' => $alertId,
            'event' => self::EVENT,
            'user_id' => null,
            'detail' => json_encode(['note' => self::NOTE, 'told_at' => $at->toIso8601String()]),
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
