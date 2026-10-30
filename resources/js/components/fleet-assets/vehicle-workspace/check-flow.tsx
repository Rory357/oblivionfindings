import {
    DateTimeField,
    localDateTimeLabel,
    validLocalDateTime,
} from '@/components/fleet-assets/maintenance/date-time-field';
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
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import {
    ClipboardCheck,
    FileCheck2,
    Loader2,
    MessageSquare,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { useVehicleChecks } from './checks-data';
import { ChecksLoadingDialog, DraftGuard, LockedVehicle } from './checks-kit';
import {
    answerLabel,
    keepAnswers,
    localNow,
    missingAnswers,
    outcomeLabel,
    recordsIssue,
    vehicleShort,
    versionLabel,
    type CheckAnswers,
} from './checks-model';
import type { CheckQuestion, VehicleChecks } from './checks-types';
import { isJsonObject, useVehicleRecordCommand } from './record-command';
import { ReportProblemDialog, type ReportSource } from './report-problem';
import { VehicleSearchSelect } from './search-select';
import type { VehicleWorkspace } from './types';
import { StagedFilesField, StudioNotice } from './wizard-kit';

const STEPS = [
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
];

type SavedCheck = ReportSource & {
    outcome: string;
    issue: boolean;
    filesNote: string;
};

/**
 * Start a vehicle check (the header's Start check, Readiness and the Checks
 * view). Loads the vehicle's checklists when none are passed in, records the
 * check through the Maintenance check rules and can go straight on to report
 * a recorded issue.
 */
export function CheckFlowDialog({
    workspace,
    checks,
    templateId,
    onClose,
    onChanged,
}: {
    workspace: VehicleWorkspace;
    /** The Checks view passes its loaded data; other callers let the dialog load it. */
    checks?: VehicleChecks | null;
    /** The checklist to start with; defaults to the vehicle's check requirement. */
    templateId?: number | null;
    onClose: () => void;
    /** A check was recorded (refresh the workspace and any checks view). */
    onChanged: () => void;
}) {
    const own = useVehicleChecks(workspace.vehicle.id, '', !checks);
    const data = checks ?? own.data;
    const [reportFor, setReportFor] = useState<ReportSource | null>(null);

    if (reportFor)
        return (
            <ReportProblemDialog
                workspace={workspace}
                checks={data}
                source={reportFor}
                onClose={onClose}
                onChanged={onChanged}
            />
        );
    if (!data)
        return (
            <ChecksLoadingDialog
                title="Start vehicle check"
                load={own.load}
                onRetry={own.reload}
                onClose={onClose}
            />
        );
    if (!data.can.start || !data.templates.length)
        return (
            <Dialog open onOpenChange={(next) => !next && onClose()}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Start vehicle check</DialogTitle>
                        <DialogDescription>
                            {!data.can.start
                                ? 'Recording a check for this vehicle needs Maintenance access at its site.'
                                : 'No checklist is available for this vehicle yet. A Maintenance manager can create one in Checks & inspections › Templates.'}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={onClose}>
                            Close
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        );

    return (
        <CheckFlow
            workspace={workspace}
            checks={data}
            initialTemplateId={
                templateId ?? data.requirement.template_id ?? undefined
            }
            onClose={onClose}
            onSaved={() => {
                if (!checks) own.reload();
                onChanged();
            }}
            onReload={() => {
                if (!checks) own.reload();
                onChanged();
            }}
            onReport={
                data.can.report ? (source) => setReportFor(source) : undefined
            }
        />
    );
}

function CheckFlow({
    workspace,
    checks,
    initialTemplateId,
    onClose,
    onSaved,
    onReload,
    onReport,
}: {
    workspace: VehicleWorkspace;
    checks: VehicleChecks;
    initialTemplateId?: number;
    onClose: () => void;
    onSaved: () => void;
    onReload: () => void;
    onReport?: (source: ReportSource) => void;
}) {
    const { vehicle } = workspace;
    const templates = checks.templates;
    const initial =
        templates.find((template) => template.id === initialTemplateId) ??
        templates[0];
    const [initialObserved] = useState(localNow);
    const [step, setStep] = useState(0);
    const [templateId, setTemplateId] = useState(initial.id);
    const [observed, setObserved] = useState(initialObserved);
    const [answers, setAnswers] = useState<CheckAnswers>({});
    const [notes, setNotes] = useState('');
    const [files, setFiles] = useState<File[]>([]);
    const [error, setError] = useState('');
    const [showMissing, setShowMissing] = useState(false);
    const [saved, setSaved] = useState<SavedCheck | null>(null);
    const [discard, setDiscard] = useState(false);
    const command = useVehicleRecordCommand(isJsonObject);
    const current =
        templates.find((template) => template.id === templateId) ?? initial;
    const questions = current.questions;
    const missing =
        missingAnswers(questions, answers).length > 0 ||
        (current.evidence_required && !files.length) ||
        (files.length > 0 && !checks.can.upload);
    const dirty =
        templateId !== initial.id ||
        observed !== initialObserved ||
        Object.values(answers).some(Boolean) ||
        Boolean(notes.trim()) ||
        files.length > 0;
    const serverError = (key: string) => command.errors[key];
    const evidenceError =
        serverError('files') ??
        Object.entries(command.errors).find(([key]) =>
            key.startsWith('files.'),
        )?.[1];

    // A reload after a checklist change keeps the answers that still apply.
    useEffect(() => {
        setAnswers((kept) => keepAnswers(current.questions, kept));
    }, [current.items_sha256, current.questions]);

    // Server errors return to the step that holds the field.
    useEffect(() => {
        const keys = Object.keys(command.errors);
        if (!keys.length) return;
        if (keys.some((key) => ['observed_local', 'template_id'].includes(key)))
            setStep(0);
        else if (
            keys.some(
                (key) => key.startsWith('answers') || key.startsWith('files'),
            )
        ) {
            setShowMissing(true);
            setStep(1);
        }
    }, [command.errors]);

    const close = () => {
        if (command.processing) return;
        if (saved || !dirty) onClose();
        else setDiscard(true);
    };

    const focusFirstMissing = () => {
        const first = missingAnswers(questions, answers)[0];
        window.setTimeout(
            () =>
                document
                    .getElementById(first ? `answer-${first}` : 'check-files')
                    ?.focus(),
            0,
        );
    };

    const advance = () => {
        if (step === 0 && !validLocalDateTime(observed)) {
            setError('Choose a complete observation date and time.');
            return;
        }
        if (step === 1 && missing) {
            setError(
                'Complete required answers and required evidence before review.',
            );
            setShowMissing(true);
            focusFirstMissing();
            return;
        }
        setError('');
        setStep(step + 1);
    };

    const submit = async () => {
        if (command.processing) return;
        if (!command.uncertain) {
            if (!validLocalDateTime(observed)) {
                setStep(0);
                setError('Choose a complete observation date and time.');
                return;
            }
            if (missing) {
                setStep(1);
                setShowMissing(true);
                setError(
                    'Complete required answers and required evidence before submitting.',
                );
                return;
            }
        }
        setError('');
        const body = new FormData();
        body.append('template_id', String(current.id));
        if (current.version_id)
            body.append('template_version_id', String(current.version_id));
        body.append('items_sha256', current.items_sha256);
        body.append('observed_local', observed);
        questions.forEach((question) => {
            const value = answers[question.id]?.trim();
            if (value) body.append(`answers[${question.id}]`, value);
        });
        if (notes.trim()) body.append('notes', notes.trim());
        if (current.rule_version_id)
            body.append('rule_version_id', String(current.rule_version_id));
        files.forEach((file) => body.append('files[]', file));
        const result = await command.submit(
            `/fleet-assets/vehicles/${vehicle.id}/checks`,
            body,
        );
        if (!result || !isJsonObject(result.run)) return;
        const run = result.run;
        const states = Array.isArray(result.files)
            ? result.files.map((file) =>
                  isJsonObject(file) ? String(file.state) : '',
              )
            : [];
        const waiting = states.filter((state) => state !== 'available').length;
        setSaved({
            id: Number(run.id),
            reference: String(run.reference),
            template: current.name,
            version: current.version,
            outcome: String(run.outcome),
            issue: recordsIssue(questions, answers),
            filesNote: !states.length
                ? ''
                : waiting
                  ? ` ${states.length - waiting} of ${states.length} files are available; the rest are stored privately and waiting for a virus check.`
                  : ` ${states.length} ${states.length === 1 ? 'file is' : 'files are'} kept with the check.`,
        });
        onSaved();
    };

    const setAnswer = (question: CheckQuestion, value: string) => {
        setAnswers((all) => ({ ...all, [question.id]: value }));
        command.clearError(`answers.${question.id}`);
    };

    const requiredCount = questions.filter(
        (question) => question.required,
    ).length;
    const pct = Math.round(
        ([
            !!templateId,
            validLocalDateTime(observed),
            ...questions
                .filter((question) => question.required)
                .map((question) => !!answers[question.id]?.trim()),
            ...(current.evidence_required ? [files.length > 0] : []),
        ].filter(Boolean).length /
            (2 + requiredCount + Number(current.evidence_required))) *
            100,
    );
    const banner = error || command.message;

    return (
        <>
            <WizardShell
                open
                title="Start vehicle check"
                maxWidth="min(92vw, 1100px)"
                description="Record a vehicle check against its checklist version"
                railIcon={ClipboardCheck}
                railTitle="Vehicle check"
                railSub={vehicleShort(vehicle)}
                steps={STEPS}
                stepIndex={step}
                onStepClick={(index) => {
                    if (!command.locked) {
                        setError('');
                        setStep(index);
                    }
                }}
                pct={pct}
                onClose={close}
                footerStart={
                    <Button
                        variant="outline"
                        disabled={command.processing}
                        onClick={close}
                    >
                        Cancel
                    </Button>
                }
                footerEnd={
                    <>
                        {step > 0 && !command.requiresReload && (
                            <Button
                                variant="outline"
                                disabled={command.locked}
                                onClick={() => setStep(step - 1)}
                            >
                                Back
                            </Button>
                        )}
                        {command.requiresReload ? (
                            <Button
                                onClick={() => {
                                    command.reset();
                                    setError('');
                                    setStep(0);
                                    onReload();
                                }}
                            >
                                Review latest checklist
                            </Button>
                        ) : (
                            <Button
                                disabled={command.processing}
                                onClick={
                                    step < 2 && !command.uncertain
                                        ? advance
                                        : submit
                                }
                            >
                                {command.processing && (
                                    <Loader2 className="size-4 animate-spin" />
                                )}
                                {command.processing
                                    ? 'Saving…'
                                    : step < 2 && !command.uncertain
                                      ? 'Continue'
                                      : command.uncertain
                                        ? 'Retry submission'
                                        : 'Submit check'}
                            </Button>
                        )}
                    </>
                }
                success={
                    saved ? (
                        <WizardSuccessPane
                            title="Check recorded"
                            blurb={
                                <>
                                    <strong>
                                        {saved.reference} ·{' '}
                                        {outcomeLabel(saved.outcome)}
                                    </strong>
                                    <br />
                                    Original answers and template{' '}
                                    {versionLabel(
                                        saved.version,
                                    ).toLowerCase()}{' '}
                                    are retained. This does not release the
                                    vehicle.
                                    {saved.filesNote}
                                </>
                            }
                            actions={
                                <>
                                    <Button variant="outline" onClick={onClose}>
                                        Back to vehicle
                                    </Button>
                                    {onReport &&
                                        (saved.outcome === 'failed' ||
                                            saved.issue) && (
                                            <Button
                                                onClick={() => onReport(saved)}
                                            >
                                                Create or link maintenance
                                            </Button>
                                        )}
                                </>
                            }
                        />
                    ) : undefined
                }
            >
                <WizardStepPane key={step}>
                    <div className="vehicle-studio">
                        <div className="flow-stack">
                            <LockedVehicle vehicle={vehicle} />
                            {banner && (
                                <StudioNotice
                                    title="Check not saved"
                                    tone="critical"
                                >
                                    {banner}
                                </StudioNotice>
                            )}
                            {/* A retry resends this exact draft, so it stays locked until settled. */}
                            <fieldset
                                disabled={command.locked}
                                className="flow-stack min-w-0"
                            >
                                {step === 0 && (
                                    <>
                                        <div className="field">
                                            <label htmlFor="check-template">
                                                Checklist template
                                            </label>
                                            <VehicleSearchSelect
                                                id="check-template"
                                                label="Checklist template"
                                                value={String(templateId)}
                                                invalid={
                                                    !!serverError('template_id')
                                                }
                                                options={templates.map(
                                                    (template) => ({
                                                        value: String(
                                                            template.id,
                                                        ),
                                                        label: template.name,
                                                        description: `${versionLabel(template.version)} · ${template.assignment_label}`,
                                                    }),
                                                )}
                                                onChange={(value) => {
                                                    const id = Number(value);
                                                    if (
                                                        !id ||
                                                        id === templateId
                                                    )
                                                        return;
                                                    setTemplateId(id);
                                                    setAnswers({});
                                                    command.clearError(
                                                        'template_id',
                                                    );
                                                }}
                                            />
                                            {serverError('template_id') && (
                                                <p
                                                    role="alert"
                                                    className="text-xs text-status-critical"
                                                >
                                                    {serverError('template_id')}
                                                </p>
                                            )}
                                        </div>
                                        <DateTimeField
                                            id="check-observed"
                                            label="Observed at (required)"
                                            value={observed}
                                            onChange={(value) => {
                                                setObserved(value);
                                                command.clearError(
                                                    'observed_local',
                                                );
                                            }}
                                            error={serverError(
                                                'observed_local',
                                            )}
                                        />
                                        {!current.rule_version_id && (
                                            <StudioNotice
                                                title="Approved rules unavailable"
                                                tone="warning"
                                            >
                                                Answers can be recorded for
                                                assessment. They cannot produce
                                                a Passed or Ready result, and
                                                the vehicle can’t be booked
                                                until Maintenance assesses the
                                                check.
                                            </StudioNotice>
                                        )}
                                    </>
                                )}
                                {step === 1 && (
                                    <>
                                        <div className="section-intro">
                                            <h3 className="text-section-title">
                                                {current.name}
                                            </h3>
                                            <StatusBadge variant="info">
                                                {versionLabel(current.version)}
                                            </StatusBadge>
                                        </div>
                                        {questions.map((question) => (
                                            <AnswerField
                                                key={question.id}
                                                question={question}
                                                value={
                                                    answers[question.id] ?? ''
                                                }
                                                onChange={(value) =>
                                                    setAnswer(question, value)
                                                }
                                                error={
                                                    serverError(
                                                        `answers.${question.id}`,
                                                    ) ??
                                                    (showMissing &&
                                                    question.required &&
                                                    !answers[
                                                        question.id
                                                    ]?.trim()
                                                        ? question.kind ===
                                                          'condition'
                                                            ? 'Choose an answer, including Unable to assess when appropriate.'
                                                            : 'Answer this required question.'
                                                        : undefined)
                                                }
                                            />
                                        ))}
                                        <div className="field">
                                            <label htmlFor="check-note">
                                                Observation notes
                                            </label>
                                            <Textarea
                                                id="check-note"
                                                value={notes}
                                                maxLength={5000}
                                                onChange={(event) =>
                                                    setNotes(event.target.value)
                                                }
                                                placeholder="Add the relevant observation…"
                                            />
                                        </div>
                                        {checks.can.upload ? (
                                            <div id="check-files" tabIndex={-1}>
                                                <StagedFilesField
                                                    label={
                                                        current.evidence_required
                                                            ? 'Check photos & documents (required)'
                                                            : 'Check photos & documents'
                                                    }
                                                    files={files}
                                                    optional={false}
                                                    onChange={(next) => {
                                                        setFiles(next);
                                                        command.clearError(
                                                            'files',
                                                        );
                                                    }}
                                                    error={
                                                        evidenceError ??
                                                        (showMissing &&
                                                        current.evidence_required &&
                                                        !files.length
                                                            ? 'Add at least one photo or document before submitting this check.'
                                                            : undefined)
                                                    }
                                                />
                                            </div>
                                        ) : (
                                            <StudioNotice
                                                title={
                                                    current.evidence_required
                                                        ? 'Evidence needs document access'
                                                        : 'Check photos & documents'
                                                }
                                                tone={
                                                    current.evidence_required
                                                        ? 'warning'
                                                        : 'info'
                                                }
                                            >
                                                {current.evidence_required
                                                    ? 'This checklist needs at least one photo or document. Adding files needs vehicle document access, so ask a fleet manager to record this check.'
                                                    : 'Adding files needs vehicle document access. You can record the answers without files.'}
                                            </StudioNotice>
                                        )}
                                        <p className="muted">
                                            Evidence from a failed check can be
                                            attached to the linked Maintenance
                                            record. A new attachment does not
                                            change the submitted answers.
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
                                                value={`${current.name} · ${versionLabel(current.version)}`}
                                            />
                                            <ReviewRow
                                                label="Observed"
                                                value={localDateTimeLabel(
                                                    observed,
                                                )}
                                            />
                                            <ReviewRow
                                                label="Record"
                                                value={vehicleShort(vehicle)}
                                            />
                                        </ReviewCard>
                                        <ReviewCard
                                            icon={MessageSquare}
                                            title="Answers"
                                            onEdit={() => setStep(1)}
                                        >
                                            {questions.map((question) => (
                                                <ReviewRow
                                                    key={question.id}
                                                    label={question.label}
                                                    value={answerLabel(
                                                        question,
                                                        answers[question.id],
                                                    )}
                                                />
                                            ))}
                                            <ReviewRow
                                                label="Notes"
                                                value={notes.trim()}
                                            />
                                            <ReviewRow
                                                label="Attachments"
                                                value={
                                                    files
                                                        .map(
                                                            (file) => file.name,
                                                        )
                                                        .join(', ') ||
                                                    'None attached'
                                                }
                                            />
                                        </ReviewCard>
                                        <StudioNotice title="Submission records the check">
                                            Assessment and any restriction or
                                            release remain separate. The
                                            submission never clears a
                                            restriction.
                                        </StudioNotice>
                                    </>
                                )}
                            </fieldset>
                        </div>
                    </div>
                </WizardStepPane>
            </WizardShell>
            <DraftGuard
                open={discard}
                onKeep={() => setDiscard(false)}
                onDiscard={() => {
                    setDiscard(false);
                    onClose();
                }}
            />
        </>
    );
}

/** One question: a choice for conditions and lists, text or a number otherwise. */
function AnswerField({
    question,
    value,
    onChange,
    error,
}: {
    question: CheckQuestion;
    value: string;
    onChange: (value: string) => void;
    error?: string;
}) {
    const id = `answer-${question.id}`;
    const describedBy = error ? `${id}-error` : undefined;
    return (
        <div className="field">
            <label htmlFor={id}>
                {question.label}{' '}
                {question.required && (
                    <span className="text-status-critical" aria-hidden>
                        *
                    </span>
                )}
            </label>
            {question.options.length ? (
                <select
                    id={id}
                    value={value}
                    required={question.required}
                    aria-invalid={!!error}
                    aria-describedby={describedBy}
                    onChange={(event) => onChange(event.target.value)}
                >
                    <option value="">Choose an answer</option>
                    {question.options.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </select>
            ) : (
                <Input
                    id={id}
                    type={question.kind === 'number' ? 'number' : 'text'}
                    inputMode={
                        question.kind === 'number' ? 'decimal' : undefined
                    }
                    maxLength={question.kind === 'number' ? undefined : 2000}
                    value={value}
                    required={question.required}
                    aria-invalid={!!error}
                    aria-describedby={describedBy}
                    onChange={(event) => onChange(event.target.value)}
                />
            )}
            {error && (
                <p id={`${id}-error`} className="text-xs text-status-critical">
                    {error}
                </p>
            )}
        </div>
    );
}
