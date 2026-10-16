<?php

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Services\HubContent;
use App\Domain\Hr\Services\HrCalendarAccessService;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    // Validate the actual saved-content contract without loading a database.
    $departments = Mockery::mock(Builder::class);
    $departments->shouldReceive('pluck')->with('id')->andReturn(collect());
    $calendar = Mockery::mock();
    $calendar->shouldReceive('visibleDepartmentsQuery')->with(Mockery::type(User::class))->andReturn($departments);
    $this->app->instance(HrCalendarAccessService::class, $calendar);
});

test('hero configuration accepts approved icons while retaining every legacy draft choice', function (string $icon) {
    $draft = HubContent::defaults('Existing saved draft');
    $draft['hero']['icon'] = $icon;
    $snapshot = $draft;
    $validated = app(HubContent::class)->validate($draft, new User, (new HubPage)->setRawAttributes(['kind' => 'page']));

    expect($validated['hero'])->toBe($snapshot['hero'])
        ->and($validated['sections'])->toBe($snapshot['sections'])
        ->and($draft)->toBe($snapshot);
})->with(['heart-handshake', 'newspaper', 'sparkles', 'building-2', 'flag', 'door-open', 'users', 'heart', 'book-open']);

test('hero overlay accepts the exact prototype bounds and retains existing shade values', function (int $overlay) {
    $draft = HubContent::defaults();
    $draft['hero']['overlay'] = $overlay;
    $snapshot = $draft;
    $validated = app(HubContent::class)->validate($draft, new User, (new HubPage)->setRawAttributes(['kind' => 'page']));

    expect($validated['hero']['overlay'])->toBe($overlay)
        ->and($validated['hero'])->toBe($snapshot['hero'])
        ->and($draft)->toBe($snapshot);
})->with([20, 55, 65, 90]);

test('hero configuration rejects values outside its bounded persisted contract', function (string $field, mixed $value) {
    $draft = HubContent::defaults();
    $draft['hero'][$field] = $value;

    try {
        app(HubContent::class)->validate($draft, new User, (new HubPage)->setRawAttributes(['kind' => 'page']));
        $this->fail('An unsupported hero setting must not be retained.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('hero.'.$field);
    }
})->with([
    'below minimum' => ['overlay', 19],
    'above maximum' => ['overlay', 91],
    'fractional shade' => ['overlay', 20.5],
    'arbitrary style' => ['overlay', '20px'],
    'missing shade' => ['overlay', null],
    'unknown icon' => ['icon', 'invented-icon'],
    'markup icon' => ['icon', '<script>'],
]);

test('legacy drafts with omitted optional hero configuration remain unchanged', function () {
    $draft = HubContent::defaults('Legacy draft');
    $original = $draft;
    $validated = app(HubContent::class)->validate($draft, new User, (new HubPage)->setRawAttributes(['kind' => 'page']));
    expect($validated['hero'])->toBe($original['hero'])->and($draft)->toBe($original);

    unset($draft['hero']);
    $original = $draft;
    $validated = app(HubContent::class)->validate($draft, new User, (new HubPage)->setRawAttributes(['kind' => 'page']));
    expect($validated)->not->toHaveKey('hero')->and($draft)->toBe($original);
});
