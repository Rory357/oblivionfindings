<?php

namespace App\Services\Operations;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class RosterTemplateReceipt
{
    private ?int $requesterId = null;

    public function begin(Request $request): bool
    {
        $request->session()->forget('roster_template_result');
        $this->requesterId = $request->user()?->id;
        try {
            return $this->isPhysicalRoot();
        } catch (Throwable) {
            return false;
        }
    }

    protected function isPhysicalRoot(): bool
    {
        return DB::transactionLevel() === 0 && ! DB::connection()->getPdo()->inTransaction();
    }

    public function committed(bool $rootEntry, RosterTemplateCommandResult $result): ?array
    {
        if (! $rootEntry) {
            return null;
        }
        try {
            if (! $this->isPhysicalRoot() || $result->actorId <= 0 || $result->actorId !== $this->requesterId) {
                return null;
            }

            return ['version' => 1, 'scope' => 'library', 'action' => $result->action, 'request_id' => $result->requestId,
                'actor_id' => $result->actorId, 'template_id' => $result->templateId, 'copy_id' => $result->copyId,
                'outcome' => $result->outcome, 'changed' => $result->changed, 'values_hash' => $result->valuesHash,
                'expected_source' => $result->expectedSource, 'source_revision' => $result->expectedSource['source_revision'] ?? null,
                'result_revision' => $result->resultRevision, 'template_shifts_count' => $result->templateShiftsCount,
                'committed_at' => now('UTC')->startOfSecond()->format('Y-m-d\TH:i:s.000\Z')];
        } catch (Throwable $exception) {
            try {
                Log::warning('Committed roster template result could not be presented', ['action' => $result->action, 'exception_class' => $exception::class]);
            } catch (Throwable) {
            }

            return null;
        }
    }
}
