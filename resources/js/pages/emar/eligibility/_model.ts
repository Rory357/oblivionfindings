/* Safety & oversight › Staff eligibility (eMAR P11 v5 `model.tsx`, the staff
 * eligibility part). The server works out each person's state from the same
 * competency policy that recording a dose uses (StaffEligibilityRegister);
 * these helpers only word it, with the organisation's Safety checks rules.
 *
 * Who can witness isn't worked out here: that rule is enforced with P07b. */
import { formatDateOnly } from '@/lib/datetime';

export type AreaResult = 'yes' | 'no' | 'unseen';
export type AreaMeta = {
    key: string;
    label: string;
    core: boolean;
    /** 'area': the Safety checks area rule; 'notyet': not checked yet. */
    rule: 'area' | 'notyet' | null;
    description: string | null;
};

export type ObservedRound = {
    resident?: string;
    client_id?: number | null;
    med_type?: string;
    cd?: boolean;
    outcome?: string;
};

export type EligAssessment = {
    id: number;
    type: string;
    type_label: string;
    status: string;
    assessed: string | null;
    until: string | null;
    assessor: string | null;
    assessor_id: number | null;
    declared_at: string | null;
    acknowledged_at: string | null;
    res: Record<string, AreaResult>;
    passed: number;
    pass_threshold: number | null;
    restricted: boolean;
    restriction_notes: string | null;
    can_witness: boolean;
    unsupervised: boolean;
    observed: ObservedRound[];
    strengths: string | null;
    to_work_on: string | null;
    action_plan: string | null;
    comments: string | null;
};

/** The person's own state, from the competency policy. */
export type BaseState =
    | 'current'
    | 'restricted'
    | 'ack'
    | 'failed'
    | 'expired'
    | 'none';
/** What the register shows: the state, plus "due for renewal" and "exemption". */
export type EligStatus = BaseState | 'due' | 'exempt';

export type PersonExemption = {
    id: number;
    house: string | null;
    from: string | null;
    until: string | null;
    by: string | null;
    reason: string;
};

export type EligPerson = {
    id: number;
    name: string;
    role: string | null;
    house_id: number | null;
    house: string | null;
    started: string | null;
    records_doses: boolean;
    st: BaseState;
    status: EligStatus;
    until: string | null;
    days: number | null;
    /** A new assessment waits for acknowledgement; the previous one counts until then. */
    prev_valid: string | null;
    pin: 'set' | 'not_set' | 'locked' | 'reset' | 'expired';
    exemption: PersonExemption | null;
    assessment: EligAssessment | null;
    /** Their other assessments, newest first. */
    history?: {
        id: number;
        type_label: string;
        status: string;
        assessed: string | null;
        until: string | null;
        assessor: string | null;
    }[];
    can?: { assess: boolean; exempt: boolean; reset_pin: boolean };
};

export type EligExemption = {
    id: number;
    user_id: number;
    person: string;
    role: string | null;
    house_id: number;
    house: string | null;
    reason: string;
    from: string | null;
    until: string | null;
    by: string | null;
    at: string | null;
    status: 'active' | 'ended' | 'revoked';
    ended_at: string | null;
    ended_by: string | null;
    end_reason: string | null;
    can_end?: boolean;
};

export type EligPolicy = {
    pass_mark: number;
    core_must_pass: boolean;
    observed_minimum: number | null;
    validity_months: number;
    renewal_days: number;
    longest_exemption_days: number;
    longest_exemption_reviewed: boolean;
    validity_reviewed: boolean;
    restricted_mode: 'off' | 'block' | 'cosigner';
    area_mode: 'off' | 'failed' | 'failed_or_not_seen';
};

export type Ability = { v: 'yes' | 'no' | 'part' | 'na'; t: string };

export const STATUS_META: Record<
    EligStatus,
    ['success' | 'warning' | 'critical' | 'info' | 'neutral', string]
> = {
    current: ['success', 'Current'],
    due: ['warning', 'Due for renewal'],
    expired: ['critical', 'Expired'],
    restricted: ['warning', 'Restricted'],
    failed: ['critical', 'Not passed'],
    none: ['neutral', 'Not assessed'],
    ack: ['info', 'Waiting for acknowledgement'],
    exempt: ['info', 'Exemption'],
};

export const day = (d: string | null | undefined) =>
    d ? formatDateOnly(d) : '—';
export const firstName = (x: { name: string }) =>
    x.name.split(' ')[0] || x.name;

/** An area's result on the assessment shown (no assessment: not assessed). */
export const areaRes = (x: EligPerson, key: string): AreaResult =>
    x.assessment?.res[key] ?? 'unseen';

const WHY_NOT: Record<string, string> = {
    expired: 'Assessment ended',
    none: 'Not assessed yet',
    failed: 'Assessment not passed',
    ack: 'New assessment not acknowledged yet',
};

/** Can they record doses as given — and how? */
export function givenAbility(x: EligPerson, policy: EligPolicy): Ability {
    if (x.st === 'restricted') {
        return policy.restricted_mode === 'block'
            ? {
                  v: 'no',
                  t: 'Can’t sign given doses alone — restricted (organisation rule: Block). A colleague on shift gives the dose.',
              }
            : policy.restricted_mode === 'cosigner'
              ? {
                    v: 'part',
                    t: 'A co-signer confirms each given dose with their witness PIN (restricted).',
                }
              : {
                    v: 'yes',
                    t: 'Records given doses — the restriction isn’t enforced (organisation rule: Off).',
                };
    }
    if (x.st === 'current') return { v: 'yes', t: 'Records given doses' };
    if (x.st === 'ack' && x.prev_valid)
        return {
            v: 'yes',
            t: `Records given doses on the previous assessment (until ${day(x.prev_valid)})`,
        };
    if (x.exemption)
        return {
            v: 'part',
            t: `Records given doses under an exemption until ${day(x.exemption.until)} (${x.exemption.house ?? 'one house'} only)`,
        };
    const why =
        x.st === 'expired' && !x.until
            ? 'Assessment has no end date'
            : x.st === 'expired'
              ? `Assessment ended ${day(x.until)}`
              : WHY_NOT[x.st];
    return { v: 'no', t: `Refused, withheld and away only — ${why}` };
}

/** Controlled drugs, covert administration and insulin, under the Safety checks area rule. */
export function areaAbility(
    x: EligPerson,
    policy: EligPolicy,
    area: AreaMeta,
): Ability {
    const res = areaRes(x, area.key);
    const word =
        res === 'yes' ? 'passed' : res === 'no' ? 'not passed' : 'not assessed';
    if (area.rule === 'notyet')
        return {
            v: 'na',
            t:
                res === 'yes'
                    ? `${area.label} area passed — not checked when recording yet`
                    : `${area.label} ${word} — the system doesn’t check this yet (orders don’t say which medicines are insulin)`,
        };
    if (givenAbility(x, policy).v === 'no')
        return {
            v: 'na',
            t: 'Not relevant while given doses can’t be recorded',
        };
    // The area rule reads the assessment that counts: none under an
    // exemption, and the previous one while a new one waits.
    if (x.st === 'ack' && x.prev_valid)
        return {
            v: 'na',
            t: 'Checked against the previous assessment until the new one is acknowledged',
        };
    if (x.st !== 'current' && x.st !== 'restricted')
        return {
            v: 'na',
            t: 'Not checked under an exemption — there’s no current assessment to read',
        };
    const what =
        area.key === 'controlled_drugs'
            ? 'controlled doses'
            : 'doses with a covert plan';
    const lbl = area.label.toLowerCase();
    if (policy.area_mode === 'off')
        return {
            v: 'yes',
            t: `${area.label}: ${word} — not checked when recording (organisation rule: Off)`,
        };
    if (res === 'no')
        return {
            v: 'no',
            t: `Can’t sign ${what} as given — ${lbl} not passed (organisation rule: block when failed)`,
        };
    if (res === 'unseen')
        return policy.area_mode === 'failed_or_not_seen'
            ? {
                  v: 'no',
                  t: `Can’t sign ${what} — ${lbl} not assessed (organisation rule)`,
              }
            : {
                  v: 'yes',
                  t: `${area.label} not assessed — allowed, because the current rule only blocks when the area was failed`,
              };
    return {
        v: 'yes',
        t: `${area.key === 'controlled_drugs' ? 'Controlled doses' : 'Doses with a covert plan'} — area passed`,
    };
}

/** The line under a person's status. */
export function eligLine(x: EligPerson, areas: AreaMeta[]): string {
    const a = x.assessment;
    switch (x.status) {
        case 'current':
            return `Until ${day(x.until)}`;
        case 'due':
            return `Ends ${day(x.until)} · in ${x.days} ${x.days === 1 ? 'day' : 'days'}`;
        case 'expired':
            return x.until ? `Ended ${day(x.until)}` : 'No end date recorded';
        case 'restricted':
            return `Until ${day(x.until)} · ${a?.restriction_notes || 'restricted'}`;
        case 'failed': {
            const core = areas
                .filter((y) => y.core && areaRes(x, y.key) === 'no')
                .map((y) => y.label.toLowerCase())
                .join(', ');
            return `Assessed ${day(a?.assessed)} · ${core || 'below the pass mark'} not passed`;
        }
        case 'none':
            if (a)
                return `Assessed ${day(a.assessed)} · ${a.declared_at ? 'doesn’t count' : 'the assessor hasn’t declared it'}`;
            return x.started
                ? `Started ${day(x.started)} · no assessment`
                : 'No assessment';
        case 'ack':
            return `Assessed ${day(a?.assessed)} · waiting for ${firstName(x)}${x.prev_valid ? ` · previous counts until ${day(x.prev_valid)}` : ''}`;
        case 'exempt':
            return `Until ${day(x.exemption?.until)} · ${x.exemption?.house ?? ''}`;
    }
}

/** What "the next step" is for someone who can't record given doses, or is due. */
export function nextStep(x: EligPerson): string {
    if (x.st === 'ack')
        return `${firstName(x)} acknowledges from their own login`;
    if (x.st === 'none') return 'First assessment';
    if (x.st === 'failed') return 'Remedial assessment';
    return 'Renewal';
}

/** Which wizard mode a person's next assessment opens in. */
export const assessMode = (x: EligPerson): 'new' | 'renew' | 'remedial' =>
    x.st === 'none' ? 'new' : x.st === 'failed' ? 'remedial' : 'renew';

export const PIN_LABEL: Record<EligPerson['pin'], string> = {
    set: 'PIN set',
    not_set: 'No PIN set',
    locked: 'Locked',
    reset: 'Reset — must set a new one',
    expired: 'Renewal due — must set a new one',
};
export const PIN_VARIANT: Record<
    EligPerson['pin'],
    'success' | 'warning' | 'critical'
> = {
    set: 'success',
    not_set: 'warning',
    locked: 'critical',
    reset: 'warning',
    expired: 'warning',
};
