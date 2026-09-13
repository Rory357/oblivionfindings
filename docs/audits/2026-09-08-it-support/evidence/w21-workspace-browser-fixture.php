<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItChangeService;
use App\Domain\It\Services\ItKbAccessService;
use App\Domain\It\Services\ItKbLifecycleService;
use App\Domain\It\Services\ItKbRevisionService;
use App\Domain\It\Services\ItMajorIncidentService;
use App\Domain\It\Services\ItProblemService;
use App\Models\ItKbArticle;
use App\Models\ItService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Invoked only by the fingerprinted bootstrap of a new disposable schema. */
function w21BrowserCreateWorkspaceFixtures(array $context, array $fixtures): array
{
    w06BrowserRequire(($context['workspace_fixtures'] ?? false)
        && DB::scalar('SELECT DATABASE()') === 'oblivion_it_draft_browser_'.$context['token']
        && ItKbArticle::query()->count() === 0
        && app(ItKbRevisionService::class)->ready(), 'Workspace fixture scope or revision setup differs.');

    w22BrowserCreateUploadFixtures($context);

    return DB::transaction(function () use ($context, $fixtures): array {
        $source = file_get_contents(base_path('tests/e2e/helpers.ts'));
        w06BrowserRequire(preg_match("/password = '([^']+)'/", $source, $match) === 1, 'Repository synthetic login convention is unavailable.');
        $password = $match[1];
        unset($source, $match);
        $actors = [];
        foreach (['author' => ItKbAccessService::AUTHOR, 'reviewer' => ItKbAccessService::REVIEW] as $key => $grant) {
            $role = Role::query()->create(['name' => 'w21-workspace-'.$key, 'label' => 'Synthetic Knowledge '.$key, 'type' => 'custom', 'level' => 10]);
            $role->permissions()->attach(Permission::query()->where('key', $grant)->firstOrFail()->id);
            $actor = User::factory()->withoutTwoFactor()->create([
                'name' => 'W21 '.$context['token'].' synthetic '.$key, 'email' => 'w21-'.$key.'@demo.test',
                'password' => $password, 'role' => 'support_worker', 'approved_at' => now(),
                'email_verified_at' => now(), 'remember_token' => null, 'landing_route_preference' => '/it/knowledge',
            ]);
            $actor->roles()->sync([$role->id]);
            HrEmployeeProfile::factory()->create([
                'user_id' => $actor->id, 'primary_site_id' => $fixtures['sites']['a'],
                'secondary_site_ids' => [$fixtures['sites']['b']], 'is_active' => true,
                'start_date' => today()->subMonth(), 'end_date' => null,
            ]);
            $actor = $actor->fresh();
            w06BrowserRequire($actor->canDo($grant) && ! $actor->canDo('it.manage') && ! $actor->canDo('it.view')
                && ! $actor->canDo($key === 'author' ? ItKbAccessService::REVIEW : ItKbAccessService::AUTHOR), 'Synthetic Knowledge duties differ.');
            $actors[$key] = $actor;
        }
        unset($password);

        $service = ItService::factory()->create(['key' => 'w21-synthetic-recovery', 'name' => 'Synthetic recovery service', 'is_active' => true]);
        $lifecycle = app(ItKbLifecycleService::class);
        $base = [
            'category' => 'network', 'body' => 'Synthetic instructions for disposable browser verification.',
            'document_type' => 'runbook', 'structured_content' => ['procedure' => 'Record the simulated checks.', 'verification' => 'Confirm the synthetic service state.'],
            'audience' => 'specific_sites', 'site_scope' => [$fixtures['sites']['a']], 'owner_user_id' => $actors['author']->id,
            'related_service_id' => $service->id, 'related_records' => [['type' => 'service', 'id' => $service->id, 'relation' => 'recovery_for']],
            'review_due_at' => today()->addMonth()->toDateString(),
        ];
        $publication = $lifecycle->create($actors['author'], ['title' => 'W21 synthetic reviewed recovery runbook', ...$base]);
        $lifecycle->submitForReview($publication, $actors['author'], ['lock_version' => $publication->fresh()->lock_version]);
        $publication = $lifecycle->publish($publication, $actors['reviewer'], ['lock_version' => $publication->fresh()->lock_version]);
        $lifecycle->update($publication, $actors['author'], ['lock_version' => $publication->lock_version, 'body' => 'Synthetic proposed recovery instructions awaiting a separate review.']);
        $documents = [];
        foreach (range(1, 26) as $number) {
            $document = $lifecycle->create($actors['author'], [
                ...$base, 'title' => sprintf('W21 synthetic system record %02d', $number),
                'document_type' => $number % 2 === 0 ? 'system' : 'software',
                'structured_content' => $number % 2 === 0 ? ['system_purpose' => 'Disposable verification system.'] : ['software_version' => 'Synthetic 1.0'],
            ]);
            $documents[] = $document->id;
        }

        $tech = User::query()->findOrFail($fixtures['actors']['tech']['id']);
        $common = ['description' => 'Disposable browser verification only.', 'category' => 'network', 'priority' => 'high',
            'site_id' => $fixtures['sites']['a'], 'is_organisation_wide' => false, 'impact_summary' => 'Synthetic service interruption.'];
        $problem = app(ItProblemService::class)->create($tech, ['title' => 'W21 synthetic recurring connection problem', ...$common]);
        $change = app(ItChangeService::class)->create($tech, ['title' => 'W21 synthetic gateway change', ...$common, 'change_type' => 'standard', 'risk_level' => 'low']);
        $major = app(ItMajorIncidentService::class)->create($tech, ['title' => 'W21 synthetic major service interruption', ...$common,
            'severity' => 'sev2', 'target_update_minutes' => 30, 'communications_lead_user_id' => $fixtures['actors']['cover']['id']]);

        return [
            'actors' => array_map(fn (User $actor) => ['id' => $actor->id, 'login' => $actor->email], $actors),
            'publication_id' => $publication->id, 'document_ids' => $documents, 'service_id' => $service->id,
            'problem' => ['id' => $problem->id, 'ticket_id' => $problem->ticket_id],
            'change' => ['id' => $change->id, 'ticket_id' => $change->ticket_id],
            'major_incident' => ['id' => $major->id, 'ticket_id' => $major->ticket_id],
            'synthetic_only' => true,
        ];
    });
}

/** Exact harmless upload bytes, generated only in a new owned disposable root. */
function w22BrowserCreateUploadFixtures(array $context): void
{
    $root = $context['root'].'/knowledge-upload-inputs';
    w06BrowserRequire(! file_exists($root) && mkdir($root), 'Knowledge input directory already exists.');
    $hashes = [];
    foreach ([1, 2] as $version) {
        $text = "W22 synthetic Knowledge PDF version {$version}";
        $stream = "BT /F1 18 Tf 50 740 Td ({$text}) Tj ET";
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>', '<< /Length '.strlen($stream).' >>'."\nstream\n".$stream."\nendstream"];
        $pdf = "%PDF-1.4\n% W22 synthetic fixture\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
        $path = $root.'/w22-synthetic-'.$version.'.pdf';
        w06BrowserRequire(file_put_contents($path, $pdf) === strlen($pdf), 'Synthetic PDF write failed.');
        $hashes[] = hash_file('sha256', $path);
    }
    $path = $root.'/w22-synthetic.docx';
    $zip = new ZipArchive;
    w06BrowserRequire($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) === true, 'Synthetic Word creation failed.');
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>W22 synthetic Word recovery instructions.</w:t></w:r></w:p><w:p><w:r><w:t>Verify the full-page Word preview and retained original.</w:t></w:r></w:p></w:body></w:document>');
    w06BrowserRequire($zip->close(), 'Synthetic Word archive failed.');
    $hashes[] = hash_file('sha256', $path);
    w06BrowserSaveNew($context['root'].'/knowledge-upload-hashes.json', ['synthetic_only' => true, 'sha256' => $hashes]);
}

/** This binding can only scan the exact generated harmless input hashes. */
function w22BrowserInstallKnowledgeScanner(array $context): void
{
    app()->instance(\App\Services\Files\MalwareScanner::class, new class($context) extends \App\Services\Files\MalwareScanner
    {
        public function __construct(private readonly array $context) {}

        public function scanPath(string $path, array $settings): \App\Services\Files\MalwareScanResult
        {
            w06BrowserRequire(($this->context['workspace_fixtures'] ?? false)
                && DB::scalar('SELECT DATABASE()') === 'oblivion_it_draft_browser_'.$this->context['token'], 'Synthetic Knowledge scanner outside owned schema.');
            $manifest = json_decode(file_get_contents($this->context['root'].'/knowledge-upload-hashes.json'), true, flags: JSON_THROW_ON_ERROR);
            $clean = is_file($path) && in_array(hash_file('sha256', $path), $manifest['sha256'] ?? [], true);

            return new \App\Services\Files\MalwareScanResult($clean ? \App\Services\Files\MalwareScanDisposition::Clean : \App\Services\Files\MalwareScanDisposition::Unavailable, 'synthetic-exact-file-browser-fixture');
        }
    });
}
