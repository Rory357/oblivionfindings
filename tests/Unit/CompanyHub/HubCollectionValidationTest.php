<?php

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Services\HubCollections;
use App\Domain\CompanyHub\Services\HubContent;
use App\Domain\Hr\Services\HrCalendarAccessService;
use App\Http\Controllers\CompanyHub\HubCollectionController;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

uses(TestCase::class);

test('absent and null collection references keep legacy drafts independent of registry availability', function () {
    $collections = Mockery::mock(HubCollections::class);
    $collections->shouldNotReceive('ready');
    $collections->shouldNotReceive('validateReference');
    $this->app->instance(HubCollections::class, $collections);
    $departments = Mockery::mock(Builder::class);
    $departments->shouldReceive('pluck')->with('id')->andReturn(collect());
    $calendar = Mockery::mock();
    $calendar->shouldReceive('visibleDepartmentsQuery')->andReturn($departments);
    $this->app->instance(HrCalendarAccessService::class, $calendar);
    $content = [...HubContent::defaults(), 'sections' => [], 'collection' => 'Existing label'];
    $page = (new HubPage)->setRawAttributes(['kind' => 'knowledge']);
    expect(app(HubContent::class)->validate($content, new User, $page))->not->toHaveKey('collection_id');
    expect(app(HubContent::class)->validate([...$content, 'collection_id' => null], new User, $page)['collection_id'])->toBeNull();
});

test('collection identifiers reject malformed values before any authorised registry lookup', function ($id) {
    $collections = Mockery::mock(HubCollections::class);
    $collections->shouldNotReceive('validateReference');
    $this->app->instance(HubCollections::class, $collections);
    expect(fn () => app(HubContent::class)->validate([...HubContent::defaults(), 'sections' => [], 'collection_id' => $id], new User))->toThrow(ValidationException::class);
})->with(['zero' => [0], 'negative' => [-1], 'overflow' => [2147483648], 'text' => ['not-an-id'], 'array' => [[1]], 'fraction' => [1.5]]);

test('collection route strings reject unbounded or malformed identifiers before coercion validation or lookup', function ($id) {
    $collections = Mockery::mock(HubCollections::class);
    $collections->shouldNotReceive('rename');
    expect(fn () => (new HubCollectionController)->update(new Request, $id, $collections))->toThrow(NotFoundHttpException::class);
})->with(['zero' => ['0'], 'negative' => ['-1'], 'overflow' => ['2147483648'], 'huge' => [str_repeat('9', 100)], 'text' => ['not-an-id'], 'fraction' => ['1.5'], 'leading-zero' => ['01']]);
