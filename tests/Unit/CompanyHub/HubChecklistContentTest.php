<?php

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Services\HubContent;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

test('a keyed checklist object is rejected before it can become an unreadable published page', function () {
    $content = [...HubContent::defaults(), 'resource_type' => 'Checklist', 'checklist' => ['unexpected-key' => ['id' => 'first', 'title' => 'Meet your buddy']]];
    try {
        app(HubContent::class)->validate($content, new User, new HubPage(['kind' => 'knowledge']));
        $this->fail('A checklist must be an ordered list.');
    } catch (ValidationException $error) {
        expect($error->errors())->toHaveKey('checklist');
    }
});
