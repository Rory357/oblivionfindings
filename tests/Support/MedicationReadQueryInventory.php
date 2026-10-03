<?php

namespace Tests\Support;

/** Keep parent-scope subqueries distinct from administration row reads. */
final class MedicationReadQueryInventory
{
    public static function fromLog(array $log): array
    {
        $reads = array_fill_keys([
            'scheduled_window', 'board_day', 'prn_unresolved', 'administration_batch',
            'followup_scope', 'refusal_scope', 'unexpected',
        ], []);
        foreach ($log as $entry) {
            $sql = $entry['query'];
            if (! preg_match('/^\s*select\b/i', $sql) || ! str_contains($sql, 'client_medication_administrations')) {
                continue;
            }
            $purpose = match (self::topLevelTable($sql)) {
                'medication_followups' => 'followup_scope',
                'medication_refusal_followups' => 'refusal_scope',
                'client_medication_administrations' => self::administrationPurpose($sql),
                default => 'unexpected',
            };
            $reads[$purpose][] = $entry;
        }

        return $reads;
    }

    public static function counts(array $reads): array
    {
        return array_map('count', $reads);
    }

    public static function describe(array $reads): string
    {
        $lines = [];
        foreach ($reads as $purpose => $entries) {
            foreach ($entries as $entry) {
                $lines[] = $purpose.': '.$entry['query'];
            }
        }

        return implode(PHP_EOL, $lines);
    }

    private static function administrationPurpose(string $sql): string
    {
        if (str_contains($sql, 'medication_prn_effectiveness') && preg_match('/\bnot exists\b/i', $sql)) {
            return 'prn_unresolved';
        }
        if (preg_match('/(?:^|[.\s(])[\x60"]?scheduled_for[\x60"]?\s+between\b/i', $sql)) {
            return preg_match('/(?:^|[.\s(])[\x60"]?scheduled_for[\x60"]?\s+is null\b/i', $sql) ? 'board_day' : 'scheduled_window';
        }
        if (preg_match('/(?:^|[.\s(])[\x60"]?id[\x60"]?\s+in\s*\(/i', $sql)) {
            return 'administration_batch';
        }

        return 'unexpected';
    }

    private static function topLevelTable(string $sql): ?string
    {
        preg_match_all("/'(?:''|[^'])*'|\"(?:\"\"|[^\"])*\"|\x60(?:\x60\x60|[^\x60])*\x60|[()]|[a-z_][a-z0-9_]*/i", $sql, $tokens);
        $depth = 0;
        $afterFrom = false;
        foreach ($tokens[0] as $token) {
            if ($token === '(') {
                $depth++;
                continue;
            }
            if ($token === ')') {
                $depth--;
                continue;
            }
            if ($depth !== 0) {
                continue;
            }
            if ($afterFrom) {
                return strtolower(trim($token, "\x60\""));
            }
            $afterFrom = strtolower($token) === 'from';
        }

        return null;
    }
}
