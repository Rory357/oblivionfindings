/* eslint-disable no-restricted-syntax -- The person/reviewer pick rows and the
 * template list rows are intentionally styled native <button>/<div> selector
 * cards (Add-Client wizard contract, same as asset-wizards.tsx); all colours
 * are semantic design tokens. */
/* 360 Feedback wizards — Request-feedback stepper + question-template
 * create/edit stepper + Manage-templates dialog. Built on the shared HR wizard
 * kit (WizardShell + primitives) so they are visually identical to the
 * Add-Client / Asset wizards. Replaces the retired full-page
 * pages/hr/feedback/request.tsx form. */
import { router, useForm } from '@inertiajs/react';
import {
    ArrowLeftRight,
    CheckCircle2,
    ClipboardCheck,
    FileText,
    ListChecks,
    MessageSquare,
    Pencil,
    Plus,
    Search,
    Trash2,
    User,
    UserCheck,
    Users,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

import {
    Field,
    ReviewCard,
    ReviewRow,
    StepHead,
    TilePicker,
    useWizard,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/hr/wizard';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { fireConfetti } from '@/lib/confetti';

/* ------------------------------------------------------------------ */
/*  Shared types + helpers                                             */
/* ------------------------------------------------------------------ */

export type FeedbackEmployee = { id: number; name: string };
export type FeedbackTemplateQuestion = { key: string; question: string };
export type FeedbackTemplate = {
    id: number;
    name: string;
    description: string | null;
    questions: FeedbackTemplateQuestion[];
    is_default: boolean;
};

/** Wizard data shared by the index page (null for non-managers). */
export type FeedbackWizardData = {
    employees: FeedbackEmployee[];
    reviewTypes: string[];
    templates: FeedbackTemplate[];
    defaultQuestions: Record<string, string>;
};

const REVIEW_TYPE_META: Record<string, { label: string; description: string }> = {
    peer: { label: 'Peer review', description: 'Feedback from colleagues at the same level' },
    manager: { label: 'Manager review', description: 'Feedback from a direct manager' },
    direct_report: { label: 'Direct report', description: 'Feedback from people they manage' },
    self: { label: 'Self assessment', description: 'Self-reflection on their own performance' },
};

export function reviewTypeLabel(type: string): string {
    return REVIEW_TYPE_META[type]?.label ?? type;
}

function initials(name: string) {
    return name
        .split(' ')
        .map((n) => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
}

function slugify(text: string) {
    return text
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_|_$/g, '')
        .slice(0, 100);
}

function fdate(iso: string): string {
    const d = new Date(`${iso}T00:00:00`);
    return Number.isNaN(d.getTime())
        ? iso
        : d.toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' });
}

/** Flash error carried by an Inertia redirect — `back()->with('error')` fires
 *  onSuccess, not onError (see reference_inertia_flash_error). */
function pageFlashError(page: { props: Record<string, unknown> }): string | null {
    const flash = page.props.flash as { error?: string } | undefined;
    return flash?.error ?? null;
}

/** Searchable single-pick person list (Assign-asset contract). */
function PersonPickList({
    people,
    pickedId,
    onPick,
    emptyLabel,
}: {
    people: FeedbackEmployee[];
    pickedId: string;
    onPick: (id: string) => void;
    emptyLabel: string;
}) {
    const [search, setSearch] = useState('');
    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return people;
        return people.filter((p) => p.name.toLowerCase().includes(q));
    }, [search, people]);

    return (
        <>
            <div className="relative mb-3">
                <Search className="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Search staff by name…"
                    className="pl-8"
                />
            </div>
            <div className="flex max-h-64 flex-col gap-1.5 overflow-y-auto">
                {filtered.map((p) => {
                    const active = String(p.id) === pickedId;
                    return (
                        <button
                            key={p.id}
                            type="button"
                            onClick={() => onPick(String(p.id))}
                            className={`flex items-center gap-3 rounded-xl border bg-card px-3 py-2.5 text-left transition-colors ${active ? 'border-primary bg-primary/[0.06]' : 'border-border hover:border-primary/50'}`}
                        >
                            <span className="grid h-9 w-9 flex-none place-items-center rounded-full bg-primary/10 text-[12.5px] font-bold text-primary">
                                {initials(p.name)}
                            </span>
                            <span className="min-w-0 flex-1 text-[13.5px] font-bold">{p.name}</span>
                            {active ? <CheckCircle2 className="h-5 w-5 text-primary" /> : null}
                        </button>
                    );
                })}
                {filtered.length === 0 ? (
                    <div className="py-6 text-center text-[13px] text-muted-foreground">
                        {search ? `No staff match “${search}”.` : emptyLabel}
                    </div>
                ) : null}
            </div>
        </>
    );
}

/* ================================================================== */
/*  Request 360 feedback                                              */
/* ================================================================== */

const REQUEST_STEPS: readonly WizardStep[] = [
    { key: 'who', label: 'Employee', blurb: 'Who the feedback is about', icon: User },
    { key: 'type', label: 'Review type', blurb: 'Peer, manager, report or self', icon: ArrowLeftRight },
    { key: 'reviewers', label: 'Reviewers', blurb: 'Who provides feedback', icon: Users },
    { key: 'questions', label: 'Questions', blurb: 'Template & due date', icon: FileText },
    { key: 'review', label: 'Review', blurb: 'Confirm & send', icon: CheckCircle2 },
];

const DEFAULT_TEMPLATE_KEY = 'default';

export function RequestFeedbackWizard({
    data,
    initialSubjectId = null,
    onClose,
}: {
    data: FeedbackWizardData;
    /** Preselected subject (e.g. deep link from an employee profile). */
    initialSubjectId?: string | null;
    onClose: () => void;
}) {
    const { employees, reviewTypes, templates, defaultQuestions } = data;
    const wizard = useWizard(REQUEST_STEPS.length);
    const [done, setDone] = useState(false);
    const [reviewerSearch, setReviewerSearch] = useState('');
    const [sentCount, setSentCount] = useState(0);

    const defaultTemplate = templates.find((t) => t.is_default);
    const form = useForm({
        subject_user_id:
            initialSubjectId &&
            employees.some((e) => String(e.id) === initialSubjectId)
                ? initialSubjectId
                : '',
        review_type: '',
        reviewer_user_ids: [] as string[],
        template_id: defaultTemplate ? String(defaultTemplate.id) : DEFAULT_TEMPLATE_KEY,
        due_date: '',
    });

    const isSelf = form.data.review_type === 'self';
    const subject = employees.find((e) => String(e.id) === form.data.subject_user_id) ?? null;
    const template =
        form.data.template_id === DEFAULT_TEMPLATE_KEY
            ? null
            : (templates.find((t) => String(t.id) === form.data.template_id) ?? null);
    const questions: FeedbackTemplateQuestion[] =
        template?.questions ??
        Object.entries(defaultQuestions).map(([key, question]) => ({ key, question }));

    // Self-assessment: the subject reviews themselves.
    useEffect(() => {
        if (isSelf && form.data.subject_user_id) {
            form.setData('reviewer_user_ids', [form.data.subject_user_id]);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps -- Inertia form helpers are stable; sync only when these fields change.
    }, [form.data.review_type, form.data.subject_user_id]);

    const toggleReviewer = (id: string) => {
        const current = form.data.reviewer_user_ids;
        form.setData(
            'reviewer_user_ids',
            current.includes(id) ? current.filter((r) => r !== id) : [...current, id],
        );
    };

    const availableReviewers = useMemo(() => {
        const q = reviewerSearch.trim().toLowerCase();
        return employees
            .filter((e) => String(e.id) !== form.data.subject_user_id)
            .filter((e) => !q || e.name.toLowerCase().includes(q));
    }, [employees, form.data.subject_user_id, reviewerSearch]);

    const stepValid = [
        !!form.data.subject_user_id,
        !!form.data.review_type,
        form.data.reviewer_user_ids.length > 0,
        true,
        true,
    ];

    const submit = () => {
        const count = form.data.reviewer_user_ids.length;
        // bulk-request is the same service call plus due-date support; the plain
        // request.store endpoint stays the no-due-date path.
        const useBulk = !!form.data.due_date;
        form.transform((data) => ({
            subject_user_id: Number(data.subject_user_id),
            reviewer_user_ids: data.reviewer_user_ids.map(Number),
            review_type: data.review_type,
            template_id:
                data.template_id === DEFAULT_TEMPLATE_KEY ? null : Number(data.template_id),
            ...(useBulk ? { due_date: data.due_date } : {}),
        }));
        form.post(useBulk ? '/hr/feedback/bulk-request' : '/hr/feedback/request', {
            preserveScroll: true,
            onFinish: () => form.transform((data) => data),
            onSuccess: (page) => {
                const err = pageFlashError(page);
                if (err) {
                    toast.error(err);
                    return;
                }
                setSentCount(count);
                setDone(true);
                fireConfetti();
            },
        });
    };

    return (
        <WizardShell
            open
            onClose={onClose}
            title="Request 360-degree feedback"
            description="Pick the employee, review type, reviewers and question template."
            railIcon={MessageSquare}
            railTitle="Request feedback"
            railSub="360-degree review"
            steps={REQUEST_STEPS}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={wizard.progress}
            success={
                done ? (
                    <WizardSuccessPane
                        title="Feedback requests sent"
                        blurb={
                            <>
                                {sentCount} reviewer{sentCount === 1 ? '' : 's'} will be asked for
                                feedback on {subject?.name ?? 'the employee'}.
                            </>
                        }
                        actions={<Button onClick={onClose}>Done</Button>}
                    />
                ) : undefined
            }
            footerStart={
                wizard.isFirst ? null : (
                    <Button variant="outline" onClick={wizard.back}>
                        Back
                    </Button>
                )
            }
            footerEnd={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    {wizard.isLast ? (
                        <Button
                            onClick={submit}
                            disabled={form.processing || !stepValid.slice(0, 3).every(Boolean)}
                        >
                            {form.processing ? 'Sending…' : 'Send requests'}
                        </Button>
                    ) : (
                        <Button onClick={wizard.next} disabled={!stepValid[wizard.index]}>
                            Continue
                        </Button>
                    )}
                </>
            }
        >
            {wizard.index === 0 && (
                <WizardStepPane>
                    <StepHead
                        icon={User}
                        title="Who is the feedback about?"
                        blurb="Pick the employee being reviewed."
                    />
                    <PersonPickList
                        people={employees}
                        pickedId={form.data.subject_user_id}
                        onPick={(id) => form.setData('subject_user_id', id)}
                        emptyLabel="No employees available."
                    />
                    {form.errors.subject_user_id ? (
                        <p className="mt-2 text-xs text-status-critical">
                            {form.errors.subject_user_id}
                        </p>
                    ) : null}
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={ArrowLeftRight}
                        title="What kind of review?"
                        blurb="The review type frames who the reviewers are."
                    />
                    <TilePicker
                        value={form.data.review_type}
                        onChange={(v) => form.setData('review_type', v)}
                        options={reviewTypes.map((type) => ({
                            key: type,
                            label: REVIEW_TYPE_META[type]?.label ?? type,
                            description: REVIEW_TYPE_META[type]?.description,
                            icon: type === 'self' ? UserCheck : Users,
                        }))}
                    />
                    {form.errors.review_type ? (
                        <p className="mt-2 text-xs text-status-critical">
                            {form.errors.review_type}
                        </p>
                    ) : null}
                </WizardStepPane>
            )}

            {wizard.index === 2 && (
                <WizardStepPane>
                    <StepHead
                        icon={Users}
                        title={isSelf ? 'Reviewer' : 'Who provides feedback?'}
                        blurb={
                            isSelf
                                ? 'Self assessments are completed by the employee themselves.'
                                : 'Pick one or more reviewers — each gets their own request.'
                        }
                    />
                    {isSelf ? (
                        <div className="flex items-center gap-3 rounded-xl border border-primary/30 bg-primary/[0.06] p-4">
                            <span className="grid h-10 w-10 flex-none place-items-center rounded-full bg-primary/10 text-xs font-bold text-primary">
                                {subject ? initials(subject.name) : '—'}
                            </span>
                            <div>
                                <p className="text-sm font-semibold">{subject?.name ?? '—'}</p>
                                <p className="text-[11.5px] text-muted-foreground">
                                    Self-assessment — the employee reviews their own performance.
                                </p>
                            </div>
                            <CheckCircle2 className="ml-auto h-5 w-5 text-primary" />
                        </div>
                    ) : (
                        <>
                            <div className="relative mb-3">
                                <Search className="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    value={reviewerSearch}
                                    onChange={(e) => setReviewerSearch(e.target.value)}
                                    placeholder="Search reviewers…"
                                    className="pl-8"
                                />
                            </div>
                            <div className="flex max-h-64 flex-col gap-1.5 overflow-y-auto">
                                {availableReviewers.map((emp) => {
                                    const selected = form.data.reviewer_user_ids.includes(
                                        String(emp.id),
                                    );
                                    return (
                                        <button
                                            key={emp.id}
                                            type="button"
                                            onClick={() => toggleReviewer(String(emp.id))}
                                            className={`flex items-center gap-3 rounded-xl border bg-card px-3 py-2.5 text-left transition-colors ${selected ? 'border-primary bg-primary/[0.06]' : 'border-border hover:border-primary/50'}`}
                                        >
                                            <Checkbox
                                                checked={selected}
                                                onCheckedChange={() =>
                                                    toggleReviewer(String(emp.id))
                                                }
                                                onClick={(e) => e.stopPropagation()}
                                                aria-label={`Select ${emp.name}`}
                                            />
                                            <span className="grid h-8 w-8 flex-none place-items-center rounded-full bg-primary/10 text-[10.5px] font-bold text-primary">
                                                {initials(emp.name)}
                                            </span>
                                            <span className="min-w-0 flex-1 text-[13.5px] font-medium">
                                                {emp.name}
                                            </span>
                                            {selected ? (
                                                <CheckCircle2 className="h-4 w-4 text-primary" />
                                            ) : null}
                                        </button>
                                    );
                                })}
                                {availableReviewers.length === 0 ? (
                                    <div className="py-6 text-center text-[13px] text-muted-foreground">
                                        {reviewerSearch
                                            ? `No staff match “${reviewerSearch}”.`
                                            : 'No other staff available as reviewers.'}
                                    </div>
                                ) : null}
                            </div>
                            {form.data.reviewer_user_ids.length > 0 ? (
                                <p className="mt-2 text-xs text-muted-foreground">
                                    {form.data.reviewer_user_ids.length} reviewer
                                    {form.data.reviewer_user_ids.length === 1 ? '' : 's'} selected.
                                </p>
                            ) : null}
                        </>
                    )}
                    {form.errors.reviewer_user_ids ? (
                        <p className="mt-2 text-xs text-status-critical">
                            {form.errors.reviewer_user_ids}
                        </p>
                    ) : null}
                </WizardStepPane>
            )}

            {wizard.index === 3 && (
                <WizardStepPane>
                    <StepHead
                        icon={FileText}
                        title="Questions & due date"
                        blurb="Pick the question template reviewers will answer."
                    />
                    <TilePicker
                        value={form.data.template_id}
                        onChange={(v) => form.setData('template_id', v)}
                        options={[
                            ...(defaultTemplate
                                ? []
                                : [
                                      {
                                          key: DEFAULT_TEMPLATE_KEY,
                                          label: 'Standard questions',
                                          description: 'The built-in six-question 360 set',
                                          icon: ListChecks,
                                          meta: `${Object.keys(defaultQuestions).length} questions`,
                                      },
                                  ]),
                            ...templates.map((t) => ({
                                key: String(t.id),
                                label: t.name,
                                description: t.description ?? undefined,
                                icon: FileText,
                                meta: `${t.questions.length} question${t.questions.length === 1 ? '' : 's'}${t.is_default ? ' · default' : ''}`,
                            })),
                        ]}
                    />
                    {form.errors.template_id ? (
                        <p className="mt-2 text-xs text-status-critical">
                            {form.errors.template_id}
                        </p>
                    ) : null}
                    <div className="mt-4 grid gap-3.5 sm:grid-cols-2">
                        <Field
                            label="Due date"
                            hint="optional"
                            error={form.errors.due_date}
                        >
                            <Input
                                type="date"
                                value={form.data.due_date}
                                onChange={(e) => form.setData('due_date', e.target.value)}
                            />
                        </Field>
                    </div>
                    <div className="mt-4">
                        <p className="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            Reviewers will answer
                        </p>
                        <div className="space-y-1.5">
                            {questions.map((q, i) => (
                                <div
                                    key={q.key || i}
                                    className="flex items-start gap-2.5 rounded-lg border border-border bg-card/50 p-2.5"
                                >
                                    <span className="grid h-5 w-5 flex-none place-items-center rounded-full bg-primary/10 text-[10px] font-bold text-primary">
                                        {i + 1}
                                    </span>
                                    <div className="min-w-0">
                                        <p className="text-[12.5px] font-semibold capitalize">
                                            {q.key.replace(/_/g, ' ')}
                                        </p>
                                        <p className="text-[11.5px] text-muted-foreground">
                                            {q.question}
                                        </p>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 4 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Confirm & send"
                        blurb="Check the details, then send the requests."
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard icon={User} title="Employee" onEdit={() => wizard.goTo(0)}>
                            <ReviewRow label="Subject" value={subject?.name} />
                            <ReviewRow
                                label="Review type"
                                value={
                                    form.data.review_type
                                        ? reviewTypeLabel(form.data.review_type)
                                        : undefined
                                }
                            />
                        </ReviewCard>
                        <ReviewCard icon={Users} title="Reviewers" onEdit={() => wizard.goTo(2)}>
                            <ReviewRow
                                label="Count"
                                value={`${form.data.reviewer_user_ids.length} reviewer${form.data.reviewer_user_ids.length === 1 ? '' : 's'}`}
                            />
                            <ReviewRow
                                label="Names"
                                value={
                                    form.data.reviewer_user_ids
                                        .map(
                                            (id) =>
                                                employees.find((e) => String(e.id) === id)?.name ??
                                                id,
                                        )
                                        .join(', ') || undefined
                                }
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={FileText}
                            title="Questions"
                            onEdit={() => wizard.goTo(3)}
                            span
                        >
                            <ReviewRow
                                label="Template"
                                value={template?.name ?? 'Standard questions'}
                            />
                            <ReviewRow label="Questions" value={String(questions.length)} />
                            <ReviewRow
                                label="Due date"
                                value={form.data.due_date ? fdate(form.data.due_date) : undefined}
                            />
                        </ReviewCard>
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

/* ================================================================== */
/*  Template create / edit                                            */
/* ================================================================== */

const TEMPLATE_STEPS: readonly WizardStep[] = [
    { key: 'details', label: 'Details', blurb: 'Name & purpose', icon: FileText },
    { key: 'questions', label: 'Questions', blurb: 'What reviewers answer', icon: ListChecks },
    { key: 'review', label: 'Review', blurb: 'Confirm & save', icon: CheckCircle2 },
];

export function TemplateWizard({
    template,
    onClose,
}: {
    /** Existing template to edit, or null to create a new one. */
    template: FeedbackTemplate | null;
    onClose: () => void;
}) {
    const wizard = useWizard(TEMPLATE_STEPS.length);
    const [done, setDone] = useState(false);
    const isEdit = !!template;

    const form = useForm({
        name: template?.name ?? '',
        description: template?.description ?? '',
        questions:
            template?.questions?.length
                ? template.questions.map((q) => ({ ...q }))
                : ([{ key: '', question: '' }] as FeedbackTemplateQuestion[]),
    });

    const setQuestion = (i: number, field: 'key' | 'question', value: string) => {
        const questions = form.data.questions.map((q) => ({ ...q }));
        questions[i] = { ...questions[i], [field]: value };
        if (field === 'question' && !questions[i].key) {
            questions[i].key = slugify(value);
        }
        form.setData('questions', questions);
    };

    const addQuestion = () =>
        form.setData('questions', [...form.data.questions, { key: '', question: '' }]);
    const removeQuestion = (i: number) =>
        form.setData(
            'questions',
            form.data.questions.filter((_, idx) => idx !== i),
        );

    const questionsValid =
        form.data.questions.length > 0 && form.data.questions.every((q) => q.question.trim());
    const stepValid = [!!form.data.name.trim(), questionsValid, true];

    const submit = () => {
        // Ensure every question carries a key before submitting.
        form.transform((data) => ({
            ...data,
            questions: data.questions.map((q) => ({
                key: q.key || slugify(q.question),
                question: q.question,
            })),
        }));
        const opts = {
            preserveScroll: true,
            onFinish: () => form.transform((data) => data),
            onSuccess: (page: { props: Record<string, unknown> }) => {
                const err = pageFlashError(page);
                if (err) {
                    toast.error(err);
                    return;
                }
                setDone(true);
                if (!isEdit) fireConfetti();
            },
        };
        if (isEdit) {
            form.put(`/hr/feedback/templates/${template.id}`, opts);
        } else {
            form.post('/hr/feedback/templates', opts);
        }
    };

    return (
        <WizardShell
            open
            onClose={onClose}
            title={isEdit ? 'Edit question template' : 'New question template'}
            description="Define the questions reviewers answer in a 360 review."
            railIcon={FileText}
            railTitle={isEdit ? 'Edit template' : 'New template'}
            railSub="360 questions"
            steps={TEMPLATE_STEPS}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={wizard.progress}
            success={
                done ? (
                    <WizardSuccessPane
                        title={isEdit ? 'Template updated' : 'Template created'}
                        blurb={
                            <>
                                “{form.data.name}” is ready to use in feedback requests.
                            </>
                        }
                        actions={<Button onClick={onClose}>Done</Button>}
                    />
                ) : undefined
            }
            footerStart={
                wizard.isFirst ? null : (
                    <Button variant="outline" onClick={wizard.back}>
                        Back
                    </Button>
                )
            }
            footerEnd={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    {wizard.isLast ? (
                        <Button
                            onClick={submit}
                            disabled={form.processing || !stepValid.slice(0, 2).every(Boolean)}
                        >
                            {form.processing
                                ? 'Saving…'
                                : isEdit
                                  ? 'Save changes'
                                  : 'Create template'}
                        </Button>
                    ) : (
                        <Button onClick={wizard.next} disabled={!stepValid[wizard.index]}>
                            Continue
                        </Button>
                    )}
                </>
            }
        >
            {wizard.index === 0 && (
                <WizardStepPane>
                    <StepHead
                        icon={FileText}
                        title="Template details"
                        blurb="Name the template and describe when to use it."
                    />
                    <div className="grid gap-3.5">
                        <Field label="Template name" required error={form.errors.name}>
                            <Input
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                placeholder="e.g. Leadership review"
                            />
                        </Field>
                        <Field label="Description" hint="optional" error={form.errors.description}>
                            <Textarea
                                rows={3}
                                value={form.data.description}
                                onChange={(e) => form.setData('description', e.target.value)}
                                placeholder="Brief description of when to use this template…"
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={ListChecks}
                        title="Questions"
                        blurb="Reviewers rate each question 1–5 and can add a comment."
                    />
                    <div className="space-y-3">
                        {form.data.questions.map((q, i) => (
                            <div key={i} className="flex gap-2 rounded-xl border border-border bg-card/50 p-3">
                                <span className="grid h-6 w-6 flex-none place-items-center rounded-full bg-primary/10 text-[10px] font-bold text-primary">
                                    {i + 1}
                                </span>
                                <div className="min-w-0 flex-1 space-y-2">
                                    <Input
                                        value={q.question}
                                        onChange={(e) => setQuestion(i, 'question', e.target.value)}
                                        placeholder="e.g. How effectively does this person communicate?"
                                        aria-label={`Question ${i + 1}`}
                                    />
                                    <Input
                                        value={q.key}
                                        onChange={(e) => setQuestion(i, 'key', e.target.value)}
                                        placeholder="Key (auto-generated)"
                                        aria-label={`Question ${i + 1} key`}
                                        className="h-7 text-xs text-muted-foreground"
                                    />
                                </div>
                                {form.data.questions.length > 1 ? (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label={`Remove question ${i + 1}`}
                                        className="h-7 w-7 flex-none text-status-critical"
                                        onClick={() => removeQuestion(i)}
                                    >
                                        <Trash2 className="h-3.5 w-3.5" />
                                    </Button>
                                ) : null}
                            </div>
                        ))}
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="mt-3 gap-1"
                        onClick={addQuestion}
                    >
                        <Plus className="h-3.5 w-3.5" />
                        Add question
                    </Button>
                    {form.errors.questions ? (
                        <p className="mt-2 text-xs text-status-critical">{form.errors.questions}</p>
                    ) : null}
                </WizardStepPane>
            )}

            {wizard.index === 2 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Confirm & save"
                        blurb="Check the template, then save it."
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard icon={FileText} title="Details" onEdit={() => wizard.goTo(0)}>
                            <ReviewRow label="Name" value={form.data.name} />
                            <ReviewRow label="Description" value={form.data.description || undefined} />
                        </ReviewCard>
                        <ReviewCard
                            icon={ListChecks}
                            title="Questions"
                            onEdit={() => wizard.goTo(1)}
                        >
                            <ReviewRow
                                label="Count"
                                value={`${form.data.questions.length} question${form.data.questions.length === 1 ? '' : 's'}`}
                            />
                        </ReviewCard>
                        <ReviewCard icon={ListChecks} title="Question list" span>
                            {form.data.questions.map((q, i) => (
                                <ReviewRow key={i} label={`Q${i + 1}`} value={q.question} />
                            ))}
                        </ReviewCard>
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

/* ================================================================== */
/*  Manage templates dialog                                           */
/* ================================================================== */

export function ManageTemplatesDialog({
    templates,
    onClose,
}: {
    templates: FeedbackTemplate[];
    onClose: () => void;
}) {
    const [editing, setEditing] = useState<FeedbackTemplate | 'new' | null>(null);
    const [confirmDeleteId, setConfirmDeleteId] = useState<number | null>(null);
    const [deleting, setDeleting] = useState(false);

    const destroy = (t: FeedbackTemplate) => {
        setDeleting(true);
        router.delete(`/hr/feedback/templates/${t.id}`, {
            preserveScroll: true,
            onSuccess: (page) => {
                const err = pageFlashError(page);
                if (err) toast.error(err);
                setConfirmDeleteId(null);
            },
            onFinish: () => setDeleting(false),
        });
    };

    if (editing) {
        return (
            <TemplateWizard
                template={editing === 'new' ? null : editing}
                onClose={() => setEditing(null)}
            />
        );
    }

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[80vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Question templates</DialogTitle>
                    <DialogDescription>
                        The question sets reviewers answer in 360 feedback requests.
                    </DialogDescription>
                </DialogHeader>
                <div className="space-y-2">
                    {templates.length === 0 ? (
                        <div className="rounded-xl border border-dashed border-border py-8 text-center">
                            <FileText className="mx-auto mb-2 h-7 w-7 text-muted-foreground" />
                            <p className="text-sm text-muted-foreground">
                                No templates yet — requests use the standard questions.
                            </p>
                        </div>
                    ) : (
                        templates.map((t) => (
                            <div
                                key={t.id}
                                className="flex items-start gap-3 rounded-xl border border-border bg-card p-3"
                            >
                                <span className="mt-0.5 grid h-8 w-8 flex-none place-items-center rounded-lg bg-primary/10">
                                    <FileText className="h-4 w-4 text-primary" />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-1.5">
                                        <p className="truncate text-sm font-semibold">{t.name}</p>
                                        {t.is_default ? (
                                            <Badge className="border-0 bg-primary/10 text-[9px] text-primary">
                                                Default
                                            </Badge>
                                        ) : null}
                                    </div>
                                    {t.description ? (
                                        <p className="mt-0.5 line-clamp-1 text-[11.5px] text-muted-foreground">
                                            {t.description}
                                        </p>
                                    ) : null}
                                    <p className="mt-0.5 text-[11px] text-muted-foreground">
                                        {t.questions.length} question
                                        {t.questions.length === 1 ? '' : 's'}
                                    </p>
                                    {confirmDeleteId === t.id ? (
                                        <div className="mt-2 flex items-center gap-2 rounded-lg border border-status-critical/30 bg-status-critical-bg p-2">
                                            <p className="flex-1 text-xs text-status-critical">
                                                Delete “{t.name}”? Existing requests keep their
                                                question snapshot.
                                            </p>
                                            <Button
                                                size="sm"
                                                variant="destructive"
                                                className="h-7 text-xs"
                                                disabled={deleting}
                                                onClick={() => destroy(t)}
                                            >
                                                {deleting ? 'Deleting…' : 'Delete'}
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                className="h-7 text-xs"
                                                onClick={() => setConfirmDeleteId(null)}
                                            >
                                                Keep
                                            </Button>
                                        </div>
                                    ) : null}
                                </div>
                                <div className="flex flex-none gap-1">
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label={`Edit ${t.name}`}
                                        className="h-8 w-8"
                                        onClick={() => setEditing(t)}
                                    >
                                        <Pencil className="h-3.5 w-3.5" />
                                    </Button>
                                    {!t.is_default ? (
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label={`Delete ${t.name}`}
                                            className="h-8 w-8 text-status-critical"
                                            onClick={() => setConfirmDeleteId(t.id)}
                                        >
                                            <Trash2 className="h-3.5 w-3.5" />
                                        </Button>
                                    ) : null}
                                </div>
                            </div>
                        ))
                    )}
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Close
                    </Button>
                    <Button className="gap-1" onClick={() => setEditing('new')}>
                        <Plus className="h-3.5 w-3.5" />
                        New template
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
