import { Loader2, Plus } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

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
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDateTime } from '@/lib/datetime';

import { taskRequest, TaskRequestError } from '../lib/task-api';
import type { MyDayShift, MyDayShiftTask } from '../lib/types';
import { TaskStepDraft, type DraftStep } from './task-step-draft';

interface Draft {
    steps?: DraftStep[];
    request_id: string;
    label: string;
    person: string;
    when: 'anytime' | 'now' | 'time';
    scheduled_for: string;
}
interface SavedDraft {
    created_task_id?: number | null;
    content: Draft | null;
    version: number;
    saved_at: string | null;
}
interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    shift: MyDayShift;
    siteName: string;
    workerName: string;
    clients: { id: number; name: string }[];
    initialPerson: 'all' | number;
    initialTime?: number;
    onCreated: (task: MyDayShiftTask) => void;
}
const emptyDraft = (person: string, at?: number): Draft => ({
    request_id: crypto.randomUUID(),
    label: '',
    person,
    when: at === undefined ? 'anytime' : 'time',
    scheduled_for: at === undefined ? '' : new Date(at).toISOString(),
    steps: [],
});

export function QuickAddTask(p: Props) {
    const [form, setForm] = useState<Draft>(() => emptyDraft(''));
    const [loaded, setLoaded] = useState(false);
    const [loading, setLoading] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [fields, setFields] = useState<Record<string, string>>({});
    const [draftState, setDraftState] = useState<
        'saved' | 'saving' | 'failed' | 'empty'
    >('empty');
    const [uncertain, setUncertain] = useState(false);
    const [alreadyCreated, setAlreadyCreated] = useState(false);
    const [needsResume, setNeedsResume] = useState(false);
    const [draftConflict, setDraftConflict] = useState(false);
    const version = useRef(0);
    const queue = useRef<Promise<unknown>>(Promise.resolve());
    const latest = useRef(form);
    const lastSaved = useRef('');
    const endpoint = `/my-day/shifts/${p.shift.id}/task-draft`;
    latest.current = form;

    const load = useCallback(async () => {
        setLoading(true);
        setLoaded(false);
        setError('');
        try {
            const saved = await taskRequest<SavedDraft>(endpoint);
            const next =
                saved.content ??
                emptyDraft(
                    p.initialPerson === 'all' ? '' : String(p.initialPerson),
                    p.initialTime,
                );
            version.current = saved.version;
            lastSaved.current = saved.content
                ? JSON.stringify(saved.content)
                : '';
            setForm(next);
            setDraftState(saved.content ? 'saved' : 'empty');
            setLoaded(true);
            setUncertain(false);
            setAlreadyCreated(!!saved.created_task_id);
            setNeedsResume(!!saved.content && !saved.created_task_id);
            setDraftConflict(false);
        } catch (cause) {
            setError(
                cause instanceof Error
                    ? cause.message
                    : 'Could not load your draft. Try again.',
            );
        } finally {
            setLoading(false);
        }
    }, [endpoint, p.initialPerson, p.initialTime]);

    useEffect(() => {
        if (p.open) void load();
    }, [p.open, load]);

    const saveDraft = useCallback(
        (content: Draft | null) => {
            const operation = queue.current
                .catch(() => undefined)
                .then(async () => {
                    if (
                        content &&
                        JSON.stringify(content) === lastSaved.current
                    )
                        return;
                    setDraftState('saving');
                    try {
                        const saved = await taskRequest<SavedDraft>(
                            endpoint,
                            'PUT',
                            { content, expected_version: version.current },
                        );
                        version.current = saved.version;
                        lastSaved.current = content
                            ? JSON.stringify(content)
                            : '';
                        setDraftState(content ? 'saved' : 'empty');
                        if (saved.created_task_id) setAlreadyCreated(true);
                    } catch (cause) {
                        setDraftState('failed');
                        if (
                            cause instanceof TaskRequestError &&
                            cause.fields.draft
                        )
                            setDraftConflict(true);
                        throw cause;
                    }
                });
            queue.current = operation;
            return operation;
        },
        [endpoint],
    );

    useEffect(() => {
        if (
            !p.open ||
            !loaded ||
            busy ||
            uncertain ||
            draftConflict ||
            needsResume ||
            alreadyCreated ||
            (!form.label.trim() && !form.steps?.length)
        )
            return;
        const timer = window.setTimeout(() => {
            void saveDraft(form).catch(() => undefined);
        }, 700);
        return () => window.clearTimeout(timer);
    }, [
        form,
        loaded,
        busy,
        uncertain,
        draftConflict,
        needsResume,
        alreadyCreated,
        p.open,
        saveDraft,
    ]);

    useEffect(() => {
        if (!p.open || !loaded) return;
        const warn = (event: BeforeUnloadEvent) => {
            if (
                busy ||
                uncertain ||
                ((latest.current.label || latest.current.steps?.length) &&
                    JSON.stringify(latest.current) !== lastSaved.current)
            ) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [p.open, loaded, busy, uncertain]);

    const close = async (discard: boolean) => {
        if (busy || uncertain || draftConflict) return;
        setBusy(true);
        setError('');
        try {
            if (loaded)
                await saveDraft(
                    discard || (!form.label.trim() && !form.steps?.length)
                        ? null
                        : form,
                );
            p.onOpenChange(false);
        } catch (cause) {
            setError(
                cause instanceof Error
                    ? cause.message
                    : 'Could not save your draft. Please retry.',
            );
        } finally {
            setBusy(false);
        }
    };

    const resolveDraftConflict = async (keepTheseAnswers: boolean) => {
        if (busy) return;
        if (!keepTheseAnswers) {
            await load();
            return;
        }
        setBusy(true);
        setError('');
        try {
            const saved = await taskRequest<SavedDraft>(endpoint);
            version.current = saved.version;
            lastSaved.current = '';
            await saveDraft(form);
            setDraftConflict(false);
        } catch (cause) {
            setError(
                cause instanceof Error
                    ? cause.message
                    : 'Could not resolve the draft. Your answers are still here.',
            );
        } finally {
            setBusy(false);
        }
    };

    const submit = async () => {
        if (busy || !loaded || alreadyCreated || needsResume || draftConflict)
            return;
        const invalid: Record<string, string> = {};
        if (!form.label.trim()) invalid.label = 'Enter what needs to happen.';
        if (form.steps?.some((step) => !step.label.trim()))
            invalid.steps = 'Give each step a name, or remove the empty step.';
        if (!form.person)
            invalid.person = 'Choose a person or a whole-site task.';
        if (form.when === 'time' && !form.scheduled_for)
            invalid.scheduled_for = 'Choose a time during this shift.';
        setFields(invalid);
        if (Object.keys(invalid).length) {
            setError(Object.values(invalid)[0]);
            return;
        }
        setBusy(true);
        setError('');
        try {
            // Store the same command key before submitting so a reload can recover a lost response.
            await saveDraft(form);
            const { task } = await taskRequest<{ task: MyDayShiftTask }>(
                `/my-day/shifts/${p.shift.id}/tasks`,
                'POST',
                {
                    request_id: form.request_id,
                    label: form.label.trim(),
                    steps: (form.steps ?? []).map((step) => ({
                        ...step,
                        label: step.label.trim(),
                    })),
                    task_scope: form.person === 'site' ? 'site' : 'client',
                    ...(form.person === 'site'
                        ? {}
                        : { client_id: Number(form.person) }),
                    when: form.when,
                    ...(form.when === 'time'
                        ? { scheduled_for: form.scheduled_for }
                        : {}),
                },
            );
            setUncertain(false);
            await saveDraft(null).catch(() =>
                toast.info(
                    'Task saved. The saved form will be cleared when you next open Add task.',
                ),
            );
            p.onCreated(task);
            p.onOpenChange(false);
            toast.success('Task added to your shift.');
        } catch (cause) {
            setError(
                cause instanceof Error
                    ? cause.message
                    : 'Task could not be saved. Your form is still here.',
            );
            if (cause instanceof TaskRequestError) {
                setFields(cause.fields);
                setUncertain(cause.uncertain);
            }
        } finally {
            setBusy(false);
        }
    };

    const slots: string[] = [];
    const end = Date.parse(p.shift.ends_at);
    for (
        let at = Math.ceil(Date.parse(p.shift.starts_at) / 300_000) * 300_000;
        at <= end && slots.length < 600;
        at += 300_000
    )
        slots.push(new Date(at).toISOString());
    // Preserve an exact selected shift start, even when it is between five-minute slots.
    if (form.scheduled_for && !slots.includes(form.scheduled_for)) {
        slots.push(form.scheduled_for);
        slots.sort((a, b) => Date.parse(a) - Date.parse(b));
    }
    const change = <K extends keyof Draft>(key: K, value: Draft[K]) => {
        setForm((current) => ({ ...current, [key]: value }));
        setFields({});
    };
    return (
        <Dialog
            open={p.open}
            onOpenChange={(open) => {
                if (!open) void close(false);
            }}
        >
            <DialogContent
                className="max-h-[90vh] overflow-y-auto"
                style={{ width: 'min(92vw, 720px)', maxWidth: 'none' }}
                onEscapeKeyDown={(event) => {
                    if (busy || uncertain) event.preventDefault();
                }}
                onPointerDownOutside={(event) => {
                    if (busy || uncertain) event.preventDefault();
                }}
            >
                <DialogHeader>
                    <DialogTitle>Add a task</DialogTitle>
                    <DialogDescription>
                        Something that needs doing during this shift. To record
                        care already provided, use Daily note, Meal or
                        Observation.
                    </DialogDescription>
                </DialogHeader>
                <p className="rounded-lg bg-muted px-4 py-3 text-sm">
                    <strong>{p.siteName || 'Current shift'}</strong> ·{' '}
                    {p.workerName}
                    <br />
                    <span className="text-muted-foreground">
                        {formatDateTime(p.shift.starts_at)} –{' '}
                        {formatDateTime(p.shift.ends_at)}
                    </span>
                </p>
                {loading ? (
                    <p className="flex items-center gap-2 py-5">
                        <Loader2 className="size-4 animate-spin" />
                        Checking for a saved draft…
                    </p>
                ) : alreadyCreated ? (
                    <p
                        role="status"
                        className="rounded-lg bg-status-success-bg p-4 text-sm text-status-success"
                    >
                        This task was already added to your shift. Close this
                        form to clear the saved draft, then use Add task for new
                        work.
                    </p>
                ) : needsResume ? (
                    <div className="space-y-3 rounded-lg bg-muted p-4">
                        <h3 className="font-semibold">
                            You have a saved draft
                        </h3>
                        <p className="text-sm">
                            {form.label || 'Untitled task'}
                        </p>
                        <Button onClick={() => setNeedsResume(false)}>
                            Resume draft
                        </Button>
                    </div>
                ) : loaded ? (
                    <form
                        id="quick-add-task-form"
                        className="space-y-5"
                        onSubmit={(event) => {
                            event.preventDefault();
                            void submit();
                        }}
                    >
                        <fieldset
                            disabled={busy || uncertain}
                            className="space-y-5"
                        >
                            <div className="space-y-2">
                                <Label htmlFor="day-task-label">
                                    What needs to happen?
                                </Label>
                                <Input
                                    id="day-task-label"
                                    autoFocus
                                    maxLength={180}
                                    value={form.label}
                                    onChange={(event) =>
                                        change('label', event.target.value)
                                    }
                                    placeholder="For example, prepare the activity bag"
                                    aria-invalid={!!fields.label}
                                    aria-describedby={
                                        fields.label
                                            ? 'day-task-error'
                                            : undefined
                                    }
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="day-task-person">
                                    Who is it for?
                                </Label>
                                <Select
                                    value={form.person}
                                    onValueChange={(value) =>
                                        change('person', value)
                                    }
                                >
                                    <SelectTrigger
                                        id="day-task-person"
                                        className="w-full"
                                        aria-invalid={
                                            !!fields.person ||
                                            !!fields.client_id
                                        }
                                    >
                                        <SelectValue placeholder="Choose a person or whole site" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {p.clients.map((client) => (
                                            <SelectItem
                                                key={client.id}
                                                value={String(client.id)}
                                            >
                                                {client.name}
                                            </SelectItem>
                                        ))}
                                        <SelectItem value="site">
                                            Whole site · not for one person
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="day-task-when">
                                    When does it need doing?
                                </Label>
                                <Select
                                    value={form.when}
                                    onValueChange={(value) =>
                                        change('when', value as Draft['when'])
                                    }
                                >
                                    <SelectTrigger
                                        id="day-task-when"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="anytime">
                                            Any time today
                                        </SelectItem>
                                        <SelectItem value="now">Now</SelectItem>
                                        <SelectItem value="time">
                                            At a set time
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            {form.when === 'time' && (
                                <div className="space-y-2">
                                    <Label htmlFor="day-task-time">
                                        Time during this shift
                                    </Label>
                                    <Select
                                        value={form.scheduled_for}
                                        onValueChange={(value) =>
                                            change('scheduled_for', value)
                                        }
                                    >
                                        <SelectTrigger
                                            id="day-task-time"
                                            className="w-full"
                                        >
                                            <SelectValue placeholder="Choose a time" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {slots.map((slot) => (
                                                <SelectItem
                                                    key={slot}
                                                    value={slot}
                                                >
                                                    {formatDateTime(slot)}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <p className="text-xs text-muted-foreground">
                                        New Zealand time, in five-minute steps.
                                    </p>
                                </div>
                            )}
                            <TaskStepDraft
                                steps={form.steps ?? []}
                                onChange={(steps) => change('steps', steps)}
                            />
                        </fieldset>
                        <p className="rounded-lg bg-accent p-3 text-sm text-accent-foreground">
                            This will appear in{' '}
                            <strong>
                                {form.when === 'anytime'
                                    ? 'Any time today'
                                    : form.when === 'now'
                                      ? 'Due now'
                                      : 'your shift’s timed work'}
                            </strong>
                            , assigned to you.
                        </p>
                    </form>
                ) : null}
                {error && (
                    <p
                        id="day-task-error"
                        role="alert"
                        className="rounded-lg bg-status-critical-bg p-3 text-sm text-status-critical"
                    >
                        {error}
                    </p>
                )}
                {draftConflict && (
                    <div
                        role="alert"
                        className="space-y-3 rounded-lg border p-4"
                    >
                        <h3 className="font-semibold">
                            Choose which draft to keep
                        </h3>
                        <p className="text-sm">
                            A different draft was saved in another window. Your
                            answers are still here. Loading the saved draft
                            replaces these answers; saving these answers
                            replaces the other draft.
                        </p>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                variant="outline"
                                disabled={busy || loading}
                                onClick={() => void resolveDraftConflict(false)}
                            >
                                Load saved draft
                            </Button>
                            <Button
                                disabled={busy || loading}
                                onClick={() => void resolveDraftConflict(true)}
                            >
                                Save these answers instead
                            </Button>
                        </div>
                    </div>
                )}
                {loaded && (
                    <p role="status" className="text-xs text-muted-foreground">
                        {draftState === 'saving'
                            ? 'Saving draft…'
                            : draftState === 'saved'
                              ? 'Draft saved privately to your account.'
                              : draftState === 'failed'
                                ? 'Draft is not saved. Keep this window open and retry.'
                                : 'Your draft will save as you type.'}
                    </p>
                )}
                <DialogFooter className="sticky bottom-0 bg-background py-2">
                    {!uncertain && loaded && !draftConflict && (
                        <Button
                            variant="ghost"
                            disabled={busy}
                            onClick={() => void close(true)}
                        >
                            Discard draft
                        </Button>
                    )}
                    {!uncertain && !draftConflict && (
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={() => void close(alreadyCreated)}
                        >
                            {alreadyCreated
                                ? 'Close saved task'
                                : loaded
                                  ? 'Keep draft & close'
                                  : 'Close'}
                        </Button>
                    )}
                    {loaded &&
                    !alreadyCreated &&
                    !needsResume &&
                    !draftConflict ? (
                        <Button
                            type="submit"
                            form="quick-add-task-form"
                            disabled={busy}
                        >
                            {busy ? (
                                <Loader2 className="size-4 animate-spin" />
                            ) : (
                                <Plus className="size-4" />
                            )}
                            {busy
                                ? 'Saving…'
                                : uncertain
                                  ? 'Retry same task'
                                  : 'Add task'}
                        </Button>
                    ) : !loaded ? (
                        <Button onClick={() => void load()} disabled={loading}>
                            Try again
                        </Button>
                    ) : null}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
