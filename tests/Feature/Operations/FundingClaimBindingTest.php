<?php

namespace Tests\Feature\Operations;

use App\Domain\Finance\Jobs\PostFundingClaimJournalJob;
use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Services\FundingClaimJournalService;
use App\Domain\Finance\Services\JournalPostingService;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\BillingEntry;
use App\Models\Client;
use App\Models\FundingClaim;
use App\Models\FundingClaimItem;
use App\Models\Permission;
use App\Models\ServiceAgreement;
use App\Models\ServiceAgreementRate;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Operations\BillingService;
use App\Services\Operations\FundingClaimService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class FundingClaimBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_claim_route_derives_immutable_money_from_one_delivery_and_replays_once(): void
    {
        $source = $this->deliverySource();
        $actor = $this->actorForSite($source['site'], ['funding.claims.create']);
        $payload = $this->claimPayload($source);

        $this->actingAs($actor)
            ->post(route('operations.funding.claims.store'), $payload)
            ->assertRedirect();
        $this->actingAs($actor)
            ->post(route('operations.funding.claims.store'), $payload)
            ->assertRedirect();

        $claim = FundingClaim::query()->sole();
        $item = FundingClaimItem::query()->sole();
        $this->assertSame($actor->id, $claim->created_by);
        $this->assertSame($source['site']->id, $claim->site_id);
        $this->assertSame('150.50', (string) $claim->total_amount);
        $this->assertSame('2.00', (string) $item->quantity);
        $this->assertSame('75.25', (string) $item->unit_price);
        $this->assertSame('150.50', (string) $item->total_amount);
        $this->assertSame($source['entry']->id, $item->billing_entry_id);
        $this->assertSame($source['timesheet']->id, $item->timesheet_id);
        $this->assertSame($source['shift']->id, $item->shift_id);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $item->delivery_digest);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $claim->provenance_digest);
        $this->assertSame('claimed', $source['entry']->fresh()->status);

        try {
            app(BillingService::class)->generateInvoice([$source['entry']->id], $actor->id);
            $this->fail('Claimed delivery was available for invoicing.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        try {
            $item->forceFill(['total_amount' => '0.01'])->save();
            $this->fail('Governed delivery provenance was mutable.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }
    }

    public function test_exact_delivery_id_is_required_and_identical_snapshots_are_never_inferred(): void
    {
        $source = $this->deliverySource();
        $sameSnapshot = $this->additionalDeliverySource($source, $source['service_date']);
        $actor = $this->actorForSite($source['site'], ['funding.claims.create']);

        $this->actingAs($actor)
            ->postJson(
                route('operations.funding.claims.store'),
                $this->claimPayload($source, [], false),
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.billing_entry_id');
        $this->actingAs($actor)
            ->postJson(route('operations.funding.claims.store'), $this->claimPayload($source, [
                'items' => [[
                    ...$this->claimPayload($source)['items'][0],
                    'billing_entry_id' => 0,
                ]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.billing_entry_id');
        $this->actingAs($actor)
            ->postJson(route('operations.funding.claims.store'), $this->claimPayload($source, [
                'items' => [[
                    ...$this->claimPayload($source)['items'][0],
                    'billing_entry_id' => 999999999,
                ]],
            ]))
            ->assertNotFound();

        $this->actingAs($actor)
            ->post(route('operations.funding.claims.store'), $this->claimPayload($source))
            ->assertRedirect();

        $this->assertSame($source['entry']->id, FundingClaimItem::query()->sole()->billing_entry_id);
        $this->assertSame('claimed', $source['entry']->fresh()->status);
        $this->assertSame('pending', $sameSnapshot['entry']->fresh()->status);
    }

    public function test_mixed_relationship_period_rate_and_foreign_site_inputs_fail_without_partial_reservation(): void
    {
        $local = $this->deliverySource();
        $foreign = $this->deliverySource(Site::factory()->create());
        $actor = $this->actorForSite($local['site'], ['funding.claims.create']);

        $this->actingAs($actor)
            ->postJson(route('operations.funding.claims.store'), $this->claimPayload($local, [
                'client_id' => $foreign['client']->id,
            ]))
            ->assertNotFound();
        $this->actingAs($actor)
            ->postJson(route('operations.funding.claims.store'), $this->claimPayload($local, [
                'items' => [[
                    ...$this->claimPayload($local)['items'][0],
                    'billing_entry_id' => $foreign['entry']->id,
                ]],
            ]))
            ->assertNotFound();

        $otherLine = $local['agreement']->lineItems()->create([
            'description' => 'Different agreement service',
            'unit_price' => '75.25',
            'quantity' => '10.00',
            'budget_allocated' => '752.50',
        ]);
        $this->actingAs($actor)
            ->postJson(route('operations.funding.claims.store'), $this->claimPayload($local, [
                'items' => [[
                    ...$this->claimPayload($local)['items'][0],
                    'service_agreement_line_item_id' => $otherLine->id,
                ]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $this->actingAs($actor)
            ->postJson(route('operations.funding.claims.store'), $this->claimPayload($local, [
                'period_start' => $local['service_date']->copy()->addDay()->toDateString(),
                'period_end' => $local['service_date']->copy()->addDay()->toDateString(),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');
        $this->actingAs($actor)
            ->postJson(route('operations.funding.claims.store'), $this->claimPayload($local, [
                'items' => [[
                    ...$this->claimPayload($local)['items'][0],
                    'unit_price' => '75.24',
                ]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $this->assertDatabaseCount('funding_claims', 0);
        $this->assertDatabaseCount('funding_claim_items', 0);
        $this->assertSame('pending', $local['entry']->fresh()->status);
        $this->assertSame('pending', $foreign['entry']->fresh()->status);
    }

    public function test_explicit_global_site_permission_broadens_scope_but_never_replaces_create_permission(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $source = $this->deliverySource($siteB);
        $restricted = $this->actorForSite($siteA, ['funding.claims.create']);
        $globalWithoutAction = $this->actorForSite($siteA, ['funding.viewAllSites']);
        $global = $this->actorForSite($siteA, ['funding.viewAllSites', 'funding.claims.create']);
        $payload = $this->claimPayload($source);

        $this->actingAs($restricted)
            ->get(route('operations.funding.claims.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('deliveries', 0));
        $this->actingAs($global)
            ->get(route('operations.funding.claims.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('deliveries', 1)
                ->where('deliveries.0.id', $source['entry']->id));

        $this->actingAs($restricted)
            ->postJson(route('operations.funding.claims.store'), $payload)
            ->assertNotFound();
        $this->actingAs($globalWithoutAction)
            ->postJson(route('operations.funding.claims.store'), $payload)
            ->assertForbidden();
        $this->actingAs($global)
            ->postJson(route('operations.funding.claims.store'), $payload)
            ->assertRedirect();

        $claim = FundingClaim::query()->sole();
        $this->assertSame($siteB->id, $claim->site_id);

        $viewActionOnly = $this->actorForSite($siteA, ['funding.viewAny']);
        $viewGlobalOnly = $this->actorForSite($siteA, ['funding.viewAllSites']);
        $authorisedViewer = $this->actorForSite($siteA, ['funding.viewAny', 'funding.viewAllSites']);
        $this->actingAs($viewActionOnly)
            ->getJson(route('operations.funding.claims.index', ['client_id' => 0]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client_id');
        $this->actingAs($viewActionOnly)
            ->get(route('operations.funding.claims.show', $claim))
            ->assertNotFound();
        $this->actingAs($viewGlobalOnly)
            ->get(route('operations.funding.claims.show', $claim))
            ->assertForbidden();
        $this->actingAs($authorisedViewer)
            ->get(route('operations.funding.claims.show', $claim))
            ->assertOk();
        DB::table('funding_claims')->where('id', $claim->id)->update(['site_id' => $siteA->id]);
        $this->actingAs($authorisedViewer)
            ->get(route('operations.funding.claims.show', $claim))
            ->assertNotFound();
    }

    public function test_failed_posting_retry_requires_its_action_permission_and_separate_global_site_scope(): void
    {
        Bus::fake([PostFundingClaimJournalJob::class]);
        $siteA = Site::factory()->create();
        $source = $this->deliverySource(Site::factory()->create());
        $creator = $this->actorForSite($source['site'], ['funding.claims.create']);
        $claim = app(FundingClaimService::class)->createDraft($creator, $this->claimPayload($source))['claim'];
        $claim->forceFill([
            'status' => 'submitted',
            'submitted_at' => now(),
            'submitted_by' => $creator->id,
            'gl_posting_status' => 'failed',
            'gl_posting_error' => 'temporary finance outage',
        ])->saveQuietly();

        $actionOnly = $this->actorForSite($siteA, ['funding.claims.retryPosting']);
        $globalOnly = $this->actorForSite($siteA, ['funding.viewAllSites']);
        $authorisedGlobal = $this->actorForSite($siteA, [
            'funding.viewAllSites',
            'funding.viewAny',
            'funding.claims.retryPosting',
        ]);

        $this->actingAs($actionOnly)
            ->post(route('operations.funding.claims.retry-posting', $claim))
            ->assertNotFound();
        $this->actingAs($globalOnly)
            ->post(route('operations.funding.claims.retry-posting', $claim))
            ->assertForbidden();
        $this->actingAs($globalOnly)
            ->post(route('operations.funding.claims.submit', $claim))
            ->assertForbidden();
        $this->actingAs($globalOnly)
            ->post(route('operations.funding.claims.approve', $claim))
            ->assertForbidden();
        $this->actingAs($authorisedGlobal)
            ->get(route('operations.funding.claims.show', $claim))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('claim.gl_posting_status', 'failed')
                ->missing('claim.gl_posting_error'));
        $this->actingAs($authorisedGlobal)
            ->post(route('operations.funding.claims.retry-posting', $claim))
            ->assertRedirect();

        $this->assertSame('queued', $claim->fresh()->gl_posting_status);
        Bus::assertDispatched(
            PostFundingClaimJournalJob::class,
            fn (PostFundingClaimJournalJob $job): bool => $job->claimId === $claim->id,
        );
    }

    public function test_expired_agreement_and_expired_explicit_rate_fail_without_a_delivery_use(): void
    {
        $expiredAgreement = $this->deliverySource();
        $actor = $this->actorForSite($expiredAgreement['site'], ['funding.claims.create']);
        $expiredAgreement['agreement']->forceFill([
            'ends_at' => $expiredAgreement['service_date']->copy()->subDay(),
        ])->save();

        try {
            app(FundingClaimService::class)->createDraft(
                $actor,
                $this->claimPayload($expiredAgreement),
            );
            $this->fail('A delivery outside the agreement term was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }

        $expiredRate = $this->deliverySource();
        $rateActor = $this->actorForSite($expiredRate['site'], ['funding.claims.create']);
        ServiceAgreementRate::query()->create([
            'service_agreement_id' => $expiredRate['agreement']->id,
            'rate_type' => 'weekday',
            'rate' => '75.25',
            'unit' => 'hour',
            'effective_from' => $expiredRate['service_date']->copy()->subMonth(),
            'effective_to' => $expiredRate['service_date']->copy()->subDay(),
        ]);

        try {
            app(FundingClaimService::class)->createDraft(
                $rateActor,
                $this->claimPayload($expiredRate),
            );
            $this->fail('An expired explicit agreement rate was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }

        $this->assertDatabaseCount('funding_claims', 0);
        $this->assertSame('pending', $expiredAgreement['entry']->fresh()->status);
        $this->assertSame('pending', $expiredRate['entry']->fresh()->status);
    }

    public function test_late_item_failure_rolls_back_claim_delivery_reservation_and_audit_together(): void
    {
        $source = $this->deliverySource();
        $second = $this->additionalDeliverySource(
            $source,
            $source['service_date']->copy()->addDay(),
        );
        $actor = $this->actorForSite($source['site'], ['funding.claims.create']);
        $createdItems = 0;
        FundingClaimItem::creating(function () use (&$createdItems): void {
            $createdItems++;
            if ($createdItems === 2) {
                throw new RuntimeException('forced funding item failure');
            }
        });

        $payload = $this->claimPayload($source);
        $payload['items'][] = $this->claimPayload($second)['items'][0];

        try {
            app(FundingClaimService::class)->createDraft($actor, $payload);
            $this->fail('The forced Funding Claim failure was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced funding item failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('funding_claims', 0);
        $this->assertDatabaseCount('funding_claim_items', 0);
        $this->assertSame('pending', $source['entry']->fresh()->status);
        $this->assertSame('pending', $second['entry']->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'funding.claim.create']);
    }

    public function test_submission_has_durable_unique_job_retries_and_failed_state(): void
    {
        Bus::fake([PostFundingClaimJournalJob::class]);
        $source = $this->deliverySource();
        $actor = $this->actorForSite($source['site'], ['funding.claims.create', 'funding.claims.submit']);
        $claim = app(FundingClaimService::class)->createDraft($actor, $this->claimPayload($source))['claim'];

        $result = app(FundingClaimService::class)->submit($actor, $claim->id);
        $this->assertFalse($result['replayed']);
        $this->assertSame('queued', $claim->fresh()->gl_posting_status);
        Bus::assertDispatched(
            PostFundingClaimJournalJob::class,
            fn (PostFundingClaimJournalJob $job): bool => $job->claimId === $claim->id,
        );

        $job = new PostFundingClaimJournalJob($claim->id);
        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 60, 300], $job->backoff);
        $failure = new RuntimeException('forced posting failure');
        $posting = Mockery::mock(FundingClaimJournalService::class);
        $posting->shouldReceive('postFundingClaimJournal')->once()->andThrow($failure);
        try {
            $job->handle($posting);
            $this->fail('The forced posting failure was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }
        $job->failed($failure);

        $claim->refresh();
        $this->assertSame('submitted', $claim->status);
        $this->assertSame('failed', $claim->gl_posting_status);
        $this->assertStringContainsString('forced posting failure', (string) $claim->gl_posting_error);
        $this->assertNull($claim->journal_id);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'funding.claim.gl.exhausted',
            'auditable_id' => $claim->id,
            'user_id' => $actor->id,
        ]);
    }

    public function test_dispatch_failure_state_and_strict_audit_commit_together(): void
    {
        $source = $this->deliverySource();
        $actor = $this->actorForSite($source['site'], ['funding.claims.create', 'funding.claims.submit']);
        $claim = app(FundingClaimService::class)->createDraft($actor, $this->claimPayload($source))['claim'];
        $failure = new RuntimeException('forced queue dispatch failure');
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andThrow($failure);
        $this->app->instance(Dispatcher::class, $dispatcher);

        $result = app(FundingClaimService::class)->submit($actor, $claim->id);

        $claim->refresh();
        $this->assertTrue($result['posting_failed']);
        $this->assertSame('submitted', $claim->status);
        $this->assertSame('failed', $claim->gl_posting_status);
        $this->assertNull($claim->journal_id);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'funding.claim.gl.dispatch_failed',
            'auditable_id' => $claim->id,
            'user_id' => $actor->id,
        ]);
    }

    public function test_posting_failure_state_and_strict_audit_commit_together(): void
    {
        $source = $this->deliverySource();
        $actor = $this->actorForSite($source['site'], ['funding.claims.create']);
        $claim = app(FundingClaimService::class)->createDraft($actor, $this->claimPayload($source))['claim'];
        $claim->forceFill([
            'status' => 'submitted',
            'submitted_at' => now(),
            'submitted_by' => $actor->id,
            'gl_posting_status' => 'queued',
        ])->saveQuietly();
        $failure = new RuntimeException('forced sequence-lock failure');
        $posting = Mockery::mock(JournalPostingService::class);
        $posting->shouldReceive('lockJournalSequence')->once()->andThrow($failure);
        $service = new FundingClaimJournalService($posting, app(FundingClaimService::class));

        try {
            $service->postFundingClaimJournal($claim);
            $this->fail('The forced journal failure was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $claim->refresh();
        $this->assertSame('submitted', $claim->status);
        $this->assertSame('failed', $claim->gl_posting_status);
        $this->assertSame(1, $claim->gl_posting_attempts);
        $this->assertNull($claim->journal_id);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'funding.claim.gl.failed',
            'auditable_id' => $claim->id,
            'user_id' => $actor->id,
        ]);
    }

    public function test_same_delivery_claim_commands_serialize_to_one_effect_on_mysql(): void
    {
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());
        $source = $this->deliverySource();
        $actor = $this->actorForSite($source['site'], ['funding.claims.create']);
        $connection->commit();

        try {
            $statuses = $this->concurrentMonetisationRound($source['entry']->id, [
                [
                    'action' => 'claim',
                    'actor_id' => $actor->id,
                    'payload' => $this->claimPayload($source),
                ],
                [
                    'action' => 'claim',
                    'actor_id' => $actor->id,
                    'payload' => $this->claimPayload($source),
                ],
            ]);

            sort($statuses);
            $this->assertSame(['claim', 'rejected'], $statuses);
            $this->assertDatabaseCount('funding_claims', 1);
            $this->assertDatabaseCount('funding_claim_items', 1);
            $this->assertSame('claimed', $source['entry']->fresh()->status);
        } finally {
            $this->cleanupCommittedFundingFixtures();
            $connection->beginTransaction();
        }
    }

    public function test_claim_and_invoice_serialize_on_the_same_delivery_use_on_mysql(): void
    {
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());
        $source = $this->deliverySource();
        $actor = $this->actorForSite($source['site'], ['funding.claims.create']);
        $connection->commit();

        try {
            $statuses = $this->concurrentMonetisationRound($source['entry']->id, [
                [
                    'action' => 'claim',
                    'actor_id' => $actor->id,
                    'payload' => $this->claimPayload($source),
                ],
                [
                    'action' => 'invoice',
                    'actor_id' => $actor->id,
                    'billing_entry_id' => $source['entry']->id,
                ],
            ]);

            $this->assertContains('rejected', $statuses);
            $this->assertSame(1, count(array_intersect($statuses, ['claim', 'invoice'])));
            $this->assertSame(
                1,
                FundingClaimItem::query()->count() + DB::table('fin_invoice_lines')->count(),
            );
            $this->assertContains($source['entry']->fresh()->status, ['claimed', 'invoiced']);
        } finally {
            $this->cleanupCommittedFundingFixtures();
            $connection->beginTransaction();
        }
    }

    public function test_finance_invoice_route_rechecks_claim_use_and_derives_delivery_money(): void
    {
        $site = Site::factory()->create();
        $invoiceSource = $this->deliverySource($site);
        $claimedSource = $this->deliverySource($site);
        $invoiceActor = $this->actorForSite($site, ['finance.ar.manage']);
        $claimActor = $this->actorForSite($site, ['funding.claims.create']);
        $payloadFor = fn (array $source, array $lineOverrides = []): array => [
            'client_id' => $source['client']->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(20)->toDateString(),
            'lines' => [[
                'billing_entry_id' => $source['entry']->id,
                'description' => 'Caller-controlled description',
                'quantity' => '2.00',
                'unit_price' => '75.25',
                'service_date' => $source['serviceDate']->toDateString(),
                ...$lineOverrides,
            ]],
        ];

        try {
            $invoiceSource['entry']->forceFill([
                'status' => 'invoiced',
                'amount' => '0.01',
            ])->save();
            $this->fail('Delivered-support provenance changed during its monetisation transition.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }
        $invoiceSource['entry']->refresh();

        $this->actingAs($invoiceActor)
            ->postJson(route('finance.invoices.store'), $payloadFor($invoiceSource, [
                'quantity' => '999.00',
                'unit_price' => '0.01',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines');
        $this->assertDatabaseCount('fin_invoices', 0);
        $this->assertSame('pending', $invoiceSource['entry']->fresh()->status);

        $this->actingAs($invoiceActor)
            ->post(route('finance.invoices.store'), $payloadFor($invoiceSource))
            ->assertRedirect();
        $invoice = FinInvoice::query()->sole()->load('lines');
        $line = $invoice->lines->sole();
        $this->assertSame(BillingEntry::class, $invoice->source_type);
        $this->assertSame($invoiceSource['entry']->id, $invoice->source_id);
        $this->assertSame($invoiceSource['entry']->id, $line->billing_entry_id);
        $this->assertSame('2.00', (string) $line->quantity);
        $this->assertSame('75.25', (string) $line->unit_price);
        $this->assertSame($invoiceSource['serviceDate']->toDateString(), $line->service_date->toDateString());
        $this->assertSame('invoiced', $invoiceSource['entry']->fresh()->status);
        $this->actingAs($invoiceActor)
            ->put(route('finance.invoices.update', $invoice), [
                'client_id' => $invoiceSource['client']->id,
                'notes' => 'attempted provenance rewrite',
            ])
            ->assertSessionHasErrors('invoice');
        $this->assertSame($invoiceSource['entry']->id, $line->fresh()->billing_entry_id);
        $this->assertSame('75.25', (string) $line->fresh()->unit_price);

        $this->actingAs($claimActor);
        try {
            app(FundingClaimService::class)->createDraft($claimActor, $this->claimPayload($invoiceSource));
            $this->fail('An invoiced delivery was available to a Funding Claim.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }

        app(FundingClaimService::class)->createDraft($claimActor, $this->claimPayload($claimedSource));
        $this->actingAs($invoiceActor)
            ->postJson(route('finance.invoices.store'), $payloadFor($claimedSource))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines');
        $this->assertSame('claimed', $claimedSource['entry']->fresh()->status);
        $this->assertDatabaseCount('fin_invoice_lines', 1);
    }

    public function test_000140_binds_and_reserves_only_an_unambiguous_legacy_delivery_before_unique_indexes(): void
    {
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());
        $connection->commit();
        $path = database_path('migrations/2026_08_23_000140_bind_funding_claim_delivery_monetisation.php');
        $migration = require $path;
        $migration->down();

        try {
            $source = $this->deliverySource();
            [$claim, $item] = $this->legacyClaim($source);

            $migration = require $path;
            $migration->up();

            $this->assertTrue(Schema::hasColumn('funding_claims', 'integrity_state'));
            $this->assertSame('legacy_bound_read_only', $claim->fresh()->integrity_state);
            $this->assertSame($source['site']->id, $claim->fresh()->site_id);
            $this->assertSame($source['entry']->id, $item->fresh()->billing_entry_id);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $item->fresh()->delivery_digest);
            $this->assertSame('claimed', $source['entry']->fresh()->status);

            try {
                app(FundingClaimService::class)->assertClaimIntegrity($claim->fresh());
                $this->fail('Legacy bound provenance was allowed into the governed workflow.');
            } catch (HttpException $exception) {
                $this->assertSame(409, $exception->getStatusCode());
            }
        } finally {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            $this->cleanupCommittedFundingFixtures();
            if (! Schema::hasColumn('funding_claims', 'integrity_state')) {
                $migration = require $path;
                $migration->up();
            }
            $connection->beginTransaction();
        }
    }

    public function test_000140_reports_all_legacy_blocker_counts_before_any_ddl(): void
    {
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());
        $connection->commit();
        $path = database_path('migrations/2026_08_23_000140_bind_funding_claim_delivery_monetisation.php');
        $migration = require $path;
        $migration->down();

        try {
            $ambiguous = $this->deliverySource();
            $this->legacyClaim($ambiguous);
            $ambiguous['entry']->replicate()->save();

            $alreadyInvoiced = $this->deliverySource();
            $this->legacyClaim($alreadyInvoiced);
            $alreadyInvoiced['entry']->forceFill(['status' => 'invoiced'])->save();

            $missing = $this->deliverySource();
            $this->legacyClaim($missing);
            DB::table('billing_entries')->where('id', $missing['entry']->id)->delete();

            $mismatched = $this->deliverySource();
            $this->legacyClaim($mismatched);
            $mismatched['entry']->forceFill([
                'site_id' => Site::factory()->create()->id,
            ])->save();

            $duplicateInvoiceUse = $this->deliverySource();
            $invoice = FinInvoice::query()->create([
                'organization_id' => 1,
                'client_id' => $duplicateInvoiceUse['client']->id,
                'invoice_number' => 'INV-MIG-'.Str::upper(Str::random(8)),
                'invoice_date' => $duplicateInvoiceUse['serviceDate'],
                'due_date' => $duplicateInvoiceUse['serviceDate']->copy()->addDays(20),
                'client_name' => 'Migration duplicate',
                'subtotal' => '150.50',
                'tax_amount' => '22.57',
                'total_amount' => '173.07',
                'currency_code' => 'NZD',
                'status' => 'draft',
            ]);
            foreach ([0, 1] as $sortOrder) {
                $invoice->lines()->create([
                    'billing_entry_id' => $duplicateInvoiceUse['entry']->id,
                    'description' => 'Duplicate delivered-support use',
                    'quantity' => '2.00',
                    'unit_price' => '75.25',
                    'tax_amount' => '22.57',
                    'line_total' => '173.07',
                    'service_date' => $duplicateInvoiceUse['serviceDate'],
                    'category' => 'weekday',
                    'sort_order' => $sortOrder,
                ]);
            }

            try {
                $migration = require $path;
                $migration->up();
                $this->fail('Ambiguous legacy delivery provenance did not block 000140.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('blocked before DDL', $exception->getMessage());
                $this->assertStringContainsString('missing=1', $exception->getMessage());
                $this->assertStringContainsString('ambiguous=1', $exception->getMessage());
                $this->assertStringContainsString('mismatched=1', $exception->getMessage());
                $this->assertStringContainsString('already_invoiced=1', $exception->getMessage());
                $this->assertStringContainsString('duplicate_use=0', $exception->getMessage());
                $this->assertStringContainsString('billing_source_duplicates=1', $exception->getMessage());
                $this->assertStringContainsString('invoice_delivery_duplicates=1', $exception->getMessage());
            }

            $this->assertFalse(Schema::hasColumn('funding_claims', 'integrity_state'));
            $this->assertFalse(Schema::hasColumn('funding_claim_items', 'billing_entry_id'));
        } finally {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            $this->cleanupCommittedFundingFixtures();
            if (! Schema::hasColumn('funding_claims', 'integrity_state')) {
                $migration = require $path;
                $migration->up();
            }
            $connection->beginTransaction();
        }
    }

    /** @return array<string, mixed> */
    private function deliverySource(?Site $site = null): array
    {
        $site ??= Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $staff = User::factory()->create(['approved_at' => now()]);
        $serviceDate = today()->subDays(2);
        $agreement = ServiceAgreement::factory()->for($client)->create([
            'status' => 'active',
            'starts_at' => $serviceDate->copy()->subMonth(),
            'ends_at' => $serviceDate->copy()->addMonth(),
            'hourly_rate' => '70.00',
            'funding_reference' => 'FUND-CANONICAL',
        ]);
        $line = $agreement->lineItems()->create([
            'description' => 'Delivered community support',
            'unit_price' => '75.25',
            'quantity' => '20.00',
            'budget_allocated' => '1505.00',
            'category' => 'weekday',
            'funding_contract_reference' => 'LINE-CANONICAL',
        ]);
        $startsAt = $serviceDate->copy()->setTime(9, 0);
        $endsAt = $serviceDate->copy()->setTime(11, 0);
        $shift = Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'user_id' => $staff->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'actual_starts_at' => $startsAt,
            'actual_ends_at' => $endsAt,
            'status' => 'completed',
        ]);
        $timesheet = Timesheet::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $staff->id,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'shift_site_id' => $site->id,
            'work_date' => $serviceDate,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'break_minutes' => 0,
            'status' => 'approved',
            'submitted_at' => $endsAt,
            'submitted_by' => $staff->id,
            'approved_at' => $endsAt->copy()->addHour(),
            'approved_by' => $staff->id,
            'client_name_snapshot' => trim($client->first_name.' '.$client->last_name),
            'staff_name_snapshot' => $staff->name,
            'shift_type_snapshot' => 'standard',
        ]);
        $entry = BillingEntry::query()->create([
            'timesheet_id' => $timesheet->id,
            'shift_id' => $shift->id,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'staff_id' => $staff->id,
            'service_agreement_id' => $agreement->id,
            'line_item_id' => $line->id,
            'service_date' => $serviceDate,
            'hours' => '2.00',
            'rate' => '75.25',
            'amount' => '150.50',
            'rate_type' => 'weekday',
            'status' => 'pending',
        ]);

        return compact('site', 'client', 'staff', 'serviceDate', 'agreement', 'line', 'shift', 'timesheet', 'entry');
    }

    /** @return array<string, mixed> */
    private function additionalDeliverySource(array $source, $serviceDate): array
    {
        $site = $source['site'];
        $client = $source['client'];
        $staff = $source['staff'];
        $agreement = $source['agreement'];
        $line = $source['line'];
        $serviceDate = $serviceDate->copy();
        $startsAt = $serviceDate->copy()->setTime(13, 0);
        $endsAt = $serviceDate->copy()->setTime(15, 0);
        $shift = Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'user_id' => $staff->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'actual_starts_at' => $startsAt,
            'actual_ends_at' => $endsAt,
            'status' => 'completed',
        ]);
        $timesheet = Timesheet::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $staff->id,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'shift_site_id' => $site->id,
            'work_date' => $serviceDate,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'break_minutes' => 0,
            'status' => 'approved',
            'submitted_at' => $endsAt,
            'submitted_by' => $staff->id,
            'approved_at' => $endsAt->copy()->addHour(),
            'approved_by' => $staff->id,
            'client_name_snapshot' => trim($client->first_name.' '.$client->last_name),
            'staff_name_snapshot' => $staff->name,
            'shift_type_snapshot' => 'standard',
        ]);
        $entry = BillingEntry::query()->create([
            'timesheet_id' => $timesheet->id,
            'shift_id' => $shift->id,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'staff_id' => $staff->id,
            'service_agreement_id' => $agreement->id,
            'line_item_id' => $line->id,
            'service_date' => $serviceDate,
            'hours' => '2.00',
            'rate' => '75.25',
            'amount' => '150.50',
            'rate_type' => 'weekday',
            'status' => 'pending',
        ]);

        return compact('site', 'client', 'staff', 'serviceDate', 'agreement', 'line', 'shift', 'timesheet', 'entry');
    }

    /** @return array{0: FundingClaim, 1: FundingClaimItem} */
    private function legacyClaim(array $source): array
    {
        $claim = FundingClaim::query()->create([
            'service_agreement_id' => $source['agreement']->id,
            'client_id' => $source['client']->id,
            'claim_reference' => 'LEGACY-'.Str::upper(Str::random(8)),
            'status' => 'draft',
            'period_start' => $source['service_date']->copy()->startOfMonth(),
            'period_end' => $source['service_date']->copy()->endOfMonth(),
            'total_amount' => '150.50',
        ]);
        $item = FundingClaimItem::query()->create([
            'funding_claim_id' => $claim->id,
            'service_agreement_line_item_id' => $source['line']->id,
            'shift_id' => $source['shift']->id,
            'timesheet_id' => $source['timesheet']->id,
            'description' => $source['line']->description,
            'quantity' => '2.00',
            'unit_price' => '75.25',
            'total_amount' => '150.50',
            'service_date' => $source['service_date'],
            'funding_contract_reference' => $source['line']->funding_contract_reference,
        ]);

        return [$claim, $item];
    }

    private function actorForSite(Site $site, array $permissionKeys): User
    {
        $actor = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::query()->create([
            'user_id' => $actor->id,
            'employee_number' => 'EMP-FUND-'.$actor->id,
            'work_email' => $actor->email,
            'position_title' => 'Funding Officer',
            'position_role' => 'finance',
            'employment_type' => 'full_time',
            'start_date' => today()->subMonth(),
            'is_active' => true,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);
        foreach ($permissionKeys as $key) {
            $permission = Permission::query()->firstOrCreate(
                ['key' => $key],
                ['description' => $key, 'group' => 'funding', 'module' => 'Finance'],
            );
            $actor->permissionOverrides()->syncWithoutDetaching([
                $permission->id => ['allowed' => true],
            ]);
        }

        return $actor;
    }

    /** @return array<string, mixed> */
    private function claimPayload(array $source, array $overrides = [], bool $includeDeliveryId = true): array
    {
        $item = [
            ...($includeDeliveryId ? ['billing_entry_id' => $source['entry']->id] : []),
            'description' => $source['line']->description,
            'quantity' => '2.00',
            'unit_price' => '75.25',
            'service_date' => $source['serviceDate']->toDateString(),
            'funding_contract_reference' => $source['line']->funding_contract_reference,
        ];

        return array_replace([
            'service_agreement_id' => $source['agreement']->id,
            'client_id' => $source['client']->id,
            'claim_reference' => 'FC-'.Str::upper(Str::random(8)),
            'client_request_uuid' => (string) Str::uuid(),
            'period_start' => $source['serviceDate']->copy()->startOfMonth()->toDateString(),
            'period_end' => $source['serviceDate']->copy()->endOfMonth()->toDateString(),
            'items' => [$item],
        ], $overrides);
    }

    /**
     * @param  array<int, array<string, mixed>>  $commands
     * @return array<int, string>
     */
    private function concurrentMonetisationRound(int $billingEntryId, array $commands): array
    {
        $connection = DB::connection();
        $database = $connection->getDatabaseName();
        $token = (string) Str::uuid();
        $releasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR."fund-bind-release-{$token}";
        $readyPaths = [];
        $attemptPaths = [];
        $processes = [];

        $connection->beginTransaction();
        BillingEntry::query()->whereKey($billingEntryId)->lockForUpdate()->firstOrFail();

        try {
            foreach ($commands as $index => $command) {
                $readyPaths[$index] = sys_get_temp_dir().DIRECTORY_SEPARATOR."fund-bind-ready-{$index}-{$token}";
                $attemptPaths[$index] = sys_get_temp_dir().DIRECTORY_SEPARATOR."fund-bind-attempt-{$index}-{$token}";
                $processes[] = $this->startMonetisationWorker(
                    $database,
                    $command,
                    $readyPaths[$index],
                    $attemptPaths[$index],
                    $releasePath,
                );
            }

            $this->waitForWorkerFiles($readyPaths, 'Funding workers did not become ready.');
            touch($releasePath);
            $this->waitForWorkerFiles($attemptPaths, 'Funding workers did not reach the monetisation command.');
            usleep(250_000);
            foreach ($processes as $process) {
                $this->assertTrue(
                    $process->isRunning(),
                    trim($process->getErrorOutput()) ?: 'A funding worker exited before the delivery lock was released.',
                );
            }

            $connection->commit();
            $statuses = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue(
                    $process->isSuccessful(),
                    trim($process->getErrorOutput()) ?: 'A funding concurrency worker failed.',
                );
                $statuses[] = json_decode(
                    trim($process->getOutput()),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                )['status'];
            }

            return $statuses;
        } finally {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach ([...$readyPaths, ...$attemptPaths, $releasePath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    /** @param array<string, mixed> $command */
    private function startMonetisationWorker(
        string $database,
        array $command,
        string $readyPath,
        string $attemptPath,
        string $releasePath,
    ): Process {
        $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$command = json_decode(base64_decode($argv[2], true), true, flags: JSON_THROW_ON_ERROR);
file_put_contents($argv[3], (string) Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
$deadline = microtime(true) + 15;
while (! is_file($argv[5])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the funding concurrency release barrier.');
    }
    usleep(10_000);
}
file_put_contents($argv[4], 'attempting');
try {
    if ($command['action'] === 'claim') {
        $actor = App\Models\User::query()->findOrFail((int) $command['actor_id']);
        $app->make(App\Services\Operations\FundingClaimService::class)
            ->createDraft($actor, $command['payload']);
        $status = 'claim';
    } elseif ($command['action'] === 'invoice') {
        $app->make(App\Services\Operations\BillingService::class)->generateInvoice(
            [(int) $command['billing_entry_id']],
            (int) $command['actor_id'],
        );
        $status = 'invoice';
    } else {
        throw new RuntimeException('Unknown funding concurrency command.');
    }
} catch (Illuminate\Validation\ValidationException|Symfony\Component\HttpKernel\Exception\HttpException $exception) {
    $status = 'rejected';
}
echo json_encode(['status' => $status], JSON_THROW_ON_ERROR);
PHP;

        $process = new Process(
            [
                PHP_BINARY,
                '-r',
                $worker,
                base_path(),
                base64_encode(json_encode($command, JSON_THROW_ON_ERROR)),
                $readyPath,
                $attemptPath,
                $releasePath,
            ],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'mysql',
                'DB_DATABASE' => $database,
                'QUEUE_CONNECTION' => 'sync',
            ],
        );
        $process->setTimeout(30);
        $process->start();

        return $process;
    }

    /** @param array<int, string> $paths */
    private function waitForWorkerFiles(array $paths, string $message): void
    {
        $deadline = microtime(true) + 15;
        while (collect($paths)->contains(fn (string $path): bool => ! is_file($path))) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException($message);
            }
            usleep(10_000);
        }
    }

    private function cleanupCommittedFundingFixtures(): void
    {
        DB::table('funding_claim_items')->delete();
        DB::table('funding_claims')->delete();
        DB::table('fin_invoice_lines')->delete();
        DB::table('fin_invoices')->delete();
        DB::table('billing_entries')->delete();
        DB::table('timesheet_client_allocations')->delete();
        DB::table('timesheets')->delete();
        DB::table('shifts')->delete();
        DB::table('service_agreement_rates')->delete();
        DB::table('service_agreement_line_items')->delete();
        DB::table('service_agreements')->delete();
        DB::table('audit_logs')->delete();
        DB::table('permission_user')->delete();
        DB::table('role_user')->delete();
        DB::table('permissions')
            ->whereNotIn('key', ['funding.viewAllSites', 'funding.claims.retryPosting'])
            ->delete();
        DB::table('hr_employee_profiles')->delete();
        DB::table('clients')->delete();
        DB::table('users')->delete();
        DB::table('sites')->delete();
    }
}
