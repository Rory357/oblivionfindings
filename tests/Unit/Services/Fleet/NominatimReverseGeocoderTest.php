<?php

namespace Tests\Unit\Services\Fleet;

use App\Services\Fleet\Geocoding\NominatimReverseGeocoder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NominatimReverseGeocoderTest extends TestCase
{
    public function test_nominatim_reverse_geocoder_returns_display_name(): void
    {
        config([
            'fleet.maps.reverse_geocode_timeout_seconds' => 6,
            'fleet.maps.nominatim.endpoint' => 'http://nominatim.test',
            'fleet.maps.nominatim.user_agent' => 'OblivionFindings/Test',
            'fleet.maps.nominatim.contact_email' => 'ops@example.test',
        ]);

        Http::fake([
            'nominatim.test/reverse*' => Http::response([
                'display_name' => 'Te Kowhai Road, Hamilton, Waikato, New Zealand',
            ], 200),
        ]);

        $address = app(NominatimReverseGeocoder::class)
            ->reverseGeocode(-37.723663, 175.241560, 123);

        $this->assertSame('Te Kowhai Road, Hamilton, Waikato, New Zealand', $address);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/reverse')
            && $request['format'] === 'jsonv2'
            && abs(((float) $request['lat']) - (-37.723663)) < 0.000001
            && abs(((float) $request['lon']) - 175.241560) < 0.000001
            && (int) $request['addressdetails'] === 1
            && (int) $request['zoom'] === 18
            && $request['email'] === 'ops@example.test'
            && $request->hasHeader('User-Agent', 'OblivionFindings/Test'));
    }
}
