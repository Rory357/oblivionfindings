<?php

namespace App\Console\Commands;

use App\Domain\SecurityDevices\Cctv\Models\CctvLiveSession;
use App\Domain\SecurityDevices\Cctv\Models\CctvRequestReceipt;
use App\Domain\SecurityDevices\Cctv\Services\CctvSessions;
use App\Domain\SecurityDevices\Cctv\Services\HikvisionDiscoveryRequests;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ExpireCctvLiveSessions extends Command
{
    protected $signature = 'cctv:expire-live-sessions';

    protected $description = 'Expire CCTV leases and erase transient signalling without retrying media.';

    public function handle(CctvSessions $sessions): int
    {
        CctvLiveSession::query()->whereNotIn('status', ['stopped', 'expired', 'revoked', 'failed'])->where('authority_until', '<=', now())->select('id')->chunkById(100, function ($rows) use ($sessions): void {
            foreach ($rows as $row) {
                DB::transaction(function () use ($row, $sessions): void {
                    $session = CctvLiveSession::query()->whereKey($row->id)->lockForUpdate()->first();
                    if ($session) {
                        $sessions->expire($session);
                    }
                });
            }
        });
        app(HikvisionDiscoveryRequests::class)->expirePending();
        // Retain receipt identity/hash for replay denial, not expired sealed material.
        CctvRequestReceipt::query()->whereNotNull('expires_at')->where('expires_at', '<=', now())->whereNotNull('response')->update(['response' => null]);

        return self::SUCCESS;
    }
}
