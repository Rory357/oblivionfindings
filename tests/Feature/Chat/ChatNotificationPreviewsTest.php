<?php

use App\Jobs\DeliverChatMessageNotifications;
use App\Models\OpsConversation;
use App\Models\OpsMessage;
use App\Models\Site;
use App\Models\User;
use App\Services\Chat\ChatMessageDelivery;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('projects only current authorised in-app Chat previews without retaining their text or marking them read', function () {
    config(['chat.shared_experience' => true]);
    Bus::fake([DeliverChatMessageNotifications::class]);
    $site = Site::factory()->create();
    $sender = User::factory()->create(['name' => 'Rohan Example', 'role' => 'staff', 'approved_at' => now()]);
    $reader = User::factory()->create(['role' => 'staff', 'approved_at' => now()]);
    $other = User::factory()->create(['role' => 'staff', 'approved_at' => now()]);
    foreach ([$sender, $reader, $other] as $person) {
        ensureCanonicalHrStaffProfile($person, $site);
    }
    $conversation = OpsConversation::create(['conversation_type' => 'direct']);
    foreach ([$sender, $reader] as $person) {
        $conversation->participants()->create(['user_id' => $person->id, 'role' => 'member']);
    }
    $this->actingAs($sender)->withHeader('X-Chat-Actor', (string) $sender->id)
        ->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), 'content' => 'Initial private text'])->assertCreated();
    expect(app(ChatMessageDelivery::class)->recover())->toBe(1);
    $notice = $reader->notifications()->sole();
    expect(json_encode($notice->data))->not->toContain('Initial private text');
    $url = route('chat.notifications.previews', ['ids' => [$notice->id]]);
    $this->actingAs($reader)->withHeader('X-Chat-Actor', (string) $reader->id)->getJson($url)
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('previews.'.$notice->id, 'Rohan Example: Initial private text');
    expect($notice->fresh()->read_at)->toBeNull();
    $message = OpsMessage::where('conversation_id', $conversation->id)->sole();
    $message->update(['content' => 'Corrected private text']);
    $this->getJson($url)->assertOk()->assertJsonPath('previews.'.$notice->id, 'Rohan Example: Corrected private text')->assertDontSee('Initial private text');

    $version = $this->getJson(route('chat.preferences.show'))->assertOk()->json('version');
    $version = $this->patchJson(route('chat.preferences.update'), ['version' => $version, 'values' => ['show_previews' => false]])->assertOk()->json('version');
    $this->getJson($url)->assertOk()->assertJsonPath('previews.'.$notice->id, null)->assertDontSee('Corrected private text');
    $this->patchJson(route('chat.preferences.update'), ['version' => $version, 'values' => ['show_previews' => true]])->assertOk();
    $this->actingAs($other)->withHeader('X-Chat-Actor', (string) $reader->id)->getJson($url)->assertForbidden();
    $this->withHeader('X-Chat-Actor', (string) $other->id)->getJson($url)->assertOk()->assertJsonPath('previews.'.$notice->id, null);
    $this->actingAs($reader)->withHeader('X-Chat-Actor', (string) $reader->id);
    $conversation->participants()->where('user_id', $reader->id)->delete();
    $this->getJson($url)->assertOk()->assertJsonPath('previews.'.$notice->id, null);
    expect(json_encode($notice->fresh()->data))->not->toContain('Corrected private text');
    expect(DB::table('notifications')->count())->toBe(1);
});
