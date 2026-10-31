<?php

namespace App\Services\HealthSafety;

use App\Jobs\DispatchProcedureReviewDelivery;
use App\Models\ProcedureReviewDelivery;
use App\Models\ProcedureReviewRequest;
use App\Models\Role;
use App\Models\RoleNotificationPreference;
use App\Models\SafeWorkProcedure;
use App\Models\SafeWorkProcedureVersion;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Notifications\AppEventNotification;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** Source-owned, database-only assignment delivery using the existing inbox/preferences. */
class ProcedureReviewNotifications
{
    // Shares the established personal assignment preference exposed in Settings.
    public const EVENT_KEY = 'tasks.assigned';

    public function __construct(private readonly SafeWorkProcedureAccessService $access) {}

    /** Called after the source assignment is saved, inside its canonical transaction. */
    public function recordAssignment(ProcedureReviewRequest $request, User $actor, ?int $previousOwnerId = null): ?ProcedureReviewDelivery
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Procedure assignment delivery must be staged in the source transaction.');
        }
        if ($previousOwnerId !== null && $previousOwnerId === (int) $request->assigned_to_user_id) {
            return null;
        }
        $delivery = ProcedureReviewDelivery::query()->firstOrCreate([
            'procedure_review_request_id' => $request->id,
            'assignment_revision' => $request->lock_version,
        ], [
            'id' => (string) Str::uuid(),
            'recipient_user_id' => $request->assigned_to_user_id,
            'initiated_by_user_id' => $actor->id,
            'status' => 'pending',
            'next_attempt_at' => now(),
        ]);
        if ($delivery->wasRecentlyCreated) {
            DB::afterCommit(fn () => $this->queue($delivery->id));
        }

        return $delivery;
    }

    public function queue(string $id): void
    {
        try {
            DispatchProcedureReviewDelivery::dispatch($id);
        } catch (Throwable $exception) {
            // The source command is already confirmed. Recovery sees its retained intent.
            Log::warning('Procedure review delivery queue acceptance failed', ['delivery_id' => $id, 'exception_type' => $exception::class]);
            try {
                ProcedureReviewDelivery::query()->whereKey($id)->whereIn('status', ['pending', 'failed'])->update([
                    'status' => 'failed', 'failure_code' => 'queue_unavailable', 'next_attempt_at' => now()->addMinute(), 'updated_at' => now(),
                ]);
            } catch (Throwable $receiptFailure) {
                Log::warning('Procedure delivery queue failure receipt unavailable', ['delivery_id' => $id, 'exception_type' => $receiptFailure::class]);
            }
        }
    }

    public function deliver(string $id): void
    {
        try {
            DB::transaction(function () use ($id): void {
                $intent = ProcedureReviewDelivery::query()->find($id);
                if (! $intent) {
                    return;
                }
                $source = ProcedureReviewRequest::query()->find($intent->procedure_review_request_id);
                if (! $source) {
                    return;
                }
                // Same governing lock order as the canonical source commands.
                $procedure = SafeWorkProcedure::query()->whereKey($source->safe_work_procedure_id)->lockForUpdate()->first();
                $request = ProcedureReviewRequest::query()->whereKey($source->id)->lockForUpdate()->first();
                $delivery = ProcedureReviewDelivery::query()->whereKey($id)->lockForUpdate()->first();
                if (! $delivery || ! in_array($delivery->status, ['pending', 'failed'], true) || $delivery->next_attempt_at?->isFuture()) {
                    return;
                }
                $delivery->attempts++;
                if (! $request) {
                    $this->skip($delivery, 'assignment_inactive');

                    return;
                }
                $superseded = ProcedureReviewDelivery::query()->where('procedure_review_request_id', $request->id)->where('assignment_revision', '>', $delivery->assignment_revision)->lock('for share nowait')->exists();
                if (! $procedure || (int) $request->safe_work_procedure_id !== (int) $procedure->id || $procedure->status === 'archived' || ! in_array($request->status, ['requested', 'in_review'], true) || (int) $request->assigned_to_user_id !== (int) $delivery->recipient_user_id || $superseded) {
                    $this->skip($delivery, 'assignment_inactive');

                    return;
                }
                $recipient = User::query()->find($delivery->recipient_user_id);
                if (! $recipient || ! $this->authorized($procedure, $request, $recipient)) {
                    $this->skip($delivery, 'authority_changed');

                    return;
                }
                // withCurrentViewer locks current staff/RBAC/Site evidence until this
                // transaction ends; retain its current instance for preference reads.
                $this->access->withCurrentViewer($recipient, function ($current, $reads, $sites) use ($delivery, $procedure, $request): void {
                    if (! $this->inAppEnabled($current, $reads)) {
                        $this->skip($delivery, 'preference_suppressed');

                        return;
                    }
                    $published = $procedure->published_version_id ? $reads->query(SafeWorkProcedureVersion::query())->where('safe_work_procedure_id', $procedure->id)->find($procedure->published_version_id) : null;
                    $publishedReadable = $published && $this->access->isApprovedVersion($published) && $this->access->audienceAllows($published->content_snapshot ?? [], $current, $sites);
                    $url = '/health-safety/procedures/'.$procedure->id.'?section=connections&request='.$request->id;
                    if (! $publishedReadable) {
                        $url .= '&revision=working';
                    }
                    $notification = new AppEventNotification([
                        'kind' => 'procedure_review_assignment', 'event_key' => self::EVENT_KEY,
                        'title' => 'Procedure review assigned',
                        'body' => 'Open the procedure connections to review your current work.',
                        'url' => $url,
                        'entity' => 'Procedure review', 'entity_id' => $request->id,
                    ]);
                    $notification->id = $delivery->id;
                    // Database notification and durable receipt commit together.
                    if (! DB::table('notifications')->where('id', $delivery->id)->exists()) {
                        $current->notify($notification);
                    }
                    $delivery->forceFill(['status' => 'delivered', 'delivered_at' => now(), 'failure_code' => null, 'next_attempt_at' => null])->save();
                });
            }, 3);
        } catch (Throwable $exception) {
            DB::transaction(function () use ($id): void {
                $delivery = ProcedureReviewDelivery::query()->whereKey($id)->lockForUpdate()->first();
                if (! $delivery || ! in_array($delivery->status, ['pending', 'failed'], true)) {
                    return;
                }
                $attempts = $delivery->attempts + 1;
                $delivery->forceFill(['status' => 'failed', 'attempts' => $attempts, 'failure_code' => 'delivery_failed', 'next_attempt_at' => now()->addSeconds(min(900, 30 * (2 ** min(5, $attempts - 1))))])->save();
            }, 3);
            throw $exception;
        }
    }

    private function authorized(SafeWorkProcedure $procedure, ProcedureReviewRequest $request, User $recipient): bool
    {
        try {
            return $this->access->withCurrentViewer($recipient, function ($current, $reads, $sites) use ($procedure, $request) {
                $this->access->assertSource($request, $current, $reads, $sites);
                $version = $reads->query(SafeWorkProcedureVersion::query())->where('safe_work_procedure_id', $procedure->id)->find($request->requested_version_id);

                return $current->canDo('procedures.view') && $current->canDo('procedures.manage') && $this->access->workingAllowed($procedure, $current, $sites) && $this->access->allSitesAllowed($procedure->applicable_sites ?? [], $sites, $current) && $version && $this->access->isApprovedVersion($version) && $this->access->audienceAllows($version->content_snapshot ?? [], $current, $sites);
            });
        } catch (HttpException $exception) {
            if (in_array($exception->getStatusCode(), [403, 404], true)) {
                return false;
            }
            throw $exception;
        }
    }

    private function inAppEnabled(User $recipient, CurrentAuthorizationReads $reads): bool
    {
        // Current locking reads keep preference changes ordered with this send.
        $override = $reads->query(UserNotificationPreference::query())->where('user_id', $recipient->id)->where('key', self::EVENT_KEY)->first();
        $roleIds = $recipient->roles->pluck('id');
        if ($roleIds->isEmpty() && filled($recipient->role)) {
            $roleIds = $reads->query(Role::query())->where('name', $recipient->role)->pluck('id');
        }
        $roleDefaults = $reads->query(RoleNotificationPreference::query())->whereIn('role_id', $roleIds)->where('key', self::EVENT_KEY)->get();
        if ($override) {
            return (bool) $override->enabled && (bool) $override->channel_inapp;
        }

        return $roleDefaults->isEmpty() || $roleDefaults->contains(fn ($preference) => (bool) $preference->enabled && (bool) $preference->channel_inapp);
    }

    private function skip(ProcedureReviewDelivery $delivery, string $code): void
    {
        $delivery->forceFill(['status' => 'skipped', 'failure_code' => $code, 'next_attempt_at' => null])->save();
    }

    /** A bounded sweep; failed transport never fabricates successful delivery. */
    public function recoverPending(int $limit = 30): int
    {
        $ids = ProcedureReviewDelivery::query()->whereIn('status', ['pending', 'failed'])
            ->where('next_attempt_at', '<=', now())->orderBy('next_attempt_at')->limit(max(1, min(100, $limit)))->pluck('id');
        foreach ($ids as $id) {
            try {
                $this->deliver($id);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $ids->count();
    }
}
