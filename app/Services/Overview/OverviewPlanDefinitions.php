<?php

namespace App\Services\Overview;

/** Only gaps without an existing canonical recording workflow belong here. */
final class OverviewPlanDefinitions
{
    public static function all(): array
    {
        $personal = ['scope' => 'personal', 'read' => null, 'write' => null];
        $lead = ['scope' => 'site', 'read' => 'shifts.manageAny', 'write' => 'shifts.manageAny'];
        $manager = ['scope' => 'site', 'read' => 'operations.dashboard.view', 'write' => 'sites.update'];
        $it = ['scope' => 'site', 'read' => 'it.view', 'write' => 'it.manage'];
        $board = ['scope' => 'organisation', 'read' => 'governance.actions.view', 'write' => 'governance.actions.manage'];

        return [
            'worker-readiness' => $personal + ['role' => 'worker', 'guide' => 'Prepare for a specific shift. This personal checklist does not replace checks in My Day.', 'fields' => ['shift_reference' => 'Shift date or reference', 'preparation' => 'What to check before the shift']],
            'worker-reminders' => $personal + ['role' => 'worker', 'guide' => 'Keep personal work reminders with a clear next step. Keep private care information in the care record.', 'fields' => ['next_step' => 'Next step']],
            'lead-break-cover' => $lead + ['role' => 'lead', 'guide' => 'Agree cover for each break window. Confirm staffing and eligibility in the roster before changing assignments.', 'fields' => ['window' => 'Break window', 'cover' => 'Agreed cover and handover', 'contingency' => 'If cover is unavailable']],
            'lead-supplies' => $lead + ['role' => 'lead', 'guide' => 'Track a site supply request through to receipt. This record does not place an order or approve spending.', 'fields' => ['items' => 'Items and quantities', 'delivery' => 'Delivery or collection arrangements']],
            'lead-relief' => $lead + ['role' => 'lead', 'guide' => 'Prepare a relief-staff handover. Eligibility and shift allocation remain in the roster.', 'fields' => ['shift_reference' => 'Shift or team reference', 'preparation' => 'Induction and handover tasks']],
            'manager-visits' => $manager + ['role' => 'manager', 'guide' => 'Plan a site visit and record its follow-up. Keep individual care details in the client workspace.', 'fields' => ['purpose' => 'Visit purpose', 'follow_up' => 'Follow-up arrangements']],
            'manager-capacity' => $manager + ['role' => 'manager', 'guide' => 'Record a service-capacity review and its evidence. Completion means the review was done, not that capacity is available.', 'fields' => ['service' => 'Service being reviewed', 'evidence' => 'Capacity evidence and constraints', 'recommendation' => 'Recommendation']],
            'manager-resources' => $manager + ['role' => 'manager', 'guide' => 'Track a resource request for the selected receiving site. Record only arrangements that this site may see.', 'fields' => ['resource' => 'Resource and quantity', 'coordination' => 'Coordination and approval reference']],
            'manager-continuity' => $manager + ['role' => 'manager', 'guide' => 'Schedule a continuity exercise, record the result and follow-up. Completion is not certification of service readiness.', 'fields' => ['scenario' => 'Exercise scenario', 'success_criteria' => 'What the exercise must demonstrate', 'follow_up' => 'Lessons and follow-up']],
            'hr-position-descriptions' => ['role' => 'hr', 'scope' => 'site', 'read' => 'hr.employees.viewAny', 'write' => 'hr.employees.manage', 'guide' => 'Schedule a position-description review. Make approved wording changes in HR Positions.', 'fields' => ['position' => 'Position title or code', 'review_scope' => 'What needs reviewing', 'approval' => 'Reviewer or approval reference']],
            'it-patching' => $it + ['role' => 'it', 'guide' => 'Record an update-readiness review for a device group. These are staff-recorded checks, not live patch telemetry. Deployments stay in IT change control.', 'fields' => ['devices' => 'Device group or inventory reference', 'release' => 'Update or release', 'readiness' => 'Readiness evidence and blockers', 'change_reference' => 'Approved change reference']],
            'it-backups' => $it + ['role' => 'it', 'guide' => 'Plan a recovery check and record its actual result. A completed task alone does not prove that backups are healthy.', 'fields' => ['system' => 'System or recovery scope', 'recovery_target' => 'Recovery target and test method', 'result' => 'Observed result and evidence reference']],
            'it-certificates' => $it + ['role' => 'it', 'guide' => 'Track a certificate renewal using its expiry as the due date. Never enter private keys, passwords or tokens.', 'fields' => ['endpoint' => 'Service or endpoint', 'issuer' => 'Certificate issuer', 'renewal' => 'Renewal method and verification']],
            'it-licences' => $it + ['role' => 'it', 'guide' => 'Review recorded licence allocation for a product at this site. Counts are maintained by staff and are not discovered automatically.', 'fields' => ['product' => 'Software product', 'entitled' => 'Seats allocated to this site', 'assigned' => 'Seats assigned at this site', 'review' => 'Allocation evidence and next step'], 'numbers' => ['entitled', 'assigned']],
            'it-security-reviews' => $it + ['role' => 'it', 'guide' => 'Schedule a security review and record follow-up. Store vulnerabilities and sensitive evidence in the restricted IT record.', 'fields' => ['scope' => 'Review scope', 'method' => 'Review method', 'follow_up' => 'Evidence reference and follow-up']],
            'governance-induction' => $personal + ['role' => 'governance', 'guide' => 'Your own board-induction checklist. Completion here does not acknowledge a board pack or sign a declaration.', 'fields' => ['requirement' => 'Induction requirement', 'evidence' => 'Completion reference']],
            'governance-engagement' => $board + ['role' => 'governance', 'guide' => 'Plan board-level stakeholder engagement. Keep individual feedback, personal details and confidential meeting content in their authorised records.', 'fields' => ['group' => 'Stakeholder group', 'purpose' => 'Engagement purpose', 'follow_up' => 'Agreed follow-up']],
            'governance-external-review' => $board + ['role' => 'governance', 'guide' => 'Prepare for an external review with evidence references, an owner and a due date. A completed preparation task is not an external assurance opinion.', 'fields' => ['review' => 'External review', 'requirement' => 'Evidence requirement', 'evidence' => 'Evidence location or record reference']],
        ];
    }
}
