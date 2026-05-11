import { router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    ChevronLeft,
    Info,
    Pill,
    Search,
    Shield,
    Zap,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

import DictateButton from '@/components/dictate-button';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { useOfflineQueueState } from '@/hooks/use-offline-queue';
import { submitOffline } from '@/lib/offline-queue';

/* -------------------------------------------------------------------------- */
/*  PR 13 — PRN (as-needed meds) quick flow                                   */
/* -------------------------------------------------------------------------- */
/*
 * Bottom-sheet, frontline-shaped entry surface for recording an as-needed
 * dose in a few taps. Launches from /meds/today so the worker stays in their
 * medication context instead of being routed back into the admin eMAR.
 *
 * Flow:
 *   1. pick med (prefiltered to configured PRN meds for assigned clients)
 *   2. pick reason (chips derived from the med's prn_reason template, plus
 *      free text when no template exists)
 *   3. confirm dose + optional note → Record PRN
 *
 * The submit hits /meds/today/prn which delegates to EnhancedMarService, so
 * safety checks, PRN over-limit handling and audit all run the same way they
 * do from the admin recording path. No second administration path introduced.
 */

export interface PrnMedication {
    id: number;
    client_id: number;
    client_name: string;
    name: string;
    dose: string | null;
    route: string | null;
    form: string | null;
    instructions: string | null;
    prn_reason: string | null;
    max_per_day: number | null;
    given_last_24h: number;
    remaining_today: number | null;
    near_limit: boolean;
    over_limit: boolean;
    is_controlled: boolean;
    requires_witness: boolean;
}

interface PrnSheetProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    medications: PrnMedication[];
    /**
     * When launched from a client-scoped surface (e.g. the consolidated client
     * care page), we already know the client and can skip the client/med
     * picker grouping. The sheet still reuses the same record step and the
     * same backend path, but the header and empty-state copy shift to reflect
     * the narrower context. Optional — /meds/today continues to pass a
     * cross-client list and no preselectedClient.
     */
    preselectedClient?: {
        id: number;
        name: string;
        /**
         * True when this worker does not currently have an active (clocked-in)
         * shift for this client. The PRN still records — we just surface the
         * context explicitly so the worker knows the administration will not
         * be linked to a shift, and future reporting can filter it.
         */
        hasActiveShift?: boolean;
    };
    /**
     * Optional submit endpoint override. Defaults to `/meds/today/prn` so
     * existing callers on the worker home keep working. The client-care
     * launch points at a client-scoped endpoint so null-shift context can be
     * handled server-side without guessing.
     */
    submitUrl?: string;
}

type Step = 'pick' | 'record';

/**
 * Turn a single prn_reason template into chip options. Supports comma,
 * pipe or newline separated reason templates, and always appends a
 * "Something else" option so the worker can type free text.
 */
function reasonChipsFor(med: PrnMedication): string[] {
    if (!med.prn_reason) return [];
    return med.prn_reason
        .split(/[\n,|]+/)
        .map((r) => r.trim())
        .filter((r) => r.length > 0)
        .slice(0, 6);
}

export default function PrnSheet({
    open,
    onOpenChange,
    medications,
    preselectedClient,
    submitUrl,
}: PrnSheetProps) {
    // When the sheet is launched from a single-client surface and there is
    // only one PRN available, jump straight to the record step. Otherwise
    // the worker still picks from the (possibly client-scoped) list.
    const initialStep: Step =
        preselectedClient && medications.length === 1 ? 'record' : 'pick';

    const [step, setStep] = useState<Step>(initialStep);
    const [selected, setSelected] = useState<PrnMedication | null>(
        preselectedClient && medications.length === 1 ? medications[0] : null,
    );
    const [search, setSearch] = useState('');
    const [reasonChoice, setReasonChoice] = useState<string | null>(null);
    const [reasonText, setReasonText] = useState('');
    const offlineQueue = useOfflineQueueState();
    const { online } = offlineQueue;
    const queuedPrnMedicationIds = useMemo(() => {
        return new Set(
            offlineQueue.pendingSubmissions
                .filter((submission) => submission.action === 'prn')
                .map((submission) =>
                    Number(submission.payload.client_medication_id),
                )
                .filter((id) => Number.isFinite(id)),
        );
    }, [offlineQueue.pendingSubmissions]);

    const form = useForm<{
        client_medication_id: number | null;
        reason: string;
        dose_given: string;
        notes: string;
    }>({
        client_medication_id:
            preselectedClient && medications.length === 1
                ? medications[0].id
                : null,
        reason: '',
        dose_given:
            preselectedClient && medications.length === 1
                ? (medications[0].dose ?? '')
                : '',
        notes: '',
    });

    // Reset everything on close so the next open is a clean sheet. Respect
    // the preselected client launch — if we opened straight into the record
    // step because there was only one PRN for this client, reset back to the
    // same starting state on next open rather than jumping to the picker.
    useEffect(() => {
        if (!open) {
            setStep(initialStep);
            setSelected(
                preselectedClient && medications.length === 1
                    ? medications[0]
                    : null,
            );
            setSearch('');
            setReasonChoice(null);
            setReasonText('');
            form.reset();
            form.clearErrors();
        }
        // We intentionally do not depend on `form` — Inertia's useForm object
        // identity changes every render and would loop us.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return medications;
        return medications.filter(
            (m) =>
                m.name.toLowerCase().includes(q) ||
                m.client_name.toLowerCase().includes(q),
        );
    }, [medications, search]);

    const groupedByClient = useMemo(() => {
        const groups = new Map<string, PrnMedication[]>();
        for (const med of filtered) {
            const list = groups.get(med.client_name) ?? [];
            list.push(med);
            groups.set(med.client_name, list);
        }
        return Array.from(groups.entries());
    }, [filtered]);

    const reasonChips = selected ? reasonChipsFor(selected) : [];

    const effectiveReason =
        reasonChoice === '__other__' || reasonChips.length === 0
            ? reasonText.trim()
            : (reasonChoice ?? '');

    function pickMed(med: PrnMedication) {
        setSelected(med);
        setReasonChoice(null);
        setReasonText('');
        form.setData('client_medication_id', med.id);
        form.setData('dose_given', med.dose ?? '');
        form.setData('notes', '');
        form.clearErrors();
        setStep('record');
    }

    function backToPick() {
        setStep('pick');
        setSelected(null);
        form.clearErrors();
    }

    function submit() {
        if (!selected) return;
        if (effectiveReason.length === 0) return;

        const url = submitUrl ?? '/meds/today/prn';
        const basePayload = {
            client_medication_id: form.data.client_medication_id,
            reason: effectiveReason,
            dose_given: form.data.dose_given,
            notes: form.data.notes,
        };

        // PR 26 — when the device is offline, queue the PRN locally and
        // close the sheet with a calm "saved on this device" toast instead
        // of letting Inertia fail the request. The queue replays through
        // this same endpoint on reconnect, and the server dedupes on
        // `client_request_uuid` so a lost ACK doesn't double-record.
        if (typeof navigator !== 'undefined' && !navigator.onLine) {
            void submitOffline({
                action: 'prn',
                url,
                payload: basePayload,
                queuedMessage:
                    'PRN saved on this device — we\u2019ll send it when you\u2019re back online.',
            }).then(() => {
                // Refresh the page props so `given_last_24h` etc. stay accurate
                // once the queued record lands. This is a no-op while offline.
                router.reload({
                    only: ['prnMedications'],
                    preserveScroll: true,
                });
                onOpenChange(false);
            });
            return;
        }

        form.transform((data) => ({
            ...data,
            reason: effectiveReason,
        }));

        form.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                // flash-toaster renders the success toast on the next prop
                // tick; we just need to close the sheet.
                onOpenChange(false);
            },
        });
    }

    const canSubmit =
        !!selected && effectiveReason.length > 0 && !form.processing;

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="bottom"
                className="flex max-h-[92dvh] flex-col gap-0 rounded-t-2xl p-0 sm:mx-auto sm:max-w-xl"
            >
                <SheetHeader className="border-b px-4 pt-5 pb-3 text-left">
                    <div className="flex items-center gap-2">
                        {step === 'record' && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                onClick={backToPick}
                                aria-label="Back to medication list"
                                className="-ml-2 h-11 w-11"
                            >
                                <ChevronLeft aria-hidden className="h-5 w-5" />
                            </Button>
                        )}
                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-status-warning-bg text-status-warning dark:bg-status-warning-bg dark:text-status-warning">
                            <Zap className="h-5 w-5" />
                        </div>
                        <div className="min-w-0">
                            <SheetTitle className="text-base">
                                Give as-needed med
                            </SheetTitle>
                            <SheetDescription className="text-xs">
                                {step === 'pick'
                                    ? preselectedClient
                                        ? `Pick the as-needed med you\u2019re giving to ${preselectedClient.name}.`
                                        : 'Pick the client and the as-needed med you\u2019re giving now.'
                                    : `${selected?.client_name} \u00b7 ${selected?.name}`}
                            </SheetDescription>
                        </div>
                    </div>
                </SheetHeader>

                {step === 'pick' && (
                    <PickStep
                        medications={medications}
                        groupedByClient={groupedByClient}
                        queuedMedicationIds={queuedPrnMedicationIds}
                        search={search}
                        onSearchChange={setSearch}
                        onPick={pickMed}
                        preselectedClient={preselectedClient}
                    />
                )}

                {step === 'record' && selected && (
                    <RecordStep
                        med={selected}
                        reasonChips={reasonChips}
                        reasonChoice={reasonChoice}
                        onReasonChoice={setReasonChoice}
                        reasonText={reasonText}
                        onReasonTextChange={setReasonText}
                        doseGiven={form.data.dose_given}
                        onDoseChange={(v) => form.setData('dose_given', v)}
                        notes={form.data.notes}
                        onNotesChange={(v) => form.setData('notes', v)}
                        onSubmit={submit}
                        submitting={form.processing}
                        canSubmit={canSubmit}
                        error={
                            form.errors.reason ||
                            form.errors.client_medication_id
                        }
                        nullShiftNotice={
                            preselectedClient &&
                            preselectedClient.hasActiveShift === false
                        }
                        offline={!online}
                    />
                )}
            </SheetContent>
        </Sheet>
    );
}

/* -------------------------------------------------------------------------- */
/*  Pick step                                                                 */
/* -------------------------------------------------------------------------- */

function PickStep({
    medications,
    groupedByClient,
    queuedMedicationIds,
    search,
    onSearchChange,
    onPick,
    preselectedClient,
}: {
    medications: PrnMedication[];
    groupedByClient: [string, PrnMedication[]][];
    queuedMedicationIds: Set<number>;
    search: string;
    onSearchChange: (s: string) => void;
    onPick: (m: PrnMedication) => void;
    preselectedClient?: { id: number; name: string };
}) {
    if (medications.length === 0) {
        return (
            <div className="flex flex-1 flex-col items-center justify-center gap-2 px-6 py-12 text-center">
                <Pill className="h-8 w-8 text-muted-foreground/60" />
                <p className="text-sm font-medium">No as-needed meds set up</p>
                <p className="max-w-xs text-xs text-muted-foreground">
                    {preselectedClient
                        ? `${preselectedClient.name} doesn\u2019t have any as-needed meds on their profile yet.`
                        : 'You can give as-needed meds from here once your clients have them set up on their profile.'}
                </p>
            </div>
        );
    }

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            {!preselectedClient && (
                <div className="border-b px-4 py-3">
                    <div className="relative">
                        <Search className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            type="search"
                            placeholder="Search client or med"
                            value={search}
                            onChange={(e) => onSearchChange(e.target.value)}
                            className="h-11 pl-9"
                            autoFocus={false}
                        />
                    </div>
                </div>
            )}

            <div className="min-h-0 flex-1 overflow-y-auto">
                {groupedByClient.length === 0 ? (
                    <div className="px-6 py-10 text-center text-sm text-muted-foreground">
                        No matches for &ldquo;{search}&rdquo;.
                    </div>
                ) : (
                    <ul className="divide-y">
                        {groupedByClient.map(([clientName, meds]) => (
                            <li key={clientName} className="py-1">
                                {!preselectedClient && (
                                    <p className="px-4 pt-2 pb-1 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                                        {clientName}
                                    </p>
                                )}
                                <ul>
                                    {meds.map((med) => (
                                        <li key={med.id}>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                onClick={() => onPick(med)}
                                                aria-label={`Record as-needed dose of ${med.name} for ${med.client_name}`}
                                                className="frontline-focus h-auto min-h-14 w-full justify-start gap-3 rounded-none px-4 py-3 text-left font-normal transition hover:bg-accent active:bg-accent/70"
                                            >
                                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-muted">
                                                    <Pill className="h-4 w-4 text-muted-foreground" />
                                                </div>
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex items-center gap-2">
                                                        <span className="truncate text-sm font-medium">
                                                            {med.name}
                                                        </span>
                                                        {med.is_controlled && (
                                                            <Badge
                                                                variant="outline"
                                                                className="shrink-0 border-primary text-[10px] tracking-wide text-primary uppercase dark:border-primary/30 dark:text-primary/70"
                                                            >
                                                                CD
                                                            </Badge>
                                                        )}
                                                        {queuedMedicationIds.has(
                                                            med.id,
                                                        ) && (
                                                            <Badge
                                                                variant="outline"
                                                                className="shrink-0 border-status-info/30 bg-status-info-bg text-[10px] tracking-wide text-status-info uppercase dark:border-status-info/30 dark:bg-status-info-bg dark:text-status-info"
                                                            >
                                                                Queued
                                                            </Badge>
                                                        )}
                                                    </div>
                                                    <div className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted-foreground">
                                                        {med.dose && (
                                                            <span>
                                                                {med.dose}
                                                            </span>
                                                        )}
                                                        {med.route && (
                                                            <>
                                                                <span
                                                                    aria-hidden
                                                                >
                                                                    &middot;
                                                                </span>
                                                                <span>
                                                                    {med.route}
                                                                </span>
                                                            </>
                                                        )}
                                                    </div>
                                                </div>
                                                <PrnLimitPill med={med} />
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}

function PrnLimitPill({ med }: { med: PrnMedication }) {
    if (med.max_per_day === null) {
        return null;
    }
    if (med.over_limit) {
        return (
            <span className="shrink-0 rounded-full border border-status-critical/30 bg-status-critical-bg px-2 py-0.5 text-[10px] font-medium text-status-critical dark:border-status-critical/30 dark:bg-status-critical-bg dark:text-status-critical">
                At limit
            </span>
        );
    }
    if (med.near_limit) {
        return (
            <span className="shrink-0 rounded-full border border-status-warning/30 bg-status-warning-bg px-2 py-0.5 text-[10px] font-medium text-status-warning dark:border-status-warning/30 dark:bg-status-warning-bg dark:text-status-warning">
                {med.given_last_24h}/{med.max_per_day} in 24h
            </span>
        );
    }
    return (
        <span className="shrink-0 text-[10px] text-muted-foreground">
            {med.given_last_24h}/{med.max_per_day} today
        </span>
    );
}

/* -------------------------------------------------------------------------- */
/*  Record step                                                               */
/* -------------------------------------------------------------------------- */

function RecordStep({
    med,
    reasonChips,
    reasonChoice,
    onReasonChoice,
    reasonText,
    onReasonTextChange,
    doseGiven,
    onDoseChange,
    notes,
    onNotesChange,
    onSubmit,
    submitting,
    canSubmit,
    error,
    nullShiftNotice,
    offline,
}: {
    med: PrnMedication;
    reasonChips: string[];
    reasonChoice: string | null;
    onReasonChoice: (r: string | null) => void;
    reasonText: string;
    onReasonTextChange: (t: string) => void;
    doseGiven: string;
    onDoseChange: (v: string) => void;
    notes: string;
    onNotesChange: (v: string) => void;
    onSubmit: () => void;
    submitting: boolean;
    canSubmit: boolean;
    error?: string;
    nullShiftNotice?: boolean;
    offline: boolean;
}) {
    const freeTextShown =
        reasonChips.length === 0 || reasonChoice === '__other__';

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-4 py-4">
                {/* Med summary */}
                <Card className="gap-0 rounded-lg bg-card/60 p-3 shadow-none">
                    <p className="text-xs text-muted-foreground">
                        {med.client_name}
                    </p>
                    <p className="mt-0.5 text-sm font-semibold">{med.name}</p>
                    <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted-foreground">
                        {med.dose && <span>{med.dose}</span>}
                        {med.route && (
                            <>
                                <span aria-hidden>&middot;</span>
                                <span>{med.route}</span>
                            </>
                        )}
                        {med.form && (
                            <>
                                <span aria-hidden>&middot;</span>
                                <span>{med.form}</span>
                            </>
                        )}
                    </div>
                    {med.instructions && (
                        <p className="mt-2 text-xs text-muted-foreground">
                            {med.instructions}
                        </p>
                    )}
                </Card>

                {/* Over-limit banner */}
                {med.over_limit && (
                    <div className="flex items-start gap-3 rounded-lg border border-status-critical/30 bg-status-critical-bg p-3 text-sm dark:border-status-critical/30">
                        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-status-critical dark:text-status-critical" />
                        <div className="min-w-0">
                            <p className="font-medium text-status-critical dark:text-status-critical">
                                Already given {med.given_last_24h} of{' '}
                                {med.max_per_day} in the last 24 hours
                            </p>
                            <p className="mt-0.5 text-xs text-status-critical dark:text-status-critical">
                                Don&rsquo;t give another dose without checking
                                with your supervisor first.
                            </p>
                        </div>
                    </div>
                )}

                {/* Null-shift notice — explicit, not silent. The admin path
                    still saves the record without a shift_id; we just tell the
                    worker that, so the reporting context isn't ambiguous. */}
                {nullShiftNotice && (
                    <div className="flex items-start gap-3 rounded-lg border border-status-info/30 bg-status-info-bg p-3 text-sm dark:border-status-info/30">
                        <Info className="mt-0.5 h-4 w-4 shrink-0 text-status-info dark:text-status-info" />
                        <div className="min-w-0">
                            <p className="font-medium text-status-info dark:text-status-info">
                                Not on shift for this client
                            </p>
                            <p className="mt-0.5 text-xs text-status-info dark:text-status-info">
                                The dose will still record, but it won&rsquo;t
                                be linked to a shift. Add a note below if
                                anything about the context matters.
                            </p>
                        </div>
                    </div>
                )}

                {/* Controlled / witness hint */}
                {med.requires_witness && (
                    <div className="bg-primary/10/70 flex items-start gap-3 rounded-lg border border-primary p-3 text-sm dark:border-primary/30 dark:bg-primary/20">
                        <Shield className="mt-0.5 h-4 w-4 shrink-0 text-primary dark:text-primary/70" />
                        <div className="min-w-0">
                            <p className="font-medium text-primary dark:text-primary/70">
                                Needs a witness
                            </p>
                            <p className="mt-0.5 text-xs text-primary dark:text-primary/70">
                                Record this dose on the full MAR with a witness,
                                so the register stays correct.
                            </p>
                        </div>
                    </div>
                )}

                {/* Why */}
                <div>
                    <label className="text-sm font-medium">
                        Why is it needed?
                    </label>
                    {reasonChips.length > 0 ? (
                        <div
                            role="radiogroup"
                            aria-label="Reason for as-needed dose"
                            className="mt-2 flex flex-wrap gap-2"
                        >
                            {reasonChips.map((chip) => {
                                const active = reasonChoice === chip;
                                return (
                                    <Button
                                        key={chip}
                                        type="button"
                                        role="radio"
                                        aria-checked={active}
                                        variant={active ? 'default' : 'outline'}
                                        onClick={() => onReasonChoice(chip)}
                                        className="frontline-focus min-h-11 rounded-full px-4 py-2 text-sm transition"
                                    >
                                        {chip}
                                    </Button>
                                );
                            })}
                            <Button
                                type="button"
                                role="radio"
                                aria-checked={reasonChoice === '__other__'}
                                variant={
                                    reasonChoice === '__other__'
                                        ? 'default'
                                        : 'outline'
                                }
                                onClick={() => onReasonChoice('__other__')}
                                className="frontline-focus min-h-11 rounded-full px-4 py-2 text-sm transition"
                            >
                                Something else
                            </Button>
                        </div>
                    ) : null}

                    {freeTextShown && (
                        <Textarea
                            value={reasonText}
                            onChange={(e) => onReasonTextChange(e.target.value)}
                            placeholder={
                                reasonChips.length === 0
                                    ? 'e.g. sore back, trouble sleeping, headache'
                                    : 'Describe the reason'
                            }
                            rows={2}
                            className="mt-2"
                        />
                    )}
                </div>

                {/* Dose */}
                <div>
                    <label htmlFor="prn-dose" className="text-sm font-medium">
                        Dose
                    </label>
                    <Input
                        id="prn-dose"
                        value={doseGiven}
                        onChange={(e) => onDoseChange(e.target.value)}
                        placeholder={med.dose ?? 'e.g. 1 tablet'}
                        className="mt-2 h-11"
                        inputMode="text"
                    />
                </div>

                {/* Note */}
                <div>
                    <div className="flex items-center justify-between">
                        <label
                            htmlFor="prn-note"
                            className="text-sm font-medium"
                        >
                            Add a note{' '}
                            <span className="text-xs text-muted-foreground">
                                (optional)
                            </span>
                        </label>
                        <DictateButton
                            value={notes}
                            onChange={onNotesChange}
                            fieldLabel="PRN note"
                        />
                    </div>
                    <Textarea
                        id="prn-note"
                        value={notes}
                        onChange={(e) => onNotesChange(e.target.value)}
                        placeholder="Anything the next worker should know"
                        rows={2}
                        className="mt-2"
                    />
                </div>

                {error && (
                    <p className="rounded-md border border-status-critical/30 bg-status-critical-bg px-3 py-2 text-sm text-status-critical dark:border-status-critical/30 dark:bg-status-critical-bg dark:text-status-critical">
                        {error}
                    </p>
                )}
            </div>

            {/* Sticky action bar */}
            <div className="border-t bg-background px-4 py-3 pb-[max(env(safe-area-inset-bottom),0.75rem)]">
                {offline && (
                    <p className="mb-2 rounded-md border border-status-info/30 bg-status-info-bg px-3 py-2 text-center text-xs text-status-info dark:border-status-info/30 dark:bg-status-info-bg dark:text-status-info">
                        Will send when you&rsquo;re back online.
                    </p>
                )}
                <Button
                    type="button"
                    size="lg"
                    className="h-12 w-full text-base font-semibold"
                    onClick={onSubmit}
                    disabled={!canSubmit}
                    data-test="meds-prn-submit"
                >
                    {submitting ? (
                        'Saving\u2026'
                    ) : (
                        <>
                            <CheckCircle2 className="mr-2 h-5 w-5" />
                            Save dose
                        </>
                    )}
                </Button>
                {!canSubmit && !submitting && (
                    <p className="mt-2 text-center text-xs text-muted-foreground">
                        Pick a reason to continue.
                    </p>
                )}
            </div>
        </div>
    );
}
