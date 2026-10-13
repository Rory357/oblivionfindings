<?php

namespace Tests\Unit\SecurityDevices;

use App\Domain\SecurityDevices\Cctv\Services\CctvJsonBody;
use Illuminate\Http\Request;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CctvJsonBodyTest extends TestCase
{
    public function test_original_signalling_bytes_are_preserved_when_input_middleware_trimmed_the_parsed_bag(): void
    {
        $sdp = CctvContractTest::offer();
        $raw = json_encode(['expected_actor_id' => 7, 'sdp' => $sdp], JSON_THROW_ON_ERROR);
        $request = Request::create('/security-devices/cctv/live-sessions/example/offer', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $raw);
        $request->request->replace(['expected_actor_id' => 7, 'sdp' => trim($sdp)]);
        self::assertSame(['expected_actor_id' => 7, 'sdp' => $sdp], CctvJsonBody::read($request, 65536));
        $nested = ['results' => [['answer' => ['type' => 'answer', 'sdp' => $sdp]]]];
        $request = Request::create('/api/monitoring/collectors/media/results', 'POST', [], [], [], [], json_encode($nested, JSON_THROW_ON_ERROR));
        self::assertSame($nested, CctvJsonBody::read($request, 262144));
    }

    public function test_ambiguous_malformed_oversized_or_nonobject_json_is_denied_before_authority_processing(): void
    {
        foreach (['[]', '{}', 'null', '7', '{"sdp":"first","sdp":"second"}', '{"sdp":"first","\u0073dp":"second"}', '{"results":[{"answer":{"sdp":"first","sdp":"second"}}]}', '{broken', '{"sdp":"'.str_repeat('x', 101).'"}', '{"sdp":"'."\xFF".'"}', str_repeat('{"deep":', 33).'0'.str_repeat('}', 33)] as $raw) {
            $request = Request::create('/example', 'POST', [], [], [], [], $raw);
            try {
                CctvJsonBody::read($request, 100);
                self::fail('Unsafe signalling JSON was accepted.');
            } catch (InvalidArgumentException $failure) {
                self::assertSame('invalid_request', $failure->getMessage());
            }
        }
        $request = Request::create('/example', 'POST', [], [], [], [], '{"one":{"sdp":"a"},"two":{"sdp":"b"}}');
        self::assertSame(['one' => ['sdp' => 'a'], 'two' => ['sdp' => 'b']], CctvJsonBody::read($request, 100));
    }
}
