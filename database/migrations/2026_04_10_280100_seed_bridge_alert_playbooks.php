<?php

use App\Models\ControlRoom\Playbook;
use App\Models\ControlRoom\PlaybookStep;
use Illuminate\Database\Migrations\Migration;

/**
 * Post-PR8 patch: Seed playbooks for bridge-created alert types.
 *
 * Bridge-created alerts use dot-notation alert_type values like
 * "operations.workplace_injury" or "safeguarding.{type}".
 * These differ from signal-pipeline alert types (which use SignalType.name).
 *
 * This migration ensures bridge-created operational alerts get playbooks too.
 * trigger_alert_types are set to null for these playbooks so they match ANY
 * alert type within their severity range — OR use specific patterns where
 * a targeted match is safe.
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
            // ─── SAFEGUARDING (bridge: safeguarding.{type}) ──────────

            [
                'code' => 'safeguarding_concern_sop',
                'name' => 'Safeguarding Concern Response',
                'category' => Playbook::CATEGORY_SAFETY,
                // Match any safeguarding alert type pattern
                'trigger_alert_types' => ['safeguarding.physical', 'safeguarding.emotional', 'safeguarding.sexual', 'safeguarding.neglect', 'safeguarding.financial', 'safeguarding.organisational', 'safeguarding.self_neglect', 'safeguarding.discriminatory', 'safeguarding.domestic_violence', 'safeguarding.modern_slavery', 'safeguarding.other'],
                'trigger_severities' => ['high', 'critical'],
                'auto_attach' => true,
                'steps' => [
                    ['title' => 'Ensure immediate safety', 'instructions' => 'Confirm the person at risk is safe. Separate from alleged perpetrator if applicable. Do NOT investigate — that is for the designated safeguarding lead.', 'type' => PlaybookStep::TYPE_TASK, 'time_limit_minutes' => 10],
                    ['title' => 'Notify designated safeguarding lead', 'instructions' => 'Contact the organisation\'s designated safeguarding lead immediately. If unavailable, contact the alternate lead.', 'type' => PlaybookStep::TYPE_NOTIFICATION],
                    ['title' => 'Record disclosure verbatim', 'instructions' => 'Write down exactly what was said/observed, in the person\'s own words. Do not interpret or summarise. Record time, date, location, witnesses.', 'type' => PlaybookStep::TYPE_EVIDENCE],
                    ['title' => 'External referral decision', 'instructions' => 'The safeguarding lead must decide whether an external referral to police, Health NZ, HDC, Whaikaha, Oranga Tamariki, or another authority is required.', 'type' => PlaybookStep::TYPE_DECISION, 'decision_options' => ['Internal investigation only', 'Refer to Health NZ', 'Refer to HDC', 'Refer to NZ Police', 'Refer to Oranga Tamariki', 'Multiple referrals required']],
                    ['title' => 'Complete safeguarding record', 'instructions' => 'Ensure all safeguarding forms are completed. Preserve all evidence. Document the chronology and actions taken.', 'type' => PlaybookStep::TYPE_EVIDENCE],
                ],
            ],

            // ─── WORKPLACE INJURY (bridge: operations.workplace_injury) ─

            [
                'code' => 'workplace_injury_sop',
                'name' => 'Workplace Injury Response',
                'category' => Playbook::CATEGORY_SAFETY,
                'trigger_alert_types' => ['operations.workplace_injury'],
                'trigger_severities' => ['high', 'critical'],
                'auto_attach' => true,
                'steps' => [
                    ['title' => 'Provide first aid', 'instructions' => 'Ensure the injured worker receives immediate first aid. Call 111 if serious.', 'type' => PlaybookStep::TYPE_TASK, 'time_limit_minutes' => 5],
                    ['title' => 'Preserve the scene', 'instructions' => 'If a serious harm incident, do NOT disturb the scene. Cordon off the area. This is a legal requirement under HSWA.', 'type' => PlaybookStep::TYPE_TASK],
                    ['title' => 'WorkSafe notification decision', 'instructions' => 'Determine if this is a notifiable event under HSWA. Serious harm, serious injury, or death must be notified to WorkSafe NZ.', 'type' => PlaybookStep::TYPE_DECISION, 'decision_options' => ['Not notifiable', 'Notifiable — notify WorkSafe within timeframe']],
                    ['title' => 'Notify management', 'instructions' => 'Inform the registered manager, health & safety officer, and HR.', 'type' => PlaybookStep::TYPE_NOTIFICATION],
                    ['title' => 'Complete incident documentation', 'instructions' => 'File accident/incident report with full details. Photograph the scene. Obtain witness statements.', 'type' => PlaybookStep::TYPE_EVIDENCE],
                ],
            ],

            // ─── SITE HAZARD (bridge: operations.hazard_identified) ───

            [
                'code' => 'hazard_high_risk_sop',
                'name' => 'High-Risk Hazard Response',
                'category' => Playbook::CATEGORY_SAFETY,
                'trigger_alert_types' => ['operations.hazard_identified'],
                'trigger_severities' => ['high', 'critical'],
                'auto_attach' => true,
                'steps' => [
                    ['title' => 'Assess immediate danger', 'instructions' => 'Determine if the hazard poses immediate danger to anyone. If yes, evacuate or restrict the area immediately.', 'type' => PlaybookStep::TYPE_TASK, 'time_limit_minutes' => 10],
                    ['title' => 'Apply interim controls', 'instructions' => 'Implement temporary controls to reduce the risk: barriers, signage, restricted access, PPE requirements.', 'type' => PlaybookStep::TYPE_TASK],
                    ['title' => 'Assign permanent resolution', 'instructions' => 'Create a corrective action and assign to the appropriate person with a due date.', 'type' => PlaybookStep::TYPE_TASK],
                    ['title' => 'Document hazard and controls', 'instructions' => 'Photograph the hazard, document interim controls applied, and record in the hazard register.', 'type' => PlaybookStep::TYPE_EVIDENCE],
                ],
            ],

            // ─── RESTRAINT EVENT (bridge: operations.restraint_event) ─

            [
                'code' => 'restraint_event_sop',
                'name' => 'Restraint Event Response',
                'category' => Playbook::CATEGORY_SAFETY,
                'trigger_alert_types' => ['operations.restraint_event'],
                'trigger_severities' => ['medium', 'high'],
                'auto_attach' => true,
                'steps' => [
                    ['title' => 'Ensure client welfare', 'instructions' => 'Check the client is safe, uninjured, and calm. Provide post-incident support and reassurance.', 'type' => PlaybookStep::TYPE_TASK, 'time_limit_minutes' => 15],
                    ['title' => 'Check staff welfare', 'instructions' => 'Check all staff involved are safe and offer immediate debrief/support.', 'type' => PlaybookStep::TYPE_TASK],
                    ['title' => 'Review against behaviour support plan', 'instructions' => 'Verify whether the restraint was within the client\'s behaviour support plan or was a deviation.', 'type' => PlaybookStep::TYPE_DECISION, 'decision_options' => ['Within support plan', 'Deviation — requires review']],
                    ['title' => 'Complete restraint documentation', 'instructions' => 'File the restraint event report with timeline, trigger, de-escalation attempts, restraint type, duration, and outcome.', 'type' => PlaybookStep::TYPE_EVIDENCE],
                ],
            ],

            // ─── CLIENT INCIDENT (bridge: incident.{type}) ───────────

            [
                'code' => 'client_incident_high_sop',
                'name' => 'High-Severity Client Incident Response',
                'category' => Playbook::CATEGORY_SAFETY,
                'trigger_alert_types' => ['incident.injury', 'incident.behaviour', 'incident.medication', 'incident.safeguarding', 'incident.near_miss'],
                'trigger_severities' => ['high', 'critical'],
                'auto_attach' => true,
                'steps' => [
                    ['title' => 'Ensure client safety', 'instructions' => 'Confirm the affected client is safe. Administer first aid if injury involved. Call emergency services if needed.', 'type' => PlaybookStep::TYPE_TASK, 'time_limit_minutes' => 10],
                    ['title' => 'Notify management', 'instructions' => 'Inform the shift lead, registered manager, and relevant family/next of kin if appropriate.', 'type' => PlaybookStep::TYPE_NOTIFICATION],
                    ['title' => 'Complete incident report', 'instructions' => 'File the incident report with full details, witness statements, and any photographs.', 'type' => PlaybookStep::TYPE_EVIDENCE],
                ],
            ],

            // ─── FLEET INCIDENT (bridge: operations.fleet_incident) ──

            [
                'code' => 'fleet_incident_sop',
                'name' => 'Fleet Incident Response',
                'category' => Playbook::CATEGORY_INVESTIGATION,
                'trigger_alert_types' => ['operations.fleet_incident'],
                'trigger_severities' => ['high', 'critical'],
                'auto_attach' => true,
                'steps' => [
                    ['title' => 'Ensure scene safety', 'instructions' => 'Confirm all occupants are safe. If injuries, call 111. Do not move the vehicle unless blocking traffic dangerously.', 'type' => PlaybookStep::TYPE_TASK, 'time_limit_minutes' => 5],
                    ['title' => 'Exchange details', 'instructions' => 'If third party involved, exchange insurance and contact details. Photograph all vehicles, damage, and the scene.', 'type' => PlaybookStep::TYPE_TASK],
                    ['title' => 'Police involvement decision', 'instructions' => 'Determine if police must be notified (injury, significant damage, or impairment suspected).', 'type' => PlaybookStep::TYPE_DECISION, 'decision_options' => ['Minor — no police needed', 'Police notified']],
                    ['title' => 'Complete fleet incident report', 'instructions' => 'File the incident report with photos, damage description, and all relevant details for insurance.', 'type' => PlaybookStep::TYPE_EVIDENCE],
                ],
            ],
        ];
    }
};
