<?php

use App\Services\Catering\UnitConverter;

it('converts within the same dimension', function () {
    $u = new UnitConverter();

    expect($u->convert(1, 'kg', 'g'))->toBe(1000.0);
    expect($u->convert(500, 'g', 'kg'))->toBe(0.5);
    expect($u->convert(1, 'l', 'ml'))->toBe(1000.0);
    expect($u->convert(2500, 'ml', 'l'))->toBe(2.5);
});

it('returns the same value for identical units', function () {
    $u = new UnitConverter();
    expect($u->convert(7, 'each', 'each'))->toBe(7.0);
    expect($u->convert(3.5, 'kg', 'kg'))->toBe(3.5);
});

it('bridges count to mass via pack size', function () {
    $u = new UnitConverter();
    // 1 "each" of milk (pack: 2L) → 2000 ml
    expect($u->convert(1, 'each', 'ml', 2, 'L'))->toBe(2000.0);
    // 3 "each" of butter (pack: 500g) → 1500 g
    expect($u->convert(3, 'each', 'g', 500, 'g'))->toBe(1500.0);
});

it('bridges mass back to count via pack size', function () {
    $u = new UnitConverter();
    // 1000 g of butter where pack is 500g → 2 packs
    expect($u->convert(1000, 'g', 'each', 500, 'g'))->toBe(2.0);
});

it('returns null when units are incompatible and no pack bridge', function () {
    $u = new UnitConverter();
    expect($u->convert(1, 'kg', 'ml'))->toBeNull();
});
