<?php

namespace Tests\Unit\Push;

use App\Services\Push\P256;
use App\Services\Push\VapidKeys;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Key generation and the stored .env forms. On Windows run with OPENSSL_CONF set. */
class VapidKeysTest extends TestCase
{
    use BrowserSide;

    public function test_generates_a_pair_in_the_stored_base64url_forms(): void
    {
        $keys = VapidKeys::generate();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{87}$/', $keys->publicKey, '65 bytes, unpadded base64url');
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $keys->privateKey, '32 bytes, unpadded base64url');
        self::assertSame("\x04", self::unb64u($keys->publicKey)[0]);
        self::assertSame(self::unb64u($keys->publicKey), $keys->publicKeyBytes());
        self::assertNotSame($keys->publicKey, VapidKeys::generate()->publicKey);

        // The halves belong together: PHP derives the same point from the scalar.
        [, $point] = self::browserKeyPair(self::unb64u($keys->privateKey));
        self::assertSame($keys->publicKeyBytes(), $point);
        $keys->signingKey();
    }

    public function test_reads_a_stored_pair_without_changing_it(): void
    {
        $keys = VapidKeys::generate();
        $stored = VapidKeys::fromStored($keys->publicKey, $keys->privateKey);

        self::assertSame($keys->publicKey, $stored->publicKey);
        self::assertSame($keys->privateKey, $stored->privateKey);
        self::assertTrue(openssl_sign('probe', $signature, $stored->signingKey(), OPENSSL_ALGO_SHA256));
        self::assertSame(1, openssl_verify('probe', $signature, self::publicKeyFromPoint($stored->publicKeyBytes()), OPENSSL_ALGO_SHA256));
        self::assertSame(1, openssl_verify('probe', self::rawSignatureToDer(P256::signatureToRaw($signature)), self::publicKeyFromPoint($stored->publicKeyBytes()), OPENSSL_ALGO_SHA256));
    }

    public function test_widens_a_private_key_stored_without_its_leading_zero_byte(): void
    {
        $scalar = "\x00".substr(hash('sha256', 'oblivion vapid leading zero', true), 1);
        [, $point] = self::browserKeyPair($scalar);

        $keys = VapidKeys::fromStored(self::b64u($point), self::b64u(substr($scalar, 1)));

        self::assertSame(self::b64u($scalar), $keys->privateKey);
        $keys->signingKey();
    }

    public static function badPairs(): array
    {
        $good = VapidKeys::generate();

        return [
            'empty public' => ['', $good->privateKey],
            'empty private' => [$good->publicKey, ''],
            'public not base64' => ['%%%', $good->privateKey],
            'public too short' => [substr($good->publicKey, 0, 80), $good->privateKey],
            'private too long' => [$good->publicKey, self::b64u(random_bytes(33))],
            'private all zero' => [$good->publicKey, self::b64u(str_repeat("\x00", 32))],
        ];
    }

    #[DataProvider('badPairs')]
    public function test_refuses_keys_not_in_the_stored_form(string $public, string $private): void
    {
        $this->expectException(InvalidArgumentException::class);
        VapidKeys::fromStored($public, $private);
    }

    public function test_refuses_to_sign_when_the_halves_belong_to_different_pairs(): void
    {
        $keys = VapidKeys::fromStored(VapidKeys::generate()->publicKey, VapidKeys::generate()->privateKey);

        $this->expectException(InvalidArgumentException::class);
        $keys->signingKey();
    }

    public function test_keeps_the_private_key_out_of_dumps(): void
    {
        $keys = VapidKeys::generate();

        self::assertStringNotContainsString($keys->privateKey, print_r($keys, true));
        self::assertStringContainsString($keys->publicKey, print_r($keys, true));
    }

    public static function derSignatures(): array
    {
        $high = "\x80".str_repeat("\x11", 31); // needs a 0x00 sign byte in DER
        $short = str_repeat("\x22", 30);        // DER drops leading zeros

        return [
            'sign bytes' => ["\x30\x46\x02\x21\x00".$high."\x02\x21\x00".$high, $high.$high],
            'short values' => ["\x30\x40\x02\x1e".$short."\x02\x1e".$short, "\x00\x00".$short."\x00\x00".$short],
        ];
    }

    #[DataProvider('derSignatures')]
    public function test_converts_der_signatures_to_fixed_width_r_and_s(string $der, string $raw): void
    {
        self::assertSame($raw, P256::signatureToRaw($der));
    }

    public static function brokenDer(): array
    {
        return [
            'empty' => [''],
            'not a sequence' => ["\x31\x06\x02\x01\x01\x02\x01\x01"],
            'length too long' => ["\x30\x07\x02\x01\x01\x02\x01\x01"],
            'trailing bytes' => ["\x30\x06\x02\x01\x01\x02\x01\x01\x00"],
            'one integer' => ["\x30\x03\x02\x01\x01"],
            'value over 32 bytes' => ["\x30\x26\x02\x21\x01".str_repeat("\x01", 32)."\x02\x01\x01"],
            'zero value' => ["\x30\x06\x02\x01\x00\x02\x01\x01"],
        ];
    }

    #[DataProvider('brokenDer')]
    public function test_refuses_der_that_is_not_a_p256_signature(string $der): void
    {
        $this->expectException(InvalidArgumentException::class);
        P256::signatureToRaw($der);
    }
}
