<?php

namespace App\Domain\SecurityDevices\NetworkConfig\Services;

/**
 * S&D Phase G (pop-up 24): which lines of a device's settings changed between two backups, laid out
 * side by side. A small Myers diff (the shortest set of added and removed lines), bounded so a huge
 * or wholly rewritten export can't tie up a request: at most 20,000 lines a side and 2,000 added or
 * removed lines. Past either bound it says "too many to show" instead of guessing.
 *
 * Production can't use sebastian/diff (a dev dependency), so this is ours. It compares lines as
 * they are given: callers pass the already-redacted lines, so a secret never reaches it.
 */
final class SettingsLineDiff
{
    public const int MAX_LINES = 20_000;

    /** Added plus removed lines; one changed line counts two. */
    public const int MAX_EDITS = 2_000;

    /** Unchanged lines kept around each change, so the change can be read in place. */
    public const int CONTEXT = 2;

    /** Rows sent to the pop-up at most; the counts still cover every change. */
    public const int MAX_ROWS = 600;

    /**
     * @param  list<string>  $before
     * @param  list<string>  $after
     * @return array{
     *     lines: int|null,
     *     added: int|null,
     *     removed: int|null,
     *     too_many: bool,
     *     rows: list<array{kind: string, left: array{n: int, text: string}|null, right: array{n: int, text: string}|null, hidden: int}>,
     *     truncated: bool
     * }
     */
    public function compare(array $before, array $after, bool $withRows = true): array
    {
        $before = array_values($before);
        $after = array_values($after);
        if (count($before) > self::MAX_LINES || count($after) > self::MAX_LINES) {
            return self::tooMany();
        }

        $script = $this->script($before, $after);
        if ($script === null) {
            return self::tooMany();
        }

        // Pair each run of removed lines with the run of added lines that replaced it.
        $paired = [];
        $removedRun = [];
        $addedRun = [];
        $flush = function () use (&$paired, &$removedRun, &$addedRun): void {
            $length = max(count($removedRun), count($addedRun));
            for ($i = 0; $i < $length; $i++) {
                $left = $removedRun[$i] ?? null;
                $right = $addedRun[$i] ?? null;
                $paired[] = [
                    'kind' => $left !== null && $right !== null ? 'changed' : ($left !== null ? 'removed' : 'added'),
                    'left' => $left,
                    'right' => $right,
                    'hidden' => 0,
                ];
            }
            $removedRun = [];
            $addedRun = [];
        };
        $added = 0;
        $removed = 0;
        foreach ($script as [$op, $i, $j]) {
            if ($op === '=') {
                $flush();
                $paired[] = [
                    'kind' => 'same',
                    'left' => ['n' => $i + 1, 'text' => $before[$i]],
                    'right' => ['n' => $j + 1, 'text' => $after[$j]],
                    'hidden' => 0,
                ];

                continue;
            }
            if ($op === '-') {
                $removed++;
                $removedRun[] = ['n' => $i + 1, 'text' => $before[$i]];

                continue;
            }
            $added++;
            $addedRun[] = ['n' => $j + 1, 'text' => $after[$j]];
        }
        $flush();

        $changedRows = count(array_filter($paired, fn (array $row): bool => $row['kind'] !== 'same'));
        if (! $withRows) {
            return ['lines' => $changedRows, 'added' => $added, 'removed' => $removed, 'too_many' => false, 'rows' => [], 'truncated' => false];
        }

        [$rows, $truncated] = $this->window($paired);

        return ['lines' => $changedRows, 'added' => $added, 'removed' => $removed, 'too_many' => false, 'rows' => $rows, 'truncated' => $truncated];
    }

    /**
     * The edit script over both lists: ['=', i, j] keeps a line, ['-', i, null] removes before[i],
     * ['+', null, j] adds after[j]. Null when the change is bigger than MAX_EDITS.
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     * @return list<array{0: string, 1: int|null, 2: int|null}>|null
     */
    private function script(array $before, array $after): ?array
    {
        $n = count($before);
        $m = count($after);
        $prefix = 0;
        while ($prefix < $n && $prefix < $m && $before[$prefix] === $after[$prefix]) {
            $prefix++;
        }
        $suffix = 0;
        while ($suffix < $n - $prefix && $suffix < $m - $prefix && $before[$n - 1 - $suffix] === $after[$m - 1 - $suffix]) {
            $suffix++;
        }

        // Lines become small numbers, so the inner loop compares integers.
        $ids = [];
        $a = [];
        $b = [];
        for ($i = $prefix; $i < $n - $suffix; $i++) {
            $a[] = $ids[$before[$i]] ??= count($ids);
        }
        for ($j = $prefix; $j < $m - $suffix; $j++) {
            $b[] = $ids[$after[$j]] ??= count($ids);
        }

        $middle = $this->myers($a, $b);
        if ($middle === null) {
            return null;
        }

        $script = [];
        for ($i = 0; $i < $prefix; $i++) {
            $script[] = ['=', $i, $i];
        }
        foreach ($middle as [$op, $i, $j]) {
            $script[] = [$op, $i === null ? null : $i + $prefix, $j === null ? null : $j + $prefix];
        }
        for ($k = $suffix; $k > 0; $k--) {
            $script[] = ['=', $n - $k, $m - $k];
        }

        return $script;
    }

    /**
     * Myers' O((N+M)D) shortest edit script. Each round's furthest-reaching points are kept packed
     * (4 bytes each) for the walk back, so 2,000 edits need about 8 MB at most.
     *
     * @param  list<int>  $a
     * @param  list<int>  $b
     * @return list<array{0: string, 1: int|null, 2: int|null}>|null
     */
    private function myers(array $a, array $b): ?array
    {
        $n = count($a);
        $m = count($b);
        if ($n === 0 && $m === 0) {
            return [];
        }
        $limit = min(self::MAX_EDITS, $n + $m);
        $v = [1 => 0];
        /** @var list<string> $trace V as it stood before each round, for k = -(d-1) … d-1. */
        $trace = [];
        $found = null;
        for ($d = 0; $d <= $limit; $d++) {
            $snapshot = [];
            for ($k = -($d - 1); $k <= $d - 1; $k += 2) {
                $snapshot[] = $v[$k] ?? 0;
            }
            $trace[$d] = $snapshot === [] ? '' : pack('l*', ...$snapshot);

            for ($k = -$d; $k <= $d; $k += 2) {
                if ($k === -$d || ($k !== $d && ($v[$k - 1] ?? -1) < ($v[$k + 1] ?? -1))) {
                    $x = $v[$k + 1] ?? 0;
                } else {
                    $x = ($v[$k - 1] ?? 0) + 1;
                }
                $y = $x - $k;
                while ($x < $n && $y < $m && $a[$x] === $b[$y]) {
                    $x++;
                    $y++;
                }
                $v[$k] = $x;
                if ($x >= $n && $y >= $m) {
                    $found = $d;

                    break 2;
                }
            }
        }
        if ($found === null) {
            return null;
        }

        $reversed = [];
        $x = $n;
        $y = $m;
        for ($d = $found; $d > 0; $d--) {
            $previous = array_values(unpack('l*', $trace[$d]));
            $at = fn (int $k): int => $previous[intdiv($k + $d - 1, 2)];
            $k = $x - $y;
            $previousK = ($k === -$d || ($k !== $d && $at($k - 1) < $at($k + 1))) ? $k + 1 : $k - 1;
            $previousX = $at($previousK);
            $previousY = $previousX - $previousK;
            while ($x > $previousX && $y > $previousY) {
                $reversed[] = ['=', $x - 1, $y - 1];
                $x--;
                $y--;
            }
            if ($x === $previousX) {
                $reversed[] = ['+', null, $y - 1];
            } else {
                $reversed[] = ['-', $x - 1, null];
            }
            $x = $previousX;
            $y = $previousY;
        }
        while ($x > 0 && $y > 0) {
            $reversed[] = ['=', $x - 1, $y - 1];
            $x--;
            $y--;
        }

        return array_reverse($reversed);
    }

    /**
     * Keep CONTEXT unchanged lines either side of each change and fold the rest into one "N lines
     * the same" row. With no change at all, the first few lines show what was compared.
     *
     * @param  list<array{kind: string, left: array{n: int, text: string}|null, right: array{n: int, text: string}|null, hidden: int}>  $paired
     * @return array{0: list<array{kind: string, left: array{n: int, text: string}|null, right: array{n: int, text: string}|null, hidden: int}>, 1: bool}
     */
    private function window(array $paired): array
    {
        $total = count($paired);
        $keep = array_fill(0, $total, false);
        $anyChange = false;
        foreach ($paired as $index => $row) {
            if ($row['kind'] === 'same') {
                continue;
            }
            $anyChange = true;
            for ($c = max(0, $index - self::CONTEXT); $c <= min($total - 1, $index + self::CONTEXT); $c++) {
                $keep[$c] = true;
            }
        }
        if (! $anyChange) {
            for ($c = 0; $c < min(8, $total); $c++) {
                $keep[$c] = true;
            }
        }

        $rows = [];
        $hidden = 0;
        foreach ($paired as $index => $row) {
            if ($keep[$index]) {
                if ($hidden > 0) {
                    $rows[] = ['kind' => 'gap', 'left' => null, 'right' => null, 'hidden' => $hidden];
                    $hidden = 0;
                }
                $rows[] = $row;

                continue;
            }
            $hidden++;
        }
        if ($hidden > 0) {
            $rows[] = ['kind' => 'gap', 'left' => null, 'right' => null, 'hidden' => $hidden];
        }

        $truncated = count($rows) > self::MAX_ROWS;

        return [array_slice($rows, 0, self::MAX_ROWS), $truncated];
    }

    /** @return array{lines: null, added: null, removed: null, too_many: true, rows: array{}, truncated: false} */
    private static function tooMany(): array
    {
        return ['lines' => null, 'added' => null, 'removed' => null, 'too_many' => true, 'rows' => [], 'truncated' => false];
    }
}
