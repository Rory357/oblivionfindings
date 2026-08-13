<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientFund;
use App\Models\FundingClaim;
use App\Models\Permission;
use App\Models\ServiceAgreement;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

function clientFundingSiteUser(Site $site, array $permissionKeys): User
{
    $user = User::factory()->create(['approved_at' => now()]);
    HrEmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-CLIENT-FUNDING-'.$user->id,
        'work_email' => $user->email,
        'position_title' => 'Client Funding Officer',
        'position_role' => 'finance',
        'employment_type' => 'full_time',
        'start_date' => now()->subMonth()->toDateString(),
        'is_active' => true,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
    ]);

    foreach ($permissionKeys as $key) {
        $permission = Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key],
        );
        $user->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
    }

    return $user;
}

function clientFundingAgreement(Client $client, string $title): ServiceAgreement
{
    return ServiceAgreement::query()->create([
        'client_id' => $client->id,
        'title' => $title,
        'agreement_type' => 'community',
        'status' => 'active',
        'starts_at' => now()->subMonth()->toDateString(),
        'total_budget' => '1000.00',
    ]);
}

function clientFundingClaim(ServiceAgreement $agreement, string $reference): FundingClaim
{
    return FundingClaim::query()->create([
        'service_agreement_id' => $agreement->id,
        'client_id' => $agreement->client_id,
        'claim_reference' => $reference,
        'status' => 'draft',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'total_amount' => '50.00',
    ]);
}

beforeEach(function (): void {
    Queue::fake();
});

it('Site-scopes Client Funds and fails closed for Site-less funds', function () {
    $assignedSite = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $user = clientFundingSiteUser($assignedSite, ['client_funds.manage']);
    $ownClient = Client::factory()->create(['site_id' => $assignedSite->id]);
    $otherClient = Client::factory()->create(['site_id' => $otherSite->id]);
    $siteLessClient = Client::factory()->create(['site_id' => null]);

    $ownFund = ClientFund::query()->create([
        'client_id' => $ownClient->id,
        'fund_name' => 'Accessible fund',
        'balance' => '50.00',
        'is_active' => true,
    ]);
    $otherFund = ClientFund::query()->create([
        'client_id' => $otherClient->id,
        'fund_name' => 'Other Site fund',
        'balance' => '60.00',
        'is_active' => true,
    ]);
    $siteLessFund = ClientFund::query()->create([
        'client_id' => $siteLessClient->id,
        'fund_name' => 'Unassigned fund',
        'balance' => '70.00',
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->get(route('operations.client_funds.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('funds.total', 1)
            ->where('funds.data.0.id', $ownFund->id)
            ->where('stats.total', 1));

    $this->actingAs($user)
        ->get(route('operations.client_funds.show', $otherFund))
        ->assertNotFound();
    $this->actingAs($user)
        ->get(route('operations.client_funds.show', $siteLessFund))
        ->assertNotFound();
});

it('Site-scopes Funding Claims and denies direct workflow actions across Sites', function () {
    $assignedSite = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $user = clientFundingSiteUser($assignedSite, [
        'funding.viewAny',
        'funding.claims.submit',
        'funding.claims.approve',
    ]);
    $ownAgreement = clientFundingAgreement(
        Client::factory()->create(['site_id' => $assignedSite->id]),
        'Accessible agreement',
    );
    $otherAgreement = clientFundingAgreement(
        Client::factory()->create(['site_id' => $otherSite->id]),
        'Other Site agreement',
    );
    $ownClaim = clientFundingClaim($ownAgreement, 'SITE-OWN-001');
    $otherClaim = clientFundingClaim($otherAgreement, 'SITE-OTHER-001');

    $this->actingAs($user)
        ->get(route('operations.funding.claims.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('claims.total', 1)
            ->where('claims.data.0.id', $ownClaim->id));

    $this->actingAs($user)
        ->get(route('operations.funding.claims.show', $otherClaim))
        ->assertNotFound();
    $this->actingAs($user)
        ->post(route('operations.funding.claims.submit', $otherClaim))
        ->assertNotFound();
});

it('requires every Claim and linked line item to match the accessible parent agreement', function () {
    $assignedSite = Site::factory()->create();
    $user = clientFundingSiteUser($assignedSite, ['funding.claims.create']);
    $agreementClient = Client::factory()->create(['site_id' => $assignedSite->id]);
    $otherClient = Client::factory()->create(['site_id' => $assignedSite->id]);
    $siteLessClient = Client::factory()->create(['site_id' => null]);
    $agreement = clientFundingAgreement($agreementClient, 'Claim agreement');
    $otherAgreement = clientFundingAgreement($otherClient, 'Other Client agreement');
    $siteLessAgreement = clientFundingAgreement($siteLessClient, 'Unassigned agreement');
    $otherLineItem = $otherAgreement->lineItems()->create([
        'description' => 'Other Client support',
        'unit_price' => '50.00',
        'quantity' => '1.00',
        'budget_allocated' => '50.00',
    ]);
    $payload = [
        'service_agreement_id' => $agreement->id,
        'client_id' => $agreementClient->id,
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'items' => [[
            'description' => 'Supported activity',
            'quantity' => '1.00',
            'unit_price' => '50.00',
            'service_date' => now()->toDateString(),
        ]],
    ];

    $this->actingAs($user)
        ->from(route('operations.funding.claims.create'))
        ->post(route('operations.funding.claims.store'), [
            ...$payload,
            'client_id' => $otherClient->id,
        ])
        ->assertSessionHasErrors('client_id');

    $payload['items'][0]['service_agreement_line_item_id'] = $otherLineItem->id;
    $this->actingAs($user)
        ->from(route('operations.funding.claims.create'))
        ->post(route('operations.funding.claims.store'), $payload)
        ->assertSessionHasErrors('items');

    $payload['service_agreement_id'] = $siteLessAgreement->id;
    unset($payload['items'][0]['service_agreement_line_item_id']);
    $this->actingAs($user)
        ->post(route('operations.funding.claims.store'), $payload)
        ->assertNotFound();

    expect(FundingClaim::query()->count())->toBe(0);
});

it('denies direct Client financial views outside the user Site boundary', function () {
    $assignedSite = Site::factory()->create();
    $otherClient = Client::factory()->create(['site_id' => Site::factory()->create()->id]);
    $siteLessClient = Client::factory()->create(['site_id' => null]);
    $user = clientFundingSiteUser($assignedSite, ['finance.dashboard']);

    $this->actingAs($user)
        ->get(route('finance.clients.financials', $otherClient))
        ->assertNotFound();
    $this->actingAs($user)
        ->get(route('finance.clients.financials', $siteLessClient))
        ->assertNotFound();
    $this->actingAs($user)
        ->get(route('finance.api.clients.ledger', $otherClient))
        ->assertNotFound();
});
