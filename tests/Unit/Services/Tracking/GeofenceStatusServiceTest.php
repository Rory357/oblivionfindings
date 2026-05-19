<?php

use App\Models\AssetGeofence;
use App\Services\Tracking\GeofenceStatusService;

beforeEach(function () {
    $this->svc = new GeofenceStatusService;
});

function makeCircle(array $shape, bool $active = true): AssetGeofence
{
    $gf = new AssetGeofence;
    $gf->type = 'circle';
    $gf->shape = $shape;
    $gf->is_active = $active;

    return $gf;
}

it('returns in_zone for a point inside a circle stored with nested center', function () {
    $gf = makeCircle([
        'center' => ['lat' => -37.823, 'lng' => 175.500],
        'radius_m' => 500,
    ]);

    expect($this->svc->evaluate(-37.823, 175.500, $gf))
        ->toBe(GeofenceStatusService::STATUS_IN_ZONE);
});

it('returns outside_zone for a point > radius from a circle with nested center', function () {
    $gf = makeCircle([
        'center' => ['lat' => -37.823, 'lng' => 175.500],
        'radius_m' => 500,
    ]);

    // Amelia's coords from the live server — ~25 km away from her site centre.
    expect($this->svc->evaluate(-37.723363, 175.241197, $gf))
        ->toBe(GeofenceStatusService::STATUS_OUTSIDE_ZONE);
});

it('returns in_zone for a point inside a circle stored with flat lat/lng', function () {
    $gf = makeCircle([
        'lat' => -37.823,
        'lng' => 175.500,
        'radius_m' => 500,
    ]);

    expect($this->svc->evaluate(-37.823, 175.500, $gf))
        ->toBe(GeofenceStatusService::STATUS_IN_ZONE);
});

it('returns unknown when geofence is null', function () {
    expect($this->svc->evaluate(-37.0, 175.0, null))
        ->toBe(GeofenceStatusService::STATUS_UNKNOWN);
});

it('returns unknown when lat/lng is null', function () {
    $gf = makeCircle(['center' => ['lat' => 0, 'lng' => 0], 'radius_m' => 100]);
    expect($this->svc->evaluate(null, null, $gf))
        ->toBe(GeofenceStatusService::STATUS_UNKNOWN);
});

it('evaluates polygons via ray casting', function () {
    $gf = new AssetGeofence;
    $gf->type = 'polygon';
    $gf->shape = [
        'coordinates' => [
            ['lat' => 0, 'lng' => 0],
            ['lat' => 0, 'lng' => 10],
            ['lat' => 10, 'lng' => 10],
            ['lat' => 10, 'lng' => 0],
        ],
    ];
    $gf->is_active = true;

    expect($this->svc->evaluate(5, 5, $gf))->toBe(GeofenceStatusService::STATUS_IN_ZONE)
        ->and($this->svc->evaluate(20, 20, $gf))->toBe(GeofenceStatusService::STATUS_OUTSIDE_ZONE);
});
