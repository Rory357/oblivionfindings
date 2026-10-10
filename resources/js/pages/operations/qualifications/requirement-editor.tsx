import { ConfirmDialog } from '@/components/confirm-dialog';
import { RecordPicker } from '@/components/people-locations/record-picker';
import {
    QualificationMappingField,
    qualificationMappingLabel,
    type QualificationMapping,
    type QualificationOption,
} from '@/components/rostering/qualification-requirement-fields';
import { object } from '@/components/rostering/workforce-settings-outcome';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow, WizardShell } from '@/components/wizard/shell';
import { router } from '@inertiajs/react';
import { Award, BadgeCheck, FileText } from 'lucide-react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';

export type QualificationClient = {
    id: number;
    first_name: string;
    last_name: string;
    site_id: number;
};
export type QualificationContext = {
    id: number;
    name: string;
    site_id: number | null;
    is_active?: boolean;
};
export type QualificationRequirement = {
    id: number;
    qualification_name: string;
    qualification_type?: string | null;
    description?: string | null;
    is_mandatory: boolean;
    service_context_id?: number | null;
    service_context?: {
        id: number;
        name: string;
        site_id: number | null;
    } | null;
    mapping?: QualificationMapping;
    client: { id: number; first_name: string; last_name: string } | null;
};
type Values = {
    client_id: number | null;
    qualification_name: string;
    qualification_type: string | null;
    description: string | null;
    is_mandatory: boolean;
    service_context_id: number | null;
    hr_compliance_requirement_id: number | null;
};
export function matchesQualificationResult(
    page: unknown,
    actorId: number,
    action: 'created' | 'updated' | 'deleted',
    id: number | null,
    values: Values | null,
) {
    const props = object(object(page)?.props);
    const receipt = object(
        object(props?.flash)?.qualification_requirement_result,
    );
    if (
        !Number.isSafeInteger(actorId) ||
        actorId < 1 ||
        !receipt ||
        object(object(props?.auth)?.user)?.id !== actorId ||
        object(props?.flash)?.error ||
        Object.keys(object(props?.errors) ?? {}).length ||
        receipt.actor_id !== actorId ||
        receipt.action !== action ||
        !Number.isSafeInteger(receipt.requirement_id) ||
        Number(receipt.requirement_id) < 1 ||
        (id !== null && receipt.requirement_id !== id)
    )
        return false;
    if (action === 'deleted') return receipt.values === null;
    const saved = object(receipt.values);
    return (
        !!saved &&
        !!values &&
        Object.keys(saved).length === Object.keys(values).length &&
        Object.entries(values).every(([key, value]) => saved[key] === value)
    );
}
const UNKNOWN =
    'The result could not be confirmed. This requirement may already have saved. Your entries are kept here. Close and check the current requirements before trying again.';
export function QualificationRequirementEditor({
    requirement,
    actorId,
    canEdit,
    clients,
    contexts,
    options,
    onClose,
    onSaved,
}: {
    requirement: QualificationRequirement | null;
    actorId: number;
    canEdit: boolean;
    clients: QualificationClient[];
    contexts: QualificationContext[];
    options: QualificationOption[];
    onClose: () => void;
    onSaved: () => void;
}) {
    const [draft, setDraft] = useState<Values>(() => ({
        client_id: requirement?.client?.id ?? null,
        qualification_name: requirement?.qualification_name ?? '',
        qualification_type: requirement
            ? (requirement.qualification_type ?? null)
            : 'certification',
        description: requirement?.description ?? null,
        is_mandatory: requirement?.is_mandatory ?? true,
        service_context_id: requirement?.service_context_id ?? null,
        hr_compliance_requirement_id:
            requirement?.mapping?.requirement_id ?? null,
    }));
    const [initial] = useState(draft);
    const [ownerActor] = useState(actorId);
    const busy = useRef(false),
        mounted = useRef(true),
        latestActor = useRef(actorId);
    useLayoutEffect(() => {
        latestActor.current = actorId;
    }, [actorId]);
    const [step, setStep] = useState(0),
        [processing, setProcessing] = useState(false),
        [uncertain, setUncertain] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({}),
        [message, setMessage] = useState(''),
        [discard, setDiscard] = useState(false);
    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
        };
    }, []);
    const identityChanged = ownerActor !== actorId;
    const locked = processing || uncertain || !canEdit || identityChanged;
    const client = clients.find((row) => row.id === draft.client_id);
    const clientName = client
        ? `${client.first_name} ${client.last_name}`
        : requirement?.client
          ? `${requirement.client.first_name} ${requirement.client.last_name}`
          : 'Choose a Client';
    const contextOptions = contexts.filter(
        (row) => row.site_id === null || row.site_id === client?.site_id,
    );
    const contextName =
        contextOptions.find((row) => row.id === draft.service_context_id)
            ?.name ??
        (draft.service_context_id === null
            ? 'All service contexts for this Client'
            : (requirement?.service_context?.name ??
              'Existing service context unavailable'));
    const dirty = JSON.stringify(initial) !== JSON.stringify(draft);
    useEffect(() => {
        if (!dirty && !processing && !uncertain) return;
        const guard = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', guard);
        return () => window.removeEventListener('beforeunload', guard);
    }, [dirty, processing, uncertain]);
    const close = () => {
        if (busy.current) return;
        if (dirty && !uncertain) {
            setDiscard(true);
            return;
        }
        onClose();
    };
    const go = (next: number) => {
        if (locked) return;
        const invalid: Record<string, string> = {};
        if (!draft.client_id) invalid.client_id = 'Choose a Client.';
        if (!draft.qualification_name.trim())
            invalid.qualification_name = 'Enter a requirement name.';
        if (next > 0 && Object.keys(invalid).length) {
            setErrors(invalid);
            setStep(0);
            return;
        }
        setErrors({});
        setStep(next);
    };
    const save = () => {
        if (locked || busy.current) return;
        const values = {
            ...draft,
            qualification_name: draft.qualification_name.trim(),
            description: draft.description?.trim() || null,
        };
        if (!values.client_id || !values.qualification_name) {
            go(1);
            return;
        }
        busy.current = true;
        setProcessing(true);
        setMessage('');
        setErrors({});
        let settled = false;
        const callbacks = {
            preserveScroll: true,
            onSuccess: (page: unknown) => {
                if (!mounted.current) return;
                settled = true;
                if (
                    ownerActor === latestActor.current &&
                    matchesQualificationResult(
                        page,
                        ownerActor,
                        requirement ? 'updated' : 'created',
                        requirement?.id ?? null,
                        values,
                    )
                ) {
                    onSaved();
                } else {
                    setUncertain(true);
                    setMessage(UNKNOWN);
                }
            },
            onError: (issues: Record<string, string>) => {
                if (!mounted.current) return;
                settled = true;
                setErrors(issues);
                setMessage(
                    'The requirement was not saved. Review the highlighted details.',
                );
                setStep(issues.hr_compliance_requirement_id ? 1 : 0);
            },
            onFinish: () => {
                busy.current = false;
                if (!mounted.current) return;
                setProcessing(false);
                if (!settled) {
                    setUncertain(true);
                    setMessage(UNKNOWN);
                }
            },
        };
        try {
            if (requirement)
                router.put(
                    `/operations/qualifications/${requirement.id}`,
                    values,
                    callbacks,
                );
            else router.post('/operations/qualifications', values, callbacks);
        } catch {
            busy.current = false;
            setProcessing(false);
            setUncertain(true);
            setMessage(UNKNOWN);
        }
    };
    return (
        <>
            <WizardShell
                open
                onClose={close}
                title={
                    requirement
                        ? 'Edit qualification requirement'
                        : 'Add qualification requirement'
                }
                description="Set the Client requirement, link its HR evidence and review the change."
                railIcon={Award}
                railTitle="Qualification"
                railSub={
                    requirement
                        ? 'Update a requirement'
                        : 'Add a Client requirement'
                }
                frontline
                steps={[
                    {
                        key: 'details',
                        label: 'Details',
                        blurb: 'Client and requirement',
                        icon: FileText,
                        disabled: locked,
                    },
                    {
                        key: 'evidence',
                        label: 'Evidence',
                        blurb: 'Recognised qualification',
                        icon: Award,
                        disabled: locked,
                    },
                    {
                        key: 'review',
                        label: 'Review',
                        blurb: 'Check before saving',
                        icon: BadgeCheck,
                        disabled: locked,
                    },
                ]}
                stepIndex={step}
                onStepClick={go}
                footerStart={
                    <Button
                        variant="outline"
                        disabled={processing}
                        onClick={close}
                    >
                        {uncertain ? 'Close and check requirements' : 'Cancel'}
                    </Button>
                }
                footerEnd={
                    step < 2 ? (
                        <Button disabled={locked} onClick={() => go(step + 1)}>
                            Continue
                        </Button>
                    ) : (
                        <Button disabled={locked} onClick={save}>
                            {processing ? 'Saving…' : 'Save requirement'}
                        </Button>
                    )
                }
            >
                {message ? (
                    <p
                        role="alert"
                        className="mb-4 rounded-lg border p-3 text-sm"
                    >
                        {message}
                    </p>
                ) : null}
                {identityChanged || !canEdit ? (
                    <p
                        role="alert"
                        className="mb-4 text-sm text-status-warning"
                    >
                        Your current access does not allow saving this
                        requirement. Your entries are retained.
                    </p>
                ) : null}
                {Object.keys(errors).length ? (
                    <div
                        role="alert"
                        className="mb-4 text-sm text-status-critical"
                    >
                        {Object.values(errors).map((error, index) => (
                            <p key={index}>{error}</p>
                        ))}
                    </div>
                ) : null}
                <fieldset disabled={locked} className="min-w-0 space-y-5">
                    {step === 0 ? (
                        <>
                            <h3 className="text-lg font-semibold">
                                Client requirement
                            </h3>
                            {requirement ? (
                                <div>
                                    <p className="text-sm font-medium">
                                        Client
                                    </p>
                                    <p>{clientName}</p>
                                </div>
                            ) : (
                                <RecordPicker
                                    label="Client"
                                    value={
                                        draft.client_id === null
                                            ? ''
                                            : String(draft.client_id)
                                    }
                                    options={clients.map((row) => ({
                                        value: String(row.id),
                                        label: `${row.first_name} ${row.last_name}`,
                                    }))}
                                    onChange={(id) =>
                                        setDraft({
                                            ...draft,
                                            client_id: Number(id),
                                            service_context_id: null,
                                        })
                                    }
                                />
                            )}
                            <div className="space-y-2">
                                <Label htmlFor="qualification-name">
                                    Requirement name
                                </Label>
                                <Input
                                    id="qualification-name"
                                    maxLength={255}
                                    value={draft.qualification_name}
                                    onChange={(event) =>
                                        setDraft({
                                            ...draft,
                                            qualification_name:
                                                event.target.value,
                                        })
                                    }
                                />
                            </div>
                            <RecordPicker
                                label="Service context"
                                value={
                                    draft.service_context_id === null
                                        ? 'all'
                                        : String(draft.service_context_id)
                                }
                                options={[
                                    {
                                        value: 'all',
                                        label: 'All service contexts for this Client',
                                    },
                                    ...(!contextOptions.some(
                                        (row) =>
                                            row.id === draft.service_context_id,
                                    ) && draft.service_context_id !== null
                                        ? [
                                              {
                                                  value: String(
                                                      draft.service_context_id,
                                                  ),
                                                  label: contextName,
                                                  description:
                                                      'Previously recorded choice',
                                              },
                                          ]
                                        : []),
                                    ...contextOptions.map((row) => ({
                                        value: String(row.id),
                                        label: row.name,
                                    })),
                                ]}
                                onChange={(id) =>
                                    setDraft({
                                        ...draft,
                                        service_context_id:
                                            id === 'all' ? null : Number(id),
                                    })
                                }
                            />
                            <div className="flex items-center justify-between gap-3 rounded-lg border p-3">
                                <Label htmlFor="qualification-mandatory">
                                    Mandatory for this Client
                                </Label>
                                <Switch
                                    id="qualification-mandatory"
                                    checked={draft.is_mandatory}
                                    onCheckedChange={(checked) =>
                                        setDraft({
                                            ...draft,
                                            is_mandatory: checked,
                                        })
                                    }
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="qualification-description">
                                    Guidance
                                </Label>
                                <Textarea
                                    id="qualification-description"
                                    value={draft.description ?? ''}
                                    onChange={(event) =>
                                        setDraft({
                                            ...draft,
                                            description: event.target.value,
                                        })
                                    }
                                />
                            </div>
                        </>
                    ) : step === 1 ? (
                        <>
                            <h3 className="text-lg font-semibold">
                                Qualification evidence
                            </h3>
                            <QualificationMappingField
                                value={draft.hr_compliance_requirement_id}
                                options={options}
                                mapping={requirement?.mapping}
                                disabled={locked}
                                onChange={(id) =>
                                    setDraft({
                                        ...draft,
                                        hr_compliance_requirement_id: id,
                                    })
                                }
                            />
                            <p className="text-sm text-muted-foreground">
                                Unlinked mandatory requirements follow the
                                warning or blocking choice in Workforce
                                settings. Missing or expired evidence for a
                                linked mandatory qualification prevents
                                assignment.
                            </p>
                        </>
                    ) : (
                        <ReviewCard
                            icon={BadgeCheck}
                            title="Review qualification requirement"
                            onEdit={() => go(0)}
                        >
                            <ReviewRow label="Client" value={clientName} />
                            <ReviewRow
                                label="Requirement"
                                value={draft.qualification_name}
                            />
                            <ReviewRow
                                label="Service context"
                                value={contextName}
                            />
                            <ReviewRow
                                label="Category"
                                value={
                                    draft.is_mandatory
                                        ? 'Mandatory'
                                        : 'Optional'
                                }
                            />
                            <ReviewRow
                                label="Guidance"
                                value={
                                    draft.description ||
                                    'No additional guidance'
                                }
                            />
                            <ReviewRow
                                label="Recognised qualification"
                                value={
                                    options.find(
                                        (row) =>
                                            row.id ===
                                            draft.hr_compliance_requirement_id,
                                    )?.name ??
                                    qualificationMappingLabel(
                                        draft.hr_compliance_requirement_id ===
                                            null
                                            ? null
                                            : requirement?.mapping,
                                    )
                                }
                            />
                        </ReviewCard>
                    )}
                </fieldset>
            </WizardShell>
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={onClose}
                title="Discard requirement changes?"
                description="Your unsaved entries will be lost."
                confirmText="Discard changes"
                cancelText="Keep editing"
                frontline
            />
        </>
    );
}

export function QualificationRemoveDialog({
    requirement,
    actorId,
    allowed,
    onClose,
    onRemoved,
}: {
    requirement: QualificationRequirement;
    actorId: number;
    allowed: boolean;
    onClose: () => void;
    onRemoved: () => void;
}) {
    const [processing, setProcessing] = useState(false),
        [uncertain, setUncertain] = useState(false),
        [error, setError] = useState('');
    const busy = useRef(false),
        requestActor = useRef(actorId),
        currentActor = useRef(actorId),
        mounted = useRef(true);
    useLayoutEffect(() => {
        currentActor.current = actorId;
    }, [actorId]);
    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
        };
    }, []);
    const check = () => {
        onClose();
        router.reload({
            only: ['requirements', 'stats'],
            preserveScroll: true,
        });
    };
    const remove = () => {
        if (
            uncertain ||
            !allowed ||
            requestActor.current !== currentActor.current
        ) {
            check();
            return;
        }
        if (busy.current) return;
        busy.current = true;
        setProcessing(true);
        setError('');
        let settled = false;
        try {
            router.delete(`/operations/qualifications/${requirement.id}`, {
                preserveScroll: true,
                onSuccess: (page) => {
                    if (!mounted.current) return;
                    settled = true;
                    if (
                        requestActor.current === currentActor.current &&
                        matchesQualificationResult(
                            page,
                            requestActor.current,
                            'deleted',
                            requirement.id,
                            null,
                        )
                    )
                        onRemoved();
                    else {
                        setUncertain(true);
                        setError(
                            'Removal could not be confirmed. Check the current requirements before another attempt.',
                        );
                    }
                },
                onError: (errors) => {
                    if (mounted.current) {
                        settled = true;
                        setError(Object.values(errors).join(' '));
                    }
                },
                onFinish: () => {
                    busy.current = false;
                    if (!mounted.current) return;
                    setProcessing(false);
                    if (!settled) {
                        setUncertain(true);
                        setError(
                            'Removal could not be confirmed. Check the current requirements before another attempt.',
                        );
                    }
                },
            });
        } catch {
            busy.current = false;
            setProcessing(false);
            setUncertain(true);
            setError(
                'Removal could not be confirmed. Check the current requirements before another attempt.',
            );
        }
    };
    return (
        <ConfirmDialog
            open
            onClose={() => {
                if (!busy.current) onClose();
            }}
            onConfirm={remove}
            title="Remove qualification requirement?"
            description={
                <>
                    <p>
                        {requirement.qualification_name} will no longer apply to{' '}
                        {requirement.client
                            ? requirement.client.first_name +
                              ' ' +
                              requirement.client.last_name
                            : 'this Client'}
                        .
                    </p>
                    {error ? (
                        <p role="alert" className="mt-3">
                            {error}
                        </p>
                    ) : null}
                </>
            }
            confirmText={
                uncertain || !allowed
                    ? 'Check current requirements'
                    : 'Remove requirement'
            }
            processing={processing}
            variant={uncertain ? 'default' : 'destructive'}
            frontline
        />
    );
}
