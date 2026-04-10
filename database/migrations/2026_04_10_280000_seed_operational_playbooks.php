<?php

use App\Models\ControlRoom\Playbook;
use App\Models\ControlRoom\PlaybookStep;
use Illuminate\Database\Migrations\Migration;

/**
 * PR8: Seed operational playbooks for all domain signal types.
 *
 * Each playbook provides structured SOP steps so operators know
 * exactly what to do when an alert fires — without consulting
 * external documentation or relying on tribal knowledge.
 *
 * trigger_alert_types must match the SignalType.name values because
 * resolveAlertType() uses signalType->name as the alert_type.
 */
return new class extends Migration
{
    public function up(): void
    {
        $playbooks = $this->getPlaybookDefinitions();

        foreach ($playbooks as $def) {
            $steps = $def['steps'];
            unset($def['steps']);

            $playbook = Playbook::firstOrCreate(
                ['code' => $def['code']],
                array_merge($def, ['version' => 1, 'is_active' => true])
            );

            if ($playbook->steps()->count() === 0) {
                foreach ($steps as $index => $step) {
                    PlaybookStep::create(array_merge($step, [
                        'playbook_id' => $playbook->id,
                        'order' => $index,
                        'is_required' => $step['is_required'] ?? true,
                        'is_blocking' => $step['is_blocking'] ?? ($index === 0),
                    ]));
                }
            }
        }
    }

    public function down(): void
    {
        $codes = collect($this->getPlaybookDefinitions())->pluck('code');

        $playbooks = Playbook::whereIn('code', $codes)->get();
        foreach ($playbooks as $playbook) {
            $playbook->steps()->delete();
            $playbook->delete();
        }
    }

    private function getPlaybookDefinitions(): array
    {
        return [
            // ─── LONE WORKER (PR4) ───────────────────────────────────

            [
                'code' => 'lone_worker_emergency_sop',
                'name' => 'Lone Worker Emergency Response',
                'category' => Playbook::CATEGORY_EMERGENCY,
                'trigger_alert_types' => ['Lone Worker Emergency'],
                'trigger_severities' => ['critical'],
                'auto_attach' => true,
                'steps' => [
                    ['title' => 'Attempt immediate contact', 'instructions' => 'Call the lone worker on their mobile. If no answer, try alternative contact numbers.', 'type' => PlaybookStep::TYPE_TASK, 'time_limit_minutes' => 2],
                    ['title' => 'Notify site supervisor', 'instructions' => 'Alert the on-site supervisor or coordinator at the worker\'s location.', 'type' => PlaybookStep::TYPE_NOTIFICATION],
                    ['title' => 'Dispatch nearest staff', 'instructions' => 'Identify and dispatch the nearest available staff member to the worker\'s last known location.', 'type' => PlaybookStep::TYPE_TASK, 'time_limit_minutes' => 5],
                    ['title' => 'Escalation decision', 'instructions' => 'If no contact established within 5 minutes, decide on emergency services escalation.', 'type' => PlaybookStep::TYPE_DECISION, 'decision_options' => ['Contact established — stand down', 'No contact — call 111']],
                    ['title' => 'Record outcome', 'instructions' => 'Document all actions taken, timeline, and outcome. Attach any evidence.', 'type' => PlaybookStep::TYPE_EVIDENCE],
                ],
            ],

            [
                'code' => 'lone_worker_overdue_checkin_sop',
                'name' => 'Lone Worker Overdue Check-in',
                'category' => Playbook::CATEGORY_SAFETY,
                'trigger_alert_types' => ['Lone Worker Overdue Check-in'],
                'trigger_severities' => ['high', 'critical'],
                'auto_attach' => true,
                'steps' => [
                    ['title' => 'Attempt contact', 'instructions' => 'Call the lone worker. Check if they simply forgot to check in.', 'type' => PlaybookStep::TYPE_TASK, 'time_limit_minutes' => 5],
                    ['title' => 'Assess situation', 'instructions' => 'Determine reason for missed check-in. Is the worker safe?', 'type' => PlaybookStep::TYPE_DECISION, 'decision_options' => ['Worker confirmed safe', 'Unable to reach — escalate']],
                    ['title' => 'Document resolution', 'instructions' => 'Record outcome and any follow-up actions needed.', 'type' => PlaybookStep::TYPE_EVIDENCE],
                ],
            ],

            // ─── MEDICATION (PR3) ────────────────────────────────────

            [
                'code' => 'medication_controlled_discrepancy_sop',
                'name' => 'Controlled Drug Discrepancy Response',
                'category' => Playbook::CATEGORY_COMPLIANCE,
                'trigger_alert_types' => ['Controlled Drug Discrepancy'],
                'trigger_severities' => ['critical'],
                'auto_attach' => true,
                'steps' => [
                    ['title' => 'Secure controlled drug register', 'instructions' => 'Immediately restrict access to the affected controlled drug. Notify the shift lead.', 'type' => PlaybookStep::TYPE_TASK, 'time_limit_minutes' => 5],
                    ['title' => 'Conduct independent recount', 'instructions' => 'Two staff members must independently verify the current stock count against the register.', 'type' => PlaybookStep::TYPE_TASK],
                    ['title' => 'Notify manager', 'instructions' => 'Inform the registered manager and clinical lead of the discrepancy.', 'type' => PlaybookStep::TYPE_NOTIFICATION],
                    ['title' => 'Determine cause', 'instructions' => 'Review administration records, witness statements, and CCTV if available.', 'type' => PlaybookStep::TYPE_DECISION, 'decision_options' => ['Recording error identified', 'Genuine discrepancy — notify pharmacy', 'Suspected diversion — notify police']],
                    ['title' => 'Document findings', 'instructions' => 'Complete the controlled drug discrepancy report with all evidence, statements, and corrective actions.', 'type' => PlaybookStep::TYPE_EVIDENCE],
                ],
            ],

            [
                'code' => 'medication_prn_over_limit_sop',
                'name' => 'PRN Over Limit Response',
                'category' => Playbook::CATEGORY_SAFETY,
                'trigger_alert_types' => ['PRN Over Limit'],
                'trigger_severities' => ['critical'],
                'auto_attach' => true,
                'steps' => [
                    ['title' => 'Review PRN administration record', 'instructions' => 'Check the eMAR for all PRN doses given in the last 24 hours. Verify counts against the limit.', 'type' => PlaybookStep::TYPE_TASK],
                    ['title' => 'Contact prescriber', 'instructions' => 'If the client is in pain/distress and has reached the PRN limit, contact the prescribing GP or on-call doctor for guidance.', 'type' => PlaybookStep::TYPE_TASK, 'time_limit_minutes' => 30],
                    ['title' => 'Document outcome', 'instructions' => 'Record prescriber advice and any changed instructions. Update the care plan if needed.', 'type' => PlaybookStep::TYPE_EVIDENCE],
                ],
            ],

            [
                'code' => 'medication_overdue_sop',
                'name' => 'Overdue Medication Response',
                'category' => Playbook::CATEGORY_SAFETY,
                'trigger_alert_types' => ['Medication Overdue'],
                'trigger_severities' => ['high'],
                'auto_attach' => true,
                'steps' => [
                    ['title' => 'Identify overdue medications', 'instructions' => 'Check the eMAR for all overdue scheduled doses for this client.', 'type' => PlaybookStep::TYPE_TASK],
                    ['title' => 'Administer or record reason', 'instructions' => 'If safe to administer late, do so. If not, record the reason (refused, withheld, absent, etc.).', 'type' => PlaybookStep::TYPE_DECISION, 'decision_options' => ['Administered late', 'Unable to administer — reason recorded']],
                    ['title' => 'Document', 'instructions' => 'Ensure eMAR is updated with the action taken and reason for delay.', 'type' => PlaybookStep::TYPE_EVIDENCE],
                ],
            ],

            [
                'code' => 'medication_error_sop',
                'name' => 'Medication Error Response',
                'category' => Playbook::CATEGORY_SAFETY,
                'trigger_alert_types' => ['Medication Error'],
                'trigger_severities' => ['high', 'critical'],
                'auto_attach' => true,
                'steps' => [
                    ['title' => 'Assess immediate risk to client', 'instructions' => 'Determine if the error poses immediate clinical risk. If yes, seek urgent medical advice.', 'type' => PlaybookStep::TYPE_TASK, 'time_limit_minutes' => 10],
                    ['title' => 'Notify shift lead and manager', 'instructions' => 'Inform the shift lead and registered manager immediately.', 'type' => PlaybookStep::TYPE_NOTIFICATION],
                    ['title' => 'Complete medication error report', 'instructions' => 'File the medication error form with full details: what happened, when, who was involved, what immediate action was taken.', 'type' => PlaybookStep::TYPE_EVIDENCE],
                ],
            ],

            // ─── INTEGRATION (PR1-PR2) ───────────────────────────────

            [
                'code' => 'integration_sos_sop',
                'name' => 'Integration SOS Response',
                'category' => Playbook::CATEGORY_EMERGENCY,
                'trigger_alert_types' => ['SOS Triggered', 'Panic Alarm', 'Duress Alarm'],
                'trigger_severities' => ['critical'],
                'auto_attach' => true,
                'steps' => [
                    ['title' => 'Verify alarm source', 'instructions' => 'Identify which device/zone triggered the alarm. Check CCTV if available.', 'type' => PlaybookStep::TYPE_TASK, 'time_limit_minutes' => 2],
                    ['title' => 'Attempt contact', 'instructions' => 'Contact the person at the alarm location via phone or intercom.', 'type' => PlaybookStep::TYPE_TASK, 'time_limit_minutes' => 3],
                    ['title' => 'Dispatch response', 'instructions' => 'Send nearest staff to the location. If no contact, call 111.', 'type' => PlaybookStep::TYPE_DECISION, 'decision_options' => ['False alarm — stand down', 'Person safe — assistance provided', 'No response — emergency services called']],
                    ['title' => 'Document outcome', 'instructions' => 'Record timeline, actions taken, and outcome.', 'type' => PlaybookStep::TYPE_EVIDENCE],
                ],
            ],

            [
                'code' => 'integration_door_forced_sop',
                'name' => 'Door Forced Response',
                'category' => Playbook::CATEGORY_SAFETY,
                'trigger_alert_types' => ['Door Forced'],
                'trigger_severities' => ['high'],
                'auto_attach' => true,
                'steps' => [
                    ['title' => 'Check CCTV and access logs', 'instructions' => 'Review camera footage and door access records for the affected entry point.', 'type' => PlaybookStep::TYPE_TASK],
                    ['title' => 'Assess security', 'instructions' => 'Determine if there is an ongoing security threat. Check all residents are accounted for.', 'type' => PlaybookStep::TYPE_DECISION, 'decision_options' => ['No threat — maintenance issue', 'Security concern — lock down and investigate']],
                    ['title' => 'Document findings', 'instructions' => 'Record incident details and any security actions taken.', 'type' => PlaybookStep::TYPE_EVIDENCE],
                ],
            ],

            // ─── FACILITY (PR5) ──────────────────────────────────────

            [
                'code' => 'inspection_failed_sop',
                'name' => 'Failed Inspection Response',
                'category' => Playbook::CATEGORY_COMPLIANCE,
                'trigger_alert_types' => ['Inspection Failed'],
                'trigger_severities' => ['high'],
                'auto_attach' => true,
                'steps' => [
                    ['title' => 'Review inspection findings', 'instructions' => 'Read the inspection record, findings, and identified corrective actions.', 'type' => PlaybookStep::TYPE_TASK],
                    ['title' => 'Assess immediate risk', 'instructions' => 'Determine if the failed inspection creates an immediate safety risk that needs urgent action.', 'type' => PlaybookStep::TYPE_DECISION, 'decision_options' => ['No immediate risk — schedule correction', 'Immediate risk — restrict access / apply temporary controls']],
                    ['title' => 'Assign corrective actions', 'instructions' => 'Create corrective actions with responsible person and due date.', 'type' => PlaybookStep::TYPE_TASK],
                    ['title' => 'Document', 'instructions' => 'Ensure all findings and actions are recorded in the inspection system.', 'type' => PlaybookStep::TYPE_EVIDENCE],
                ],
            ],

            [
                'code' => 'cr_device_offline_sop',
                'name' => 'Safety Device Offline Response',
                'category' => Playbook::CATEGORY_MAINTENANCE,
                'trigger_alert_types' => ['Device Offline'],
                'trigger_severities' => ['medium', 'high'],
                'auto_attach' => true,
                'steps' => [
                    ['title' => 'Identify affected device', 'instructions' => 'Determine which device is offline, its location, and what it monitors (bed sensor, camera, alarm panel, etc.).', 'type' => PlaybookStep::TYPE_TASK],
                    ['title' => 'Assess impact', 'instructions' => 'Determine if the offline device creates a safety gap. If it is a bed sensor or alarm panel, implement manual checks until restored.', 'type' => PlaybookStep::TYPE_DECISION, 'decision_options' => ['Non-critical — log for maintenance', 'Safety device — implement manual monitoring']],
                    ['title' => 'Arrange restoration', 'instructions' => 'Contact maintenance or the device vendor to restore the device.', 'type' => PlaybookStep::TYPE_TASK],
                ],
            ],

            // ─── H&S MONITORING (PR13) ───────────────────────────────

            [
                'code' => 'hs_investigation_overdue_sop',
                'name' => 'Overdue Investigation Follow-up',
                'category' => Playbook::CATEGORY_COMPLIANCE,
                'trigger_alert_types' => ['H&S Investigation Overdue'],
                'trigger_severities' => ['medium', 'high'],
                'auto_attach' => true,
                'steps' => [
                    ['title' => 'Contact lead investigator', 'instructions' => 'Check in with the lead investigator on progress and blockers.', 'type' => PlaybookStep::TYPE_TASK],
                    ['title' => 'Assess and decide', 'instructions' => 'Determine whether the investigation needs additional resource, extended deadline, or escalation.', 'type' => PlaybookStep::TYPE_DECISION, 'decision_options' => ['Extended timeline — update target date', 'Additional resource needed', 'Escalate to management']],
                    ['title' => 'Update investigation record', 'instructions' => 'Record the follow-up outcome and any revised target date.', 'type' => PlaybookStep::TYPE_EVIDENCE],
                ],
            ],

            [
                'code' => 'hs_corrective_action_overdue_sop',
                'name' => 'Overdue Corrective Action Follow-up',
                'category' => Playbook::CATEGORY_COMPLIANCE,
                'trigger_alert_types' => ['H&S Corrective Action Overdue'],
                'trigger_severities' => ['medium', 'high'],
                'auto_attach' => true,
                'steps' => [
                    ['title' => 'Contact assigned person', 'instructions' => 'Follow up with the person assigned to the corrective action.', 'type' => PlaybookStep::TYPE_TASK],
                    ['title' => 'Assess progress', 'instructions' => 'Determine if the action can be completed or needs reallocation.', 'type' => PlaybookStep::TYPE_DECISION, 'decision_options' => ['In progress — extend deadline', 'Blocked — reassign', 'Escalate to management']],
                    ['title' => 'Update action record', 'instructions' => 'Record follow-up outcome in the corrective action system.', 'type' => PlaybookStep::TYPE_EVIDENCE],
                ],
            ],

            [
                'code' => 'hs_drill_failure_sop',
                'name' => 'Failed Emergency Drill Response',
                'category' => Playbook::CATEGORY_COMPLIANCE,
                'trigger_alert_types' => ['Emergency Drill Failed'],
                'trigger_severities' => ['medium'],
                'auto_attach' => true,
                'steps' => [
                    ['title' => 'Review drill findings', 'instructions' => 'Read the drill report, observer notes, and identified improvements.', 'type' => PlaybookStep::TYPE_TASK],
                    ['title' => 'Identify training gaps', 'instructions' => 'Determine if staff need additional emergency procedure training.', 'type' => PlaybookStep::TYPE_TASK],
                    ['title' => 'Schedule re-drill', 'instructions' => 'Schedule a follow-up drill within the appropriate timeframe to verify improvements.', 'type' => PlaybookStep::TYPE_TASK],
                    ['title' => 'Document actions', 'instructions' => 'Record all corrective actions and the planned re-drill date.', 'type' => PlaybookStep::TYPE_EVIDENCE],
                ],
            ],
        ];
    }
};
