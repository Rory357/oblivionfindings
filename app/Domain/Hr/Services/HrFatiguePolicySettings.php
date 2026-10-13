<?php

namespace App\Domain\Hr\Services;

use App\Models\AppSetting;
use App\Models\User;
use App\Models\WorkforceEligibilityRecheck;
use App\Services\AuditLogger;
use App\Services\Eligibility\WorkforceEligibilityRefresh;
use App\Services\Operations\WorkforceMutationGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** The single HR-owned fatigue authority; deployment configuration is its fallback. */
final class HrFatiguePolicySettings
{
    public const KEY = 'hr.fatigue';

    public const AUDIT_ACTION = 'hr.settings.fatigue.updated';

    public const KEYS = ['max_hours_per_day', 'max_hours_per_week', 'warning_threshold_weekly',
        'min_rest_between_shifts_hours', 'max_consecutive_days'];

    public const MAX_REST_HOURS = 8784;

    public const MAX_CONSECUTIVE_DAYS = 366;

    public function values(): array
    {
        return $this->snapshot()['values'];
    }

    public function defaults(): array
    {
        return $this->normalize((array) config('hr.fatigue', []));
    }

    public function snapshot(): array
    {
        // A locking read is current even inside a pre-existing REPEATABLE READ
        // snapshot. Assignment and policy commands share the application mutex.
        $row = AppSetting::query()->useWritePdo()->where('key', self::KEY)->sharedLock()->first();

        return $this->payload($row);
    }

    public function payload(?AppSetting $row): array
    {
        $defaults = $this->defaults();
        $stored = $row?->value;
        if ($row && (! is_array($stored) || ! is_int($stored['version'] ?? null)
            || $stored['version'] < 0 || $stored['version'] >= PHP_INT_MAX || ! is_array($stored['values'] ?? null))) {
            throw new \RuntimeException('The saved staffing rules could not be loaded. Contact the HR settings owner.');
        }
        try {
            $values = $row ? $this->normalize($stored['values']) : $defaults;
        } catch (ValidationException $exception) {
            throw new \RuntimeException('The saved staffing rules could not be loaded. Contact the HR settings owner.', previous: $exception);
        }
        $version = $row ? $stored['version'] : 0;

        return ['values' => $values, 'defaults' => $defaults, 'version' => $version,
            'revision' => hash('sha256', json_encode(['record' => $version > 0 ? $row?->id : null,
                'version' => $version, 'values' => $values], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)),
            'source' => $row && $version > 0 ? 'saved_override' : 'deployment_defaults',
            'limits' => ['max_rest_hours' => self::MAX_REST_HOURS, 'max_consecutive_days' => self::MAX_CONSECUTIVE_DAYS,
                'maximum_hours_exclusive_minimum' => 0, 'warning_and_rest_minimum' => 0]];
    }

    /** Input limits bound processing; they are not clinical or legal standards. */
    public function validationRules(): array
    {
        $finite = static function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_numeric($value) || ! is_finite((float) $value)) {
                $fail('The value must be a finite number.');
            }
        };

        return [
            'values' => ['required', 'array:'.implode(',', self::KEYS)],
            'values.max_hours_per_day' => ['required', 'numeric', 'gt:0', $finite],
            'values.max_hours_per_week' => ['required', 'numeric', 'gt:0', $finite],
            'values.warning_threshold_weekly' => ['required', 'numeric', 'min:0', 'lte:values.max_hours_per_week', $finite],
            'values.min_rest_between_shifts_hours' => ['required', 'numeric', 'min:0', 'max:'.self::MAX_REST_HOURS, $finite],
            'values.max_consecutive_days' => ['required', 'integer', 'min:1', 'max:'.self::MAX_CONSECUTIVE_DAYS],
        ];
    }

    public function save(User $actor, array $input, string $expectedRevision, string $reason, Request $request): array
    {
        $values = $this->normalize($input);
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Explain why the staffing rules are changing.']);
        }

        return DB::transaction(function () use ($actor, $values, $expectedRevision, $reason, $request): array {
            $guard = app(WorkforceMutationGuard::class);
            $guard->lock();
            $actor = $guard->actor($actor, 'hr.settings.manage', additionalPermissions: ['hr.settings.manage', 'rostering.viewAny']);
            abort_unless($actor->canDo('rostering.viewAny'), 403);
            $request->setUserResolver(fn () => $actor);
            $row = AppSetting::query()->where('key', self::KEY)->lockForUpdate()->first();
            $before = $this->payload($row);
            if (! hash_equals($before['revision'], $expectedRevision)) {
                throw ValidationException::withMessages([
                    'expected_revision' => 'The staffing rules changed in another session. Reload the saved rules before saving again.',
                ]);
            }
            if ($values === $before['values']) {
                return [...$before, 'changed' => false, 'actor_id' => (int) $actor->id,
                    'expected_revision' => $expectedRevision, 'prior_revision' => $before['revision'],
                    'refresh' => ['status' => 'not_requested', 'recheck_id' => null, 'source_version' => null]];
            }
            if (! $row) {
                AppSetting::query()->insertOrIgnore(['key' => self::KEY,
                    'value' => json_encode(['version' => 0, 'values' => $before['values']], JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now()]);
                $row = AppSetting::query()->where('key', self::KEY)->lockForUpdate()->firstOrFail();
                $before = $this->payload($row);
                if (! hash_equals($before['revision'], $expectedRevision)) {
                    throw ValidationException::withMessages(['expected_revision' => 'The staffing rules changed. Reload the saved rules before saving again.']);
                }
            }
            if (! $row->update(['value' => ['version' => $before['version'] + 1, 'values' => $values]])) {
                throw ValidationException::withMessages(['staffing_rules' => 'The staffing rules could not be saved. Your draft is retained.']);
            }
            $row = AppSetting::query()->whereKey($row->id)->where('key', self::KEY)->lockForUpdate()->firstOrFail();
            $after = $this->payload($row);
            if ($after['values'] !== $values || $after['version'] !== $before['version'] + 1) {
                throw ValidationException::withMessages(['staffing_rules' => 'The staffing rules could not be saved. Your draft is retained.']);
            }
            AuditLogger::logOrFail(self::AUDIT_ACTION, $row, ['before' => $before['values'], 'after' => $values,
                'source_key' => self::KEY, 'reason' => $reason,
                'revision_before' => $before['revision'], 'revision_after' => $after['revision']], $request);
            $intent = app(WorkforceEligibilityRefresh::class)->stage('hr_fatigue_policy', (int) $row->id, $after['revision'], ['all_assigned' => true]);
            // Certify persisted intent, not a filled model or eventual delivery.
            $staged = WorkforceEligibilityRecheck::query()->whereKey($intent->id)->lockForUpdate()->firstOrFail();
            if ($staged->source_type !== 'hr_fatigue_policy' || (int) $staged->source_id !== (int) $row->id
                || $staged->source_fingerprint !== $after['revision'] || ! $staged->all_assigned
                || (int) $staged->source_version !== (int) $intent->source_version || (int) $staged->source_version < 1) {
                throw ValidationException::withMessages(['staffing_rules' => 'The staffing rule recheck could not be recorded. Your draft is retained.']);
            }

            return [...$after, 'changed' => true, 'actor_id' => (int) $actor->id,
                'expected_revision' => $expectedRevision, 'prior_revision' => $before['revision'],
                'refresh' => ['status' => 'staged', 'recheck_id' => (int) $staged->id, 'source_version' => (int) $staged->source_version]];
        });
    }

    private function normalize(array $values): array
    {
        Validator::make(['values' => $values], $this->validationRules())->validate();

        return collect(self::KEYS)->mapWithKeys(fn (string $key) => [$key => $key === 'max_consecutive_days'
            ? (int) $values[$key] : (float) $values[$key]])->all();
    }
}
