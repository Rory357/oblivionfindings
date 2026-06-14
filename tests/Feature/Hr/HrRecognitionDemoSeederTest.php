<?php

use App\Domain\Hr\Models\HrFeedPost;
use App\Domain\Hr\Models\HrKudos;
use Database\Seeders\HrDemoSeeder;

test('the HR demo seeder populates the community feed with posts and kudos', function () {
    $this->seed(HrDemoSeeder::class);

    expect(HrFeedPost::query()->count())->toBeGreaterThan(0);
    expect(HrKudos::query()->count())->toBeGreaterThan(0);

    // Kudos also surface as kudos-type feed posts.
    expect(HrFeedPost::query()->where('post_type', 'kudos')->count())->toBeGreaterThan(0);
});

test('re-running the demo seeder does not duplicate the feed (idempotent)', function () {
    $this->seed(HrDemoSeeder::class);
    $postsAfterFirst = HrFeedPost::query()->count();
    $kudosAfterFirst = HrKudos::query()->count();

    $this->seed(HrDemoSeeder::class);

    expect(HrFeedPost::query()->count())->toBe($postsAfterFirst);
    expect(HrKudos::query()->count())->toBe($kudosAfterFirst);
});
