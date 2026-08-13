<?php

namespace App\Services\Eligibility;

use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Immutable decision used at assignment write boundaries.
 */
final readonly class AssignmentEligibilityDecision
{
    public const UNAVAILABLE_MESSAGE = 'Eligibility checks are temporarily unavailable. No assignment was made. Please try again shortly.';

    private function __construct(
        public AssignmentEligibilityStatus $status,
        public int $shiftId,
        public int $userId,
        public string $eligibilityFingerprint,
        public CarbonImmutable $decidedAt,
        public ?EligibilityResult $result = null,
    ) {}

    public static function fromResult(Shift $shift, User $user, EligibilityResult $result): self
    {
        $status = match (true) {
            $result->hasBlocks() => AssignmentEligibilityStatus::HardBlock,
            $result->hasWarnings() => AssignmentEligibilityStatus::Warning,
            default => AssignmentEligibilityStatus::Pass,
        };

        return new self(
            status: $status,
            shiftId: (int) $shift->getKey(),
            userId: (int) $user->getKey(),
            eligibilityFingerprint: self::fingerprint($shift, $user),
            decidedAt: CarbonImmutable::now(),
            result: $result,
        );
    }

    public static function unavailable(Shift $shift, User $user): self
    {
        return new self(
            status: AssignmentEligibilityStatus::Unavailable,
            shiftId: (int) $shift->getKey(),
            userId: (int) $user->getKey(),
            eligibilityFingerprint: self::fingerprint($shift, $user),
            decidedAt: CarbonImmutable::now(),
        );
    }

    public function isWarning(): bool
    {
        return $this->status === AssignmentEligibilityStatus::Warning;
    }

    public function matches(Shift $shift, User $user): bool
    {
        return $this->shiftId === (int) $shift->getKey()
            && $this->userId === (int) $user->getKey()
            && hash_equals($this->eligibilityFingerprint, self::fingerprint($shift, $user));
    }

    public function assertMayAssign(string $field, string $hardBlockFallback): void
    {
        if ($this->status === AssignmentEligibilityStatus::Unavailable) {
            throw ValidationException::withMessages([
                $field => self::UNAVAILABLE_MESSAGE,
            ])->status(503);
        }

        if ($this->status === AssignmentEligibilityStatus::HardBlock) {
            throw ValidationException::withMessages([
                $field => $this->result?->blocking_reasons[0] ?? $hardBlockFallback,
            ])->status(422);
        }
    }

    private static function fingerprint(Shift $shift, User $user): string
    {
        return hash('sha256', json_encode([
            'shift_id' => (int) $shift->getKey(),
            'starts_at' => $shift->starts_at?->toJSON(),
            'ends_at' => $shift->ends_at?->toJSON(),
            'site_id' => $shift->site_id,
            'client_id' => $shift->client_id,
            'service_context_id' => $shift->service_context_id,
            'coverage_roles' => array_values($shift->coverage_roles ?? []),
            'required_licence_class' => $shift->required_licence_class,
            'required_licence_endorsements' => array_values($shift->required_licence_endorsements ?? []),
            'user_id' => (int) $user->getKey(),
            'user_updated_at' => $user->updated_at?->toJSON(),
            'user_organization_id' => $user->organization_id,
            'user_approved_at' => $user->approved_at?->toJSON(),
        ], JSON_THROW_ON_ERROR));
    }
}
