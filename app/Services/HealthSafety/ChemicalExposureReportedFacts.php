<?php

namespace App\Services\HealthSafety;

use LogicException;

/** Closed reported facts only. Callers separately prove current source and original retained acceptance. */
final class ChemicalExposureReportedFacts
{
    public const FIELDS = ['medical_attention_sought', 'incident_reported', 'medical_treatment'];
    public const EVIDENCE_KEYS = ['intent_id', 'revision', 'actor_id', 'exposure_id', 'worker_id', 'site_id', 'substance_id'];
    public const CATEGORIES = ['none', 'first_aid', 'medical', 'hospitalisation', 'death'];

    public static function legacy(array $snapshot): array
    {
        self::snapshotKeys($snapshot);
        $contract = ['schema_version' => 1];
        foreach (self::FIELDS as $field) {
            self::value($field, $snapshot[$field] ?? null);
            $contract[$field] = ['value' => $snapshot[$field] ?? null, 'origin' => 'legacy_unconfirmed', 'reported_in' => null];
        }
        return $contract;
    }

    public static function capture(array $snapshot, array $provided, array $identity): array
    {
        self::identity($identity);
        self::provided($provided);
        self::snapshotKeys($snapshot);
        $contract = ['schema_version' => 1];
        foreach (self::FIELDS as $field) {
            $value = $snapshot[$field] ?? null;
            if (array_key_exists($field, $provided)) {
                if ($provided[$field] !== $value) { throw new LogicException('Reported capture differs from persisted facts.'); }
                $contract[$field] = self::reported($value, $identity);
            } else {
                if ($value !== null) { throw new LogicException('Omitted capture answer cannot become a recorded value.'); }
                $contract[$field] = ['value' => null, 'origin' => 'unanswered', 'reported_in' => null];
            }
        }
        self::assertContract($contract, $snapshot);
        return $contract;
    }

    public static function transition(array $prior, array $before, array $after, array $provided, array $identity): array
    {
        self::identity($identity);
        self::provided($provided);
        self::assertContract($prior, $before);
        $contract = $prior;
        foreach (self::FIELDS as $field) {
            $value = $after[$field] ?? null;
            if (! array_key_exists($field, $provided)) {
                if ($value !== ($before[$field] ?? null)) { throw new LogicException('An omitted reported answer changed.'); }
                continue;
            }
            if ($provided[$field] !== $value) { throw new LogicException('Submitted reported answer differs from persisted facts.'); }
            $entry = $prior[$field];
            // An already explicit identical answer retains its ORIGINAL reporter; reviewing it is not new attribution.
            if (in_array($entry['origin'], ['reported', 'reported_unknown'], true)
                && $entry['value'] === $value && self::sameTuple($entry['reported_in'], $identity)) {
                continue;
            }
            $contract[$field] = self::reported($value, $identity);
        }
        self::assertContract($contract, $after);
        return ['contract' => $contract, 'provenance_changed' => ChemicalCommandValues::digest($contract) !== ChemicalCommandValues::digest($prior)];
    }

    public static function assertContract(array $contract, array $snapshot): void
    {
        self::snapshotKeys($snapshot);
        self::keys($contract, ['schema_version', ...self::FIELDS]);
        if ($contract['schema_version'] !== 1) { throw new LogicException('Unsupported reported-fact schema.'); }
        foreach (self::FIELDS as $field) {
            $entry = $contract[$field];
            if (! is_array($entry)) { throw new LogicException('Reported-fact entry must be a closed object.'); }
            self::keys($entry, ['value', 'origin', 'reported_in']);
            self::value($field, $entry['value']);
            if ($entry['value'] !== ($snapshot[$field] ?? null)) { throw new LogicException('Reported-fact evidence differs from source facts.'); }
            if (! in_array($entry['origin'], ['reported', 'reported_unknown', 'unanswered', 'legacy_unconfirmed'], true)) {
                throw new LogicException('Unsupported reported-fact origin.');
            }
            if (in_array($entry['origin'], ['reported', 'reported_unknown'], true)) {
                if (! is_array($entry['reported_in'])) { throw new LogicException('Explicit reported fact needs original acceptance identity.'); }
                self::identity($entry['reported_in']);
                if (($entry['origin'] === 'reported') !== ($entry['value'] !== null)) {
                    throw new LogicException('Unknown is not a reported negative or a known category.');
                }
            } elseif ($entry['reported_in'] !== null || ($entry['origin'] === 'unanswered' && $entry['value'] !== null)) {
                throw new LogicException('Unconfirmed fact cannot claim original reporting evidence.');
            }
        }
    }

    public static function project(array $contract, array $currentTuple): array
    {
        self::tuple($currentTuple);
        self::keys($contract, ['schema_version', ...self::FIELDS]);
        $stored = [];
        foreach (self::FIELDS as $field) {
            if (! is_array($contract[$field])) { throw new LogicException('Reported projection entry must be a closed object.'); }
            $stored[$field] = $contract[$field]['value'] ?? null;
        }
        self::assertContract($contract, $stored);
        $result = ['fact_states' => []];
        foreach (self::FIELDS as $field) {
            $entry = $contract[$field];
            $state = $entry['origin'];
            if ($entry['reported_in'] !== null && ! self::sameTuple($entry['reported_in'], $currentTuple)) { $state = 'prior_context'; }
            $result[$field] = $field === 'medical_treatment' ? $entry['value'] : ($state === 'reported' ? $entry['value'] : null);
            $result['fact_states'][$field] = ['state' => $state, 'recorded_value' => $entry['value']];
        }
        return $result;
    }

    /** Selected operational policy only; no harm assessment, investigation, WorkSafe or closure decision. */
    public static function response(array $contract, array $currentTuple): array
    {
        $projection = self::project($contract, $currentTuple);
        $category = $projection['fact_states']['medical_treatment']['state'] === 'reported' ? $projection['medical_treatment'] : null;
        $attention = $projection['medical_attention_sought'];
        $floor = match ($category) {
            'death' => 'critical', 'hospitalisation', 'medical' => 'high', 'first_aid' => 'medium', 'none' => 'low',
            default => $attention === true ? 'high' : 'medium',
        };
        return ['reported_attention' => $attention === null ? 'unknown' : ($attention ? 'yes' : 'no'),
            'recorded_category' => $projection['medical_treatment'],
            'category_state' => $projection['fact_states']['medical_treatment']['state'], 'response_floor' => $floor,
            'basis' => $category !== null ? 'reported_category' : ($attention === true ? 'reported_attention' : 'operational_triage'),
            'care_review_needed' => $attention === null || $category === null
                || ($attention === true && in_array($category, ['none', 'first_aid'], true))
                || ($attention === false && in_array($category, ['medical', 'hospitalisation', 'death'], true)),
            'harm_assessment' => 'unassessed'];
    }

    public static function sameTuple(array $left, array $right): bool
    {
        foreach (['exposure_id', 'worker_id', 'site_id', 'substance_id'] as $key) {
            if (! isset($left[$key], $right[$key]) || $left[$key] !== $right[$key]) { return false; }
        }
        return true;
    }

    private static function reported(mixed $value, array $identity): array
    {
        return ['value' => $value, 'origin' => $value === null ? 'reported_unknown' : 'reported', 'reported_in' => $identity];
    }

    private static function provided(array $provided): void
    {
        if (array_diff(array_keys($provided), self::FIELDS) !== []) { throw new LogicException('Only three fixed reported fields are allowed.'); }
        foreach ($provided as $field => $value) { self::value($field, $value); }
    }

    private static function value(string $field, mixed $value): void
    {
        if ($field === 'medical_treatment' ? ($value !== null && ! in_array($value, self::CATEGORIES, true)) : ($value !== null && ! is_bool($value))) {
            throw new LogicException('Reported fact has an invalid scalar type or category.');
        }
    }

    private static function identity(array $identity): void
    {
        self::keys($identity, self::EVIDENCE_KEYS);
        foreach ($identity as $value) {
            if (! is_int($value) || $value < 1) { throw new LogicException('Reported evidence requires exact positive identities.'); }
        }
        if ($identity['revision'] > 4294967295) { throw new LogicException('Reported evidence revision exceeds the source range.'); }
    }

    private static function tuple(array $tuple): void
    {
        self::keys($tuple, ['exposure_id', 'worker_id', 'site_id', 'substance_id']);
        foreach ($tuple as $value) { if (! is_int($value) || $value < 1) { throw new LogicException('Reported projection needs an exact current source tuple.'); } }
    }

    private static function snapshotKeys(array $snapshot): void
    {
        if (array_diff(self::FIELDS, array_keys($snapshot)) !== []) { throw new LogicException('Reported facts require a complete admitted source snapshot.'); }
    }

    private static function keys(array $value, array $keys): void
    {
        if (array_diff(array_keys($value), $keys) !== [] || array_diff($keys, array_keys($value)) !== []) {
            throw new LogicException('Reported-fact proof has unknown or missing keys.');
        }
    }
}
