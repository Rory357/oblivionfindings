/* Daily / communication note wizard — built on the shared WizardShell chrome
 * (the Add Client modal contract): 248px stepper rail, "Step x of y" header,
 * 3px progress strip and muted footer band. The flow, fields, validation and
 * submit payload are unchanged from the bespoke dialog it replaces. */
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
    WizardShell,
    WizardStepPane,
    type WizardStep,
} from '@/components/wizard/shell';
import { cn } from '@/lib/utils';
import {
    defaultDailyNoteValues,
    useDailyNoteForm,
    type DailyNoteFormValues,
} from '@/pages/operations/clients/hooks/use-daily-note-form';
import { router } from '@inertiajs/react';
import {
    AlertTriangle,
    Check,
    ChevronLeft,
    ChevronRight,
    ClipboardCheck,
    FileCheck2,
    FileText,
    LayoutGrid,
    MessageSquare,
    Save,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import {
    NOTE_CATEGORIES,
    NoteCategoryPicker,
    type NoteCategoryKey,
} from './_note-category-picker';

type DailyNoteWizardProps = {
    clientId: number;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    mode?: 'daily' | 'communication';
    shiftOptions?: Array<{ id: number; label: string }>;
    goalOptions?: Array<{ id?: number | string; label: string }>;
    onSubmitted?: () => void;
};

const STEPS: WizardStep[] = [
    {
        key: 'category',
        label: 'Category',
        blurb: 'What kind of note',
        icon: LayoutGrid,
    },
    {
        key: 'details',
        label: 'Details',
        blurb: 'What happened & when',
        icon: FileText,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check, flag & save',
        icon: ClipboardCheck,
    },
];

const splitTags = (value: string) =>
    value
        .split(',')
        .map((item) => item.trim())
        .filter(Boolean);

export function DailyNoteWizard({
    clientId,
    open,
    onOpenChange,
    mode = 'daily',
    shiftOptions = [],
    goalOptions = [],
    onSubmitted,
}: DailyNoteWizardProps) {
    const form = useDailyNoteForm(
        mode === 'communication' ? 'communication' : 'daily_note',
    );
    const [step, setStep] = useState(0);
    // Note type is first-class since the standalone progress-note page was
    // retired — progress notes & handovers are written from this wizard.
    const [noteType, setNoteType] = useState<
        'daily_note' | 'progress_note' | 'handover'
    >('daily_note');
    const [tagText, setTagText] = useState('');
    const [concernText, setConcernText] = useState('');
    const [processing, setProcessing] = useState(false);
    const openedAt = useRef<number | null>(null);

    const isCommunication = mode === 'communication';
    const title = isCommunication ? 'Communication Note' : 'Daily Note';
    const showMoodFields = ['mood', 'concern'].includes(form.data.category);
    const showConcernFields = form.data.category === 'concern';
    const showGoalFields = form.data.category === 'goal_progress';

    useEffect(() => {
        if (open) {
            openedAt.current = Date.now();
            form.setData(
                defaultDailyNoteValues(
                    isCommunication ? 'communication' : 'daily_note',
                ),
            );
            setStep(0);
            setNoteType('daily_note');
            setTagText('');
            setConcernText('');
            setProcessing(false);
        } else {
            openedAt.current = null;
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, isCommunication]);

    const selectedCategory = useMemo(
        () =>
            NOTE_CATEGORIES.find(
                (category) => category.key === form.data.category,
            ),
        [form.data.category],
    );

    const update = <K extends keyof DailyNoteFormValues>(
        key: K,
        value: DailyNoteFormValues[K],
    ) => {
        form.setData(key, value as never);
    };

    const canContinue =
        step === 0
            ? Boolean(form.data.category)
            : step === 1
              ? Boolean(form.data.body.trim())
              : true;

    const submit = (draft: boolean) => {
        if (!draft && !form.data.body.trim()) return;

        setProcessing(true);
        const behaviourTags = splitTags(tagText);
        const concernFlags = splitTags(concernText);
        const payload = {
            ...form.data,
            type: isCommunication ? 'communication' : noteType,
            behaviour_tags: behaviourTags,
            concerns_flags: concernFlags,
            mood_rating:
                form.data.mood_rating === '' ? null : form.data.mood_rating,
            shift_id: form.data.shift_id ? Number(form.data.shift_id) : null,
            goal: form.data.goal || null,
            attachments: form.data.attachments,
            is_draft: draft,
            visibility: draft ? 'internal' : form.data.visibility,
            flagged_reason: form.data.is_flagged
                ? form.data.flagged_reason
                : null,
            follow_up_due_at: form.data.follow_up_due_at || null,
            occurred_at: form.data.occurred_at || null,
        };

        router.post(`/operations/clients/${clientId}/daily-notes`, payload, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                window.dispatchEvent(
                    new CustomEvent('client-profile:note-capture', {
                        detail: {
                            mode: isCommunication ? 'communication' : 'daily',
                            category: form.data.category,
                            draft,
                            flagged: form.data.is_flagged,
                            elapsed_ms: openedAt.current
                                ? Date.now() - openedAt.current
                                : null,
                        },
                    }),
                );
                onOpenChange(false);
                onSubmitted?.();
            },
            onFinish: () => setProcessing(false),
        });
    };

    const footerStart = (
        <div className="flex gap-2">
            <Button
                type="button"
                variant="outline"
                onClick={() => onOpenChange(false)}
            >
                Cancel
            </Button>
            <Button
                type="button"
                variant="outline"
                onClick={() => submit(true)}
                disabled={!form.data.body.trim() || processing}
            >
                <Save className="mr-2 h-4 w-4" />
                Save Draft
            </Button>
        </div>
    );

    const footerEnd = (
        <>
            <Button
                type="button"
                variant="outline"
                onClick={() => setStep((current) => current - 1)}
                disabled={step === 0}
            >
                <ChevronLeft className="mr-2 h-4 w-4" />
                Back
            </Button>
            {step < 2 ? (
                <Button
                    type="button"
                    onClick={() => setStep((current) => current + 1)}
                    disabled={!canContinue}
                    className="min-h-11"
                    data-test="daily-note-next"
                >
                    Next
                    <ChevronRight className="ml-2 h-4 w-4" />
                </Button>
            ) : (
                <Button
                    type="button"
                    onClick={() => submit(false)}
                    disabled={!form.data.body.trim() || processing}
                    className="min-h-11"
                    data-test="daily-note-submit"
                >
                    <Check className="mr-2 h-4 w-4" />
                    Save Note
                </Button>
            )}
        </>
    );

    return (
        <WizardShell
            open={open}
            onClose={() => onOpenChange(false)}
            title={title}
            description="Use the steps to capture enough context for the next worker and for review later."
            railIcon={isCommunication ? MessageSquare : FileCheck2}
            railTitle={title}
            railSub={
                isCommunication
                    ? 'Family & external contact'
                    : 'Shift record for this client'
            }
            steps={STEPS}
            stepIndex={step}
            onStepClick={(index) => {
                // The bespoke dialog only allowed backwards travel (via Back);
                // rail clicks keep that contract so Next-gating is never skipped.
                if (index < step) setStep(index);
            }}
            footerStart={footerStart}
            footerEnd={footerEnd}
        >
            <div
                data-test={
                    isCommunication
                        ? 'client-communication-note-dialog'
                        : 'client-daily-note-dialog'
                }
            >
                {step === 0 ? (
                    <WizardStepPane>
                        <div
                            className="space-y-4"
                            data-test="daily-note-step-category"
                        >
                            {!isCommunication ? (
                                <div className="space-y-2">
                                    <Label>Note type</Label>
                                    <div className="grid gap-2 sm:grid-cols-3">
                                        {(
                                            [
                                                [
                                                    'daily_note',
                                                    'Daily note',
                                                    'What happened on shift',
                                                ],
                                                [
                                                    'progress_note',
                                                    'Progress note',
                                                    'Movement on a goal or plan',
                                                ],
                                                [
                                                    'handover',
                                                    'Handover',
                                                    'Brief the next shift',
                                                ],
                                            ] as const
                                        ).map(([key, label, desc]) => {
                                            const active = noteType === key;
                                            return (
                                                // eslint-disable-next-line no-restricted-syntax -- selector tile card, not a standard button
                                                <button
                                                    key={key}
                                                    type="button"
                                                    aria-pressed={active}
                                                    onClick={() =>
                                                        setNoteType(key)
                                                    }
                                                    data-test={`daily-note-type-${key}`}
                                                    className={cn(
                                                        'flex flex-col gap-0.5 rounded-lg border p-3 text-left transition-all hover:border-primary/50',
                                                        active
                                                            ? 'border-primary bg-primary/10 ring-1 ring-primary/40'
                                                            : 'border-border bg-card/50',
                                                    )}
                                                >
                                                    <span className="text-sm font-semibold">
                                                        {label}
                                                    </span>
                                                    <span className="text-xs text-muted-foreground">
                                                        {desc}
                                                    </span>
                                                </button>
                                            );
                                        })}
                                    </div>
                                </div>
                            ) : null}
                            <NoteCategoryPicker
                                value={form.data.category}
                                onChange={(value: NoteCategoryKey) =>
                                    update('category', value)
                                }
                            />
                        </div>
                    </WizardStepPane>
                ) : null}

                {step === 1 ? (
                    <WizardStepPane>
                        <div
                            className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_18rem]"
                            data-test="daily-note-step-details"
                        >
                            <div className="space-y-4">
                                <div className="space-y-2">
                                    <Label htmlFor="daily-note-subject">
                                        Short heading
                                    </Label>
                                    <Input
                                        id="daily-note-subject"
                                        value={form.data.subject}
                                        onChange={(event) =>
                                            update(
                                                'subject',
                                                event.target.value,
                                            )
                                        }
                                        placeholder="Optional"
                                        className="min-h-11"
                                    />
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="daily-note-body">
                                        What happened?
                                    </Label>
                                    <Textarea
                                        id="daily-note-body"
                                        value={form.data.body}
                                        onChange={(event) =>
                                            update('body', event.target.value)
                                        }
                                        className="min-h-44"
                                        autoFocus
                                        data-test="daily-note-body"
                                    />
                                </div>

                                {showGoalFields ? (
                                    <div className="space-y-2">
                                        <Label htmlFor="daily-note-goal">
                                            Related goal
                                        </Label>
                                        {goalOptions.length > 0 ? (
                                            <Select
                                                value={form.data.goal}
                                                onValueChange={(value) =>
                                                    update('goal', value)
                                                }
                                            >
                                                <SelectTrigger
                                                    id="daily-note-goal"
                                                    className="min-h-11"
                                                >
                                                    <SelectValue placeholder="Choose goal" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {goalOptions.map(
                                                        (goal) => (
                                                            <SelectItem
                                                                key={String(
                                                                    goal.id ??
                                                                        goal.label,
                                                                )}
                                                                value={
                                                                    goal.label
                                                                }
                                                            >
                                                                {goal.label}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                        ) : (
                                            <Input
                                                id="daily-note-goal"
                                                value={form.data.goal}
                                                onChange={(event) =>
                                                    update(
                                                        'goal',
                                                        event.target.value,
                                                    )
                                                }
                                                className="min-h-11"
                                            />
                                        )}
                                    </div>
                                ) : null}

                                {isCommunication ? (
                                    <div className="grid gap-3 sm:grid-cols-3">
                                        <div className="space-y-2">
                                            <Label htmlFor="daily-note-contact">
                                                Contact
                                            </Label>
                                            <Input
                                                id="daily-note-contact"
                                                value={
                                                    form.data.contact_person
                                                }
                                                onChange={(event) =>
                                                    update(
                                                        'contact_person',
                                                        event.target.value,
                                                    )
                                                }
                                                className="min-h-11"
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="daily-note-relationship">
                                                Relationship
                                            </Label>
                                            <Input
                                                id="daily-note-relationship"
                                                value={
                                                    form.data
                                                        .contact_relationship
                                                }
                                                onChange={(event) =>
                                                    update(
                                                        'contact_relationship',
                                                        event.target.value,
                                                    )
                                                }
                                                className="min-h-11"
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="daily-note-method">
                                                Method
                                            </Label>
                                            <Select
                                                value={
                                                    form.data.contact_method
                                                }
                                                onValueChange={(value) =>
                                                    update(
                                                        'contact_method',
                                                        value,
                                                    )
                                                }
                                            >
                                                <SelectTrigger
                                                    id="daily-note-method"
                                                    className="min-h-11"
                                                >
                                                    <SelectValue placeholder="Choose" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="phone">
                                                        Phone
                                                    </SelectItem>
                                                    <SelectItem value="email">
                                                        Email
                                                    </SelectItem>
                                                    <SelectItem value="portal">
                                                        Portal
                                                    </SelectItem>
                                                    <SelectItem value="in_person">
                                                        In person
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                    </div>
                                ) : null}
                            </div>

                            <div className="space-y-4 rounded-lg border bg-muted/30 p-4">
                                <div className="space-y-2">
                                    <Label htmlFor="daily-note-when">
                                        When
                                    </Label>
                                    <Input
                                        id="daily-note-when"
                                        type="datetime-local"
                                        value={form.data.occurred_at}
                                        onChange={(event) =>
                                            update(
                                                'occurred_at',
                                                event.target.value,
                                            )
                                        }
                                        className="min-h-11"
                                    />
                                </div>

                                {shiftOptions.length > 0 ? (
                                    <div className="space-y-2">
                                        <Label htmlFor="daily-note-shift">
                                            Shift
                                        </Label>
                                        <Select
                                            value={form.data.shift_id}
                                            onValueChange={(value) =>
                                                update('shift_id', value)
                                            }
                                        >
                                            <SelectTrigger
                                                id="daily-note-shift"
                                                className="min-h-11"
                                            >
                                                <SelectValue placeholder="Link shift" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {shiftOptions.map((shift) => (
                                                    <SelectItem
                                                        key={shift.id}
                                                        value={String(
                                                            shift.id,
                                                        )}
                                                    >
                                                        {shift.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                ) : null}

                                {showMoodFields ? (
                                    <>
                                        <div className="space-y-2">
                                            <Label htmlFor="daily-note-mood">
                                                Mood rating
                                            </Label>
                                            <Input
                                                id="daily-note-mood"
                                                type="number"
                                                min="1"
                                                max="10"
                                                value={form.data.mood_rating}
                                                onChange={(event) =>
                                                    update(
                                                        'mood_rating',
                                                        event.target.value
                                                            ? Number(
                                                                  event.target
                                                                      .value,
                                                              )
                                                            : '',
                                                    )
                                                }
                                                className="min-h-11"
                                            />
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="daily-note-tags">
                                                Behaviour tags
                                            </Label>
                                            <Input
                                                id="daily-note-tags"
                                                value={tagText}
                                                onChange={(event) =>
                                                    setTagText(
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="Comma separated"
                                                className="min-h-11"
                                            />
                                        </div>
                                    </>
                                ) : null}

                                {showConcernFields ? (
                                    <div className="space-y-2">
                                        <Label htmlFor="daily-note-concerns">
                                            Concern flags
                                        </Label>
                                        <Input
                                            id="daily-note-concerns"
                                            value={concernText}
                                            onChange={(event) =>
                                                setConcernText(
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="Comma separated"
                                            className="min-h-11"
                                        />
                                    </div>
                                ) : null}

                                <div className="space-y-2">
                                    <Label htmlFor="daily-note-attachments">
                                        Attachments
                                    </Label>
                                    <Input
                                        id="daily-note-attachments"
                                        type="file"
                                        multiple
                                        className="min-h-11"
                                        onChange={(event) =>
                                            update(
                                                'attachments',
                                                Array.from(
                                                    event.target.files ?? [],
                                                ).map((file) => ({
                                                    name: file.name,
                                                    size: file.size,
                                                })),
                                            )
                                        }
                                    />
                                    {form.data.attachments.length > 0 ? (
                                        <p className="text-xs text-muted-foreground">
                                            {form.data.attachments.length} file
                                            {form.data.attachments.length === 1
                                                ? ''
                                                : 's'}{' '}
                                            attached
                                        </p>
                                    ) : null}
                                </div>

                                <label className="flex items-center justify-between gap-3 rounded-lg border bg-background p-3 text-sm">
                                    <span>Show on timeline</span>
                                    <Switch
                                        checked={form.data.appears_on_timeline}
                                        onCheckedChange={(checked) =>
                                            update(
                                                'appears_on_timeline',
                                                checked,
                                            )
                                        }
                                    />
                                </label>
                            </div>
                        </div>
                    </WizardStepPane>
                ) : null}

                {step === 2 ? (
                    <WizardStepPane>
                        <div
                            className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_18rem]"
                            data-test="daily-note-step-review"
                        >
                            <div className="space-y-4 rounded-lg border p-4">
                                <div className="flex flex-wrap items-center gap-2">
                                    <Badge variant="secondary">
                                        {selectedCategory?.label ??
                                            'Daily note'}
                                    </Badge>
                                    {form.data.subject ? (
                                        <span className="text-sm font-medium">
                                            {form.data.subject}
                                        </span>
                                    ) : null}
                                </div>
                                <p className="text-sm leading-6 whitespace-pre-wrap">
                                    {form.data.body ||
                                        'No note body has been entered yet.'}
                                </p>
                                {tagText || concernText ? (
                                    <div className="flex flex-wrap gap-2">
                                        {splitTags(tagText).map((tag) => (
                                            <Badge key={tag} variant="outline">
                                                {tag}
                                            </Badge>
                                        ))}
                                        {splitTags(concernText).map((flag) => (
                                            <Badge
                                                key={flag}
                                                className="bg-status-warning-bg text-status-warning"
                                            >
                                                {flag}
                                            </Badge>
                                        ))}
                                    </div>
                                ) : null}
                            </div>

                            <div className="space-y-4 rounded-lg border bg-muted/30 p-4">
                                <label className="frontline-focus flex min-h-11 items-start gap-3 rounded-lg border bg-background p-3">
                                    <Checkbox
                                        checked={form.data.is_flagged}
                                        onCheckedChange={(checked) =>
                                            update(
                                                'is_flagged',
                                                checked === true,
                                            )
                                        }
                                    />
                                    <span>
                                        <span className="flex items-center gap-2 text-sm font-medium">
                                            <AlertTriangle className="h-4 w-4 text-status-warning" />
                                            Needs review
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            Send this to the review queue.
                                        </span>
                                    </span>
                                </label>

                                <label className="flex min-h-11 items-center justify-between gap-3 rounded-lg border bg-background p-3 text-sm">
                                    <span>Visible to family</span>
                                    <Switch
                                        checked={
                                            form.data.visibility === 'portal'
                                        }
                                        onCheckedChange={(checked) =>
                                            update(
                                                'visibility',
                                                checked
                                                    ? 'portal'
                                                    : 'internal',
                                            )
                                        }
                                    />
                                </label>

                                {form.data.is_flagged ? (
                                    <Textarea
                                        value={form.data.flagged_reason}
                                        onChange={(event) =>
                                            update(
                                                'flagged_reason',
                                                event.target.value,
                                            )
                                        }
                                        placeholder="Reason for review"
                                        className="min-h-20"
                                    />
                                ) : null}

                                <div className="space-y-2">
                                    <Label htmlFor="daily-note-follow-up">
                                        Follow-up action
                                    </Label>
                                    <Textarea
                                        id="daily-note-follow-up"
                                        value={form.data.follow_up_action}
                                        onChange={(event) =>
                                            update(
                                                'follow_up_action',
                                                event.target.value,
                                            )
                                        }
                                        className="min-h-20"
                                        placeholder="Optional"
                                    />
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="daily-note-due">
                                        Follow-up due
                                    </Label>
                                    <Input
                                        id="daily-note-due"
                                        type="datetime-local"
                                        value={form.data.follow_up_due_at}
                                        onChange={(event) =>
                                            update(
                                                'follow_up_due_at',
                                                event.target.value,
                                            )
                                        }
                                        className="min-h-11"
                                    />
                                </div>
                            </div>
                        </div>
                    </WizardStepPane>
                ) : null}
            </div>
        </WizardShell>
    );
}
