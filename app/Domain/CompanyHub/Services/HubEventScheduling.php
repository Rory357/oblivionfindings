<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubEventDraft;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** The existing local Hub scheduler releases private event editions at their approved instant. */
class HubEventScheduling
{
    public function __construct(private readonly HubEventPublishing $publishing) {}

    public function publishDue(int $limit = 100): array
    {
        $result = ['published' => 0, 'blocked' => 0, 'failed' => 0];
        if (! $this->publishing->schedulingReady()) {
            return $result;
        }
        $due = HubEventDraft::where('state', 'scheduled')->whereNull('archived_at')->where('publish_at', '<=', now())
            ->orderBy('publish_at')->orderBy('id')->limit(max(1, min(100, $limit)))->get(['id', 'version']);
        foreach ($due as $draft) {
            try {
                $result['published'] += (int) $this->publishing->releaseScheduled($draft->id, $draft->version);
            } catch (ValidationException|HttpExceptionInterface $error) {
                $result['blocked'] += (int) DB::transaction(function () use ($draft): bool {
                    $current = HubEventDraft::whereKey($draft->id)->lockForUpdate()->first();
                    if (! $current || $current->state !== 'scheduled' || $current->version !== $draft->version) {
                        return false;
                    }
                    $current->update(['state' => 'schedule_blocked', 'schedule_blocked_at' => now(), 'schedule_error' => 'publication_unavailable', 'version' => $current->version + 1,
                        'feedback' => 'This scheduled event could not be published. Cancel its schedule, then review the current author, permissions, audience, event version and files before scheduling again.']);

                    return true;
                });
            } catch (\Throwable $error) {
                $result['failed']++;
                Log::warning('Scheduled Hub event publication will retry', ['draft_id' => $draft->id, 'exception' => $error::class]);
            }
        }

        return $result;
    }
}
