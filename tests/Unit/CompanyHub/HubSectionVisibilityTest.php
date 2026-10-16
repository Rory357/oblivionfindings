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

beforeEach(function () {
    $departments = Mockery::mock(Builder::class);
    $departments->shouldReceive('pluck')->with('id')->andReturn(collect([]));
    $calendar = Mockery::mock();
    $calendar->shouldReceive('visibleDepartmentsQuery')->andReturn($departments);
    $this->app->instance(HrCalendarAccessService::class, $calendar);
    $this->content = [...HubContent::defaults(), 'sections' => [['id' => 'work', 'columns' => 1, 'blocks' => [
        ['id' => 'unfinished', 'type' => 'text', 'title' => '', 'body' => '', 'hidden' => false],
        ['id' => 'individually-hidden', 'type' => 'text', 'title' => 'Retained note', 'body' => 'Private authored note', 'hidden' => true],
    ]]]];
    $this->validateSection = fn ($content) => app(HubContent::class)->validate($content, new User, new HubPage(['kind' => 'page']));
});

test('section visibility normalizes bounded boolean values and retains independent child flags without changing legacy omission', function () {
    foreach ([true, false, 0, 1, '0', '1'] as $value) {
        $input = $this->content;
        $input['sections'][0]['hidden'] = $value;
        $validated = ($this->validateSection)($input);
        expect($validated['sections'][0]['hidden'])->toBe((bool) $value)
            ->and($validated['sections'][0]['blocks'])->toBe($this->content['sections'][0]['blocks']);
        if ((bool) $value) {
            expect(HubReadiness::issues($validated))->toBe([])->and(HubContent::visibleBlocks($validated)->all())->toBe([]);
        } else {
            expect(array_column(HubReadiness::issues($validated), 'code'))->toBe(['block_heading', 'block_body']);
        }
    }
    expect(($this->validateSection)($this->content)['sections'][0])->not->toHaveKey('hidden');
});

test('hidden sections reject invalid booleans unsafe structure URLs and unauthorized team filters', function () {
    foreach ([null, 2, -1, 'true', 'false', 'yes', [], ['nested' => true]] as $value) {
        $input = $this->content;
        $input['sections'][0]['hidden'] = $value;
        try {
            ($this->validateSection)($input);
            $this->fail('Visibility must not accept arbitrary truthy values.');
        } catch (ValidationException $error) {
            expect($error->errors())->toHaveKey('sections.0.hidden');
        }
    }
    $base = $this->content;
    $base['sections'][0]['hidden'] = true;
    foreach ([
        ['id' => 'unsafe', 'type' => 'links', 'title' => '', 'links' => [['id' => 'one', 'title' => '', 'url' => 'javascript:alert(1)']]],
        ['id' => 'unsafe', 'type' => 'button', 'title' => '', 'action_label' => '', 'url' => '//outside.example/unsafe'],
        ['id' => 'unsafe', 'type' => 'video', 'title' => '', 'url' => 'https://outside.example/watch'],
        ['id' => 'unsafe', 'type' => 'image', 'title' => '', 'media_id' => ['forged' => 1]],
        ['id' => 'unsafe', 'type' => 'people', 'title' => '', 'team_ids' => [999]],
        ['id' => 'unsafe', 'type' => 'text', 'title' => '', 'arbitrary_style' => 'position:fixed'],
    ] as $unsafe) {
        $input = $base;
        $input['sections'][0]['blocks'] = [$unsafe];
        expect(fn () => ($this->validateSection)($input))->toThrow(ValidationException::class);
    }
    $base['sections'][0]['columns'] = 4;
    expect(fn () => ($this->validateSection)($base))->toThrow(ValidationException::class);
});

test('hidden section completeness skips only its children while visible issue identities and page hero gates stay intact', function () {
    $content = ['title' => '', 'hero' => ['style' => 'image'], 'sections' => [
        ['id' => 'hidden', 'hidden' => true, 'blocks' => [['id' => 'unfinished-image', 'type' => 'image', 'title' => '']]],
        ['id' => 'visible', 'hidden' => false, 'blocks' => [['id' => 'unfinished-text', 'type' => 'text', 'title' => 'Visible work', 'body' => '']]],
    ]];
    $snapshot = $content;
    $issues = HubReadiness::issues($content);
    expect(array_column($issues, 'code'))->toBe(['page_title', 'hero_image', 'block_body'])
        ->and($issues[2]['field'])->toBe('sections.1.blocks.0.body')->and($issues[2]['section_id'])->toBe('visible')
        ->and($issues[2]['block_id'])->toBe('unfinished-text')->and($content)->toBe($snapshot);
});

test('reader projection excludes entire hidden sections and individual hidden children without altering the full authored snapshot', function () {
    $content = ['sections' => [
        ['id' => 'hidden-section', 'hidden' => true, 'blocks' => [['id' => 'section-child', 'type' => 'file', 'hidden' => false, 'media_id' => 123]]],
        ['id' => 'visible-section', 'blocks' => [
            ['id' => 'visible-child', 'type' => 'people', 'team_ids' => [1, 2]],
            ['id' => 'hidden-child', 'type' => 'text', 'hidden' => true, 'body' => 'Private retained text'],
        ]],
    ]];
    $snapshot = $content;
    $reader = HubContent::readerContent($content, [1]);
    expect(array_column($reader['sections'], 'id'))->toBe(['visible-section'])
        ->and($reader['sections'][0]['blocks'])->toBe([['id' => 'visible-child', 'type' => 'people', 'team_ids' => [1]]])
        ->and(HubContent::visibleBlocks($content)->pluck('id')->all())->toBe(['visible-child'])->and($content)->toBe($snapshot);
});
