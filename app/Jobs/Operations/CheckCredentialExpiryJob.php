<?php

namespace App\Jobs\Operations;

use App\Models\StaffCredential;
use App\Models\User;
use App\Services\Operations\OpsNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckCredentialExpiryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $organizationId
    ) {}

    public function handle(OpsNotificationService $notificationService): void
    {
        $staffIds = User::where('organization_id', $this->organizationId)
            ->staff()
            ->pluck('id');

        $expiringSoon = StaffCredential::whereIn('user_id', $staffIds)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays(30))
            ->where('expires_at', '>=', now())
            ->with('user:id,name')
            ->get();

        foreach ($expiringSoon as $credential) {
            $notificationService->notifySpecific(
                $credential->user_id,
                $this->organizationId,
                'Credential Expiring Soon',
                sprintf(
                    'Your %s credential expires on %s. Please arrange renewal.',
                    $credential->type,
                    $credential->expires_at->format('d M Y')
                ),
                'credential.expiring',
                ['credential_id' => $credential->id, 'type' => $credential->type]
            );
        }

        Log::info("Checked credential expiry for org {$this->organizationId}: {$expiringSoon->count()} expiring.");
    }
}
