export type BoardAction = 'claim' | 'approve' | 'set_alert_preference';
export type ActionEligibility = {
    assessment_status: 'assessed' | 'unknown';
    warning_reasons: string[];
    blocked_reasons: string[];
    acknowledgement_required: false;
    reason_required: false;
};
export type PositionSource = {
    actor_id: number;
    action: 'claim' | 'approve';
    source_revision: string;
    display: {
        position_id: number;
        shift_id: number;
        status: string;
        starts_at: string | null;
        ends_at: string | null;
        client: string;
        client_redacted: boolean;
        site: string | null;
        claimed_by: number | null;
        claimant: string | null;
    };
    can: { claim: boolean; approve: boolean };
    urls: { claim: string; approve: string };
    eligibility: ActionEligibility | null;
};
export type AlertSource = {
    actor_id: number;
    source_revision: string;
    enabled: boolean;
    can_update: boolean;
};
export type ActionSource = PositionSource | AlertSource;
export type ActionTarget =
    | { action: 'claim' | 'approve'; positionId: number }
    | { action: 'set_alert_preference' };
export type ActionIntent = {
    target: ActionTarget;
    source: ActionSource;
    body: { request_id: string; source_revision: string; enabled?: boolean };
};
export type ActionConfirmation = {
    outcome: 'claimed' | 'approved' | 'changed' | 'unchanged';
    changed: boolean;
    committedAt: string;
    warnings: string[];
};

const record = (v: unknown): v is Record<string, unknown> =>
    typeof v === 'object' && v !== null && !Array.isArray(v);
const id = (v: unknown): v is number =>
    typeof v === 'number' && Number.isSafeInteger(v) && v > 0;
const rev = (v: unknown): v is string =>
    typeof v === 'string' && /^[0-9a-f]{64}$/.test(v);
const nullableId = (v: unknown) => v === null || id(v);
const text = (v: unknown): v is string =>
    typeof v === 'string' && v.trim() !== '';
const nullableText = (v: unknown) => v === null || typeof v === 'string';
const keys = (v: Record<string, unknown>, names: string[]) =>
    Object.keys(v).length === names.length &&
    names.every((name) => Object.hasOwn(v, name));

function instant(v: unknown): v is string | null {
    if (v === null) return true;
    if (
        typeof v !== 'string' ||
        !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/.test(v)
    )
        return false;
    const date = new Date(v);
    return (
        !Number.isNaN(date.getTime()) &&
        date.toISOString().slice(0, 19) === v.slice(0, 19)
    );
}
const sameInstant = (a: unknown, b: string | null) =>
    instant(a) &&
    (a === null || b === null
        ? a === b
        : a.replace(/\.0{1,6}Z$/, 'Z') === b.replace(/\.0{1,6}Z$/, 'Z'));

function eligibility(v: unknown): v is ActionEligibility | null {
    return (
        v === null ||
        (record(v) &&
            keys(v, [
                'assessment_status',
                'warning_reasons',
                'blocked_reasons',
                'acknowledgement_required',
                'reason_required',
            ]) &&
            ['assessed', 'unknown'].includes(String(v.assessment_status)) &&
            Array.isArray(v.warning_reasons) &&
            v.warning_reasons.every(text) &&
            Array.isArray(v.blocked_reasons) &&
            v.blocked_reasons.every(text) &&
            v.acknowledgement_required === false &&
            v.reason_required === false)
    );
}

export function actionSourceUrl(target: ActionTarget): string {
    if (target.action === 'set_alert_preference')
        return '/operations/job-board/alerts/preference';
    if (!id(target.positionId) || !['claim', 'approve'].includes(target.action))
        throw new Error('Choose a current position.');
    return `/operations/job-board/${target.positionId}/action-source?action=${target.action}`;
}

export function actionCommandUrl(target: ActionTarget): string {
    if (target.action === 'set_alert_preference')
        return '/operations/job-board/alerts/toggle';
    if (!id(target.positionId) || !['claim', 'approve'].includes(target.action))
        throw new Error('Choose a current position.');
    return `/operations/job-board/${target.positionId}/${target.action}`;
}

export function readActionSource(
    v: unknown,
    target: ActionTarget,
    actor: number,
): ActionSource {
    const invalid = () =>
        new Error(
            "We couldn't check this shift's details. Load them again before you continue.",
        );
    if (
        !id(actor) ||
        !record(v) ||
        v.actor_id !== actor ||
        !rev(v.source_revision)
    )
        throw invalid();
    if (target.action === 'set_alert_preference') {
        if (
            !keys(v, [
                'actor_id',
                'source_revision',
                'enabled',
                'can_update',
            ]) ||
            typeof v.enabled !== 'boolean' ||
            typeof v.can_update !== 'boolean'
        )
            throw invalid();
        return v as AlertSource;
    }
    if (
        !keys(v, [
            'actor_id',
            'action',
            'source_revision',
            'display',
            'can',
            'urls',
            'eligibility',
        ]) ||
        v.action !== target.action ||
        !record(v.display) ||
        !record(v.can) ||
        !record(v.urls) ||
        !eligibility(v.eligibility)
    )
        throw invalid();
    const display = v.display;
    if (
        !keys(display, [
            'position_id',
            'shift_id',
            'status',
            'starts_at',
            'ends_at',
            'client',
            'client_redacted',
            'site',
            'claimed_by',
            'claimant',
        ]) ||
        display.position_id !== target.positionId ||
        !id(display.shift_id) ||
        !text(display.status) ||
        !instant(display.starts_at) ||
        !instant(display.ends_at) ||
        !text(display.client) ||
        typeof display.client_redacted !== 'boolean' ||
        !nullableText(display.site) ||
        !nullableId(display.claimed_by) ||
        !nullableText(display.claimant) ||
        !keys(v.can, ['claim', 'approve']) ||
        typeof v.can.claim !== 'boolean' ||
        typeof v.can.approve !== 'boolean' ||
        v.can[target.action === 'claim' ? 'approve' : 'claim'] !== false ||
        !keys(v.urls, ['claim', 'approve']) ||
        v.urls.claim !== `/operations/job-board/${target.positionId}/claim` ||
        v.urls.approve !== `/operations/job-board/${target.positionId}/approve`
    )
        throw invalid();
    const source = v as PositionSource;
    if (
        source.can[target.action] &&
        (!source.eligibility ||
            source.eligibility.assessment_status !== 'assessed' ||
            source.eligibility.blocked_reasons.length > 0 ||
            (target.action === 'claim'
                ? source.display.status !== 'open'
                : source.display.status !== 'claimed' ||
                  !id(source.display.claimed_by)))
    )
        throw invalid();
    return source;
}

export function mayConfirmAction(
    source: ActionSource,
    target: ActionTarget,
): boolean {
    return target.action === 'set_alert_preference'
        ? 'can_update' in source && source.can_update
        : 'can' in source &&
              source.action === target.action &&
              source.can[target.action];
}

export function prepareActionIntent(
    source: ActionSource,
    target: ActionTarget,
    actor: number,
    requestId: string,
    enabled?: boolean,
): ActionIntent {
    const current = readActionSource(source, target, actor);
    if (
        !mayConfirmAction(current, target) ||
        !/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(
            requestId,
        ) ||
        (target.action === 'set_alert_preference' &&
            typeof enabled !== 'boolean')
    )
        throw new Error("Load the shift's details again before you continue.");
    return {
        target: { ...target },
        source: structuredClone(current),
        body: {
            request_id: requestId,
            source_revision: current.source_revision,
            ...(target.action === 'set_alert_preference' ? { enabled } : {}),
        },
    };
}

/** Only the physically confirmed matching command result completes this local
 * attempt. Current state, a redirected page and even JSON refusal statuses do
 * not prove what this request saved after the root transaction committed. */
export function confirmedAction(
    status: number,
    value: unknown,
    intent: ActionIntent,
): ActionConfirmation | null {
    if (
        status !== 200 ||
        !record(value) ||
        !keys(value, ['result']) ||
        !record(value.result)
    )
        return null;
    const r = value.result;
    if (
        !keys(r, [
            'version',
            'scope',
            'action',
            'outcome',
            'changed',
            'actor_id',
            'request_id',
            'source_revision',
            'result_revision',
            'position',
            'shift',
            'preference',
            'eligibility',
            'committed_at',
        ]) ||
        r.version !== 1 ||
        r.scope !== 'job_board' ||
        r.action !== intent.target.action ||
        r.actor_id !== intent.source.actor_id ||
        r.request_id !== intent.body.request_id ||
        r.source_revision !== intent.body.source_revision ||
        !rev(r.result_revision) ||
        r.committed_at === null ||
        !instant(r.committed_at) ||
        !eligibility(r.eligibility) ||
        typeof r.changed !== 'boolean'
    )
        return null;
    const warnings = r.eligibility?.warning_reasons ?? [];
    if (intent.target.action === 'set_alert_preference') {
        if (
            !('enabled' in intent.source) ||
            !record(r.preference) ||
            !keys(r.preference, ['enabled']) ||
            r.preference.enabled !== intent.body.enabled ||
            r.position !== null ||
            r.shift !== null ||
            r.eligibility !== null
        )
            return null;
        const changed = intent.source.enabled !== intent.body.enabled;
        if (
            r.changed !== changed ||
            r.outcome !== (changed ? 'changed' : 'unchanged')
        )
            return null;
    } else {
        if (
            !('display' in intent.source) ||
            !record(r.position) ||
            !record(r.shift) ||
            r.preference !== null ||
            !r.eligibility ||
            r.eligibility.assessment_status !== 'assessed' ||
            r.eligibility.blocked_reasons.length > 0
        )
            return null;
        const p = r.position;
        const shift = r.shift;
        const old = intent.source.display;
        if (
            !keys(p, [
                'id',
                'shift_id',
                'status',
                'claimed_by',
                'claimed_at',
                'approved_by',
                'approved_at',
            ]) ||
            p.id !== old.position_id ||
            p.shift_id !== old.shift_id ||
            !nullableId(p.claimed_by) ||
            !instant(p.claimed_at) ||
            !nullableId(p.approved_by) ||
            !instant(p.approved_at) ||
            !keys(shift, [
                'id',
                'status',
                'assigned',
                'starts_at',
                'ends_at',
            ]) ||
            shift.id !== old.shift_id ||
            !text(shift.status) ||
            typeof shift.assigned !== 'boolean' ||
            !sameInstant(shift.starts_at, old.starts_at) ||
            !sameInstant(shift.ends_at, old.ends_at) ||
            r.changed !== true
        )
            return null;
        if (intent.target.action === 'claim') {
            if (
                r.outcome !== 'claimed' ||
                p.status !== 'claimed' ||
                p.claimed_by !== intent.source.actor_id ||
                p.claimed_at === null
            )
                return null;
        } else if (
            r.outcome !== 'approved' ||
            p.status !== 'filled' ||
            p.claimed_by !== old.claimed_by ||
            p.approved_by !== intent.source.actor_id ||
            p.approved_at === null ||
            shift.assigned !== true
        )
            return null;
    }
    return {
        outcome: r.outcome as ActionConfirmation['outcome'],
        changed: r.changed,
        committedAt: r.committed_at,
        warnings: [...warnings],
    };
}

/** Plain words for a position's current state, for staff-facing copy. */
export function positionStateLabel(
    status: string,
    claimant: string | null,
): string {
    if (status === 'open') return 'open for claims';
    if (status === 'claimed')
        return claimant
            ? `claimed by ${claimant} and waiting for approval`
            : 'claimed and waiting for approval';
    if (status === 'filled')
        return claimant ? `filled by ${claimant}` : 'filled';
    if (status === 'cancelled') return 'cancelled';
    return 'no longer available';
}

/** A fresh read reports current state only. It never confirms the earlier
 * request, so the copy says so every time. */
export function currentActionSummary(
    source: ActionSource,
    intent: ActionIntent,
): string {
    readActionSource(source, intent.target, intent.source.actor_id);
    if ('enabled' in source)
        return `Your alerts are ${source.enabled ? 'on' : 'off'} right now. This check cannot show whether your earlier change went through.`;
    return `Right now this shift is ${positionStateLabel(source.display.status, source.display.claimant)}. This check cannot show whether your earlier request went through.`;
}
