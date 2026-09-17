/**
 * Governance plain-language labels — the ONE place Governance enum values
 * become words a volunteer board member understands.
 *
 * Wording source of truth:
 *   docs/audits/2026-09-14-governance-plain-language-ux/vocabulary.md
 *
 * ⚠️ Server mirror: `App\Domain\Governance\Support\GovernanceLabels::LABELS`
 * holds the same domain → value → label maps for server-generated text.
 * Both files MUST change together. `tests/fixtures/governance/labels.json`
 * is the shared contract: `governance-labels.test.ts` and
 * `tests/Unit/Governance/GovernanceLabelsTest.php` each assert their map
 * equals it, so editing one side without the other (and the fixture) fails.
 *
 * Rules the helpers enforce:
 *   - sentence case, NZ English;
 *   - unknown values never leak snake_case — they are humanised
 *     ("some_new_value" → "Some new value");
 *   - missing values read "Not set" (labels) / "No data yet" (status chips),
 *     never a blank, a 0 or a green chip.
 */
import type { StatusVariant } from '@/components/ui/status-badge';

/* -------------------------------------------------------------------------- */
/*  Label maps (mirrored in GovernanceLabels.php — change both together)       */
/* -------------------------------------------------------------------------- */

const FREQUENCY = {
    weekly: 'Weekly',
    fortnightly: 'Every 2 weeks',
    monthly: 'Monthly',
    bimonthly: 'Every 2 months',
    quarterly: 'Every 3 months',
    biannual: 'Twice a year',
    annual: 'Once a year',
    annually: 'Once a year',
    ad_hoc: 'As needed',
    event_driven: 'When something happens',
} as const;

const PRIORITY = {
    low: 'Low',
    medium: 'Medium',
    high: 'High',
    critical: 'Critical',
} as const;

const THEME = {
    safety: 'Safety',
    quality: 'Quality of support',
    people: 'People',
    finance: 'Finance',
    compliance: 'Compliance',
    it_resilience: 'Reliable IT systems',
} as const;

const GOAL_STATUS = {
    not_started: 'Not started',
    planning: 'Planning',
    in_progress: 'In progress',
    on_track: 'On track',
    at_risk: 'At risk',
    delayed: 'Delayed',
    off_track: 'Off track',
    on_hold: 'On hold',
    blocked: 'Blocked',
    achieved: 'Achieved',
    partially_achieved: 'Partly achieved',
    missed: 'Missed',
    complete: 'Done',
    completed: 'Done',
    cancelled: 'Cancelled',
} as const;

export const GOVERNANCE_LABELS = {
    /* ── Resolutions & votes ─────────────────────────────────────────────── */
    resolution_status: {
        draft: 'Draft',
        proposed: 'Waiting for the board',
        open: 'Open for voting',
        closed: 'Voting closed',
        implemented: 'Done',
        archived: 'Archived',
        // Legacy rows that stored the outcome in `status`.
        carried: 'Passed',
        defeated: 'Not passed',
        withdrawn: 'Withdrawn',
        cancelled: 'Cancelled',
    },
    resolution_outcome: {
        carried: 'Passed',
        defeated: 'Not passed',
        no_quorum: 'No decision — not enough members took part',
        deferred: 'Put off to a later meeting',
        withdrawn: 'Withdrawn',
    },
    voting_threshold: {
        ordinary: 'More For than Against',
        simple_majority: 'More For than Against',
        special: 'At least two-thirds For',
        two_thirds: 'At least two-thirds For',
        unanimous: 'Everyone entitled votes For',
        // Resolution::determineOutcome() has no rule for these legacy keys
        // and counts them as "more For than Against" — say what really happens.
        special_majority: 'More For than Against',
        three_quarters: 'At least three-quarters For',
    },
    resolution_purpose: {
        decision: 'For decision',
        discussion: 'For discussion',
        information: 'For information',
    },
    decision_type: {
        resolution: 'Resolution',
        motion: 'Resolution',
        budget_approval: 'Budget approval',
        strategic: 'Strategy',
        financial: 'Finance',
        policy: 'Policy',
        operational: 'Operations',
        statutory: 'Legal requirement',
        governance: 'Governance',
    },
    conflict_type: {
        material: 'Personal or financial interest',
        related: 'Link to a person or organisation involved',
        prejudicial: 'Bias or divided loyalty',
        other: 'Other',
    },
    vote: {
        for: 'For',
        against: 'Against',
        abstain: 'Abstain',
    },
    voting_method: {
        in_person: 'In the meeting',
        electronic: 'Online',
        written: 'In writing',
        proxy: 'By proxy',
    },
    authority_subject: {
        voting_profile: 'Voting rules',
        strategic_plan: 'Strategic plan',
        budget_adjustment: 'Budget change',
        budget: 'Budget',
        performance_review: 'Performance review',
    },
    quorum_mode: {
        majority_floor_plus_one: 'More than half of the voting members',
        percentage: 'A set percentage of the voting members',
        fixed_count: 'A set number of voting members',
    },
    legal_form: {
        charitable_trust: 'Charitable trust',
        incorporated_society: 'Incorporated society',
        company: 'Company',
    },
    governing_body: {
        board: 'Board',
        committee: 'Committee',
    },

    /* ── Actions ─────────────────────────────────────────────────────────── */
    action_status: {
        active: 'Open',
        open: 'Not started',
        in_progress: 'In progress',
        blocked: 'Blocked',
        overdue: 'Overdue',
        complete: 'Done',
        completed: 'Done',
        cancelled: 'Cancelled',
    },
    priority: PRIORITY,
    action_source: {
        meeting: 'Meeting',
        governance_meeting: 'Meeting',
        resolution: 'Resolution',
        risk_review: 'Risk review',
        site_risk_review: 'Site risk review',
        compliance_review: 'Compliance review',
        risk_register_entry: 'Risk',
        compliance_obligation: 'Requirement',
        manual: 'Added directly',
    },

    /* ── Meetings, minutes, packs, CEO reports ───────────────────────────── */
    meeting_type: {
        full_board: 'Full board meeting',
        audit_risk: 'Audit and risk committee',
        people: 'People committee',
        finance: 'Finance committee',
        special_general: 'Special general meeting',
        executive_session: 'Board-only session',
    },
    meeting_status: {
        scheduled: 'Scheduled',
        upcoming: 'Coming up',
        agenda_draft: 'Agenda being prepared',
        agenda_final: 'Agenda ready',
        in_progress: 'In progress',
        minutes_pending: 'Minutes to finish',
        minutes_draft: 'Minutes being written',
        minutes_review: 'Minutes waiting for the board',
        minutes_approved: 'Minutes approved',
        minutes_signed: 'Minutes signed',
        archived: 'Archived',
        cancelled: 'Cancelled',
        pack_draft: 'Board pack being prepared',
    },
    rsvp_response: {
        accepted: 'Attending',
        attending: 'Attending',
        tentative: 'Not sure yet',
        unsure: 'Not sure yet',
        declined: 'Sent apologies',
        apology: 'Sent apologies',
    },
    attendance_status: {
        present: 'Present',
        late: 'Arrived late',
        apology: 'Sent apologies',
        no_show: 'Absent without apologies',
        unrecorded: 'Not recorded',
    },
    agenda_item_type: {
        standard: 'Discussion',
        decision: 'For decision',
        consent: 'Routine item (agreed together)',
        for_info: 'For information',
    },
    minutes_status: {
        draft: 'Draft',
        review: 'Waiting for the board',
        in_review: 'Waiting for the board',
        approved: 'Approved',
        signed: 'Signed',
        locked: 'Final version',
        reviewed: 'Sent for approval',
        archived: 'Archived',
    },
    board_pack_status: {
        draft: 'Draft',
        pack_draft: 'Draft',
        queued: 'Waiting to be prepared',
        building: 'Being prepared',
        generated: 'Ready',
        published: 'Ready',
        distributed: 'Sent to members',
        current: 'Current version',
        superseded: 'Replaced by a newer version',
        failed: "Couldn't be prepared",
        archived: 'Archived',
        cancelled: 'Cancelled',
    },
    ceo_report_status: {
        draft: 'Draft',
        submitted: 'Waiting for the board',
        presented: 'Presented to the board',
        overdue: 'Overdue',
    },

    /* ── Risk ────────────────────────────────────────────────────────────── */
    risk_category: {
        client_safety: 'Safety of the people we support',
        reputational: 'Reputation',
        financial: 'Finance',
        it_cyber: 'IT and cyber security',
        workforce: 'Staff and workforce',
        legal_compliance: 'Legal and compliance',
        operational: 'Day-to-day operations',
        clinical: 'Care and clinical',
    },
    risk_strategy: {
        treat: 'Reduce it',
        transfer: 'Share it',
        terminate: 'Stop the activity',
        avoid: 'Stop the activity',
        tolerate: 'Live with it and monitor',
        accept: 'Live with it and monitor',
    },
    risk_status: {
        active: 'Open',
        open: 'Open',
        mitigating: 'Open',
        transferred: 'Open',
        accepted: 'Accepted by the board',
        avoided: 'Closed',
        closed: 'Closed',
        voided: 'Removed from the register',
    },
    risk_level: {
        minimal: 'Minimal',
        low: 'Low',
        medium: 'Medium',
        high: 'High',
        critical: 'Critical',
    },
    control_effectiveness: {
        none: 'No controls in place',
        weak: 'Weak',
        moderate: 'Partly effective',
        strong: 'Strong',
    },
    risk_likelihood: {
        '1': 'Rare',
        '2': 'Unlikely',
        '3': 'Possible',
        '4': 'Likely',
        '5': 'Almost certain',
    },
    risk_impact: {
        '1': 'Insignificant',
        '2': 'Minor',
        '3': 'Moderate',
        '4': 'Major',
        '5': 'Catastrophic',
    },
    risk_treatment_status: {
        planned: 'Planned',
        in_progress: 'In progress',
        overdue: 'Overdue',
        complete: 'Done',
        cancelled: 'Cancelled',
    },
    risk_event_type: {
        incident: 'Incident',
        alert: 'Alert',
        safeguarding: 'Safeguarding concern',
        audit: 'Audit finding',
        breach: 'Privacy breach',
        complaint: 'Complaint',
        hs_event: 'Health and safety event',
    },

    /* ── Compliance, care quality, Te Tiriti ─────────────────────────────── */
    compliance_framework: {
        charities: 'Charities Act 2005 (Charities Services)',
        nga_paerewa:
            'Ngā Paerewa Health and Disability Services Standard (NZS 8134:2021)',
        code_of_rights: 'Code of Rights (Health and Disability Commissioner)',
        hdsa_safety: 'Health and Disability Services (Safety) Act 2001',
        privacy_act: 'Privacy Act 2020',
        hip_code: 'Health Information Privacy Code 2020',
        hswa: 'Health and Safety at Work Act 2015',
        employment: 'Employment Relations Act 2000',
        funding_moh: 'Health New Zealand funding',
        funding_dss: 'Disability Support Services funding',
        funding_msd: 'Ministry of Social Development funding',
        funding_acc: 'ACC funding',
    },
    compliance_status: {
        not_due: 'Not due yet',
        pending: 'Not due yet',
        due_soon: 'Due soon',
        in_progress: 'In progress',
        overdue: 'Overdue',
        complete: 'Done',
        cancelled: 'Cancelled',
    },
    compliance_evidence_type: {
        document: 'Document',
        audit_report: 'Audit report',
        certification: 'Certificate',
        system_export: 'Report from the system',
        attestation: 'Signed confirmation',
        screenshot: 'Screenshot',
        policy: 'Policy',
        procedure: 'Procedure',
    },
    frequency: FREQUENCY,
    care_quality_category: {
        falls: 'Falls',
        medication_errors: 'Medication errors',
        pressure_injuries: 'Skin injuries',
        restraint: 'Use of restraint',
        infections: 'Infections',
        safeguarding: 'Safeguarding concerns',
        complaints: 'Complaints',
    },
    care_quality_status: {
        normal: 'On target',
        warning: 'Needs watching',
        critical: 'Needs action',
        no_data: 'No data yet',
    },
    // English principle names only: the audit found the Māori pairings in
    // TeTiritiObligation::PRINCIPLES are wrong — restore te reo names once a
    // Māori advisor confirms them (GOV audit §3.12).
    te_tiriti_principle: {
        partnership: 'Partnership',
        tino_rangatiratanga: 'Tino rangatiratanga',
        active_protection: 'Active protection',
        equity: 'Equity',
        options: 'Options (Kōwhiringa)',
        participation: 'Tino rangatiratanga',
        protection: 'Active protection',
    },
    te_tiriti_status: {
        not_started: 'Not started',
        in_progress: 'In progress',
        ongoing: 'Part of everyday practice',
        implemented: 'Done',
        achieved: 'Done',
        embedded: 'Part of everyday practice',
    },

    /* ── Policies & documents ────────────────────────────────────────────── */
    policy_status: {
        draft: 'Draft',
        under_review: 'Waiting for the board',
        approved: 'Approved',
        active: 'Approved',
        published: 'Approved',
        superseded: 'Replaced by a newer version',
        archived: 'Archived',
    },
    policy_category: {
        governance: 'Governance',
        financial: 'Finance',
        hr: 'People and HR',
        health_safety: 'Health and safety',
        privacy: 'Privacy',
        clinical: 'Care and clinical',
        operational: 'Operations',
        risk: 'Risk',
        compliance: 'Compliance',
        other: 'Other',
    },
    policy_confirmation_status: {
        not_required: 'No confirmation needed',
        pending: 'To confirm',
        overdue: 'Overdue',
        confirmed: 'Confirmed',
        attested: 'Confirmed',
        not_yet_in_effect: 'Not in effect yet',
    },
    document_type: {
        constitution: 'Governing document',
        terms_of_reference: 'Terms of reference',
        policy: 'Policy',
        procedure: 'Procedure',
        template: 'Template',
        report: 'Report',
        certificate: 'Certificate or registration',
        minutes: 'Minutes',
        other: 'Other',
    },

    /* ── Board finance ───────────────────────────────────────────────────── */
    budget_status: {
        drafting: 'Draft',
        draft: 'Draft',
        proposed: 'Waiting for the board',
        submitted: 'Waiting for the board',
        under_review: 'Waiting for the board',
        pending: 'Waiting for the board',
        approved: 'Approved',
        rejected: 'Not approved',
        closed: 'Closed',
        archived: 'Archived',
    },
    budget_category: {
        staffing: 'Staffing',
        operations: 'Operations',
        fleet: 'Vehicles',
        compliance: 'Compliance',
        capital: 'Equipment and buildings',
        admin: 'Administration',
        other: 'Other',
    },
    budget_change_type: {
        increase: 'Increase',
        decrease: 'Decrease',
        reallocate: 'Move money between lines',
    },
    budget_change_status: {
        draft: 'Draft',
        submitted: 'Waiting for approval',
        under_review: 'Waiting for approval',
        approved: 'Approved',
        rejected: 'Not approved',
        declined: 'Not approved',
    },
    // Spend requests are approved by the person with the right delegation,
    // not always the whole board — so the chip says "approval", truthfully.
    spend_status: {
        draft: 'Draft',
        submitted: 'Waiting for approval',
        pending: 'Waiting for approval',
        requires_board: 'Needs board approval',
        approved: 'Approved',
        rejected: 'Not approved',
        expired: 'Expired',
    },
    spend_category: {
        capex: 'Equipment or building purchase',
        opex: 'Running costs outside the budget',
        supplier_contract: 'Supplier contract',
        donor_restricted: 'Donor-restricted funds',
    },

    /* ── Strategy & performance ──────────────────────────────────────────── */
    strategic_plan_status: {
        draft: 'Draft',
        review: 'Waiting for the board',
        consultation: 'Out for feedback',
        approved: 'Approved',
        active: 'Approved',
        superseded: 'Replaced by a newer version',
        archived: 'Archived',
        completed: 'Archived',
    },
    plan_length: {
        annual: '1-year plan',
        '3_year': '3-year plan',
        '5_year': '5-year plan',
    },
    theme: THEME,
    goal_status: GOAL_STATUS,
    performance_review_status: {
        drafting: 'Draft',
        draft: 'Draft',
        active: 'In progress',
        self_review: 'Waiting for self-assessment',
        peer_review: 'Collecting feedback',
        board_review: 'Waiting for the board',
        completed: 'Done',
        closed: 'Closed',
    },
    performance_review_type: {
        quarterly: 'Quarterly',
        annual: 'Annual',
        ad_hoc: 'One-off',
    },
    performance_rating: {
        exceeds: 'Exceeds expectations',
        meets: 'Meets expectations',
        needs_improvement: 'Needs improvement',
        unsatisfactory: 'Below expectations',
    },
    performance_decision: {
        remuneration_increase: 'Increase pay',
        maintain: 'Keep pay the same',
        development_plan: 'Agree a development plan',
        performance_improvement: 'Agree a performance improvement plan',
    },
    reviewer_role: {
        board_member: 'Board member',
        peer: 'Peer',
        direct_report: 'Direct report',
        self: 'Self-assessment',
    },
    evaluation_status: {
        draft: 'Draft',
        active: 'Open for responses',
        open: 'Open for responses',
        closed: 'Closed',
        reported: 'Results shared',
    },
    evaluation_type: {
        board: 'Whole board',
        committee: 'Committee',
        chair: 'Chair',
        individual: 'Individual members',
        annual_self_assessment: 'Annual self-assessment',
        peer_review: 'Peer review',
        external_review: 'External review',
    },

    /* ── Board & members ─────────────────────────────────────────────────── */
    board_role: {
        chair: 'Chair',
        deputy_chair: 'Deputy chair',
        secretary: 'Secretary',
        treasurer: 'Treasurer',
        member: 'Board member',
        observer: "Observer (can't vote)",
    },
    board_member_standing: {
        active: 'Current member',
        inactive: 'Not active',
        term_not_started: 'Term not started',
        term_ended: 'Term ended',
        observer: "Observer (can't vote)",
    },
    interest_type: {
        financial: 'Financial',
        personal: 'Personal',
        professional: 'Professional',
        family: 'Family',
        directorship: 'Director or trustee role',
        employment: 'Employment',
        property: 'Property',
        other: 'Other',
    },
    interest_nature: {
        direct: 'Direct',
        indirect: 'Through someone close to me',
    },
    interest_status: {
        current: 'Current',
        ended: 'Ended',
    },

    /* ── Audit log ───────────────────────────────────────────────────────── */
    audit_entity_type: {
        action_item: 'Action',
        board_evaluation: 'Board evaluation',
        board_member: 'Board member',
        board_member_interest: 'Interest',
        board_pack: 'Board pack',
        budget: 'Budget',
        budget_adjustment: 'Budget change',
        budget_allocation: 'Budget allocation',
        ceo_board_report: 'CEO report',
        compliance_obligation: 'Requirement',
        governance_document: 'Document',
        governance_meeting: 'Meeting',
        governance_policy: 'Policy',
        governance_setting: 'Governance settings',
        governance_voting_profile: 'Voting rules',
        incident_governance_escalation: 'Incident raised with the board',
        meeting_minute: 'Minutes',
        notifiable_incident: 'Notifiable incident',
        performance_review: 'Performance review',
        resolution: 'Resolution',
        risk_register_entry: 'Risk',
        risk_treatment: 'Action to reduce a risk',
        safeguarding_concern: 'Safeguarding concern',
        spend_approval: 'Spend request',
        strategic_plan: 'Strategic plan',
        te_tiriti_obligation: 'Te Tiriti commitment',
    },
    // Phrases complete the sentence "<person> … " in the audit log.
    audit_event: {
        viewed: 'viewed',
        downloaded: 'downloaded',
        edited: 'edited',
        created: 'created',
        updated: 'updated',
        deleted: 'deleted',
        approved: 'approved',
        voted: 'voted on',
        exported: 'exported',
        'resolution.created': 'created a resolution',
        'resolution.updated': 'updated a resolution',
        'resolution.voted': 'voted on a resolution',
        'resolution.conflict_declared': 'declared a conflict of interest',
        'resolution.voting_opened': 'opened voting',
        'resolution.voting_closed': 'closed voting',
        'resolution.finalized': 'finalised a resolution',
        'resolution.authority_bound':
            'linked a resolution to the record it approves',
        'resolution.authority_unbound':
            'removed the record linked to a resolution',
        'resolution.authority_consumed':
            'used a resolution to approve a record',
        'resolution.attachment_added': 'added a file to a resolution',
        'resolution.attachment_removed': 'removed a file from a resolution',
        'minutes.approved': 'approved minutes',
        'minutes.signed': 'signed minutes',
        'board_pack.read': 'confirmed reading a board pack',
        'board_pack.downloaded': 'downloaded a board pack',
        'board_pack.attachment_added': 'added a file to a board pack',
        'board_pack.attachment_removed': 'removed a file from a board pack',
        'board_pack.attachment_downloaded':
            'downloaded a file from a board pack',
        'policy.attested': 'confirmed reading a policy',
        'budget.proposed': 'sent a budget to the board',
        'budget.approved': 'approved a budget',
        'budget.allocation_created': 'added a budget allocation',
        'spend_approval.created': 'created a spend request',
        'spend_approval.updated': 'updated a spend request',
        'spend_approval.submitted': 'submitted a spend request',
        'spend_approval.approved': 'approved a spend request',
        'spend_approval.rejected': 'declined a spend request',
        'spend_approval.attachment_added': 'added a file to a spend request',
        'spend_approval.attachment_removed':
            'removed a file from a spend request',
        'risk_treatment.attachment_added':
            'added a file to an action to reduce a risk',
        'risk_treatment.attachment_removed':
            'removed a file from an action to reduce a risk',
        'compliance.auto_created': 'automatically added a requirement',
        'action.auto_created': 'automatically created an action',
        'incident.escalated': 'raised an incident with the board',
        'notifiable_incident.auto_created':
            'automatically recorded a notifiable incident',
        'notifiable_incident.reopened_after_reclassification':
            'reopened a notifiable incident after it was reclassified',
        'notifiable_incident.retracted_after_reclassification':
            'withdrew a notifiable incident after it was reclassified',
        'settings.updated': 'changed governance settings',
        'governance_rules.updated': 'changed the voting rules',
        'governance_rules.activated':
            "recorded the board's approval of the voting rules",
        board_member_appointed: 'appointed a board member',
        board_member_removed: 'removed a board member',
        role_changed: 'changed a board role',
        policy_updated: 'updated a policy',
        key_person_changed: 'changed a key person',
        'action.escalated': 'raised an action with the board',
        'action.evidence_added': 'added evidence to an action',
        'action.evidence_removed': 'removed evidence from an action',
        'resolution.published': 'shared a resolution with members',
        'budget.returned_to_drafting': 'returned a budget to drafting',
        'risk.accepted': 'recorded the board accepting a risk',
        'risk.closed': 'closed a risk',
        'risk_treatment.completed': 'marked a risk action as done',
        'risk_treatment.due_date_changed':
            'changed the due date of a risk action',
        'policy.approved': 'approved a policy',
        'compliance.updated': 'updated a requirement',
        'compliance.evidence_downloaded':
            'downloaded evidence for a requirement',
    },
} as const satisfies Record<string, Record<string, string>>;

export type GovernanceLabelDomain = keyof typeof GOVERNANCE_LABELS;

type Maybe<T> = T | null | undefined;

/* -------------------------------------------------------------------------- */
/*  Casing + fallbacks                                                         */
/* -------------------------------------------------------------------------- */

/** Proper nouns that keep their capitals inside sentence-cased text. */
const PROPER_NOUNS = [
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

function escapeRegExp(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * "Board Pack Distributed" → "Board pack distributed". Acronyms ("CEO",
 * "NZ", "H&S", "Q1") and the proper nouns above keep their capitals.
 * Mirrors GovernanceLabels::sentence().
 */
export function sentenceCase(text: Maybe<string>): string {
    if (!text) return '';
    const words = String(text).trim().split(/\s+/);
    const cased = words
        .map((word) => {
            const core = word.replace(/[^\p{L}\p{N}]/gu, '');
            const isAcronym =
                core.length >= 2 &&
                /\p{Lu}/u.test(core) &&
                !/\p{Ll}/u.test(core);
            return isAcronym ? word : word.toLocaleLowerCase('en-NZ');
        })
        .join(' ');
    let result = cased;
    for (const noun of PROPER_NOUNS) {
        result = result.replace(
            new RegExp(`\\b${escapeRegExp(noun)}\\b`, 'giu'),
            noun,
        );
    }
    return result.charAt(0).toLocaleUpperCase('en-NZ') + result.slice(1);
}

/**
 * Safe fallback for values with no label: never shows snake_case, dotted
 * keys or class names. "some_new-value" → "Some new value";
 * "App\\Models\\BoardPack" → "Board pack". Mirrors GovernanceLabels::humanise().
 */
export function humaniseGovernanceValue(value: Maybe<string | number>): string {
    if (value === null || value === undefined) return '';
    let text = String(value).trim();
    if (text.includes('\\')) text = text.split('\\').pop() ?? text;
    text = text
        .replace(/([\p{Ll}\p{N}])(\p{Lu})/gu, '$1 $2')
        .replace(/[_.\-\s]+/g, ' ')
        .trim();
    return sentenceCase(text);
}

/** Lookup keys tried in order: raw, class basename, snake_case basename. */
function lookupKeys(value: string): string[] {
    const raw = value.trim();
    const base = raw.includes('\\') ? (raw.split('\\').pop() ?? raw) : raw;
    const snake = base
        .replace(/([a-z0-9])([A-Z])/g, '$1_$2')
        .replace(/[\s-]+/g, '_')
        .toLowerCase();
    return Array.from(new Set([raw, base, snake]));
}

/** Plain label for any Governance enum value. Missing → "Not set". */
export function governanceLabel(
    domain: GovernanceLabelDomain,
    value: Maybe<string | number>,
): string {
    if (value === null || value === undefined || String(value).trim() === '') {
        return 'Not set';
    }
    const map = GOVERNANCE_LABELS[domain] as Record<string, string>;
    for (const key of lookupKeys(String(value))) {
        if (Object.prototype.hasOwnProperty.call(map, key)) return map[key];
    }
    return humaniseGovernanceValue(value);
}

const labelFor =
    (domain: GovernanceLabelDomain) =>
    (value: Maybe<string | number>): string =>
        governanceLabel(domain, value);

/* ── Per-domain helpers ─────────────────────────────────────────────────── */

export const resolutionStatusLabel = labelFor('resolution_status');
export const resolutionOutcomeLabel = labelFor('resolution_outcome');
export const votingThresholdLabel = labelFor('voting_threshold');
export const resolutionPurposeLabel = labelFor('resolution_purpose');
export const decisionTypeLabel = labelFor('decision_type');
export const conflictTypeLabel = labelFor('conflict_type');
export const voteLabel = labelFor('vote');
export const votingMethodLabel = labelFor('voting_method');
export const authoritySubjectLabel = labelFor('authority_subject');
export const quorumModeLabel = labelFor('quorum_mode');
export const legalFormLabel = labelFor('legal_form');
export const governingBodyLabel = labelFor('governing_body');
export const actionStatusLabel = labelFor('action_status');
export const priorityLabel = labelFor('priority');
export const actionSourceLabel = labelFor('action_source');
export const meetingTypeLabel = labelFor('meeting_type');
export const meetingStatusLabel = labelFor('meeting_status');
export const rsvpResponseLabel = labelFor('rsvp_response');
export const attendanceStatusLabel = labelFor('attendance_status');
export const agendaItemTypeLabel = labelFor('agenda_item_type');
export const minutesStatusLabel = labelFor('minutes_status');
export const boardPackStatusLabel = labelFor('board_pack_status');
export const ceoReportStatusLabel = labelFor('ceo_report_status');
export const riskCategoryLabel = labelFor('risk_category');
export const riskStrategyLabel = labelFor('risk_strategy');
export const riskStatusLabel = labelFor('risk_status');
export const riskLevelLabel = labelFor('risk_level');
export const controlEffectivenessLabel = labelFor('control_effectiveness');
export const riskLikelihoodLabel = labelFor('risk_likelihood');
export const riskImpactLabel = labelFor('risk_impact');
export const riskTreatmentStatusLabel = labelFor('risk_treatment_status');
export const riskEventTypeLabel = labelFor('risk_event_type');
export const complianceFrameworkLabel = labelFor('compliance_framework');
export const complianceStatusLabel = labelFor('compliance_status');
export const complianceEvidenceTypeLabel = labelFor('compliance_evidence_type');
export const frequencyLabel = labelFor('frequency');
export const careQualityCategoryLabel = labelFor('care_quality_category');
export const careQualityStatusLabel = labelFor('care_quality_status');
export const teTiritiPrincipleLabel = labelFor('te_tiriti_principle');
export const teTiritiStatusLabel = labelFor('te_tiriti_status');
export const policyStatusLabel = labelFor('policy_status');
export const policyCategoryLabel = labelFor('policy_category');
export const policyConfirmationStatusLabel = labelFor(
    'policy_confirmation_status',
);
/** Policy "read and confirm" cadence (annual / biannual / quarterly). */
export const confirmationFrequencyLabel = frequencyLabel;
export const documentTypeLabel = labelFor('document_type');
export const budgetStatusLabel = labelFor('budget_status');
export const budgetCategoryLabel = labelFor('budget_category');
export const budgetChangeTypeLabel = labelFor('budget_change_type');
export const budgetChangeStatusLabel = labelFor('budget_change_status');
export const spendStatusLabel = labelFor('spend_status');
export const spendCategoryLabel = labelFor('spend_category');
export const strategicPlanStatusLabel = labelFor('strategic_plan_status');
export const planLengthLabel = labelFor('plan_length');
export const themeLabel = labelFor('theme');
/** Strategic goals, measures of success, initiatives and CEO review goals. */
export const goalStatusLabel = labelFor('goal_status');
export const performanceReviewStatusLabel = labelFor(
    'performance_review_status',
);
export const performanceReviewTypeLabel = labelFor('performance_review_type');
export const performanceRatingLabel = labelFor('performance_rating');
export const performanceDecisionLabel = labelFor('performance_decision');
export const reviewerRoleLabel = labelFor('reviewer_role');
export const evaluationStatusLabel = labelFor('evaluation_status');
export const evaluationTypeLabel = labelFor('evaluation_type');
export const boardRoleLabel = labelFor('board_role');
export const boardMemberStandingLabel = labelFor('board_member_standing');
export const interestTypeLabel = labelFor('interest_type');
export const interestNatureLabel = labelFor('interest_nature');
export const interestStatusLabel = labelFor('interest_status');
export const auditEntityTypeLabel = labelFor('audit_entity_type');

/** "voted on a resolution" — the verb phrase after the person's name. */
export function auditEventLabel(value: Maybe<string>): string {
    if (!value || !value.trim()) return 'did something';
    const map = GOVERNANCE_LABELS.audit_event as Record<string, string>;
    for (const key of lookupKeys(value)) {
        if (Object.prototype.hasOwnProperty.call(map, key)) return map[key];
    }
    return humaniseGovernanceValue(value).toLocaleLowerCase('en-NZ');
}

/**
 * "Quarter 1, 2026" / "Annual review 2026" from stored cycles like
 * "2026-Q1" / "2026-Annual". Anything else is humanised.
 */
export function reviewCycleLabel(cycle: Maybe<string>): string {
    if (!cycle || !cycle.trim()) return 'Not set';
    const value = cycle.trim();
    const quarter = /^(\d{4})[-\s]?Q([1-4])$/i.exec(value);
    if (quarter) return `Quarter ${quarter[2]}, ${quarter[1]}`;
    const annual = /^(\d{4})[-\s]?annual$/i.exec(value);
    if (annual) return `Annual review ${annual[1]}`;
    return humaniseGovernanceValue(value);
}

/** Server risk bands (RiskScoringService::getRiskLevel) for a 1–25 score. */
export function riskLevelForScore(
    score: Maybe<number>,
): keyof (typeof GOVERNANCE_LABELS)['risk_level'] | null {
    if (score === null || score === undefined || !Number.isFinite(score)) {
        return null;
    }
    if (score >= 20) return 'critical';
    if (score >= 15) return 'high';
    if (score >= 10) return 'medium';
    if (score >= 5) return 'low';
    return 'minimal';
}

/* -------------------------------------------------------------------------- */
/*  Status chips — one label + one StatusBadge variant per status             */
/* -------------------------------------------------------------------------- */

const N: StatusVariant = 'neutral';
const I: StatusVariant = 'info';
const W: StatusVariant = 'warning';
const S: StatusVariant = 'success';
const C: StatusVariant = 'critical';

/**
 * Variants follow vocabulary.md "Status chips": Draft / Archived / Replaced
 * → neutral · Waiting for the board → warning · Open for voting → info ·
 * Approved / Passed / Done / Confirmed → success · Not approved / Not passed /
 * Overdue → critical.
 */
export const GOVERNANCE_STATUS_VARIANTS = {
    resolution_status: {
        draft: N,
        proposed: W,
        open: I,
        closed: N,
        implemented: S,
        archived: N,
        carried: S,
        defeated: C,
        withdrawn: N,
        cancelled: N,
    },
    resolution_outcome: {
        carried: S,
        defeated: C,
        no_quorum: W,
        deferred: N,
        withdrawn: N,
    },
    action_status: {
        active: I,
        open: N,
        in_progress: I,
        blocked: C,
        overdue: C,
        complete: S,
        completed: S,
        cancelled: N,
    },
    priority: { low: N, medium: I, high: W, critical: C },
    meeting_status: {
        scheduled: I,
        upcoming: I,
        agenda_draft: N,
        agenda_final: I,
        in_progress: I,
        minutes_pending: W,
        minutes_draft: N,
        minutes_review: W,
        minutes_approved: S,
        minutes_signed: S,
        archived: N,
        cancelled: N,
        pack_draft: I,
    },
    rsvp_response: {
        accepted: S,
        attending: S,
        tentative: I,
        unsure: I,
        declined: N,
        apology: N,
    },
    attendance_status: {
        present: S,
        late: W,
        apology: N,
        no_show: C,
        unrecorded: N,
    },
    minutes_status: {
        draft: N,
        review: W,
        in_review: W,
        approved: S,
        signed: S,
        locked: S,
        reviewed: W,
        archived: N,
    },
    board_pack_status: {
        draft: N,
        pack_draft: N,
        queued: I,
        building: I,
        generated: I,
        published: I,
        distributed: S,
        current: S,
        superseded: N,
        failed: C,
        archived: N,
        cancelled: N,
    },
    ceo_report_status: { draft: N, submitted: W, presented: S, overdue: C },
    risk_status: {
        active: I,
        open: I,
        mitigating: I,
        transferred: I,
        accepted: S,
        avoided: N,
        closed: N,
        voided: N,
    },
    risk_level: { minimal: S, low: S, medium: W, high: W, critical: C },
    risk_treatment_status: {
        planned: N,
        in_progress: I,
        overdue: C,
        complete: S,
        cancelled: N,
    },
    compliance_status: {
        not_due: N,
        pending: N,
        due_soon: W,
        in_progress: I,
        overdue: C,
        complete: S,
        cancelled: N,
    },
    care_quality_status: { normal: S, warning: W, critical: C, no_data: N },
    te_tiriti_status: {
        not_started: N,
        in_progress: I,
        ongoing: S,
        implemented: S,
        achieved: S,
        embedded: S,
    },
    policy_status: {
        draft: N,
        under_review: W,
        approved: S,
        active: S,
        published: S,
        superseded: N,
        archived: N,
    },
    policy_confirmation_status: {
        not_required: N,
        pending: W,
        overdue: C,
        confirmed: S,
        attested: S,
        not_yet_in_effect: N,
    },
    budget_status: {
        drafting: N,
        draft: N,
        proposed: W,
        submitted: W,
        under_review: W,
        pending: W,
        approved: S,
        rejected: C,
        closed: N,
        archived: N,
    },
    budget_change_status: {
        draft: N,
        submitted: W,
        under_review: W,
        approved: S,
        rejected: C,
        declined: C,
    },
    spend_status: {
        draft: N,
        submitted: W,
        pending: W,
        requires_board: W,
        approved: S,
        rejected: C,
        expired: N,
    },
    strategic_plan_status: {
        draft: N,
        review: W,
        consultation: I,
        approved: S,
        active: S,
        superseded: N,
        archived: N,
        completed: N,
    },
    goal_status: {
        not_started: N,
        planning: N,
        in_progress: I,
        on_track: I,
        at_risk: W,
        delayed: W,
        off_track: C,
        on_hold: W,
        blocked: C,
        achieved: S,
        partially_achieved: W,
        missed: C,
        complete: S,
        completed: S,
        cancelled: N,
    },
    performance_review_status: {
        drafting: N,
        draft: N,
        active: I,
        self_review: I,
        peer_review: I,
        board_review: W,
        completed: S,
        closed: N,
    },
    evaluation_status: {
        draft: N,
        active: I,
        open: I,
        closed: N,
        reported: S,
    },
    board_member_standing: {
        active: S,
        inactive: N,
        term_not_started: N,
        term_ended: N,
        observer: N,
    },
    interest_status: { current: I, ended: N },
} as const satisfies Partial<
    Record<GovernanceLabelDomain, Record<string, StatusVariant>>
>;

export type GovernanceStatusDomain = keyof typeof GOVERNANCE_STATUS_VARIANTS;

export interface GovernanceStatusChip {
    label: string;
    variant: StatusVariant;
}

/**
 * One chip for a Governance status: `<StatusBadge variant={chip.variant}>
 * {chip.label}</StatusBadge>`. Missing → "No data yet" (neutral); unknown →
 * humanised label, neutral.
 */
export function governanceStatus(
    domain: GovernanceStatusDomain,
    value: Maybe<string>,
): GovernanceStatusChip {
    if (value === null || value === undefined || value.trim() === '') {
        return { label: 'No data yet', variant: 'neutral' };
    }
    const variants = GOVERNANCE_STATUS_VARIANTS[domain] as Record<
        string,
        StatusVariant
    >;
    const key = lookupKeys(value).find((candidate) =>
        Object.prototype.hasOwnProperty.call(variants, candidate),
    );
    return {
        label: governanceLabel(domain, value),
        variant: key ? variants[key] : 'neutral',
    };
}

/**
 * The single header chip for a resolution: the outcome wins once voting has
 * closed, so a record never shows "Voting closed" beside "Passed".
 */
export function resolutionChip(
    status: Maybe<string>,
    outcome: Maybe<string>,
): GovernanceStatusChip {
    if (status === 'closed' && outcome) {
        return governanceStatus('resolution_outcome', outcome);
    }
    return governanceStatus('resolution_status', status);
}

/* -------------------------------------------------------------------------- */
/*  Money, references, financial years                                         */
/* -------------------------------------------------------------------------- */

/**
 * "$85,000" / "$85,000.50" / "-$1,200". Cents show only when there are some,
 * unless `cents` forces them on (true) or off (false). Missing → "Not stated".
 */
export function formatNzd(
    amount: Maybe<number | string>,
    options: { cents?: boolean } = {},
): string {
    if (amount === null || amount === undefined) return 'Not stated';
    if (typeof amount === 'string' && amount.trim() === '') return 'Not stated';
    const value = typeof amount === 'number' ? amount : Number(amount);
    if (!Number.isFinite(value)) return 'Not stated';

    const hasCents = Math.round(Math.abs(value) * 100) % 100 !== 0;
    const showCents = options.cents ?? hasCents;
    const formatted = new Intl.NumberFormat('en-NZ', {
        minimumFractionDigits: showCents ? 2 : 0,
        maximumFractionDigits: showCents ? 2 : 0,
    }).format(Math.abs(value));
    const rounded = Number(formatted.replace(/,/g, ''));
    return `${value < 0 && rounded !== 0 ? '-' : ''}$${formatted}`;
}

/** "Ref RES-2026-004" — references go last and muted. Missing → "". */
export function refSuffix(code: Maybe<string>): string {
    const value = code?.trim();
    return value ? `Ref ${value}` : '';
}

/**
 * NZ financial year (1 July – 30 June) → "2025/26".
 * - a date (Date / ISO string / "YYYY-MM-DD") → the year it falls in, using
 *   the Auckland calendar date;
 * - a number (or "2026") → the financial year ENDING in that year
 *   (NZ "FY2026" = 1 July 2025 – 30 June 2026 = "2025/26");
 * - "2025-2026" / "2025/26" → normalised. Missing/invalid → "Not set".
 */
export function financialYearLabel(
    value: Maybe<Date | string | number>,
): string {
    if (value === null || value === undefined || value === '') return 'Not set';
    const format = (startYear: number) =>
        `${startYear}/${String((startYear + 1) % 100).padStart(2, '0')}`;

    if (typeof value === 'number') {
        return Number.isInteger(value) ? format(value - 1) : 'Not set';
    }
    if (typeof value === 'string') {
        const text = value.trim();
        const range = /^(\d{4})\s*[-/]\s*(\d{2}|\d{4})$/.exec(text);
        if (range) {
            const start = Number(range[1]);
            const end = Number(range[2]);
            const isYearRange =
                range[2].length === 4
                    ? end === start + 1
                    : end === (start + 1) % 100;
            if (isYearRange) return format(start);
            // "2026-06" is a month (June 2026), not a year range.
            if (range[2].length === 2 && end >= 1 && end <= 12) {
                return format(end >= 7 ? start : start - 1);
            }
            return 'Not set';
        }
        const fy = /^(?:FY\s*)?(\d{4})$/i.exec(text);
        if (fy) return format(Number(fy[1]) - 1);
        // Calendar dates ("2026-06-30") never shift day with timezones.
        const calendar = /^(\d{4})-(\d{2})-(\d{2})$/.exec(text);
        if (calendar) {
            const month = Number(calendar[2]);
            if (month < 1 || month > 12) return 'Not set';
            const year = Number(calendar[1]);
            return format(month >= 7 ? year : year - 1);
        }
    }

    const date = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(date.getTime())) return 'Not set';
    const parts = Object.fromEntries(
        new Intl.DateTimeFormat('en-NZ', {
            timeZone: 'Pacific/Auckland',
            year: 'numeric',
            month: 'numeric',
        })
            .formatToParts(date)
            .map((part) => [part.type, part.value]),
    );
    const year = Number(parts.year);
    const month = Number(parts.month);
    if (!year || !month) return 'Not set';
    return format(month >= 7 ? year : year - 1);
}

/* -------------------------------------------------------------------------- */
/*  How a resolution passes                                                    */
/* -------------------------------------------------------------------------- */

type ThresholdRule = 'ordinary' | 'two_thirds' | 'three_quarters' | 'unanimous';

function thresholdRule(threshold: Maybe<string>): ThresholdRule {
    switch (threshold) {
        case 'special':
        case 'two_thirds':
            return 'two_thirds';
        case 'three_quarters':
            return 'three_quarters';
        case 'unanimous':
            return 'unanimous';
        default:
            // Mirrors Resolution::determineOutcome(): anything else is
            // decided by more For than Against.
            return 'ordinary';
    }
}

/** One plain sentence on how a resolution passes under its voting rule. */
export function thresholdExplanation(threshold: Maybe<string>): string {
    switch (thresholdRule(threshold)) {
        case 'two_thirds':
            return "It passes if at least two-thirds of the For and Against votes are For — abstentions don't count either way.";
        case 'three_quarters':
            return "It passes if at least three-quarters of the For and Against votes are For — abstentions don't count either way.";
        case 'unanimous':
            return 'It passes only if every voting member votes For — one Against vote, abstention, step-aside or missing vote means it does not pass.';
        default:
            return "It passes if more voting members vote For than Against — abstentions don't count either way.";
    }
}

const THRESHOLD_NEED: Record<ThresholdRule, string> = {
    ordinary: 'more For than Against',
    two_thirds: 'at least two-thirds of the For and Against votes to be For',
    three_quarters:
        'at least three-quarters of the For and Against votes to be For',
    unanimous: 'every voting member to vote For',
};

export interface OutcomeSentenceInput {
    outcome: Maybe<string>;
    for: number;
    against: number;
    abstain?: Maybe<number>;
    /** Voting members who took part. */
    participating?: Maybe<number>;
    /** Quorum: how many voting members had to take part. */
    required?: Maybe<number>;
    /** Everyone entitled to vote. */
    votingMembers?: Maybe<number>;
    threshold?: Maybe<string>;
}

/**
 * "Passed. 5 voted For and 2 Against (1 abstained). It needed more For than
 * Against, and at least 4 of 7 voting members taking part (7 did)."
 */
export function outcomeSentence(input: OutcomeSentenceInput): string {
    const forCount = Math.max(0, input.for ?? 0);
    const against = Math.max(0, input.against ?? 0);
    const abstain = Math.max(0, input.abstain ?? 0);

    const lead = input.outcome
        ? `${resolutionOutcomeLabel(input.outcome)}.`
        : 'No result yet.';

    const votes =
        forCount + against + abstain === 0
            ? 'No votes were cast.'
            : `${forCount} voted For and ${against} Against${
                  abstain > 0 ? ` (${abstain} abstained)` : ''
              }.`;

    let need = `It needed ${THRESHOLD_NEED[thresholdRule(input.threshold)]}`;
    if (
        input.required !== null &&
        input.required !== undefined &&
        input.votingMembers !== null &&
        input.votingMembers !== undefined
    ) {
        need += `, and at least ${input.required} of ${input.votingMembers} voting members taking part`;
        if (input.participating !== null && input.participating !== undefined) {
            need += ` (${input.participating} did)`;
        }
    }

    return `${lead} ${votes} ${need}.`;
}
