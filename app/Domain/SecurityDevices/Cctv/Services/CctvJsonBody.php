<?php

namespace App\Domain\SecurityDevices\Cctv\Services;

use Illuminate\Http\Request;
use InvalidArgumentException;
use JsonException;

/** Signalling strings retain their original bytes despite global input trimming. */
final class CctvJsonBody
{
    public static function read(Request $request, int $maximum): array
    {
        $raw = $request->getContent();
        if (! is_string($raw) || strlen($raw) > $maximum || ! mb_check_encoding($raw, 'UTF-8')) {
            throw new InvalidArgumentException('invalid_request');
        }
        try {
            $value = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('invalid_request');
        }
        if (! is_array($value) || array_is_list($value) || ! str_starts_with(ltrim($raw), '{')) {
            throw new InvalidArgumentException('invalid_request');
        }
        // JSON decoding accepts duplicate keys. Reject them, including escaped
        // spellings, rather than permit two interpretations of a signed body.
        preg_match_all('/"(?:\\\\.|[^"\\\\])*"|[{}\[\],:]|[^\s{}\[\],:]+/s', $raw, $matches);
        $stack = [];
        foreach ($matches[0] as $token) {
            if ($token === '{' || $token === '[') {
                $stack[] = ['object' => $token === '{', 'key' => $token === '{', 'seen' => []];

                continue;
            }
            if ($token === '}' || $token === ']') {
                array_pop($stack);

                continue;
            }
            $index = count($stack) - 1;
            if ($index < 0 || ! $stack[$index]['object']) {
                continue;
            }
            if ($token === ',') {
                $stack[$index]['key'] = true;
            } elseif ($stack[$index]['key'] && str_starts_with($token, '"')) {
                $key = ':'.json_decode($token, true, 32, JSON_THROW_ON_ERROR);
                if (isset($stack[$index]['seen'][$key])) {
                    throw new InvalidArgumentException('invalid_request');
                }
                $stack[$index]['seen'][$key] = true;
                $stack[$index]['key'] = false;
            }
        }

        return $value;
    }
}
