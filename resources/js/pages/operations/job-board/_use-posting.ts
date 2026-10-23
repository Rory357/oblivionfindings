import { useEffect, useLayoutEffect, useRef, useState } from 'react';

import {
    newRequestId,
    readHeaders,
    readJson,
    refusalMessage,
    writeHeaders,
} from './_board-http';
import {
    confirmedPosting,
    postingReadback,
    preparePostingIntent,
    readPostablePage,
    type PostablePage,
    type PostableShift,
    type PostingDetails,
    type PostingIntent,
} from './_posting-contract';

const EMPTY_DETAILS: PostingDetails = { skills: '', notes: '', expiry: '' };
const UNKNOWN =
    "We couldn't confirm the post. It may already have saved. Your entries are kept here. Check the shift before you post it again.";
const CHANGED =
    'This shift changed after you chose it. Choose it again from the updated list. Nothing was posted.';
/** Posting runs every check inside one save, so these always mean nothing was posted. */
const DEFINITE_REFUSALS = [403, 404, 409, 419, 422] as const;

type Review = { intent: PostingIntent; zone: string; listKey: string };
export type PostingOutcome =
    | { status: 'confirmed'; positionId: number; committedAt: string }
    | { status: 'rejected' | 'unknown'; message: string };
type Listing =
    | { status: 'idle' | 'loading' }
    | { status: 'ready'; page: PostablePage; key: string }
    | { status: 'error'; message: string };

const readInit = (signal?: AbortSignal): RequestInit => ({
    credentials: 'same-origin',
    cache: 'no-store',
    redirect: 'error',
    signal,
    headers: readHeaders(),
});

/**
 * The page-owned state for Post position (simplified from Codex R10).
 * It lives in the page so closing the dialog keeps an unconfirmed attempt
 * (and the entries) while the person stays on this page. It never claims to
 * survive a reload, and it never re-sends a post by itself.
 */
export function usePosting({
    open,
    actor,
    allowed,
    week,
    onConfirmed,
}: {
    open: boolean;
    actor: number;
    allowed: boolean;
    week: string;
    onConfirmed: () => void;
}) {
    const [range, setRange] = useState<'week' | 'all'>('week');
    const [query, setQuery] = useState('');
    const [page, setPage] = useState(1);
    const [reload, setReload] = useState(0);
    const [listing, setListing] = useState<Listing>({ status: 'idle' });
    const [selection, setSelection] = useState<PostableShift | null>(null);
    const [details, setDetails] = useState<PostingDetails>({
        ...EMPTY_DETAILS,
    });
    const [review, setReview] = useState<Review | null>(null);
    const [outcome, setOutcome] = useState<PostingOutcome | null>(null);
    const [error, setError] = useState('');
    const [pending, setPending] = useState(false);
    const [checking, setChecking] = useState(false);
    const [readback, setReadback] = useState('');
    const [checked, setChecked] = useState(false);
    const busy = useRef(false);
    const mounted = useRef(true);
    const readEpoch = useRef(0);
    const scope = `${actor}:${allowed}:${week}`;
    const live = useRef(scope);
    const listKey = `${scope}:${range}:${query}:${page}:${reload}`;
    const held =
        pending ||
        outcome?.status === 'unknown' ||
        outcome?.status === 'confirmed';

    useLayoutEffect(() => {
        live.current = scope;
    }, [scope]);
    useEffect(() => {
        mounted.current = true;
        const reads = readEpoch;
        return () => {
            mounted.current = false;
            reads.current++;
        };
    }, []);

    // Another signed-in person never sees the last person's entries.
    const lastActor = useRef(actor);
    useEffect(() => {
        if (lastActor.current === actor) return;
        lastActor.current = actor;
        setDetails({ ...EMPTY_DETAILS });
        setSelection(null);
        setReview(null);
        setOutcome(null);
        setError('');
        setReadback('');
        setChecked(false);
    }, [actor]);

    // The shift list. A changed search, range, page or account starts a new
    // read and drops the choice made from the old one.
    useEffect(() => {
        const epoch = ++readEpoch.current;
        const abort = new AbortController();
        if (!held) {
            setSelection(null);
            setReview(null);
        }
        if (!open || !allowed || !(actor > 0) || held) {
            setListing({ status: 'idle' });
            return () => abort.abort();
        }
        setListing({ status: 'loading' });
        const params = new URLSearchParams({
            page: String(page),
            per_page: '20',
        });
        if (query) params.set('q', query);
        if (range === 'week') params.set('week', week);
        const current = () =>
            mounted.current &&
            !abort.signal.aborted &&
            readEpoch.current === epoch &&
            live.current === scope;
        void (async () => {
            try {
                const response = await fetch(
                    `/operations/job-board/postable-shifts?${params}`,
                    readInit(abort.signal),
                );
                if (response.status !== 200)
                    throw new Error(
                        response.status === 403
                            ? 'You can no longer post shifts from this account.'
                            : "The shift list couldn't be loaded. Your entries are kept.",
                    );
                const result = readPostablePage(await response.json(), page);
                if (current())
                    setListing({ status: 'ready', page: result, key: listKey });
            } catch (failure) {
                if (current())
                    setListing({
                        status: 'error',
                        message:
                            failure instanceof Error
                                ? failure.message
                                : "The shift list couldn't be loaded.",
                    });
            }
        })();
        return () => abort.abort();
    }, [open, allowed, actor, week, scope, range, query, page, listKey, held]);

    const editable = () => !busy.current && !held && allowed;

    const choose = (shiftId: number) => {
        if (
            !editable() ||
            listing.status !== 'ready' ||
            listing.key !== listKey
        )
            return;
        const row = listing.page.data.find(
            (item) => item.id === shiftId && item.can_post,
        );
        if (!row) return;
        setSelection(row);
        setReview(null);
        setOutcome(null);
        setError('');
    };

    const changeDetails = (next: PostingDetails) => {
        if (!editable()) return;
        setDetails(next);
        setReview(null);
        setOutcome(null);
        setError('');
    };

    const captureReview = () => {
        if (
            !editable() ||
            !selection ||
            listing.status !== 'ready' ||
            listing.key !== listKey
        ) {
            setError('Choose a shift from the current list first.');
            return false;
        }
        try {
            const row = listing.page.data.find(
                (item) =>
                    item.id === selection.id &&
                    item.source_revision === selection.source_revision &&
                    item.can_post,
            );
            if (!row)
                throw new Error(
                    'That shift is no longer in the list. Choose it again.',
                );
            const intent = preparePostingIntent(
                row,
                actor,
                newRequestId(),
                details,
                listing.page.workerTimezone,
            );
            setReview({ intent, zone: listing.page.workerTimezone, listKey });
            setError('');
            return true;
        } catch (failure) {
            setError(
                failure instanceof Error
                    ? failure.message
                    : "The posting details couldn't be checked.",
            );
            return false;
        }
    };

    const submit = async () => {
        const captured = review;
        if (
            busy.current ||
            held ||
            !captured ||
            !allowed ||
            captured.intent.actor !== actor ||
            captured.listKey !== listKey
        )
            return;
        let headers: Record<string, string>;
        try {
            headers = writeHeaders();
        } catch (failure) {
            setError(
                failure instanceof Error
                    ? failure.message
                    : 'Your session could not be checked.',
            );
            return;
        }
        busy.current = true;
        setPending(true);
        setOutcome(null);
        setError('');
        setChecked(false);
        setReadback('');
        const capturedScope = live.current;
        try {
            const response = await fetch(captured.intent.row.post_url, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                redirect: 'error',
                headers,
                body: JSON.stringify(captured.intent.body),
            });
            const value = await readJson(response);
            if (!mounted.current) return;
            const sameContext = live.current === capturedScope;
            const receipt = sameContext
                ? confirmedPosting(
                      response.status,
                      value,
                      captured.intent,
                      actor,
                  )
                : null;
            if (receipt) {
                setOutcome({ status: 'confirmed', ...receipt });
                onConfirmed();
                return;
            }
            const refusal = sameContext
                ? refusalMessage(response.status, value, DEFINITE_REFUSALS)
                : null;
            if (refusal) {
                // Nothing was posted: keep the entries, ask for a fresh choice.
                setReview(null);
                setSelection(null);
                setOutcome({
                    status: 'rejected',
                    message: response.status === 409 ? CHANGED : refusal,
                });
                setReload((n) => n + 1);
                return;
            }
            setOutcome({ status: 'unknown', message: UNKNOWN });
        } catch {
            if (mounted.current)
                setOutcome({ status: 'unknown', message: UNKNOWN });
        } finally {
            busy.current = false;
            if (mounted.current) setPending(false);
        }
    };

    /** Reads the one shift by its number. It reports current state only. */
    const checkCurrent = async () => {
        const captured = review;
        if (
            busy.current ||
            checking ||
            !captured ||
            outcome?.status !== 'unknown'
        )
            return;
        setChecking(true);
        setReadback('');
        try {
            const params = new URLSearchParams({
                q: String(captured.intent.row.id),
                page: '1',
                per_page: '25',
            });
            const response = await fetch(
                `/operations/job-board/postable-shifts?${params}`,
                readInit(),
            );
            if (response.status !== 200)
                throw new Error(
                    "We couldn't check the shift right now. The earlier post is still not confirmed.",
                );
            const result = readPostablePage(await response.json(), 1);
            if (!mounted.current) return;
            setReadback(
                postingReadback(
                    result.data.find(
                        (row) => row.id === captured.intent.row.id,
                    ),
                ),
            );
            setChecked(true);
        } catch (failure) {
            if (mounted.current)
                setReadback(
                    failure instanceof Error &&
                        failure.message.startsWith('We couldn')
                        ? failure.message
                        : "We couldn't check the shift right now. The earlier post is still not confirmed.",
                );
        } finally {
            if (mounted.current) setChecking(false);
        }
    };

    /** After a check, drop the unconfirmed attempt but keep the typed entries. */
    const startAgain = () => {
        if (
            busy.current ||
            checking ||
            !checked ||
            outcome?.status !== 'unknown'
        )
            return false;
        setOutcome(null);
        setReview(null);
        setSelection(null);
        setReadback('');
        setChecked(false);
        setReload((n) => n + 1);
        return true;
    };

    const clearDraft = () => {
        if (busy.current || outcome?.status === 'unknown') return;
        setDetails({ ...EMPTY_DETAILS });
        setSelection(null);
        setReview(null);
        setOutcome(null);
        setError('');
        setReadback('');
        setChecked(false);
        setQuery('');
        setRange('week');
        setPage(1);
        setReload((n) => n + 1);
    };

    return {
        listing,
        selection,
        details,
        review,
        outcome,
        error,
        pending,
        busy,
        checking,
        readback,
        checked,
        range,
        query,
        page,
        dirty: Boolean(
            selection || details.skills || details.notes || details.expiry,
        ),
        choose,
        changeDetails,
        captureReview,
        submit,
        checkCurrent,
        startAgain,
        clearDraft,
        search: (value: string) => {
            if (!editable()) return;
            if ([...value].length > 100) {
                setError('Keep the shift search to 100 characters or fewer.');
                return;
            }
            setQuery(value.trim());
            setPage(1);
            setError('');
        },
        changeRange: (value: 'week' | 'all') => {
            if (!editable()) return;
            setRange(value);
            setPage(1);
        },
        changePage: (value: number) => {
            if (editable() && value >= 1 && value <= 10000) setPage(value);
        },
        reloadList: () => {
            if (editable()) setReload((n) => n + 1);
        },
    };
}

export type PostingController = ReturnType<typeof usePosting>;
