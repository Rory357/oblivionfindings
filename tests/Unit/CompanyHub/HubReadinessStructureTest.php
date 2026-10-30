<?php

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Services\HubContent;
use App\Domain\CompanyHub\Services\HubReadiness;
use App\Domain\Hr\Services\HrCalendarAccessService;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

test('unfinished hidden links retain a list contract before any authorisation lookup', function () {
    $calendar = Mockery::mock();
    $calendar->shouldNotReceive('visibleDepartmentsQuery');
    $this->app->instance(HrCalendarAccessService::class, $calendar);
    $content = HubContent::defaults();
    $content['sections'] = [['id' => 'unfinished', 'columns' => 1, 'blocks' => [[
        'id' => 'links', 'type' => 'links', 'title' => '', 'hidden' => true,
        'links' => ['unexpected-key' => ['id' => 'one', 'title' => '', 'url' => '']],
    ]]]];

    try {
        app(HubContent::class)->validate($content, new User, (new HubPage)->setRawAttributes(['kind' => 'page']));
        $this->fail('Keyed link objects must not pass the reader list contract.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('sections.0.blocks.0.links');
    }
});

test('campaign blocks save bounded authorised source filters while automatic empty sources stay release ready', function () {
    $departments = Mockery::mock(Builder::class);
    $departments->shouldReceive('pluck')->with('id')->andReturn(collect([1]));
    $calendar = Mockery::mock();
    $calendar->shouldReceive('visibleDepartmentsQuery')->andReturn($departments);
    $this->app->instance(HrCalendarAccessService::class, $calendar);
    $content = HubContent::defaults();
    $content['sections'] = [['id' => 'initiatives', 'columns' => 1, 'blocks' => [[
        'id' => 'progress', 'type' => 'campaign', 'title' => 'Shared progress', 'team_ids' => [1], 'limit' => 1,
        'tone' => 'soft', 'hidden' => false, 'width' => 12, 'min_height' => 120,
    ]]]];
    $validated = app(HubContent::class)->validate($content, new User, (new HubPage)->setRawAttributes(['kind' => 'page']));
    expect($validated['sections'])->toBe($content['sections'])->and(HubReadiness::issues($validated))->toBe([]);

    foreach ([['team_ids' => [2]], ['team_ids' => [1, 1]], ['campaign_id' => 99], ['body' => 'Copied progress'], ['media_id' => null], ['limit' => 13]] as $change) {
        $invalid = $content;
        $invalid['sections'][0]['blocks'][0] = [...$invalid['sections'][0]['blocks'][0], ...$change];
        expect(fn () => app(HubContent::class)->validate($invalid, new User, (new HubPage)->setRawAttributes(['kind' => 'page'])))->toThrow(ValidationException::class);
    }
    $content['sections'][0]['blocks'][0]['title'] = null;
    $unfinished = app(HubContent::class)->validate($content, new User, (new HubPage)->setRawAttributes(['kind' => 'page']));
    expect($unfinished['sections'][0]['blocks'][0]['title'])->toBe('')->and(HubReadiness::issues($unfinished)[0]['code'])->toBe('block_heading');
    $unfinished['sections'][0]['blocks'][0]['hidden'] = true;
    expect(HubReadiness::issues($unfinished))->toBe([]);
});
