<?php

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Models\ConsentTypeVersion;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Consents\ConsentEvidenceMalwareScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function grantConsentEvidencePermissions(User $user, array $permissionKeys): void
{
    $role = Role::query()->create([
        'name' => 'consent_evidence_'.$user->id,
        'label' => 'Consent Evidence Test',
        'level' => 50,
        'type' => 'custom',
    ]);

    foreach ($permissionKeys as $key) {
        Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'test', 'module' => 'Test'],
        );
    }

    $role->permissions()->sync(
        Permission::query()->whereIn('key', $permissionKeys)->pluck('id')->all(),
    );
    $user->roles()->syncWithoutDetaching([$role->id]);
}

function makeConsentEvidenceActor(Site $site, array $permissions): User
{
    $actor = User::factory()->create(['role' => 'manager']);
    grantConsentEvidencePermissions($actor, $permissions);
    ensureCanonicalHrStaffProfile($actor, $site);

    return $actor;
}

function bindConsentEvidenceScanner(string $disposition, int $times = 1): void
{
    $scanner = Mockery::mock(ConsentEvidenceMalwareScanner::class);
    $scanner->shouldReceive('scan')->times($times)->andReturn([
        'disposition' => $disposition,
        'scanner' => 'test-scanner',
    ]);
    app()->instance(ConsentEvidenceMalwareScanner::class, $scanner);
}

function fakeConsentPdf(string $name = 'signed-consent.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $name,
        "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF",
    );
}

/** @return array{actor: User, client: Client, site: Site, type: ConsentType} */
function makeConsentEvidenceContext(array $permissions = []): array
{
    $site = Site::factory()->create([
        'is_active' => true,
        'archived' => false,
    ]);
    $actor = makeConsentEvidenceActor($site, $permissions ?: [
        'clients.viewAny',
        'consents.viewAny',
        'consents.manage',
        'consents.record',
    ]);
    $client = Client::factory()->create(['site_id' => $site->id]);
    $type = ConsentType::factory()->create([
        'active' => true,
        'requires_capacity_assessment' => false,
    ]);

    return compact('actor', 'client', 'site', 'type');
}

/** @param array{actor: User, client: Client, site: Site, type: ConsentType} $context */
function consentEvidenceStorePayload(array $context, array $overrides = []): array
{
    return [
        'consent_type_id' => $context['type']->id,
        'status' => 'refused',
        'given_method' => 'written',
        'given_at' => now()->subMinute()->toDateTimeString(),
        'refusal_reason' => 'The client declined after the information was explained.',
        'signed_document' => fakeConsentPdf(),
        ...$overrides,
    ];
}

function makeStoredConsentEvidence(
    Client $client,
    ConsentType $type,
    User $actor,
    array $overrides = [],
): ClientConsent {
    $path = 'consent-evidence/'.Str::uuid().'.pdf';
    Storage::disk('private')->put($path, "%PDF-1.4\n%%EOF");

    return ClientConsent::query()->create([
        'client_id' => $client->id,
        'site_id' => $client->site_id,
        'consent_type_id' => $type->id,
        'status' => 'given',
        'given_at' => now()->subDay(),
        'given_by_user_id' => $actor->id,
        'given_method' => 'written',
        'created_by' => $actor->id,
        'signed_document_path' => $path,
        'signed_document_disk' => 'private',
        'signed_document_original_name' => 'signed-consent.pdf',
        'signed_document_mime_type' => 'application/pdf',
        'signed_document_size_bytes' => Storage::disk('private')->size($path),
        'signed_document_sha256' => hash('sha256', "%PDF-1.4\n%%EOF"),
        'signed_document_malware_disposition' => 'clean',
        'signed_document_scanner' => 'test-scanner',
        'signed_document_scanned_at' => now()->subMinute(),
        'signed_document_uploaded_by_user_id' => $actor->id,
        ...$overrides,
    ]);
}

beforeEach(function (): void {
    Storage::fake('private');
    Storage::fake('public');
});

it('stores scanned consent evidence privately under an opaque name and records its disposition', function (): void {
    $context = makeConsentEvidenceContext();
    bindConsentEvidenceScanner('clean');

    $this->actingAs($context['actor'])
        ->post(
            route('operations.clients.consents.store', $context['client'], false),
            consentEvidenceStorePayload($context, [
                'signed_document' => fakeConsentPdf('Resident Full Name signed form.pdf'),
            ]),
        )
        ->assertRedirect();

    $consent = ClientConsent::query()->sole();

    expect($consent->signed_document_path)
        ->toMatch('/^consent-evidence\/[0-9a-f-]{36}\.pdf$/')
        ->not->toContain('Resident Full Name')
        ->and($consent->signed_document_disk)->toBe('private')
        ->and($consent->signed_document_malware_disposition)->toBe('clean')
        ->and($consent->signed_document_scanner)->toBe('test-scanner')
        ->and($consent->signed_document_sha256)->toHaveLength(64)
        ->and($consent->signed_document_command_sha256)->toHaveLength(64)
        ->and($consent->signed_document_original_name)->toBe('Resident Full Name signed form.pdf')
        ->and($consent->toArray())->not->toHaveKeys([
            'signed_document_path',
            'signed_document_disk',
            'signed_document_sha256',
            'signed_document_command_sha256',
        ]);

    Storage::disk('private')->assertExists($consent->signed_document_path);
    expect(Storage::disk('public')->allFiles())->toBe([]);
    // Laravel's generic served-local-disk endpoint rejects every unsigned
    // non-production request before checking whether the object exists.
    $this->get('/storage/'.$consent->signed_document_path)->assertForbidden();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'consents.evidence.attached',
        'auditable_type' => $consent->getMorphClass(),
        'auditable_id' => $consent->id,
        'client_id' => $context['client']->id,
    ]);
});

it('converges an exact evidence replay without a second consent file or audit effect', function (): void {
    $context = makeConsentEvidenceContext();
    bindConsentEvidenceScanner('clean', 2);
    $payload = consentEvidenceStorePayload($context, [
        'given_at' => now()->subMinute()->startOfSecond()->toDateTimeString(),
        'signed_document' => fakeConsentPdf('same-signed-consent.pdf'),
    ]);

    $this->actingAs($context['actor'])
        ->post(route('operations.clients.consents.store', $context['client'], false), $payload)
        ->assertRedirect();

    $payload['signed_document'] = fakeConsentPdf('same-signed-consent.pdf');
    $this->actingAs($context['actor'])
        ->post(route('operations.clients.consents.store', $context['client'], false), $payload)
        ->assertRedirect();

    expect(ClientConsent::query()->count())->toBe(1)
        ->and(Storage::disk('private')->allFiles())->toHaveCount(1)
        ->and(AuditLog::query()->where('action', 'consents.evidence.attached')->count())->toBe(1);
});

it('rejects disallowed content before scanning or creating a consent', function (): void {
    $context = makeConsentEvidenceContext();
    $scanner = Mockery::mock(ConsentEvidenceMalwareScanner::class);
    $scanner->shouldNotReceive('scan');
    app()->instance(ConsentEvidenceMalwareScanner::class, $scanner);

    $this->actingAs($context['actor'])
        ->post(
            route('operations.clients.consents.store', $context['client'], false),
            consentEvidenceStorePayload($context, [
                'signed_document' => UploadedFile::fake()->create('payload.exe', 10, 'application/x-dosexec'),
            ]),
        )
        ->assertSessionHasErrors('signed_document');

    expect(ClientConsent::query()->count())->toBe(0)
        ->and(Storage::disk('private')->allFiles())->toBe([])
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('rejects an oversized signed document before scanning', function (): void {
    $context = makeConsentEvidenceContext();
    $scanner = Mockery::mock(ConsentEvidenceMalwareScanner::class);
    $scanner->shouldNotReceive('scan');
    app()->instance(ConsentEvidenceMalwareScanner::class, $scanner);

    $this->actingAs($context['actor'])
        ->post(
            route('operations.clients.consents.store', $context['client'], false),
            consentEvidenceStorePayload($context, [
                'signed_document' => UploadedFile::fake()->create(
                    'too-large.pdf',
                    10 * 1024 + 1,
                    'application/pdf',
                ),
            ]),
        )
        ->assertSessionHasErrors('signed_document');

    expect(ClientConsent::query()->count())->toBe(0)
        ->and(Storage::disk('private')->allFiles())->toBe([]);
});

it('rejects allowed MIME metadata when the scanned file content is incomplete', function (): void {
    $context = makeConsentEvidenceContext();
    bindConsentEvidenceScanner('clean');

    $this->actingAs($context['actor'])
        ->post(
            route('operations.clients.consents.store', $context['client'], false),
            consentEvidenceStorePayload($context, [
                'signed_document' => UploadedFile::fake()->createWithContent(
                    'incomplete.pdf',
                    "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj",
                ),
            ]),
        )
        ->assertSessionHasErrors('signed_document');

    expect(ClientConsent::query()->count())->toBe(0)
        ->and(Storage::disk('private')->allFiles())->toBe([]);
});

it('fails closed for infected or unavailable malware dispositions without partial state', function (string $disposition): void {
    $context = makeConsentEvidenceContext();
    bindConsentEvidenceScanner($disposition);

    $this->actingAs($context['actor'])
        ->post(
            route('operations.clients.consents.store', $context['client'], false),
            consentEvidenceStorePayload($context),
        )
        ->assertSessionHasErrors('signed_document');

    expect(ClientConsent::query()->count())->toBe(0)
        ->and(ConsentTypeVersion::query()->count())->toBe(0)
        ->and(Storage::disk('private')->allFiles())->toBe([])
        ->and(AuditLog::query()->where('action', 'consents.evidence.attached')->count())->toBe(0);
})->with(['infected', 'unavailable']);

it('rejects evidence whose bytes change while the malware check is running', function (): void {
    $context = makeConsentEvidenceContext();
    $scanner = Mockery::mock(ConsentEvidenceMalwareScanner::class);
    $scanner->shouldReceive('scan')->once()->andReturnUsing(function (UploadedFile $file): array {
        file_put_contents($file->getRealPath(), "%PDF-1.5\naltered after scan started\n%%EOF");

        return ['disposition' => 'clean', 'scanner' => 'test-scanner'];
    });
    app()->instance(ConsentEvidenceMalwareScanner::class, $scanner);

    $this->actingAs($context['actor'])
        ->post(
            route('operations.clients.consents.store', $context['client'], false),
            consentEvidenceStorePayload($context),
        )
        ->assertSessionHasErrors('signed_document');

    expect(ClientConsent::query()->count())->toBe(0)
        ->and(Storage::disk('private')->allFiles())->toBe([])
        ->and(AuditLog::query()->where('action', 'consents.evidence.attached')->count())->toBe(0);
});

it('discards the prepared private object when the consent transaction is rejected', function (): void {
    $context = makeConsentEvidenceContext();
    bindConsentEvidenceScanner('clean');

    $this->actingAs($context['actor'])
        ->post(
            route('operations.clients.consents.store', $context['client'], false),
            consentEvidenceStorePayload($context, [
                'status' => 'given',
                'given_by_relationship' => 'staff',
            ]),
        )
        ->assertSessionHasErrors('status');

    expect(ClientConsent::query()->count())->toBe(0)
        ->and(ConsentTypeVersion::query()->count())->toBe(0)
        ->and(Storage::disk('private')->allFiles())->toBe([])
        ->and(AuditLog::query()->where('action', 'consents.evidence.attached')->count())->toBe(0);
});

it('requires authentication and the consent evidence action capability', function (): void {
    $context = makeConsentEvidenceContext();
    $consent = makeStoredConsentEvidence(
        $context['client'],
        $context['type'],
        $context['actor'],
    );
    $url = route('operations.clients.consents.evidence.download', [$context['client'], $consent], false);

    $this->get($url)->assertRedirect('/login');

    $viewer = makeConsentEvidenceActor($context['site'], [
        'clients.viewAny',
        'consents.viewAny',
        'sites.viewAll',
    ]);
    $this->actingAs($viewer)->get($url)->assertForbidden();

    expect(AuditLog::query()->where('action', 'consents.evidence.downloaded')->count())->toBe(0);
});

it('checks the store action after canonical Client scope and before scanning', function (): void {
    $context = makeConsentEvidenceContext();
    $scanner = Mockery::mock(ConsentEvidenceMalwareScanner::class);
    $scanner->shouldNotReceive('scan');
    app()->instance(ConsentEvidenceMalwareScanner::class, $scanner);
    $storeUrl = route('operations.clients.consents.store', $context['client'], false);

    $this->post($storeUrl, consentEvidenceStorePayload($context))
        ->assertRedirect('/login');

    $viewer = makeConsentEvidenceActor($context['site'], [
        'clients.viewAny',
        'consents.viewAny',
    ]);
    $this->actingAs($viewer)
        ->post($storeUrl, consentEvidenceStorePayload($context))
        ->assertForbidden();

    expect(ClientConsent::query()->count())->toBe(0)
        ->and(Storage::disk('private')->allFiles())->toBe([])
        ->and(AuditLog::query()->where('action', 'consents.evidence.attached')->count())->toBe(0);
});

it('conceals a foreign Site store target before scanning or mutation', function (): void {
    $context = makeConsentEvidenceContext();
    $foreignSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);
    $scanner = Mockery::mock(ConsentEvidenceMalwareScanner::class);
    $scanner->shouldNotReceive('scan');
    app()->instance(ConsentEvidenceMalwareScanner::class, $scanner);

    $this->actingAs($context['actor'])
        ->post(
            route('operations.clients.consents.store', $foreignClient, false),
            consentEvidenceStorePayload($context),
        )
        ->assertNotFound();

    expect(ClientConsent::query()->count())->toBe(0)
        ->and(Storage::disk('private')->allFiles())->toBe([])
        ->and(AuditLog::query()->where('action', 'consents.evidence.attached')->count())->toBe(0);
});

it('conceals wrong-site and wrong-client direct objects without an access audit', function (): void {
    $context = makeConsentEvidenceContext();
    $sameSiteClient = Client::factory()->create(['site_id' => $context['site']->id]);
    $sameSiteConsent = makeStoredConsentEvidence(
        $sameSiteClient,
        $context['type'],
        $context['actor'],
    );
    $foreignSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);
    $foreignConsent = makeStoredConsentEvidence(
        $foreignClient,
        $context['type'],
        $context['actor'],
    );

    $this->actingAs($context['actor'])
        ->get(route(
            'operations.clients.consents.evidence.download',
            [$context['client'], $sameSiteConsent],
            false,
        ))
        ->assertNotFound();
    $this->actingAs($context['actor'])
        ->get(route(
            'operations.clients.consents.evidence.download',
            [$foreignClient, $foreignConsent],
            false,
        ))
        ->assertNotFound();

    expect(AuditLog::query()->where('action', 'consents.evidence.downloaded')->count())->toBe(0);
});

it('allows the explicit global Site role only when paired with the consent evidence capability', function (): void {
    $localSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $foreignSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = makeConsentEvidenceActor($localSite, [
        'clients.viewAny',
        'consents.manage',
        'consents.viewAny',
        'sites.viewAll',
    ]);
    $client = Client::factory()->create(['site_id' => $foreignSite->id]);
    $type = ConsentType::factory()->create();
    $consent = makeStoredConsentEvidence($client, $type, $actor);

    $response = $this->actingAs($actor)
        ->get(route('operations.clients.consents.evidence.download', [$client, $consent], false))
        ->assertOk()
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox; frame-ancestors 'none'");

    $cacheControlDirectives = array_map(
        'trim',
        explode(',', (string) $response->headers->get('Cache-Control')),
    );
    sort($cacheControlDirectives);

    expect($cacheControlDirectives)->toBe(['max-age=0', 'no-store', 'private']);

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $actor->id,
        'client_id' => $client->id,
        'action' => 'consents.evidence.downloaded',
        'auditable_id' => $consent->id,
    ]);
});

it('fails closed when private evidence bytes no longer match their recorded digest', function (): void {
    $context = makeConsentEvidenceContext();
    $consent = makeStoredConsentEvidence(
        $context['client'],
        $context['type'],
        $context['actor'],
    );
    Storage::disk('private')->put($consent->signed_document_path, "%PDF-1.5\n%%EOF");

    $this->actingAs($context['actor'])
        ->get(route(
            'operations.clients.consents.evidence.download',
            [$context['client'], $consent],
            false,
        ))
        ->assertNotFound();

    expect(AuditLog::query()->where('action', 'consents.evidence.downloaded')->count())->toBe(0);
});

it('rejects traversal metadata without reading or deleting another private object', function (): void {
    $context = makeConsentEvidenceContext();
    Storage::disk('private')->put('secret.pdf', "%PDF-1.4\nprivate\n%%EOF");
    $consent = makeStoredConsentEvidence(
        $context['client'],
        $context['type'],
        $context['actor'],
        ['signed_document_path' => 'consent-evidence/../secret.pdf'],
    );

    $this->actingAs($context['actor'])
        ->get(route(
            'operations.clients.consents.evidence.download',
            [$context['client'], $consent],
            false,
        ))
        ->assertNotFound();

    Storage::disk('private')->assertExists('secret.pdf');
    expect(AuditLog::query()->where('action', 'consents.evidence.downloaded')->count())->toBe(0);
});

it('moves legacy public paths into an opaque private unverified inventory resumably', function (): void {
    $context = makeConsentEvidenceContext();
    $legacyPath = 'consent-documents/'.$context['client']->id.'/legacy-signed-form.pdf';
    Storage::disk('public')->put($legacyPath, "%PDF-1.4\nlegacy\n%%EOF");
    $consent = ClientConsent::query()->create([
        'client_id' => $context['client']->id,
        'site_id' => $context['site']->id,
        'consent_type_id' => $context['type']->id,
        'status' => 'given',
        'given_at' => now()->subYear(),
        'given_by_user_id' => $context['actor']->id,
        'given_method' => 'written',
        'created_by' => $context['actor']->id,
        'signed_document_path' => $legacyPath,
    ]);

    $migration = require base_path('database/migrations/2026_08_23_000260_govern_client_consent_evidence.php');
    $migration->up();
    $migration->up();

    $consent->refresh();
    expect($consent->signed_document_path)
        ->toMatch('/^consent-evidence\/legacy\/[0-9a-f]{64}\.pdf$/')
        ->and($consent->signed_document_disk)->toBe('private')
        ->and($consent->signed_document_sha256)->toHaveLength(64)
        ->and($consent->signed_document_malware_disposition)->toBe('legacy_unverified')
        ->and($consent->hasDownloadableSignedDocument())->toBeFalse();
    Storage::disk('public')->assertMissing($legacyPath);
    Storage::disk('private')->assertExists($consent->signed_document_path);

    $this->actingAs($context['actor'])
        ->get(route('operations.clients.consents.evidence.download', [$context['client'], $consent], false))
        ->assertNotFound();
    expect(AuditLog::query()->where('action', 'consents.evidence.downloaded')->count())->toBe(0);
});

it('preserves both legacy objects when a resumed quarantine finds a digest conflict', function (): void {
    $context = makeConsentEvidenceContext();
    $legacyPath = 'consent-documents/'.$context['client']->id.'/conflicting-form.pdf';
    $consent = ClientConsent::query()->create([
        'client_id' => $context['client']->id,
        'site_id' => $context['site']->id,
        'consent_type_id' => $context['type']->id,
        'status' => 'given',
        'given_at' => now()->subYear(),
        'given_by_user_id' => $context['actor']->id,
        'given_method' => 'written',
        'created_by' => $context['actor']->id,
        'signed_document_path' => $legacyPath,
    ]);
    $token = hash('sha256', "legacy-consent-evidence-v1|{$consent->id}|{$legacyPath}");
    $privatePath = "consent-evidence/legacy/{$token}.pdf";
    Storage::disk('public')->put($legacyPath, "%PDF-1.4\nsource!\n%%EOF");
    Storage::disk('private')->put($privatePath, "%PDF-1.4\ntarget!\n%%EOF");

    $migration = require base_path('database/migrations/2026_08_23_000260_govern_client_consent_evidence.php');
    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, "Consent {$consent->id} legacy evidence quarantine is inconsistent.");

    Storage::disk('public')->assertExists($legacyPath);
    Storage::disk('private')->assertExists($privatePath);
    expect($consent->fresh()->signed_document_disk)->toBeNull();
});

it('keeps soft-deleted evidence retained while direct recovery stays concealed', function (): void {
    $context = makeConsentEvidenceContext();
    $consent = makeStoredConsentEvidence(
        $context['client'],
        $context['type'],
        $context['actor'],
    );
    $path = $consent->signed_document_path;
    $consent->delete();

    $this->actingAs($context['actor'])
        ->get(route(
            'operations.clients.consents.evidence.download',
            [$context['client'], $consent->id],
            false,
        ))
        ->assertNotFound();

    Storage::disk('private')->assertExists($path);
    expect(AuditLog::query()->where('action', 'consents.evidence.downloaded')->count())->toBe(0);
});

it('retains revoked consent as manager-only history but denies expired disposed and unverified evidence', function (): void {
    $context = makeConsentEvidenceContext();
    $revoked = makeStoredConsentEvidence(
        $context['client'],
        $context['type'],
        $context['actor'],
        ['status' => 'revoked'],
    );

    $this->actingAs($context['actor'])
        ->get(route('operations.clients.consents.evidence.download', [$context['client'], $revoked], false))
        ->assertOk();

    foreach ([
        ['signed_document_retained_until' => now()->subSecond()],
        ['signed_document_disposed_at' => now()->subSecond()],
        ['signed_document_malware_disposition' => 'legacy_unverified'],
    ] as $overrides) {
        $consent = makeStoredConsentEvidence(
            $context['client'],
            $context['type'],
            $context['actor'],
            $overrides,
        );

        $this->actingAs($context['actor'])
            ->get(route('operations.clients.consents.evidence.download', [$context['client'], $consent], false))
            ->assertNotFound();
    }

    expect(AuditLog::query()->where('action', 'consents.evidence.downloaded')->count())->toBe(1);
});
