<?php

namespace App\Services\Fleet;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\FleetFinanceReviewRequest;
use App\Models\User;
use App\Notifications\AppEventNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Transactional delivery intent, recovered by the scheduler after worker or process failure. */
final class FinanceReviewNotices
{
    public function record(FleetFinanceReviewRequest $request, string $kind, ?string $identity = null): void
    {
        DB::table('fleet_finance_review_notices')->insertOrIgnore([
            'review_request_id' => $request->id, 'kind' => $kind,
            'dedupe_key' => $identity ?? $request->id.':'.$kind.':'.$request->lock_version,
            'payload' => json_encode(['version' => $request->lock_version, 'status' => $request->status,
                'due_on' => $request->due_on?->toDateString(), 'assigned_to_user_id' => $request->assigned_to_user_id]),
            'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function reminders(): void
    {
        FleetFinanceReviewRequest::query()->where('status', 'submitted')->whereNotNull('due_on')
            ->whereDate('due_on', '<=', today('Pacific/Auckland'))->chunkById(100, function ($requests): void {
                foreach ($requests as $request) {
                    $this->record($request, 'due', $request->id.':due:'.$request->lock_version.':'.today('Pacific/Auckland')->toDateString());
                }
            });
    }

    public function deliver(int $id): void
    {
        try {
            DB::transaction(function () use ($id): void {
                $notice = DB::table('fleet_finance_review_notices')->where('id', $id)->lockForUpdate()->first();
                if (! $notice || $notice->delivered_at || now()->lt($notice->available_at)) {
                    return;
                }
                $request = FleetFinanceReviewRequest::with('asset')->findOrFail($notice->review_request_id);
                $finance = app(VehicleFinanceService::class);
                $requesterNotice = in_array($notice->kind, ['changes_requested', 'resolved', 'declined'], true);
                $payload = json_decode($notice->payload, true);
                // Recheck the saved context: the due date or reviewer may have changed while delivery was queued.
                $obsolete = $requesterNotice
                    ? $request->status !== $notice->kind
                    : ($request->status !== 'submitted' || ($notice->kind !== 'due' && $request->lock_version !== $payload['version']));
                if ($notice->kind === 'due') {
                    $obsolete = $obsolete || ! $request->due_on || $request->due_on->toDateString() > today('Pacific/Auckland')->toDateString()
                        || $request->due_on->toDateString() !== ($payload['due_on'] ?? null)
                        || $request->assigned_to_user_id !== ($payload['assigned_to_user_id'] ?? null);
                }
                if ($obsolete) {
                    DB::table('fleet_finance_review_notices')->where('id', $id)->update(['delivered_at' => now()]);

                    return;
                }
                User::query()->whereNotNull('approved_at')
                    ->when($requesterNotice, fn ($q) => $q->whereKey($request->requested_by_user_id))
                    ->when(! $requesterNotice && $request->assigned_to_user_id, fn ($q) => $q->whereKey($request->assigned_to_user_id))
                    ->chunkById(100, function ($users) use ($notice, $request, $finance, $requesterNotice): void {
                        foreach ($users as $user) {
                            if ($requesterNotice) {
                                $allowed = $finance->canView($user) && app(SecurityDevicesAccessService::class)->fleetVehicle($user, $request->asset_id) !== null;
                            } else {
                                $allowed = $user->id !== $request->requested_by_user_id && $finance->canDecide($user)
                                    && ($user->canDo('finance.assets.view') || $user->canDo('finance.ap.view'))
                                    && in_array((int) $request->asset?->site_id, $finance->financeSiteIds($user), true);
                            }
                            if (! $allowed) {
                                continue;
                            }
                            $uuid = (string) Str::uuid();
                            // The notice row remains locked through these database-only inserts and completion.
                            DB::table('notifications')->insert([
                                'id' => $uuid, 'type' => AppEventNotification::class,
                                'notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->id,
                                'data' => json_encode(['kind' => 'fleet_finance_review_request', 'entity_id' => $request->id,
                                    'title' => $request->reference_number.' · '.ucfirst(str_replace('_', ' ', $notice->kind)),
                                    'body' => $request->typeLabel(),
                                    'url' => $requesterNotice ? '/fleet-assets/vehicles/'.$request->asset_id.'?view=finance' : '/finance/vehicle-reviews?request='.$request->id]),
                                'created_at' => now(), 'updated_at' => now(),
                            ]);
                        }
                    });
                DB::table('fleet_finance_review_notices')->where('id', $id)->update(['delivered_at' => now(), 'last_error' => null, 'updated_at' => now()]);
            }, 3);
        } catch (\Throwable $error) {
            DB::table('fleet_finance_review_notices')->where('id', $id)->whereNull('delivered_at')->update([
                'attempts' => DB::raw('attempts + 1'), 'available_at' => now()->addMinutes(5),
                'last_error' => 'delivery_failed', 'updated_at' => now(),
            ]);
            throw $error;
        }
    }
}
