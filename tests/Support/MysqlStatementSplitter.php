<?php

namespace Tests\Support;

/**
 * Splits a mysql-client SQL script (a mysqldump file) into single statements
 * the way the `mysql` client does, so the PDO schema loader can run dumps that
 * contain triggers, quoted data and comments.
 *
 * Understood: `DELIMITER x` lines, '...' and "..." strings (backslash and
 * doubled-quote escapes), `...` identifiers, `-- ` and `#` line comments and
 * plain block comments (dropped), and executable comments (kept as statement
 * text, which is how mysqldump wraps CREATE TRIGGER and session settings).
 *
 * CRLF line ends become LF first, as the Windows mysql client reads them, so a
 * trigger body is stored the same way whichever path loaded the file.
 * (mysqldump escapes line breaks inside data, so only line ends change.)
 */
final class MysqlStatementSplitter
{
    /**
     * @return \Generator<int, string>
     */
    public static function split(string $sql): \Generator
    {
        $sql = str_replace("\r\n", "\n", $sql);
        $length = strlen($sql);
        $delimiter = ';';
        $statement = '';
        $position = 0;
        $atLineStart = true;

        while ($position < $length) {
            if ($atLineStart && trim($statement) === '') {
                $lineEnd = strpos($sql, "\n", $position);
                $line = substr($sql, $position, $lineEnd === false ? null : $lineEnd - $position);

                if (preg_match('/\A\s*DELIMITER\s+(\S+)\s*\z/i', $line, $match) === 1) {
                    $delimiter = $match[1];
                    $statement = '';
                    $position = $lineEnd === false ? $length : $lineEnd + 1;

                    continue;
                }
            }

            // Copy plain text up to the next character that can change state.
            $plain = strcspn($sql, "'\"`-#/\n".$delimiter[0], $position);
            if ($plain > 0) {
                $statement .= substr($sql, $position, $plain);
                $position += $plain;
                $atLineStart = false;

                continue;
            }

            $char = $sql[$position];

            if ($char === "\n") {
                $statement .= "\n";
                $position++;
                $atLineStart = true;

                continue;
            }

            $atLineStart = false;

            if (substr_compare($sql, $delimiter, $position, strlen($delimiter)) === 0) {
                $position += strlen($delimiter);
                if (trim($statement) !== '') {
                    yield trim($statement);
                }
                $statement = '';

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $end = self::quotedEnd($sql, $position, $char);
                $statement .= substr($sql, $position, $end - $position);
                $position = $end;

                continue;
            }

            if ($char === '#' || ($char === '-' && self::startsLineComment($sql, $position))) {
                $lineEnd = strpos($sql, "\n", $position);
                $position = $lineEnd === false ? $length : $lineEnd;

                continue;
            }

            if ($char === '/' && ($sql[$position + 1] ?? '') === '*') {
                $marker = $sql[$position + 2] ?? '';
                if ($marker === '!' || $marker === '+') {
                    // Executable comment: its body is SQL, so keep scanning it as text.
                    $statement .= '/*'.$marker;
                    $position += 3;

                    continue;
                }

                $close = strpos($sql, '*/', $position + 2);
                $position = $close === false ? $length : $close + 2;
                $statement .= ' ';

                continue;
            }

            $statement .= $char;
            $position++;
        }

        if (trim($statement) !== '') {
            yield trim($statement);
        }
    }

    private static function quotedEnd(string $sql, int $position, string $quote): int
    {
        $length = strlen($sql);
        $cursor = $position + 1;

        while ($cursor < $length) {
            $next = strcspn($sql, $quote === '`' ? '`' : $quote.'\\', $cursor);
            $cursor += $next;

            if ($cursor >= $length) {
                return $length;
            }

            if ($sql[$cursor] === '\\') {
                $cursor += 2;

                continue;
            }

            // A doubled quote is an escaped quote inside the string.
            if (($sql[$cursor + 1] ?? '') === $quote) {
                $cursor += 2;

                continue;
            }

            return $cursor + 1;
        }

        return $length;
    }

    private static function startsLineComment(string $sql, int $position): bool
    {
        if (($sql[$position + 1] ?? '') !== '-') {
            return false;
        }

        $after = $sql[$position + 2] ?? "\n";

        return $after === ' ' || $after === "\t" || $after === "\n" || $after === "\r";
    }
}
