import { Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    FileText,
    ListChecks,
    Pill,
} from 'lucide-react';
import { useMemo, useState } from 'react';

import DictateButton from '@/components/dictate-button';
import HandoverWriteForm, {
    emptyHandoverWriteValue,
    type HandoverWriteValue,
} from '@/components/handover-write-form';
import ShiftTaskList, {
    type ShiftTaskListItem,
} from '@/components/shift-task-list';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { useIsMobile } from '@/hooks/use-mobile';

export type EndOfShiftBlocker = {
    key: string;
    label: string;
    detail: string;
    count: number;
    action_url: string | null;
    blocking: boolean;
};

export type EndOfShiftChecklistSession = {
    id: number;
    shift_id: number | null;
    client_name: string | null;
    break_minutes?: number;
    handover_submitted?: boolean;
    tasks?: ShiftTaskListItem[];
    end_of_shift_blockers?: EndOfShiftBlocker[];
};

function blockerIcon(key: string) {
    if (key === 'meds_unsigned') return Pill;
    if (key === 'handover_missing') return FileText;
    return ListChecks;
}

function ChecklistBody({
    session,
    notes,
    setNotes,
    breakMinutes,
    setBreakMinutes,
    overrideReason,
    setOverrideReason,
    handoverValue,
    setHandoverValue,
}: {
    session: EndOfShiftChecklistSession;
    notes: string;
    setNotes: (next: string) => void;
    breakMinutes: number;
    setBreakMinutes: (next: number) => void;
    overrideReason: string;
    setOverrideReason: (next: string) => void;
    handoverValue: HandoverWriteValue;
    setHandoverValue: (next: HandoverWriteValue) => void;
}) {
    const blockers = session.end_of_shift_blockers ?? [];
    const otherBlockers = blockers.filter(
        (blocker) => blocker.key !== 'handover_missing',
    );
    const hasHandoverBlocker = blockers.some(
        (blocker) => blocker.key === 'handover_missing',
    );
    const hasTaskBlocker = blockers.some(
        (blocker) => blocker.key === 'tasks_pending',
    );

    return (
        <div className="space-y-5">
            {blockers.length === 0 ? (
                <div className="rounded-lg border border-status-success/30 bg-status-success-bg p-3 text-sm text-status-success">
                    <div className="flex items-center gap-2 font-medium">
                        <CheckCircle2 className="h-4 w-4" />
                        Ready to end shift
                    </div>
                    <p className="mt-1">
                        Required tasks, handover, incidents, and medication
                        records are clear.
                    </p>
                </div>
            ) : (
                <div className="space-y-2">
                    {blockers.map((blocker) => {
                        const Icon = blockerIcon(blocker.key);
                        return (
                            <div
                                key={blocker.key}
                                className="rounded-lg border bg-card p-3"
                            >
                                <div className="flex items-start gap-3">
                                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
                                        <Icon className="h-4 w-4" />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="text-sm font-semibold">
                                            {blocker.label}
                                        </div>
                                        <p className="mt-0.5 text-sm text-muted-foreground">
                                            {blocker.detail}
                                        </p>
                                        {blocker.action_url &&
                                        !blocker.action_url.startsWith('#') ? (
                                            <Button
                                                asChild
                                                variant="link"
                                                className="mt-1 h-auto p-0 text-sm"
                                            >
                                                <Link href={blocker.action_url}>
                                                    Open related page
                                                </Link>
                                            </Button>
                                        ) : null}
                                    </div>
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}

            {hasTaskBlocker && (session.tasks?.length ?? 0) > 0 ? (
                <section id="shift-tasks" className="space-y-2">
                    <h3 className="text-sm font-semibold">Shift tasks</h3>
                    <ShiftTaskList tasks={session.tasks ?? []} maxVisible={6} />
                </section>
            ) : null}

            {hasHandoverBlocker && session.shift_id ? (
                <section id="handover" className="space-y-2">
                    <h3 className="text-sm font-semibold">Handover</h3>
                    <HandoverWriteForm
                        value={handoverValue}
                        onChange={setHandoverValue}
                    />
                </section>
            ) : null}

            <section className="grid gap-3 sm:grid-cols-2">
                <div className="space-y-1.5">
                    <label
                        htmlFor="end-shift-break-minutes"
                        className="text-sm font-medium"
                    >
                        Break minutes
                    </label>
                    <Input
                        id="end-shift-break-minutes"
                        type="number"
                        min={0}
                        max={240}
                        value={breakMinutes}
                        onChange={(event) =>
                            setBreakMinutes(
                                Math.max(
                                    0,
                                    Math.min(
                                        240,
                                        Math.floor(
                                            Number(event.target.value) || 0,
                                        ),
                                    ),
                                ),
                            )
                        }
                    />
                </div>

                {otherBlockers.length > 0 ? (
                    <div className="space-y-1.5">
                        <label
                            htmlFor="override-reason"
                            className="text-sm font-medium"
                        >
                            Reason to end anyway
                        </label>
                        <Input
                            id="override-reason"
                            value={overrideReason}
                            onChange={(event) =>
                                setOverrideReason(event.target.value)
                            }
                            placeholder="Brief reason"
                        />
                    </div>
                ) : null}
            </section>

            <section className="space-y-1.5">
                <div className="flex items-center justify-between gap-2">
                    <label
                        htmlFor="end-shift-notes"
                        className="text-sm font-medium"
                    >
                        Optional notes
                    </label>
                    <DictateButton
                        value={notes}
                        onChange={setNotes}
                        fieldLabel="Optional end of shift notes"
                    />
                </div>
                <Textarea
                    id="end-shift-notes"
                    rows={3}
                    value={notes}
                    onChange={(event) => setNotes(event.target.value)}
                    placeholder="Anything payroll or your manager should know."
                    className="text-base"
                />
            </section>

            {otherBlockers.length > 0 ? (
                <div className="rounded-lg border border-status-warning/30 bg-status-warning-bg p-3 text-sm text-status-warning">
                    <div className="flex items-center gap-2 font-medium">
                        <AlertTriangle className="h-4 w-4" />
                        Override will be audit logged
                    </div>
                    <p className="mt-1">
                        You can end the shift now if needed, but the reason and
                        outstanding items will be recorded.
                    </p>
                </div>
            ) : null}
        </div>
    );
}

export default function EndOfShiftChecklist({
    session,
    open,
    onOpenChange,
}: {
    session: EndOfShiftChecklistSession;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const isMobile = useIsMobile();
    const [submitting, setSubmitting] = useState(false);
    const [notes, setNotes] = useState('');
    const [breakMinutes, setBreakMinutes] = useState(
        session.break_minutes ?? 0,
    );
    const [overrideReason, setOverrideReason] = useState('');
    const [handoverValue, setHandoverValue] = useState<HandoverWriteValue>(
        emptyHandoverWriteValue,
    );

    const blockers = session.end_of_shift_blockers ?? [];
    const otherBlockers = useMemo(
        () => blockers.filter((blocker) => blocker.key !== 'handover_missing'),
        [blockers],
    );
    const hasHandoverBlocker = blockers.some(
        (blocker) => blocker.key === 'handover_missing',
    );
    const force = otherBlockers.length > 0;
    const canSubmit =
        !submitting && (!force || overrideReason.trim().length >= 4);

    const postClockOut = () => {
        router.post(
            '/attendance/clock-out',
            {
                session_id: session.id,
                break_minutes: breakMinutes,
                notes: notes.trim() || null,
                force,
                override_reason: force ? overrideReason.trim() : null,
            },
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
                onFinish: () => setSubmitting(false),
            },
        );
    };

    const submit = () => {
        if (submitting) return;
        setSubmitting(true);

        if (hasHandoverBlocker && session.shift_id) {
            router.post(
                '/attendance/handover',
                {
                    shift_id: session.shift_id,
                    meds_completed: handoverValue.meds_completed,
                    shift_rating: handoverValue.shift_rating,
                    handover_notes: handoverValue.handover_notes,
                    follow_up_needed: handoverValue.follow_up_needed,
                },
                {
                    preserveScroll: true,
                    onSuccess: postClockOut,
                    onError: () => setSubmitting(false),
                },
            );
            return;
        }

        postClockOut();
    };

    const body = (
        <ChecklistBody
            session={session}
            notes={notes}
            setNotes={setNotes}
            breakMinutes={breakMinutes}
            setBreakMinutes={setBreakMinutes}
            overrideReason={overrideReason}
            setOverrideReason={setOverrideReason}
            handoverValue={handoverValue}
            setHandoverValue={setHandoverValue}
        />
    );
    const title = `End shift${session.client_name ? ` for ${session.client_name}` : ''}`;
    const description =
        blockers.length === 0
            ? 'Confirm break minutes and wrap the shift.'
            : 'Clear the required items, or provide a reason to end anyway.';
    const label = submitting
        ? 'Ending...'
        : force
          ? 'End shift anyway'
          : hasHandoverBlocker
            ? 'Save handover and end shift'
            : 'End shift';

    if (isMobile) {
        return (
            <Sheet open={open} onOpenChange={onOpenChange}>
                <SheetContent
                    side="bottom"
                    className="max-h-[92vh] overflow-y-auto rounded-t-2xl"
                >
                    <SheetHeader className="pr-12">
                        <SheetTitle>{title}</SheetTitle>
                        <SheetDescription>{description}</SheetDescription>
                    </SheetHeader>
                    <div className="px-4">{body}</div>
                    <SheetFooter>
                        <Button
                            type="button"
                            onClick={submit}
                            disabled={!canSubmit}
                            variant={force ? 'destructive' : 'default'}
                        >
                            {label}
                        </Button>
                    </SheetFooter>
                </SheetContent>
            </Sheet>
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                {body}
                <DialogFooter>
                    <Button
                        type="button"
                        onClick={submit}
                        disabled={!canSubmit}
                        variant={force ? 'destructive' : 'default'}
                    >
                        {label}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
