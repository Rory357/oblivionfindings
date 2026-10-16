<?php

use App\Domain\CompanyHub\Services\HubFeed;
use App\Domain\CompanyHub\Services\HubPublishing;
use App\Domain\CompanyHub\Services\HubRichText;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Notification::fake();
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->editor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->editor->roles()->attach(Role::where('name', 'hr')->sole());
    $this->site = ensureCanonicalHrStaffProfile($this->editor);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->sole());
    ensureCanonicalHrStaffProfile($this->reader, $this->site);
    $this->publisher = app(HubPublishing::class);
    $this->guide = $this->publisher->create($this->editor, 'knowledge', 'Our formatted guide', 'blank');
    $this->document = ['version' => 1, 'blocks' => [
        ['type' => 'heading', 'level' => 2, 'runs' => [['text' => 'Orientation instructions']]],
        ['type' => 'paragraph', 'runs' => [['text' => 'Meet the orientation buddy.', 'bold' => true]]],
        ['type' => 'bullet_list', 'items' => [[['text' => 'Find the shared tools']], [['text' => 'Read the guide', 'href' => '/company-hub?view=resources']]]],
        ['type' => 'table', 'header' => true, 'rows' => [
            [[['text' => 'When']], [['text' => 'Action']]],
            [[['text' => 'Morning']], [['text' => 'Welcome conversation', 'italic' => true]]],
        ]],
    ]];
    $this->content = [...$this->guide->draft, 'sections' => [['id' => 'guide', 'columns' => 1, 'blocks' => [[
        'id' => 'instructions', 'type' => 'text', 'title' => 'Useful instructions', 'body' => 'Untrusted misleading fallback', 'rich_text' => $this->document,
    ]]]]];
});

test('formatted guides persist semantic content derive searchable plain text and keep saved work separate from publication', function () {
    $this->guide = $this->publisher->save($this->editor, $this->guide, $this->content, 1);
    expect($this->guide->draft['sections'][0]['blocks'][0]['rich_text'])->toEqual($this->document)
        ->and($this->guide->draft['sections'][0]['blocks'][0]['body'])->toBe(HubRichText::plainText($this->document));
    $release = $this->publisher->publish($this->editor, $this->guide, 2, null, null);
    $url = '/company-hub/read/'.$this->guide->slug;
    $this->actingAs($this->reader)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('selectedPage.content.sections.0.blocks.0.rich_text', fn ($document) => collect($document)->toArray() == $this->document)
        ->where('selectedPage.content.sections.0.blocks.0.rich_text.version', 1)
        ->where('selectedPage.content.sections.0.blocks.0.body', HubRichText::plainText($this->document)));
    expect(collect(app(HubFeed::class)->forUser($this->reader, 'orientation buddy')['pages'])->pluck('id')->all())->toContain($this->guide->id)
        ->and(app(HubFeed::class)->forUser($this->reader, 'misleading fallback')['pages'])->toBe([]);
    $draft = $this->guide->draft;
    $draft['sections'][0]['blocks'][0]['rich_text']['blocks'][1]['runs'][0]['text'] = 'A revised draft-only instruction.';
    $this->guide = $this->publisher->save($this->editor, $this->guide->fresh(), $draft, 2);
    $this->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('selectedPage.content.sections.0.blocks.0.rich_text.blocks.1.runs.0.text', 'Meet the orientation buddy.'));
    expect(app(HubFeed::class)->forUser($this->reader, 'revised draft-only')['pages'])->toBe([])
        ->and($release->fresh()->content['sections'][0]['blocks'][0]['rich_text'])->toEqual($this->document);
    $this->putJson('/company-hub/manage/'.$this->guide->id, ['version' => 3, 'content' => $draft])->assertNotFound();
    expect($this->guide->fresh()->draft_version)->toBe(3);
});

test('unsafe or misplaced rich text is rejected even in hidden drafts without altering saved content', function () {
    $original = $this->guide->draft;
    foreach ([
        ['type' => 'paragraph', 'runs' => [['text' => 'Open', 'href' => 'javascript:alert(1)']]],
        ['type' => 'paragraph', 'runs' => [['text' => 'Open', 'onclick' => 'alert(1)']]],
        ['type' => 'html', 'html' => '<script>alert(1)</script>'],
    ] as $node) {
        $content = $this->content;
        $content['sections'][0]['hidden'] = true;
        $content['sections'][0]['blocks'][0]['rich_text']['blocks'] = [$node];
        $this->actingAs($this->editor)->putJson('/company-hub/manage/'.$this->guide->id, ['version' => 1, 'content' => $content])
            ->assertUnprocessable()->assertJsonValidationErrors('sections.0.blocks.0.rich_text');
    }
    foreach (['notice', 'file', 'featured'] as $type) {
        $content = $this->content;
        $content['sections'][0]['blocks'][0]['type'] = $type;
        $this->putJson('/company-hub/manage/'.$this->guide->id, ['version' => 1, 'content' => $content])
            ->assertUnprocessable()->assertJsonValidationErrors('sections.0.blocks.0.rich_text');
    }
    $content = $this->content;
    $content['sections'][0]['blocks'][0]['rich_text'] = '<p>Raw HTML is not a semantic document.</p>';
    $this->putJson('/company-hub/manage/'.$this->guide->id, ['version' => 1, 'content' => $content])
        ->assertUnprocessable()->assertJsonValidationErrors('sections.0.blocks.0.rich_text');
    expect($this->guide->fresh()->draft)->toEqual($original)->and($this->guide->fresh()->draft_version)->toBe(1);
});

test('empty formatting remains safely saveable but cannot publish and clearing it preserves legacy prose', function () {
    $content = $this->content;
    $content['sections'][0]['blocks'][0]['rich_text']['blocks'] = [['type' => 'paragraph', 'runs' => []]];
    $this->guide = $this->publisher->save($this->editor, $this->guide, $content, 1);
    expect($this->guide->draft['sections'][0]['blocks'][0]['body'])->toBe('');
    $this->actingAs($this->editor)->postJson('/company-hub/manage/'.$this->guide->id.'/publish', ['version' => 2])
        ->assertUnprocessable()->assertJsonValidationErrors('sections.0.blocks.0.body');
    expect($this->guide->revisions()->count())->toBe(0);
    $content = $this->guide->draft;
    $content['sections'][0]['blocks'][0]['rich_text'] = null;
    $content['sections'][0]['blocks'][0]['body'] = "Plain first step.\n\nPlain second step.";
    $this->guide = $this->publisher->save($this->editor, $this->guide, $content, 2);
    expect($this->guide->draft['sections'][0]['blocks'][0]['body'])->toBe($content['sections'][0]['blocks'][0]['body']);
    $this->publisher->publish($this->editor, $this->guide, 3, null, null);
    $this->actingAs($this->reader)->get('/company-hub/read/'.$this->guide->slug)->assertInertia(fn (Assert $page) => $page
        ->where('selectedPage.content.sections.0.blocks.0.rich_text', null)
        ->where('selectedPage.content.sections.0.blocks.0.body', $content['sections'][0]['blocks'][0]['body']));
});
