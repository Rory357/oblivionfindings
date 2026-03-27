<?php

namespace Database\Seeders;

use App\Models\ControlRoom\ConfigOption;
use Illuminate\Database\Seeder;

class ControlRoomConfigSeeder extends Seeder
{
    public function run(): void
    {
        $options = [
            // Alert categories
            ['group' => 'category', 'value' => 'incident', 'label' => 'Incident', 'color' => '#ef4444', 'sort_order' => 1],
            ['group' => 'category', 'value' => 'safeguarding', 'label' => 'Safeguarding', 'color' => '#8b5cf6', 'sort_order' => 2],
            ['group' => 'category', 'value' => 'medication', 'label' => 'Medication', 'color' => '#f97316', 'sort_order' => 3],
            ['group' => 'category', 'value' => 'compliance', 'label' => 'Compliance', 'color' => '#3b82f6', 'sort_order' => 4],
            ['group' => 'category', 'value' => 'maintenance', 'label' => 'Maintenance', 'color' => '#6b7280', 'sort_order' => 5],
            ['group' => 'category', 'value' => 'fleet', 'label' => 'Fleet & Transport', 'color' => '#10b981', 'sort_order' => 6],
            ['group' => 'category', 'value' => 'security', 'label' => 'Security', 'color' => '#dc2626', 'sort_order' => 7],
            ['group' => 'category', 'value' => 'health_safety', 'label' => 'Health & Safety', 'color' => '#eab308', 'sort_order' => 8],
            ['group' => 'category', 'value' => 'operational', 'label' => 'Operational', 'color' => '#0ea5e9', 'sort_order' => 9],
            ['group' => 'category', 'value' => 'other', 'label' => 'Other', 'color' => '#94a3b8', 'sort_order' => 10],

            // Resolution codes
            ['group' => 'resolution_code', 'value' => 'resolved_action_taken', 'label' => 'Resolved - Action Taken', 'color' => '#10b981', 'sort_order' => 1],
            ['group' => 'resolution_code', 'value' => 'resolved_no_action', 'label' => 'Resolved - No Action Required', 'color' => '#6b7280', 'sort_order' => 2],
            ['group' => 'resolution_code', 'value' => 'resolved_escalated', 'label' => 'Resolved - Escalated Externally', 'color' => '#f97316', 'sort_order' => 3],
            ['group' => 'resolution_code', 'value' => 'resolved_duplicate', 'label' => 'Resolved - Duplicate', 'color' => '#94a3b8', 'sort_order' => 4],
            ['group' => 'resolution_code', 'value' => 'resolved_false_alarm', 'label' => 'Resolved - False Alarm', 'color' => '#eab308', 'sort_order' => 5],
            ['group' => 'resolution_code', 'value' => 'resolved_referred', 'label' => 'Resolved - Referred to Other Team', 'color' => '#3b82f6', 'sort_order' => 6],
            ['group' => 'resolution_code', 'value' => 'resolved_client_resolved', 'label' => 'Resolved - Client Resolved', 'color' => '#8b5cf6', 'sort_order' => 7],

            // Task categories
            ['group' => 'task_category', 'value' => 'investigation', 'label' => 'Investigation', 'color' => '#3b82f6', 'sort_order' => 1],
            ['group' => 'task_category', 'value' => 'follow_up', 'label' => 'Follow-up', 'color' => '#f97316', 'sort_order' => 2],
            ['group' => 'task_category', 'value' => 'documentation', 'label' => 'Documentation', 'color' => '#6b7280', 'sort_order' => 3],
            ['group' => 'task_category', 'value' => 'communication', 'label' => 'Communication', 'color' => '#8b5cf6', 'sort_order' => 4],
            ['group' => 'task_category', 'value' => 'review', 'label' => 'Review', 'color' => '#eab308', 'sort_order' => 5],
            ['group' => 'task_category', 'value' => 'corrective_action', 'label' => 'Corrective Action', 'color' => '#ef4444', 'sort_order' => 6],
        ];

        foreach ($options as $option) {
            ConfigOption::updateOrCreate(
                ['group' => $option['group'], 'value' => $option['value']],
                array_merge($option, ['is_active' => true]),
            );
        }
    }
}
