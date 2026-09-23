import {
    DateTimeField,
    localDateTimeLabel,
    validLocalDateTime,
} from '@/components/fleet-assets/maintenance/date-time-field';
import { LeaveCalendarRange } from '@/components/hr/leave-calendar-range';
import { Button } from '@/components/ui/button';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import {
    ArrowRight,
    CalendarDays,
    ClipboardCheck,
    Clock as ClockIcon,
    FileCheck2,
    FileText,
    History,
    Link2,
    Loader2,
    MessageSquare,
    ShieldAlert,
    Upload,
    Wrench,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { Badge, Modal, Notice, Picker } from './ui';

export type Run = {
    id: string;
    template: string;
    version: string;
    observed: string;
    submitted: string;
    answers: string[];
    notes: string;
    outcome: string;
};
export const originalRun: Run = {
    id: 'CHK-0182',
    template: 'Vehicle condition record',
    version: 'DEMO-3',
    observed: '2026-09-21T08:10',
    submitted: '21 Sep 2026 · 8:14 am',
    answers: ['Issue recorded', 'No issue recorded'],
    notes: 'A condition concern was recorded for assessment. The original evidence stays with this check.',
    outcome: 'Failed',
};
const templates = [
    {
        id: 'condition',
        name: 'Vehicle condition record',
        detail: 'DEMO-3 · Example questions only · Vehicle profile',
    },
    {
        id: 'return',
        name: 'Return condition record',
        detail: 'DEMO-2 · Example questions only · After use',
    },
];
const exampleQuestions = [
    'Exterior condition (example)',
    'Cabin condition (example)',
];
const locked = (
    <div className="locked">
        <strong>Kōwhai van</strong>
        <span>VH-014 · KWH014 · Kōwhai House</span>
    </div>
);

function CloseGuard({
    onKeep,
    onDiscard,
}: {
    onKeep: () => void;
    onDiscard: () => void;
}) {
    return (
        <Modal
            title="Discard this draft?"
            icon={ShieldAlert}
            description="Your unsent answers and locally selected files will be removed."
            onClose={onKeep}
            footer={
                <>
                    <Button variant="outline" onClick={onKeep}>
                        Keep editing
                    </Button>
                    <Button variant="destructive" onClick={onDiscard}>
                        Discard draft
                    </Button>
                </>
            }
        >
            <p>No operational record has been changed.</p>
        </Modal>
    );
}

export function CheckFlow({
    nextId,
    onClose,
    onDone,
    onReport,
    fail,
    searchFail,
    unconfigured,
}: {
    nextId: string;
    onClose: () => void;
    onDone: (run: Run) => void;
    onReport: () => void;
    fail: boolean;
    searchFail: boolean;
    unconfigured: boolean;
}) {
    const [step, setStep] = useState(0),
        [template, setTemplate] = useState('condition'),
        [observed, setObserved] = useState('2026-09-21T09:20'),
        [answers, setAnswers] = useState(['', '']),
        [notes, setNotes] = useState(''),
        [error, setError] = useState(''),
        [attempt, setAttempt] = useState(0),
        [saving, setSaving] = useState(false),
        [saved, setSaved] = useState<Run | null>(null),
        [discard, setDiscard] = useState(false);
    const dirty =
        template !== 'condition' ||
        observed !== '2026-09-21T09:20' ||
        answers.some(Boolean) ||
        Boolean(notes.trim());
    const close = () => {
        if (!saving) saved || !dirty ? onClose() : setDiscard(true);
    };
    const advance = () => {
        if (step === 0 && !validLocalDateTime(observed)) {
            setError('Choose a complete observation date and time.');
            return;
        }
        if (step === 1 && answers.some((a) => !a)) {
            setError('Answer both example observations before review.');
            document.querySelector<HTMLSelectElement>('#answer-0')?.focus();
            return;
        }
        setError('');
        setStep(step + 1);
    };
    const submit = () => {
        if (saving) return;
        if (!validLocalDateTime(observed)) {
            setStep(0);
            setError('Choose a complete observation date and time.');
            return;
        }
        if (answers.some((answer) => !answer)) {
            setStep(1);
            setError('Answer both example observations before submitting.');
            return;
        }
        setSaving(true);
        setError('');
        setTimeout(() => {
            setSaving(false);
            setAttempt((a) => a + 1);
            if (fail && attempt === 0) {
                setError(
                    'The check could not be saved. Your answers are kept. Retry uses this same draft.',
                );
                return;
            }
            const run: Run = {
                id: nextId,
                template: templates.find((t) => t.id === template)!.name,
                version: template === 'condition' ? 'DEMO-3' : 'DEMO-2',
                observed,
                submitted: '21 Sep 2026 · 9:24 am',
                answers: [...answers],
                notes,
                outcome:
                    unconfigured || answers.includes('Unable to assess')
                        ? 'Needs assessment'
                        : answers.includes('Issue recorded')
                          ? 'Failed'
                          : 'Passed',
            };
            setSaved(run);
            onDone(run);
        }, 650);
    };
    return (
        <>
            <WizardShell
                open
                title="Start vehicle check"
                maxWidth="min(92vw, 1100px)"
                description="Synthetic checklist walkthrough"
                railIcon={ClipboardCheck}
                railTitle="Vehicle check"
                railSub="Kōwhai van · VH-014"
                steps={[
                    {
                        key: 'context',
                        label: 'Check details',
                        blurb: 'Template and observation',
                        icon: ClipboardCheck,
                    },
                    {
                        key: 'answers',
                        label: 'Record answers',
                        blurb: 'Original observations',
                        icon: MessageSquare,
                    },
                    {
                        key: 'review',
                        label: 'Review',
                        blurb: 'Check before submitting',
                        icon: FileCheck2,
                    },
                ]}
                stepIndex={step}
                onStepClick={(i) => {
                    if (!saving) {
                        setError('');
                        setStep(i);
                    }
                }}
                pct={Math.round(
                    ((Number(Boolean(template)) +
                        Number(validLocalDateTime(observed)) +
                        answers.filter(Boolean).length +
                        Number(Boolean(notes.trim()))) /
                        5) *
                        100,
                )}
                onClose={close}
                footerStart={
                    <Button variant="outline" disabled={saving} onClick={close}>
                        Cancel
                    </Button>
                }
                footerEnd={
                    <>
                        {step > 0 && (
                            <Button
                                variant="outline"
                                disabled={saving}
                                onClick={() => setStep(step - 1)}
                            >
                                Back
                            </Button>
                        )}
                        <Button
                            disabled={saving}
                            onClick={step < 2 ? advance : submit}
                        >
                            {saving && (
                                <Loader2 className="h-4 w-4 animate-spin" />
                            )}
                            {saving
                                ? 'Saving demo…'
                                : step < 2
                                  ? 'Continue'
                                  : attempt
                                    ? 'Retry submission'
                                    : 'Submit check'}
                        </Button>
                    </>
                }
                success={
                    saved ? (
                        <WizardSuccessPane
                            title="Check recorded in this preview"
                            blurb={
                                <>
                                    <strong>
                                        {saved.id} · {saved.outcome}
                                    </strong>
                                    <br />
                                    Original answers and template{' '}
                                    {saved.version} are retained. This does not
                                    release the vehicle.
                                </>
                            }
                            actions={
                                <>
                                    <Button variant="outline" onClick={onClose}>
                                        Back to vehicle
                                    </Button>
                                    {saved.outcome === 'Failed' && (
                                        <Button onClick={onReport}>
                                            Create or link maintenance
                                        </Button>
                                    )}
                                </>
                            }
                        />
                    ) : undefined
                }
            >
                <WizardStepPane>
                    <div className="flow-stack">
                        {locked}
                        <p className="fixture-note">
                            Demonstration template · These example fields are
                            not an operational safety checklist.
                        </p>
                        {error && (
                            <Notice title="Check not saved" tone="critical">
                                {error}
                            </Notice>
                        )}
                        {step === 0 && (
                            <>
                                <Picker
                                    label="Checklist template"
                                    value={template}
                                    onChange={setTemplate}
                                    options={templates}
                                    fail={searchFail}
                                />
                                <DateTimeField
                                    id="check-observed"
                                    label="Observed at (required)"
                                    value={observed}
                                    onChange={setObserved}
                                />
                                {unconfigured && (
                                    <Notice
                                        title="Approved rules unavailable"
                                        tone="warning"
                                    >
                                        Answers can be recorded for assessment.
                                        They cannot produce a Passed or Ready
                                        result.
                                    </Notice>
                                )}
                            </>
                        )}
                        {step === 1 && (
                            <>
                                <div className="section-intro">
                                    <h3 className="text-section-title">
                                        {
                                            templates.find(
                                                (t) => t.id === template,
                                            )!.name
                                        }
                                    </h3>
                                    <Badge tone="info">
                                        {template === 'condition'
                                            ? 'DEMO-3'
                                            : 'DEMO-2'}
                                    </Badge>
                                </div>
                                {exampleQuestions.map((q, i) => (
                                    <div className="field" key={q}>
                                        <label htmlFor={`answer-${i}`}>
                                            {q}{' '}
                                            <span className="text-status-critical">
                                                *
                                            </span>
                                        </label>
                                        <select
                                            id={`answer-${i}`}
                                            aria-required="true"
                                            aria-invalid={Boolean(
                                                error && !answers[i],
                                            )}
                                            aria-describedby={
                                                error && !answers[i]
                                                    ? `answer-error-${i}`
                                                    : undefined
                                            }
                                            value={answers[i]}
                                            onChange={(e) =>
                                                setAnswers((a) =>
                                                    a.map((v, n) =>
                                                        i === n
                                                            ? e.target.value
                                                            : v,
                                                    ),
                                                )
                                            }
                                        >
                                            <option value="">
                                                Choose an answer
                                            </option>
                                            <option>No issue recorded</option>
                                            <option>Issue recorded</option>
                                            <option>Unable to assess</option>
                                        </select>
                                        {error && !answers[i] && (
                                            <p
                                                id={`answer-error-${i}`}
                                                className="text-xs text-status-critical"
                                            >
                                                Choose an answer, including
                                                Unable to assess when
                                                appropriate.
                                            </p>
                                        )}
                                    </div>
                                ))}
                                <div className="field">
                                    <label htmlFor="check-note">
                                        Observation notes
                                    </label>
                                    <Textarea
                                        id="check-note"
                                        value={notes}
                                        onChange={(e) =>
                                            setNotes(e.target.value)
                                        }
                                        placeholder="Add the relevant observation…"
                                    />
                                </div>
                                <p className="muted">
                                    Evidence from a failed check can be attached
                                    to the linked Maintenance record. A new
                                    attachment does not change the submitted
                                    answers.
                                </p>
                            </>
                        )}
                        {step === 2 && (
                            <>
                                <ReviewCard
                                    icon={ClipboardCheck}
                                    title="Original check"
                                    onEdit={() => setStep(0)}
                                >
                                    <ReviewRow
                                        label="Template"
                                        value={`${templates.find((t) => t.id === template)!.name} · ${template === 'condition' ? 'DEMO-3' : 'DEMO-2'}`}
                                    />
                                    <ReviewRow
                                        label="Observed"
                                        value={localDateTimeLabel(observed)}
                                    />
                                    <ReviewRow
                                        label="Record"
                                        value="Kōwhai van · VH-014"
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={MessageSquare}
                                    title="Answers"
                                    onEdit={() => setStep(1)}
                                >
                                    {exampleQuestions.map((q, i) => (
                                        <ReviewRow
                                            key={q}
                                            label={q}
                                            value={answers[i]}
                                        />
                                    ))}
                                    <ReviewRow label="Notes" value={notes} />
                                </ReviewCard>
                                <Notice title="Submission records the check">
                                    Assessment and any restriction or release
                                    remain separate. The submission never clears
                                    a restriction.
                                </Notice>
                            </>
                        )}
                    </div>
                </WizardStepPane>
            </WizardShell>
            {discard && (
                <CloseGuard
                    onKeep={() => setDiscard(false)}
                    onDiscard={onClose}
                />
            )}
        </>
    );
}

export function RunDetail({
    run,
    onClose,
    onReport,
    linked,
    canReport = true,
}: {
    run: Run;
    onClose: () => void;
    onReport: () => void;
    linked: boolean;
    canReport?: boolean;
}) {
    const [section, setSection] = useState(0);
    const sections = [
        {
            key: 'record',
            label: 'Check record',
            blurb: 'Outcome and original answers',
            icon: ClipboardCheck,
        },
        {
            key: 'source',
            label: 'Source & timing',
            blurb: 'Version and observation',
            icon: History,
        },
        {
            key: 'followup',
            label: 'Follow-up',
            blurb: 'Evidence and related work',
            icon: Wrench,
        },
    ];
    return (
        <WizardShell
            open
            title={`Check ${run.id}`}
            description={`${run.template} · Original submitted record`}
            railIcon={ClipboardCheck}
            railTitle="Vehicle check"
            railSub={run.id}
            steps={sections}
            stepIndex={section}
            onStepClick={setSection}
            headerLabel={sections[section].label}
            pct={null}
            maxWidth="min(92vw, 1100px)"
            onClose={onClose}
            railExtra={
                <div className="detail-rail-summary">
                    <Badge
                        tone={
                            run.outcome === 'Failed'
                                ? 'critical'
                                : run.outcome === 'Passed'
                                  ? 'success'
                                  : 'warning'
                        }
                    >
                        {run.outcome}
                    </Badge>
                    <p>{run.version} · original version</p>
                </div>
            }
            footerStart={
                <Button variant="outline" onClick={onClose}>
                    Back to vehicle
                </Button>
            }
            footerEnd={
                canReport ? (
                    <Button onClick={onReport}>
                        {linked
                            ? 'Review linked maintenance'
                            : 'Create or link maintenance'}
                    </Button>
                ) : (
                    <span className="muted">View-only access</span>
                )
            }
        >
            <WizardStepPane key={section}>
                <div className="flow-stack">
                    {locked}
                    {section === 0 && (
                        <>
                            <div className="section-intro">
                                <div>
                                    <span className="eyebrow">
                                        SUBMITTED CHECK
                                    </span>
                                    <h3 className="text-section-title">
                                        {run.template}
                                    </h3>
                                </div>
                                <Badge
                                    tone={
                                        run.outcome === 'Failed'
                                            ? 'critical'
                                            : run.outcome === 'Passed'
                                              ? 'success'
                                              : 'warning'
                                    }
                                >
                                    {run.outcome}
                                </Badge>
                            </div>
                            <ReviewCard
                                icon={ClipboardCheck}
                                title="Answers as submitted"
                            >
                                {exampleQuestions.map((q, i) => (
                                    <ReviewRow
                                        key={q}
                                        label={q}
                                        value={run.answers[i]}
                                    />
                                ))}
                            </ReviewCard>
                            <ReviewCard
                                icon={MessageSquare}
                                title="Observation notes"
                            >
                                <p className="body-copy">
                                    {run.notes ||
                                        'No additional notes recorded.'}
                                </p>
                            </ReviewCard>
                            <Notice title="The check is evidence, not a release">
                                The original outcome stays with this submission.
                                Maintenance assessment and any authorised
                                release remain separate.
                            </Notice>
                        </>
                    )}
                    {section === 1 && (
                        <>
                            <ReviewCard icon={FileText} title="Original source">
                                <ReviewRow
                                    label="Template"
                                    value={run.template}
                                />
                                <ReviewRow
                                    label="Version"
                                    value={run.version}
                                />
                                <ReviewRow
                                    label="Run reference"
                                    value={run.id}
                                />
                                <ReviewRow
                                    label="Vehicle"
                                    value="Kōwhai van · VH-014"
                                />
                            </ReviewCard>
                            <ReviewCard
                                icon={ClockIcon}
                                title="Timing & author"
                            >
                                <ReviewRow
                                    label="Observed"
                                    value={localDateTimeLabel(run.observed)}
                                />
                                <ReviewRow
                                    label="Submitted"
                                    value={
                                        run.submitted + ' · Pacific/Auckland'
                                    }
                                />
                                <ReviewRow
                                    label="Recorded by"
                                    value="Alex Morgan · demo staff"
                                />
                            </ReviewCard>
                            <Notice title="Original evidence is preserved">
                                Later templates or repair notes do not change
                                these answers. Corrections retain their own
                                author and record.
                            </Notice>
                        </>
                    )}
                    {section === 2 && (
                        <>
                            <ReviewCard
                                icon={Wrench}
                                title="Maintenance follow-up"
                            >
                                <ReviewRow
                                    label="Relationship"
                                    value={
                                        linked
                                            ? 'Linked to an existing work record'
                                            : 'No follow-up link recorded in this preview'
                                    }
                                />
                                <p className="body-copy mt-3">
                                    Carry this exact check and version into the
                                    maintenance report. The Coordinator assesses
                                    the condition and decides the next action.
                                </p>
                            </ReviewCard>
                            <ReviewCard
                                icon={FileText}
                                title="Supporting evidence"
                            >
                                <ReviewRow
                                    label="Original evidence"
                                    value={
                                        run.id === 'CHK-0182'
                                            ? 'EV-DEMO-0182 · condition note'
                                            : 'No separate attachment recorded'
                                    }
                                />
                                <p className="body-copy mt-3">
                                    Files added to related work do not rewrite
                                    the submitted answers.
                                </p>
                            </ReviewCard>
                        </>
                    )}
                </div>
            </WizardStepPane>
        </WizardShell>
    );
}

export function ReportFlow({
    run,
    onClose,
    onDone,
    fail,
    searchFail,
    reportOnly,
}: {
    run?: Run;
    onClose: () => void;
    onDone: (id: string) => void;
    fail: boolean;
    searchFail: boolean;
    reportOnly: boolean;
}) {
    const [step, setStep] = useState(0),
        [title, setTitle] = useState(
            run
                ? 'Condition concern from vehicle check'
                : 'Vehicle condition concern',
        ),
        [notes, setNotes] = useState(
            run
                ? 'Please review the original check and assess the next action.'
                : 'Please assess this vehicle concern and the next action.',
        ),
        [link, setLink] = useState(''),
        [choice, setChoice] = useState('link'),
        [range, setRange] = useState<[string | null, string | null]>([
            null,
            null,
        ]),
        [showDates, setShowDates] = useState(false),
        [error, setError] = useState(''),
        [attempt, setAttempt] = useState(0),
        [saving, setSaving] = useState(false),
        [saved, setSaved] = useState(''),
        [discard, setDiscard] = useState(false);
    const options = [
        {
            id: 'WO-0264',
            name: 'Condition concern · WO-0264',
            detail: 'Kōwhai van · Open · Kōwhai House',
        },
        {
            id: 'WO-0268',
            name: 'Routine service · WO-0268',
            detail: 'Kōwhai van · Awaiting scheduling · Kōwhai House',
        },
    ];
    const reportDirty = Boolean(
        title !==
            (run
                ? 'Condition concern from vehicle check'
                : 'Vehicle condition concern') ||
        notes !==
            (run
                ? 'Please review the original check and assess the next action.'
                : 'Please assess this vehicle concern and the next action.') ||
        link ||
        showDates ||
        choice !== 'link',
    );
    const closeReport = () => {
        if (!saving) saved || !reportDirty ? onClose() : setDiscard(true);
    };
    const readableDate = (value: string) => {
        const [year, month, day] = value.split('-').map(Number);
        return new Date(year, month - 1, day).toLocaleDateString('en-NZ', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        });
    };
    const estimateSummary = !showDates
        ? 'Not known yet · no estimated dates will be recorded.'
        : !range[0]
          ? 'No dates selected. Choose the first day.'
          : !range[1]
            ? `From ${readableDate(range[0])} · choose the final day to finish the range.`
            : range[0] === range[1]
              ? `${readableDate(range[0])} · single calendar day`
              : `${readableDate(range[0])} – ${readableDate(range[1])} · both dates included`;
    useEffect(() => {
        if (error && step === 0) {
            const target = !title.trim()
                ? 'report-title'
                : showDates && (!range[0] || !range[1])
                  ? 'estimate-window'
                  : null;
            if (target) document.getElementById(target)?.focus();
        }
    }, [error, step]);
    const next = () => {
        if (
            step === 0 &&
            (!title.trim() || (showDates && (!range[0] || !range[1])))
        ) {
            setError(
                'Enter a title and finish the selected date range, or choose Not known yet.',
            );
            return;
        }
        if (step === 1 && !reportOnly && choice === 'link' && !link) {
            setError(
                'Choose an existing work record or choose Create a new report.',
            );
            return;
        }
        setError('');
        setStep(step + 1);
    };
    const submit = () => {
        if (saving) return;
        if (!title.trim() || (showDates && (!range[0] || !range[1]))) {
            setStep(0);
            setError(
                'Enter a title and finish the chosen range, or choose Not known yet.',
            );
            return;
        }
        if (!reportOnly && choice === 'link' && !link) {
            setStep(1);
            setError('Choose an existing work record or create a new report.');
            return;
        }
        setSaving(true);
        setError('');
        setTimeout(() => {
            setSaving(false);
            setAttempt((a) => a + 1);
            if (fail && attempt === 0) {
                setError(
                    'Confirmation was interrupted. Keep this draft and retry to recover the same result. Do not start another report.',
                );
                return;
            }
            setSaved(!reportOnly && choice === 'link' ? link : 'WO-DEMO-0269');
        }, 700);
    };
    return (
        <>
            <WizardShell
                open
                title="Create or link maintenance"
                maxWidth="min(92vw, 1100px)"
                description="Review a check concern with its original source"
                railIcon={Wrench}
                railTitle="Maintenance report"
                railSub={run ? `From ${run.id}` : 'From vehicle profile'}
                steps={[
                    {
                        key: 'details',
                        label: 'Report details',
                        blurb: 'Condition and optional dates',
                        icon: MessageSquare,
                    },
                    {
                        key: 'link',
                        label: 'Related work',
                        blurb: 'Avoid a duplicate job',
                        icon: Link2,
                    },
                    {
                        key: 'review',
                        label: 'Review',
                        blurb: 'Owner and original source',
                        icon: FileCheck2,
                    },
                ]}
                stepIndex={step}
                onStepClick={(i) => {
                    if (!saving) {
                        setError('');
                        setStep(i);
                    }
                }}
                pct={Math.round(
                    ([
                        Boolean(title.trim()),
                        Boolean(notes.trim()),
                        !showDates || Boolean(range[0] && range[1]),
                        reportOnly || choice === 'new' || Boolean(link),
                    ].filter(Boolean).length /
                        4) *
                        100,
                )}
                onClose={closeReport}
                footerStart={
                    <Button
                        variant="outline"
                        disabled={saving}
                        onClick={closeReport}
                    >
                        Cancel
                    </Button>
                }
                footerEnd={
                    <>
                        {step > 0 && (
                            <Button
                                variant="outline"
                                disabled={saving}
                                onClick={() => setStep(step - 1)}
                            >
                                Back
                            </Button>
                        )}
                        <Button
                            disabled={saving}
                            onClick={step < 2 ? next : submit}
                        >
                            {saving && (
                                <Loader2 className="h-4 w-4 animate-spin" />
                            )}
                            {saving
                                ? 'Confirming demo…'
                                : step < 2
                                  ? 'Continue'
                                  : attempt
                                    ? 'Retry and recover report'
                                    : 'Confirm report'}
                        </Button>
                    </>
                }
                success={
                    saved ? (
                        <WizardSuccessPane
                            title="Report linked in this preview"
                            blurb={
                                <>
                                    {saved} retains{' '}
                                    {run
                                        ? `the original check ${run.id}`
                                        : 'the original vehicle report'}
                                    . The site's Coordinator owns the next
                                    assessment. No safety release or financial
                                    approval has occurred.
                                </>
                            }
                            actions={
                                <Button onClick={() => onDone(saved)}>
                                    Review {saved}
                                    <ArrowRight size={16} />
                                </Button>
                            }
                        />
                    ) : undefined
                }
            >
                <WizardStepPane>
                    <div className="flow-stack">
                        {locked}
                        <div className="source-strip">
                            <Link2 size={15} />
                            <span>
                                {run ? (
                                    <>
                                        Original check <strong>{run.id}</strong>{' '}
                                        · {run.version} · Answers unchanged
                                    </>
                                ) : (
                                    'Vehicle profile · No check is attached to this manual report'
                                )}
                            </span>
                        </div>
                        {error && (
                            <Notice
                                title="Report not confirmed"
                                tone="critical"
                            >
                                {error}
                            </Notice>
                        )}
                        {step === 0 && (
                            <>
                                <div className="field">
                                    <label htmlFor="report-title">
                                        What needs attention?{' '}
                                        <span className="text-status-critical">
                                            *
                                        </span>
                                    </label>
                                    <Input
                                        id="report-title"
                                        aria-invalid={!!error && !title.trim()}
                                        value={title}
                                        onChange={(e) =>
                                            setTitle(e.target.value)
                                        }
                                    />
                                </div>
                                <div className="field">
                                    <label htmlFor="report-notes">
                                        Details for the Coordinator
                                    </label>
                                    <Textarea
                                        id="report-notes"
                                        value={notes}
                                        onChange={(e) =>
                                            setNotes(e.target.value)
                                        }
                                    />
                                </div>
                                <div
                                    className="field"
                                    id="estimate-window"
                                    role="group"
                                    tabIndex={-1}
                                    aria-labelledby="estimate-window-label"
                                    aria-describedby="estimate-instructions estimate-summary"
                                >
                                    <label id="estimate-window-label">
                                        Estimated maintenance window · optional
                                    </label>
                                    <div className="inline-actions">
                                        <Button
                                            variant={
                                                showDates
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            onClick={() => setShowDates(true)}
                                        >
                                            <CalendarDays size={16} />
                                            Choose dates
                                        </Button>
                                        <Button
                                            variant={
                                                !showDates
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            onClick={() => {
                                                setShowDates(false);
                                                setRange([null, null]);
                                            }}
                                        >
                                            Not known yet
                                        </Button>
                                    </div>
                                    {showDates && (
                                        <>
                                            <p
                                                id="estimate-instructions"
                                                className="muted"
                                            >
                                                Choose the first and final day.
                                                For one day, choose the same day
                                                twice. These are local calendar
                                                dates.
                                            </p>
                                            <LeaveCalendarRange
                                                start={range[0]}
                                                end={range[1]}
                                                month={new Date(2026, 8, 21)}
                                                required={false}
                                                onChange={(s, e) =>
                                                    setRange([s, e])
                                                }
                                            />
                                        </>
                                    )}
                                    <div
                                        id="estimate-summary"
                                        className="estimate-summary"
                                        role="status"
                                        aria-live="polite"
                                    >
                                        <CalendarDays size={17} />
                                        <span>{estimateSummary}</span>
                                    </div>
                                    <small className="muted">
                                        An estimate is advisory. It does not
                                        reserve the vehicle or book a provider.
                                    </small>
                                </div>
                            </>
                        )}
                        {step === 1 && (
                            <>
                                {reportOnly ? (
                                    <Notice title="Coordinator review required">
                                        You can report the concern. Choosing or
                                        merging existing work requires
                                        Maintenance manager access.
                                    </Notice>
                                ) : (
                                    <>
                                        <Notice
                                            title="Related work found"
                                            tone="warning"
                                        >
                                            Review the existing condition
                                            concern before creating another job.
                                        </Notice>
                                        <label className="choice">
                                            <input
                                                type="radio"
                                                name="link-mode"
                                                checked={choice === 'link'}
                                                onChange={() =>
                                                    setChoice('link')
                                                }
                                            />
                                            <span>
                                                <strong>
                                                    Link this report to existing
                                                    work
                                                </strong>
                                                <small>
                                                    Keep the new report and
                                                    original check as separate
                                                    evidence.
                                                </small>
                                            </span>
                                        </label>
                                        {choice === 'link' && (
                                            <Picker
                                                label="Maintenance work"
                                                value={link}
                                                onChange={setLink}
                                                options={options}
                                                fail={searchFail}
                                            />
                                        )}
                                        <label className="choice">
                                            <input
                                                type="radio"
                                                name="link-mode"
                                                checked={choice === 'new'}
                                                onChange={() =>
                                                    setChoice('new')
                                                }
                                            />
                                            <span>
                                                <strong>
                                                    Create a new report
                                                </strong>
                                                <small>
                                                    Use when this is a separate
                                                    concern. Coordinator review
                                                    still applies.
                                                </small>
                                            </span>
                                        </label>
                                    </>
                                )}
                            </>
                        )}
                        {step === 2 && (
                            <>
                                <ReviewCard
                                    icon={Wrench}
                                    title="Report"
                                    onEdit={() => setStep(0)}
                                >
                                    <ReviewRow label="Title" value={title} />
                                    <ReviewRow label="Details" value={notes} />
                                    <ReviewRow
                                        label="Estimated dates"
                                        value={estimateSummary}
                                    />
                                    <ReviewRow
                                        label="Original check"
                                        value={
                                            run
                                                ? `${run.id} · ${run.version}`
                                                : 'Manual vehicle report'
                                        }
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={Link2}
                                    title="Routing"
                                    onEdit={() => setStep(1)}
                                >
                                    <ReviewRow
                                        label="Work"
                                        value={
                                            !reportOnly && choice === 'link'
                                                ? link
                                                : 'New report for assessment'
                                        }
                                    />
                                    <ReviewRow
                                        label="Next owner"
                                        value="Kōwhai House Coordinator · demo mapping"
                                    />
                                    <ReviewRow
                                        label="Backup"
                                        value="Nominated site backup · demo mapping"
                                    />
                                </ReviewCard>
                                <Notice
                                    title="Reporting cannot authorise release"
                                    tone="warning"
                                >
                                    Reporting or linking a concern cannot
                                    release the vehicle. The example routing
                                    must be replaced by approved operational
                                    assignments.
                                </Notice>
                            </>
                        )}
                    </div>
                </WizardStepPane>
            </WizardShell>
            {discard && (
                <CloseGuard
                    onKeep={() => setDiscard(false)}
                    onDiscard={onClose}
                />
            )}
        </>
    );
}

type UploadItem = {
    file: File;
    status: 'staged' | 'uploading' | 'failed' | 'saved';
    id?: string;
};
export function UploadFlow({
    onClose,
    fail,
    work,
}: {
    onClose: () => void;
    fail: boolean;
    work: string;
}) {
    const [items, setItems] = useState<UploadItem[]>([]),
        [error, setError] = useState(''),
        [attempt, setAttempt] = useState(0),
        [discard, setDiscard] = useState(false);
    const add = (files: File[]) => {
        let rejected = false;
        const valid = files.filter((f) => {
            const ok =
                ['image/png', 'image/jpeg', 'application/pdf'].includes(
                    f.type,
                ) && f.size <= 10 * 1024 * 1024;
            rejected ||= !ok;
            return ok;
        });
        setItems((i) => [
            ...i,
            ...valid.map((file) => ({ file, status: 'staged' as const })),
        ]);
        setError(
            rejected
                ? 'Some files were rejected. Choose JPEG, PNG or PDF, up to 10 MiB per file. Your valid files are kept.'
                : '',
        );
    };
    const upload = () => {
        setItems((i) =>
            i.map((x) =>
                x.status === 'saved' ? x : { ...x, status: 'uploading' },
            ),
        );
        setTimeout(() => {
            setItems((i) =>
                i.map((x, n) =>
                    x.status === 'saved'
                        ? x
                        : fail && attempt === 0 && n === i.length - 1
                          ? { ...x, status: 'failed' }
                          : {
                                ...x,
                                status: 'saved',
                                id: `ATT-DEMO-${410 + n}`,
                            },
                ),
            );
            setAttempt((a) => a + 1);
        }, 750);
    };
    const pending = items.some((x) => x.status !== 'saved');
    const busy = items.some((x) => x.status === 'uploading');
    return (
        <>
            <Modal
                title="Add evidence"
                size="standard"
                icon={Upload}
                description={`${work} · Kōwhai van · Original evidence`}
                onClose={() => (pending ? setDiscard(true) : onClose())}
                footer={
                    <>
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={() =>
                                pending ? setDiscard(true) : onClose()
                            }
                        >
                            Close
                        </Button>
                        <Button onClick={upload} disabled={!pending || busy}>
                            {busy
                                ? 'Uploading demo…'
                                : items.some((x) => x.status === 'failed')
                                  ? 'Retry failed files'
                                  : 'Save evidence in preview'}
                        </Button>
                    </>
                }
            >
                <Notice title="Local demonstration">
                    Files stay in this page's memory. Nothing is transmitted.
                    Closing with unsaved files or reloading loses those
                    selections.
                </Notice>
                <div className="upload-title" id="evidence-label">
                    Evidence files
                </div>
                <FileDropzone
                    aria-labelledby="evidence-label"
                    onFiles={add}
                    disabled={busy}
                    accept="image/jpeg,image/png,application/pdf"
                    hint="JPEG, PNG or PDF · Up to 10 MiB per file · Maintenance evidence"
                />
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() =>
                        add([
                            new File(
                                ['Synthetic evidence only'],
                                'condition-evidence-demo.pdf',
                                { type: 'application/pdf' },
                            ),
                        ])
                    }
                >
                    Use a synthetic sample file
                </Button>
                {error && (
                    <Notice
                        title="File selection needs attention"
                        tone="critical"
                    >
                        {error}
                    </Notice>
                )}
                <div className="flow-stack">
                    {items.map((item, n) => (
                        <div
                            key={n}
                            className={
                                item.status === 'saved'
                                    ? 'saved-evidence'
                                    : undefined
                            }
                            role="group"
                            aria-label={item.file.name}
                        >
                            <StagedFileCard
                                file={item.file}
                                onRemove={() =>
                                    !busy &&
                                    item.status !== 'saved' &&
                                    setItems((i) => i.filter((_, j) => j !== n))
                                }
                            >
                                <div className="inline-actions">
                                    <Badge
                                        tone={
                                            item.status === 'saved'
                                                ? 'success'
                                                : item.status === 'failed'
                                                  ? 'critical'
                                                  : 'neutral'
                                        }
                                    >
                                        {item.status === 'saved'
                                            ? 'Saved in demo'
                                            : item.status === 'failed'
                                              ? 'Upload failed'
                                              : item.status === 'uploading'
                                                ? 'Uploading demo'
                                                : 'Selected · not saved'}
                                    </Badge>
                                    {item.id && <span>{item.id}</span>}
                                </div>
                                {item.status === 'saved' && (
                                    <small className="muted">
                                        Original selection retained as
                                        demonstration evidence. Saved evidence
                                        cannot be removed here.
                                    </small>
                                )}
                            </StagedFileCard>
                        </div>
                    ))}
                </div>
                {items.some((x) => x.status === 'failed') && (
                    <Notice title="Some evidence needs retry" tone="warning">
                        Confirmed files are kept. Retry only the files that
                        failed.
                    </Notice>
                )}
            </Modal>
            {discard && (
                <CloseGuard
                    onKeep={() => setDiscard(false)}
                    onDiscard={onClose}
                />
            )}
        </>
    );
}
