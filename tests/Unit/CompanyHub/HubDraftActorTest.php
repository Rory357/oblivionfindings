<?php

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Services\HubContent;
use App\Domain\CompanyHub\Services\HubDraft;
use App\Domain\CompanyHub\Services\HubTemplates;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

test('draft lifecycle fails closed when the current actor no longer exists before reading or writing', function (string $operation) {
    $actor = Mockery::mock(User::class);
    $actor->shouldReceive('fresh')->once()->andReturnNull();
    $page = Mockery::mock(HubPage::class);
    $staff = Mockery::mock(HrCurrentStaffService::class);
    $service = new HubDraft($staff);
    try {
        expect(fn () => match ($operation) {
            'read' => $service->read($actor, $page),
            'discard' => $service->discard($actor, $page, ['actor_id' => 1, 'version' => 1]),
            'restore' => $service->restore($actor, $page, ['actor_id' => 1, 'version' => 1, 'discard_id' => 1]),
        })->toThrow(NotFoundHttpException::class);
    } finally {
        Mockery::close();
    }
})->with(['read', 'discard', 'restore']);

test('read only starter layouts use canonical page starters and Home defaults without author or record bindings', function () {
    $page = HubDraft::starterLayouts('news');
    expect(array_column($page, 'key'))->toBe(HubTemplates::KEYS);
    foreach ($page as $starter) {
        expect($starter['sections'])->toEqual(HubTemplates::sections($starter['key']))
            ->and(array_keys($starter))->toBe(['key', 'label', 'sections']);
    }
    $home = HubDraft::starterLayouts('home');
    expect($home[0]['key'])->toBe('home')->and($home[0]['sections'])->toEqual(HubContent::defaults()['sections'])
        ->and(array_column(array_slice($home, 1), 'key'))->toBe(HubTemplates::KEYS);
});
