<?php

use App\Domain\CompanyHub\Services\HubShowcase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\Facade;

beforeEach(function () {
    $this->showcasePreviousContainer = Container::getInstance();
    $this->showcasePreviousFacadeApplication = Facade::getFacadeApplication();
    $this->showcasePreviousClock = Carbon::getTestNow();
    $container = new Container;
    $container->instance('config', new Repository(['app' => ['worker_timezone' => 'Pacific/Auckland']]));
    $container->instance('date', new DateFactory);
    $container->bind('db', fn () => throw new LogicException('Showcase fixtures must never access a database.'));
    Container::setInstance($container);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
    Carbon::setTestNow(Carbon::parse('2026-10-15 10:00:00', 'Pacific/Auckland'));
});

afterEach(function () {
    Carbon::setTestNow($this->showcasePreviousClock);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($this->showcasePreviousFacadeApplication);
    Container::setInstance($this->showcasePreviousContainer);
});

test('showcase fixtures remain fictional and read only without a database application', function () {
    $data = HubShowcase::data();

    expect($data['demo'])->toBeTrue()
        ->and($data['homeId'])->toBeNull()
        ->and($data['selectedPage'])->toBeNull()
        ->and($data['participation'])->toBeNull()
        ->and($data['campaign'])->toBeNull()
        ->and($data['department'])->toBeNull()
        ->and($data['editablePages'])->toBe([])
        ->and($data['ideaActorId'])->toBeNull()
        ->and($data['capabilities']['write'])->toBeFalse()
        ->and($data['capabilities']['calendar'])->toBeFalse()
        ->and($data['canViewOrg'])->toBeFalse();

    foreach (['pages', 'people', 'ideas'] as $key) {
        $ids = array_column($data[$key], 'id');
        expect(count(array_unique($ids)))->toBe(count($ids));
        foreach ($ids as $id) {
            expect($id)->toBeLessThan(0);
        }
    }
    foreach ($data['people'] as $person) {
        expect($person['user_id'])->toBeLessThan(0)
            ->and($person['work_email'])->toBeNull()
            ->and($person['work_phone'])->toBeNull()
            ->and($person['staff_record_url'])->toBeNull()
            ->and($person['can_edit_profile'])->toBeFalse();
    }
    expect(HubShowcase::data('not-a-fixture')['selectedPage'])->toBeNull();
});

test('future waitlist and past gallery fixtures have consistent dates counts and safe local photography', function () {
    $data = HubShowcase::data();
    $upcoming = $data['events'][0];
    $past = $data['events'][3];
    $registration = $upcoming['extendedProps']['registration'];

    expect(count($data['events']))->toBe(4)
        ->and(Carbon::parse($upcoming['start'])->isFuture())->toBeTrue()
        ->and(Carbon::parse($past['end'])->isPast())->toBeTrue()
        ->and($upcoming['editable'])->toBeFalse()
        ->and($registration['date'])->toBe('2026-10-18')
        ->and($registration['capacity'])->toBe($registration['going'])
        ->and($registration['mine'])->toBe('waiting')
        ->and($registration['waiting'])->toBe(2)
        ->and($registration['queue_position'])->toBe(2)
        ->and($registration['can_configure'])->toBeFalse()
        ->and($registration['personal_reminder']['can_enable'])->toBeFalse()
        ->and($past['extendedProps']['hubContent']['past'])->toBeTrue()
        ->and($past['extendedProps']['hubContent']['recap'])->not->toBe('')
        ->and($past['extendedProps']['hubContent']['story']['url'])->toStartWith('/company-hub/showcase?story=');

    $photos = $past['extendedProps']['hubContent']['gallery'];
    expect(count($photos))->toBe(3)
        ->and(count(array_unique(array_column($photos, 'album'))))->toBe(2);
    foreach ($photos as $photo) {
        expect($photo['id'])->toBeLessThan(0)
            ->and($photo['url'])->toStartWith('/images/company-hub/')
            ->and(is_file(dirname(__DIR__, 3).'/public'.$photo['url']))->toBeTrue()
            ->and($photo['alt'])->not->toBe('')
            ->and($photo['caption'])->not->toBe('')
            ->and($photo['shared_by']['profile_url'])->toBeNull();
    }
    foreach ($data['events'] as $event) {
        expect($event['extendedProps']['eventId'])->toBeLessThan(0)
            ->and($event['extendedProps']['editVersion'])->toBe(1)
            ->and($event['extendedProps']['cancellation']['can_cancel'])->toBeFalse();
    }
});

test('sample attendance is read only and agrees with the selected past event totals', function () {
    $data = HubShowcase::data();
    $event = $data['events'][3];
    $roster = $data['showcaseEventRosters'][$event['id']];
    $registration = $event['extendedProps']['registration'];

    expect($roster['actor_id'])->toBe(0)
        ->and($roster['event_id'])->toBe($event['extendedProps']['eventId'])
        ->and($roster['edit_version'])->toBe($event['extendedProps']['editVersion'])
        ->and($roster['registration_version'])->toBe($registration['version'])
        ->and($roster['date'])->toBe($registration['date'])
        ->and($roster['can_export'])->toBeFalse()
        ->and($roster['can_record'])->toBeFalse()
        ->and(count($roster['registrations']))->toBe($registration['going'])
        ->and(count(array_filter($roster['registrations'], fn ($row) => $row['attendance'] === 'attended')))->toBe($registration['attended']);
    foreach ($roster['registrations'] as $row) {
        expect($row['id'])->toBeLessThan(0)
            ->and($row['can_offer_place'])->toBeFalse();
    }
});

test('sample idea has a dated progress history current owner and non editable discussion', function () {
    $data = HubShowcase::data();
    $idea = $data['ideas'][0];

    expect($data['ideaProgressReady'])->toBeTrue()
        ->and($idea['can_remove'])->toBeFalse()
        ->and($idea['can_update'])->toBeFalse()
        ->and($idea['supported'])->toBeFalse()
        ->and($idea['owner']['id'])->toBe($data['ideaOwners'][0]['id'])
        ->and($idea['owner']['url'])->toBe('/company-hub/showcase?story=sample-team')
        ->and($idea['team'])->toBe($data['ideaTeams'][0])
        ->and(array_column($idea['updates'], 'status'))->toBe(['reviewing', 'in_progress'])
        ->and($idea['status'])->toBe($idea['updates'][1]['status'])
        ->and($idea['status_note'])->toBe($idea['updates'][1]['note'])
        ->and(Carbon::parse($idea['updates'][0]['created_at'])->greaterThan(Carbon::parse($idea['created_at'])))->toBeTrue()
        ->and(Carbon::parse($idea['updates'][1]['created_at'])->greaterThan(Carbon::parse($idea['updates'][0]['created_at'])))->toBeTrue()
        ->and($idea['comments'][0]['can_remove'])->toBeFalse();
});

test('sample polls project an unvoted ballot and internally consistent closed results for display only', function () {
    $data = HubShowcase::data('sample-polls', 37);
    $page = $data['selectedPage'];
    $participation = $data['participation'];
    $blocks = $page['content']['sections'][0]['blocks'];
    $open = $participation['polls'][$blocks[0]['id']];
    $closed = $participation['polls'][$blocks[1]['id']];

    expect($data['demo'])->toBeTrue()
        ->and($page['id'])->toBeLessThan(0)
        ->and($page['can_edit'])->toBeFalse()
        ->and($participation['actor_id'])->toBe(37)
        ->and($participation['page_id'])->toBe($page['id'])
        ->and($participation['revision_id'])->toBe($page['revision_id'])
        ->and($participation['can_moderate'])->toBeFalse()
        ->and($open['mine'])->toBeNull()
        ->and($open['counts'])->toBeNull()
        ->and($open['results_visible'])->toBeFalse()
        ->and($open['closed'])->toBeFalse()
        ->and($closed['closed'])->toBeTrue()
        ->and($closed['results_visible'])->toBeTrue()
        ->and(count($closed['counts']))->toBe(count($blocks[1]['options']))
        ->and(array_sum($closed['counts']))->toBe($closed['total'])
        ->and($closed['mine'])->toBeLessThan(count($blocks[1]['options']));
    foreach ($participation['polls'] as $poll) {
        expect($poll['can_manage'])->toBeFalse()
            ->and($poll['timezone'])->toBe('Pacific/Auckland');
    }

    $otherViewer = HubShowcase::data('sample-polls', 91);
    $otherViewer['participation']['actor_id'] = 37;
    expect($otherViewer)->toBe($data)
        ->and(HubShowcase::data('sample-polls')['participation']['actor_id'])->toBe(0)
        ->and(HubShowcase::data('sample-campaign', 37)['participation']['actor_id'])->toBe(0)
        ->and(HubShowcase::data(null, 37))->toBe(HubShowcase::data());
});

test('sample campaign reader curation and personal activity projections share exact fictional references', function () {
    $data = HubShowcase::data('sample-campaign');
    $page = $data['selectedPage'];
    $content = $page['content']['campaign'];
    $campaign = $data['campaign'];
    $activities = $data['participation']['campaign'];

    expect($page['kind'])->toBe('campaign')
        ->and($content['milestones'])->toBe($campaign['milestones'])
        ->and(array_column($campaign['milestones'], 'status'))->toBe(['done', 'active', 'next'])
        ->and($campaign['total'])->toBe(count($campaign['milestones']))
        ->and($campaign['done'])->toBe(1)
        ->and($campaign['ends_on'])->toBe($content['ends_on'])
        ->and($content['story_ids'])->toBe(array_column($campaign['stories'], 'id'))
        ->and($content['resource_ids'])->toBe(array_column($campaign['resources'], 'id'))
        ->and($content['event_keys'])->toBe(array_column($campaign['events'], 'id'))
        ->and(array_column($activities, 'id'))->toBe(array_column($content['steps'], 'id'))
        ->and(array_column($activities, 'done'))->toBe([true, false])
        ->and($data['department'])->toBeNull();
    foreach ($activities as $activity) {
        expect($activity['url'])->toStartWith('/company-hub/showcase?');
    }
    foreach (array_merge($campaign['stories'], $campaign['resources']) as $related) {
        expect($related['id'])->toBeLessThan(0)
            ->and(in_array($related['id'], array_column($data['pages'], 'id'), true))->toBeTrue();
    }
});

test('sample team tabs share fictional team people stories and guides without creation privileges', function () {
    $data = HubShowcase::data('sample-team');
    $space = $data['department'];
    $page = $data['selectedPage'];

    expect($page['kind'])->toBe('department')
        ->and($page['content']['team_ids'])->toBe(array_column($space['teams'], 'id'))
        ->and($space['page_id'])->toBe($page['id'])
        ->and($space['revision_id'])->toBe($page['revision_id'])
        ->and($space['can_create_update'])->toBeFalse()
        ->and(count($space['updates']))->toBe(2)
        ->and(count($space['resources']))->toBe(2)
        ->and($space['people'])->toBe($data['people'])
        ->and($space['owner']['id'])->toBe($space['people'][0]['id'])
        ->and($data['campaign'])->toBeNull()
        ->and($data['participation'])->toBeNull();
    foreach (array_merge($space['updates'], $space['resources']) as $related) {
        expect($related['team_ids'])->toContain($space['teams'][0]['id']);
    }
});
