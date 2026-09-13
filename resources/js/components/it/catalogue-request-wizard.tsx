import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import type { ItDraftSnapshot } from '@/hooks/it-ticket-draft-contract';
import { router } from '@inertiajs/react';
import { BookOpen, ClipboardCheck, FileText, Loader2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { catalogueFileError, catalogueFiles } from './catalogue-attachments';
import { CatalogueEntityPicker } from './catalogue-entity-picker';
import {
    CatalogFieldControl,
    type CatalogEntityFieldType,
    type CatalogFieldOption,
    type CatalogFieldOptions,
    type CatalogItem,
    type CatalogValue,
} from './catalogue-request-fields';
import { TicketDraftRecovery } from './ticket-draft-recovery';
import {
    catalogueDraftIdentity,
    useCatalogueDraft,
} from './use-catalogue-draft';
import { useCatalogueSubmission } from './use-catalogue-submission';

const steps = [
    {
        key: 'details',
        label: 'Request details',
        blurb: 'Site and information',
        icon: FileText,
    },
    {
        key: 'review',
        label: 'Review request',
        blurb: 'Check before submitting',
        icon: ClipboardCheck,
    },
] as const;
function initialValues(item: CatalogItem): Record<string, CatalogValue> {
    return Object.fromEntries(
        (item.form_schema.fields ?? []).map((field) => [
            field.key,
            field.default ??
                (field.type === 'boolean'
                    ? false
                    : ['multiselect', 'attachment'].includes(field.type ?? '')
                      ? []
                      : ''),
        ]),
    );
}
const provided = (value: CatalogValue | undefined) =>
    value !== null &&
    value !== undefined &&
    (typeof value === 'string'
        ? value.trim() !== ''
        : Array.isArray(value)
          ? value.length > 0
          : true);

function savedEntries(snapshot: ItDraftSnapshot): [string, string][] {
    try {
        const values: unknown = JSON.parse(
            snapshot.fields.catalogue_values ?? '{}',
        );
        if (!values || typeof values !== 'object' || Array.isArray(values))
            return [];
        return Object.entries(values)
            .filter(([, value]) => !Array.isArray(value) || value.length > 0)
            .map(([key, value]) => [
                key,
                typeof value === 'boolean'
                    ? value
                        ? 'Yes'
                        : 'No'
                    : Array.isArray(value)
                      ? value.join(', ')
                      : String(value ?? 'Not supplied'),
            ]);
    } catch {
        return [];
    }
}

export function CatalogueRequestWizard({
    actorId,
    item,
    fieldOptions,
    onClose,
    onPrivateHidden,
    draftRecoveryEnabled = false,
}: {
    actorId: number;
    item: CatalogItem;
    fieldOptions: CatalogFieldOptions;
    onClose: () => void;
    onPrivateHidden: () => void;
    draftRecoveryEnabled?: boolean;
}) {
    const [initial] = useState(() => ({
        values: initialValues(item),
        siteId:
            item.outcome_type === 'provisioning'
                ? null
                : (item.site_options?.[0]?.id ?? null),
    }));
    const [values, setValues] = useState(initial.values);
    const [siteId, setSiteId] = useState(initial.siteId);
    const [requestedFor, setRequestedFor] = useState<CatalogFieldOption | null>(
        null,
    );
    const [forAnotherPerson, setForAnotherPerson] = useState(false);
    const [labels, setLabels] = useState<
        Record<string, CatalogFieldOption | null>
    >({});
    const [step, setStep] = useState(0);
    const [localErrors, setLocalErrors] = useState<Record<string, string>>({});
    const [confirmation, setConfirmation] = useState<
        'discard' | 'cancel' | 'leave' | null
    >(null);
    const [hidden, setHidden] = useState(false);
    const [draftAwaitingAccess, setDraftAwaitingAccess] = useState(false);
    const status = useRef<HTMLDivElement>(null);
    const form = useRef<HTMLFormElement>(null);
    const heading = useRef<HTMLHeadingElement>(null);
    const allowNavigation = useRef(false);
    const pendingNavigation = useRef<(() => void) | null>(null);
    const [savedIdentity] = useState(() =>
        catalogueDraftIdentity(draftRecoveryEnabled, actorId, item.id),
    );
    const command = useCatalogueSubmission({
        actorId,
        itemId: item.id,
        schemaVersion: item.form_schema_version,
        draftIdentity: savedIdentity,
        onDenied: () => {
            setValues({});
            setLabels({});
            setSiteId(null);
            setRequestedFor(null);
            setForAnotherPerson(false);
            setHidden(true);
            onPrivateHidden();
        },
        onSession: () => {
            setHidden(true);
            onPrivateHidden();
        },
    });
    const errors = { ...command.errors, ...localErrors };
    const fields = item.form_schema.fields ?? [];
    const dirty =
        JSON.stringify(values) !== JSON.stringify(initial.values) ||
        siteId !== initial.siteId ||
        forAnotherPerson;
    const recovery = useCatalogueDraft({
        enabled: draftRecoveryEnabled,
        actorId,
        item,
        command,
        values,
        siteId,
        requestedForId: forAnotherPerson ? (requestedFor?.id ?? null) : null,
        step,
        dirty,
        onDenied: command.deny,
    });
    const resetFields = () => {
        setValues(initial.values);
        setSiteId(initial.siteId);
        setRequestedFor(null);
        setForAnotherPerson(false);
        setLabels({});
        setLocalErrors({});
        setStep(0);
    };
    const resumeSnapshot = (snapshot: ItDraftSnapshot) => {
        if (recovery.schemaChanged) return;
        try {
            const parsed: unknown = JSON.parse(
                snapshot.fields.catalogue_values ?? '{}',
            );
            if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed))
                return;
            const allowed = Object.fromEntries(
                Object.entries(parsed).filter(([key]) =>
                    fields.some(
                        (field) =>
                            field.key === key && field.type !== 'attachment',
                    ),
                ),
            );
            setValues({ ...initial.values, ...allowed } as Record<
                string,
                CatalogValue
            >);
            setSiteId(snapshot.fields.site_id ?? initial.siteId);
            const personId = snapshot.fields.requested_for_user_id;
            setForAnotherPerson(!!personId && personId !== actorId);
            setRequestedFor(
                personId && personId !== actorId
                    ? {
                          id: personId,
                          name: 'Saved employee — checking current access',
                          detail: null,
                      }
                    : null,
            );
            setLabels({});
            setStep(0);
            setDraftAwaitingAccess(false);
        } catch {
            setLocalErrors({
                draft: 'The saved fields could not be read. Keep the original draft and review it before continuing.',
            });
        }
    };
    const uncertain =
        !command.editing &&
        !['committed', 'cancelled', 'denied', 'unavailable'].includes(
            command.phase,
        );
    const draftSessionHidden =
        draftRecoveryEnabled &&
        ['session_expired', 'access_denied'].includes(recovery.draft.state);
    const showPrivateFields =
        !draftRecoveryEnabled ||
        (!draftSessionHidden &&
            !draftAwaitingAccess &&
            !recovery.draft.memoryBlocked);
    useEffect(() => {
        if (draftSessionHidden) {
            setDraftAwaitingAccess(true);
            onPrivateHidden();
        }
    }, [draftSessionHidden, onPrivateHidden]);
    useEffect(() => {
        if (
            recovery.draft.state === 'ready' &&
            !dirty &&
            recovery.draft.attachments.length === 0 &&
            !recovery.draft.memoryBlocked
        )
            setDraftAwaitingAccess(false);
    }, [
        recovery.draft.state,
        dirty,
        recovery.draft.attachments.length,
        recovery.draft.memoryBlocked,
    ]);
    const draftInFlight =
        draftRecoveryEnabled &&
        (recovery.draft.busy ||
            recovery.preparing ||
            recovery.draft.state === 'outcome_unknown');
    const hasDraftWork =
        dirty ||
        (draftRecoveryEnabled && recovery.draft.attachments.length > 0);
    useEffect(() => {
        if (!(command.editing && hasDraftWork) && !uncertain && !draftInFlight)
            return;
        const unload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', unload);
        const remove = router.on('before', (event) => {
            if (allowNavigation.current) return;
            event.preventDefault();
            const visit = event.detail.visit;
            pendingNavigation.current = () => router.visit(visit.url, visit);
            setConfirmation(uncertain || draftInFlight ? 'leave' : 'discard');
        });
        return () => {
            window.removeEventListener('beforeunload', unload);
            remove();
        };
    }, [command.editing, hasDraftWork, uncertain, draftInFlight]);
    const returnToDesk = () => router.visit('/it');
    const close = () => {
        if (command.phase === 'denied') {
            returnToDesk();
            return;
        }
        if (command.pending || draftInFlight) {
            setConfirmation('leave');
            return;
        }
        if (command.editing) {
            if (hasDraftWork) setConfirmation('discard');
            else onClose();
            return;
        }
        if (command.phase === 'committed' || command.phase === 'cancelled') {
            returnToDesk();
            return;
        }
        setConfirmation('leave');
    };
    useEffect(() => {
        if (command.phase === 'validation') setStep(0);
        if (!command.editing) status.current?.focus();
    }, [command.phase, command.editing]);
    const errorSummary = Object.values(errors).join(' ');
    useEffect(() => {
        if (!command.editing) return;
        const frame = requestAnimationFrame(() => {
            const invalid = form.current?.querySelector<HTMLElement>(
                '[aria-invalid="true"]',
            );
            (invalid ?? heading.current)?.focus({ preventScroll: true });
        });
        return () => cancelAnimationFrame(frame);
    }, [step, command.editing, errorSummary]);

    const validate = () => {
        const found: Record<string, string> = {};
        if (item.outcome_type !== 'provisioning' && !siteId)
            found.site_id = 'Choose an approved request Site.';
        if (forAnotherPerson && !requestedFor)
            found.requested_for_user_id =
                'Choose the person this request is for.';
        for (const field of fields) {
            if (
                field.required &&
                !provided(values[field.key]) &&
                !(
                    draftRecoveryEnabled &&
                    field.type === 'attachment' &&
                    recovery.draft.attachments.some(
                        (file) =>
                            file.catalogue_field_key === field.key &&
                            file.state === 'ready',
                    )
                )
            )
                found[`values.${field.key}`] = `${field.label} is required.`;
            if (field.type === 'attachment') {
                const files = catalogueFiles(values[field.key]);
                const problem = catalogueFileError(files, field.max);
                if (problem) found[`values.${field.key}`] = problem;
                else if (
                    files.length > 0 &&
                    field.min &&
                    files.length < field.min
                )
                    found[`values.${field.key}`] =
                        `Choose at least ${field.min} files.`;
            }
        }
        if (
            Object.values(values).reduce<number>(
                (count, value) => count + catalogueFiles(value).length,
                0,
            ) > 5
        )
            found.values =
                'Attach no more than five files across this request.';
        setLocalErrors(found);
        if (Object.keys(found).length > 0) {
            setStep(0);
            return false;
        }
        if (step === 0 && form.current && !form.current.reportValidity())
            return false;
        return true;
    };
    const display = (key: string): string => {
        const field = fields.find((candidate) => candidate.key === key);
        const value = values[key];
        if (draftRecoveryEnabled && field?.type === 'attachment')
            return (
                recovery.draft.attachments
                    .filter((file) => file.catalogue_field_key === key)
                    .map((file) => file.name)
                    .join(', ') || 'Not provided'
            );
        if (!provided(value)) return 'Not provided';
        if (field?.type === 'attachment')
            return catalogueFiles(value)
                .map(
                    (file) =>
                        `${file.name} (${Math.max(1, Math.ceil(file.size / 1024))} KB)`,
                )
                .join(', ');
        if (['employee', 'user', 'asset'].includes(field?.type ?? '')) {
            const option =
                labels[key] ??
                fieldOptions[field?.type as CatalogEntityFieldType]?.find(
                    (entry) => entry.id === Number(value),
                );
            return option?.id === Number(value)
                ? option.name
                : 'Selected record — recheck its availability';
        }
        if (typeof value === 'boolean') return value ? 'Yes' : 'No';
        const choice = (entry: string | number) => {
            const option = field?.options?.find((candidate) =>
                typeof candidate === 'string'
                    ? candidate === String(entry)
                    : String(candidate.value) === String(entry),
            );
            return option && typeof option !== 'string'
                ? option.label
                : String(entry);
        };
        return Array.isArray(value)
            ? value.map((entry) => choice(String(entry))).join(', ')
            : choice(value as string | number);
    };
    const busyLabel =
        command.phase === 'sending'
            ? 'Submitting request…'
            : command.phase === 'recovering'
              ? 'Checking saved result…'
              : 'Confirming cancellation…';
    const formId = `catalogue-request-${item.id}`;
    const title =
        hidden || draftSessionHidden || draftAwaitingAccess
            ? 'Request access and recovery'
            : item.name;
    const completeness = fields.length
        ? Math.round(
              (fields.filter((field) => provided(values[field.key])).length /
                  fields.length) *
                  100,
          )
        : null;

    return (
        <>
            <WizardShell
                open
                maxWidth="min(92vw, 900px)"
                onClose={close}
                onOpenAutoFocus={(event) => {
                    event.preventDefault();
                    (command.editing ? heading.current : status.current)?.focus(
                        { preventScroll: true },
                    );
                }}
                title={title}
                description="Complete, review and submit the published request."
                railIcon={BookOpen}
                railTitle="Service request"
                railSub={
                    hidden
                        ? 'Access and recovery'
                        : `Published version ${item.form_schema_version}`
                }
                steps={steps.map((entry) => ({
                    ...entry,
                    disabled: !command.editing,
                }))}
                stepIndex={step}
                headerLabel={command.editing ? undefined : 'Request status'}
                onStepClick={(next) => {
                    if (command.editing) setStep(next);
                }}
                pct={command.editing ? completeness : null}
                pctLabel="Fields completed"
                footerStart={
                    <Button type="button" variant="outline" onClick={close}>
                        {command.editing ? 'Cancel' : 'Close'}
                    </Button>
                }
                footerEnd={
                    command.editing ? (
                        <>
                            {step > 0 && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setStep(0)}
                                >
                                    Back
                                </Button>
                            )}
                            {step === 0 ? (
                                <Button
                                    key="continue"
                                    type="button"
                                    disabled={
                                        !recovery.canEdit || recovery.draft.busy
                                    }
                                    onClick={() => {
                                        if (validate()) setStep(1);
                                    }}
                                >
                                    Continue
                                </Button>
                            ) : (
                                <Button
                                    key="submit"
                                    type="submit"
                                    form={formId}
                                    disabled={
                                        !recovery.canEdit || recovery.draft.busy
                                    }
                                >
                                    {recovery.preparing
                                        ? 'Saving request details…'
                                        : 'Submit request'}
                                </Button>
                            )}
                        </>
                    ) : null
                }
                success={
                    command.result ? (
                        <WizardSuccessPane
                            title="Request saved"
                            blurb={
                                command.result.reference
                                    ? `${command.result.reference} is saved. Open it to follow its progress.`
                                    : 'Your provisioning request is saved. Open it to follow approval and fulfilment.'
                            }
                            actions={
                                <>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={returnToDesk}
                                    >
                                        Back to IT & Support
                                    </Button>
                                    <Button
                                        type="button"
                                        onClick={() =>
                                            router.visit(command.result!.url)
                                        }
                                    >
                                        View request
                                    </Button>
                                </>
                            }
                        />
                    ) : undefined
                }
            >
                {command.editing ? (
                    <form
                        id={formId}
                        ref={form}
                        onSubmit={(event) => {
                            event.preventDefault();
                            if (
                                !recovery.canEdit ||
                                recovery.draft.busy ||
                                !validate()
                            )
                                return;
                            if (step === 0) {
                                setStep(1);
                                return;
                            }
                            void recovery.submit();
                        }}
                    >
                        <WizardStepPane>
                            {draftRecoveryEnabled && (
                                <div className="mb-5 space-y-3">
                                    <TicketDraftRecovery
                                        draft={{
                                            ...recovery.draft,
                                            save: recovery.save,
                                        }}
                                        snapshot={recovery.snapshot}
                                        hasLocalChanges={dirty}
                                        onResume={(saved) =>
                                            resumeSnapshot(saved.payload)
                                        }
                                        onResumeMemory={(saved) =>
                                            resumeSnapshot(saved.snapshot)
                                        }
                                        onDiscarded={() => {
                                            recovery.forget();
                                            resetFields();
                                            if (recovery.schemaChanged) {
                                                allowNavigation.current = true;
                                                onClose();
                                            }
                                        }}
                                        onStartNew={resetFields}
                                        renderReview={(saved) => (
                                            <div className="space-y-3">
                                                <p>
                                                    Saved request version{' '}
                                                    {
                                                        saved.payload.fields
                                                            .schema_version
                                                    }
                                                    . {saved.attachments.length}{' '}
                                                    original files.
                                                </p>
                                                <dl className="space-y-2">
                                                    {savedEntries(
                                                        saved.payload,
                                                    ).map(([key, value]) => (
                                                        <div key={key}>
                                                            <dt className="text-muted-foreground">
                                                                {!recovery.schemaChanged
                                                                    ? (fields.find(
                                                                          (
                                                                              field,
                                                                          ) =>
                                                                              field.key ===
                                                                              key,
                                                                      )
                                                                          ?.label ??
                                                                      key.replaceAll(
                                                                          '_',
                                                                          ' ',
                                                                      ))
                                                                    : key.replaceAll(
                                                                          '_',
                                                                          ' ',
                                                                      )}
                                                            </dt>
                                                            <dd className="break-words whitespace-pre-wrap">
                                                                {value}
                                                            </dd>
                                                        </div>
                                                    ))}
                                                </dl>
                                            </div>
                                        )}
                                        onRequestRecovery={() =>
                                            void command.recover()
                                        }
                                    />
                                    {recovery.schemaChanged && (
                                        <p role="alert">
                                            The published form changed. This
                                            original draft remains saved. Review
                                            its original version before starting
                                            a new request from the current form.
                                        </p>
                                    )}
                                    {recovery.locatorError && (
                                        <p role="alert">
                                            The browser cannot retain the
                                            recovery reference. Keep this form
                                            open until the reference can be
                                            saved.
                                        </p>
                                    )}
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={
                                            !recovery.canEdit ||
                                            recovery.draft.busy
                                        }
                                        onClick={async () => {
                                            if (await recovery.save()) {
                                                allowNavigation.current = true;
                                                onClose();
                                            }
                                        }}
                                    >
                                        Save and close
                                    </Button>
                                    {recovery.draft.attachments.map((file) => (
                                        <div
                                            key={file.id}
                                            className="flex items-center justify-between gap-3 text-sm"
                                        >
                                            <span>
                                                {fields.find(
                                                    (field) =>
                                                        field.key ===
                                                        file.catalogue_field_key,
                                                )?.label ??
                                                    'Original file field'}{' '}
                                                · {file.name} · {file.state}
                                            </span>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                disabled={recovery.draft.busy}
                                                onClick={() =>
                                                    void recovery.draft.remove(
                                                        file.id,
                                                    )
                                                }
                                            >
                                                Remove {file.name}
                                            </Button>
                                        </div>
                                    ))}
                                </div>
                            )}
                            {showPrivateFields ? (
                                <>
                                    <h2
                                        ref={heading}
                                        tabIndex={-1}
                                        className="text-section-title outline-none"
                                    >
                                        {step === 0
                                            ? 'Request details'
                                            : 'Review request'}
                                    </h2>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {item.name}
                                    </p>
                                    {Object.keys(errors).length > 0 && (
                                        <div
                                            role="alert"
                                            className="mt-4 rounded-xl border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive"
                                        >
                                            <p className="font-semibold">
                                                Check the request details.
                                            </p>
                                            <ul className="mt-1 list-disc pl-5">
                                                {Object.entries(errors).map(
                                                    ([key, error]) => (
                                                        <li key={key}>
                                                            {error}
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                        </div>
                                    )}
                                    {step === 0 ? (
                                        <div className="mt-5 space-y-5">
                                            {item.outcome_type !==
                                                'provisioning' && (
                                                <label className="block space-y-1 text-sm font-medium">
                                                    <span>Request site</span>
                                                    <select
                                                        required
                                                        aria-invalid={Boolean(
                                                            errors.site_id,
                                                        )}
                                                        className="min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm"
                                                        value={siteId ?? ''}
                                                        onChange={(event) => {
                                                            setSiteId(
                                                                event.target
                                                                    .value
                                                                    ? Number(
                                                                          event
                                                                              .target
                                                                              .value,
                                                                      )
                                                                    : null,
                                                            );
                                                            setLocalErrors({});
                                                            setRequestedFor(
                                                                null,
                                                            );
                                                        }}
                                                    >
                                                        <option value="">
                                                            Choose an approved
                                                            site
                                                        </option>
                                                        {item.site_options?.map(
                                                            (site) => (
                                                                <option
                                                                    key={
                                                                        site.id
                                                                    }
                                                                    value={
                                                                        site.id
                                                                    }
                                                                >
                                                                    {site.name}
                                                                </option>
                                                            ),
                                                        )}
                                                    </select>
                                                </label>
                                            )}
                                            {item.can_request_for_others && (
                                                <div className="space-y-3">
                                                    <label className="flex items-center gap-2 text-sm font-medium">
                                                        <Checkbox
                                                            checked={
                                                                forAnotherPerson
                                                            }
                                                            onCheckedChange={(
                                                                checked,
                                                            ) => {
                                                                setForAnotherPerson(
                                                                    checked ===
                                                                        true,
                                                                );
                                                                setRequestedFor(
                                                                    null,
                                                                );
                                                                setLocalErrors(
                                                                    {},
                                                                );
                                                            }}
                                                        />
                                                        Request for another
                                                        person
                                                    </label>
                                                    {forAnotherPerson && (
                                                        <div className="space-y-1">
                                                            <label
                                                                htmlFor="catalogue-requested-for"
                                                                className="text-sm font-medium"
                                                            >
                                                                Requested for
                                                            </label>
                                                            <CatalogueEntityPicker
                                                                actorId={
                                                                    actorId
                                                                }
                                                                itemId={item.id}
                                                                schemaVersion={
                                                                    item.form_schema_version
                                                                }
                                                                purpose="requested-for"
                                                                siteId={siteId}
                                                                fieldKey="requested_for_user_id"
                                                                id="catalogue-requested-for"
                                                                label="Requested for"
                                                                required
                                                                value={
                                                                    requestedFor?.id ??
                                                                    null
                                                                }
                                                                initialSelected={
                                                                    requestedFor ??
                                                                    undefined
                                                                }
                                                                invalid={Boolean(
                                                                    errors.requested_for_user_id,
                                                                )}
                                                                describedBy="catalogue-requested-for-help"
                                                                onChange={(
                                                                    value,
                                                                ) => {
                                                                    if (
                                                                        value ===
                                                                        null
                                                                    )
                                                                        setRequestedFor(
                                                                            null,
                                                                        );
                                                                }}
                                                                onSelectedOption={
                                                                    setRequestedFor
                                                                }
                                                                onAccessLost={
                                                                    command.deny
                                                                }
                                                                onSessionLost={() =>
                                                                    command.pauseForSession(
                                                                        values,
                                                                        siteId,
                                                                        requestedFor?.id ??
                                                                            null,
                                                                    )
                                                                }
                                                            />
                                                            <p
                                                                id="catalogue-requested-for-help"
                                                                className="text-sm text-muted-foreground"
                                                            >
                                                                Choose an
                                                                eligible person
                                                                within your
                                                                approved Sites.
                                                                You remain the
                                                                submitter.
                                                            </p>
                                                        </div>
                                                    )}
                                                </div>
                                            )}
                                            {fields.map((field) => (
                                                <CatalogFieldControl
                                                    key={field.key}
                                                    actorId={actorId}
                                                    itemId={item.id}
                                                    schemaVersion={
                                                        item.form_schema_version
                                                    }
                                                    field={field}
                                                    disabled={
                                                        !recovery.canEdit ||
                                                        (field.type ===
                                                            'attachment' &&
                                                            recovery.draft.busy)
                                                    }
                                                    options={
                                                        fieldOptions[
                                                            field.type as CatalogEntityFieldType
                                                        ] ?? []
                                                    }
                                                    value={values[field.key]}
                                                    error={
                                                        errors[
                                                            `values.${field.key}`
                                                        ] ??
                                                        Object.entries(
                                                            errors,
                                                        ).find(([key]) =>
                                                            key.startsWith(
                                                                `values.${field.key}.`,
                                                            ),
                                                        )?.[1]
                                                    }
                                                    onChange={(value) => {
                                                        if (
                                                            draftRecoveryEnabled &&
                                                            field.type ===
                                                                'attachment'
                                                        ) {
                                                            void (async () => {
                                                                for (const file of catalogueFiles(
                                                                    value,
                                                                )) {
                                                                    if (
                                                                        !(await recovery.upload(
                                                                            file,
                                                                            field.key,
                                                                        ))
                                                                    )
                                                                        break;
                                                                }
                                                            })();
                                                            return;
                                                        }
                                                        command.clearFieldErrors(
                                                            `values.${field.key}`,
                                                        );
                                                        setValues(
                                                            (previous) => ({
                                                                ...previous,
                                                                [field.key]:
                                                                    value,
                                                            }),
                                                        );
                                                        setLocalErrors({});
                                                    }}
                                                    onSelectedOption={(
                                                        option,
                                                    ) =>
                                                        setLabels(
                                                            (previous) => ({
                                                                ...previous,
                                                                [field.key]:
                                                                    option,
                                                            }),
                                                        )
                                                    }
                                                    onAccessLost={command.deny}
                                                    onSessionLost={() =>
                                                        command.pauseForSession(
                                                            values,
                                                            siteId,
                                                            forAnotherPerson
                                                                ? (requestedFor?.id ??
                                                                      null)
                                                                : undefined,
                                                        )
                                                    }
                                                />
                                            ))}
                                        </div>
                                    ) : (
                                        <div className="mt-5 space-y-4">
                                            <ReviewCard
                                                icon={BookOpen}
                                                title="Request"
                                                onEdit={() => setStep(0)}
                                            >
                                                <ReviewRow
                                                    label="Form"
                                                    value={item.name}
                                                />
                                                <ReviewRow
                                                    label="Requested for"
                                                    value={
                                                        forAnotherPerson
                                                            ? requestedFor?.name
                                                            : 'Myself'
                                                    }
                                                />
                                                <ReviewRow
                                                    label="Published version"
                                                    value={
                                                        item.form_schema_version
                                                    }
                                                />
                                                {siteId && (
                                                    <ReviewRow
                                                        label="Request site"
                                                        value={
                                                            item.site_options?.find(
                                                                (site) =>
                                                                    site.id ===
                                                                    siteId,
                                                            )?.name
                                                        }
                                                    />
                                                )}
                                                <ReviewRow
                                                    label="Outcome"
                                                    value={item.outcome_type.replace(
                                                        /_/g,
                                                        ' ',
                                                    )}
                                                />
                                                <ReviewRow
                                                    label="Approval"
                                                    value={
                                                        item.requires_approval
                                                            ? 'Approval required'
                                                            : 'No catalogue approval required'
                                                    }
                                                />
                                            </ReviewCard>
                                            {[
                                                {
                                                    title: 'Request details',
                                                    fields: fields.filter(
                                                        (field) =>
                                                            (field.visibility ??
                                                                'requester') ===
                                                            'requester',
                                                    ),
                                                },
                                                {
                                                    title: 'Internal IT details',
                                                    fields: fields.filter(
                                                        (field) =>
                                                            [
                                                                'internal',
                                                                'restricted',
                                                            ].includes(
                                                                field.visibility ??
                                                                    'requester',
                                                            ),
                                                    ),
                                                },
                                            ]
                                                .filter(
                                                    (group) =>
                                                        group.fields.length >
                                                            0 ||
                                                        group.title ===
                                                            'Request details',
                                                )
                                                .map((group) => (
                                                    <ReviewCard
                                                        key={group.title}
                                                        icon={FileText}
                                                        title={group.title}
                                                        onEdit={() =>
                                                            setStep(0)
                                                        }
                                                    >
                                                        {group.title ===
                                                            'Internal IT details' && (
                                                            <p className="mb-3 text-sm text-muted-foreground">
                                                                Only authorised
                                                                IT staff can see
                                                                these answers.
                                                            </p>
                                                        )}
                                                        {group.fields.length ? (
                                                            group.fields.map(
                                                                (field) => (
                                                                    <ReviewRow
                                                                        key={
                                                                            field.key
                                                                        }
                                                                        label={
                                                                            field.label
                                                                        }
                                                                        value={
                                                                            <span className="break-words whitespace-pre-wrap">
                                                                                {display(
                                                                                    field.key,
                                                                                )}
                                                                            </span>
                                                                        }
                                                                    />
                                                                ),
                                                            )
                                                        ) : (
                                                            <p className="text-sm text-muted-foreground">
                                                                No additional
                                                                details
                                                                required.
                                                            </p>
                                                        )}
                                                    </ReviewCard>
                                                ))}
                                        </div>
                                    )}
                                </>
                            ) : (
                                <p role="status">
                                    Check or resume the original draft above
                                    before reviewing private request details.
                                </p>
                            )}
                        </WizardStepPane>
                    </form>
                ) : (
                    <div
                        ref={status}
                        tabIndex={-1}
                        role="status"
                        className="space-y-4 outline-none"
                    >
                        <h2 className="text-lg font-semibold">
                            {command.phase === 'cancelled'
                                ? 'Request cancelled'
                                : 'Request status'}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {command.pending
                                ? busyLabel
                                : command.phase === 'cancelled'
                                  ? 'This request identity is cancelled. Any delayed submission using it will be refused.'
                                  : command.message}
                        </p>
                        {command.pending ? (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={command.stopWaiting}
                            >
                                <Loader2 className="h-4 w-4 animate-spin" />
                                Stop waiting
                            </Button>
                        ) : command.phase === 'denied' ||
                          command.phase === 'unavailable' ||
                          command.phase === 'cancelled' ? (
                            <Button type="button" onClick={returnToDesk}>
                                Return to IT & Support
                            </Button>
                        ) : (
                            <div className="flex flex-wrap gap-2">
                                {command.phase === 'session' && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        asChild
                                    >
                                        <a
                                            href="/login"
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            Sign in again
                                        </a>
                                    </Button>
                                )}
                                <Button
                                    type="button"
                                    onClick={() => void command.recover()}
                                >
                                    Check saved result
                                </Button>
                                {command.canRetry &&
                                    ['unknown', 'not_found'].includes(
                                        command.phase,
                                    ) && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => void command.retry()}
                                        >
                                            Retry original request
                                        </Button>
                                    )}
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setConfirmation('cancel')}
                                >
                                    Cancel unconfirmed request
                                </Button>
                            </div>
                        )}
                    </div>
                )}
            </WizardShell>
            <ConfirmDialog
                open={confirmation !== null}
                onClose={() => {
                    pendingNavigation.current = null;
                    setConfirmation(null);
                }}
                title={
                    confirmation === 'discard'
                        ? 'Discard this draft?'
                        : confirmation === 'cancel'
                          ? 'Cancel the unconfirmed request?'
                          : 'Leave this unconfirmed request?'
                }
                description={
                    confirmation === 'discard'
                        ? 'Your unsaved details will be discarded.'
                        : confirmation === 'cancel'
                          ? 'If the request already saved, its existing ticket or workflow will be kept and shown. Otherwise delayed submissions using this identity will be refused.'
                          : command.recoveryStored
                            ? 'The server may still finish. Only the request identity will be kept for recovery; details are not stored in the browser. This does not cancel the request.'
                            : 'The server may still finish. Browser recovery storage is unavailable, so leaving will lose this recovery identity. Check the saved result before leaving. This does not cancel the request.'
                }
                confirmText={
                    confirmation === 'discard'
                        ? 'Discard draft'
                        : confirmation === 'cancel'
                          ? 'Confirm cancellation'
                          : 'Leave and check later'
                }
                onConfirm={async () => {
                    if (confirmation === 'cancel') {
                        void command.cancel();
                        return;
                    }
                    if (confirmation === 'discard' && draftRecoveryEnabled) {
                        if (!(await recovery.draft.discard())) return;
                        recovery.forget();
                    }
                    allowNavigation.current = true;
                    if (confirmation === 'leave') command.stopWaiting();
                    if (pendingNavigation.current) pendingNavigation.current();
                    else if (confirmation === 'discard') onClose();
                    else returnToDesk();
                }}
            />
        </>
    );
}
