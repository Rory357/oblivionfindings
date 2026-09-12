<?php

namespace Tests\Concurrency\It;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItProvisioningTemplateService;
use App\Models\ItProvisioningTemplate;
use App\Models\ItProvisioningTemplateVersion;
use App\Models\ItProvisioningWorkflow;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Run alone through the isolated wrapper: these fixtures are naturally committed. */
final class ItProvisioningTemplateVersionConcurrencyTest extends TestCase
{
    public function createApplication()
    {
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'oblivion_it_support_test'
            || getenv('DB_HOST') !== '127.0.0.1' || preg_match('/^it_[a-f0-9]{16}$/D', (string) getenv('TEST_TOKEN')) !== 1) {
            throw new RuntimeException('Use the isolated IT wrapper for template version concurrency.');
        }

        return parent::createApplication();
    }

    public function test_competing_edits_and_launches_preserve_one_consistent_template_contract(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('oblivion_it_support_test_'.getenv('TEST_TOKEN'), DB::connection()->getDatabaseName());
        Http::preventStrayRequests();
        Notification::fake();
        Queue::fake();
        $this->seed(RbacSeeder::class);
        $actor = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $actor->roles()->sync(Role::where('name', 'admin')->pluck('id'));
        $site = Site::factory()->create();
        $profile = HrEmployeeProfile::factory()->create(['user_id' => $actor->id, 'primary_site_id' => $site->id, 'is_active' => true]);
        $template = app(ItProvisioningTemplateService::class)->create($actor, [
            'name' => 'Synthetic concurrency template', 'description' => 'No external fulfilment', 'lifecycle_type' => 'joiner',
            'position_role' => null, 'site_id' => $site->id, 'employment_type' => null, 'selection_priority' => 0, 'is_active' => true,
            'tasks' => [['task_key' => 'verify', 'title' => 'Original instructions', 'description' => 'Original evidence step',
                'category' => 'account', 'action' => 'verify', 'request_type' => 'account', 'responsible_team_id' => null,
                'stage' => 1, 'sort_order' => 0, 'dependency_task_keys' => [], 'trigger_fields' => [],
                'approval_required' => true, 'evidence_required' => true, 'due_offset_days' => 0, 'fulfiller_fields' => []]],
        ]);
        foreach ([['edit', 'edit'], ['edit', 'launch'], ['launch', 'edit']] as $operations) {
            $template->refresh();
            $before = $template->lock_version;
            $beforeVersions = ItProvisioningTemplateVersion::query()->count();
            $results = $this->race($template, $actor, $profile, $operations);
            $this->assertSame($before + 1, $template->fresh()->lock_version);
            $this->assertSame($beforeVersions + 1, ItProvisioningTemplateVersion::query()->count());
            if ($operations === ['edit', 'edit']) {
                $statuses = array_column($results, 'status');
                sort($statuses);
                $this->assertSame(['committed', 'stale'], $statuses);
            } else {
                $this->assertSame(['committed', 'committed'], array_column($results, 'status'));
                $result = collect($results)->firstWhere('operation', 'launch');
                $workflow = ItProvisioningWorkflow::query()->findOrFail($result['workflow_id']);
                $version = $workflow->templateVersion;
                $request = $workflow->requests()->sole();
                $this->assertContains($version->version, [$before, $before + 1]);
                $this->assertSame($version->contract['tasks'][0]['title'], $request->item);
                $this->assertSame($version->contract['tasks'][0]['description'], $request->notes);
                $this->assertTrue($request->approval_required);
                $this->assertTrue($request->evidence_required);
            }
        }
        foreach (ItProvisioningWorkflow::query()->with(['templateVersion', 'requests'])->get() as $workflow) {
            $this->assertSame($workflow->templateVersion->contract['tasks'][0]['title'], $workflow->requests->sole()->item);
        }
    }

    private function race(ItProvisioningTemplate $template, User $actor, HrEmployeeProfile $profile, array $operations): array
    {
        $prefix = storage_path('framework/testing/'.getenv('TEST_TOKEN').'-template-version-'.Str::uuid());
        $paths = [$prefix.'-0.ready', $prefix.'-1.ready'];
        $workers = [];
        DB::beginTransaction();
        ItProvisioningTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
        try {
            foreach ($operations as $index => $operation) {
                $workers[] = $worker = new Process([PHP_BINARY, base_path('tests/Support/It/provisioning-template-version-worker.php'),
                    $operation, (string) $template->id, (string) $actor->id, (string) $profile->id, (string) $template->lock_version,
                    $index === 0 ? 'first' : 'second', $paths[$index]], base_path(), timeout: 45);
                $worker->start();
            }
            $deadline = microtime(true) + 30;
            while (count(array_filter($paths, 'is_file')) !== 2) {
                foreach ($workers as $worker) {
                    if (! $worker->isRunning()) {
                        throw new RuntimeException('Template worker stopped before the lock barrier: '.trim($worker->getErrorOutput()));
                    }
                }
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Template lock barrier timed out.');
                }
                usleep(10_000);
            }
            usleep(250_000);
            foreach ($workers as $worker) {
                $this->assertTrue($worker->isRunning(), 'Both workers must reach the held template lock.');
            }
            $releasedAt = microtime(true);
            DB::commit();
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), trim($worker->getErrorOutput()));
                $result = json_decode(trim($worker->getOutput()), true, flags: JSON_THROW_ON_ERROR);
                $this->assertTrue($result['waiting']);
                $this->assertSame(0, $result['transaction_level']);
                $this->assertGreaterThanOrEqual($releasedAt, $result['completed_at']);
                $results[] = $result;
            }

            return $results;
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
