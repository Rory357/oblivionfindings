<?php

namespace Database\Seeders;

use App\Models\ItProvisioningTemplate;
use App\Models\ItProvisioningTemplateTask;
use Illuminate\Database\Seeder;

class ItProvisioningTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedTemplate('joiner', 'Standard joiner', [
            $this->task('identity', 'Create core work account', 'account', 'grant', 'account', 1),
            $this->task('email', 'Create work email', 'email', 'grant', 'account', 2, ['identity']),
            $this->task('groups', 'Apply approved groups', 'group', 'grant', 'access', 2, ['identity'], approval: true),
            $this->task('licences', 'Assign approved software licences', 'licence', 'grant', 'access', 2, ['identity'], approval: true),
            $this->task('device', 'Prepare assigned work device', 'device', 'configure', 'equipment', 2, ['identity'], evidence: true),
            $this->task('peripherals', 'Prepare required peripherals', 'peripheral', 'configure', 'equipment', 2, ['identity']),
            $this->task('network', 'Configure approved network and Wi-Fi access', 'network', 'configure', 'access', 3, ['identity']),
            $this->task('doors', 'Verify and grant approved access control', 'access_control', 'verify', 'access', 3, ['identity'], approval: true, evidence: true),
            $this->task('telephony', 'Verify telephony requirements', 'telephony', 'verify', 'equipment', 3, ['identity']),
            $this->task('vehicle-tech', 'Verify fleet and vehicle technology requirements', 'vehicle_technology', 'verify', 'equipment', 3, ['identity']),
            $this->task('healthcare', 'Verify and grant approved healthcare system access', 'healthcare_access', 'verify', 'access', 3, ['identity'], approval: true, evidence: true),
        ]);

        $this->seedTemplate('mover', 'Standard role or site change', [
            $this->task('identity-delta', 'Update core account details', 'account', 'change', 'account', 1, triggers: ['position_role', 'primary_site_id', 'employment_type']),
            $this->task('group-delta', 'Review groups and licences', 'group', 'change', 'access', 2, ['identity-delta'], approval: true, triggers: ['position_role', 'primary_site_id', 'employment_type']),
            $this->task('network-delta', 'Review network and Wi-Fi access', 'network', 'change', 'access', 2, ['identity-delta'], triggers: ['primary_site_id', 'position_role']),
            $this->task('door-delta', 'Review access control permissions', 'access_control', 'change', 'access', 2, ['identity-delta'], approval: true, evidence: true, triggers: ['primary_site_id', 'position_role']),
            $this->task('equipment-delta', 'Review devices, peripherals, telephony and vehicle technology', 'device', 'verify', 'equipment', 2, ['identity-delta'], triggers: ['primary_site_id', 'position_role']),
            $this->task('healthcare-delta', 'Review approved healthcare system access', 'healthcare_access', 'change', 'access', 2, ['identity-delta'], approval: true, evidence: true, triggers: ['position_role', 'primary_site_id']),
        ]);

        $this->seedTemplate('leaver', 'Standard leaver', [
            $this->task('accounts-revoke', 'Revoke core accounts', 'account', 'revoke', 'account', 1, approval: true, evidence: true),
            $this->task('email-revoke', 'Secure email and mailbox access', 'email', 'revoke', 'account', 1, approval: true, evidence: true),
            $this->task('groups-revoke', 'Remove groups and software licences', 'licence', 'revoke', 'access', 1, approval: true),
            $this->task('network-revoke', 'Revoke network, VPN and Wi-Fi access', 'network', 'revoke', 'access', 1, evidence: true),
            $this->task('doors-revoke', 'Revoke access control credentials', 'access_control', 'revoke', 'access', 1, evidence: true),
            $this->task('telephony-revoke', 'Recover telephony services', 'telephony', 'recover', 'equipment', 2, ['accounts-revoke']),
            $this->task('vehicle-revoke', 'Recover fleet and vehicle technology access', 'vehicle_technology', 'recover', 'equipment', 2, ['accounts-revoke']),
            $this->task('healthcare-revoke', 'Revoke healthcare system access', 'healthcare_access', 'revoke', 'access', 1, approval: true, evidence: true),
        ]);
    }

    /** @param array<int, array<string, mixed>> $tasks */
    private function seedTemplate(string $lifecycle, string $name, array $tasks): void
    {
        $template = ItProvisioningTemplate::query()->firstOrCreate(
            ['tenant_id' => 1, 'name' => $name],
            [
                'description' => 'Safe baseline workflow. Tailor role and site specific templates in IT Setup as needed.',
                'lifecycle_type' => $lifecycle,
                'selection_priority' => -100,
                'is_active' => true,
            ],
        );

        if ($template->tasks()->exists()) {
            return;
        }

        foreach ($tasks as $sort => $task) {
            $template->tasks()->create([...$task, 'sort_order' => $sort]);
        }
    }

    /** @param array<int, string> $dependencies @param array<int, string> $triggers */
    private function task(
        string $key,
        string $title,
        string $category,
        string $action,
        string $requestType,
        int $stage,
        array $dependencies = [],
        bool $approval = false,
        bool $evidence = false,
        array $triggers = [],
    ): array {
        return [
            'task_key' => $key,
            'title' => $title,
            'category' => $category,
            'action' => $action,
            'request_type' => $requestType,
            'stage' => $stage,
            'dependency_task_keys' => $dependencies,
            'trigger_fields' => $triggers,
            'approval_required' => $approval,
            'evidence_required' => $evidence,
            'due_offset_days' => 0,
            'fulfiller_fields' => ItProvisioningTemplateTask::FULFILLER_FIELDS,
        ];
    }
}
