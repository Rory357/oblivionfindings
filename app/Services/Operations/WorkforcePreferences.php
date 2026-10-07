<?php

namespace App\Services\Operations;

use App\Models\User;
use App\Models\UserUiPreference;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class WorkforcePreferences
{
    public const KEY = 'workforce.roster-defaults';

    public function for(User $actor): array
    {
        return $this->payload($actor->uiPreferences()->where('key', self::KEY)->first());
    }

    public function payload(?UserUiPreference $preference): array
    {
        $value = $preference?->value ?? [];

        return [
            'default_tab' => in_array($value['default_tab'] ?? null, ['shifts', 'calendar'], true) ? $value['default_tab'] : 'shifts',
            'roster_view' => in_array($value['roster_view'] ?? null, ['grid', 'list'], true) ? $value['roster_view'] : 'grid',
            'revision' => hash('sha256', json_encode([$value, $preference?->getRawOriginal('updated_at')], JSON_THROW_ON_ERROR)),
        ];
    }

    /** Returns the persisted command snapshot; the HTTP caller alone certifies root commit. */
    public function save(User $actor, array $values, string $expectedRevision, Request $request): array
    {
        $values = ['default_tab' => $values['default_tab'], 'roster_view' => $values['roster_view']];

        return DB::transaction(function () use ($actor, $values, $expectedRevision, $request): array {
            $guard = app(WorkforceMutationGuard::class);
            $guard->lock();
            $actor = $guard->actor($actor, 'rostering.viewAny', additionalPermissions: ['rostering.viewAny']);
            $request->setUserResolver(fn () => $actor);
            $preference = $actor->uiPreferences()->where('key', self::KEY)->lockForUpdate()->first();
            $before = $this->payload($preference);
            if (! hash_equals($before['revision'], $expectedRevision)) {
                throw ValidationException::withMessages([
                    'expected_revision' => 'Your workforce preferences changed in another session. Reload settings before saving again.',
                ]);
            }
            // Preserve the existing first-save and timestamp behavior even when
            // the submitted preference values already match the defaults.
            $preference ??= $actor->uiPreferences()->make(['key' => self::KEY]);
            $preference->fill(['value' => Arr::only($values, ['default_tab', 'roster_view'])]);
            if (! $preference->save()) {
                throw ValidationException::withMessages(['preferences' => 'Your workforce preferences could not be saved. Your draft is retained.']);
            }
            // A model's filled attributes are not evidence of SQL persistence.
            // Read our owned row while its command locks are still held.
            $stored = $actor->uiPreferences()->where('key', self::KEY)->lockForUpdate()->firstOrFail();
            $after = $this->payload($stored);
            $savedValues = Arr::only($after, ['default_tab', 'roster_view']);
            if ($savedValues !== Arr::only($values, ['default_tab', 'roster_view'])) {
                throw ValidationException::withMessages(['preferences' => 'Your workforce preferences could not be saved. Your draft is retained.']);
            }
            $changed = Arr::only($before, ['default_tab', 'roster_view']) !== $savedValues;
            if ($changed) {
                AuditLogger::logOrFail('workforce.preferences.updated', $stored, [
                    'before' => Arr::only($before, ['default_tab', 'roster_view']),
                    'after' => $savedValues,
                ], $request);
            }

            return [...$after, 'actor_id' => (int) $actor->id, 'expected_revision' => $expectedRevision,
                'prior_revision' => $before['revision'], 'values' => $savedValues, 'changed' => $changed];
        });
    }
}
