<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItCatalogManagementService;
use App\Domain\It\Services\ItProvisioningTemplateService;
use App\Domain\It\Services\ItProvisioningWorkflowService;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\ItCatalogItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Synthetic-only setup, invoked by the fingerprinted owned browser bootstrap. */
function w15BrowserCreateCatalogueFixtures(array $context, array $fixtures): array
{
    w06BrowserRequire(($context['catalogue_fixtures'] ?? false)
        && DB::scalar('SELECT DATABASE()') === 'oblivion_it_draft_browser_'.$context['token'], 'Catalogue fixture scope mismatch.');

    return DB::transaction(function () use ($context, $fixtures): array {
        $actor = User::query()->findOrFail($fixtures['actors']['tech']['id']);
        w06BrowserRequire($actor->email === 'w06-tech@demo.test' && $actor->canDo('it.manage'), 'Expected synthetic catalogue author unavailable.');
        $management = app(ItCatalogManagementService::class);
        $items = [];
        foreach (['approval', 'service', 'draft', 'versioned', 'entities'] as $case) {
            $data = [
                'it_service_id' => null,
                'name' => 'W15 '.$context['token'].' synthetic '.$case.' request',
                'description' => 'Isolated catalogue verification only. No external fulfilment or communication.',
                'outcome_type' => $case === 'approval' ? 'provisioning' : 'service_request',
                'category' => 'hardware', 'provisioning_type' => $case === 'approval' ? 'equipment' : null,
                'default_priority' => 'normal', 'requires_approval' => $case === 'approval', 'internal_only' => false,
                'search_terms' => ['synthetic', $case], 'sort_order' => 0,
                'form_schema' => ['fields' => [[
                    'key' => 'details', 'label' => 'What do you need?', 'type' => 'textarea',
                    'required' => true, 'visibility' => 'requester', 'max' => 2000,
                ]]],
            ];
            if ($case === 'entities') {
                $data['form_schema']['fields'] = [
                    ['key' => 'employee', 'label' => 'Employee', 'type' => 'employee', 'required' => true, 'visibility' => 'requester'],
                    ['key' => 'user', 'label' => 'User', 'type' => 'user', 'required' => true, 'visibility' => 'requester'],
                    ['key' => 'asset', 'label' => 'Equipment', 'type' => 'asset', 'required' => true, 'visibility' => 'requester'],
                ];
            }
            $item = $management->create($actor, $data);
            if ($case !== 'draft') {
                $item = $management->publish($item, $actor, $item->lock_version);
            }
            if ($case === 'versioned') {
                $item = $management->update($item, $actor, [
                    ...$data, 'expected_version' => $item->lock_version,
                    'name' => 'W15 '.$context['token'].' synthetic confidential revised draft', 'internal_only' => true,
                ]);
            }
            $items[$case] = ['id' => (int) $item->id, 'name' => $item->name,
                'draft_version' => $item->form_schema_version, 'published_version' => $item->publishedVersion?->version,
                'lock_version' => $item->lock_version, 'published' => $item->is_published];
        }
        w06BrowserRequire(ItCatalogItem::query()->count() === 5, 'Unexpected existing catalogue items in the synthetic schema.');
        $entityAssets = [];
        for ($number = 1; $number <= 202; $number++) {
            $asset = Asset::query()->create([
                'site_id' => $fixtures['sites'][$number === 202 ? 'c' : 'a'],
                'name' => 'W15 '.$context['token'].' synthetic equipment '.str_pad((string) $number, 3, '0', STR_PAD_LEFT),
                'asset_tag' => 'W15-'.$context['token'].'-'.$number,
                'status' => 'active', 'category' => 'IT Equipment', 'risk_level' => 'low',
                'created_by_user_id' => $actor->id, 'updated_by_user_id' => $actor->id,
            ]);
            AssetAssignment::query()->create([
                'asset_id' => $asset->id, 'assignee_type' => 'staff', 'assignee_id' => $fixtures['actors']['requester']['id'],
                'purpose' => 'Isolated catalogue search verification only', 'assigned_at' => now(),
            ]);
            if ($number >= 201) {
                $entityAssets[$number === 201 ? 'last_permitted' : 'unapproved_site'] = ['id' => $asset->id, 'name' => $asset->name];
            }
        }

        $template = app(ItProvisioningTemplateService::class)->create($actor, [
            'name' => 'W15 '.$context['token'].' synthetic joiner template',
            'description' => 'Synthetic manual-verification instructions; no external account is created.',
            'lifecycle_type' => 'joiner', 'position_role' => null, 'site_id' => $fixtures['sites']['a'],
            'employment_type' => null, 'selection_priority' => 0, 'is_active' => true,
            'tasks' => [[
                'task_key' => 'account', 'title' => 'Record synthetic account verification',
                'description' => 'Original version: retain these manual verification instructions.',
                'category' => 'account', 'action' => 'verify', 'request_type' => 'account',
                'responsible_team_id' => null, 'stage' => 1, 'sort_order' => 0,
                'dependency_task_keys' => [], 'trigger_fields' => [], 'approval_required' => true,
                'evidence_required' => true, 'due_offset_days' => 0, 'fulfiller_fields' => ['work_email'],
            ]],
        ]);
        $profile = HrEmployeeProfile::query()->where('user_id', $fixtures['actors']['requester']['id'])->firstOrFail();
        $workflow = app(ItProvisioningWorkflowService::class)->launch($profile, 'joiner', 'synthetic_browser',
            (int) $profile->id, 'w15:'.$context['token'].':version-history', (int) $actor->id);

        return ['items' => $items, 'entity_assets' => $entityAssets, 'template' => ['id' => $template->id, 'name' => $template->name],
            'workflow' => ['id' => $workflow->id, 'template_version_id' => $workflow->template_version_id], 'synthetic_only' => true];
    });
}
