<?php

use App\Domain\CompanyHub\Services\HubKnowledge;
use App\Domain\CompanyHub\Services\HubReaderMetadata;

test('Process and Checklist support optional personal steps without broadening other resource types', function () {
    foreach (['Process', 'Checklist'] as $type) {
        expect(HubKnowledge::supportsPersonalSteps(['resource_type' => $type]))->toBeTrue();
    }
    foreach (['Guide', 'Policy', 'Form', 'Information', 'News', null] as $type) {
        expect(HubKnowledge::supportsPersonalSteps(['resource_type' => $type, 'checklist' => [['id' => 'old', 'title' => 'Retained instruction']]]))->toBeFalse();
    }
});

test('search includes only eligible personal instructions alongside visible authored prose', function (string $type, bool $eligible) {
    $body = HubReaderMetadata::body(['resource_type' => $type, 'checklist' => [['id' => 'one', 'title' => 'Instructiononlyneedle']],
        'sections' => [['blocks' => [['type' => 'text', 'body' => 'Visible prose'], ['type' => 'text', 'body' => 'Hiddenbodyneedle', 'hidden' => true]]]]]);
    expect($body)->toContain('Visible prose')->not->toContain('Hiddenbodyneedle');
    expect(str_contains($body, 'Instructiononlyneedle'))->toBe($eligible);
    expect(trim(HubReaderMetadata::body(['resource_type' => $type, 'sections' => []])))->toBe('');
})->with([['Process', true], ['Checklist', true], ['Guide', false]]);
