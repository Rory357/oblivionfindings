<?php

use App\Support\Signing\CompactToken;
use App\Support\Signing\InvalidCompactToken;
use PHPUnit\Framework\TestCase;

final class CompactTokenTest extends TestCase
{
    private const SECRET = 'test-secret-for-oblivion-live-0123456789abcdef';

    private const KEYS = ['k2026a' => self::SECRET, 'k2025z' => 'previous-secret-for-oblivion-live-0123456789'];

    /** The gateway's own tests (live/test/tickets.test.mjs) check this exact token. */
    private const VECTOR = 'eyJhbGciOiJIUzI1NiIsInR5cCI6Ik9MIiwia2lkIjoiazIwMjZhIn0.eyJ2IjoxLCJzdWIiOjQyLCJhdWQiOiJvYmxpdmlvbi1saXZlIiwic2lkIjoiMzVmMTQxY2YzOTU2MDBhYzJiZGM4ODQyYzM1MTNmOWVmY2UzN2ZiNzdkMDJjNjU0Y2VmYWJlNmRjZTE5YTkxYSIsImlhdCI6MTc5MjgwMDAwMCwiZXhwIjoxNzkyODAwMDYwLCJqdGkiOiJBQUVDQXdRRkJnY0lDUW9MREEwT0R3IiwiZmFtIjpmYWxzZX0.MzJ55Ps3kPdgyOq3cyPxWxvS7Td-2gyhdszSwSzmLS0';

    private static function claims(array $overrides = []): array
    {
        return [...['v' => 1, 'sub' => 42, 'aud' => 'oblivion-live', 'sid' => hash('sha256', 'vector-session'),
            'iat' => 1792800000, 'exp' => 1792800060, 'jti' => CompactToken::encode(implode('', array_map('chr', range(0, 15)))), 'fam' => false], ...$overrides];
    }

    private static function refusal(callable $check): string
    {
        try {
            $check();
        } catch (InvalidCompactToken $error) {
            return $error->reason;
        }

        return 'accepted';
    }

    public function test_it_produces_the_vector_the_gateway_checks(): void
    {
        $this->assertSame(self::VECTOR, CompactToken::issue(self::claims(), 'k2026a', self::SECRET));
        $claims = CompactToken::verify(self::VECTOR, self::KEYS, 'oblivion-live', 5, 1792800010);
        $this->assertSame(42, $claims['sub']);
        $this->assertFalse($claims['fam']);
    }

    public function test_it_uses_unpadded_base64url_and_refuses_other_spellings(): void
    {
        $this->assertSame('Zm9vYg', CompactToken::encode('foob'));
        $this->assertSame('-_8', CompactToken::encode("\xfb\xff"));
        $this->assertSame('foob', CompactToken::decode('Zm9vYg'));
        foreach (['Zm9vYg==', 'Zm9v+g', 'Zm9vYh', 'Z', ' Zm9v', 'Zm9v/g'] as $text) {
            $this->assertSame('malformed', self::refusal(fn () => CompactToken::decode($text)), $text);
        }
    }

    public function test_it_requires_audience_expiry_and_a_known_key(): void
    {
        $now = 1792800010;
        $verify = fn (string $token, int $at = 1792800010) => CompactToken::verify($token, self::KEYS, 'oblivion-live', 5, $at);
        $this->assertSame('audience', self::refusal(fn () => $verify(CompactToken::issue(self::claims(['aud' => 'oblivion-calls']), 'k2026a', self::SECRET))));
        $this->assertSame('audience', self::refusal(fn () => $verify(CompactToken::issue(array_diff_key(self::claims(), ['aud' => 1]), 'k2026a', self::SECRET))));
        $this->assertSame('expired', self::refusal(fn () => $verify(CompactToken::issue(array_diff_key(self::claims(), ['exp' => 1]), 'k2026a', self::SECRET))));
        $this->assertSame('expired', self::refusal(fn () => $verify(self::VECTOR, 1792800066)));
        $this->assertSame('accepted', self::refusal(fn () => $verify(self::VECTOR, 1792800065)), 'five seconds of clock difference');
        $this->assertSame('not_yet_valid', self::refusal(fn () => $verify(self::VECTOR, $now - 20)));
        $this->assertSame('key', self::refusal(fn () => $verify(CompactToken::issue(self::claims(), 'k1999', self::SECRET))));
        // The previous key still verifies tokens that name it.
        $this->assertSame('accepted', self::refusal(fn () => $verify(CompactToken::issue(self::claims(), 'k2025z', self::KEYS['k2025z']))));
    }

    public function test_it_refuses_bad_signatures_and_any_algorithm_but_hs256(): void
    {
        $verify = fn (string $token) => CompactToken::verify($token, self::KEYS, 'oblivion-live', 5, 1792800010);
        $this->assertSame('signature', self::refusal(fn () => $verify(CompactToken::issue(self::claims(), 'k2026a', 'not-the-secret-0123456789abcdefghij'))));
        // The right secret under the wrong key name.
        $this->assertSame('signature', self::refusal(fn () => $verify(CompactToken::issue(self::claims(), 'k2025z', self::SECRET))));
        [$header, $payload, $signature] = explode('.', self::VECTOR);
        $other = explode('.', CompactToken::issue(self::claims(['sub' => 43]), 'k2026a', self::SECRET))[1];
        $this->assertSame('signature', self::refusal(fn () => $verify("$header.$other.$signature")));
        foreach (['none', 'HS512', 'RS256', 'hs256'] as $algorithm) {
            $forged = CompactToken::encode(json_encode(['alg' => $algorithm, 'typ' => 'OL', 'kid' => 'k2026a']));
            $this->assertSame('algorithm', self::refusal(fn () => $verify("$forged.$payload.$signature")), $algorithm);
            $this->assertSame('algorithm', self::refusal(fn () => $verify("$forged.$payload.")), $algorithm);
        }
        $wrongType = CompactToken::encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => 'k2026a']));
        $this->assertSame('algorithm', self::refusal(fn () => $verify("$wrongType.$payload.$signature")));
        foreach (['', 'a.b', 'a.b.c.d', '..', str_repeat('x', 3000), self::VECTOR.'='] as $token) {
            $this->assertNotSame('accepted', self::refusal(fn () => $verify($token)));
        }
    }
}
