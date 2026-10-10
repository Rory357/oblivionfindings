import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    HouseQualificationFields,
    copyHouseQualification,
    houseQualificationErrors,
    houseQualificationSummary,
    newHouseQualification,
    qualificationMappingLabel,
    type HouseQualificationOptions,
    type HouseQualificationValues,
    type QualificationMapping,
} from '@/components/rostering/qualification-requirement-fields';
import { object } from '@/components/rostering/workforce-settings-outcome';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import { ReviewCard, ReviewRow, WizardShell } from '@/components/wizard/shell';
import type { SharedData } from '@/types';
import { router, useForm, usePage } from '@inertiajs/react';
import {
    Award,
    BadgeCheck,
    GraduationCap,
    Pencil,
    Plus,
    Trash2,
} from 'lucide-react';
import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import {
    matchesHouseRequirementResult,
    type HouseRequirementIntent,
    type HouseRequirementValues,
} from './house-requirement-outcome';
import {
    SiteProfileEmptyState,
    SiteProfileLockedState,
} from './site-profile-states';

type StaffRequirement = Partial<HouseQualificationValues> & {
    mapping?: QualificationMapping;
    id: number;
    name: string;
    category?: string | null;
    description?: string | null;
    certification_required: boolean;
    expiry_period_months?: number | null;
};

export type SiteStaffRequirementsData = Partial<HouseQualificationOptions> & {
    locked: boolean;
    can_manage: boolean;
    items: StaffRequirement[];
};

type RequirementForm = HouseQualificationValues & {
    requirement_name: string;
    category: 'mandatory' | 'recommended' | 'specialist';
    description: string;
    certification_required: boolean;
    expiry_period_months: string;
};

const EMPTY_FORM: RequirementForm = {
    ...newHouseQualification(),
    requirement_name: '',
    category: 'mandatory',
    description: '',
    certification_required: false,
    expiry_period_months: '',
};

export function SiteProfileStaffRequirements({
    siteId,
    data,
}: {
    siteId: number;
    data: SiteStaffRequirementsData;
}) {
    const [dialogOpen, setDialogOpen] = useState(false);
    const [step, setStep] = useState(0);
    const [localErrors, setLocalErrors] = useState<Record<string, string>>({});
    const [editing, setEditing] = useState<StaffRequirement | null>(null);
    const [deleting, setDeleting] = useState<StaffRequirement | null>(null);
    const form = useForm<RequirementForm>(EMPTY_FORM);
    const actorId = usePage<SharedData>().props.auth.user.id;
    const [owner, setOwner] = useState({ actorId, siteId });
    const [initial, setInitial] = useState(EMPTY_FORM);
    const [processing, setProcessing] = useState(false);
    const [uncertain, setUncertain] = useState(false);
    const [message, setMessage] = useState('');
    const [discard, setDiscard] = useState(false);
    const pending = useRef(false),
        held = useRef(false),
        alive = useRef(true);
    const latest = useRef({ actorId, siteId, canManage: data.can_manage });
    useLayoutEffect(() => {
        latest.current = { actorId, siteId, canManage: data.can_manage };
    }, [actorId, siteId, data.can_manage]);
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
        };
    }, []);
    const identityChanged =
        owner.actorId !== actorId || owner.siteId !== siteId;
    const locked =
        processing || uncertain || identityChanged || !data.can_manage;
    const dirty =
        dialogOpen && JSON.stringify(initial) !== JSON.stringify(form.data);
    useEffect(() => {
        if (!dirty && !processing && !uncertain) return;
        const guard = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', guard);
        const remove = router.on('before', (event) => {
            if (
                event.detail.visit.method === 'get' &&
                !event.detail.visit.only.length
            ) {
                event.preventDefault();
                setMessage(
                    'Finish or close this form before leaving. Your entries are kept here.',
                );
            }
        });
        return () => {
            window.removeEventListener('beforeunload', guard);
            remove();
        };
    }, [dirty, processing, uncertain]);
    const grouped = useMemo(
        () =>
            data.items.reduce<Record<string, StaffRequirement[]>>(
                (groups, item) => {
                    const key = item.category || 'mandatory';
                    groups[key] = [...(groups[key] ?? []), item];
                    return groups;
                },
                {},
            ),
        [data.items],
    );

    if (data.locked) {
        return <SiteProfileLockedState label="Staff requirements" />;
    }

    const openCreate = () => {
        if (pending.current) return;
        setEditing(null);
        const draft = {
            ...EMPTY_FORM,
            ...newHouseQualification(
                data.new_requirement_defaults
                    ? (data as HouseQualificationOptions)
                    : undefined,
            ),
        };
        form.setData(draft);
        setInitial(draft);
        setOwner({ actorId, siteId });
        held.current = false;
        setUncertain(false);
        setMessage('');
        setStep(0);
        setLocalErrors({});
        form.clearErrors();
        setDialogOpen(true);
    };

    const openEdit = (requirement: StaffRequirement) => {
        if (pending.current) return;
        setEditing(requirement);
        setStep(0);
        setLocalErrors({});
        const draft = {
            ...copyHouseQualification(requirement),
            requirement_name: requirement.name,
            category: (requirement.category ||
                'mandatory') as RequirementForm['category'],
            description: requirement.description || '',
            certification_required: requirement.certification_required,
            expiry_period_months: requirement.expiry_period_months
                ? String(requirement.expiry_period_months)
                : '',
        };
        form.setData(draft);
        setInitial(draft);
        setOwner({ actorId, siteId });
        held.current = false;
        setUncertain(false);
        setMessage('');
        form.clearErrors();
        setDialogOpen(true);
    };

    const goToStep = (next: number) => {
        if (locked || pending.current) return;
        if (next > 0 && !form.data.requirement_name.trim()) {
            setLocalErrors({ requirement_name: 'Enter a requirement name.' });
            setStep(0);
            return;
        }
        const errors = houseQualificationErrors(form.data);
        if (next > 1 && Object.keys(errors).length) {
            setLocalErrors(errors);
            setStep(1);
            return;
        }
        setLocalErrors({});
        setStep(next);
    };
    const closeEditor = () => {
        if (pending.current) return;
        if (dirty || uncertain) setDiscard(true);
        else setDialogOpen(false);
    };
    const run = (
        intent: HouseRequirementIntent,
        send: (options: NonNullable<Parameters<typeof router.post>[2]>) => void,
    ) => {
        if (locked || pending.current || held.current) return;
        pending.current = true;
        setProcessing(true);
        setMessage('');
        form.clearErrors();
        let settled = false;
        const sameOwner = () =>
            latest.current.actorId === intent.actor_id &&
            latest.current.siteId === intent.site_id &&
            latest.current.canManage;
        const unknown = () => {
            if (!alive.current || settled) return;
            settled = true;
            held.current = true;
            setUncertain(true);
            setMessage(
                'The result could not be confirmed. Your change may already have been saved. Check the current requirements before making another change. Your entries are kept here.',
            );
        };
        const finish = () => {
            pending.current = false;
            if (!alive.current) return;
            if (!settled) unknown();
            setProcessing(false);
        };
        try {
            send({
                preserveScroll: true,
                onSuccess: (page) => {
                    if (!alive.current || settled) return;
                    if (
                        !sameOwner() ||
                        !matchesHouseRequirementResult(page, intent)
                    ) {
                        unknown();
                        return;
                    }
                    settled = true;
                    setDialogOpen(false);
                    setDeleting(null);
                    router.reload({
                        only: ['staffRequirementsData'],
                        preserveScroll: true,
                    });
                },
                onError: (errors) => {
                    if (!alive.current || settled) return;
                    if (!sameOwner() || !Object.keys(errors).length) {
                        unknown();
                        return;
                    }
                    settled = true;
                    form.setError(errors);
                    setMessage(
                        'The change was not saved. Review the details below.',
                    );
                    setStep(
                        errors.hr_compliance_requirement_id ||
                            errors.applicability_mode ||
                            errors.minimum_qualified_staff
                            ? 1
                            : 0,
                    );
                },
                onCancel: unknown,
                onFinish: finish,
            });
        } catch {
            unknown();
            finish();
        }
    };
    const checkCurrent = () => {
        if (pending.current || identityChanged) return;
        pending.current = true;
        setProcessing(true);
        let checked = false;
        const failed = () => {
            if (alive.current)
                setMessage(
                    'Current requirements could not be checked. Your entries are retained. Try checking again.',
                );
        };
        const finish = () => {
            pending.current = false;
            if (!alive.current) return;
            if (!checked) failed();
            setProcessing(false);
        };
        try {
            router.reload({
                only: ['staffRequirementsData', 'site', 'auth'],
                preserveScroll: true,
                onSuccess: (page) => {
                    if (!alive.current) return;
                    const props = object(page.props);
                    const current = object(props?.staffRequirementsData);
                    if (
                        latest.current.actorId !== owner.actorId ||
                        latest.current.siteId !== owner.siteId ||
                        object(object(props?.auth)?.user)?.id !==
                            owner.actorId ||
                        object(props?.site)?.id !== owner.siteId ||
                        current?.locked !== false ||
                        !Array.isArray(current.items)
                    )
                        return;
                    checked = true;
                    setMessage(
                        'The current requirements have been loaded below. This does not confirm the earlier save. Close this form to review the list, then edit an existing requirement or add one if needed. Your entries remain here until you close.',
                    );
                },
                onFinish: finish,
            });
        } catch {
            finish();
        }
    };
    const submit = () => {
        if (locked || pending.current || held.current) return;
        const errors = houseQualificationErrors(form.data);
        if (Object.keys(errors).length) {
            setLocalErrors(errors);
            setStep(1);
            return;
        }
        if (!form.data.requirement_name.trim()) {
            goToStep(1);
            return;
        }
        const values: HouseRequirementValues = {
            site_id: siteId,
            requirement_name: form.data.requirement_name.trim(),
            category: form.data.category,
            description: form.data.description.trim() || null,
            certification_required: form.data.certification_required,
            expiry_period_months:
                form.data.expiry_period_months === ''
                    ? null
                    : Number(form.data.expiry_period_months),
            hr_compliance_requirement_id:
                form.data.hr_compliance_requirement_id,
            applicability_mode: form.data.applicability_mode,
            minimum_qualified_staff:
                form.data.applicability_mode === 'minimum_staff'
                    ? Number(form.data.minimum_qualified_staff)
                    : null,
            is_active: true,
        };
        const intent: HouseRequirementIntent = {
            actor_id: owner.actorId,
            site_id: owner.siteId,
            request_id: crypto.randomUUID(),
            action: editing ? 'updated' : 'created',
            requirement_id: editing?.id ?? null,
            values,
        };
        run(intent, (options) =>
            editing
                ? router.put(
                      `/sites/${siteId}/staff-requirements/${editing.id}`,
                      { ...values, request_id: intent.request_id },
                      options,
                  )
                : router.post(
                      `/sites/${siteId}/staff-requirements`,
                      { ...values, request_id: intent.request_id },
                      options,
                  ),
        );
    };

    return (
        <div className="space-y-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold">
                        Staff requirements
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Skills and certifications staff need before working at
                        this Site, including whether every worker needs them or
                        a qualified group can cover the duty.
                    </p>
                </div>
                {data.can_manage ? (
                    <Button
                        type="button"
                        className="min-h-11"
                        onClick={openCreate}
                    >
                        <Plus className="mr-2 h-4 w-4" /> Add requirement
                    </Button>
                ) : null}
            </div>

            {data.items.length ? (
                <div className="space-y-4">
                    {(['mandatory', 'recommended', 'specialist'] as const).map(
                        (category) => {
                            const items = grouped[category] ?? [];
                            if (!items.length) return null;
                            return (
                                <Card key={category}>
                                    <CardHeader>
                                        <CardTitle className="flex items-center gap-2 text-base capitalize">
                                            {category === 'mandatory' ? (
                                                <BadgeCheck className="h-4 w-4" />
                                            ) : (
                                                <GraduationCap className="h-4 w-4" />
                                            )}
                                            {category}
                                            <Badge variant="outline">
                                                {items.length}
                                            </Badge>
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="divide-y">
                                        {items.map((requirement) => (
                                            <div
                                                key={requirement.id}
                                                className="flex flex-wrap items-start gap-3 py-3"
                                            >
                                                <span className="rounded-lg bg-primary/10 p-2 text-primary">
                                                    {requirement.certification_required ? (
                                                        <Award className="h-5 w-5" />
                                                    ) : (
                                                        <GraduationCap className="h-5 w-5" />
                                                    )}
                                                </span>
                                                <div className="min-w-0 flex-1">
                                                    <p className="font-semibold">
                                                        {requirement.name}
                                                    </p>
                                                    <p className="text-sm text-muted-foreground">
                                                        {requirement.description ||
                                                            'No additional guidance recorded.'}
                                                    </p>
                                                    <p className="mt-2 text-sm">
                                                        {houseQualificationSummary(
                                                            requirement,
                                                        )}
                                                    </p>
                                                    <p className="text-sm text-muted-foreground">
                                                        {qualificationMappingLabel(
                                                            requirement.mapping,
                                                        )}
                                                    </p>
                                                    <div className="mt-2 flex flex-wrap gap-2">
                                                        {requirement.certification_required ? (
                                                            <Badge variant="outline">
                                                                Certification
                                                                required
                                                            </Badge>
                                                        ) : null}
                                                        {requirement.expiry_period_months ? (
                                                            <Badge variant="outline">
                                                                Renew every{' '}
                                                                {
                                                                    requirement.expiry_period_months
                                                                }{' '}
                                                                months
                                                            </Badge>
                                                        ) : null}
                                                    </div>
                                                </div>
                                                {data.can_manage ? (
                                                    <div className="flex gap-2">
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="sm"
                                                            className="min-h-11"
                                                            onClick={() =>
                                                                openEdit(
                                                                    requirement,
                                                                )
                                                            }
                                                        >
                                                            <Pencil className="mr-2 h-4 w-4" />{' '}
                                                            Edit
                                                        </Button>
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="sm"
                                                            className="min-h-11 text-status-critical"
                                                            onClick={() => {
                                                                if (
                                                                    pending.current
                                                                )
                                                                    return;
                                                                setOwner({
                                                                    actorId,
                                                                    siteId,
                                                                });
                                                                held.current = false;
                                                                setUncertain(
                                                                    false,
                                                                );
                                                                setMessage('');
                                                                form.clearErrors();
                                                                setDeleting(
                                                                    requirement,
                                                                );
                                                            }}
                                                        >
                                                            <Trash2 className="mr-2 h-4 w-4" />{' '}
                                                            Remove
                                                        </Button>
                                                    </div>
                                                ) : null}
                                            </div>
                                        ))}
                                    </CardContent>
                                </Card>
                            );
                        },
                    )}
                </div>
            ) : (
                <SiteProfileEmptyState
                    icon={GraduationCap}
                    title="No staff requirements recorded"
                    description="Record mandatory, recommended, and specialist competency requirements."
                    action={
                        data.can_manage
                            ? { label: 'Add requirement', onClick: openCreate }
                            : undefined
                    }
                />
            )}

            <WizardShell
                open={dialogOpen}
                onClose={closeEditor}
                title={
                    editing ? 'Edit staff requirement' : 'Add staff requirement'
                }
                description="Record the requirement, choose how it applies and review before saving."
                railIcon={GraduationCap}
                railTitle="Staff requirement"
                railSub={
                    editing
                        ? 'Update this Site requirement'
                        : 'Add a Site requirement'
                }
                steps={[
                    {
                        key: 'details',
                        label: 'Details',
                        blurb: 'Name and guidance',
                        icon: GraduationCap,
                        disabled: locked,
                    },
                    {
                        key: 'qualification',
                        label: 'Qualification',
                        blurb: 'Evidence and coverage',
                        icon: Award,
                        disabled: locked,
                    },
                    {
                        key: 'review',
                        label: 'Review',
                        blurb: 'Check your choices',
                        icon: BadgeCheck,
                        disabled: locked,
                    },
                ]}
                stepIndex={step}
                onStepClick={goToStep}
                frontline
                footerStart={
                    <Button
                        variant="outline"
                        disabled={processing}
                        onClick={() =>
                            step > 0 && !uncertain
                                ? setStep(step - 1)
                                : closeEditor()
                        }
                    >
                        {uncertain
                            ? 'Close and review list'
                            : step > 0
                              ? 'Back'
                              : 'Cancel'}
                    </Button>
                }
                footerEnd={
                    uncertain ? (
                        <Button
                            disabled={processing || identityChanged}
                            onClick={checkCurrent}
                        >
                            {processing
                                ? 'Checking…'
                                : 'Check current requirements'}
                        </Button>
                    ) : step < 2 ? (
                        <Button
                            disabled={locked}
                            onClick={() => goToStep(step + 1)}
                        >
                            Continue
                        </Button>
                    ) : (
                        <Button disabled={locked} onClick={submit}>
                            {processing ? 'Saving…' : 'Save requirement'}
                        </Button>
                    )
                }
            >
                {identityChanged || !data.can_manage ? (
                    <p
                        role="alert"
                        className="mb-4 text-sm text-status-warning"
                    >
                        Your current access does not allow saving this
                        requirement. Your entries are retained.
                    </p>
                ) : null}
                {message ? (
                    <p
                        role="alert"
                        className="mb-4 rounded-lg border p-3 text-sm"
                    >
                        {message}
                    </p>
                ) : null}
                {Object.keys(form.errors).length > 0 ? (
                    <div
                        role="alert"
                        className="mb-4 rounded-lg border border-status-critical/30 p-3 text-sm text-status-critical"
                    >
                        {Object.values(form.errors).map((error, i) => (
                            <p key={i}>{error}</p>
                        ))}
                    </div>
                ) : null}
                <fieldset disabled={locked} className="min-w-0 space-y-4">
                    {step === 0 ? (
                        <>
                            <h3 className="text-lg font-semibold">
                                Requirement details
                            </h3>
                            <div className="space-y-2">
                                <Label htmlFor="staff-requirement-name">
                                    Requirement name
                                </Label>
                                <Input
                                    id="staff-requirement-name"
                                    value={form.data.requirement_name}
                                    onChange={(event) =>
                                        form.setData(
                                            'requirement_name',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                                {localErrors.requirement_name ||
                                form.errors.requirement_name ? (
                                    <p
                                        role="alert"
                                        className="text-sm text-status-critical"
                                    >
                                        {localErrors.requirement_name ||
                                            form.errors.requirement_name}
                                    </p>
                                ) : null}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="staff-requirement-category">
                                    Category
                                </Label>
                                <Select
                                    value={form.data.category}
                                    onValueChange={(value) =>
                                        form.setData(
                                            'category',
                                            value as RequirementForm['category'],
                                        )
                                    }
                                >
                                    <SelectTrigger id="staff-requirement-category">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="mandatory">
                                            Mandatory
                                        </SelectItem>
                                        <SelectItem value="recommended">
                                            Recommended
                                        </SelectItem>
                                        <SelectItem value="specialist">
                                            Specialist
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="staff-requirement-description">
                                    Description
                                </Label>
                                <Textarea
                                    id="staff-requirement-description"
                                    value={form.data.description}
                                    onChange={(event) =>
                                        form.setData(
                                            'description',
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="flex items-center justify-between gap-3 rounded-lg border p-3">
                                <Label htmlFor="staff-requirement-certification">
                                    Certification required
                                </Label>
                                <Switch
                                    id="staff-requirement-certification"
                                    checked={form.data.certification_required}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'certification_required',
                                            checked,
                                        )
                                    }
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="staff-requirement-expiry">
                                    Expiry period (months)
                                </Label>
                                <Input
                                    id="staff-requirement-expiry"
                                    type="number"
                                    min={1}
                                    value={form.data.expiry_period_months}
                                    onChange={(event) =>
                                        form.setData(
                                            'expiry_period_months',
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>
                        </>
                    ) : step === 1 ? (
                        <>
                            <h3 className="text-lg font-semibold">
                                Qualification and coverage
                            </h3>
                            <HouseQualificationFields
                                id="staff-requirement"
                                value={form.data}
                                options={data.mapping_options ?? []}
                                mapping={editing?.mapping}
                                disabled={locked}
                                errors={{ ...form.errors, ...localErrors }}
                                onChange={(patch) =>
                                    form.setData({ ...form.data, ...patch })
                                }
                            />
                        </>
                    ) : (
                        <ReviewCard
                            icon={BadgeCheck}
                            title="Review staff requirement"
                            onEdit={() => {
                                if (!locked) setStep(0);
                            }}
                        >
                            <ReviewRow
                                label="Name"
                                value={form.data.requirement_name}
                            />
                            <ReviewRow
                                label="Category"
                                value={form.data.category}
                            />
                            <ReviewRow
                                label="Description"
                                value={form.data.description}
                            />
                            <ReviewRow
                                label="Certification"
                                value={
                                    form.data.certification_required
                                        ? 'Required'
                                        : 'Not required'
                                }
                            />
                            <ReviewRow
                                label="Expiry period"
                                value={
                                    form.data.expiry_period_months
                                        ? form.data.expiry_period_months +
                                          ' months'
                                        : 'Not specified'
                                }
                            />
                            <ReviewRow
                                label="Recognised qualification"
                                value={
                                    (data.mapping_options ?? []).find(
                                        (option) =>
                                            option.id ===
                                            form.data
                                                .hr_compliance_requirement_id,
                                    )?.name ??
                                    qualificationMappingLabel(
                                        form.data
                                            .hr_compliance_requirement_id ===
                                            null
                                            ? null
                                            : editing?.mapping,
                                    )
                                }
                            />
                            <ReviewRow
                                label="Who needs it"
                                value={houseQualificationSummary(form.data)}
                            />
                        </ReviewCard>
                    )}
                </fieldset>
            </WizardShell>

            <ConfirmDialog
                open={deleting !== null}
                onClose={() => {
                    if (!pending.current) {
                        setDeleting(null);
                        setUncertain(false);
                    }
                }}
                processing={processing}
                frontline
                buttonClassName="min-h-11"
                onConfirm={() => {
                    if (uncertain) {
                        checkCurrent();
                        return;
                    }
                    if (!deleting || locked) return;
                    const intent: HouseRequirementIntent = {
                        actor_id: owner.actorId,
                        site_id: owner.siteId,
                        request_id: crypto.randomUUID(),
                        action: 'deleted',
                        requirement_id: deleting.id,
                        values: null,
                    };
                    run(intent, (options) =>
                        router.delete(
                            `/sites/${siteId}/staff-requirements/${deleting.id}`,
                            {
                                ...options,
                                data: { request_id: intent.request_id },
                            },
                        ),
                    );
                }}
                title="Remove staff requirement?"
                description={
                    <>
                        <p>
                            {deleting?.name ?? 'This requirement'} will no
                            longer apply to this Site.
                        </p>
                        {message ? (
                            <p role="alert" className="mt-3">
                                {message}
                            </p>
                        ) : null}
                        {identityChanged || !data.can_manage ? (
                            <p role="alert" className="mt-3">
                                Your current access does not allow removing this
                                requirement.
                            </p>
                        ) : null}
                        {Object.values(form.errors).map((error, i) => (
                            <p role="alert" key={i}>
                                {error}
                            </p>
                        ))}
                    </>
                }
                confirmText={
                    uncertain
                        ? 'Check current requirements'
                        : 'Remove requirement'
                }
                cancelText={uncertain ? 'Close and review list' : 'Cancel'}
                variant={uncertain ? 'default' : 'destructive'}
            />
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={() => {
                    setDiscard(false);
                    setDialogOpen(false);
                    setUncertain(false);
                }}
                title={
                    uncertain
                        ? 'Close this unconfirmed change?'
                        : 'Discard your changes?'
                }
                description={
                    uncertain
                        ? 'The earlier change may already have saved. Closing removes this draft from view. Review the current requirements before making another change.'
                        : 'Your unsaved entries will be discarded.'
                }
                confirmText={
                    uncertain ? 'Close and review list' : 'Discard changes'
                }
                cancelText="Keep editing"
                frontline
                buttonClassName="min-h-11"
            />
        </div>
    );
}
