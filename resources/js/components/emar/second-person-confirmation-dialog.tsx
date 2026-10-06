import ConfirmDialog from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { ErrorState } from '@/components/ui/error-state';
import { LoadingState } from '@/components/ui/loading-state';
import { formatDateTime } from '@/lib/datetime';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { CheckCircle2, Clock, Loader2, UserCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

export type SecondPersonConfirmationStatus =
    | 'pending'
    | 'confirmed'
    | 'disputed'
    | 'expired';

export interface SecondPersonConfirmationAnswer {
    status: Exclude<SecondPersonConfirmationStatus, 'pending'>;
    replayed: boolean;
}

export interface SecondPersonConfirmationDetails {
    id: number;
    followup_id: number;
    status: SecondPersonConfirmationStatus;
    due_at: string;
    server_now: string;
    person_name: string;
    medication_name: string;
    given_at: string;
    recorded_by: string;
}

function isDetails(
    value: unknown,
    nominationId: number,
): value is SecondPersonConfirmationDetails {
    if (typeof value !== 'object' || value === null) return false;
    const data = value as Partial<SecondPersonConfirmationDetails>;
    return (
        data.id === nominationId &&
        typeof data.followup_id === 'number' &&
        ['pending', 'confirmed', 'disputed', 'expired'].includes(
            String(data.status),
        ) &&
        [data.due_at, data.server_now, data.given_at].every(
            (date) =>
                typeof date === 'string' && Number.isFinite(Date.parse(date)),
        ) &&
        [data.person_name, data.medication_name, data.recorded_by].every(
            (text) => typeof text === 'string',
        )
    );
}

function isAnswer(value: unknown): value is SecondPersonConfirmationAnswer {
    if (typeof value !== 'object' || value === null) return false;
    const data = value as Partial<SecondPersonConfirmationAnswer>;
    return (
        ['confirmed', 'disputed', 'expired'].includes(String(data.status)) &&
        typeof data.replayed === 'boolean'
    );
}

export interface SecondPersonConfirmationDialogProps {
    open: boolean;
    nominationId: number | null;
    onOpenChange: (open: boolean) => void;
    onAnswered?: (
        answer: SecondPersonConfirmationAnswer,
        nominationId: number,
    ) => void;
}

/** The P08a row supplies context.nomination_id; private details come from the authorized source API. */
export function SecondPersonConfirmationDialog(
    props: SecondPersonConfirmationDialogProps,
) {
    const { auth } = usePage<SharedData>().props;
    const actorId = auth.user?.id ?? null;
    const mayRead = auth.can?.medications?.view !== false;

    return (
        <Dialog open={props.open} onOpenChange={props.onOpenChange}>
            <DialogContent
                className="frontline-dialog flex max-h-[90vh] flex-col gap-0 overflow-hidden p-0 [&>[data-slot=dialog-close]]:top-5 [&>[data-slot=dialog-close]]:right-5"
                style={{
                    width: 'min(92vw, 480px)',
                    maxWidth: 'min(92vw, 480px)',
                }}
            >
                {props.open &&
                props.nominationId !== null &&
                actorId !== null &&
                mayRead ? (
                    <ConfirmationBody
                        key={String(actorId) + ':' + String(props.nominationId)}
                        {...props}
                        nominationId={props.nominationId}
                    />
                ) : (
                    <DialogHeader className="p-6">
                        <DialogTitle>Were you there?</DialogTitle>
                        <DialogDescription>
                            {actorId === null
                                ? 'Sign in to answer your confirmation request.'
                                : 'This confirmation is no longer available to you.'}
                        </DialogDescription>
                    </DialogHeader>
                )}
            </DialogContent>
        </Dialog>
    );
}

function ConfirmationBody({
    nominationId,
    onOpenChange,
    onAnswered,
}: SecondPersonConfirmationDialogProps & { nominationId: number }) {
    const [details, setDetails] =
        useState<SecondPersonConfirmationDetails | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [retry, setRetry] = useState(0);
    const [choice, setChoice] = useState<boolean | null>(null);
    const [sending, setSending] = useState(false);
    const [answer, setAnswer] = useState<SecondPersonConfirmationAnswer | null>(
        null,
    );
    const [saveError, setSaveError] = useState<string | null>(null);
    const [uncertainAnswer, setUncertainAnswer] = useState<boolean | null>(
        null,
    );
    const [secondsLeft, setSecondsLeft] = useState<number | null>(null);
    const live = useRef(false);
    const mutation = useRef<AbortController | null>(null);
    const saving = useRef(false);

    useEffect(() => {
        live.current = true;
        const controller = new AbortController();
        axios
            .get<SecondPersonConfirmationDetails>(
                '/meds/confirmations/' + nominationId,
                {
                    signal: controller.signal,
                    headers: { Accept: 'application/json' },
                },
            )
            .then(({ data }) => {
                if (!controller.signal.aborted && live.current) {
                    if (!isDetails(data, nominationId))
                        throw new Error('Unexpected confirmation response.');
                    setDetails(data);
                }
            })
            .catch((error: unknown) => {
                if (controller.signal.aborted || !live.current) return;
                const status = axios.isAxiosError(error)
                    ? error.response?.status
                    : undefined;
                setLoadError(
                    status === 401 || status === 419
                        ? 'Your session ended. Sign in again to answer.'
                        : status === 403 || status === 404
                          ? 'This confirmation is no longer available to you.'
                          : 'The confirmation could not load. Try again.',
                );
            });
        return () => {
            live.current = false;
            controller.abort();
            mutation.current?.abort();
        };
    }, [nominationId, retry]);

    useEffect(() => {
        if (!details) return;
        const budget =
            (Date.parse(details.due_at) - Date.parse(details.server_now)) /
            1000;
        const receivedAt = performance.now();
        const tick = () =>
            setSecondsLeft(
                Math.max(
                    0,
                    Math.ceil(budget - (performance.now() - receivedAt) / 1000),
                ),
            );
        tick();
        const timer = window.setInterval(tick, 1000);
        return () => window.clearInterval(timer);
    }, [details]);

    const terminal =
        answer?.status ??
        (details?.status !== 'pending' ? details?.status : null);
    const expired =
        terminal === 'expired' || (details !== null && secondsLeft === 0);
    const canAnswer =
        details?.status === 'pending' &&
        terminal === null &&
        secondsLeft !== null &&
        secondsLeft > 0 &&
        uncertainAnswer === null;

    async function submit(wasThere: boolean) {
        if (saving.current) return;
        saving.current = true;
        setSending(true);
        setSaveError(null);
        const controller = new AbortController();
        mutation.current = controller;
        let completed: SecondPersonConfirmationAnswer | null = null;
        try {
            const { data } = await axios.post<SecondPersonConfirmationAnswer>(
                '/meds/confirmations/' + nominationId,
                {
                    was_there: wasThere,
                },
                {
                    signal: controller.signal,
                    headers: { Accept: 'application/json' },
                },
            );
            if (!live.current || controller.signal.aborted) return;
            if (!isAnswer(data)) throw new Error('Unconfirmed save response.');
            completed = data;
            setAnswer(data);
            setChoice(null);
            setUncertainAnswer(null);
        } catch (error: unknown) {
            if (!live.current || controller.signal.aborted) return;
            const status = axios.isAxiosError(error)
                ? error.response?.status
                : undefined;
            setChoice(null);
            if (
                status === 401 ||
                status === 419 ||
                status === 403 ||
                status === 404
            ) {
                setDetails(null);
                setLoadError(
                    status === 401 || status === 419
                        ? 'Your session ended. Sign in again to answer.'
                        : 'This confirmation is no longer available to you.',
                );
            } else if (status === 422) {
                setDetails(null);
                setLoadError(
                    'Your answer could not be changed. Reload to see the recorded result.',
                );
            } else {
                setUncertainAnswer(wasThere);
                setSaveError(
                    'We couldn’t confirm whether your answer saved. Retry the same answer to check safely.',
                );
            }
        } finally {
            saving.current = false;
            if (live.current && !controller.signal.aborted) setSending(false);
        }
        if (completed && live.current) onAnswered?.(completed, nominationId);
    }

    function reload() {
        setDetails(null);
        setLoadError(null);
        setAnswer(null);
        setChoice(null);
        setSaveError(null);
        setUncertainAnswer(null);
        setSecondsLeft(null);
        setRetry((value) => value + 1);
    }

    return (
        <>
            <DialogHeader className="shrink-0 border-b px-6 py-5 pr-14 text-left">
                <div className="flex items-center gap-3">
                    <span className="rounded-xl bg-primary/10 p-2.5 text-primary">
                        <UserCheck className="size-5" aria-hidden="true" />
                    </span>
                    <div>
                        <DialogTitle>Were you there?</DialogTitle>
                        <DialogDescription>
                            Answer in your own login.
                        </DialogDescription>
                    </div>
                </div>
            </DialogHeader>
            <div
                className="min-h-0 min-w-0 space-y-4 overflow-y-auto px-6 py-5 [overflow-wrap:anywhere]"
                aria-live="polite"
                aria-busy={sending}
            >
                {loadError ? (
                    <ErrorState
                        title="Confirmation unavailable"
                        message={loadError}
                        onRetry={reload}
                        className="py-4"
                    />
                ) : !details ? (
                    <LoadingState
                        message="Checking your confirmation…"
                        className="py-4"
                    />
                ) : (
                    <>
                        <dl className="space-y-3 text-sm">
                            <div>
                                <dt className="text-muted-foreground">
                                    Person
                                </dt>
                                <dd className="font-medium">
                                    {details.person_name}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Medicine
                                </dt>
                                <dd className="font-medium">
                                    {details.medication_name}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Recorded as given
                                </dt>
                                <dd>{formatDateTime(details.given_at)}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Recorded by
                                </dt>
                                <dd>{details.recorded_by}</dd>
                            </div>
                        </dl>
                        {terminal === 'confirmed' ? (
                            <p className="flex gap-2 text-sm">
                                <CheckCircle2
                                    className="size-5 shrink-0 text-primary"
                                    aria-hidden="true"
                                />
                                Your confirmation is recorded.
                            </p>
                        ) : terminal === 'disputed' ? (
                            <p className="text-sm">
                                Your answer is recorded. A house lead will check
                                this dose.
                            </p>
                        ) : expired ? (
                            <p className="text-sm">
                                The 30-minute window has ended. A house lead
                                will need to check this dose.
                            </p>
                        ) : (
                            <p className="flex gap-2 text-sm text-muted-foreground">
                                <Clock
                                    className="size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                Confirm by {formatDateTime(details.due_at)}.
                            </p>
                        )}
                        {canAnswer && (
                            <p className="text-sm">
                                Were you there when this medicine was given?
                                Your answer becomes part of the dose record.
                            </p>
                        )}
                        {saveError && (
                            <p
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                {saveError}
                            </p>
                        )}
                    </>
                )}
            </div>
            <DialogFooter className="shrink-0 flex-wrap border-t bg-muted/30 px-6 py-4">
                <Button
                    variant="outline"
                    className="frontline-hit"
                    onClick={() => onOpenChange(false)}
                    disabled={sending}
                >
                    Close
                </Button>
                {canAnswer && (
                    <>
                        <Button
                            variant="outline"
                            className="frontline-hit"
                            onClick={() => setChoice(false)}
                            disabled={sending}
                        >
                            I wasn’t there
                        </Button>
                        <Button
                            className="frontline-hit"
                            onClick={() => setChoice(true)}
                            disabled={sending}
                        >
                            I was there
                        </Button>
                    </>
                )}
                {uncertainAnswer !== null && details && (
                    <Button
                        className="frontline-hit"
                        onClick={() => void submit(uncertainAnswer)}
                        disabled={sending}
                    >
                        {sending && (
                            <Loader2
                                className="size-4 animate-spin"
                                aria-hidden="true"
                            />
                        )}
                        Retry same answer
                    </Button>
                )}
            </DialogFooter>
            <ConfirmDialog
                frontline
                open={choice !== null && !expired}
                onClose={() => !sending && setChoice(null)}
                title={
                    choice
                        ? 'Record that you were there?'
                        : 'Record that you weren’t there?'
                }
                description={
                    choice
                        ? 'This confirms you were there for this dose. Your recorded answer cannot be changed here.'
                        : 'A house lead will check this dose. Your recorded answer cannot be changed here.'
                }
                confirmText="Record answer"
                cancelText="Back"
                variant="default"
                processing={sending}
                buttonClassName="frontline-hit"
                onConfirm={() => choice !== null && void submit(choice)}
            />
        </>
    );
}
