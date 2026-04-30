import { Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    FileText,
    ListChecks,
    Pill,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

import DictateButton from '@/components/dictate-button';
import DraftResumePrompt from '@/components/draft-resume-prompt';
import DraftSavedIndicator from '@/components/draft-saved-indicator';
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
import { useFormAutosave } from '@/hooks/use-form-autosave';
import { useIsMobile } from '@/hooks/use-mobile';
import { useMyDayLabels } from '@/hooks/use-my-day-labels';

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

const BREAK_CHIPS: ReadonlyArray<number> = [0, 15, 30, 45, 60];

function blockerIcon(key: string) {
    if (key === 'meds_unsigned') return Pill;
    if (key === 'handover_missing') return FileText;
    return ListChecks;
}

function ChecklistBody({
    session,
    blockers,
    tasks,
    onTasksChange,
    notes,
    setNotes,
    breakMinutes,
    setBreakMinutes,
    overrideReason,
    setOverrideReason,
    handoverValue,
    setHandoverValue,
    handoverSavedAt,
    resumeAvailable,
    onResumeHandoverDraft,
    onDiscardHandoverDraft,
}: {
    session: EndOfShiftChecklistSession;
    blockers: EndOfShiftBlocker[];
    tasks: ShiftTaskListItem[];
    onTasksChange: (next: ShiftTaskListItem[]) => void;
    notes: string;
    setNotes: (next: string) => void;
    breakMinutes: number;
    setBreakMinutes: (next: number) => void;
    overrideReason: string;
    setOverrideReason: (next: string) => void;
    handoverValue: HandoverWriteValue;
    setHandoverValue: (next: HandoverWriteValue) => void;
    handoverSavedAt: number | null;
    resumeAvailable: { savedAt: number } | null;
    onResumeHandoverDraft: () => void;
    onDiscardHandoverDraft: () => void;
}) {
    const t = useMyDayLabels();
    const otherBlockers = blockers.filter(
        (blocker) => blocker.key !== 'handover_missing',
    );
    const hasHandoverBlocker = blockers.some(
        (blocker) => blocker.key === 'handover_missing',
    );
    const incompleteTaskCount = tasks.filter(
        (task) => !task.is_completed,
    ).length;
    const showTaskList = (tasks?.length ?? 0) > 0;

    return (
        <div className="space-y-5">
            {blockers.length === 0 ? (
                <div className="rounded-lg border border-status-success/30 bg-status-success-bg p-3 text-sm text-status-success">
                    <div className="flex items-center gap-2 font-medium">
                        <CheckCircle2 className="h-4 w-4" />
                        {t('ready_to_end_shift')}
                    </div>
                    <p className="mt-1">{t('ready_subtitle')}</p>
                </div>
            ) : (
                <div className="space-y-2">
                    {blockers.map((blocker) => {
                        const Icon = blockerIcon(blocker.key);
                        return (
                            // eslint-disable-next-line no-restricted-syntax -- Blocker rows use custom icon layout inside the dialog.
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
                                                    {t('open_related_page')}
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

            {showTaskList ? (
                <section id="shift-tasks" className="space-y-2">
                    <div className="flex items-center justify-between gap-2">
                        <h3 className="text-sm font-semibold">
                            {t('shift_tasks')}
                        </h3>
                        <span
                            className={
                                incompleteTaskCount === 0
                                    ? 'text-xs font-medium text-status-success'
                                    : 'text-xs font-medium text-muted-foreground'
                            }
                        >
                            {incompleteTaskCount === 0
                                ? t('all_complete')
                                : t('still_to_do', {
                                      open: incompleteTaskCount,
                                      total: tasks.length,
                                  })}
                        </span>
                    </div>
                    <ShiftTaskList
                        tasks={tasks}
                        onTasksChange={onTasksChange}
                        maxVisible={6}
                        submitOnToggle={false}
                    />
                </section>
            ) : null}

            {hasHandoverBlocker && session.shift_id ? (
                <section id="handover" className="space-y-2">
                    <h3 className="text-sm font-semibold">{t('handover')}</h3>
                    {resumeAvailable ? (
                        <DraftResumePrompt
                            savedAt={resumeAvailable.savedAt}
                            onResume={onResumeHandoverDraft}
                            onDiscard={onDiscardHandoverDraft}
                            title="Resume your unfinished handover?"
                            description="We kept your handover answers from earlier on this device."
                        />
                    ) : null}
                    <HandoverWriteForm
                        value={handoverValue}
                        onChange={setHandoverValue}
                    />
                    <DraftSavedIndicator savedAt={handoverSavedAt} />
                </section>
            ) : null}

            <section className="grid gap-3 sm:grid-cols-2">
                <div className="space-y-1.5">
                    <label
                        htmlFor="end-shift-break-minutes"
                        className="text-sm font-medium"
                    >
                        {t('break_minutes')}
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
                    <div className="flex flex-wrap gap-2 pt-1">
                        {BREAK_CHIPS.map((minutes) => (
                            <Button
                                key={minutes}
                                type="button"
                                variant={
                                    breakMinutes === minutes
                                        ? 'default'
                                        : 'outline'
                                }
                                size="sm"
                                onClick={() => setBreakMinutes(minutes)}
                            >
                                {minutes}m
                            </Button>
                        ))}
                    </div>
                </div>

                {otherBlockers.length > 0 ? (
                    <div className="space-y-1.5">
                        <label
                            htmlFor="override-reason"
                            className="text-sm font-medium"
                        >
                            {t('reason_to_end_anyway')}
                        </label>
                        <Input
                            id="override-reason"
                            data-test="end-shift-override-reason"
                            value={overrideReason}
                            onChange={(event) =>
                                setOverrideReason(event.target.value)
                            }
                            placeholder={t('brief_reason')}
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
                        {t('optional_notes')}
                    </label>
                    <DictateButton
                        value={notes}
                        onChange={setNotes}
                        fieldLabel={t('optional_notes')}
                    />
                </div>
                <Textarea
                    id="end-shift-notes"
                    rows={3}
                    value={notes}
                    onChange={(event) => setNotes(event.target.value)}
                    placeholder={t('optional_notes_placeholder')}
                    className="text-base"
                />
            </section>

            {otherBlockers.length > 0 ? (
                <div className="rounded-lg border border-status-warning/30 bg-status-warning-bg p-3 text-sm text-status-warning">
                    <div className="flex items-center gap-2 font-medium">
                        <AlertTriangle className="h-4 w-4" />
                        {t('override_audit_title')}
                    </div>
                    <p className="mt-1">{t('override_audit_subtitle')}</p>
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
    const page = usePage().props as { auth?: { user?: { id?: number } } };
    const userId = page.auth?.user?.id ?? 0;
    const t = useMyDayLabels();
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
    const [resumeAvailable, setResumeAvailable] = useState<{
        savedAt: number;
    } | null>(null);
    const [tasks, setTasks] = useState<ShiftTaskListItem[]>(
        session.tasks ?? [],
    );

    useEffect(() => {
        if (!open) {
            return;
        }

        setSubmitting(false);
        setNotes('');
        setOverrideReason('');
        setBreakMinutes(session.break_minutes ?? 0);
    }, [open, session.id, session.break_minutes]);

    // Resync local tasks if the session payload changes (e.g. live refresh).
    useEffect(() => {
        setTasks(session.tasks ?? []);
    }, [session.tasks]);

    // Drop the tasks_pending blocker once the worker has ticked off every
    // task in the embedded list — no more "End shift anyway" + override
    // reason just because the original payload still says X tasks pending.
    const blockers = useMemo(() => {
        const incompleteCount = tasks.filter(
            (task) => !task.is_completed,
        ).length;
        return (session.end_of_shift_blockers ?? [])
            .map((blocker) =>
                blocker.key === 'tasks_pending'
                    ? { ...blocker, count: incompleteCount }
                    : blocker,
            )
            .filter(
                (blocker) =>
                    blocker.key !== 'tasks_pending' || incompleteCount > 0,
            );
    }, [session.end_of_shift_blockers, tasks]);
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
    const handoverDraftKey = session.shift_id
        ? `oblivion:clockout-handover:v1:u${userId}:s${session.shift_id}`
        : null;
    const handoverEligibleForSave =
        open && hasHandoverBlocker && !!session.shift_id;
    const {
        savedAt: handoverSavedAt,
        load: loadHandoverDraft,
        clear: clearHandoverDraft,
    } = useFormAutosave<Record<string, unknown>>(
        handoverValue as unknown as Record<string, unknown>,
        { shift_id: session.shift_id },
        {
            key: handoverDraftKey ?? 'oblivion:clockout-handover:v1:disabled',
            enabled: !!handoverDraftKey && handoverEligibleForSave,
        },
    );

    useEffect(() => {
        if (!open) {
            setResumeAvailable(null);
            setHandoverValue(emptyHandoverWriteValue);
            return;
        }

        if (!handoverEligibleForSave) {
            setResumeAvailable(null);
            setHandoverValue(emptyHandoverWriteValue);
            return;
        }

        const existing = loadHandoverDraft();
        const draftData = existing?.data as
            | Partial<HandoverWriteValue>
            | undefined;
        const hasDraft =
            !!draftData &&
            (!!draftData.handover_notes?.trim() ||
                (draftData.shift_rating !== null &&
                    draftData.shift_rating !== undefined) ||
                draftData.follow_up_needed === true ||
                draftData.meds_completed === false);

        if (hasDraft && draftData && existing) {
            setHandoverValue({
                ...emptyHandoverWriteValue,
                ...draftData,
            });
            setResumeAvailable({ savedAt: existing.savedAt });
            return;
        }

        setHandoverValue(emptyHandoverWriteValue);
        setResumeAvailable(null);
    }, [handoverEligibleForSave, loadHandoverDraft, open]);

    const postClockOut = () => {
        router.post(
            '/attendance/clock-out',
            {
                session_id: session.id,
                break_minutes: breakMinutes,
                notes: notes.trim() || null,
                force,
                override_reason: force ? overrideReason.trim() : null,
                task_updates: tasks.map((task) => ({
                    id: task.id,
                    is_completed: task.is_completed,
                })),
                handover:
                    hasHandoverBlocker && session.shift_id
                        ? {
                              meds_completed: handoverValue.meds_completed,
                              shift_rating: handoverValue.shift_rating,
                              handover_notes: handoverValue.handover_notes,
                              follow_up_needed: handoverValue.follow_up_needed,
                          }
                        : null,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    clearHandoverDraft();
                    onOpenChange(false);
                },
                onFinish: () => setSubmitting(false),
            },
        );
    };

    const submit = () => {
        if (submitting) return;
        setSubmitting(true);
        postClockOut();
    };

    const body = (
        <ChecklistBody
            session={session}
            blockers={blockers}
            tasks={tasks}
            onTasksChange={setTasks}
            notes={notes}
            setNotes={setNotes}
            breakMinutes={breakMinutes}
            setBreakMinutes={setBreakMinutes}
            overrideReason={overrideReason}
            setOverrideReason={setOverrideReason}
            handoverValue={handoverValue}
            setHandoverValue={setHandoverValue}
            handoverSavedAt={handoverSavedAt}
            resumeAvailable={resumeAvailable}
            onResumeHandoverDraft={() => setResumeAvailable(null)}
            onDiscardHandoverDraft={() => {
                clearHandoverDraft();
                setHandoverValue(emptyHandoverWriteValue);
                setResumeAvailable(null);
            }}
        />
    );
    const title = session.client_name
        ? t('end_shift_for', { name: session.client_name })
        : t('end_shift');
    const description =
        blockers.length === 0
            ? t('confirm_break_minutes')
            : t('clear_required_or_reason');
    const label = submitting
        ? t('ending')
        : force
          ? t('end_shift_anyway')
          : hasHandoverBlocker
            ? t('save_handover_and_end')
            : t('end_shift');

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
                            data-test="end-shift-submit"
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
                        data-test="end-shift-submit"
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
