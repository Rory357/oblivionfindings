<?php

use App\Enums\ClientGender;
use App\Models\Client;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * To-do #12: Gender is one shared dropdown (Female, Male, Another gender with
 * the person's own words, Prefer not to say), and typed answers from before
 * the dropdown are mapped without losing or guessing anything.
 */

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
    $this->admin->roles()->attach(Role::where('name', 'admin')->first());
    $this->site = Site::factory()->create();
    $this->serviceContext = ServiceContext::factory()->create();
});

function clientGenderPayload(Site $site, ServiceContext $context, array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Aroha',
        'last_name' => 'Test',
        'date_of_birth' => '1990-01-15',
        'status' => 'active',
        'site_id' => $site->id,
        'service_context_id' => $context->id,
    ], $overrides);
}

function clientGenderMigration(): object
{
    return require database_path('migrations/2026_11_04_100000_make_client_gender_a_choice.php');
}

/** Write a typed answer straight to the row, as the old free-text box did. */
function clientWithTypedGender(Site $site, ?string $typed): Client
{
    $client = Client::factory()->create(['site_id' => $site->id]);
    DB::table('clients')->where('id', $client->id)->update([
        'gender' => $typed,
        'gender_description' => null,
        'gender_recorded_as' => null,
    ]);

    return $client;
}

it('saves each option on the list', function (string $gender) {
    $this->actingAs($this->admin)
        ->post('/clients', clientGenderPayload($this->site, $this->serviceContext, ['gender' => $gender, 'last_name' => 'Option '.$gender]))
        ->assertSessionHasNoErrors();

    $client = Client::query()->where('last_name', 'Option '.$gender)->firstOrFail();
    expect($client->gender)->toBe($gender)
        ->and($client->gender_description)->toBeNull()
        ->and($client->gender_recorded_as)->toBeNull();
})->with(['female', 'male', 'prefer_not_to_say']);

it('saves Another gender with the person\'s own words', function () {
    $this->actingAs($this->admin)
        ->post('/clients', clientGenderPayload($this->site, $this->serviceContext, [
            'gender' => 'another_gender',
            'gender_description' => '  Takatāpui ',
        ]))
        ->assertSessionHasNoErrors();

    $client = Client::query()->where('last_name', 'Test')->firstOrFail();
    expect($client->gender)->toBe('another_gender')
        ->and($client->gender_description)->toBe('Takatāpui');
});

it('keeps gender optional', function () {
    $this->actingAs($this->admin)
        ->post('/clients', clientGenderPayload($this->site, $this->serviceContext, ['gender' => '']))
        ->assertSessionHasNoErrors();

    expect(Client::query()->where('last_name', 'Test')->value('gender'))->toBeNull();
});

it('refuses anything not on the list', function (string $typed) {
    $this->actingAs($this->admin)
        ->post('/clients', clientGenderPayload($this->site, $this->serviceContext, ['gender' => $typed]))
        ->assertSessionHasErrors(['gender' => 'Choose a gender from the list.']);

    expect(Client::query()->where('last_name', 'Test')->exists())->toBeFalse();
})->with(['Woman', 'Female', 'non_binary', 'other', 'f']);

it('needs a description only for Another gender', function () {
    $this->actingAs($this->admin)
        ->post('/clients', clientGenderPayload($this->site, $this->serviceContext, [
            'gender' => 'another_gender',
            'gender_description' => '',
        ]))
        ->assertSessionHasErrors(['gender_description' => 'Describe their gender in their own words, or choose another option.']);

    $this->actingAs($this->admin)
        ->post('/clients', clientGenderPayload($this->site, $this->serviceContext, [
            'gender' => 'another_gender',
            'gender_description' => str_repeat('x', ClientGender::DESCRIPTION_MAX_LENGTH + 1),
        ]))
        ->assertSessionHasErrors('gender_description');

    $this->actingAs($this->admin)
        ->post('/clients', clientGenderPayload($this->site, $this->serviceContext, ['gender' => 'female']))
        ->assertSessionHasNoErrors();
});

it('clears the description when another option is chosen', function () {
    $this->actingAs($this->admin)
        ->post('/clients', clientGenderPayload($this->site, $this->serviceContext, [
            'gender' => 'female',
            'gender_description' => 'left over from Another gender',
        ]))
        ->assertSessionHasNoErrors();

    expect(Client::query()->where('last_name', 'Test')->value('gender_description'))->toBeNull();

    $client = Client::factory()->gender(ClientGender::AnotherGender, 'Non-binary')->create(['site_id' => $this->site->id]);

    $this->actingAs($this->admin)
        ->put("/clients/{$client->id}", clientGenderPayload($this->site, $this->serviceContext, [
            'first_name' => $client->first_name,
            'last_name' => $client->last_name,
            'gender' => 'male',
            'gender_description' => 'Non-binary',
        ]))
        ->assertSessionHasNoErrors();

    expect($client->refresh()->gender)->toBe('male')
        ->and($client->gender_description)->toBeNull();
});

it('still loads and saves a person whose old answer needs a choice', function () {
    $client = Client::factory()->withUnmappedGender('Whānau to confirm')->create(['site_id' => $this->site->id]);

    $this->actingAs($this->admin)
        ->getJson("/operations/clients/{$client->id}/edit?modal=1")
        ->assertOk()
        ->assertJsonPath('initialValues.gender', '')
        ->assertJsonPath('initialValues.gender_description', '')
        ->assertJsonPath('initialValues.gender_recorded_as', 'Whānau to confirm');

    // Saving other details without choosing keeps the old answer and the flag.
    $this->actingAs($this->admin)
        ->put("/clients/{$client->id}", clientGenderPayload($this->site, $this->serviceContext, [
            'first_name' => 'Renamed',
            'last_name' => $client->last_name,
            'gender' => '',
            'gender_description' => '',
        ]))
        ->assertSessionHasNoErrors();

    expect($client->refresh()->first_name)->toBe('Renamed')
        ->and($client->gender)->toBeNull()
        ->and($client->gender_recorded_as)->toBe('Whānau to confirm');

    // Choosing an option replaces it.
    $this->actingAs($this->admin)
        ->put("/clients/{$client->id}", clientGenderPayload($this->site, $this->serviceContext, [
            'first_name' => 'Renamed',
            'last_name' => $client->last_name,
            'gender' => 'prefer_not_to_say',
        ]))
        ->assertSessionHasNoErrors();

    expect($client->refresh()->gender)->toBe('prefer_not_to_say')
        ->and($client->gender_recorded_as)->toBeNull();
});

it('shows a readable label and the choose-an-option flag on the profile', function () {
    $described = Client::factory()->gender(ClientGender::AnotherGender, 'Takatāpui')->create(['site_id' => $this->site->id]);
    $female = Client::factory()->gender(ClientGender::Female)->create(['site_id' => $this->site->id]);
    $flagged = Client::factory()->withUnmappedGender('Whānau to confirm')->create(['site_id' => $this->site->id]);

    $this->actingAs($this->admin)->get("/operations/clients/{$described->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('client.gender', 'another_gender')
            ->where('client.gender_description', 'Takatāpui')
            ->where('client.gender_label', 'Another gender: Takatāpui')
            ->where('client.gender_recorded_as', null));

    $this->actingAs($this->admin)->get("/operations/clients/{$female->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('client.gender_label', 'Female')
            ->where('client.gender_recorded_as', null));

    $this->actingAs($this->admin)->get("/operations/clients/{$flagged->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('client.gender', null)
            ->where('client.gender_label', null)
            ->where('client.gender_recorded_as', 'Whānau to confirm'));
});

it('maps free text from writers that skip the form, never guessing', function () {
    $woman = Client::factory()->create(['site_id' => $this->site->id, 'gender' => 'Woman']);
    $nonBinary = Client::factory()->create(['site_id' => $this->site->id, 'gender' => 'Non-binary']);
    $unclear = Client::factory()->create(['site_id' => $this->site->id, 'gender' => 'see notes']);

    expect($woman->refresh()->only(['gender', 'gender_description', 'gender_recorded_as']))
        ->toBe(['gender' => 'female', 'gender_description' => null, 'gender_recorded_as' => null])
        ->and($nonBinary->refresh()->only(['gender', 'gender_description', 'gender_recorded_as']))
        ->toBe(['gender' => 'another_gender', 'gender_description' => 'Non-binary', 'gender_recorded_as' => null])
        ->and($unclear->refresh()->only(['gender', 'gender_description', 'gender_recorded_as']))
        ->toBe(['gender' => null, 'gender_description' => null, 'gender_recorded_as' => 'see notes']);
});

it('maps typed answers in the data migration and keeps what it cannot map', function () {
    $typed = [
        'F' => ['female', null],
        '  Woman ' => ['female', null],
        'Female' => ['female', null],
        'boy' => ['male', null],
        'Not stated' => ['prefer_not_to_say', null],
        'unknown' => ['prefer_not_to_say', null],
        'Non-binary' => ['another_gender', 'Non-binary'],
        'Takatāpui' => ['another_gender', 'Takatāpui'],
        'trans woman' => ['another_gender', 'trans woman'],
        'Whānau to confirm' => [null, null],
    ];
    $clients = collect($typed)->map(fn ($expected, $text) => clientWithTypedGender($this->site, (string) $text));
    $alreadyCode = clientWithTypedGender($this->site, 'female');
    $notRecorded = clientWithTypedGender($this->site, null);

    $counts = clientGenderMigration()->mapTypedGenders();

    expect($counts)->toBe([
        'female' => 3,
        'male' => 1,
        'another_gender' => 3,
        'prefer_not_to_say' => 2,
        'flagged' => 1,
    ]);

    foreach ($typed as $text => [$gender, $description]) {
        $row = DB::table('clients')->where('id', $clients[$text]->id)->first();
        expect($row->gender)->toBe($gender, "gender for '{$text}'")
            ->and($row->gender_description)->toBe($description, "description for '{$text}'")
            // Every changed row keeps exactly what was typed, so nothing is lost.
            ->and($row->gender_recorded_as)->toBe((string) $text, "kept text for '{$text}'");
    }

    // Trans, non-binary and takatāpui answers are never collapsed into Female or Male.
    expect(DB::table('clients')->where('id', $clients['trans woman']->id)->value('gender'))->toBe('another_gender');

    expect(DB::table('clients')->where('id', $alreadyCode->id)->first())
        ->gender->toBe('female')
        ->gender_recorded_as->toBeNull()
        ->and(DB::table('clients')->where('id', $notRecorded->id)->value('gender'))->toBeNull();

    // The unmapped answer is flagged where the person is edited.
    $this->actingAs($this->admin)->get("/operations/clients/{$clients['Whānau to confirm']->id}")
        ->assertInertia(fn (Assert $page) => $page->where('client.gender_recorded_as', 'Whānau to confirm'));

    // Running it again changes nothing.
    expect(clientGenderMigration()->mapTypedGenders())->toBe([
        'female' => 0,
        'male' => 0,
        'another_gender' => 0,
        'prefer_not_to_say' => 0,
        'flagged' => 0,
    ]);
});

it('puts the original text back when the migration is reversed', function () {
    $typed = ['F', '  Woman ', 'Non-binary', 'Whānau to confirm'];
    $clients = collect($typed)->mapWithKeys(fn (string $text) => [$text => clientWithTypedGender($this->site, $text)]);
    $alreadyCode = clientWithTypedGender($this->site, 'male');
    $chosenLater = Client::factory()->gender(ClientGender::AnotherGender, 'Two-spirit')->create(['site_id' => $this->site->id]);
    $declinedLater = Client::factory()->gender(ClientGender::PreferNotToSay)->create(['site_id' => $this->site->id]);

    $migration = clientGenderMigration();
    $migration->mapTypedGenders();
    $migration->restoreTypedGenders();

    foreach ($typed as $text) {
        expect(DB::table('clients')->where('id', $clients[$text]->id)->value('gender'))->toBe($text);
    }
    expect(DB::table('clients')->where('id', $alreadyCode->id)->value('gender'))->toBe('male')
        ->and(DB::table('clients')->where('id', $chosenLater->id)->value('gender'))->toBe('Two-spirit')
        ->and(DB::table('clients')->where('id', $declinedLater->id)->value('gender'))->toBe('Prefer not to say');
});

it('maps each option to its NHI gender code', function () {
    expect(ClientGender::nhiCodeFor('female'))->toBe('F')
        ->and(ClientGender::nhiCodeFor('male'))->toBe('M')
        ->and(ClientGender::nhiCodeFor('another_gender'))->toBe('O')
        ->and(ClientGender::nhiCodeFor('prefer_not_to_say'))->toBe('U')
        ->and(ClientGender::nhiCodeFor(null))->toBe('U');
});
