<?php

namespace App\Domain\Governance\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Governance plain-language labels for server-generated text (titles,
 * reasons, notifications, exports, audit phrases).
 *
 * Wording source of truth:
 *   docs/audits/2026-09-14-governance-plain-language-ux/vocabulary.md
 *
 * ⚠️ Client mirror: `resources/js/lib/governance-labels.ts`
 * (`GOVERNANCE_LABELS`) holds the same domain → value → label maps. Both
 * MUST change together. `tests/fixtures/governance/labels.json` is the
 * shared contract — the PHP and Vitest suites each assert their map equals
 * it, so a one-sided edit fails CI.
 */
final class GovernanceLabels
{
    public const TIMEZONE = 'Pacific/Auckland';

    /** Proper nouns that keep their capitals inside sentence-cased text. */
    public const PROPER_NOUNS = [
        'Te Tiriti',
        'Waitangi',
        'Ngā Paerewa',
        'Māori',
        'New Zealand',
        'Aotearoa',
        'WorkSafe',
        'Health NZ',
        'Charities Services',
        'Privacy Act',
        'Code of Rights',
    ];

    /*
     * Domain → stored value → label. Generated from, and kept identical to,
     * GOVERNANCE_LABELS in resources/js/lib/governance-labels.ts (see that
     * file for per-domain notes, e.g. why legacy special_majority reads
     * "More For than Against" and why Te Tiriti principles are English-only).
     */
    public const LABELS = [
        'resolution_status' => [
            'draft' => 'Draft',
            'proposed' => 'Waiting for the board',
            'open' => 'Open for voting',
            'closed' => 'Voting closed',
            'implemented' => 'Done',
            'archived' => 'Archived',
            'carried' => 'Passed',
            'defeated' => 'Not passed',
            'withdrawn' => 'Withdrawn',
            'cancelled' => 'Cancelled',
        ],
        'resolution_outcome' => [
            'carried' => 'Passed',
            'defeated' => 'Not passed',
            'no_quorum' => 'No decision — not enough members took part',
            'deferred' => 'Put off to a later meeting',
            'withdrawn' => 'Withdrawn',
        ],
        'voting_threshold' => [
            'ordinary' => 'More For than Against',
            'simple_majority' => 'More For than Against',
            'special' => 'At least two-thirds For',
            'two_thirds' => 'At least two-thirds For',
            'unanimous' => 'Everyone entitled votes For',
            'special_majority' => 'More For than Against',
            'three_quarters' => 'At least three-quarters For',
        ],
        'resolution_purpose' => [
            'decision' => 'For decision',
            'discussion' => 'For discussion',
            'information' => 'For information',
        ],
        'decision_type' => [
            'resolution' => 'Resolution',
            'motion' => 'Resolution',
            'budget_approval' => 'Budget approval',
            'strategic' => 'Strategy',
            'financial' => 'Finance',
            'policy' => 'Policy',
            'operational' => 'Operations',
            'statutory' => 'Legal requirement',
            'governance' => 'Governance',
        ],
        'conflict_type' => [
            'material' => 'Personal or financial interest',
            'related' => 'Link to a person or organisation involved',
            'prejudicial' => 'Bias or divided loyalty',
            'other' => 'Other',
        ],
        'vote' => [
            'for' => 'For',
            'against' => 'Against',
            'abstain' => 'Abstain',
        ],
        'voting_method' => [
            'in_person' => 'In the meeting',
            'electronic' => 'Online',
            'written' => 'In writing',
            'proxy' => 'By proxy',
        ],
        'authority_subject' => [
            'voting_profile' => 'Voting rules',
            'strategic_plan' => 'Strategic plan',
            'budget_adjustment' => 'Budget change',
            'budget' => 'Budget',
            'performance_review' => 'Performance review',
        ],
        'quorum_mode' => [
            'majority_floor_plus_one' => 'More than half of the voting members',
            'percentage' => 'A set percentage of the voting members',
            'fixed_count' => 'A set number of voting members',
        ],
        'legal_form' => [
            'charitable_trust' => 'Charitable trust',
            'incorporated_society' => 'Incorporated society',
            'company' => 'Company',
        ],
        'governing_body' => [
            'board' => 'Board',
            'committee' => 'Committee',
        ],
        'action_status' => [
            'active' => 'Open',
            'open' => 'Not started',
            'in_progress' => 'In progress',
            'blocked' => 'Blocked',
            'overdue' => 'Overdue',
            'complete' => 'Done',
            'completed' => 'Done',
            'cancelled' => 'Cancelled',
        ],
        'priority' => [
            'low' => 'Low',
            'medium' => 'Medium',
            'high' => 'High',
            'critical' => 'Critical',
        ],
        'action_source' => [
            'meeting' => 'Meeting',
            'governance_meeting' => 'Meeting',
            'resolution' => 'Resolution',
            'risk_review' => 'Risk review',
            'site_risk_review' => 'Site risk review',
            'compliance_review' => 'Compliance review',
            'risk_register_entry' => 'Risk',
            'compliance_obligation' => 'Requirement',
            'manual' => 'Added directly',
        ],
        'meeting_type' => [
            'full_board' => 'Full board meeting',
            'audit_risk' => 'Audit and risk committee',
            'people' => 'People committee',
            'finance' => 'Finance committee',
            'special_general' => 'Special general meeting',
            'executive_session' => 'Board-only session',
        ],
        'meeting_status' => [
            'scheduled' => 'Scheduled',
            'upcoming' => 'Coming up',
            'agenda_draft' => 'Agenda being prepared',
            'agenda_final' => 'Agenda ready',
            'in_progress' => 'In progress',
            'minutes_pending' => 'Minutes to finish',
            'minutes_draft' => 'Minutes being written',
            'minutes_review' => 'Minutes waiting for the board',
            'minutes_approved' => 'Minutes approved',
            'minutes_signed' => 'Minutes signed',
            'archived' => 'Archived',
            'cancelled' => 'Cancelled',
            'pack_draft' => 'Board pack being prepared',
        ],
        'rsvp_response' => [
            'accepted' => 'Attending',
            'attending' => 'Attending',
            'tentative' => 'Not sure yet',
            'unsure' => 'Not sure yet',
            'declined' => 'Sent apologies',
            'apology' => 'Sent apologies',
        ],
        'attendance_status' => [
            'present' => 'Present',
            'late' => 'Arrived late',
            'apology' => 'Sent apologies',
            'no_show' => 'Absent without apologies',
            'unrecorded' => 'Not recorded',
        ],
        'agenda_item_type' => [
            'standard' => 'Discussion',
            'decision' => 'For decision',
            'consent' => 'Routine item (agreed together)',
            'for_info' => 'For information',
        ],
        'minutes_status' => [
            'draft' => 'Draft',
            'review' => 'Waiting for the board',
            'in_review' => 'Waiting for the board',
            'approved' => 'Approved',
            'signed' => 'Signed',
            'locked' => 'Final version',
            'reviewed' => 'Sent for approval',
            'archived' => 'Archived',
        ],
        'board_pack_status' => [
            'draft' => 'Draft',
            'pack_draft' => 'Draft',
            'queued' => 'Waiting to be prepared',
            'building' => 'Being prepared',
            'generated' => 'Ready',
            'published' => 'Ready',
            'distributed' => 'Sent to members',
            'current' => 'Current version',
            'superseded' => 'Replaced by a newer version',
            'failed' => 'Couldn\'t be prepared',
            'archived' => 'Archived',
            'cancelled' => 'Cancelled',
        ],
        'ceo_report_status' => [
            'draft' => 'Draft',
            'submitted' => 'Waiting for the board',
            'presented' => 'Presented to the board',
            'overdue' => 'Overdue',
        ],
        'risk_category' => [
            'client_safety' => 'Safety of the people we support',
            'reputational' => 'Reputation',
            'financial' => 'Finance',
            'it_cyber' => 'IT and cyber security',
            'workforce' => 'Staff and workforce',
            'legal_compliance' => 'Legal and compliance',
            'operational' => 'Day-to-day operations',
            'clinical' => 'Care and clinical',
        ],
        'risk_strategy' => [
            'treat' => 'Reduce it',
            'transfer' => 'Share it',
            'terminate' => 'Stop the activity',
            'avoid' => 'Stop the activity',
            'tolerate' => 'Live with it and monitor',
            'accept' => 'Live with it and monitor',
        ],
        'risk_status' => [
            'active' => 'Open',
            'open' => 'Open',
            'mitigating' => 'Open',
            'transferred' => 'Open',
            'accepted' => 'Accepted by the board',
            'avoided' => 'Closed',
            'closed' => 'Closed',
            'voided' => 'Removed from the register',
        ],
        'risk_level' => [
            'minimal' => 'Minimal',
            'low' => 'Low',
            'medium' => 'Medium',
            'high' => 'High',
            'critical' => 'Critical',
        ],
        'control_effectiveness' => [
            'none' => 'No controls in place',
            'weak' => 'Weak',
            'moderate' => 'Partly effective',
            'strong' => 'Strong',
        ],
        'risk_likelihood' => [
            '1' => 'Rare',
            '2' => 'Unlikely',
            '3' => 'Possible',
            '4' => 'Likely',
            '5' => 'Almost certain',
        ],
        'risk_impact' => [
            '1' => 'Insignificant',
            '2' => 'Minor',
            '3' => 'Moderate',
            '4' => 'Major',
            '5' => 'Catastrophic',
        ],
        'risk_treatment_status' => [
            'planned' => 'Planned',
            'in_progress' => 'In progress',
            'overdue' => 'Overdue',
            'complete' => 'Done',
            'cancelled' => 'Cancelled',
        ],
        'risk_event_type' => [
            'incident' => 'Incident',
            'alert' => 'Alert',
            'safeguarding' => 'Safeguarding concern',
            'audit' => 'Audit finding',
            'breach' => 'Privacy breach',
            'complaint' => 'Complaint',
            'hs_event' => 'Health and safety event',
        ],
        'compliance_framework' => [
            'charities' => 'Charities Act 2005 (Charities Services)',
            'nga_paerewa' => 'Ngā Paerewa Health and Disability Services Standard (NZS 8134:2021)',
            'code_of_rights' => 'Code of Rights (Health and Disability Commissioner)',
            'hdsa_safety' => 'Health and Disability Services (Safety) Act 2001',
            'privacy_act' => 'Privacy Act 2020',
            'hip_code' => 'Health Information Privacy Code 2020',
            'hswa' => 'Health and Safety at Work Act 2015',
            'employment' => 'Employment Relations Act 2000',
            'funding_moh' => 'Health New Zealand funding',
            'funding_dss' => 'Disability Support Services funding',
            'funding_msd' => 'Ministry of Social Development funding',
            'funding_acc' => 'ACC funding',
        ],
        'compliance_status' => [
            'not_due' => 'Not due yet',
            'pending' => 'Not due yet',
            'due_soon' => 'Due soon',
            'in_progress' => 'In progress',
            'overdue' => 'Overdue',
            'complete' => 'Done',
            'cancelled' => 'Cancelled',
        ],
        'compliance_evidence_type' => [
            'document' => 'Document',
            'audit_report' => 'Audit report',
            'certification' => 'Certificate',
            'system_export' => 'Report from the system',
            'attestation' => 'Signed confirmation',
            'screenshot' => 'Screenshot',
            'policy' => 'Policy',
            'procedure' => 'Procedure',
        ],
        'frequency' => [
            'weekly' => 'Weekly',
            'fortnightly' => 'Every 2 weeks',
            'monthly' => 'Monthly',
            'bimonthly' => 'Every 2 months',
            'quarterly' => 'Every 3 months',
            'biannual' => 'Twice a year',
            'annual' => 'Once a year',
            'annually' => 'Once a year',
            'ad_hoc' => 'As needed',
            'event_driven' => 'When something happens',
        ],
        'care_quality_category' => [
            'falls' => 'Falls',
            'medication_errors' => 'Medication errors',
            'pressure_injuries' => 'Skin injuries',
            'restraint' => 'Use of restraint',
            'infections' => 'Infections',
            'safeguarding' => 'Safeguarding concerns',
            'complaints' => 'Complaints',
        ],
        'care_quality_status' => [
            'normal' => 'On target',
            'warning' => 'Needs watching',
            'critical' => 'Needs action',
            'no_data' => 'No data yet',
        ],
        'te_tiriti_principle' => [
            'partnership' => 'Partnership',
            'tino_rangatiratanga' => 'Tino rangatiratanga',
            'active_protection' => 'Active protection',
            'equity' => 'Equity',
            'options' => 'Options (Kōwhiringa)',
            'participation' => 'Tino rangatiratanga',
            'protection' => 'Active protection',
        ],
        'te_tiriti_status' => [
            'not_started' => 'Not started',
            'in_progress' => 'In progress',
            'ongoing' => 'Part of everyday practice',
            'implemented' => 'Done',
            'achieved' => 'Done',
            'embedded' => 'Part of everyday practice',
        ],
        'policy_status' => [
            'draft' => 'Draft',
            'under_review' => 'Waiting for the board',
            'approved' => 'Approved',
            'active' => 'Approved',
            'published' => 'Approved',
            'superseded' => 'Replaced by a newer version',
            'archived' => 'Archived',
        ],
        'policy_category' => [
            'governance' => 'Governance',
            'financial' => 'Finance',
            'hr' => 'People and HR',
            'health_safety' => 'Health and safety',
            'privacy' => 'Privacy',
            'clinical' => 'Care and clinical',
            'operational' => 'Operations',
            'risk' => 'Risk',
            'compliance' => 'Compliance',
            'other' => 'Other',
        ],
        'policy_confirmation_status' => [
            'not_required' => 'No confirmation needed',
            'pending' => 'To confirm',
            'overdue' => 'Overdue',
            'confirmed' => 'Confirmed',
            'attested' => 'Confirmed',
            'not_yet_in_effect' => 'Not in effect yet',
        ],
        'document_type' => [
            'constitution' => 'Governing document',
            'terms_of_reference' => 'Terms of reference',
            'policy' => 'Policy',
            'procedure' => 'Procedure',
            'template' => 'Template',
            'report' => 'Report',
            'certificate' => 'Certificate or registration',
            'minutes' => 'Minutes',
            'other' => 'Other',
        ],
        'budget_status' => [
            'drafting' => 'Draft',
            'draft' => 'Draft',
            'proposed' => 'Waiting for the board',
            'submitted' => 'Waiting for the board',
            'under_review' => 'Waiting for the board',
            'pending' => 'Waiting for the board',
            'approved' => 'Approved',
            'rejected' => 'Not approved',
            'closed' => 'Closed',
            'archived' => 'Archived',
        ],
        'budget_category' => [
            'staffing' => 'Staffing',
            'operations' => 'Operations',
            'fleet' => 'Vehicles',
            'compliance' => 'Compliance',
            'capital' => 'Equipment and buildings',
            'admin' => 'Administration',
            'other' => 'Other',
        ],
        'budget_change_type' => [
            'increase' => 'Increase',
            'decrease' => 'Decrease',
            'reallocate' => 'Move money between lines',
        ],
        'budget_change_status' => [
            'draft' => 'Draft',
            'submitted' => 'Waiting for approval',
            'under_review' => 'Waiting for approval',
            'approved' => 'Approved',
            'rejected' => 'Not approved',
            'declined' => 'Not approved',
        ],
        'spend_status' => [
            'draft' => 'Draft',
            'submitted' => 'Waiting for approval',
            'pending' => 'Waiting for approval',
            'requires_board' => 'Needs board approval',
            'approved' => 'Approved',
            'rejected' => 'Not approved',
            'expired' => 'Expired',
        ],
        'spend_category' => [
            'capex' => 'Equipment or building purchase',
            'opex' => 'Running costs outside the budget',
            'supplier_contract' => 'Supplier contract',
            'donor_restricted' => 'Donor-restricted funds',
        ],
        'strategic_plan_status' => [
            'draft' => 'Draft',
            'review' => 'Waiting for the board',
            'consultation' => 'Out for feedback',
            'approved' => 'Approved',
            'active' => 'Approved',
            'superseded' => 'Replaced by a newer version',
            'archived' => 'Archived',
            'completed' => 'Archived',
        ],
        'plan_length' => [
            'annual' => '1-year plan',
            '3_year' => '3-year plan',
            '5_year' => '5-year plan',
        ],
        'theme' => [
            'safety' => 'Safety',
            'quality' => 'Quality of support',
            'people' => 'People',
            'finance' => 'Finance',
            'compliance' => 'Compliance',
            'it_resilience' => 'Reliable IT systems',
        ],
        'goal_status' => [
            'not_started' => 'Not started',
            'planning' => 'Planning',
            'in_progress' => 'In progress',
            'on_track' => 'On track',
            'at_risk' => 'At risk',
            'delayed' => 'Delayed',
            'off_track' => 'Off track',
            'on_hold' => 'On hold',
            'blocked' => 'Blocked',
            'achieved' => 'Achieved',
            'partially_achieved' => 'Partly achieved',
            'missed' => 'Missed',
            'complete' => 'Done',
            'completed' => 'Done',
            'cancelled' => 'Cancelled',
        ],
        'performance_review_status' => [
            'drafting' => 'Draft',
            'draft' => 'Draft',
            'active' => 'In progress',
            'self_review' => 'Waiting for self-assessment',
            'peer_review' => 'Collecting feedback',
            'board_review' => 'Waiting for the board',
            'completed' => 'Done',
            'closed' => 'Closed',
        ],
        'performance_review_type' => [
            'quarterly' => 'Quarterly',
            'annual' => 'Annual',
            'ad_hoc' => 'One-off',
        ],
        'performance_rating' => [
            'exceeds' => 'Exceeds expectations',
            'meets' => 'Meets expectations',
            'needs_improvement' => 'Needs improvement',
            'unsatisfactory' => 'Below expectations',
        ],
        'performance_decision' => [
            'remuneration_increase' => 'Increase pay',
            'maintain' => 'Keep pay the same',
            'development_plan' => 'Agree a development plan',
            'performance_improvement' => 'Agree a performance improvement plan',
        ],
        'reviewer_role' => [
            'board_member' => 'Board member',
            'peer' => 'Peer',
            'direct_report' => 'Direct report',
            'self' => 'Self-assessment',
        ],
        'evaluation_status' => [
            'draft' => 'Draft',
            'active' => 'Open for responses',
            'open' => 'Open for responses',
            'closed' => 'Closed',
            'reported' => 'Results shared',
        ],
        'evaluation_type' => [
            'board' => 'Whole board',
            'committee' => 'Committee',
            'chair' => 'Chair',
            'individual' => 'Individual members',
            'annual_self_assessment' => 'Annual self-assessment',
            'peer_review' => 'Peer review',
            'external_review' => 'External review',
        ],
        'board_role' => [
            'chair' => 'Chair',
            'deputy_chair' => 'Deputy chair',
            'secretary' => 'Secretary',
            'treasurer' => 'Treasurer',
            'member' => 'Board member',
            'observer' => 'Observer (can\'t vote)',
        ],
        'board_member_standing' => [
            'active' => 'Current member',
            'inactive' => 'Not active',
            'term_not_started' => 'Term not started',
            'term_ended' => 'Term ended',
            'observer' => 'Observer (can\'t vote)',
        ],
        'interest_type' => [
            'financial' => 'Financial',
            'personal' => 'Personal',
            'professional' => 'Professional',
            'family' => 'Family',
            'directorship' => 'Director or trustee role',
            'employment' => 'Employment',
            'property' => 'Property',
            'other' => 'Other',
        ],
        'interest_nature' => [
            'direct' => 'Direct',
            'indirect' => 'Through someone close to me',
        ],
        'interest_status' => [
            'current' => 'Current',
            'ended' => 'Ended',
        ],
        'audit_entity_type' => [
            'action_item' => 'Action',
            'board_evaluation' => 'Board evaluation',
            'board_member' => 'Board member',
            'board_member_interest' => 'Interest',
            'board_pack' => 'Board pack',
            'budget' => 'Budget',
            'budget_adjustment' => 'Budget change',
            'budget_allocation' => 'Budget allocation',
            'ceo_board_report' => 'CEO report',
            'compliance_obligation' => 'Requirement',
            'governance_document' => 'Document',
            'governance_meeting' => 'Meeting',
            'governance_policy' => 'Policy',
            'governance_setting' => 'Governance settings',
            'governance_voting_profile' => 'Voting rules',
            'incident_governance_escalation' => 'Incident raised with the board',
            'meeting_minute' => 'Minutes',
            'notifiable_incident' => 'Notifiable incident',
            'performance_review' => 'Performance review',
            'resolution' => 'Resolution',
            'risk_register_entry' => 'Risk',
            'risk_treatment' => 'Action to reduce a risk',
            'safeguarding_concern' => 'Safeguarding concern',
            'spend_approval' => 'Spend request',
            'strategic_plan' => 'Strategic plan',
            'te_tiriti_obligation' => 'Te Tiriti commitment',
        ],
        'audit_event' => [
            'viewed' => 'viewed',
            'downloaded' => 'downloaded',
            'edited' => 'edited',
            'created' => 'created',
            'updated' => 'updated',
            'deleted' => 'deleted',
            'approved' => 'approved',
            'voted' => 'voted on',
            'exported' => 'exported',
            'resolution.created' => 'created a resolution',
            'resolution.updated' => 'updated a resolution',
            'resolution.voted' => 'voted on a resolution',
            'resolution.conflict_declared' => 'declared a conflict of interest',
            'resolution.voting_opened' => 'opened voting',
            'resolution.voting_closed' => 'closed voting',
            'resolution.finalized' => 'finalised a resolution',
            'resolution.authority_bound' => 'linked a resolution to the record it approves',
            'resolution.authority_unbound' => 'removed the record linked to a resolution',
            'resolution.authority_consumed' => 'used a resolution to approve a record',
            'resolution.attachment_added' => 'added a file to a resolution',
            'resolution.attachment_removed' => 'removed a file from a resolution',
            'minutes.approved' => 'approved minutes',
            'minutes.signed' => 'signed minutes',
            'board_pack.read' => 'confirmed reading a board pack',
            'board_pack.downloaded' => 'downloaded a board pack',
            'board_pack.attachment_added' => 'added a file to a board pack',
            'board_pack.attachment_removed' => 'removed a file from a board pack',
            'board_pack.attachment_downloaded' => 'downloaded a file from a board pack',
            'policy.attested' => 'confirmed reading a policy',
            'budget.proposed' => 'sent a budget to the board',
            'budget.approved' => 'approved a budget',
            'budget.allocation_created' => 'added a budget allocation',
            'spend_approval.created' => 'created a spend request',
            'spend_approval.updated' => 'updated a spend request',
            'spend_approval.submitted' => 'submitted a spend request',
            'spend_approval.approved' => 'approved a spend request',
            'spend_approval.rejected' => 'declined a spend request',
            'spend_approval.attachment_added' => 'added a file to a spend request',
            'spend_approval.attachment_removed' => 'removed a file from a spend request',
            'risk_treatment.attachment_added' => 'added a file to an action to reduce a risk',
            'risk_treatment.attachment_removed' => 'removed a file from an action to reduce a risk',
            'compliance.auto_created' => 'automatically added a requirement',
            'action.auto_created' => 'automatically created an action',
            'incident.escalated' => 'raised an incident with the board',
            'notifiable_incident.auto_created' => 'automatically recorded a notifiable incident',
            'notifiable_incident.reopened_after_reclassification' => 'reopened a notifiable incident after it was reclassified',
            'notifiable_incident.retracted_after_reclassification' => 'withdrew a notifiable incident after it was reclassified',
            'settings.updated' => 'changed governance settings',
            'governance_rules.updated' => 'changed the voting rules',
            'governance_rules.activated' => 'recorded the board\'s approval of the voting rules',
            'board_member_appointed' => 'appointed a board member',
            'board_member_removed' => 'removed a board member',
            'role_changed' => 'changed a board role',
            'policy_updated' => 'updated a policy',
            'key_person_changed' => 'changed a key person',
            'action.escalated' => 'raised an action with the board',
            'action.evidence_added' => 'added evidence to an action',
            'action.evidence_removed' => 'removed evidence from an action',
            'resolution.published' => 'shared a resolution with members',
            'budget.returned_to_drafting' => 'returned a budget to drafting',
            'risk.accepted' => 'recorded the board accepting a risk',
            'risk.closed' => 'closed a risk',
            'risk_treatment.completed' => 'marked a risk action as done',
            'risk_treatment.due_date_changed' => 'changed the due date of a risk action',
            'policy.approved' => 'approved a policy',
            'compliance.updated' => 'updated a requirement',
            'compliance.evidence_downloaded' => 'downloaded evidence for a requirement',
        ],
    ];

    /** Plain label for any Governance enum value. Missing → "Not set". */
    public static function label(string $domain, string|int|null $value): string
    {
        if ($value === null || trim((string) $value) === '') {
            return 'Not set';
        }

        $map = self::LABELS[$domain] ?? [];

        foreach (self::lookupKeys((string) $value) as $key) {
            if (array_key_exists($key, $map)) {
                return $map[$key];
            }
        }

        return self::humanise($value);
    }

    /** "voted on a resolution" — the verb phrase after the person's name. */
    public static function auditEvent(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return 'did something';
        }

        foreach (self::lookupKeys($value) as $key) {
            if (array_key_exists($key, self::LABELS['audit_event'])) {
                return self::LABELS['audit_event'][$key];
            }
        }

        return mb_strtolower(self::humanise($value));
    }

    /** One plain sentence on how a resolution passes under its voting rule. */
    public static function thresholdExplanation(?string $threshold): string
    {
        return match ($threshold) {
            'special', 'two_thirds' => "It passes if at least two-thirds of the For and Against votes are For — abstentions don't count either way.",
            'three_quarters' => "It passes if at least three-quarters of the For and Against votes are For — abstentions don't count either way.",
            'unanimous' => 'It passes only if every voting member votes For — one Against vote, abstention, step-aside or missing vote means it does not pass.',
            // Mirrors Resolution::determineOutcome(): anything else is decided
            // by more For than Against.
            default => "It passes if more voting members vote For than Against — abstentions don't count either way.",
        };
    }

    /**
     * "$85,000" / "$85,000.50" / "-$1,200". Cents show only when there are
     * some, unless `$cents` forces them on or off. Missing → "Not stated".
     */
    public static function money(float|int|string|null $amount, ?bool $cents = null): string
    {
        if ($amount === null || (is_string($amount) && trim($amount) === '') || ! is_numeric($amount)) {
            return 'Not stated';
        }

        $value = (float) $amount;
        if (! is_finite($value)) {
            return 'Not stated';
        }

        $hasCents = ((int) round(abs($value) * 100)) % 100 !== 0;
        $decimals = ($cents ?? $hasCents) ? 2 : 0;
        $formatted = number_format(abs($value), $decimals, '.', ',');
        $isNegative = $value < 0 && (float) str_replace(',', '', $formatted) !== 0.0;

        return ($isNegative ? '-' : '').'$'.$formatted;
    }

    /** "Ref RES-2026-004" — references go last and muted. Missing → "". */
    public static function ref(?string $code): string
    {
        $code = trim((string) $code);

        return $code === '' ? '' : 'Ref '.$code;
    }

    /**
     * NZ financial year (1 July – 30 June) → "2025/26".
     * - a date → the financial year it falls in (Auckland time);
     * - an int (or "2026" / "FY2026") → the year ENDING in that year
     *   (NZ "FY2026" = 1 July 2025 – 30 June 2026 = "2025/26");
     * - "2025-2026" / "2025/26" → normalised.
     */
    public static function financialYear(CarbonInterface|int|string $value): string
    {
        if (is_int($value)) {
            return self::formatFinancialYear($value - 1);
        }

        if (is_string($value)) {
            $text = trim($value);

            if (preg_match('/^(\d{4})\s*[-\/]\s*(\d{2}|\d{4})$/', $text, $range)) {
                $start = (int) $range[1];
                $end = (int) $range[2];
                $isYearRange = strlen($range[2]) === 4 ? $end === $start + 1 : $end === ($start + 1) % 100;

                if ($isYearRange) {
                    return self::formatFinancialYear($start);
                }

                // "2026-06" is a month (June 2026), not a year range.
                if (strlen($range[2]) === 2 && $end >= 1 && $end <= 12) {
                    return self::formatFinancialYear($end >= 7 ? $start : $start - 1);
                }

                return 'Not set';
            }

            if (preg_match('/^(?:FY\s*)?(\d{4})$/i', $text, $year)) {
                return self::formatFinancialYear((int) $year[1] - 1);
            }

            $date = self::toNzDate($text);
            if ($date === null) {
                return 'Not set';
            }
        } else {
            $date = CarbonImmutable::instance($value)->setTimezone(self::timezone());
        }

        return self::formatFinancialYear($date->month >= 7 ? $date->year : $date->year - 1);
    }

    /**
     * "7 September 2026" or, with time, "7 Sep 2026, 5:00 pm" — always in
     * NZ time. Calendar dates ("2026-09-07") never shift day. Missing or
     * unreadable → "Not set".
     */
    public static function date(CarbonInterface|string|null $value, bool $withTime = false): string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return 'Not set';
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value))) {
            $date = self::toNzDate($value);

            return $date === null ? 'Not set' : $date->format($withTime ? 'j M Y' : 'j F Y');
        }

        $date = is_string($value)
            ? self::toNzDate($value)
            : CarbonImmutable::instance($value)->setTimezone(self::timezone());

        if ($date === null) {
            return 'Not set';
        }

        return $date->format($withTime ? 'j M Y, g:i a' : 'j F Y');
    }

    /**
     * "Board Pack Distributed" → "Board pack distributed". Acronyms ("CEO",
     * "NZ", "H&S", "Q1") and PROPER_NOUNS keep their capitals.
     */
    public static function sentence(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $words = preg_split('/\s+/u', $text) ?: [$text];
        $cased = array_map(static function (string $word): string {
            $core = (string) preg_replace('/[^\p{L}\p{N}]/u', '', $word);
            $isAcronym = mb_strlen($core) >= 2
                && preg_match('/\p{Lu}/u', $core) === 1
                && preg_match('/\p{Ll}/u', $core) === 0;

            return $isAcronym ? $word : mb_strtolower($word);
        }, $words);

        $result = implode(' ', $cased);
        foreach (self::PROPER_NOUNS as $noun) {
            $result = (string) preg_replace('/\b'.preg_quote($noun, '/').'\b/iu', $noun, $result);
        }

        return mb_strtoupper(mb_substr($result, 0, 1)).mb_substr($result, 1);
    }

    /**
     * Safe fallback for values with no label: never shows snake_case, dotted
     * keys or class names. "some_new-value" → "Some new value".
     */
    public static function humanise(string|int|null $value): string
    {
        if ($value === null) {
            return '';
        }

        $text = trim((string) $value);
        if (str_contains($text, '\\')) {
            $text = substr((string) strrchr($text, '\\'), 1);
        }

        $text = (string) preg_replace('/([\p{Ll}\p{N}])(\p{Lu})/u', '$1 $2', $text);
        $text = trim((string) preg_replace('/[_.\-\s]+/u', ' ', $text));

        return self::sentence($text);
    }

    /** @return array<int, string> raw, class basename, snake_case basename */
    private static function lookupKeys(string $value): array
    {
        $raw = trim($value);
        $base = str_contains($raw, '\\') ? substr((string) strrchr($raw, '\\'), 1) : $raw;
        $snake = mb_strtolower((string) preg_replace(
            '/[\s-]+/',
            '_',
            (string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $base),
        ));

        return array_values(array_unique([$raw, $base, $snake]));
    }

    private static function formatFinancialYear(int $startYear): string
    {
        return sprintf('%d/%02d', $startYear, ($startYear + 1) % 100);
    }

    private static function toNzDate(string $value): ?CarbonImmutable
    {
        $value = trim($value);

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, self::timezone());

                return $date instanceof CarbonImmutable && $date->format('Y-m-d') === $value ? $date : null;
            }

            // Stored instants without an offset are UTC (app convention).
            return CarbonImmutable::parse($value, 'UTC')->setTimezone(self::timezone());
        } catch (Throwable) {
            return null;
        }
    }

    private static function timezone(): string
    {
        try {
            $timezone = config('app.worker_timezone');
        } catch (Throwable) {
            $timezone = null;
        }

        return is_string($timezone) && $timezone !== '' ? $timezone : self::TIMEZONE;
    }
}
