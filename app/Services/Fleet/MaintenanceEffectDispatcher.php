<?php

namespace App\Services\Fleet;

use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Facades\DB;
use Throwable;

class MaintenanceEffectDispatcher
{
    public function __construct(private readonly UserSiteAccessService $siteAccess) {}

    public function dispatchDue(int $limit = 25): array
    {
        $ids = DB::table('fleet_maintenance_effects')
            ->whereIn('state', ['pending', 'retry'])
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')->limit(max(1, min(100, $limit)))->pluck('id');
        $results = [];
        foreach ($ids as $id) {
            $results[(int) $id] = $this->dispatchOne((int) $id);
        }

        return $results;
    }

    public function dispatchOne(int $effectId): string
    {
        try {
            return DB::transaction(function () use ($effectId): string {
                $effect = DB::table('fleet_maintenance_effects')->where('id', $effectId)
                    ->lockForUpdate()->first();
                abort_unless($effect, 404);
                if ($effect->state === 'delivered') {
                    return 'delivered';
                }
                abort_unless(in_array($effect->state, ['pending', 'retry'], true), 409);
                $payload = json_decode((string) $effect->payload_json, true, flags: JSON_THROW_ON_ERROR);
                if ($effect->effect_kind !== 'maintenance.released'
                    || ! is_array($payload)
                    || (int) ($payload['work_order_id'] ?? 0) !== (int) $effect->work_order_id) {
                    throw new \RuntimeException('Unsupported or invalid maintenance effect payload.');
                }
                $work = DB::table('fleet_work_orders')->where('id', $effect->work_order_id)->first();
                $asset = $work ? DB::table('assets')->where('id', $work->asset_id)->first() : null;
                if (! $work || ! $asset || ! $asset->site_id) {
                    throw new \RuntimeException('Maintenance effect lost its canonical asset site.');
                }
                $recipient = User::query()->find($work->assigned_to_user_id);
                if (! $recipient) {
                    throw new \RuntimeException('Maintenance effect has no accountable recipient.');
                }
                $this->siteAccess->assertCanUseCurrentStaffAtSite(
                    $recipient, (int) $recipient->id, (int) $asset->site_id,
                );
                $digest = hash('sha256', $effect->effect_key.'|'.$recipient->id);
                $notificationId = substr($digest, 0, 8).'-'.substr($digest, 8, 4).'-5'.substr($digest, 13, 3)
                    .'-a'.substr($digest, 17, 3).'-'.substr($digest, 20, 12);
                $otherHold = DB::table('fleet_maintenance_restrictions')->where('asset_id', $asset->id)
                    ->where('state', 'active')->exists();
                DB::table('notifications')->insertOrIgnore([
                    'id' => $notificationId,
                    'type' => 'fleet.maintenance.released',
                    'notifiable_type' => $recipient->getMorphClass(),
                    'notifiable_id' => $recipient->id,
                    'data' => json_encode([
                        'title' => 'Maintenance release recorded',
                        'message' => $otherHold
                            ? 'This work order was released; another maintenance restriction remains. Check current booking readiness.'
                            : 'This work order was released. Check current asset and booking readiness before use.',
                        'module' => 'fleet',
                        'asset_id' => $asset->id,
                        'work_order_id' => $work->id,
                        'url' => '/fleet-assets/maintenance/work-orders/'.$work->id,
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('fleet_maintenance_effects')->where('id', $effectId)->update([
                    'state' => 'delivered', 'attempts' => (int) $effect->attempts + 1,
                    'next_attempt_at' => null, 'last_error' => null, 'updated_at' => now(),
                ]);

                return 'delivered';
            }, 3);
        } catch (Throwable $error) {
            DB::transaction(function () use ($effectId, $error): void {
                $effect = DB::table('fleet_maintenance_effects')->where('id', $effectId)
                    ->lockForUpdate()->first();
                if (! $effect || $effect->state === 'delivered') {
                    return;
                }
                $attempts = (int) $effect->attempts + 1;
                DB::table('fleet_maintenance_effects')->where('id', $effectId)->update([
                    'state' => 'retry', 'attempts' => $attempts,
                    'next_attempt_at' => now()->addSeconds(min(3600, 30 * (2 ** min($attempts, 7)))),
                    'last_error' => mb_substr($error->getMessage(), 0, 1000), 'updated_at' => now(),
                ]);
            });

            return 'retry';
        }
    }
}
