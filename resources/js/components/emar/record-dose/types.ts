/* eMAR P01 — "Record a dose: one pop-up everywhere". The shapes the shared
 * recording dialog reads from GET /meds/today/doses/requirements and
 * /meds/today/prn/{order}/requirements (DoseRecordingRequirements on the
 * server) and the target each entry point opens it for. */

/** Nothing can be recorded: the server answers with the reason and the house only (P0-1). */
export type BlockAllKey = 'notClockedIn' | 'notOnShift' | 'siteNotApproved' | 'controlledNotAllowed' | 'awaitingVerification' | 'prnLimit';

/** "Given" can't be recorded; a refusal, withhold or absence still can. */
export type BlockGivenKey = 'covertMissing' | 'allergyBlocked' | 'safetyBlocked' | 'noWitness';

export type BlockKey = BlockAllKey | BlockGivenKey;

export interface Block<K extends BlockKey = BlockKey> {
    key: K;
    facts: Record<string, unknown>;
}

/**
 * The whole answer when nothing can be recorded: the reason and the house,
 * never the person, their allergies, the order or colleagues (P0-1). The
 * dialog names the person and medicine from the row that opened it.
 */
export interface BlockedRequirements {
    kind: 'scheduled' | 'prn';
    block_all: Block<BlockAllKey>;
    checked_at: string;
}

export type CompetencyState =
    | 'current'
    | 'expired'
    | 'not_current'
    | 'restricted'
    | 'cosigner'
    | 'area';

export type SecondPersonKind = 'witness' | 'rule' | 'cosigner' | 'amount';

/** A colleague on shift at the house now, and whether they can confirm this dose — never why not. */
export interface Candidate {
    id: number;
    name: string;
    can_confirm: boolean;
}

export interface Observation {
    key: string;
    label: string;
    unit: string;
    fields: string[];
}

export interface DoseRequirements {
    witness_override?: { id: number; expires_at: string; followup_due_at: string } | null;
    kind: 'scheduled' | 'prn';
    order: {
        id: number;
        name: string;
        dosage: string | null;
        dose_amount: number | null;
        dose_unit: string | null;
        route: string | null;
        form: string | null;
        instructions: string | null;
        prescriber: string | null;
        controlled: boolean;
        high_risk: boolean;
        witness_required: boolean;
        is_prn: boolean;
        version: number | null;
        verified: { at: string | null; by: string | null } | null;
        awaiting_check: boolean;
        /** Controlled medicines: the stock's unit, and whether the order's amount is in it (NF-18). */
        stock: { unit: string | null; from_order: boolean } | null;
    };
    due: {
        due_at: string;
        window_opens_at: string;
        window_closes_at: string;
        before_minutes: number;
        after_minutes: number;
        state: 'notdue' | 'due' | 'late';
        late_minutes: number;
    } | null;
    block_all: null;
    block_given: Block<BlockGivenKey> | null;
    competency: {
        state: CompetencyState;
        message: string | null;
    };
    second_person: {
        kind: SecondPersonKind | null;
        rule_sentences: string[];
        anyone_available: boolean;
        may_go_unconfirmed: boolean;
        candidates: Candidate[];
    };
    observations: Observation[];
    observation_rule_sentences: string[];
    allergy: {
        status: 'recorded' | 'none_recorded' | 'unavailable';
        list: string[];
        match: { allergen: string | null; source: string | null; severity: string | null } | null;
        rule: 'warn' | 'block' | string;
    };
    covert: { state: 'none' | 'active' | 'missing'; plan: string | null; review_date: string | null };
    support: 'administer' | 'prompt' | 'assist' | 'independent';
    variable: boolean;
    not_simple: string[];
    person: {
        id: number;
        preferred_name: string;
        legal_name: string;
        house: string | null;
        born: string | null;
        age: number | null;
        nhi: string | null;
        photo_url: string | null;
    };
    who_can_give: { id: number; name: string }[];
    house_lead: { id: number; name: string } | null;
    /** The house's on-call contact now (Settings › On-call, P11 B2), or why there's nobody. */
    on_call: { configured: boolean; name: string | null; phone: string | null; warning: string | null };
    prn: {
        count_24h: number;
        max_24h: number | null;
        min_hours_between: number | null;
        last_at: string | null;
        last_by: string | null;
        reasons: string[];
    } | null;
    reoffer: { refusal_id: number; refused_at: string | null; follow_up_due_at: string | null } | null;
    options: {
        late_reasons: Record<string, string>;
        amount_reasons: Record<string, string>;
        withheld_reasons: Record<string, string>;
        away_reasons: Record<string, string>;
        more_severities: string[];
    };
    checked_at: string;
}

/** What the requirements endpoints answer: everything the dialog needs, or only why nothing can be recorded. */
export type RequirementsAnswer = DoseRequirements | BlockedRequirements;

export const isBlockedAnswer = (answer: RequirementsAnswer): answer is BlockedRequirements => answer.block_all !== null;

/** Where the dialog was opened from ("Opened from" on the rail and review). */
export type EntryPoint =
    | 'meds-today'
    | 'round'
    | 'as-needed'
    | 'prn-records'
    | 'mar'
    | 'dashboard'
    | 'client-profile'
    | 'shift'
    | 'transport'
    | 'follow-up';

export const ENTRY_LABEL: Record<EntryPoint, string> = {
    'meds-today': 'Meds today › Schedule',
    round: 'Meds today › Rounds (guided round)',
    'as-needed': 'Meds today › As-needed',
    'prn-records': 'Medication › As-needed records',
    mar: 'MAR chart',
    dashboard: 'Medication dashboard',
    'client-profile': 'Client profile',
    shift: 'Shift',
    transport: 'Fleet › Transport',
    'follow-up': 'A follow-up',
};

/**
 * The names the opening row already shows. When nothing can be recorded the
 * server sends no person or order details (P0-1), so the dialog words the
 * reason with these.
 */
export interface DoseLabel {
    person: string;
    medicine: string;
}

export type DoseTarget =
    | { kind: 'scheduled'; orderId: number; scheduledFor: string; label?: DoseLabel }
    | { kind: 'prn'; orderId: number; label?: DoseLabel };

/** record = all outcomes; notgiven = refusal/withhold/away only; reoffer = linked to a refusal. */
export type RecordMode = 'record' | 'notgiven' | 'reoffer';
