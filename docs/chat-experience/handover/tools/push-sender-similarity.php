<?php

/*
 * Token-similarity check for the clean-room push sender (checker session, 23 Oct 2026).
 *
 * usage: php similarity.php <mode> <k> <report.txt> <listfile-new> <listfile-corpus>
 *   mode   norm  identifiers -> ID, variables -> $V, strings -> STR, numbers -> NUM (structure only)
 *          raw   identifiers, variables and literals kept (literal copying)
 *          words comments only: lower-cased word k-grams (copied comments / docblocks)
 *   k      minimum shared run, in tokens (or words for "words")
 *
 * Each list file holds one PHP file path per line. For every new file the report gives:
 * token count, tokens covered by a shared run of >= k, and every maximal shared run with
 * its line ranges in both files, longest first.
 */

[$self, $mode, $k, $reportPath, $newList, $corpusList] = $argv + array_fill(0, 6, null);
$k = (int) $k;
if (! in_array($mode, ['norm', 'raw', 'words'], true) || $k < 2) {
    fwrite(STDERR, "usage: php similarity.php norm|raw|words <k> <report> <new-list> <corpus-list>\n");
    exit(2);
}

function readList(string $path): array
{
    return array_values(array_filter(array_map('trim', file($path)), fn ($l) => $l !== ''));
}

/** @return list<array{0:string,1:int}> [token, line] */
function tokens(string $file, string $mode): array
{
    $src = file_get_contents($file);
    $out = [];
    $all = @token_get_all($src);
    if ($mode === 'words') {
        foreach ($all as $t) {
            if (is_array($t) && ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) {
                $line = $t[2];
                foreach (preg_split('/\R/', $t[1]) as $i => $text) {
                    preg_match_all('/[a-z0-9]+/i', $text, $m);
                    foreach ($m[0] as $w) {
                        $out[] = [strtolower($w), $line + $i];
                    }
                }
            }
        }

        return $out;
    }

    $depth = 0;
    $skipUntilSemicolon = false;
    foreach ($all as $t) {
        if (is_array($t)) {
            [$id, $text, $line] = $t;
            if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG], true)) {
                continue;
            }
            // Drop file-level namespace, use (imports) and declare statements: shared by every PHP file.
            if ($depth === 0 && in_array($id, [T_NAMESPACE, T_USE, T_DECLARE], true)) {
                $skipUntilSemicolon = true;
            }
            if ($skipUntilSemicolon) {
                continue;
            }
            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            }
            if ($mode === 'norm') {
                $tok = match (true) {
                    $id === T_VARIABLE => '$V',
                    in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true) => 'ID',
                    in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) => 'STR',
                    in_array($id, [T_LNUMBER, T_DNUMBER], true) => 'NUM',
                    default => strtolower($text),
                };
            } else {
                $tok = in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)
                    ? strtolower(ltrim($text, '\\'))
                    : ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE || $id === T_VARIABLE || $id === T_LNUMBER || $id === T_DNUMBER ? $text : strtolower($text));
            }
            $out[] = [$tok, $line];
        } else {
            $line = $out === [] ? 1 : end($out)[1];
            if ($skipUntilSemicolon) {
                if ($t === ';' || $t === '{') {
                    $skipUntilSemicolon = false;
                    if ($t === '{') {
                        $depth++;
                    }
                }

                continue;
            }
            if ($t === '{') {
                $depth++;
            } elseif ($t === '}') {
                $depth = max(0, $depth - 1);
            }
            $out[] = [$t, $line];
        }
    }

    return $out;
}

$newFiles = readList($newList);
$corpusFiles = readList($corpusList);

// Index every k-gram of the corpus.
$corpus = [];
$index = [];
foreach ($corpusFiles as $ci => $file) {
    $toks = tokens($file, $mode);
    $corpus[$ci] = $toks;
    $seq = array_column($toks, 0);
    for ($p = 0; $p + $k <= count($seq); $p++) {
        $h = crc32(implode("\x1f", array_slice($seq, $p, $k)));
        $index[$h][] = $ci * 1000000 + $p;
    }
}

$report = [];
$report[] = "mode=$mode k=$k corpus_files=".count($corpusFiles).' corpus_tokens='.array_sum(array_map('count', $corpus)).' kgrams='.count($index);
$summary = [];
$allMatches = [];
foreach ($newFiles as $file) {
    $toks = tokens($file, $mode);
    $seq = array_column($toks, 0);
    $n = count($seq);
    $covered = array_fill(0, max($n, 1), false);
    $matches = [];
    $seen = [];
    for ($i = 0; $i + $k <= $n; $i++) {
        $h = crc32(implode("\x1f", array_slice($seq, $i, $k)));
        foreach (array_slice($index[$h] ?? [], 0, 5000) as $packed) {
            $ci = intdiv($packed, 1000000);
            $p = $packed % 1000000;
            // Only maximal runs: skip when the previous tokens also match (that run was already recorded).
            if ($i > 0 && $p > 0 && $seq[$i - 1] === $corpus[$ci][$p - 1][0]) {
                continue;
            }
            $len = 0;
            $cseq = $corpus[$ci];
            while ($i + $len < $n && $p + $len < count($cseq) && $seq[$i + $len] === $cseq[$p + $len][0]) {
                $len++;
            }
            if ($len < $k) {
                continue;
            }
            for ($x = $i; $x < $i + $len; $x++) {
                $covered[$x] = true;
            }
            $key = "$i:$len";
            $matches[] = [
                'len' => $len,
                'new' => [$toks[$i][1], $toks[$i + $len - 1][1]],
                'corpus' => $corpusFiles[$ci],
                'cl' => [$cseq[$p][1], $cseq[$p + $len - 1][1]],
                'i' => $i,
                'text' => implode(' ', array_slice($seq, $i, min($len, 60))),
            ];
            $seen[$key] = ($seen[$key] ?? 0) + 1;
        }
    }
    $cov = $n === 0 ? 0 : count(array_filter($covered)) / $n;
    $distinctRuns = [];
    foreach ($matches as $m) {
        $distinctRuns[$m['i'].':'.$m['len']][] = $m;
    }
    $summary[] = sprintf('%-60s tokens=%5d covered=%5d (%5.1f%%) distinct_runs=%d longest=%d',
        $file, $n, count(array_filter($covered)), 100 * $cov, count($distinctRuns), $matches === [] ? 0 : max(array_column($matches, 'len')));
    uasort($distinctRuns, fn ($a, $b) => $b[0]['len'] <=> $a[0]['len']);
    foreach ($distinctRuns as $runs) {
        $m = $runs[0];
        $where = array_map(fn ($r) => basename($r['corpus']).':'.$r['cl'][0].'-'.$r['cl'][1], array_slice($runs, 0, 4));
        $allMatches[] = sprintf("[%3d] %s:%d-%d  ~  %s%s\n      %s",
            $m['len'], $file, $m['new'][0], $m['new'][1], implode(', ', $where),
            count($runs) > 4 ? ' (+'.(count($runs) - 4).' more)' : '', $m['text']);
    }
}

$report[] = '';
$report[] = 'SUMMARY';
array_push($report, ...$summary);
$report[] = '';
$report[] = 'RUNS (longest first per file; [length] new-file:lines ~ corpus-file:lines)';
array_push($report, ...$allMatches);
file_put_contents($reportPath, implode("\n", $report)."\n");
echo implode("\n", array_slice($report, 0, count($summary) + 4)), "\n";
