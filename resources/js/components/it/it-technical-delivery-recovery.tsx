import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTime } from '@/lib/datetime';
import axios from 'axios';
import { Loader2, RefreshCw } from 'lucide-react';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';
import {
    deliveryOutcome,
    deliveryStates,
    deliveryTone,
    parseDeliveryReview,
    type DeliveryReview,
    type DeliverySource,
} from './it-technical-delivery-contract';

export function ItTechnicalDeliveryRecovery({
    actorId,
    source,
    deliveryId,
    onAccessLost,
}: {
    actorId: number;
    source: DeliverySource;
    deliveryId: number;
    onAccessLost: () => void;
}) {
    const [review, setReview] = useState<DeliveryReview | null>(null);
    const [stage, setStage] = useState<
        'loading' | 'ready' | 'requesting' | 'refresh' | 'denied'
    >('loading');
    const [confirmed, setConfirmed] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const active = useRef<AbortController | null>(null);
    const epoch = useRef(0);
    const accessLost = useRef(onAccessLost);
    const feedback = useRef<HTMLDivElement | null>(null);
    useLayoutEffect(() => {
        accessLost.current = onAccessLost;
    }, [onAccessLost]);
    useLayoutEffect(() => {
        if (message) feedback.current?.focus();
    }, [message]);

    const run = useCallback(
        async (proposal?: DeliveryReview) => {
            if (active.current) return;
            const request = new AbortController();
            active.current = request;
            const generation = ++epoch.current;
            const url = `/it/setup/technical-deliveries/${source}/${deliveryId}`;
            setStage(proposal ? 'requesting' : 'loading');
            setConfirmed(false);
            setMessage(null);
            setReview(null);
            try {
                const response = proposal
                    ? await axios.post(
                          `${url}/retry`,
                          {
                              viewer_user_id: actorId,
                              version: proposal.version,
                          },
                          { signal: request.signal, timeout: 15000 },
                      )
                    : await axios.get(url, {
                          params: { viewer_user_id: actorId },
                          signal: request.signal,
                          timeout: 15000,
                      });
                if (epoch.current !== generation) return;
                const result = parseDeliveryReview(
                    response.data,
                    actorId,
                    source,
                    deliveryId,
                    !!proposal,
                );
                if (!result) throw new Error('Unconfirmed delivery response');
                setReview(result);
                setStage('ready');
                if (proposal)
                    setMessage(
                        'One retry allowance was recorded. The current delivery outcome is shown below.',
                    );
            } catch (error) {
                if (epoch.current !== generation) return;
                const status = axios.isAxiosError(error)
                    ? error.response?.status
                    : undefined;
                setReview(null);
                if (status && [401, 403, 404, 419].includes(status)) {
                    setStage('denied');
                    setMessage(
                        'Delivery access is unavailable. Refresh the page or sign in again.',
                    );
                    accessLost.current();
                } else {
                    setStage('refresh');
                    setMessage(
                        status === 409
                            ? 'Delivery changed. Refresh and review the current outcome before requesting another retry.'
                            : proposal
                              ? 'The retry result could not be confirmed. Check the current delivery outcome before requesting another attempt.'
                              : 'The current delivery could not be checked. Try refreshing the outcome.',
                    );
                }
            } finally {
                if (epoch.current === generation) active.current = null;
            }
        },
        [actorId, deliveryId, source],
    );

    const invalidate = useCallback(() => {
        epoch.current++;
        active.current?.abort();
        active.current = null;
    }, []);
    useEffect(() => {
        void run();
        return invalidate;
    }, [run, invalidate]);
    const stopWaiting = () => {
        invalidate();
        setReview(null);
        setStage('refresh');
        setMessage(
            'Stopped waiting. This does not cancel a recorded retry. Check the current delivery outcome before requesting another attempt.',
        );
    };
    const busy = stage === 'loading' || stage === 'requesting';
    return (
        <div className="space-y-5">
            <div
                ref={feedback}
                tabIndex={-1}
                role="status"
                className="outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
                {message ? (
                    <p className="text-subtle">{message}</p>
                ) : busy ? (
                    <p className="text-subtle flex items-center gap-2">
                        <Loader2
                            className="size-4 animate-spin"
                            aria-hidden="true"
                        />
                        {stage === 'requesting'
                            ? 'Recording one retry allowance…'
                            : 'Checking current delivery…'}
                    </p>
                ) : null}
            </div>
            {review ? (
                <>
                    <StatusBadge variant={deliveryTone(review)}>
                        {deliveryStates[review.state]}
                    </StatusBadge>
                    <dl className="grid grid-cols-2 gap-4">
                        <div>
                            <dt className="text-caption">
                                Source acknowledgement
                            </dt>
                            <dd className="text-subtle text-foreground">
                                {review.source_delivered
                                    ? 'Recorded'
                                    : 'Not completed'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-caption">
                                Lifetime attempts / current limit
                            </dt>
                            <dd className="text-subtle text-foreground">
                                {review.attempts ?? 'Unrecorded'} /{' '}
                                {review.attempt_limit ?? 'Unrecorded'}
                            </dd>
                        </div>
                        <div className="col-span-2">
                            <dt className="text-caption">
                                Current recorded result
                            </dt>
                            <dd className="text-subtle text-foreground">
                                {deliveryOutcome(review)}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-caption">First recorded</dt>
                            <dd className="text-subtle text-foreground">
                                {formatDateTime(
                                    review.created_at,
                                    'Not recorded',
                                )}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-caption">Completed</dt>
                            <dd className="text-subtle text-foreground">
                                {formatDateTime(
                                    review.completed_at,
                                    'No completed outcome',
                                )}
                            </dd>
                        </div>
                    </dl>
                    {review.can_retry ? (
                        <div className="space-y-3">
                            <p className="text-subtle">
                                Review the source or routing problem before
                                retrying. This allows one attempt using the
                                recorded source evidence and preserves the
                                delivery history.
                            </p>
                            <label className="text-subtle flex items-start gap-2 text-foreground">
                                <Checkbox
                                    checked={confirmed}
                                    onCheckedChange={(value) =>
                                        setConfirmed(value === true)
                                    }
                                />
                                I want to request one delivery attempt.
                            </label>
                            <Button
                                disabled={!confirmed}
                                onClick={() => void run(review)}
                            >
                                <RefreshCw className="size-4" />
                                Request one retry
                            </Button>
                        </div>
                    ) : (
                        <p className="text-subtle">
                            This outcome does not currently permit another
                            retry. Recovery evidence requires technician
                            verification and does not close the ticket.
                        </p>
                    )}
                </>
            ) : null}
            {busy ? (
                <Button variant="outline" onClick={stopWaiting}>
                    Stop waiting
                </Button>
            ) : stage !== 'denied' ? (
                <Button variant="outline" onClick={() => void run()}>
                    Refresh delivery outcome
                </Button>
            ) : null}
            <p className="text-caption">
                Closing this view stops waiting; it does not cancel a retry
                already recorded by the server. Reopen the delivery to check its
                current outcome.
            </p>
        </div>
    );
}
