import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import {
    ArrowDown,
    ArrowUp,
    ClipboardCheck,
    FileCheck2,
    ListChecks,
    Loader2,
    Plus,
    Settings2,
    Trash2,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    assignmentOptions,
    draftFromTemplate,
    kindLabel,
    newQuestion,
    questionSummary,
    templateDraftError,
    USE_PRESETS,
    versionLabel,
    type DraftQuestion,
    type TemplateDraft,
} from './checks-model';
import type {
    CheckAssignment,
    CheckQuestion,
    CheckQuestionKind,
    CheckTemplate,
    VehicleChecks,
} from './checks-types';
import {
    useVehicleCollectionView,
    VehicleCollectionToggle,
    VehicleRecordCollection,
} from './record-collection';
import { isJsonObject, useVehicleRecordCommand } from './record-command';
import { VehicleSearchSelect } from './search-select';
import { SourceRecordDialog } from './studio-kit';
import type { VehicleProfile } from './types';
import { StudioNotice } from './wizard-kit';

/**
 * The controlled check-template library: search, Create checklist, and per
 * checklist Preview, Customise (publishes the next version) and Use checklist.
 */
export function ChecklistLibrary({
    vehicle,
    checks,
    onRun,
    onChanged,
}: {
    vehicle: VehicleProfile;
    checks: VehicleChecks;
    onRun: (templateId: number) => void;
    onChanged: () => void;
}) {
    const { can, templates } = checks;
    const canManage = can.manage_templates;
    // A checklist other vehicles use is a fleet-wide setting.
    const canCustomise = (template: CheckTemplate) =>
        canManage &&
        (can.manage_shared_templates || template.assignment === 'vehicle');
    const canRun = can.start;
    const [edit, setEdit] = useState<CheckTemplate | null | undefined>();
    const [preview, setPreview] = useState<CheckTemplate | null>(null);
    const [query, setQuery] = useState('');
    const layout = useVehicleCollectionView('checklist-templates');
    const shown = templates.filter((template) =>
        template.name.toLowerCase().includes(query.trim().toLowerCase()),
    );

    return (
        <>
            <div className="library-toolbar">
                <Input
                    aria-label="Search checklist library"
                    placeholder="Search checklists…"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                />
                <VehicleCollectionToggle
                    label="Checklist templates"
                    view={layout.view}
                    onChange={layout.setView}
                />
                <Button disabled={!canManage} onClick={() => setEdit(null)}>
                    <Plus className="size-4" />
                    Create checklist
                </Button>
            </div>
            <p className="studio-footnote">
                Universal vehicle library · Managed with Maintenance checklists.
                Changes publish a new version; submitted checks keep their
                original questions and evidence.
            </p>
            <VehicleRecordCollection
                label="Checklist templates"
                view={layout.view}
                columns={[
                    { label: 'When to use', width: '1.1fr' },
                    { label: 'Coverage & evidence', width: '1.1fr' },
                    { label: 'Actions', width: '1.5fr' },
                ]}
                empty={
                    templates.length
                        ? {
                              title: 'No matching checklists',
                              description: 'Try another checklist name.',
                              action: (
                                  <Button
                                      variant="outline"
                                      onClick={() => setQuery('')}
                                  >
                                      Clear search
                                  </Button>
                              ),
                          }
                        : {
                              title: 'No checklists yet',
                              description:
                                  'Create a checklist to start recording vehicle checks.',
                              action: canManage ? (
                                  <Button onClick={() => setEdit(null)}>
                                      <Plus className="size-4" />
                                      Create checklist
                                  </Button>
                              ) : undefined,
                          }
                }
                records={shown.map((template) => ({
                    id: template.id,
                    name: template.name,
                    subline: versionLabel(template.version),
                    icon: ListChecks,
                    fields: [
                        <>
                            <strong>{template.use}</strong>
                            <small>
                                {template.questions.length}{' '}
                                {template.questions.length === 1
                                    ? 'question'
                                    : 'questions'}
                            </small>
                        </>,
                        <>
                            <span>{template.assignment_label}</span>
                            <small>
                                Evidence{' '}
                                {template.evidence_required
                                    ? 'required'
                                    : 'optional'}
                            </small>
                        </>,
                        <div className="collection-actions">
                            <Button
                                variant="outline"
                                onClick={() => setPreview(template)}
                            >
                                Preview
                            </Button>
                            <Button
                                variant="outline"
                                disabled={!canCustomise(template)}
                                onClick={() => setEdit(template)}
                            >
                                <Settings2 className="size-[14px]" />
                                Customise
                            </Button>
                            <Button
                                disabled={!canRun}
                                onClick={() => onRun(template.id)}
                            >
                                Use checklist
                            </Button>
                        </div>,
                    ],
                    onOpen: () => setPreview(template),
                    footer: {
                        primary: versionLabel(template.version),
                        secondary: 'Published version · originals retained',
                    },
                    actions: [
                        {
                            label: 'Preview checklist',
                            icon: ListChecks,
                            onClick: () => setPreview(template),
                        },
                        ...(canCustomise(template)
                            ? [
                                  {
                                      label: 'Customise checklist',
                                      icon: Settings2,
                                      onClick: () => setEdit(template),
                                  },
                              ]
                            : []),
                        ...(canRun
                            ? [
                                  {
                                      label: 'Use checklist',
                                      icon: ListChecks,
                                      onClick: () => onRun(template.id),
                                  },
                              ]
                            : []),
                    ],
                }))}
            />
            {preview && (
                <SourceRecordDialog
                    title={preview.name}
                    description={`Checklist library · ${versionLabel(preview.version)}`}
                    rows={[
                        ['Version', versionLabel(preview.version)],
                        ['Use', preview.use],
                        ['Assignment', preview.assignment_label],
                        [
                            'Evidence',
                            preview.evidence_required ? 'Required' : 'Optional',
                        ],
                        [
                            'Approved check rules',
                            preview.rule_version_id
                                ? `Cover this version at ${vehicle.site?.name ?? 'this site'}`
                                : 'Not covered at this site · checks are recorded for assessment',
                        ],
                        ...preview.questions.map(
                            (question, index) =>
                                [
                                    `${index + 1}. ${question.label}`,
                                    questionSummary(question),
                                ] as [string, string],
                        ),
                    ]}
                    onClose={() => setPreview(null)}
                />
            )}
            {edit !== undefined && (
                <TemplateEditor
                    vehicle={vehicle}
                    checks={checks}
                    original={edit}
                    onClose={() => setEdit(undefined)}
                    onSaved={onChanged}
                />
            )}
        </>
    );
}

const EDITOR_STEPS = [
    {
        key: 'details',
        label: 'Details',
        blurb: 'Purpose and reuse',
        icon: ClipboardCheck,
    },
    {
        key: 'questions',
        label: 'Questions',
        blurb: 'Order, answer types and evidence',
        icon: ListChecks,
    },
    {
        key: 'review',
        label: 'Review version',
        blurb: 'Preserve earlier submissions',
        icon: FileCheck2,
    },
];

const NEW_KINDS: Array<{ value: CheckQuestionKind; label: string }> = [
    {
        value: 'condition',
        label: 'Condition · No issue / Issue / Unable to assess',
    },
    { value: 'text', label: 'Written observation' },
    { value: 'number', label: 'Number / reading' },
];

/**
 * Create a checklist or publish its next version. Publishing never rewrites
 * an earlier version or a submitted check.
 */
function TemplateEditor({
    vehicle,
    checks,
    original,
    onClose,
    onSaved,
}: {
    vehicle: VehicleProfile;
    checks: VehicleChecks;
    original: CheckTemplate | null;
    onClose: () => void;
    onSaved: () => void;
}) {
    // Checklists used beyond this vehicle are fleet-wide settings; without
    // that authority a new checklist is for this vehicle only.
    const shared = checks.can.manage_shared_templates;
    const [initial] = useState(() => {
        const base = draftFromTemplate(original);
        return shared ? base : { ...base, assignment: 'vehicle' as const };
    });
    const [draft, setDraft] = useState<TemplateDraft>(initial);
    const [step, setStep] = useState(0);
    const [error, setError] = useState('');
    const [published, setPublished] = useState<number | null>(null);
    const [discard, setDiscard] = useState(false);
    const command = useVehicleRecordCommand(isJsonObject);
    // Choice-list and yes/no questions carried over from an existing checklist.
    const carried = useMemo(
        () =>
            new Map(
                (original?.questions ?? [])
                    .filter((question) =>
                        ['select', 'checkbox'].includes(question.kind),
                    )
                    .map((question): [string, CheckQuestion] => [
                        question.id,
                        question,
                    ]),
            ),
        [original],
    );
    const dirty = JSON.stringify(draft) !== JSON.stringify(initial);
    const useOptions = useMemo(() => {
        const seen = new Set<string>();
        return [
            ...USE_PRESETS,
            ...checks.templates.map((template) => template.use),
            draft.use,
        ]
            .filter((option) => {
                const key = option.trim().toLowerCase();
                if (!key || seen.has(key)) return false;
                seen.add(key);
                return true;
            })
            .map((option) => ({ value: option, label: option }));
    }, [checks.templates, draft.use]);
    const assignment = assignmentOptions(vehicle).filter(
        (option) => shared || option.value === 'vehicle',
    );
    const serverError =
        Object.entries(command.errors).find(([key]) =>
            ['name', 'use', 'assignment', 'questions', 'confirmed'].some(
                (field) => key === field || key.startsWith(`${field}.`),
            ),
        )?.[1] ?? '';
    const banner = error || serverError || command.message;

    const touch = (patch: Partial<TemplateDraft>) => {
        setDraft((current) => ({ ...current, ...patch }));
        setError('');
    };
    const change = (id: string, patch: Partial<DraftQuestion>) =>
        touch({
            questions: draft.questions.map((question) =>
                question.id === id ? { ...question, ...patch } : question,
            ),
        });
    const move = (from: number, to: number) => {
        const next = [...draft.questions];
        [next[from], next[to]] = [next[to], next[from]];
        touch({ questions: next });
    };
    const close = () => {
        if (command.processing) return;
        if (published !== null || !dirty) onClose();
        else setDiscard(true);
    };

    const submit = async () => {
        const problem = templateDraftError(draft);
        if (problem && !command.uncertain) {
            setError(problem);
            return;
        }
        setError('');
        const result = await command.submit(
            original
                ? `/fleet-assets/vehicles/${vehicle.id}/check-templates/${original.id}/versions`
                : `/fleet-assets/vehicles/${vehicle.id}/check-templates`,
            {
                name: draft.name.trim(),
                use: draft.use.trim(),
                assignment: draft.assignment,
                evidence_required: draft.evidence,
                questions: draft.questions.map((question) => ({
                    id: question.id,
                    label: question.label.trim(),
                    kind: question.kind,
                    required: question.required,
                    options:
                        question.kind === 'select' ? question.options : null,
                })),
                confirmed: draft.confirmed,
                ...(original
                    ? {
                          expected_version_id: original.version_id,
                          expected_items_sha256: original.items_sha256,
                      }
                    : {}),
            },
        );
        if (!result || !isJsonObject(result.template)) return;
        setPublished(Number(result.template.version));
        onSaved();
    };

    return (
        <>
            <WizardShell
                open
                title={
                    original
                        ? 'Customise vehicle checklist'
                        : 'Create vehicle checklist'
                }
                description="Reusable questions and controlled versions"
                railIcon={ClipboardCheck}
                railTitle="Checklist library"
                railSub="Maintenance · vehicle templates"
                maxWidth="min(92vw,1100px)"
                steps={EDITOR_STEPS}
                stepIndex={step}
                onStepClick={(index) => {
                    if (!command.locked) setStep(index);
                }}
                pct={Math.round(
                    ([
                        !!draft.name.trim(),
                        draft.questions.every(
                            (question) => !!question.label.trim(),
                        ),
                        draft.confirmed,
                    ].filter(Boolean).length /
                        3) *
                        100,
                )}
                success={
                    published !== null ? (
                        <WizardSuccessPane
                            title="Checklist version published"
                            blurb={`${versionLabel(published)} is available to new checks. Earlier submissions retain their original version and answers.`}
                            actions={
                                <Button onClick={onClose}>
                                    Back to library
                                </Button>
                            }
                        />
                    ) : undefined
                }
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
                    command.requiresReload ? (
                        <Button
                            onClick={() => {
                                onSaved();
                                onClose();
                            }}
                        >
                            Review latest version
                        </Button>
                    ) : (
                        <>
                            <Button
                                variant="outline"
                                disabled={step === 0 || command.locked}
                                onClick={() => setStep(step - 1)}
                            >
                                Back
                            </Button>
                            <Button
                                disabled={command.processing}
                                onClick={() => {
                                    setError('');
                                    if (step < 2 && !command.uncertain) {
                                        if (step === 0 && !draft.name.trim()) {
                                            setError('Name the checklist.');
                                            return;
                                        }
                                        setStep(step + 1);
                                        return;
                                    }
                                    void submit();
                                }}
                            >
                                {command.processing && (
                                    <Loader2 className="size-4 animate-spin" />
                                )}
                                {command.processing
                                    ? 'Publishing…'
                                    : step < 2 && !command.uncertain
                                      ? 'Continue'
                                      : command.uncertain
                                        ? 'Retry publishing'
                                        : 'Publish version'}
                            </Button>
                        </>
                    )
                }
            >
                <WizardStepPane key={step}>
                    <div className="vehicle-studio">
                        <div className="flow-stack">
                            {banner && (
                                <StudioNotice
                                    title="Version not published"
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
                                            <label htmlFor="template-name">
                                                Checklist name
                                            </label>
                                            <Input
                                                id="template-name"
                                                value={draft.name}
                                                maxLength={255}
                                                aria-invalid={
                                                    !!command.errors.name
                                                }
                                                onChange={(event) =>
                                                    touch({
                                                        name: event.target
                                                            .value,
                                                    })
                                                }
                                            />
                                        </div>
                                        <div className="field">
                                            <label htmlFor="template-use">
                                                Used for
                                            </label>
                                            <VehicleSearchSelect
                                                id="template-use"
                                                label="Used for"
                                                value={draft.use}
                                                options={useOptions}
                                                onChange={(value) =>
                                                    touch({ use: value })
                                                }
                                                onAdd={(typed) => {
                                                    const value = typed
                                                        .trim()
                                                        .replace(/\s+/g, ' ')
                                                        .slice(0, 120);
                                                    if (value)
                                                        touch({ use: value });
                                                }}
                                            />
                                        </div>
                                        <div className="field">
                                            <label htmlFor="template-scope">
                                                Assignment
                                            </label>
                                            <select
                                                id="template-scope"
                                                value={draft.assignment}
                                                onChange={(event) =>
                                                    touch({
                                                        assignment: event.target
                                                            .value as CheckAssignment,
                                                    })
                                                }
                                            >
                                                {assignment.map((option) => (
                                                    <option
                                                        key={option.value}
                                                        value={option.value}
                                                    >
                                                        {option.label}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        <StudioNotice title="One reusable library">
                                            Assignment chooses which vehicles
                                            can use this checklist from the
                                            shared vehicle checklist library.
                                            Checks pass or fail only against a
                                            version the site’s approved check
                                            rules cover.
                                            {!shared &&
                                                ' Checklists used by other vehicles are fleet-wide settings, changed by a Fleet Manager.'}
                                        </StudioNotice>
                                    </>
                                )}
                                {step === 1 && (
                                    <>
                                        <div className="question-builder">
                                            {draft.questions.map(
                                                (question, index) => (
                                                    <section key={question.id}>
                                                        <div className="question-number">
                                                            {index + 1}
                                                        </div>
                                                        <div className="question-fields">
                                                            <Input
                                                                aria-label={`Question ${index + 1}`}
                                                                placeholder="What should the person check?"
                                                                value={
                                                                    question.label
                                                                }
                                                                maxLength={255}
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    change(
                                                                        question.id,
                                                                        {
                                                                            label: event
                                                                                .target
                                                                                .value,
                                                                        },
                                                                    )
                                                                }
                                                            />
                                                            <select
                                                                aria-label={`Answer type ${index + 1}`}
                                                                value={
                                                                    question.kind
                                                                }
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    change(
                                                                        question.id,
                                                                        {
                                                                            kind: event
                                                                                .target
                                                                                .value as CheckQuestionKind,
                                                                        },
                                                                    )
                                                                }
                                                            >
                                                                {NEW_KINDS.map(
                                                                    (kind) => (
                                                                        <option
                                                                            key={
                                                                                kind.value
                                                                            }
                                                                            value={
                                                                                kind.value
                                                                            }
                                                                        >
                                                                            {
                                                                                kind.label
                                                                            }
                                                                        </option>
                                                                    ),
                                                                )}
                                                                {carried.has(
                                                                    question.id,
                                                                ) && (
                                                                    <option
                                                                        value={
                                                                            carried.get(
                                                                                question.id,
                                                                            )
                                                                                ?.kind
                                                                        }
                                                                    >
                                                                        {carriedLabel(
                                                                            carried.get(
                                                                                question.id,
                                                                            ),
                                                                        )}
                                                                    </option>
                                                                )}
                                                            </select>
                                                            <label className="inline-check">
                                                                <input
                                                                    type="checkbox"
                                                                    checked={
                                                                        question.required
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        change(
                                                                            question.id,
                                                                            {
                                                                                required:
                                                                                    event
                                                                                        .target
                                                                                        .checked,
                                                                            },
                                                                        )
                                                                    }
                                                                />
                                                                Required answer
                                                            </label>
                                                        </div>
                                                        <div className="question-controls">
                                                            <Button
                                                                size="icon"
                                                                variant="ghost"
                                                                aria-label={`Move question ${index + 1} up`}
                                                                disabled={
                                                                    index === 0
                                                                }
                                                                onClick={() =>
                                                                    move(
                                                                        index,
                                                                        index -
                                                                            1,
                                                                    )
                                                                }
                                                            >
                                                                <ArrowUp className="size-[15px]" />
                                                            </Button>
                                                            <Button
                                                                size="icon"
                                                                variant="ghost"
                                                                aria-label={`Move question ${index + 1} down`}
                                                                disabled={
                                                                    index ===
                                                                    draft
                                                                        .questions
                                                                        .length -
                                                                        1
                                                                }
                                                                onClick={() =>
                                                                    move(
                                                                        index,
                                                                        index +
                                                                            1,
                                                                    )
                                                                }
                                                            >
                                                                <ArrowDown className="size-[15px]" />
                                                            </Button>
                                                            <Button
                                                                size="icon"
                                                                variant="ghost"
                                                                aria-label={`Remove question ${index + 1}`}
                                                                disabled={
                                                                    draft
                                                                        .questions
                                                                        .length ===
                                                                    1
                                                                }
                                                                onClick={() =>
                                                                    touch({
                                                                        questions:
                                                                            draft.questions.filter(
                                                                                (
                                                                                    entry,
                                                                                ) =>
                                                                                    entry.id !==
                                                                                    question.id,
                                                                            ),
                                                                    })
                                                                }
                                                            >
                                                                <Trash2 className="size-[15px]" />
                                                            </Button>
                                                        </div>
                                                    </section>
                                                ),
                                            )}
                                        </div>
                                        <Button
                                            variant="outline"
                                            disabled={
                                                draft.questions.length >= 50
                                            }
                                            onClick={() =>
                                                touch({
                                                    questions: [
                                                        ...draft.questions,
                                                        newQuestion(),
                                                    ],
                                                })
                                            }
                                        >
                                            <Plus className="size-4" />
                                            Add question
                                        </Button>
                                        <label className="inline-check">
                                            <input
                                                type="checkbox"
                                                checked={draft.evidence}
                                                onChange={(event) =>
                                                    touch({
                                                        evidence:
                                                            event.target
                                                                .checked,
                                                    })
                                                }
                                            />
                                            Require at least one photo or
                                            document before submission
                                        </label>
                                        <StudioNotice title="How outcomes are decided">
                                            Under the site’s approved check
                                            rules, an issue produces Failed and
                                            an unassessed condition produces
                                            Needs assessment. Written
                                            observations and numbers never imply
                                            a pass. Without approved rules,
                                            every check is recorded for
                                            assessment.
                                        </StudioNotice>
                                    </>
                                )}
                                {step === 2 && (
                                    <>
                                        <ReviewCard
                                            icon={ClipboardCheck}
                                            title={
                                                draft.name.trim() ||
                                                'Unnamed checklist'
                                            }
                                        >
                                            <ReviewRow
                                                label="Use"
                                                value={draft.use}
                                            />
                                            <ReviewRow
                                                label="Assignment"
                                                value={
                                                    assignment.find(
                                                        (option) =>
                                                            option.value ===
                                                            draft.assignment,
                                                    )?.label
                                                }
                                            />
                                            <ReviewRow
                                                label="Evidence"
                                                value={
                                                    draft.evidence
                                                        ? 'Required'
                                                        : 'Optional'
                                                }
                                            />
                                            {draft.questions.map(
                                                (question, index) => (
                                                    <ReviewRow
                                                        key={question.id}
                                                        label={`${index + 1}. ${question.label}`}
                                                        value={questionSummary(
                                                            question,
                                                        )}
                                                    />
                                                ),
                                            )}
                                        </ReviewCard>
                                        <label className="inline-check">
                                            <input
                                                type="checkbox"
                                                checked={draft.confirmed}
                                                onChange={(event) =>
                                                    touch({
                                                        confirmed:
                                                            event.target
                                                                .checked,
                                                    })
                                                }
                                            />
                                            Publish this version for new checks;
                                            preserve all submitted records.
                                        </label>
                                    </>
                                )}
                            </fieldset>
                        </div>
                    </div>
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={() => {
                    setDiscard(false);
                    onClose();
                }}
                title="Discard checklist changes?"
                description="Unsaved checklist draft. The published checklist stays unchanged."
                confirmText="Discard draft"
                cancelText="Keep editing"
            />
        </>
    );
}

function carriedLabel(
    question:
        | { kind: CheckQuestionKind; options: Array<{ label: string }> }
        | undefined,
): string {
    if (!question) return '';
    if (question.kind === 'checkbox') return kindLabel('checkbox');
    const choices = question.options.map((option) => option.label).join(' / ');
    return `${kindLabel('select')} · ${choices}`;
}
