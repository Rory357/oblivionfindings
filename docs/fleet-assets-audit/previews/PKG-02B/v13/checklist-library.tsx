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
    Plus,
    Settings2,
    Trash2,
} from 'lucide-react';
import { createContext, useContext, useState, type ReactNode } from 'react';
import { CatalogPicker } from './catalog-picker';
import {
    CollectionToggle,
    RecordCollection,
    useCollectionView,
} from './collection-view';
import { Modal, Notice } from './ui';
export type CheckQuestion = {
    id: string;
    label: string;
    kind: 'condition' | 'text' | 'number';
    required: boolean;
};
export type CheckTemplate = {
    id: string;
    name: string;
    version: string;
    detail: string;
    use: string;
    scope: string;
    evidence: boolean;
    questions: CheckQuestion[];
};
export const seedTemplates: CheckTemplate[] = ['condition', 'return'].map(
    (id, i) => ({
        id,
        name: i ? 'Return condition record' : 'Vehicle condition record',
        version: i ? 'DEMO-2' : 'DEMO-3',
        detail: 'Synthetic approved version · vehicle library',
        use: i ? 'After vehicle use' : 'Before vehicle use',
        scope: 'All vehicles',
        evidence: false,
        questions: [
            {
                id: 'exterior',
                label: 'Exterior condition (example)',
                kind: 'condition',
                required: true,
            },
            {
                id: 'cabin',
                label: 'Cabin condition (example)',
                kind: 'condition',
                required: true,
            },
        ],
    }),
);
const Context = createContext<{
    templates: CheckTemplate[];
    save: (t: CheckTemplate) => void;
}>({ templates: seedTemplates, save: () => {} });
export const useChecklists = () => useContext(Context);
export function ChecklistProvider({ children }: { children: ReactNode }) {
    const [templates, setTemplates] = useState(seedTemplates);
    return (
        <Context.Provider
            value={{
                templates,
                save: (t) =>
                    setTemplates((all) => [
                        ...all.filter((x) => x.id !== t.id),
                        t,
                    ]),
            }}
        >
            {children}
        </Context.Provider>
    );
}
export function ChecklistLibrary({
    canManage,
    canRun,
    onRun,
    onView,
}: {
    canManage: boolean;
    canRun: boolean;
    onRun: (id: string) => void;
    onView: (t: CheckTemplate) => void;
}) {
    const { templates } = useChecklists();
    const [edit, setEdit] = useState<CheckTemplate | null | undefined>();
    const [query, setQuery] = useState('');
    const layout = useCollectionView('checklist-templates');
    return (
        <>
            <div className="library-toolbar">
                <Input
                    aria-label="Search checklist library"
                    placeholder="Search checklists…"
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                />
                <CollectionToggle label="Checklist templates" {...layout} />
                <Button disabled={!canManage} onClick={() => setEdit(null)}>
                    <Plus size={16} />
                    Create checklist
                </Button>
            </div>
            <p className="studio-footnote">
                Universal vehicle library · Managed with Maintenance checklists.
                Changes publish a new version; submitted checks keep their
                original questions and evidence.
            </p>
            <RecordCollection
                label="Checklist templates"
                view={layout.view}
                columns={[
                    { label: 'When to use', width: '1.1fr' },
                    { label: 'Coverage & evidence', width: '1.1fr' },
                    { label: 'Actions', width: '1.5fr' },
                ]}
                records={templates
                    .filter((t) =>
                        t.name.toLowerCase().includes(query.toLowerCase()),
                    )
                    .map((t) => ({
                        id: t.id,
                        name: t.name,
                        subline: `Version ${t.version}`,
                        icon: ListChecks,
                        fields: [
                            <>
                                <strong>{t.use}</strong>
                                <small>{t.questions.length} questions</small>
                            </>,
                            <>
                                <span>{t.scope}</span>
                                <small>
                                    Evidence{' '}
                                    {t.evidence ? 'required' : 'optional'}
                                </small>
                            </>,
                            <div className="collection-actions">
                                <Button
                                    variant="outline"
                                    onClick={() => onView(t)}
                                >
                                    Preview
                                </Button>
                                <Button
                                    variant="outline"
                                    disabled={!canManage}
                                    onClick={() => setEdit(t)}
                                >
                                    <Settings2 size={14} />
                                    Customise
                                </Button>
                                <Button
                                    disabled={!canRun}
                                    onClick={() => onRun(t.id)}
                                >
                                    Use checklist
                                </Button>
                            </div>,
                        ],
                        open: () => onView(t),
                        footer: {
                            primary: t.version,
                            secondary: 'Published version · originals retained',
                        },
                        actions: [
                            {
                                label: 'Preview checklist',
                                icon: ListChecks,
                                onClick: () => onView(t),
                            },
                            ...(canManage
                                ? [
                                      {
                                          label: 'Customise checklist',
                                          icon: Settings2,
                                          onClick: () => setEdit(t),
                                      },
                                  ]
                                : []),
                            ...(canRun
                                ? [
                                      {
                                          label: 'Use checklist',
                                          icon: ListChecks,
                                          onClick: () => onRun(t.id),
                                      },
                                  ]
                                : []),
                        ],
                    }))}
                empty={
                    <>
                        <h3>No matching checklists</h3>
                        <p>Try another checklist name.</p>
                        <Button variant="outline" onClick={() => setQuery('')}>
                            Clear search
                        </Button>
                    </>
                }
            />
            {edit !== undefined && (
                <TemplateEditor
                    original={edit}
                    onClose={() => setEdit(undefined)}
                />
            )}
        </>
    );
}
function TemplateEditor({
    original,
    onClose,
}: {
    original: CheckTemplate | null;
    onClose: () => void;
}) {
    const { save } = useChecklists();
    const [step, setStep] = useState(0),
        [done, setDone] = useState(false),
        [discard, setDiscard] = useState(false),
        [error, setError] = useState('');
    const [name, setName] = useState(original?.name || ''),
        [use, setUse] = useState(original?.use || 'Before vehicle use'),
        [scope, setScope] = useState(original?.scope || 'All vehicles'),
        [evidence, setEvidence] = useState(original?.evidence || false),
        [confirmed, setConfirmed] = useState(false);
    const [questions, setQuestions] = useState<CheckQuestion[]>(
        original?.questions.map((q) => ({ ...q })) || [
            {
                id: crypto.randomUUID(),
                label: '',
                kind: 'condition',
                required: true,
            },
        ],
    );
    const [dirty, setDirty] = useState(false);
    const touch = () => setDirty(true);
    const close = () => (done || !dirty ? onClose() : setDiscard(true));
    const change = (id: string, patch: Partial<CheckQuestion>) => {
        touch();
        setQuestions((all) =>
            all.map((q) => (q.id === id ? { ...q, ...patch } : q)),
        );
    };
    const move = (i: number, n: number) => {
        touch();
        setQuestions((all) => {
            const next = [...all];
            [next[i], next[n]] = [next[n], next[i]];
            return next;
        });
    };
    const validate = () =>
        !name.trim() || !use
            ? 'Name the checklist and choose its use.'
            : !questions.length || questions.some((q) => !q.label.trim())
              ? 'Give every question a label.'
              : new Set(questions.map((q) => q.label.trim().toLowerCase()))
                      .size !== questions.length
                ? 'Question labels must be unique.'
                : !questions.some((q) => q.kind === 'condition' && q.required)
                  ? 'Include a required condition question for the demonstrated outcome rule.'
                  : !confirmed
                    ? 'Confirm the new version for this synthetic preview.'
                    : '';
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
                steps={[
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
                ]}
                stepIndex={step}
                onStepClick={setStep}
                pct={Math.round(
                    ([
                        !!name.trim(),
                        questions.every((q) => !!q.label.trim()),
                        confirmed,
                    ].filter(Boolean).length /
                        3) *
                        100,
                )}
                success={
                    done ? (
                        <WizardSuccessPane
                            title="Checklist version published"
                            blurb="Available to new checks in this preview. Earlier submissions retain their original version and answers."
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
                    <Button variant="outline" onClick={close}>
                        Cancel
                    </Button>
                }
                footerEnd={
                    !done ? (
                        <>
                            <Button
                                variant="outline"
                                disabled={step === 0}
                                onClick={() => setStep(step - 1)}
                            >
                                Back
                            </Button>
                            <Button
                                onClick={() => {
                                    setError('');
                                    if (step < 2) {
                                        if (step === 0 && !name.trim()) {
                                            setError('Name the checklist.');
                                            return;
                                        }
                                        setStep(step + 1);
                                        return;
                                    }
                                    const e = validate();
                                    if (e) {
                                        setError(e);
                                        return;
                                    }
                                    save({
                                        id:
                                            original?.id ||
                                            'template-' + crypto.randomUUID(),
                                        name: name.trim(),
                                        version: original
                                            ? `DEMO-${Number(original.version.replace('DEMO-', '')) + 1}`
                                            : 'DEMO-1',
                                        detail: 'Synthetic approved version · vehicle library',
                                        use,
                                        scope,
                                        evidence,
                                        questions,
                                    });
                                    setDone(true);
                                }}
                            >
                                {step < 2 ? 'Continue' : 'Publish demo version'}
                            </Button>
                        </>
                    ) : (
                        <Button onClick={onClose}>Done</Button>
                    )
                }
            >
                <WizardStepPane key={step}>
                    <div className="flow-stack">
                        {error && (
                            <Notice
                                tone="critical"
                                title="Version not published"
                            >
                                {error}
                            </Notice>
                        )}
                        {step === 0 && (
                            <>
                                <div className="field">
                                    <label htmlFor="template-name">
                                        Checklist name
                                    </label>
                                    <Input
                                        id="template-name"
                                        value={name}
                                        onChange={(e) => {
                                            touch();
                                            setName(e.target.value);
                                        }}
                                    />
                                </div>
                                <CatalogPicker
                                    label="Used for"
                                    value={use}
                                    onChange={(v) => {
                                        touch();
                                        setUse(v);
                                    }}
                                    options={[
                                        'Before vehicle use',
                                        'After vehicle use',
                                        'Scheduled inspection',
                                        'Accessibility equipment',
                                        'Post-repair verification',
                                    ]}
                                />
                                <div className="field">
                                    <label htmlFor="template-scope">
                                        Assignment
                                    </label>
                                    <select
                                        id="template-scope"
                                        value={scope}
                                        onChange={(e) => {
                                            touch();
                                            setScope(e.target.value);
                                        }}
                                    >
                                        <option>All vehicles</option>
                                        <option>Accessible vehicles</option>
                                        <option>This vehicle · VH-014</option>
                                    </select>
                                </div>
                                <Notice title="One reusable library">
                                    Profile assignment selects a version from
                                    the universal vehicle checklist library.
                                    Production publishing requires the
                                    organisation’s checklist authority.
                                </Notice>
                            </>
                        )}
                        {step === 1 && (
                            <>
                                <div className="question-builder">
                                    {questions.map((q, i) => (
                                        <section key={q.id}>
                                            <div className="question-number">
                                                {i + 1}
                                            </div>
                                            <div className="question-fields">
                                                <Input
                                                    aria-label={`Question ${i + 1}`}
                                                    placeholder="What should the person check?"
                                                    value={q.label}
                                                    onChange={(e) =>
                                                        change(q.id, {
                                                            label: e.target
                                                                .value,
                                                        })
                                                    }
                                                />
                                                <select
                                                    aria-label={`Answer type ${i + 1}`}
                                                    value={q.kind}
                                                    onChange={(e) =>
                                                        change(q.id, {
                                                            kind: e.target
                                                                .value as CheckQuestion['kind'],
                                                        })
                                                    }
                                                >
                                                    <option value="condition">
                                                        Condition · No issue /
                                                        Issue / Unable to assess
                                                    </option>
                                                    <option value="text">
                                                        Written observation
                                                    </option>
                                                    <option value="number">
                                                        Number / reading
                                                    </option>
                                                </select>
                                                <label className="inline-check">
                                                    <input
                                                        type="checkbox"
                                                        checked={q.required}
                                                        onChange={(e) =>
                                                            change(q.id, {
                                                                required:
                                                                    e.target
                                                                        .checked,
                                                            })
                                                        }
                                                    />
                                                    Required answer
                                                </label>
                                            </div>
                                            <div className="question-controls">
                                                <Button
                                                    size="icon"
                                                    variant="ghost"
                                                    aria-label={`Move question ${i + 1} up`}
                                                    disabled={i === 0}
                                                    onClick={() =>
                                                        move(i, i - 1)
                                                    }
                                                >
                                                    <ArrowUp size={15} />
                                                </Button>
                                                <Button
                                                    size="icon"
                                                    variant="ghost"
                                                    aria-label={`Move question ${i + 1} down`}
                                                    disabled={
                                                        i ===
                                                        questions.length - 1
                                                    }
                                                    onClick={() =>
                                                        move(i, i + 1)
                                                    }
                                                >
                                                    <ArrowDown size={15} />
                                                </Button>
                                                <Button
                                                    size="icon"
                                                    variant="ghost"
                                                    aria-label={`Remove question ${i + 1}`}
                                                    disabled={
                                                        questions.length === 1
                                                    }
                                                    onClick={() => {
                                                        touch();
                                                        setQuestions(
                                                            questions.filter(
                                                                (x) =>
                                                                    x.id !==
                                                                    q.id,
                                                            ),
                                                        );
                                                    }}
                                                >
                                                    <Trash2 size={15} />
                                                </Button>
                                            </div>
                                        </section>
                                    ))}
                                </div>
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        touch();
                                        setQuestions([
                                            ...questions,
                                            {
                                                id: crypto.randomUUID(),
                                                label: '',
                                                kind: 'condition',
                                                required: true,
                                            },
                                        ]);
                                    }}
                                >
                                    <Plus size={16} />
                                    Add question
                                </Button>
                                <label className="inline-check">
                                    <input
                                        type="checkbox"
                                        checked={evidence}
                                        onChange={(e) => {
                                            touch();
                                            setEvidence(e.target.checked);
                                        }}
                                    />
                                    Require at least one photo or document
                                    before submission
                                </label>
                                <Notice title="Demonstrated outcome rule">
                                    An issue produces Failed; an unassessed
                                    condition produces Needs assessment. Written
                                    observations and numbers do not imply a
                                    pass. These are synthetic rules for design
                                    review.
                                </Notice>
                            </>
                        )}
                        {step === 2 && (
                            <>
                                <ReviewCard
                                    icon={ClipboardCheck}
                                    title={name || 'Unnamed checklist'}
                                >
                                    <ReviewRow label="Use" value={use} />
                                    <ReviewRow
                                        label="Assignment"
                                        value={scope}
                                    />
                                    <ReviewRow
                                        label="Evidence"
                                        value={
                                            evidence ? 'Required' : 'Optional'
                                        }
                                    />
                                    {questions.map((q, i) => (
                                        <ReviewRow
                                            key={q.id}
                                            label={`${i + 1}. ${q.label}`}
                                            value={`${q.kind} · ${q.required ? 'Required' : 'Optional'}`}
                                        />
                                    ))}
                                </ReviewCard>
                                <label className="inline-check">
                                    <input
                                        type="checkbox"
                                        checked={confirmed}
                                        onChange={(e) => {
                                            touch();
                                            setConfirmed(e.target.checked);
                                        }}
                                    />
                                    Publish this synthetic version for new
                                    checks; preserve all submitted records.
                                </label>
                            </>
                        )}
                    </div>
                </WizardStepPane>
            </WizardShell>
            {discard && (
                <Modal
                    title="Discard checklist changes?"
                    description="Unsaved checklist draft"
                    icon={ClipboardCheck}
                    onClose={() => setDiscard(false)}
                    footer={
                        <>
                            <Button
                                variant="outline"
                                onClick={() => setDiscard(false)}
                            >
                                Keep editing
                            </Button>
                            <Button variant="destructive" onClick={onClose}>
                                Discard draft
                            </Button>
                        </>
                    }
                >
                    The published checklist stays unchanged.
                </Modal>
            )}
        </>
    );
}
