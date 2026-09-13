import { ConfirmDialog } from '@/components/confirm-dialog';
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
import { SetupCreateRecovery } from '@/pages/it/setup/_create-recovery';
import {
    useSetupCreateCommand,
    type SetupCommandOutcome,
} from '@/pages/it/setup/use-setup-create-command';
import { router, useForm } from '@inertiajs/react';
import {
    BookOpenCheck,
    CheckCircle2,
    CircleDashed,
    Laptop,
    ListChecks,
    Loader2,
    Pencil,
    Plus,
    Send,
    ShieldCheck,
    Trash2,
} from 'lucide-react';
import { useEffect, useRef, useState, type FormEvent } from 'react';

export interface CatalogManagementField {
    key: string;
    label: string;
    type: string;
    required: boolean;
    visibility: string;
    options?: string[];
    min?: number | '';
    max?: number | '';
    help?: string;
}

export interface CatalogManagementItem {
    id: number;
    it_service_id: number | null;
    service_name: string | null;
    name: string;
    slug: string;
    description: string | null;
    outcome_type: string;
    category: string;
    provisioning_type: string | null;
    default_priority: string;
    requires_approval: boolean;
    is_published: boolean;
    internal_only: boolean;
    site_scope?: number[] | null;
    form_schema_version: number;
    lock_version: number;
    published_version: number | null;
    form_schema: { fields?: CatalogManagementField[] };
    search_terms: string[];
    sort_order: number;
    submission_count: number;
}

export interface CatalogServiceOption {
    id: number;
    name: string;
}

interface Props {
    actorId?: number;
    items: CatalogManagementItem[];
    services: CatalogServiceOption[];
    sites?: CatalogServiceOption[];
}

const FIELD_TYPES = [
    'text',
    'textarea',
    'email',
    'date',
    'integer',
    'number',
    'boolean',
    'select',
    'multiselect',
    'employee',
    'user',
    'asset',
];
const OUTCOMES = ['service_request', 'security_request', 'provisioning'];
const EDITOR_STEPS = [
    {
        key: 'details',
        label: 'Request details',
        blurb: 'Purpose, outcome and audience',
        icon: BookOpenCheck,
    },
    {
        key: 'fields',
        label: 'Form fields',
        blurb: 'Questions and validation',
        icon: ListChecks,
    },
    {
        key: 'review',
        label: 'Review draft',
        blurb: 'Check before saving',
        icon: CheckCircle2,
    },
];
const OUTCOME_DETAILS = [
    {
        key: 'service_request',
        label: 'Service request',
        description: 'Create governed IT work.',
        icon: BookOpenCheck,
    },
    {
        key: 'security_request',
        label: 'Security request',
        description: 'Request security assistance.',
        icon: ShieldCheck,
    },
    {
        key: 'provisioning',
        label: 'Provisioning',
        description: 'Request equipment or access.',
        icon: Laptop,
    },
];
const CATEGORIES = ['hardware', 'account', 'network', 'other'];
const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
const PROVISIONING_TYPES = ['account', 'access', 'equipment', 'other'];
const humanize = (value: string) =>
    value
        .replace(/_/g, ' ')
        .replace(/^\w/, (character) => character.toUpperCase());

const emptyField = (index: number): CatalogManagementField => ({
    key: `field_${index + 1}`,
    label: '',
    type: 'text',
    required: false,
    visibility: 'requester',
    options: [],
    help: '',
});

const normaliseFields = (
    fields: CatalogManagementField[] = [],
): CatalogManagementField[] =>
    fields.map((field) => ({
        ...field,
        options: (field.options ?? []).map((option) =>
            typeof option === 'string' ? option : String(option),
        ),
        help: field.help ?? '',
        min: field.min ?? '',
        max: field.max ?? '',
    }));

export function ItCatalogueManagement({
    items,
    services,
    sites = [],
    actorId,
}: Props) {
    const [editing, setEditing] = useState<CatalogManagementItem | null>(null);
    const [editorOpen, setEditorOpen] = useState(false);
    const [editorStep, setEditorStep] = useState(0);
    const [saved, setSaved] = useState(false);
    const [editorActor, setEditorActor] = useState(actorId);
    const createCommand = useSetupCreateCommand({
        active: editorOpen && !editing,
        actorId,
        resource: 'catalogue-items',
    });
    const [leave, setLeave] = useState<(() => void) | null>(null);
    const [initialDraft, setInitialDraft] = useState('');
    const allowNavigation = useRef(false);
    const editorFocus = useRef<HTMLFormElement>(null);
    const [publishing, setPublishing] = useState<CatalogManagementItem | null>(
        null,
    );
    const [unpublishing, setUnpublishing] =
        useState<CatalogManagementItem | null>(null);
    const form = useForm({
        expected_version: null as number | null,
        it_service_id: '',
        name: '',
        description: '',
        outcome_type: 'service_request',
        category: 'other',
        provisioning_type: '',
        default_priority: 'normal',
        requires_approval: false,
        internal_only: false,
        site_scope: null as number[] | null,
        search_terms: [] as string[],
        sort_order: 0,
        form_schema: { fields: [] as CatalogManagementField[] },
    });
    const unpublishForm = useForm({ reason: '', expected_version: 0 });
    const publishForm = useForm({ expected_version: 0 });
    const errorSummary = Object.values(form.errors).join(' ');
    useEffect(() => {
        if (!editorOpen || saved) return;
        const frame = requestAnimationFrame(() => editorFocus.current?.focus());
        return () => cancelAnimationFrame(frame);
    }, [editorOpen, editorStep, saved, errorSummary]);
    const commandPending =
        !editing && (createCommand.pending || createCommand.busy);
    const editorBlocked =
        form.processing || (!editing && !createCommand.canEdit);
    const concealEditor =
        actorId !== editorActor ||
        (!editing && ['expired', 'denied'].includes(createCommand.state));
    const dirty =
        editorOpen && !saved && JSON.stringify(form.data) !== initialDraft;
    const completeness = [
        Boolean(form.data.name.trim()),
        Boolean(form.data.description.trim()),
        Boolean(form.data.it_service_id),
        OUTCOMES.includes(form.data.outcome_type),
        ...form.data.form_schema.fields.map((field) =>
            Boolean(field.key.trim() && field.label.trim()),
        ),
    ];

    useEffect(() => {
        if (
            !editorOpen ||
            saved ||
            (!dirty && !form.processing && !commandPending)
        )
            return;
        const unload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', unload);
        const remove = router.on('before', (event) => {
            if (allowNavigation.current) return;
            event.preventDefault();
            if (form.processing || commandPending) return;
            const visit = event.detail.visit;
            if (
                visit.async &&
                visit.method === 'get' &&
                visit.url.href === window.location.href &&
                visit.only.length > 0
            )
                return;
            setLeave(() => () => {
                allowNavigation.current = true;
                router.visit(visit.url, visit);
            });
        });
        return () => {
            window.removeEventListener('beforeunload', unload);
            remove();
        };
    }, [editorOpen, saved, dirty, form.processing, commandPending]);

    const closeEditor = () => {
        if (form.processing || commandPending) return;
        if (dirty) setLeave(() => () => setEditorOpen(false));
        else setEditorOpen(false);
    };

    const changeSiteScope = (scope: number[] | null) => {
        form.setData('site_scope', scope);
        form.clearErrors('site_scope');
    };

    const validateDetails = () => {
        if (
            form.data.site_scope !== null &&
            form.data.site_scope.length === 0
        ) {
            form.setError('site_scope', 'Choose at least one approved site.');
            return false;
        }
        if (!form.data.name.trim()) {
            form.setError('name', 'Enter a request name.');
            return false;
        }
        if (
            form.data.outcome_type === 'provisioning' &&
            !PROVISIONING_TYPES.includes(form.data.provisioning_type)
        ) {
            form.setError('provisioning_type', 'Choose the provisioning type.');
            return false;
        }
        return true;
    };
    const validateFields = () => {
        const keys = new Set<string>();
        for (const field of form.data.form_schema.fields) {
            if (
                !/^[a-z][a-z0-9_]*$/.test(field.key) ||
                !field.label.trim() ||
                keys.has(field.key) ||
                (['select', 'multiselect'].includes(field.type) &&
                    !field.options?.some((option) => option.trim()))
            ) {
                form.setError(
                    'form_schema',
                    'Each field needs a unique valid key, a label, and choices where applicable.',
                );
                return false;
            }
            keys.add(field.key);
        }
        return true;
    };
    const continueEditor = () => {
        form.clearErrors();
        if (editorStep === 0 && !validateDetails()) return;
        if (editorStep === 1 && !validateFields()) return;
        setEditorStep((step) => Math.min(2, step + 1));
    };

    const openEditor = (item?: CatalogManagementItem) => {
        setEditorActor(actorId);
        if (createCommand.state === 'committed')
            createCommand.restore(crypto.randomUUID(), null, false);
        setEditorStep(0);
        setSaved(false);
        setLeave(null);
        allowNavigation.current = false;
        setEditing(item ?? null);
        form.clearErrors();
        const values = {
            expected_version: item?.lock_version ?? null,
            it_service_id: String(item?.it_service_id ?? ''),
            name: item?.name ?? '',
            description: item?.description ?? '',
            outcome_type: item?.outcome_type ?? 'service_request',
            category: item?.category ?? 'other',
            provisioning_type: item?.provisioning_type ?? '',
            default_priority: item?.default_priority ?? 'normal',
            requires_approval: item?.requires_approval ?? false,
            internal_only: item?.internal_only ?? false,
            site_scope: item?.site_scope ?? null,
            search_terms: item?.search_terms ?? [],
            sort_order: item?.sort_order ?? 0,
            form_schema: {
                fields: normaliseFields(item?.form_schema.fields),
            },
        };
        setInitialDraft(JSON.stringify(values));
        form.setData(values);
        setEditorOpen(true);
    };

    const handleCreateOutcome = (outcome: SetupCommandOutcome | null) => {
        if (outcome && !('cancelled' in outcome)) setSaved(true);
    };
    const save = async (event: FormEvent) => {
        event.preventDefault();
        if (editorBlocked || saved || concealEditor) return;
        form.clearErrors();
        if (!validateDetails()) {
            setEditorStep(0);
            return;
        }
        if (!validateFields()) {
            setEditorStep(1);
            return;
        }
        if (editorStep !== 2) {
            setEditorStep(2);
            return;
        }
        if (!editing) {
            handleCreateOutcome(
                await createCommand.submit({
                    ...form.data,
                    search_terms: form.data.search_terms.filter(Boolean),
                }),
            );
            const result = createCommand.getSnapshot();
            if (result.state === 'validation') {
                form.setError(result.errors as typeof form.errors);
                setEditorStep(
                    Object.keys(result.errors).some((key) =>
                        key.startsWith('form_schema'),
                    )
                        ? 1
                        : 0,
                );
            }
            return;
        }
        form.transform((data) => ({
            ...data,
            actor_user_id: editorActor,
            search_terms: data.search_terms.filter(Boolean),
        }));
        const options = {
            preserveScroll: true,
            onSuccess: (page: { props: Record<string, unknown> }) => {
                const flash = page.props.flash as
                    | { success?: string; error?: string }
                    | undefined;
                if (!flash?.success || flash.error) {
                    form.setError(
                        'name',
                        flash?.error ??
                            'The save could not be confirmed. Check the current catalogue before retrying.',
                    );
                    setEditorStep(0);
                    return;
                }
                setSaved(true);
            },
            onError: (errors: Record<string, string>) =>
                setEditorStep(
                    Object.keys(errors).some((key) =>
                        key.startsWith('form_schema'),
                    )
                        ? 1
                        : 0,
                ),
            onFinish: () => {
                allowNavigation.current = false;
            },
        };
        allowNavigation.current = true;
        if (editing) {
            form.patch(`/it/setup/catalogue-items/${editing.id}`, options);
        } else {
            form.post('/it/setup/catalogue-items', options);
        }
    };

    const setField = (
        index: number,
        patch: Partial<CatalogManagementField>,
    ) => {
        const fields = [...form.data.form_schema.fields];
        fields[index] = { ...fields[index], ...patch };
        form.setData('form_schema', { fields });
    };

    const removeField = (index: number) => {
        form.setData('form_schema', {
            fields: form.data.form_schema.fields.filter(
                (_, fieldIndex) => fieldIndex !== index,
            ),
        });
    };

    const publish = () => {
        if (!publishing) return;
        publishForm.post(`/it/setup/catalogue-items/${publishing.id}/publish`, {
            preserveScroll: true,
            onSuccess: () => setPublishing(null),
        });
    };

    const unpublish = (event: FormEvent) => {
        event.preventDefault();
        if (!unpublishing) return;
        unpublishForm.post(
            `/it/setup/catalogue-items/${unpublishing.id}/unpublish`,
            {
                preserveScroll: true,
                onSuccess: () => setUnpublishing(null),
            },
        );
    };

    return (
        <section className="space-y-4" aria-labelledby="catalogue-setup-title">
            <header className="flex flex-col justify-between gap-3 rounded-2xl border border-border bg-card p-5 shadow-sm md:flex-row md:items-center">
                <div className="flex items-start gap-3">
                    <span className="grid h-11 w-11 flex-none place-items-center rounded-xl bg-primary/10 text-primary">
                        <BookOpenCheck className="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div>
                        <h2
                            id="catalogue-setup-title"
                            className="text-lg font-bold"
                        >
                            Service catalogue
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Build request forms as drafts, test their fields,
                            and publish them when they are ready for staff.
                        </p>
                    </div>
                </div>
                <Button className="min-h-11" onClick={() => openEditor()}>
                    <Plus className="h-4 w-4" aria-hidden="true" />
                    New request form
                </Button>
            </header>

            {items.length ? (
                <div className="grid gap-4 xl:grid-cols-2">
                    {items.map((item) => (
                        <article
                            key={item.id}
                            className="rounded-2xl border border-border bg-card p-5 shadow-sm"
                        >
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div className="flex items-center gap-2">
                                        {item.is_published ? (
                                            <CheckCircle2
                                                className="text-status-good h-4 w-4"
                                                aria-hidden="true"
                                            />
                                        ) : (
                                            <CircleDashed
                                                className="h-4 w-4 text-muted-foreground"
                                                aria-hidden="true"
                                            />
                                        )}
                                        <StatusBadge
                                            variant={
                                                item.is_published
                                                    ? 'success'
                                                    : 'neutral'
                                            }
                                            size="sm"
                                        >
                                            {item.is_published
                                                ? 'Published'
                                                : 'Draft'}
                                        </StatusBadge>
                                        <StatusBadge variant="info" size="sm">
                                            {humanize(item.outcome_type)}
                                        </StatusBadge>
                                    </div>
                                    <h3 className="mt-3 font-semibold">
                                        {item.name}
                                    </h3>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {item.description ??
                                            'No description has been added.'}
                                    </p>
                                </div>
                            </div>
                            <dl className="mt-4 grid grid-cols-2 gap-3 rounded-xl bg-muted/35 p-3 text-sm sm:grid-cols-4">
                                <Metric
                                    label="Form version"
                                    value={`v${item.form_schema_version}`}
                                />
                                <Metric
                                    label="Published version"
                                    value={
                                        item.is_published
                                            ? `v${item.published_version}`
                                            : 'Not published'
                                    }
                                />
                                <Metric
                                    label="Fields"
                                    value={String(
                                        item.form_schema.fields?.length ?? 0,
                                    )}
                                />
                                <Metric
                                    label="Submissions"
                                    value={String(item.submission_count)}
                                />
                                <Metric
                                    label="Service"
                                    value={item.service_name ?? 'Not linked'}
                                />
                            </dl>
                            <div className="mt-4 flex flex-wrap gap-2">
                                <Button
                                    variant="outline"
                                    className="min-h-11"
                                    onClick={() => openEditor(item)}
                                >
                                    <Pencil
                                        className="h-4 w-4"
                                        aria-hidden="true"
                                    />
                                    Edit
                                </Button>
                                {item.is_published ? (
                                    <Button
                                        variant="outline"
                                        className="min-h-11"
                                        onClick={() => {
                                            unpublishForm.reset();
                                            unpublishForm.clearErrors();
                                            unpublishForm.setData(
                                                'expected_version',
                                                item.lock_version,
                                            );
                                            setUnpublishing(item);
                                        }}
                                    >
                                        Withdraw from catalogue
                                    </Button>
                                ) : null}
                                {!item.is_published ||
                                item.published_version !==
                                    item.form_schema_version ? (
                                    <Button
                                        className="min-h-11"
                                        onClick={() => {
                                            publishForm.clearErrors();
                                            publishForm.setData(
                                                'expected_version',
                                                item.lock_version,
                                            );
                                            setPublishing(item);
                                        }}
                                    >
                                        <Send
                                            className="h-4 w-4"
                                            aria-hidden="true"
                                        />
                                        Review and publish
                                    </Button>
                                ) : null}
                            </div>
                        </article>
                    ))}
                </div>
            ) : (
                <div className="rounded-2xl border border-dashed border-border bg-card px-6 py-14 text-center">
                    <p className="font-semibold">No request forms yet</p>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Create a draft form, then publish it when it is ready.
                    </p>
                </div>
            )}

            <WizardShell
                open={editorOpen && actorId === editorActor}
                onClose={closeEditor}
                title={editing ? `Edit ${editing.name}` : 'New request form'}
                description="Prepare a service request draft. Staff continue using the published version until you review and publish these changes."
                railIcon={BookOpenCheck}
                railTitle={editing ? 'Edit request' : 'New request'}
                railSub={
                    editing
                        ? `Draft v${editing.form_schema_version}`
                        : 'Service catalogue'
                }
                steps={EDITOR_STEPS}
                stepIndex={editorStep}
                onStepClick={(index) => {
                    if (!editorBlocked) setEditorStep(index);
                }}
                pct={Math.round(
                    (completeness.filter(Boolean).length /
                        completeness.length) *
                        100,
                )}
                footerStart={
                    <Button
                        type="button"
                        variant="outline"
                        disabled={editorBlocked}
                        onClick={
                            editorStep === 0
                                ? closeEditor
                                : () => setEditorStep((step) => step - 1)
                        }
                    >
                        {editorStep === 0 ? 'Cancel' : 'Back'}
                    </Button>
                }
                footerEnd={
                    editorStep < 2 ? (
                        <Button
                            key="continue"
                            type="button"
                            onClick={continueEditor}
                            disabled={editorBlocked}
                        >
                            Continue
                        </Button>
                    ) : (
                        <Button
                            key="save"
                            type="submit"
                            form="catalogue-draft-editor"
                            disabled={editorBlocked}
                        >
                            {form.processing ? (
                                <Loader2
                                    className="h-4 w-4 animate-spin"
                                    aria-hidden="true"
                                />
                            ) : null}
                            {form.processing ? 'Saving draft…' : 'Save draft'}
                        </Button>
                    )
                }
                success={
                    saved ? (
                        <WizardSuccessPane
                            title="Draft saved"
                            blurb="The draft is saved. Review and publish it from the catalogue when it is ready for staff."
                            actions={
                                <>
                                    <Button
                                        onClick={() => {
                                            setEditorOpen(false);
                                            allowNavigation.current = true;
                                            router.reload({
                                                only: [
                                                    'catalogItems',
                                                    'generatedAt',
                                                ],
                                                onFinish: () => {
                                                    allowNavigation.current = false;
                                                },
                                            });
                                        }}
                                    >
                                        Back to catalogue
                                    </Button>
                                    {!editing ? (
                                        <Button
                                            variant="outline"
                                            onClick={() => openEditor()}
                                        >
                                            Add another request
                                        </Button>
                                    ) : null}
                                </>
                            }
                        />
                    ) : undefined
                }
            >
                <WizardStepPane key={editorStep}>
                    {!editing &&
                    (createCommand.state !== 'validation' ||
                        Object.keys(createCommand.errors).length === 0) ? (
                        <SetupCreateRecovery
                            command={createCommand}
                            onOutcome={handleCreateOutcome}
                        />
                    ) : null}
                    {concealEditor ? (
                        <p role="alert">
                            This draft is concealed. Sign in with the original
                            account and check the saved result, or reopen the
                            catalogue with your current access.
                        </p>
                    ) : (
                        <form
                            id="catalogue-draft-editor"
                            ref={editorFocus}
                            tabIndex={-1}
                            aria-label={EDITOR_STEPS[editorStep].label}
                            onSubmit={save}
                            noValidate
                        >
                            <fieldset
                                disabled={editorBlocked}
                                className="min-w-0 space-y-4"
                            >
                                {Object.entries(form.errors)
                                    .filter(
                                        ([key]) =>
                                            editorStep !== 0 ||
                                            ![
                                                'name',
                                                'it_service_id',
                                                'provisioning_type',
                                                'description',
                                                'site_scope',
                                            ].includes(key),
                                    )
                                    .map(([key, message]) => (
                                        <p
                                            key={key}
                                            role="alert"
                                            className="text-sm text-destructive"
                                        >
                                            {message}
                                        </p>
                                    ))}
                                {editorStep === 0 ? (
                                    <>
                                        <h2 className="text-lg font-semibold">
                                            Request details
                                        </h2>
                                        <p className="text-sm text-muted-foreground">
                                            Choose what this request creates,
                                            who it is for, and how IT should
                                            handle it.
                                        </p>
                                        <div
                                            className="grid grid-cols-2 gap-2 sm:grid-cols-3"
                                            role="group"
                                            aria-label="Request outcome"
                                        >
                                            {OUTCOME_DETAILS.map((outcome) => {
                                                const Icon = outcome.icon;
                                                const active =
                                                    form.data.outcome_type ===
                                                    outcome.key;
                                                return (
                                                    <Button
                                                        key={outcome.key}
                                                        type="button"
                                                        variant="outline"
                                                        aria-pressed={active}
                                                        onClick={() =>
                                                            form.setData(
                                                                'outcome_type',
                                                                outcome.key,
                                                            )
                                                        }
                                                        className={`h-auto items-start justify-start rounded-xl p-3 text-left whitespace-normal ${active ? 'border-primary bg-primary/10 ring-1 ring-primary/40' : 'border-border bg-card/40'}`}
                                                    >
                                                        <Icon
                                                            className="mt-0.5 h-4 w-4 shrink-0 text-primary"
                                                            aria-hidden="true"
                                                        />
                                                        <span>
                                                            <span className="block text-sm font-medium">
                                                                {outcome.label}
                                                            </span>
                                                            <span className="block text-xs font-normal text-muted-foreground">
                                                                {
                                                                    outcome.description
                                                                }
                                                            </span>
                                                        </span>
                                                    </Button>
                                                );
                                            })}
                                        </div>
                                        <div className="mt-5 grid gap-4 md:grid-cols-2">
                                            <Field
                                                label="Request name"
                                                error={form.errors.name}
                                            >
                                                <Input
                                                    required
                                                    className="min-h-11"
                                                    value={form.data.name}
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'name',
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                            </Field>
                                            <Field
                                                label="Linked service"
                                                error={
                                                    form.errors.it_service_id
                                                }
                                            >
                                                <select
                                                    className="min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm"
                                                    value={
                                                        form.data.it_service_id
                                                    }
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'it_service_id',
                                                            event.target.value,
                                                        )
                                                    }
                                                >
                                                    <option value="">
                                                        No linked service
                                                    </option>
                                                    {services.map((service) => (
                                                        <option
                                                            key={service.id}
                                                            value={service.id}
                                                        >
                                                            {service.name}
                                                        </option>
                                                    ))}
                                                </select>
                                            </Field>
                                            <Field label="Category">
                                                <Select
                                                    value={form.data.category}
                                                    options={CATEGORIES}
                                                    onChange={(value) =>
                                                        form.setData(
                                                            'category',
                                                            value,
                                                        )
                                                    }
                                                />
                                            </Field>
                                            {form.data.outcome_type ===
                                            'provisioning' ? (
                                                <Field
                                                    label="Provisioning type"
                                                    error={
                                                        form.errors
                                                            .provisioning_type
                                                    }
                                                >
                                                    <Select
                                                        value={
                                                            form.data
                                                                .provisioning_type
                                                        }
                                                        options={
                                                            PROVISIONING_TYPES
                                                        }
                                                        placeholder="Choose provisioning type"
                                                        onChange={(value) =>
                                                            form.setData(
                                                                'provisioning_type',
                                                                value,
                                                            )
                                                        }
                                                    />
                                                </Field>
                                            ) : null}
                                            <Field label="Default priority">
                                                <Select
                                                    value={
                                                        form.data
                                                            .default_priority
                                                    }
                                                    options={PRIORITIES}
                                                    onChange={(value) =>
                                                        form.setData(
                                                            'default_priority',
                                                            value,
                                                        )
                                                    }
                                                />
                                            </Field>
                                            <Field label="Search terms (comma separated)">
                                                <Input
                                                    className="min-h-11"
                                                    value={form.data.search_terms.join(
                                                        ', ',
                                                    )}
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'search_terms',
                                                            event.target.value
                                                                .split(',')
                                                                .map((term) =>
                                                                    term.trim(),
                                                                ),
                                                        )
                                                    }
                                                />
                                            </Field>
                                            <Field label="Display order">
                                                <Input
                                                    type="number"
                                                    min={0}
                                                    className="min-h-11"
                                                    value={form.data.sort_order}
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'sort_order',
                                                            Number(
                                                                event.target
                                                                    .value,
                                                            ),
                                                        )
                                                    }
                                                />
                                            </Field>
                                            <Field
                                                label="Description"
                                                className="md:col-span-2"
                                                error={form.errors.description}
                                            >
                                                <Textarea
                                                    rows={3}
                                                    value={
                                                        form.data.description
                                                    }
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'description',
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                            </Field>
                                        </div>

                                        <div className="mt-5 flex flex-wrap gap-4 rounded-xl border border-border p-3">
                                            <Checkbox
                                                label="Approval required"
                                                checked={
                                                    form.data.requires_approval
                                                }
                                                onChange={(checked) =>
                                                    form.setData(
                                                        'requires_approval',
                                                        checked,
                                                    )
                                                }
                                            />
                                            <Checkbox
                                                label="IT staff only"
                                                checked={
                                                    form.data.internal_only
                                                }
                                                onChange={(checked) =>
                                                    form.setData(
                                                        'internal_only',
                                                        checked,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div className="mt-5 space-y-3">
                                            <Field
                                                label="Available at"
                                                error={form.errors.site_scope}
                                            >
                                                <select
                                                    className="min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm"
                                                    value={
                                                        form.data.site_scope ===
                                                        null
                                                            ? 'all'
                                                            : 'selected'
                                                    }
                                                    onChange={(event) =>
                                                        changeSiteScope(
                                                            event.target
                                                                .value === 'all'
                                                                ? null
                                                                : [],
                                                        )
                                                    }
                                                >
                                                    <option value="all">
                                                        All approved sites
                                                    </option>
                                                    <option value="selected">
                                                        Selected sites
                                                    </option>
                                                </select>
                                            </Field>
                                            {form.data.site_scope !== null ? (
                                                <fieldset className="space-y-2 rounded-xl border border-border p-3">
                                                    <legend className="px-1 text-sm font-medium">
                                                        Sites for this request
                                                        form
                                                    </legend>
                                                    {sites.map((site) => (
                                                        <Checkbox
                                                            key={site.id}
                                                            label={site.name}
                                                            checked={
                                                                form.data.site_scope?.includes(
                                                                    site.id,
                                                                ) ?? false
                                                            }
                                                            onChange={(
                                                                checked,
                                                            ) =>
                                                                changeSiteScope(
                                                                    checked
                                                                        ? [
                                                                              ...(form
                                                                                  .data
                                                                                  .site_scope ??
                                                                                  []),
                                                                              site.id,
                                                                          ]
                                                                        : (
                                                                              form
                                                                                  .data
                                                                                  .site_scope ??
                                                                              []
                                                                          ).filter(
                                                                              (
                                                                                  id,
                                                                              ) =>
                                                                                  id !==
                                                                                  site.id,
                                                                          ),
                                                                )
                                                            }
                                                        />
                                                    ))}
                                                    {sites.length === 0 ? (
                                                        <p className="text-sm text-muted-foreground">
                                                            No approved sites
                                                            are available to
                                                            your access.
                                                        </p>
                                                    ) : null}
                                                    {form.data.site_scope.filter(
                                                        (id) =>
                                                            !sites.some(
                                                                (site) =>
                                                                    site.id ===
                                                                    id,
                                                            ),
                                                    ).length > 0 ? (
                                                        <div className="space-y-2">
                                                            <p className="text-sm text-status-critical">
                                                                A saved site is
                                                                no longer
                                                                available.
                                                                Remove it before
                                                                saving.
                                                            </p>
                                                            {form.data.site_scope
                                                                .filter(
                                                                    (id) =>
                                                                        !sites.some(
                                                                            (
                                                                                site,
                                                                            ) =>
                                                                                site.id ===
                                                                                id,
                                                                        ),
                                                                )
                                                                .map((id) => (
                                                                    <Checkbox
                                                                        key={id}
                                                                        label="Unavailable saved site"
                                                                        checked
                                                                        onChange={() =>
                                                                            changeSiteScope(
                                                                                (
                                                                                    form
                                                                                        .data
                                                                                        .site_scope ??
                                                                                    []
                                                                                ).filter(
                                                                                    (
                                                                                        savedId,
                                                                                    ) =>
                                                                                        savedId !==
                                                                                        id,
                                                                                ),
                                                                            )
                                                                        }
                                                                    />
                                                                ))}
                                                        </div>
                                                    ) : null}
                                                </fieldset>
                                            ) : null}
                                            <p className="text-sm text-muted-foreground">
                                                Staff still need approved access
                                                to the request site. Changes
                                                take effect when this version is
                                                published.
                                            </p>
                                        </div>
                                    </>
                                ) : null}
                                {editorStep === 1 ? (
                                    <section
                                        className="mt-6 space-y-3"
                                        aria-labelledby="form-fields-title"
                                    >
                                        <div className="flex items-center justify-between gap-3">
                                            <div>
                                                <h3
                                                    id="form-fields-title"
                                                    className="font-semibold"
                                                >
                                                    Form fields
                                                </h3>
                                                <p className="text-sm text-muted-foreground">
                                                    Employee, user, and asset
                                                    fields use safe named
                                                    choices rather than record
                                                    numbers.
                                                </p>
                                            </div>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                className="min-h-11"
                                                onClick={() =>
                                                    form.setData(
                                                        'form_schema',
                                                        {
                                                            fields: [
                                                                ...form.data
                                                                    .form_schema
                                                                    .fields,
                                                                emptyField(
                                                                    form.data
                                                                        .form_schema
                                                                        .fields
                                                                        .length,
                                                                ),
                                                            ],
                                                        },
                                                    )
                                                }
                                            >
                                                <Plus
                                                    className="h-4 w-4"
                                                    aria-hidden="true"
                                                />
                                                Add field
                                            </Button>
                                        </div>
                                        {form.data.form_schema.fields.map(
                                            (field, index) => (
                                                <div
                                                    key={`${index}-${field.key}`}
                                                    className="rounded-xl border border-border p-4"
                                                >
                                                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                                                        <Field label="Field key">
                                                            <Input
                                                                className="min-h-11"
                                                                value={
                                                                    field.key
                                                                }
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    setField(
                                                                        index,
                                                                        {
                                                                            key: event
                                                                                .target
                                                                                .value,
                                                                        },
                                                                    )
                                                                }
                                                            />
                                                        </Field>
                                                        <Field label="Question or label">
                                                            <Input
                                                                className="min-h-11"
                                                                value={
                                                                    field.label
                                                                }
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    setField(
                                                                        index,
                                                                        {
                                                                            label: event
                                                                                .target
                                                                                .value,
                                                                        },
                                                                    )
                                                                }
                                                            />
                                                        </Field>
                                                        <Field label="Field type">
                                                            <Select
                                                                value={
                                                                    field.type
                                                                }
                                                                options={
                                                                    FIELD_TYPES
                                                                }
                                                                onChange={(
                                                                    value,
                                                                ) =>
                                                                    setField(
                                                                        index,
                                                                        {
                                                                            type: value,
                                                                            options:
                                                                                [],
                                                                        },
                                                                    )
                                                                }
                                                            />
                                                        </Field>
                                                        <Field label="Visible to">
                                                            <Select
                                                                value={
                                                                    field.visibility
                                                                }
                                                                options={[
                                                                    'requester',
                                                                    'internal',
                                                                    'restricted',
                                                                ]}
                                                                onChange={(
                                                                    value,
                                                                ) =>
                                                                    setField(
                                                                        index,
                                                                        {
                                                                            visibility:
                                                                                value,
                                                                        },
                                                                    )
                                                                }
                                                            />
                                                        </Field>
                                                    </div>
                                                    <div className="mt-3 grid gap-3 md:grid-cols-[1fr_auto_auto] md:items-end">
                                                        {[
                                                            'select',
                                                            'multiselect',
                                                        ].includes(
                                                            field.type,
                                                        ) ? (
                                                            <Field label="Choices (comma separated)">
                                                                <Input
                                                                    className="min-h-11"
                                                                    value={(
                                                                        field.options ??
                                                                        []
                                                                    ).join(
                                                                        ', ',
                                                                    )}
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        setField(
                                                                            index,
                                                                            {
                                                                                options:
                                                                                    event.target.value
                                                                                        .split(
                                                                                            ',',
                                                                                        )
                                                                                        .map(
                                                                                            (
                                                                                                option,
                                                                                            ) =>
                                                                                                option.trim(),
                                                                                        )
                                                                                        .filter(
                                                                                            Boolean,
                                                                                        ),
                                                                            },
                                                                        )
                                                                    }
                                                                />
                                                            </Field>
                                                        ) : null}
                                                        <Field label="Help text">
                                                            <Input
                                                                className="min-h-11"
                                                                value={
                                                                    field.help ??
                                                                    ''
                                                                }
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    setField(
                                                                        index,
                                                                        {
                                                                            help: event
                                                                                .target
                                                                                .value,
                                                                        },
                                                                    )
                                                                }
                                                            />
                                                        </Field>
                                                        <Checkbox
                                                            label="Required"
                                                            checked={
                                                                field.required
                                                            }
                                                            onChange={(
                                                                checked,
                                                            ) =>
                                                                setField(
                                                                    index,
                                                                    {
                                                                        required:
                                                                            checked,
                                                                    },
                                                                )
                                                            }
                                                        />
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            className="min-h-11 text-destructive"
                                                            onClick={() =>
                                                                removeField(
                                                                    index,
                                                                )
                                                            }
                                                        >
                                                            <Trash2
                                                                className="h-4 w-4"
                                                                aria-hidden="true"
                                                            />
                                                            Remove
                                                        </Button>
                                                    </div>
                                                    {[
                                                        'text',
                                                        'textarea',
                                                        'email',
                                                        'integer',
                                                        'number',
                                                    ].includes(field.type) ? (
                                                        <div className="mt-3 grid max-w-md gap-3 sm:grid-cols-2">
                                                            <Field label="Minimum">
                                                                <Input
                                                                    type="number"
                                                                    min={0}
                                                                    className="min-h-11"
                                                                    value={
                                                                        field.min ??
                                                                        ''
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        setField(
                                                                            index,
                                                                            {
                                                                                min:
                                                                                    event
                                                                                        .target
                                                                                        .value ===
                                                                                    ''
                                                                                        ? ''
                                                                                        : Number(
                                                                                              event
                                                                                                  .target
                                                                                                  .value,
                                                                                          ),
                                                                            },
                                                                        )
                                                                    }
                                                                />
                                                            </Field>
                                                            <Field label="Maximum">
                                                                <Input
                                                                    type="number"
                                                                    min={1}
                                                                    className="min-h-11"
                                                                    value={
                                                                        field.max ??
                                                                        ''
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        setField(
                                                                            index,
                                                                            {
                                                                                max:
                                                                                    event
                                                                                        .target
                                                                                        .value ===
                                                                                    ''
                                                                                        ? ''
                                                                                        : Number(
                                                                                              event
                                                                                                  .target
                                                                                                  .value,
                                                                                          ),
                                                                            },
                                                                        )
                                                                    }
                                                                />
                                                            </Field>
                                                        </div>
                                                    ) : null}
                                                </div>
                                            ),
                                        )}
                                    </section>
                                ) : null}
                                {editorStep === 2 ? (
                                    <div className="space-y-4">
                                        <h2 className="text-lg font-semibold">
                                            Review draft
                                        </h2>
                                        <p className="text-sm text-muted-foreground">
                                            Saving keeps this as a draft.
                                            Existing requests retain their
                                            original service contract.
                                        </p>
                                        <ReviewCard
                                            title="Request details"
                                            icon={BookOpenCheck}
                                            onEdit={() => setEditorStep(0)}
                                        >
                                            <ReviewRow
                                                label="Name"
                                                value={form.data.name}
                                            />
                                            <ReviewRow
                                                label="Outcome"
                                                value={humanize(
                                                    form.data.outcome_type,
                                                )}
                                            />
                                            <ReviewRow
                                                label="Service"
                                                value={
                                                    services.find(
                                                        (service) =>
                                                            String(
                                                                service.id,
                                                            ) ===
                                                            form.data
                                                                .it_service_id,
                                                    )?.name ?? 'Not linked'
                                                }
                                            />
                                            <ReviewRow
                                                label="Approval"
                                                value={
                                                    form.data.requires_approval
                                                        ? 'Required'
                                                        : 'Not required'
                                                }
                                            />
                                            <ReviewRow
                                                label="Audience"
                                                value={
                                                    form.data.internal_only
                                                        ? 'IT staff'
                                                        : 'Approved requesters'
                                                }
                                            />
                                            <ReviewRow
                                                label="Available at"
                                                value={
                                                    form.data.site_scope ===
                                                    null
                                                        ? 'All approved sites'
                                                        : form.data.site_scope
                                                              .map(
                                                                  (id) =>
                                                                      sites.find(
                                                                          (
                                                                              site,
                                                                          ) =>
                                                                              site.id ===
                                                                              id,
                                                                      )?.name ??
                                                                      'Unavailable site',
                                                              )
                                                              .join(', ') ||
                                                          'No sites selected'
                                                }
                                            />
                                            <ReviewRow
                                                label="Priority"
                                                value={humanize(
                                                    form.data.default_priority,
                                                )}
                                            />
                                            <ReviewRow
                                                label="Description"
                                                value={form.data.description}
                                            />
                                        </ReviewCard>
                                        <ReviewCard
                                            title="Form fields"
                                            icon={ListChecks}
                                            onEdit={() => setEditorStep(1)}
                                        >
                                            {form.data.form_schema.fields
                                                .length ? (
                                                form.data.form_schema.fields.map(
                                                    (field, index) => (
                                                        <ReviewRow
                                                            key={index}
                                                            label={
                                                                field.label ||
                                                                `Field ${index + 1}`
                                                            }
                                                            value={`${humanize(field.type)} · ${field.required ? 'Required' : 'Optional'} · ${field.visibility === 'requester' ? 'Requester visible' : 'IT only'}`}
                                                        />
                                                    ),
                                                )
                                            ) : (
                                                <p className="text-sm text-muted-foreground">
                                                    No additional questions.
                                                </p>
                                            )}
                                        </ReviewCard>
                                    </div>
                                ) : null}
                            </fieldset>
                        </form>
                    )}
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                open={leave !== null}
                onClose={() => setLeave(null)}
                onConfirm={() => leave?.()}
                title="Discard this catalogue draft?"
                description="Your unsaved changes will be discarded. The published request and existing submissions will stay unchanged."
                confirmText="Discard draft"
            />

            <Dialog
                open={publishing !== null}
                onOpenChange={(open) =>
                    !open && !publishForm.processing && setPublishing(null)
                }
            >
                <DialogContent
                    style={{
                        width: 'min(92vw, 480px)',
                        maxWidth: 'min(92vw, 480px)',
                    }}
                >
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <Send
                                className="h-4 w-4 text-primary"
                                aria-hidden="true"
                            />
                            Publish request
                        </DialogTitle>
                        <DialogDescription>
                            {publishing?.name} will become available in the
                            service catalogue using service version{' '}
                            {publishing?.form_schema_version}.
                        </DialogDescription>
                    </DialogHeader>
                    <dl className="grid grid-cols-2 gap-3 text-sm">
                        <Metric
                            label="Outcome"
                            value={humanize(publishing?.outcome_type ?? '')}
                        />
                        <Metric
                            label="Approval"
                            value={
                                publishing?.requires_approval
                                    ? 'Required'
                                    : 'Not required'
                            }
                        />
                        <Metric
                            label="Audience"
                            value={
                                publishing?.internal_only
                                    ? 'IT staff'
                                    : 'Approved requesters'
                            }
                        />
                        <Metric
                            label="Fields"
                            value={String(
                                publishing?.form_schema.fields?.length ?? 0,
                            )}
                        />
                        <Metric
                            label="Available at"
                            wrap
                            value={
                                publishing?.site_scope == null
                                    ? 'All approved sites'
                                    : publishing.site_scope
                                          .map(
                                              (id) =>
                                                  sites.find(
                                                      (site) => site.id === id,
                                                  )?.name ?? 'Unavailable site',
                                          )
                                          .join(', ') || 'No sites selected'
                            }
                        />
                    </dl>
                    {Object.entries(publishForm.errors).map(
                        ([key, message]) => (
                            <p
                                key={key}
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                {message}
                            </p>
                        ),
                    )}
                    <DialogFooter>
                        <Button
                            variant="outline"
                            className="min-h-11"
                            onClick={() => setPublishing(null)}
                            disabled={publishForm.processing}
                        >
                            Keep as draft
                        </Button>
                        <Button
                            className="min-h-11"
                            onClick={publish}
                            disabled={publishForm.processing}
                        >
                            {publishForm.processing
                                ? 'Publishing…'
                                : 'Publish request'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={unpublishing !== null}
                onOpenChange={(open) =>
                    !open && !unpublishForm.processing && setUnpublishing(null)
                }
            >
                <DialogContent
                    style={{
                        width: 'min(92vw, 480px)',
                        maxWidth: 'min(92vw, 480px)',
                    }}
                >
                    <form onSubmit={unpublish}>
                        {unpublishForm.errors.expected_version ? (
                            <p
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                {unpublishForm.errors.expected_version}
                            </p>
                        ) : null}
                        <DialogHeader>
                            <DialogTitle className="flex items-center gap-2">
                                <CircleDashed
                                    className="h-4 w-4 text-primary"
                                    aria-hidden="true"
                                />
                                Withdraw request from catalogue
                            </DialogTitle>
                            <DialogDescription>
                                New submissions will stop. Existing requests and
                                their form snapshots remain available.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="mt-5">
                            <Field
                                label="Reason for unpublishing"
                                error={unpublishForm.errors.reason}
                            >
                                <Textarea
                                    required
                                    rows={4}
                                    value={unpublishForm.data.reason}
                                    onChange={(event) =>
                                        unpublishForm.setData(
                                            'reason',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                        </div>
                        <DialogFooter className="mt-6">
                            <Button
                                type="button"
                                variant="outline"
                                className="min-h-11"
                                onClick={() => setUnpublishing(null)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                className="min-h-11"
                                disabled={unpublishForm.processing}
                            >
                                Withdraw from catalogue
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </section>
    );
}

function Metric({
    label,
    value,
    wrap = false,
}: {
    label: string;
    value: string;
    wrap?: boolean;
}) {
    return (
        <div className={wrap ? 'col-span-2 min-w-0' : undefined}>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd
                className={`mt-0.5 font-medium ${wrap ? 'break-words whitespace-normal' : 'truncate'}`}
                title={value}
            >
                {value}
            </dd>
        </div>
    );
}

function Field({
    label,
    error,
    className = '',
    children,
}: {
    label: string;
    error?: string;
    className?: string;
    children: React.ReactNode;
}) {
    return (
        <div className={`space-y-1.5 text-sm font-medium ${className}`}>
            <label className="block space-y-1.5">
                <span>{label}</span>
                {children}
            </label>
            {error ? (
                <p role="alert" className="text-xs text-destructive">
                    {error}
                </p>
            ) : null}
        </div>
    );
}

function Select({
    value,
    options,
    onChange,
    placeholder,
}: {
    value: string;
    options: string[];
    onChange: (value: string) => void;
    placeholder?: string;
}) {
    return (
        <select
            className="min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
            value={value}
            onChange={(event) => onChange(event.target.value)}
        >
            {placeholder ? <option value="">{placeholder}</option> : null}
            {options.map((option) => (
                <option key={option} value={option}>
                    {humanize(option)}
                </option>
            ))}
        </select>
    );
}

function Checkbox({
    label,
    checked,
    onChange,
}: {
    label: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
}) {
    return (
        <label className="flex min-h-11 items-center gap-2 text-sm font-medium">
            <input
                type="checkbox"
                checked={checked}
                onChange={(event) => onChange(event.target.checked)}
            />
            {label}
        </label>
    );
}
