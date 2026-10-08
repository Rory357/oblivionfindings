<?php

namespace App\Domain\Hr\Services;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WorkforceEligibilityRecheck;
use App\Services\AuditLogger;
use App\Services\Eligibility\WorkforceEligibilityRefresh;
use App\Services\Operations\WorkforceMutationGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** The separately versioned operational qualification policy; HR hard stops remain independent. */
final class HrEligibilityRuleSettings
{
    public const KEY = 'hr.eligibility_rules';

    public const AUDIT_ACTION = 'hr.settings.eligibility_rules.updated';

    public const KEYS = ['unmapped_mandatory_qualification', 'house_qualification_approach'];

    public const DEFAULTS = ['unmapped_mandatory_qualification' => 'warn', 'house_qualification_approach' => 'per_requirement'];

    public function values(): array
    {
        return $this->snapshot()['values'];
    }

    public function defaults(): array
    {
        return $this->normalize((array) config('hr.eligibility_rules', self::DEFAULTS));
    }

    public function snapshot(bool $current = false): array
    {
        // A locking read is current even inside a pre-existing REPEATABLE READ
        // snapshot. Assignment and policy commands share the application mutex.
        $query = AppSetting::query()->useWritePdo()->where('key', self::KEY);
        $row = ($current ? $query->lock('for share nowait') : $query->sharedLock())->first();

        return $this->payload($row);
    }

    public function payload(?AppSetting $row): array
    {
        $defaults = $this->defaults();
        $stored = $row?->value;
        if ($row && (! is_array($stored) || ! is_int($stored['version'] ?? null)
            || $stored['version'] < 0 || $stored['version'] >= PHP_INT_MAX || ! is_array($stored['values'] ?? null))) {
            throw new \RuntimeException('The saved eligibility rules could not be loaded. Contact the HR settings owner.');
        }
        try {
            $values = $row ? $this->normalize($stored['values']) : $defaults;
        } catch (ValidationException $exception) {
            throw new \RuntimeException('The saved eligibility rules could not be loaded. Contact the HR settings owner.', previous: $exception);
        }
        $version = $row ? $stored['version'] : 0;

        return ['values' => $values, 'defaults' => $defaults, 'version' => $version,
            'revision' => hash('sha256', json_encode(['record' => $version > 0 ? $row?->id : null,
                'version' => $version, 'values' => $values], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)),
            'source' => $row && $version > 0 ? 'saved_override' : 'deployment_defaults'];
    }

    public function validationRules(): array
    {
        return ['values' => ['required', 'array:'.implode(',', self::KEYS)],
            'values.unmapped_mandatory_qualification' => ['required', 'in:warn,block'],
            'values.house_qualification_approach' => ['required', 'in:per_requirement,all_workers,minimum_staff']];
    }

    public function save(User $actor, array $input, string $expectedRevision, string $reason, Request $request): array
    {
        $values = $this->normalize($input);
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Explain why the eligibility rules are changing.']);
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
                    'expected_revision' => 'The eligibility rules changed in another session. Reload the saved rules before saving again.',
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
                    throw ValidationException::withMessages(['expected_revision' => 'The eligibility rules changed. Reload the saved rules before saving again.']);
                }
            }
            $rowId = (int) $row->id;
            if (! $row->update(['value' => ['version' => $before['version'] + 1, 'values' => $values]])) {
                throw ValidationException::withMessages(['eligibility_rules' => 'The eligibility rules could not be saved. Your draft is retained.']);
            }
            if ((int) $row->id !== $rowId) {
                throw ValidationException::withMessages(['eligibility_rules' => 'The eligibility rules could not be saved. Your draft is retained.']);
            }
            $row = AppSetting::query()->whereKey($rowId)->where('key', self::KEY)->lockForUpdate()->firstOrFail();
            $after = $this->payload($row);
            if ($after['values'] !== $values || $after['version'] !== $before['version'] + 1) {
                throw ValidationException::withMessages(['eligibility_rules' => 'The eligibility rules could not be saved. Your draft is retained.']);
            }
            $priorAuditId = AuditLog::query()->where('action', self::AUDIT_ACTION)
                ->where('auditable_type', $row->getMorphClass())->where('auditable_id', $rowId)
                ->orderByDesc('id')->lockForUpdate()->first()?->id ?? 0;
            AuditLogger::logOrFail(self::AUDIT_ACTION, $row, ['before' => $before['values'], 'after' => $values,
                'source_key' => self::KEY, 'reason' => $reason,
                'revision_before' => $before['revision'], 'revision_after' => $after['revision']], $request);
            $audit = AuditLog::query()->where('id', '>', $priorAuditId)->where('action', self::AUDIT_ACTION)->where('auditable_type', $row->getMorphClass())
                ->where('auditable_id', $row->id)->where('user_id', $actor->id)->orderByDesc('id')->lockForUpdate()->first();
            if (! $audit || ($audit->meta['revision_before'] ?? null) !== $before['revision']
                || ($audit->meta['revision_after'] ?? null) !== $after['revision']
                || ($audit->meta['source_key'] ?? null) !== self::KEY || ($audit->meta['reason'] ?? null) !== $reason
                || $this->normalize($audit->meta['before'] ?? []) !== $before['values'] || $this->normalize($audit->meta['after'] ?? []) !== $values) {
                throw ValidationException::withMessages(['eligibility_rules' => 'The eligibility rule audit could not be recorded. Your draft is retained.']);
            }
            $intent = app(WorkforceEligibilityRefresh::class)->stage('hr_eligibility_rules', (int) $row->id, $after['revision'], ['all_assigned' => true]);
            // Certify persisted intent, not a filled model or eventual delivery.
            $staged = WorkforceEligibilityRecheck::query()->whereKey($intent->id)->lockForUpdate()->firstOrFail();
            if ($staged->source_type !== 'hr_eligibility_rules' || (int) $staged->source_id !== (int) $row->id
                || $staged->source_fingerprint !== $after['revision'] || ! $staged->all_assigned
                || (int) $staged->source_version !== (int) $intent->source_version || (int) $staged->source_version < 1) {
                throw ValidationException::withMessages(['eligibility_rules' => 'The eligibility rule recheck could not be recorded. Your draft is retained.']);
            }

            return [...$after, 'changed' => true, 'actor_id' => (int) $actor->id,
                'expected_revision' => $expectedRevision, 'prior_revision' => $before['revision'],
                'refresh' => ['status' => 'staged', 'recheck_id' => (int) $staged->id, 'source_version' => (int) $staged->source_version]];
        });
    }

    private function normalize(array $values): array
    {
        Validator::make(['values' => $values], $this->validationRules())->validate();

        return collect(self::KEYS)->mapWithKeys(fn (string $key) => [$key => $values[$key]])->all();
    }
}
