<?php

use App\Domain\CompanyHub\Services\HubReadiness;

test('resource governance warnings describe missing current contact and review date without changing release readiness', function () {
    $content = ['title' => 'Work guidance', 'sections' => [['id' => 'guide', 'blocks' => [
        ['id' => 'file', 'type' => 'file', 'title' => 'Guide', 'media_id' => 7],
    ]]]];
    $snapshot = $content;
    expect(array_column(HubReadiness::governanceWarnings($content, false, '2026-10-16'), 'code'))->toBe(['owner_unavailable', 'review_missing'])
        ->and(HubReadiness::issues($content))->toBe([])->and($content)->toBe($snapshot);
});

test('resource governance warnings distinguish overdue review dates from due today and future dates', function (?string $date, array $codes) {
    expect(array_column(HubReadiness::governanceWarnings(['review_on' => $date], true, '2026-10-16'), 'code'))->toBe($codes);
})->with([[null, ['review_missing']], ['2026-10-15', ['review_overdue']], ['2026-10-16', []], ['2026-10-17', []]]);
