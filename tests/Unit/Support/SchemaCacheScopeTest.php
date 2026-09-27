<?php

use App\Support\SchemaCache;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;

it('only lists the current database when discovering application tables', function (): void {
    $previous = Facade::getFacadeApplication();
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication(new Container);
    Cache::swap(Mockery::mock());
    Schema::swap(Mockery::mock());
    try {
        Cache::shouldReceive('has')->andReturn(false);
        Cache::shouldReceive('rememberForever')->andReturnUsing(fn ($key, $callback) => $callback());
        Schema::shouldReceive('getCurrentSchemaName')->once()->andReturn('isolated_assets_preview');
        Schema::shouldReceive('getTableListing')->once()->with('isolated_assets_preview', false)->andReturn(['assets', 'site_rooms']);
        SchemaCache::flush();

        expect(SchemaCache::hasTable('assets'))->toBeTrue()
            ->and(SchemaCache::hasTable('site_rooms'))->toBeTrue()
            ->and(SchemaCache::hasTable('unrelated_database_table'))->toBeFalse();
    } finally {
        SchemaCache::flush();
        Mockery::close();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($previous);
    }
});
