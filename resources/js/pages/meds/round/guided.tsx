import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Home,
    Pause,
    Pill,
    XCircle,
} from 'lucide-react';
import { useMemo, useState } from 'react';

import MedRoundCard from '@/components/med-round-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Progress } from '@/components/ui/progress';
import { Textarea } from '@/components/ui/textarea';
import { useOfflineQueueState } from '@/hooks/use-offline-queue';
import { submitOffline } from '@/lib/offline-queue';

/* -------------------------------------------------------------------------- */
/*  PR 9 — Guided Medication Round                                            */
/* -------------------------------------------------------------------------- */
/*
 * Worker-facing, one-med-at-a-time full-screen flow.
 *
 * Source of truth: the existing MedicationRound + ClientMedicationAdministration
 * tables. Progress is derived from administrations linked to this round, so
 * resuming a round always picks up at the first dose that still needs action
 * and never double-administers.
 *
 * Actions are plain frontline language (Given / Refused / Held). They map to
 * the backend statuses given / refused / withheld inside the controller. The
 * API is reused via EnhancedMarService so controlled-drug, safety and audit
 * logic continues to run unchanged.
 */

type ActionKind = 'given' | 'refused' | 'held';

interface AdministrationSummary {
    id: number;
    status: string;
    reason: string | null;
    administered_at: string | null;
}

interface RoundItem {
    client_id: number;
    client_name: string;
    client_photo_url: string | null;
    medication_id: number;
    medication_name: string;
    dose: string | null;
    route: string | null;
    form: string | null;
    instructions: string | null;
    is_controlled: boolean;
    is_high_risk: boolean;
    requires_witness: boolean;
    scheduled_for: string;
    administration: AdministrationSummary | null;
}

interface RoundSummary {
    id: number;
    name: string;
    status: string;
    scheduled_time: string;
    window_minutes: number;
    round_date: string | null;
    started_at: string | null;
    completed_at: string | null;
}

interface Progress {
    total: number;
    completed: number;
    pending: number;
    given: number;
    refused: number;
    held: number;
    next_index: number | null;
    percent: number;
}

interface Props {
    round: RoundSummary;
    items: RoundItem[];
    progress: Progress;
}

function statusLabel(status: string | null): string {
    switch (status) {
        case 'given':
            return 'Given';
        case 'refused':
            return 'Refused';
        case 'withheld':
        case 'held':
            return 'Held';
        case 'missed':
            return 'Missed';
        default:
            return 'Due';
    }
}

function itemKey(item: RoundItem): string {
    return `${item.medication_id}:${item.scheduled_for}`;
}

export default function GuidedRound({ round, items, progress }: Props) {
    // Start the worker at the first dose that still needs action. If the
    // whole round is already done we park on the last item so the completion
    // screen renders.
    const initialIndex = progress.next_index ?? Math.max(0, items.length - 1);
    const [currentIndex, setCurrentIndex] = useState(initialIndex);
    const [pendingAction, setPendingAction] = useState<ActionKind | null>(null);
    const [reason, setReason] = useState('');
    const [processing, setProcessing] = useState(false);
    const [statusError, setStatusError] = useState<string | null>(null);
    const [queuedKeys, setQueuedKeys] = useState<Set<string>>(() => new Set());
    const { online } = useOfflineQueueState();

    const current: RoundItem | undefined = items[currentIndex];
    const isRoundFinished = progress.pending === 0;
    const currentQueued = current ? queuedKeys.has(itemKey(current)) : false;
    const currentActioned = !!current?.administration || currentQueued;

    // Reset the dialog state whenever we move between items so a stale reason
    // can't accidentally be submitted for the wrong dose.
    const goTo = (nextIndex: number) => {
        setPendingAction(null);
        setReason('');
        setStatusError(null);
        setCurrentIndex(
            Math.min(Math.max(nextIndex, 0), Math.max(items.length - 1, 0)),
        );
    };

    function openAction(action: ActionKind) {
        if (!current) return;
        setStatusError(null);
        setPendingAction(action);
        setReason('');
    }

    function advanceAfterSubmit(submittedKey: string) {
        const nextPending = items.findIndex(
            (it, idx) =>
                idx > currentIndex &&
                !it.administration &&
                !queuedKeys.has(itemKey(it)) &&
                itemKey(it) !== submittedKey,
        );
        if (nextPending >= 0) {
            setCurrentIndex(nextPending);
        } else if (currentIndex < items.length - 1) {
            setCurrentIndex(currentIndex + 1);
        }
    }

    async function submitAction() {
        if (!current || !pendingAction) return;

        // "Given" does not require a reason; "Refused" and "Held" always do.
        if (pendingAction !== 'given' && reason.trim().length === 0) return;

        setProcessing(true);
        setStatusError(null);

        const key = itemKey(current);
        const payload = {
            status: pendingAction,
            reason: pendingAction === 'given' ? '' : reason.trim(),
            scheduled_for: current.scheduled_for,
        };

        try {
            const result = await submitOffline({
                action: 'round_admin',
                url: `/emar/rounds/${round.id}/guided/items/${current.medication_id}`,
                payload,
                queuedMessage:
                    'Round dose saved on this device — we\u2019ll send it when you\u2019re back online.',
            });

            setPendingAction(null);
            setReason('');

            if (result.status === 'queued') {
                setQueuedKeys((currentKeys) => new Set(currentKeys).add(key));
                advanceAfterSubmit(key);
                return;
            }

            router.reload({ preserveScroll: true });
            advanceAfterSubmit(key);
        } catch (error: unknown) {
            setStatusError(
                error instanceof Error
                    ? error.message
                    : 'Could not record this dose.',
            );
        } finally {
            setProcessing(false);
        }
    }

    function completeRound() {
        router.post(
            `/emar/rounds/${round.id}/guided/complete`,
            {},
            { preserveScroll: false },
        );
    }

    const headerTitle = useMemo(
        () => round.name || `Round at ${round.scheduled_time?.slice(0, 5)}`,
        [round.name, round.scheduled_time],
    );

    return (
        <div className="min-h-dvh bg-muted/30">
            <Head title={`Guided round — ${headerTitle}`} />

            {/* ── Top bar ─────────────────────────────────────────────── */}
            <header className="sticky top-0 z-20 border-b bg-background/95 backdrop-blur">
                <div className="mx-auto flex max-w-2xl items-center gap-3 px-4 py-3">
                    <Button
                        variant="ghost"
                        size="icon"
                        asChild
                        className="h-11 w-11"
                    >
                        <Link href="/my-day" aria-label="Back to My Day">
                            <ArrowLeft aria-hidden className="h-5 w-5" />
                        </Link>
                    </Button>
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm leading-tight font-semibold">
                            {headerTitle}
                        </p>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {isRoundFinished
                                ? 'Round complete'
                                : `${progress.completed} of ${progress.total} done`}
                        </p>
                    </div>
                    {round.status === 'completed' && (
                        <Badge variant="secondary" className="shrink-0">
                            Completed
                        </Badge>
                    )}
                </div>
                <div className="mx-auto max-w-2xl px-4 pb-3">
                    <Progress value={progress.percent} className="h-2" />
                </div>
            </header>

            {/* ── Content ─────────────────────────────────────────────── */}
            <main className="mx-auto w-full max-w-2xl px-4 pt-4 pb-[calc(8rem+env(safe-area-inset-bottom,0px))] sm:pt-6">
                {items.length === 0 ? (
                    <EmptyRound roundName={headerTitle} />
                ) : isRoundFinished ? (
                    <RoundCompleteView
                        progress={progress}
                        items={items}
                        onComplete={completeRound}
                        alreadyCompleted={round.status === 'completed'}
                    />
                ) : current ? (
                    <>
                        <div className="mb-3 flex items-center justify-between text-xs text-muted-foreground">
                            <span>
                                {currentIndex + 1} of {items.length}
                            </span>
                            {(current.administration || currentQueued) && (
                                <Badge
                                    variant="outline"
                                    className="text-[10px] tracking-wide uppercase"
                                >
                                    {currentQueued
                                        ? 'Queued'
                                        : statusLabel(
                                              current.administration?.status ??
                                                  null,
                                          )}
                                </Badge>
                            )}
                        </div>

                        <MedRoundCard
                            clientName={current.client_name}
                            clientPhotoUrl={current.client_photo_url}
                            medicationName={current.medication_name}
                            dose={current.dose}
                            route={current.route}
                            form={current.form}
                            instructions={current.instructions}
                            scheduledFor={current.scheduled_for}
                            isControlled={current.is_controlled}
                            isHighRisk={current.is_high_risk}
                            requiresWitness={current.requires_witness}
                        />

                        {statusError && (
                            <p className="mt-3 rounded-md border border-status-critical/30 bg-status-critical-bg px-3 py-2 text-sm text-status-critical dark:border-status-critical/30 dark:bg-status-critical-bg dark:text-status-critical">
                                {statusError}
                            </p>
                        )}

                        {/* Navigation between items (useful when resuming or
                            reviewing). Does not submit anything. */}
                        <div className="mt-4 flex items-center justify-between">
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => goTo(currentIndex - 1)}
                                disabled={currentIndex === 0}
                                aria-label="Previous dose"
                                className="min-h-11 px-3"
                            >
                                <ChevronLeft
                                    aria-hidden
                                    className="mr-1 h-4 w-4"
                                />
                                Previous
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => goTo(currentIndex + 1)}
                                disabled={currentIndex >= items.length - 1}
                                aria-label="Next dose"
                                className="min-h-11 px-3"
                            >
                                Next
                                <ChevronRight
                                    aria-hidden
                                    className="ml-1 h-4 w-4"
                                />
                            </Button>
                        </div>
                    </>
                ) : null}
            </main>

            {/* ── Action bar (sticky, mobile-first) ───────────────────── */}
            {current && !isRoundFinished && (
                <div className="frontline-sticky-footer fixed inset-x-0 bottom-0 z-30 border-t bg-background/95 px-4 pt-3 shadow-[0_-4px_12px_rgb(0_0_0_/_4%)] backdrop-blur">
                    <div className="mx-auto grid max-w-2xl grid-cols-3 gap-2">
                        <Button
                            size="lg"
                            className="h-14 w-full bg-status-success text-base font-semibold text-white hover:bg-status-success"
                            disabled={currentActioned || processing}
                            onClick={() => openAction('given')}
                            data-test="meds-round-given"
                        >
                            <CheckCircle2 className="mr-1 h-5 w-5" />
                            Given
                        </Button>
                        <Button
                            size="lg"
                            variant="outline"
                            className="h-14 w-full border-status-warning/30 bg-status-warning-bg text-base font-semibold text-status-warning hover:bg-status-warning-bg dark:border-status-warning/30 dark:bg-status-warning-bg dark:text-status-warning"
                            disabled={currentActioned || processing}
                            onClick={() => openAction('refused')}
                            data-test="meds-round-refused"
                        >
                            <XCircle className="mr-1 h-5 w-5" />
                            Refused
                        </Button>
                        <Button
                            size="lg"
                            variant="outline"
                            className="h-14 w-full border-status-warning/30 bg-status-warning-bg text-base font-semibold text-status-warning hover:bg-status-warning-bg dark:border-status-warning/30 dark:bg-status-warning-bg dark:text-status-warning"
                            disabled={currentActioned || processing}
                            onClick={() => openAction('held')}
                            data-test="meds-round-held"
                        >
                            <Pause className="mr-1 h-5 w-5" />
                            Held
                        </Button>
                    </div>
                    {currentActioned && (
                        <p className="mx-auto mt-2 max-w-2xl text-center text-xs text-muted-foreground">
                            {currentQueued
                                ? 'Saved on this device and waiting to send.'
                                : `Already recorded as ${statusLabel(current.administration?.status ?? null)}.`}
                        </p>
                    )}
                </div>
            )}

            {/* ── Confirm / reason dialog ─────────────────────────────── */}
            <Dialog
                open={!!pendingAction}
                onOpenChange={(open) => {
                    if (!open) {
                        setPendingAction(null);
                        setReason('');
                    }
                }}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            {pendingAction === 'given' && 'Give this dose now?'}
                            {pendingAction === 'refused' &&
                                'Why was this refused?'}
                            {pendingAction === 'held' &&
                                'Why is this being held?'}
                        </DialogTitle>
                    </DialogHeader>

                    {current && (
                        <div className="rounded-lg border bg-muted/40 p-3 text-sm">
                            <p className="font-medium">
                                {current.client_name} —{' '}
                                {current.medication_name}
                            </p>
                            {current.dose && (
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    {current.dose}
                                    {current.route ? ` · ${current.route}` : ''}
                                </p>
                            )}
                        </div>
                    )}

                    {pendingAction !== 'given' && (
                        <div className="space-y-1.5">
                            <label className="text-xs font-medium text-muted-foreground">
                                Reason (required)
                            </label>
                            <Textarea
                                autoFocus
                                value={reason}
                                onChange={(e) => setReason(e.target.value)}
                                placeholder={
                                    pendingAction === 'refused'
                                        ? 'e.g. Client refused, said not hungry'
                                        : 'e.g. GP asked to hold until review'
                                }
                                className="min-h-[90px]"
                            />
                        </div>
                    )}

                    {pendingAction === 'given' && current?.requires_witness && (
                        <p className="rounded-md border border-status-warning/30 bg-status-warning-bg px-3 py-2 text-xs text-status-warning dark:border-status-warning/30 dark:bg-status-warning-bg dark:text-status-warning">
                            This med usually needs a witness. If no one is with
                            you, hold it and tell your supervisor.
                        </p>
                    )}

                    {!online && (
                        <p className="rounded-md border border-status-info/30 bg-status-info-bg px-3 py-2 text-xs text-status-info dark:border-status-info/30 dark:bg-status-info-bg dark:text-status-info">
                            This will send when you&rsquo;re back online.
                        </p>
                    )}

                    {statusError && (
                        <p className="rounded-md border border-status-critical/30 bg-status-critical-bg px-3 py-2 text-xs text-status-critical dark:border-status-critical/30 dark:bg-status-critical-bg dark:text-status-critical">
                            {statusError}
                        </p>
                    )}

                    <DialogFooter className="gap-2 sm:gap-0">
                        <Button
                            variant="ghost"
                            onClick={() => {
                                setPendingAction(null);
                                setReason('');
                            }}
                            disabled={processing}
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={submitAction}
                            disabled={
                                processing ||
                                (pendingAction !== 'given' &&
                                    reason.trim().length === 0)
                            }
                            className={
                                pendingAction === 'given'
                                    ? 'bg-status-success text-white hover:bg-status-success'
                                    : ''
                            }
                        >
                            Confirm
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

/* -------------------------------------------------------------------------- */
/*  Empty state — no due meds fall inside this round's window                 */
/* -------------------------------------------------------------------------- */

function EmptyRound({ roundName }: { roundName: string }) {
    return (
        <div className="mt-12 flex flex-col items-center rounded-2xl border bg-card p-8 text-center">
            <div className="flex h-14 w-14 items-center justify-center rounded-full bg-muted">
                <Pill className="h-6 w-6 text-muted-foreground" />
            </div>
            <h2 className="mt-4 text-lg font-semibold">
                Nothing to give right now
            </h2>
            <p className="mt-1 max-w-sm text-sm text-muted-foreground">
                No scheduled medications fall inside the window for{' '}
                <span className="font-medium text-foreground">{roundName}</span>
                .
            </p>
            <Button asChild className="mt-6">
                <Link href="/my-day">
                    <Home className="mr-2 h-4 w-4" />
                    Back to My Day
                </Link>
            </Button>
        </div>
    );
}

/* -------------------------------------------------------------------------- */
/*  Completion view                                                           */
/* -------------------------------------------------------------------------- */

function RoundCompleteView({
    progress,
    items,
    onComplete,
    alreadyCompleted,
}: {
    progress: Progress;
    items: RoundItem[];
    onComplete: () => void;
    alreadyCompleted: boolean;
}) {
    const flaggedItems = items.filter(
        (it) => it.administration && it.administration.status !== 'given',
    );

    return (
        <div className="space-y-4">
            <div className="flex flex-col items-center rounded-2xl border bg-card p-6 text-center">
                <div className="flex h-14 w-14 items-center justify-center rounded-full bg-status-success-bg text-status-success dark:bg-status-success-bg dark:text-status-success">
                    <CheckCircle2 className="h-7 w-7" />
                </div>
                <h2 className="mt-3 text-lg font-semibold">Round complete</h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    Every dose in this round has been recorded.
                </p>

                <div className="mt-4 grid w-full grid-cols-3 gap-2 text-center text-sm">
                    <div className="rounded-lg bg-status-success-bg p-2">
                        <p className="text-lg font-bold text-status-success dark:text-status-success">
                            {progress.given}
                        </p>
                        <p className="text-xs text-muted-foreground">Given</p>
                    </div>
                    <div className="rounded-lg bg-status-warning-bg p-2">
                        <p className="text-lg font-bold text-status-warning dark:text-status-warning">
                            {progress.refused}
                        </p>
                        <p className="text-xs text-muted-foreground">Refused</p>
                    </div>
                    <div className="rounded-lg bg-status-warning-bg p-2">
                        <p className="text-lg font-bold text-status-warning dark:text-status-warning">
                            {progress.held}
                        </p>
                        <p className="text-xs text-muted-foreground">Held</p>
                    </div>
                </div>

                <div className="mt-5 flex w-full flex-col gap-2 sm:flex-row sm:justify-center">
                    {!alreadyCompleted && (
                        <Button onClick={onComplete} size="lg">
                            Finish round
                            <ArrowRight className="ml-2 h-4 w-4" />
                        </Button>
                    )}
                    <Button variant="outline" size="lg" asChild>
                        <Link href="/my-day">
                            <Home className="mr-2 h-4 w-4" />
                            Back to My Day
                        </Link>
                    </Button>
                </div>
            </div>

            {flaggedItems.length > 0 && (
                <div className="rounded-2xl border bg-card p-4">
                    <p className="text-sm font-semibold">
                        Doses that need follow-up
                    </p>
                    <ul className="mt-2 divide-y">
                        {flaggedItems.map((it) => (
                            <li
                                key={`${it.medication_id}-${it.scheduled_for}`}
                                className="flex items-center justify-between gap-3 py-2 text-sm"
                            >
                                <div className="min-w-0">
                                    <p className="truncate font-medium">
                                        {it.client_name} — {it.medication_name}
                                    </p>
                                    {it.administration?.reason && (
                                        <p className="truncate text-xs text-muted-foreground">
                                            {it.administration.reason}
                                        </p>
                                    )}
                                </div>
                                <Badge variant="outline" className="shrink-0">
                                    {statusLabel(
                                        it.administration?.status ?? null,
                                    )}
                                </Badge>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}
