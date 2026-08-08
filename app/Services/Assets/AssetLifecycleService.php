<?php

namespace App\Services\Assets;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class AssetLifecycleService
{
    public function __construct(
        private readonly SecurityDevicesAccessService $access,
    ) {}

    public function retire(User $actor, Asset $asset): Asset
    {
        return DB::transaction(function () use ($actor, $asset): Asset {
            $asset = $this->access->assignableAsset($actor, (int) $asset->getKey(), true) ?? abort(404);
            Gate::forUser($actor)->authorize('delete', $asset);

            if ($asset->assignments()->whereNull('released_at')->lockForUpdate()->first(['id'])) {
                throw ValidationException::withMessages([
                    'asset' => 'Release the current Asset assignment before retiring it.',
                ]);
            }
            if ($asset->activeDeviceLinks()->lockForUpdate()->first(['id'])) {
                throw ValidationException::withMessages([
                    'asset' => 'Unlink active Devices before retiring this Asset.',
                ]);
            }

            if ($asset->status !== 'retired') {
                $asset->update([
                    'status' => 'retired',
                    'updated_by_user_id' => $actor->id,
                ]);
                AuditLogger::logOrFail('assets.retired', $asset, [
                    'site_id' => $asset->site_id,
                    'client_id' => $asset->client_id,
                ]);
            }

            return $asset->fresh();
        }, 3);
    }
}
