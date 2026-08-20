<?php

namespace App\Domain\Privacy\Services;

use App\Models\DataSubjectRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * The single write boundary for DataSubjectRequest lifecycle and assignment.
 *
 * Privacy requests are organisation-wide records. The explicit
 * privacy.processRequests permission is therefore the authority boundary;
 * generic roles and Site access do not confer lifecycle access.
 */
class DataSubjectRequestLifecycleService
{
    public const AUDIT_CREATED = 'privacy.request.created';

    public const AUDIT_VERIFIED = 'privacy.request.identityVerified';

    public const AUDIT_COMPLETED = 'privacy.request.completed';

    public const AUDIT_REFUSED = 'privacy.request.refused';

    public const AUDIT_EXTENDED = 'privacy.request.deadlineExtended';

    public const AUDIT_ASSIGNED = 'privacy.request.assigned';

    private const TRANSITIONS = [
        'verify' => [
            'received' => 'in_progress',
            'under_review' => 'in_progress',
            'identity_verification' => 'in_progress',
        ],
        'complete' => [
            'in_progress' => 'completed',
        ],
        'refuse' => [
            'in_progress' => 'rejected',
        ],
        'extend' => [
            'received' => 'received',
            'under_review' => 'under_review',
            'identity_verification' => 'identity_verification',
            'in_progress' => 'in_progress',
        ],
        'assign' => [
            'received' => 'received',
            'under_review' => 'under_review',
            'identity_verification' => 'identity_verification',
            'in_progress' => 'in_progress',
        ],
    ];

    private const INTAKE_ATTRIBUTES = [
        'request_type',
        'client_id',
        'user_id',
        'subject_name',
        'subject_email',
        'request_details',
        'specific_data_requested',
        'received_at',
        'due_date',
    ];

    /** @param array<string, mixed> $attributes */
    public function intake(
        User $actor,
        array $attributes,
        string $source,
        ?int $assigneeId = null,
        ?string $verificationMethod = null,
    ): DataSubjectRequest {
        $this->authorize($actor);

        $source = trim($source);
        if ($source === '') {
            throw new ConflictHttpException('Privacy request intake requires a source.');
        }

        if ($verificationMethod !== null) {
            $verificationMethod = $this->requireProvenance(
                $verificationMethod,
                'Identity verification requires a verification method.',
            );
        }

        return DB::transaction(function () use (
            $actor,
            $assigneeId,
            $attributes,
            $source,
            $verificationMethod,
        ): DataSubjectRequest {
            $intakeAttributes = array_intersect_key($attributes, array_flip(self::INTAKE_ATTRIBUTES));
            $created = DataSubjectRequest::create(array_merge($intakeAttributes, [
                'identity_verified' => 'pending',
                'status' => 'identity_verification',
                'created_by' => $actor->id,
            ]));
            $locked = $this->lock($created);

            $this->audit(self::AUDIT_CREATED, $locked, $actor, [
                'actor_id' => $actor->id,
                'source' => $source,
                'status' => $locked->status,
                'request_type' => $locked->request_type,
            ]);

            if ($assigneeId !== null) {
                $locked = $this->assignLocked($locked, $actor, $assigneeId);
            }

            if ($verificationMethod !== null) {
                $locked = $this->verifyIdentityLocked($locked, $actor, $verificationMethod);
            }

            return $locked->fresh();
        });
    }

    public function verifyIdentity(
        DataSubjectRequest $request,
        User $actor,
        string $verificationMethod,
    ): DataSubjectRequest {
        $this->authorize($actor);

        return DB::transaction(function () use ($request, $actor, $verificationMethod): DataSubjectRequest {
            $locked = $this->lock($request);
            $verificationMethod = $this->requireProvenance(
                $verificationMethod,
                'Identity verification requires a verification method.',
            );

            return $this->verifyIdentityLocked($locked, $actor, $verificationMethod)->fresh();
        });
    }

    public function complete(
        DataSubjectRequest $request,
        User $actor,
        ?string $completionNotes,
    ): DataSubjectRequest {
        $this->authorize($actor);

        return DB::transaction(function () use ($request, $actor, $completionNotes): DataSubjectRequest {
            $locked = $this->lock($request);

            if ($locked->status === 'completed') {
                if (
                    $locked->completed_by_user_id === $actor->id
                    && $locked->completion_notes === $completionNotes
                ) {
                    return $locked;
                }

                throw new ConflictHttpException('This request has already been completed with different details.');
            }

            $this->assertVerified($locked, 'completed');
            $from = $locked->status;
            $to = $this->transitionTarget('complete', $locked);

            $locked->update([
                'status' => $to,
                'completed_at' => now(),
                'completed_by_user_id' => $actor->id,
                'completion_notes' => $completionNotes,
                'updated_by' => $actor->id,
            ]);

            $this->audit(self::AUDIT_COMPLETED, $locked, $actor, [
                'actor_id' => $actor->id,
                'from_status' => $from,
                'to_status' => $to,
            ]);

            return $locked->fresh();
        });
    }

    public function refuse(
        DataSubjectRequest $request,
        User $actor,
        string $reason,
        string $legalBasis,
    ): DataSubjectRequest {
        $this->authorize($actor);

        return DB::transaction(function () use ($request, $actor, $reason, $legalBasis): DataSubjectRequest {
            $locked = $this->lock($request);
            $reason = $this->requireProvenance($reason, 'Refusal requires a reason.');
            $legalBasis = $this->requireProvenance($legalBasis, 'Refusal requires a legal basis.');

            if ($locked->status === 'rejected') {
                if (
                    $locked->refused_by_user_id === $actor->id
                    && $locked->rejection_reason === $reason
                    && $locked->rejection_legal_basis === $legalBasis
                ) {
                    return $locked;
                }

                throw new ConflictHttpException('This request has already been refused with different details.');
            }

            $this->assertVerified($locked, 'refused');
            $from = $locked->status;
            $to = $this->transitionTarget('refuse', $locked);

            $locked->update([
                'status' => $to,
                'refused_at' => now(),
                'refused_by_user_id' => $actor->id,
                'rejection_reason' => $reason,
                'rejection_legal_basis' => $legalBasis,
                'updated_by' => $actor->id,
            ]);

            $this->audit(self::AUDIT_REFUSED, $locked, $actor, [
                'actor_id' => $actor->id,
                'from_status' => $from,
                'to_status' => $to,
            ]);

            return $locked->fresh();
        });
    }

    public function extend(
        DataSubjectRequest $request,
        User $actor,
        string $reason,
        string $extendedDueDate,
    ): DataSubjectRequest {
        $this->authorize($actor);

        return DB::transaction(function () use ($request, $actor, $reason, $extendedDueDate): DataSubjectRequest {
            $locked = $this->lock($request);
            $reason = $this->requireProvenance($reason, 'Deadline extension requires a reason.');
            $normalisedDate = $this->futureDate($extendedDueDate);
            $this->transitionTarget('extend', $locked);

            if (
                $locked->extension_requested
                && $locked->extension_reason === $reason
                && $locked->extended_due_date?->toDateString() === $normalisedDate
            ) {
                return $locked;
            }

            $locked->update([
                'extension_requested' => true,
                'extension_reason' => $reason,
                'extended_due_date' => $normalisedDate,
                'updated_by' => $actor->id,
            ]);

            $this->audit(self::AUDIT_EXTENDED, $locked, $actor, [
                'actor_id' => $actor->id,
                'status' => $locked->status,
                'extended_due_date' => $normalisedDate,
            ]);

            return $locked->fresh();
        });
    }

    public function assign(
        DataSubjectRequest $request,
        User $actor,
        ?int $assigneeId,
    ): DataSubjectRequest {
        $this->authorize($actor);

        return DB::transaction(function () use ($request, $actor, $assigneeId): DataSubjectRequest {
            $locked = $this->lock($request);

            return $this->assignLocked($locked, $actor, $assigneeId)->fresh();
        });
    }

    private function verifyIdentityLocked(
        DataSubjectRequest $locked,
        User $actor,
        string $verificationMethod,
    ): DataSubjectRequest {
        if ($locked->status === 'in_progress' && $locked->identity_verified === 'verified') {
            if (
                $locked->verified_by_user_id === $actor->id
                && $locked->verification_method === $verificationMethod
            ) {
                return $locked;
            }

            throw new ConflictHttpException('Identity verification has already been recorded with different details.');
        }

        $from = $locked->status;
        $to = $this->transitionTarget('verify', $locked);

        $locked->update([
            'identity_verified' => 'verified',
            'identity_verified_at' => now(),
            'verified_by_user_id' => $actor->id,
            'verification_method' => $verificationMethod,
            'status' => $to,
            'updated_by' => $actor->id,
        ]);

        $this->audit(self::AUDIT_VERIFIED, $locked, $actor, [
            'actor_id' => $actor->id,
            'from_status' => $from,
            'to_status' => $to,
        ]);

        return $locked;
    }

    private function assignLocked(
        DataSubjectRequest $locked,
        User $actor,
        ?int $assigneeId,
    ): DataSubjectRequest {
        $this->transitionTarget('assign', $locked);

        if ($assigneeId !== null) {
            $assignee = User::query()->lockForUpdate()->find($assigneeId);

            if (! $assignee || ! $assignee->isApproved() || ! $assignee->canDo('privacy.processRequests')) {
                throw new ConflictHttpException('The selected assignee is not available to process privacy requests.');
            }
        }

        if ($locked->assigned_to_user_id === $assigneeId) {
            return $locked;
        }

        $previousAssigneeId = $locked->assigned_to_user_id;
        $updates = [
            'assigned_to_user_id' => $assigneeId,
            'updated_by' => $actor->id,
        ];

        if ($assigneeId !== null && $locked->assigned_at === null) {
            $updates['assigned_at'] = now();
        }

        $locked->update($updates);

        $this->audit(self::AUDIT_ASSIGNED, $locked, $actor, [
            'actor_id' => $actor->id,
            'from_assignee_id' => $previousAssigneeId,
            'to_assignee_id' => $assigneeId,
            'status' => $locked->status,
        ]);

        return $locked;
    }

    private function authorize(User $actor): void
    {
        $authenticated = auth()->user();

        if (
            ! $authenticated instanceof User
            || ! $authenticated->is($actor)
            || ! $actor->canDo('privacy.processRequests')
        ) {
            throw new AuthorizationException('This user cannot process privacy requests.');
        }
    }

    private function lock(DataSubjectRequest $request): DataSubjectRequest
    {
        return DataSubjectRequest::query()
            ->lockForUpdate()
            ->findOrFail($request->getKey());
    }

    private function transitionTarget(string $command, DataSubjectRequest $request): string
    {
        $target = self::TRANSITIONS[$command][$request->status] ?? null;
        if ($target === null) {
            throw new ConflictHttpException(sprintf(
                'A request in %s status cannot be %s.',
                str_replace('_', ' ', $request->status),
                match ($command) {
                    'verify' => 'verified',
                    'refuse' => 'refused',
                    'extend' => 'extended',
                    'assign' => 'assigned',
                    default => $command.'d',
                },
            ));
        }

        return $target;
    }

    private function assertVerified(DataSubjectRequest $request, string $action): void
    {
        if ($request->identity_verified !== 'verified' || $request->identity_verified_at === null) {
            throw new ConflictHttpException("Identity must be verified before this request can be {$action}.");
        }
    }

    private function requireProvenance(string $value, string $message): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new ConflictHttpException($message);
        }

        return $value;
    }

    private function futureDate(string $value): string
    {
        try {
            $date = Carbon::parse($value)->startOfDay();
        } catch (Throwable) {
            throw new ConflictHttpException('The extended due date is invalid.');
        }

        if ($date->lte(today())) {
            throw new ConflictHttpException('The extended due date must be after today.');
        }

        return $date->toDateString();
    }

    /** @param array<string, mixed> $meta */
    private function audit(
        string $action,
        DataSubjectRequest $request,
        User $actor,
        array $meta,
    ): void {
        $httpRequest = request()->duplicate();
        $httpRequest->setUserResolver(fn (): User => $actor);

        AuditLogger::logOrFail($action, $request, $meta, $httpRequest);
    }
}
