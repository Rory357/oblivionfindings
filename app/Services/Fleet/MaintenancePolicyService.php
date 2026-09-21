<?php

namespace App\Services\Fleet;

use Illuminate\Support\Facades\DB;

class MaintenancePolicyService
{
    /**
     * Return an approved, assigned, exact version. A missing or inconsistent
     * assignment is deliberately not a usable operating rule.
     *
     * @return array{id:int, version:int, rules:array, sha256:string}|null
     */
    public function current(int $siteId, string $assetCategory, string $ruleKind, bool $lock = false): ?array
    {
        $query = DB::table('fleet_maintenance_policy_assignments as assignment')
            ->join('fleet_maintenance_policy_versions as policy', 'policy.id', '=', 'assignment.policy_version_id')
            ->where('assignment.site_id', $siteId)
            ->where('assignment.asset_category', $assetCategory)
            ->where('assignment.rule_kind', $ruleKind)
            ->select('policy.id', 'policy.site_id', 'policy.asset_category', 'policy.rule_kind',
                'policy.version', 'policy.rules_json', 'policy.content_sha256', 'policy.approved_at');

        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $row || ! $row->approved_at || (int) $row->site_id !== $siteId
            || $row->asset_category !== $assetCategory || $row->rule_kind !== $ruleKind) {
            return null;
        }

        $rules = json_decode((string) $row->rules_json, true);
        if (! is_array($rules) || ! hash_equals((string) $row->content_sha256, MaintenanceFingerprint::of($rules))) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'version' => (int) $row->version,
            'rules' => $rules,
            'sha256' => (string) $row->content_sha256,
        ];
    }

    /**
     * A positive outcome requires an explicit approved rule for every answer.
     * Unknown, missing, conditional or unverified evidence stays assessment.
     *
     * @param array{id:int, version:int, rules:array, sha256:string}|null $policy
     * @param array<string, array<string, mixed>> $responses
     */
    public function checkOutcome(?array $policy, array $responses): string
    {
        if (! $policy || ! isset($policy['rules']['questions']) || ! is_array($policy['rules']['questions'])) {
            return 'needs_assessment';
        }

        $questions = $policy['rules']['questions'];
        if ($questions === [] || ! array_is_list($questions)) {
            return 'needs_assessment';
        }

        $known = [];
        $uncertain = false;
        $failed = false;
        foreach ($questions as $question) {
            if (! is_array($question) || ! isset($question['id']) || ! is_string($question['id'])
                || $question['id'] === '' || isset($known[$question['id']])) {
                return 'needs_assessment';
            }

            $id = $question['id'];
            $known[$id] = true;
            $answer = $responses[$id] ?? null;
            $value = is_array($answer) ? ($answer['result'] ?? null) : null;
            if (array_key_exists('when', $question)) {
                $when = $question['when'];
                if (! is_array($when) || ! is_string($when['question_id'] ?? null)
                    || ! isset($known[$when['question_id']]) || $when['question_id'] === $id
                    || ! is_string($when['equals'] ?? null)) {
                    $uncertain = true;
                    continue;
                }
                $conditionAnswer = $responses[$when['question_id']] ?? null;
                $condition = is_array($conditionAnswer) ? ($conditionAnswer['result'] ?? null) : null;
                if (! is_string($condition)) {
                    $uncertain = true;
                    continue;
                }
                if ($condition !== $when['equals']) {
                    // Explicitly non-applicable questions need no answer or
                    // evidence. A contradictory submitted answer remains
                    // assessment rather than silently ignored evidence.
                    if ($answer !== null && $value !== 'na') {
                        $uncertain = true;
                    }
                    continue;
                }
            }
            if ($value === 'fail') {
                $failed = true;
                continue;
            }

            if (! is_string($value) || ! isset($question['pass_values'])
                || ! is_array($question['pass_values']) || ! array_is_list($question['pass_values'])) {
                $uncertain = true;
                continue;
            }

            if ($value === 'na') {
                if (($question['allow_na'] ?? false) !== true) {
                    $uncertain = true;
                }
                if (($question['evidence_required'] ?? false) === true
                    && ($question['na_evidence_exempt'] ?? false) !== true
                    && ($answer['evidence_verified'] ?? false) !== true) {
                    $uncertain = true;
                }
                continue;
            }

            if (! in_array($value, $question['pass_values'], true)
                || (($question['evidence_required'] ?? false) === true
                    && ($answer['evidence_verified'] ?? false) !== true)) {
                $uncertain = true;
            }
        }

        if (array_diff_key($responses, $known) !== []) {
            $uncertain = true;
        }

        return $failed ? 'failed' : ($uncertain ? 'needs_assessment' : 'passed');
    }
}
