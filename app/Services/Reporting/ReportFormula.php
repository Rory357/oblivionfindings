<?php

namespace App\Services\Reporting;

use Illuminate\Validation\ValidationException;

/** Small arithmetic grammar over aggregate IDs, never PHP, SQL or JavaScript. */
final class ReportFormula
{
    public function calculate(string $formula, array $values): ?float
    {
        $tokens = [];
        preg_match_all('/m[1-8]|(?:[0-9]+(?:\.[0-9]+)?)|[()+*\/\-]/', preg_replace('/\s+/', '', $formula), $matches);
        $tokens = $matches[0];
        if ($tokens === [] || count($tokens) > 64 || implode('', $tokens) !== preg_replace('/\s+/', '', $formula)) {
            $this->invalid();
        }
        $i = 0;
        $expression = $term = $factor = null;
        $factor = function () use (&$i, $tokens, $values, &$expression, &$factor): ?float {
            $token = $tokens[$i++] ?? '';
            if ($token === '-') {
                $value = $factor();

                return $value === null ? null : -$value;
            }
            if ($token === '(') {
                $value = $expression();
                if (($tokens[$i++] ?? '') !== ')') {
                    $this->invalid();
                }

                return $value;
            }
            if (is_numeric($token)) {
                return (float) $token;
            }
            if (array_key_exists($token, $values)) {
                return $values[$token] === null ? null : (float) $values[$token];
            }
            $this->invalid();
        };
        $term = function () use (&$i, $tokens, &$factor): ?float {
            $left = $factor();
            while (in_array($tokens[$i] ?? '', ['*', '/'])) {
                $op = $tokens[$i++];
                $right = $factor();
                $left = $left === null || $right === null || ($op === '/' && $right == 0)
                    ? null : ($op === '*' ? $left * $right : $left / $right);
            }

            return $left;
        };
        $expression = function () use (&$i, $tokens, &$term): ?float {
            $left = $term();
            while (in_array($tokens[$i] ?? '', ['+', '-'])) {
                $op = $tokens[$i++];
                $right = $term();
                $left = $left === null || $right === null ? null : ($op === '+' ? $left + $right : $left - $right);
            }

            return $left;
        };
        $result = $expression();
        if ($i !== count($tokens)) {
            $this->invalid();
        }

        return $result !== null && is_finite($result) ? $result : null;
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['measures' => 'Use measure IDs, numbers, brackets and + − × ÷ only. Formula references must exist and cannot refer to themselves.']);
    }
}
