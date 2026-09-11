import { Button } from '@/components/ui/button';
import { newItCommentUuid } from '@/hooks/it-ticket-comment-contract';
import {
    duplicateReasonLabels,
    readDuplicateMatches,
    type DuplicateMatch,
} from '@/hooks/it-ticket-duplicate-contract';
import axios from 'axios';
import { ExternalLink, Search } from 'lucide-react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';

interface Props {
    actorId: number;
    title: string;
    siteId: number | null;
    workType?: string;
    serviceId?: number | null;
    organisationWide?: boolean;
    sourceId?: number;
    sourceVersion?: number;
    disabled?: boolean;
}
export function TicketDuplicateSuggestions(props: Props) {
    // Immediately discard old result labels when the draft, record version or viewer changes.
    const scope = JSON.stringify([
        props.actorId,
        props.title,
        props.siteId,
        props.workType,
        props.serviceId,
        props.organisationWide,
        props.sourceId,
        props.sourceVersion,
        props.disabled,
    ]);
    return <DuplicateSuggestionsBody key={scope} {...props} />;
}
function DuplicateSuggestionsBody({
    actorId,
    title,
    siteId,
    workType = 'incident',
    serviceId = null,
    organisationWide = false,
    sourceId,
    disabled = false,
}: Props) {
    const [state, setState] = useState<
        'idle' | 'loading' | 'ready' | 'error' | 'access' | 'session'
    >('idle');
    const [matches, setMatches] = useState<DuplicateMatch[]>([]);
    const [message, setMessage] = useState<string | null>(null);
    const request = useRef<AbortController | null>(null);
    const epoch = useRef(0);
    const checkButton = useRef<HTMLButtonElement>(null);
    const restoreFocus = useRef(false);
    useLayoutEffect(() => {
        if (state === 'loading' || !restoreFocus.current) return;
        restoreFocus.current = false;
        const active = document.activeElement;
        const dialog = checkButton.current?.closest('[role="dialog"]');
        // Restore focus lost by disabling/removing a busy control. Do not
        // interrupt someone who moved to another field while the read ran.
        if (
            active === document.body ||
            active === dialog ||
            active === checkButton.current
        )
            checkButton.current?.focus({ preventScroll: true });
    }, [state]);
    const eligible =
        !disabled &&
        (sourceId !== undefined ||
            (title.trim().length >= 3 &&
                (siteId !== null || organisationWide)));
    useEffect(
        () => () => {
            ++epoch.current;
            request.current?.abort();
        },
        [],
    );
    const cancel = () => {
        ++epoch.current;
        request.current?.abort();
        request.current = null;
        setState('idle');
        setMatches([]);
        setMessage(
            sourceId === undefined
                ? 'Check stopped. Your ticket draft is unchanged.'
                : 'Check stopped. This ticket is unchanged.',
        );
    };
    const check = async () => {
        if (!eligible || request.current) return;
        const controller = new AbortController();
        restoreFocus.current = true;
        request.current = controller;
        const token = ++epoch.current;
        const uuid = newItCommentUuid();
        setState('loading');
        setMatches([]);
        setMessage(null);
        try {
            const response = await axios.post(
                sourceId === undefined
                    ? '/it/tickets/duplicate-suggestions'
                    : `/it/tickets/${sourceId}/duplicate-suggestions`,
                {
                    actor_user_id: actorId,
                    query_uuid: uuid,
                    ...(sourceId === undefined
                        ? {
                              title: title.trim(),
                              site_id: siteId,
                              work_type: workType,
                              it_service_id: serviceId,
                              is_organisation_wide: organisationWide,
                          }
                        : {}),
                },
                {
                    signal: controller.signal,
                    timeout: 15000,
                    headers: { Accept: 'application/json' },
                },
            );
            if (epoch.current !== token) return;
            const next = readDuplicateMatches(
                response.data,
                actorId,
                uuid,
                sourceId ?? null,
            );
            if (!next) throw new Error('Unconfirmed suggestions');
            setMatches(next);
            setState('ready');
        } catch (error) {
            if (epoch.current !== token) return;
            const status = axios.isAxiosError(error)
                ? error.response?.status
                : undefined;
            setMatches([]);
            if (status === 401 || status === 419) {
                setState('session');
                setMessage(
                    'Sign in again before checking for similar tickets.',
                );
            } else if (status === 403 || status === 404) {
                setState('access');
                setMessage(
                    'Ticket suggestions are no longer available for this account or Site.',
                );
            } else {
                setState('error');
                setMessage(
                    status === 422
                        ? 'Review the title, Site and service before checking again.'
                        : 'Similar tickets could not be checked. Retry the check; your draft is unchanged.',
                );
            }
        } finally {
            if (epoch.current === token) request.current = null;
        }
    };
    return (
        <section
            aria-label="Similar open tickets"
            className="my-4 space-y-3 rounded-xl border border-border bg-muted/20 p-4"
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0 flex-1">
                    <h3 className="text-section-title">Similar open tickets</h3>
                    <p className="text-caption text-muted-foreground">
                        Check for an existing report before continuing. Matches
                        suggest a connection; you decide whether this is a
                        separate request.
                    </p>
                </div>
                <Button
                    ref={checkButton}
                    variant="outline"
                    type="button"
                    onClick={check}
                    disabled={!eligible || state === 'loading'}
                >
                    <Search className="h-4 w-4" />
                    {state === 'session'
                        ? 'Check after signing in'
                        : state === 'error' || state === 'access'
                          ? 'Retry ticket check'
                          : 'Check similar tickets'}
                </Button>
            </div>
            {!eligible && (
                <p className="text-caption text-muted-foreground">
                    Enter at least three title characters and choose an approved
                    Site to check.
                </p>
            )}
            {state === 'loading' && (
                <div className="flex items-center gap-3">
                    <p role="status">Checking permitted open tickets…</p>
                    <Button variant="outline" type="button" onClick={cancel}>
                        Stop checking
                    </Button>
                </div>
            )}
            {message && (
                <p
                    role={state === 'idle' ? 'status' : 'alert'}
                    className="text-subtle"
                >
                    {message}
                </p>
            )}
            {state === 'session' && (
                <a
                    href="/login"
                    target="_blank"
                    rel="noopener noreferrer"
                    className="text-primary underline"
                >
                    Sign in (opens in a new tab)
                </a>
            )}
            {state === 'ready' && (
                <>
                    <p
                        role="status"
                        className="text-caption text-muted-foreground"
                    >
                        {matches.length
                            ? `${matches.length} possible ${matches.length === 1 ? 'match' : 'matches'} found.`
                            : 'No similar ticket found in this check.'}{' '}
                        Checks up to 100 recent open tickets you can access with
                        the same work type and affected area; shows up to 10
                        matches. Older or differently worded reports may still
                        exist.
                    </p>
                    {matches.length > 0 && (
                        <ul className="divide-y divide-border">
                            {matches.map((match) => (
                                <li key={match.id} className="space-y-1 py-3">
                                    <a
                                        href={match.href}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="flex items-center gap-2 font-medium break-words text-primary underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring"
                                    >
                                        <span className="min-w-0">
                                            {match.reference} · {match.title}
                                        </span>
                                        <ExternalLink className="h-4 w-4 shrink-0" />
                                        <span className="sr-only">
                                            {' '}
                                            (opens in a new tab)
                                        </span>
                                    </a>
                                    <p className="text-caption text-muted-foreground">
                                        {match.reasons
                                            .map(
                                                (reason) =>
                                                    duplicateReasonLabels[
                                                        reason
                                                    ],
                                            )
                                            .join(' · ')}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                    <p className="text-caption text-muted-foreground">
                        {sourceId === undefined
                            ? 'Opening a ticket keeps this draft here.'
                            : 'Opening a ticket keeps this record here.'}{' '}
                        No tickets are linked, merged or submitted by this
                        check.
                    </p>
                </>
            )}
        </section>
    );
}
