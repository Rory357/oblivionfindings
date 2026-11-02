<?php

namespace Tests\Unit\Push;

use App\Services\Push\WebPushEncryption;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** RFC 8291 message encryption. On Windows run with OPENSSL_CONF set to Herd's php84/extras/ssl/openssl.cnf. */
class WebPushEncryptionTest extends TestCase
{
    use BrowserSide;

    // RFC 8291 section 5 and Appendix A.
    private const PLAINTEXT = 'When I grow up, I want to be a watermelon';

    private const AS_PUBLIC = 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIg Dll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8';

    private const AS_PRIVATE = 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw';

    private const UA_PUBLIC = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcx aOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';

    private const UA_PRIVATE = 'q1dXpw3UpT5VOmu_cf_v6ih07Aems3njxI-JWgLcM94';

    private const SALT = 'DGv6ra1nlYgDCS1FRnbzlw';

    private const AUTH_SECRET = 'BTBZMqHH6r4Tts7J_aSIgg';

    private const HEADER = 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z 9KsN6nGRTbVYI_c7VJSPQTBtkgcy27ml mlMoZIIgDll6e3vCYLocInmYWAmS6Tlz AC8wEqKK6PBru3jl7A8';

    private const CIPHERTEXT = '8pfeW0KbunFT06SuDKoJH9Ql87S1QUrd irN6GcG7sFz1y1sqLgVi1VhjVkHsUoEs bI_0LpXMuGvnzQ';

    private const BODY = 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27ml
        mlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPT
        pK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN';

    private function rfcEncryption(): WebPushEncryption
    {
        return WebPushEncryption::withFixedInputs(self::unb64u(self::SALT), self::unb64u(self::AS_PRIVATE), self::unb64u(self::AS_PUBLIC));
    }

    public function test_reproduces_the_rfc_8291_appendix_a_vector_byte_for_byte(): void
    {
        $body = $this->rfcEncryption()->encrypt(self::unb64u(self::UA_PUBLIC), self::unb64u(self::AUTH_SECRET), self::PLAINTEXT);

        self::assertSame(self::unb64u(self::HEADER), substr($body, 0, 86), 'the 86-byte header');
        self::assertSame(self::unb64u(self::CIPHERTEXT), substr($body, 86), 'the ciphertext and tag');
        self::assertSame(self::unb64u(self::BODY), $body, 'the section 5 body');
        self::assertSame(144, strlen($body));
    }

    public function test_the_rfc_receiver_can_decrypt_the_vector(): void
    {
        [$uaPrivate, $uaPublic] = self::browserKeyPair(self::unb64u(self::UA_PRIVATE));
        self::assertSame(self::unb64u(self::UA_PUBLIC), $uaPublic);

        self::assertSame(self::PLAINTEXT, self::decrypt(self::unb64u(self::BODY), $uaPrivate, $uaPublic, self::unb64u(self::AUTH_SECRET)));
    }

    public function test_round_trips_through_a_fresh_browser_key_with_fresh_salt_and_server_key_each_time(): void
    {
        [$uaPrivate, $uaPublic] = self::browserKeyPair();
        $authSecret = random_bytes(16);
        $payload = json_encode(['title' => 'Medication due', 'body' => 'Open Oblivion to record the dose.', 'data' => ['url' => '/emar']]);
        $encryption = new WebPushEncryption;

        $first = $encryption->encrypt($uaPublic, $authSecret, $payload);
        $second = $encryption->encrypt($uaPublic, $authSecret, $payload);

        self::assertSame($payload, self::decrypt($first, $uaPrivate, $uaPublic, $authSecret));
        self::assertSame($payload, self::decrypt($second, $uaPrivate, $uaPublic, $authSecret));
        self::assertNotSame(substr($first, 0, 16), substr($second, 0, 16), 'a new salt per message');
        self::assertNotSame(substr($first, 21, 65), substr($second, 21, 65), 'a new server key per message');
        self::assertStringNotContainsString('Medication', $first);
    }

    public function test_a_full_record_fits_4096_bytes_and_one_byte_more_is_refused(): void
    {
        [$uaPrivate, $uaPublic] = self::browserKeyPair();
        $authSecret = random_bytes(16);
        $largest = str_repeat('a', WebPushEncryption::MAX_PAYLOAD_BYTES);

        $body = (new WebPushEncryption)->encrypt($uaPublic, $authSecret, $largest);
        self::assertSame(4096, strlen($body));
        self::assertSame($largest, self::decrypt($body, $uaPrivate, $uaPublic, $authSecret));

        $this->expectException(InvalidArgumentException::class);
        (new WebPushEncryption)->encrypt($uaPublic, $authSecret, $largest.'a');
    }

    public static function malformedKeys(): array
    {
        $point = self::unb64u(self::UA_PUBLIC);

        return [
            'empty key' => ['', random_bytes(16)],
            'compressed point' => ["\x02".substr($point, 1, 32), random_bytes(16)],
            'missing 0x04 prefix' => ["\x05".substr($point, 1), random_bytes(16)],
            'point off the curve' => ["\x04".str_repeat("\x01", 64), random_bytes(16)],
            'short auth secret' => [$point, random_bytes(15)],
            'long auth secret' => [$point, random_bytes(17)],
        ];
    }

    #[DataProvider('malformedKeys')]
    public function test_refuses_malformed_browser_keys(string $uaPublic, string $authSecret): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new WebPushEncryption)->encrypt($uaPublic, $authSecret, 'payload');
    }
}
