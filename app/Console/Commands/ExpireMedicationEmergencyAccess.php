<?php

namespace App\Console\Commands;

use App\Models\ClientBreakGlassAccess;
use App\Services\Medication\EmergencyAccess\EmergencyAccessService;
use Illuminate\Console\Command;

class ExpireMedicationEmergencyAccess extends Command
{
    protected $signature = 'emar:expire-emergency-access';

    protected $description = 'Record ended emergency access grants at their actual expiry time.';

    public function handle(EmergencyAccessService $service): int
    {
        $failed = 0;
        ClientBreakGlassAccess::query()->whereNull('ended_at')->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())->orderBy('id')
            ->each(function (ClientBreakGlassAccess $grant) use ($service, &$failed): void {
                try {
                    $service->end(null, $grant);
                } catch (\Throwable $exception) {
                    $failed++;
                    report($exception);
                    $this->warn('EA-'.$grant->id.' could not be closed in the audit trail; its expired authority remains unavailable.');
                }
            });

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
