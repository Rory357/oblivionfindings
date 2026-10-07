import { router } from '@inertiajs/react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';

export type SuggestionAction =
    | 'accept'
    | 'dismiss'
    | 'apply'
    | 'apply_accepted';
export type SuggestionSource = {
    run_id: number;
    site_id: number;
    suggestion_id: number;
    shift_id: number;
    candidate_user_id: number | null;
    status: string;
    source_revision: string;
};
export type RunSource = { run_id: number; site_id: number };
export type SuggestionIntent = {
    action: SuggestionAction;
    source: SuggestionSource | RunSource;
};
type Expectation = SuggestionIntent & { actorId: number; requestId: string };
export type SuggestionReceipt = {
    outcome:
        | 'accepted'
        | 'dismissed'
        | 'applied'
        | 'unchanged'
        | 'not_applied'
        | 'expired_marked_stale';
    counts: {
        selected: number;
        applied: number;
        stale: number;
        failed: number;
    };
};
type Notice = {
    kind: 'confirmed' | 'warning' | 'unknown' | 'read';
    message: string;
};
const object = (value: unknown): Record<string, unknown> =>
    value !== null && typeof value === 'object' && !Array.isArray(value)
        ? (value as Record<string, unknown>)
        : {};
const positive = (value: unknown): value is number =>
    typeof value === 'number' && Number.isSafeInteger(value) && value > 0;
const count = (value: unknown): value is number =>
    typeof value === 'number' && Number.isSafeInteger(value) && value >= 0;
const revision = (value: unknown): value is string =>
    typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);
const utc = (value: unknown): value is string =>
    typeof value === 'string' &&
    /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.000Z$/.test(value) &&
    Number.isFinite(Date.parse(value));
export const SUGGESTION_READ_KEYS = [
    'run',
    'suggestions',
    'worker_timezone',
    'suggestion_visibility',
    'auth',
];
export const UNKNOWN_SUGGESTION =
    'We could not confirm the result. It may already have saved. Reload suggestions to check the current choices and assignments before trying again.';
const FAILED_READ =
    'Suggestions could not be refreshed. The list may be out of date. Try reloading again.';

export function validSuggestionSource(
    value: unknown,
): value is SuggestionSource {
    const source = object(value);
    return (
        positive(source.run_id) &&
        positive(source.site_id) &&
        positive(source.suggestion_id) &&
        positive(source.shift_id) &&
        (source.candidate_user_id === null ||
            positive(source.candidate_user_id)) &&
        typeof source.status === 'string' &&
        source.status.length > 0 &&
        revision(source.source_revision)
    );
}
function orderedSource(intent: SuggestionIntent) {
    const source = intent.source;
    if (intent.action === 'apply_accepted')
        return { run_id: source.run_id, site_id: source.site_id };
    const single = source as SuggestionSource;
    return {
        run_id: single.run_id,
        site_id: single.site_id,
        suggestion_id: single.suggestion_id,
        shift_id: single.shift_id,
        candidate_user_id: single.candidate_user_id,
        status: single.status,
        source_revision: single.source_revision,
    };
}
/** Ordered submitted intent shared with the server; the UUID only correlates this attempt. */
export async function suggestionHash(intent: SuggestionIntent) {
    const bytes = await crypto.subtle.digest(
        'SHA-256',
        new TextEncoder().encode(
            JSON.stringify({
                action: intent.action,
                run_id: intent.source.run_id,
                suggestion_id:
                    intent.action === 'apply_accepted'
                        ? null
                        : (intent.source as SuggestionSource).suggestion_id,
                expected_source: orderedSource(intent),
            }),
        ),
    );
    return Array.from(new Uint8Array(bytes), (value) =>
        value.toString(16).padStart(2, '0'),
    ).join('');
}
function sameActorProps(page: unknown, actorId: number) {
    const props = object(object(page).props);
    return positive(actorId) &&
        object(object(props.auth).user).id === actorId &&
        !Object.keys(object(props.errors)).length
        ? props
        : null;
}
export function suggestionReceipt(
    page: unknown,
    expected: Expectation,
    hash: string,
): SuggestionReceipt | null {
    const props = sameActorProps(page, expected.actorId);
    if (!props) return null;
    const flash = object(props.flash),
        receipt = object(flash.roster_suggestion_result);
    const bulk = expected.action === 'apply_accepted',
        source = orderedSource(expected);
    const echoed = object(receipt.expected_source);
    if (
        flash.error ||
        receipt.actor_id !== expected.actorId ||
        receipt.request_id !== expected.requestId ||
        receipt.action !== expected.action ||
        receipt.run_id !== source.run_id ||
        receipt.site_id !== source.site_id ||
        receipt.suggestion_id !==
            (bulk ? null : (source as SuggestionSource).suggestion_id) ||
        receipt.scope !== (bulk ? 'accepted_run' : 'single') ||
        !revision(hash) ||
        receipt.values_hash !== hash ||
        Object.keys(echoed).length !== Object.keys(source).length ||
        Object.entries(source).some(([key, value]) => echoed[key] !== value) ||
        typeof receipt.changed !== 'boolean'
    )
        return null;
    const totals = object(receipt.counts);
    if (
        !['selected', 'applied', 'stale', 'failed'].every((key) =>
            count(totals[key]),
        ) ||
        !Array.isArray(receipt.assignments) ||
        receipt.assignments.length !== totals.applied
    )
        return null;
    const assignments = receipt.assignments.map(object);
    if (
        new Set(assignments.map((item) => item.shift_id)).size !==
            assignments.length ||
        new Set(assignments.map((item) => item.suggestion_id)).size !==
            assignments.length ||
        assignments.some(
            (item) =>
                !positive(item.suggestion_id) ||
                !positive(item.shift_id) ||
                !positive(item.user_id) ||
                typeof item.status !== 'string' ||
                !item.status.length ||
                !utc(item.starts_at) ||
                !utc(item.ends_at) ||
                Date.parse(item.ends_at) <= Date.parse(item.starts_at),
        )
    )
        return null;
    if (bulk) {
        if (receipt.suggestion !== null) return null;
        const applied =
            receipt.outcome === 'applied' &&
            receipt.disposition === 'applied' &&
            receipt.changed === true &&
            positive(totals.selected) &&
            totals.applied === totals.selected &&
            totals.stale === 0 &&
            totals.failed === 0;
        const empty =
            receipt.outcome === 'unchanged' &&
            receipt.disposition === 'empty' &&
            receipt.changed === false &&
            Object.values(totals).every((value) => value === 0);
        const blocked =
            receipt.outcome === 'not_applied' &&
            receipt.disposition === 'preflight_no_change' &&
            receipt.changed === false &&
            positive(totals.selected) &&
            totals.applied === 0 &&
            (Number(totals.stale) > 0 || Number(totals.failed) > 0);
        if (!applied && !empty && !blocked) return null;
    } else {
        const single = source as SuggestionSource,
            saved = object(receipt.suggestion);
        if (
            totals.selected !== 1 ||
            totals.failed !== 0 ||
            saved.id !== single.suggestion_id ||
            !['accepted_by', 'dismissed_by', 'applied_by'].every(
                (key) => saved[key] === null || positive(saved[key]),
            ) ||
            !['accepted_at', 'dismissed_at', 'applied_at'].every(
                (key) => saved[key] === null || utc(saved[key]),
            )
        )
            return null;
        if (expected.action === 'apply') {
            const assigned = assignments[0];
            if (
                receipt.outcome !== 'applied' ||
                receipt.disposition !== 'single' ||
                !receipt.changed ||
                totals.applied !== 1 ||
                totals.stale !== 0 ||
                saved.status !== 'applied' ||
                saved.applied_by !== expected.actorId ||
                !utc(saved.applied_at) ||
                assigned.suggestion_id !== single.suggestion_id ||
                assigned.shift_id !== single.shift_id ||
                assigned.user_id !== single.candidate_user_id
            )
                return null;
        } else if (
            expected.action === 'accept' &&
            receipt.outcome === 'expired_marked_stale'
        ) {
            if (
                receipt.disposition !== 'expired' ||
                totals.stale !== 1 ||
                totals.applied !== 0 ||
                saved.status !== 'stale'
            )
                return null;
        } else {
            const status =
                expected.action === 'accept' ? 'accepted' : 'dismissed';
            if (
                receipt.disposition !== 'single' ||
                totals.applied !== 0 ||
                totals.stale !== 0 ||
                saved.status !== status ||
                receipt.outcome !== (receipt.changed ? status : 'unchanged') ||
                saved[`${status}_by`] !== expected.actorId ||
                !utc(saved[`${status}_at`])
            )
                return null;
        }
    }
    return {
        outcome: receipt.outcome as SuggestionReceipt['outcome'],
        counts: totals as SuggestionReceipt['counts'],
    };
}

/** Only a same-user, same-run current read releases an uncertain command. */
export function currentSuggestionPage(
    page: unknown,
    actorId: number,
    source: RunSource,
) {
    const props = sameActorProps(page, actorId);
    if (!props) return false;
    const run = object(props.run),
        visibility = object(props.suggestion_visibility);
    if (
        run.id !== source.run_id ||
        object(run.site).id !== source.site_id ||
        typeof run.status !== 'string' ||
        typeof run.is_expired !== 'boolean' ||
        typeof object(run.can).apply_accepted !== 'boolean' ||
        !(
            object(run.urls).apply_accepted === null ||
            typeof object(run.urls).apply_accepted === 'string'
        ) ||
        typeof props.worker_timezone !== 'string' ||
        !props.worker_timezone ||
        !Array.isArray(props.suggestions) ||
        visibility.basis !== 'current_canonical_run_site' ||
        !['recorded_count', 'visible_count', 'withheld_count'].every((key) =>
            count(visibility[key]),
        ) ||
        visibility.visible_count !== props.suggestions.length ||
        visibility.recorded_count !==
            Number(visibility.visible_count) + Number(visibility.withheld_count)
    )
        return false;
    return props.suggestions.every((value) => {
        const item = object(value),
            can = object(item.can),
            urls = object(item.urls);
        return (
            validSuggestionSource({
                run_id: run.id,
                site_id: source.site_id,
                suggestion_id: item.id,
                shift_id: item.shift_id,
                candidate_user_id: item.candidate_user_id,
                status: item.status,
                source_revision: item.source_revision,
            }) &&
            ['accept', 'dismiss', 'apply'].every(
                (key) =>
                    typeof can[key] === 'boolean' &&
                    (urls[key] === null || typeof urls[key] === 'string'),
            )
        );
    });
}
function receiptNotice(
    result: SuggestionReceipt,
    action: SuggestionAction,
): Notice {
    if (result.outcome === 'expired_marked_stale')
        return {
            kind: 'warning',
            message:
                'This suggestion expired and was marked stale. Generate fresh suggestions before assigning a worker.',
        };
    if (result.outcome === 'not_applied')
        return {
            kind: 'warning',
            message:
                'No workers were assigned. The accepted choices did not pass the current checks. Review the roster and generate fresh suggestions.',
        };
    if (action === 'apply_accepted' && result.outcome === 'unchanged')
        return {
            kind: 'confirmed',
            message:
                'There were no accepted choices to apply. No assignments changed.',
        };
    if (result.outcome === 'applied')
        return {
            kind: 'confirmed',
            message: `${result.counts.applied} ${result.counts.applied === 1 ? 'worker assigned' : 'workers assigned'}. Review the roster for the saved assignments.`,
        };
    if (action === 'accept')
        return {
            kind: 'confirmed',
            message:
                result.outcome === 'unchanged'
                    ? 'This choice is already accepted. Use Apply when you are ready to assign the worker.'
                    : 'Choice accepted. Use Apply when you are ready to assign the worker.',
        };
    return {
        kind: 'confirmed',
        message:
            result.outcome === 'unchanged'
                ? 'This choice is already dismissed.'
                : 'Choice dismissed.',
    };
}

export function useSuggestionCommand(actorId: number, source: RunSource) {
    const context = `${actorId}:${source.run_id}:${source.site_id}`;
    const identity = useRef(context),
        epoch = useRef(0),
        alive = useRef(true),
        pending = useRef(false),
        held = useRef(false);
    const detachInvalid = useRef<(() => void) | null>(null);
    const [activity, setActivity] = useState<'command' | 'read' | null>(null);
    const [notice, setNotice] = useState<Notice | null>(null);
    const [needsRead, setNeedsRead] = useState(false);
    useLayoutEffect(() => {
        if (identity.current === context) return;
        detachInvalid.current?.();
        detachInvalid.current = null;
        identity.current = context;
        epoch.current += 1;
        pending.current = false;
        held.current = true;
        setActivity(null);
        setNeedsRead(true);
        setNotice({ kind: 'unknown', message: UNKNOWN_SUGGESTION });
    }, [context]);
    useEffect(() => {
        alive.current = true;
        return () => {
            detachInvalid.current?.();
            detachInvalid.current = null;
            alive.current = false;
            epoch.current += 1;
        };
    }, []);
    const submit = async (url: string, intent: SuggestionIntent) => {
        if (
            !alive.current ||
            pending.current ||
            held.current ||
            !positive(actorId) ||
            intent.source.run_id !== source.run_id ||
            intent.source.site_id !== source.site_id ||
            !positive(source.run_id) ||
            !positive(source.site_id) ||
            (intent.action !== 'apply_accepted' &&
                !validSuggestionSource(intent.source))
        )
            return;
        pending.current = true;
        setActivity('command');
        setNotice(null);
        const token = ++epoch.current;
        const current = () =>
            alive.current &&
            identity.current === context &&
            epoch.current === token;
        let expected: Expectation, hash: string;
        try {
            expected = {
                ...structuredClone(intent),
                actorId,
                requestId: crypto.randomUUID(),
            };
            hash = await suggestionHash(expected);
        } catch {
            if (current()) {
                pending.current = false;
                setActivity(null);
                setNotice({
                    kind: 'warning',
                    message:
                        'This action could not be prepared. Nothing was sent. Try again.',
                });
            }
            return;
        }
        if (!current()) return;
        let detach: (() => void) | null = null;
        let finished = false,
            result: SuggestionReceipt | null = null,
            fresh = false;
        const finish = () => {
            detach?.();
            if (detachInvalid.current === detach) detachInvalid.current = null;
            detach = null;
            if (!current() || finished) return;
            finished = true;
            pending.current = false;
            held.current = !fresh;
            setActivity(null);
            setNeedsRead(!fresh);
            setNotice(
                result
                    ? {
                          ...receiptNotice(result, intent.action),
                          message:
                              receiptNotice(result, intent.action).message +
                              (fresh
                                  ? ''
                                  : ' Reload suggestions to check the current list before another action.'),
                      }
                    : { kind: 'unknown', message: UNKNOWN_SUGGESTION },
            );
        };
        try {
            // Only this exact modern request owns this recovery UI. Leave all
            // other Inertia errors to their existing handlers.
            detach = router.on('invalid', (event) => {
                if (!current() || finished) return;
                const config = event.detail.response.config;
                const headers = config.headers;
                const protocol =
                    headers?.['X-Roster-Suggestion-Result'] ??
                    headers?.['x-roster-suggestion-result'];
                let payload: unknown = config.data;
                try {
                    if (typeof payload === 'string')
                        payload = JSON.parse(payload);
                } catch {
                    return;
                }
                if (
                    protocol !== 'committed-v1' ||
                    object(payload).request_id !== expected.requestId
                )
                    return;
                event.preventDefault();
                finish();
            });
            detachInvalid.current = detach;
            router.post(
                url,
                {
                    request_id: expected.requestId,
                    expected_source: orderedSource(expected),
                },
                {
                    headers: { 'X-Roster-Suggestion-Result': 'committed-v1' },
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess: (page) => {
                        if (!current() || finished || result) return;
                        result = suggestionReceipt(page, expected, hash);
                        fresh =
                            Boolean(result) &&
                            currentSuggestionPage(page, actorId, source);
                    },
                    // A response without the matching committed receipt cannot establish the write's outcome.
                    onFinish: finish,
                    onCancel: finish,
                    onError: finish,
                },
            );
        } catch {
            finish();
        }
    };
    const refresh = (automatic = false) => {
        if (
            !alive.current ||
            pending.current ||
            (automatic && held.current) ||
            !positive(actorId)
        )
            return;
        pending.current = true;
        setActivity('read');
        const token = ++epoch.current;
        const current = () =>
            alive.current &&
            identity.current === context &&
            epoch.current === token;
        let finished = false,
            fresh = false;
        const finish = () => {
            if (!current() || finished) return;
            finished = true;
            pending.current = false;
            held.current = !fresh;
            setActivity(null);
            setNeedsRead(!fresh);
            if (!fresh) setNotice({ kind: 'unknown', message: FAILED_READ });
            else if (!automatic)
                setNotice({
                    kind: 'read',
                    message:
                        'Current suggestions loaded. Review the choices and assignments before taking another action.',
                });
        };
        try {
            router.reload({
                only: SUGGESTION_READ_KEYS,
                preserveScroll: true,
                onSuccess: (page) => {
                    if (current() && !finished)
                        fresh = currentSuggestionPage(page, actorId, source);
                },
                onError: finish,
                onCancel: finish,
                onFinish: finish,
            });
        } catch {
            finish();
        }
    };
    return {
        submit,
        refresh,
        notice,
        activity,
        needsRead,
        busy: activity !== null,
        blocked: activity !== null || needsRead || !positive(actorId),
    };
}
