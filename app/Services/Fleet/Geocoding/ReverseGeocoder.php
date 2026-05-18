<?php

namespace App\Services\Fleet\Geocoding;

interface ReverseGeocoder
{
    public function reverseGeocode(float $lat, float $lng, ?int $assetId = null): ?string;
}
