/* eslint-disable no-restricted-syntax -- The wizard footer + emergency-contact
 * rows use native <button>/<label> elements to match the Add-Client modal chrome
 * (see components/wizard/shell.tsx and primitives.tsx, which do the same). All
 * colours are semantic design tokens. */
import { useForm } from '@inertiajs/react';
import {
    Briefcase,
    ClipboardCheck,
    Contact,
    Link2,
    Plus,
    ShieldCheck,
    UserPlus,
    UsersRound,
    X,
} from 'lucide-react';

import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { employeeCreationIsComplete } from '@/lib/hr/staff-creation-workflow';
import { cn } from '@/lib/utils';

import { PeoplePicker, type PersonOption } from './people-picker';
import {
    Field,
    InfoCard,
    ReviewCard,
    ReviewRow,
    SelectInput,
    StepHead,
    SubHead,
    useWizard,
    WizardShell,
    WizardStepPane,
    type WizardStep,
} from './wizard';

export interface AddEmployeeFormData {
    positions: { id: number; title: string }[];
    managers: { value: string; label: string }[];
    roles: { value: string; label: string }[];
    employmentTypes: { value: string; label: string }[];
}

const STEPS: readonly WizardStep[] = [
    {
        key: 'person',
        label: 'Person',
        blurb: 'Name & contact',
        icon: UsersRound,
    },
    { key: 'job', label: 'Job', blurb: 'Role & placement', icon: Briefcase },
    {
        key: 'rtw',
        label: 'Right to work',
        blurb: 'Visa & eligibility',
        icon: ShieldCheck,
    },
    {
        key: 'emergency',
        label: 'Emergency',
        blurb: 'Next of kin',
        icon: Contact,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm & create',
        icon: ClipboardCheck,
    },
];

const WORK_RIGHTS = [
    { value: 'citizen', label: 'NZ citizen' },
    { value: 'permanent_resident', label: 'Permanent resident' },
    { value: 'resident_visa', label: 'Resident visa' },
    { value: 'work_visa', label: 'Work visa' },
    { value: 'student_visa', label: 'Student visa' },
    { value: 'other', label: 'Other' },
];

const VISA_STATUSES = ['resident_visa', 'work_visa', 'student_visa'];

type EmergencyContact = { name: string; relationship: string; phone: string };

const WORK_RIGHTS_LABEL: Record<string, string> = Object.fromEntries(
    WORK_RIGHTS.map((w) => [w.value, w.label]),
);

export function AddEmployeeDialog({
    open,
    onClose,
    formData,
    departments,
    sites,
}: {
    open: boolean;
    onClose: () => void;
    formData: AddEmployeeFormData;
    departments: { id: number; name: string }[];
    sites: { id: number; name: string }[];
}) {
    const wizard = useWizard(STEPS.length);
    const form = useForm({
        name: '',
        email: '',
        preferred_name: '',
        role: formData.roles.some((role) => role.value === 'support_worker')
            ? 'support_worker'
            : (formData.roles[0]?.value ?? ''),
        position_id: '',
        employment_type: 'full_time',
        department_id: '',
        team: '',
        primary_site_id: '',
        secondary_site_ids: [] as number[],
        manager_user_id: '',
        start_date: '',
        work_phone: '',
        work_rights_status: '',
        visa_type: '',
        visa_expires_at: '',
        emergency_contacts: [
            { name: '', relationship: '', phone: '' },
        ] as EmergencyContact[],
        start_onboarding: true,
        send_invite: false,
        link_existing: false,
    });

    const close = () => {
        form.reset();
        form.clearErrors();
        wizard.reset();
        onClose();
    };

    const submit = () => {
        form.transform((data) => ({
            ...data,
            position_id: data.position_id || null,
            department_id: data.department_id || null,
            primary_site_id: data.primary_site_id || null,
            manager_user_id: data.manager_user_id || null,
            work_rights_status: data.work_rights_status || null,
            visa_type: data.visa_type || null,
            visa_expires_at: data.visa_expires_at || null,
            emergency_contacts: data.emergency_contacts.filter(
                (c) => c.name.trim() !== '',
            ),
        }));
        form.post('/hr/people', {
            preserveScroll: true,
            // On success the controller redirects to the new profile — Inertia
            // follows it, so no success pane is needed here.
            onError: (errors) => {
                // A dedupe conflict is resolved in place on the Review step
                // (the "link to existing record" callout) — don't jump away.
                if (errors.email?.includes('Link to existing')) return;
                if (errors.name || errors.email) wizard.goTo(0);
                else if (
                    errors.work_rights_status ||
                    errors.visa_type ||
                    errors.visa_expires_at
                )
                    wizard.goTo(2);
                else wizard.goTo(1);
            },
        });
    };

    const canSubmit = employeeCreationIsComplete({
        name: form.data.name,
        email: form.data.email,
        role: form.data.role,
        primarySiteId: form.data.primary_site_id,
    });

    const selectPrimarySite = (siteId: string) => {
        form.setData((current) => ({
            ...current,
            primary_site_id: siteId,
            secondary_site_ids: current.secondary_site_ids.filter(
                (id) => String(id) !== siteId,
            ),
        }));
    };

    const toggleSecondarySite = (siteId: number) => {
        const selected = form.data.secondary_site_ids.includes(siteId);
        form.setData(
            'secondary_site_ids',
            selected
                ? form.data.secondary_site_ids.filter((id) => id !== siteId)
                : [...form.data.secondary_site_ids, siteId],
        );
    };

    const managerOptions: PersonOption[] = formData.managers.map((m) => ({
        value: m.value,
        label: m.label,
    }));

    const contacts = form.data.emergency_contacts;
    const setContact = (i: number, key: keyof EmergencyContact, val: string) =>
        form.setData(
            'emergency_contacts',
            contacts.map((c, idx) => (idx === i ? { ...c, [key]: val } : c)),
        );
    const addContact = () =>
        form.setData('emergency_contacts', [
            ...contacts,
            { name: '', relationship: '', phone: '' },
        ]);
    const removeContact = (i: number) =>
        form.setData(
            'emergency_contacts',
            contacts.filter((_, idx) => idx !== i),
        );

    const needsVisa = VISA_STATUSES.includes(form.data.work_rights_status);
    const linkConflict =
        form.errors.email?.includes('Link to existing') ?? false;
    const secondarySiteError =
        form.errors.secondary_site_ids ??
        Object.entries(form.errors).find(([key]) =>
            key.startsWith('secondary_site_ids.'),
        )?.[1];

    const positionLabel =
        formData.positions.find((p) => String(p.id) === form.data.position_id)
            ?.title ?? '—';
    const departmentLabel =
        departments.find((d) => String(d.id) === form.data.department_id)
            ?.name ?? '—';
    const siteLabel =
        sites.find((s) => String(s.id) === form.data.primary_site_id)?.name ??
        '—';
    const additionalSiteLabels = sites
        .filter((site) => form.data.secondary_site_ids.includes(site.id))
        .map((site) => site.name)
        .join(', ');
    const managerLabel =
        formData.managers.find((m) => m.value === form.data.manager_user_id)
            ?.label ?? '—';
    const roleLabel =
        formData.roles.find((r) => r.value === form.data.role)?.label ??
        form.data.role;
    const typeLabel =
        formData.employmentTypes.find(
            (t) => t.value === form.data.employment_type,
        )?.label ?? '—';
    const namedContacts = contacts.filter((c) => c.name.trim() !== '');

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="Add employee"
            description="Create a new employee record and user account."
            railIcon={UserPlus}
            railTitle="Add employee"
            railSub="New team member"
            steps={STEPS}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={wizard.progress}
            footerStart={
                wizard.isFirst ? null : (
                    <button
                        type="button"
                        onClick={wizard.back}
                        className="rounded-md px-3 py-2 text-sm font-semibold text-muted-foreground hover:bg-muted"
                    >
                        Back
                    </button>
                )
            }
            footerEnd={
                <>
                    <button
                        type="button"
                        onClick={close}
                        className="rounded-md px-3 py-2 text-sm font-semibold text-muted-foreground hover:bg-muted"
                    >
                        Cancel
                    </button>
                    {wizard.isLast ? (
                        <button
                            type="button"
                            onClick={submit}
                            disabled={!canSubmit || form.processing}
                            className={cn(
                                'rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-opacity',
                                (!canSubmit || form.processing) &&
                                    'cursor-not-allowed opacity-50',
                            )}
                        >
                            {form.processing
                                ? 'Adding…'
                                : linkConflict
                                  ? 'Link & add'
                                  : 'Add employee'}
                        </button>
                    ) : (
                        <button
                            type="button"
                            onClick={wizard.next}
                            className="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground"
                        >
                            Continue
                        </button>
                    )}
                </>
            }
        >
            {wizard.index === 0 && (
                <WizardStepPane>
                    <StepHead
                        icon={UsersRound}
                        title="Who are you adding?"
                        blurb="Their name and a work email — a sign-in account is created automatically."
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Full name"
                            required
                            error={form.errors.name}
                        >
                            <Input
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                placeholder="e.g. Ana Williams"
                            />
                        </Field>
                        <Field
                            label="Preferred name"
                            hint="optional"
                            error={form.errors.preferred_name}
                        >
                            <Input
                                value={form.data.preferred_name}
                                onChange={(e) =>
                                    form.setData(
                                        'preferred_name',
                                        e.target.value,
                                    )
                                }
                                placeholder="e.g. Ana"
                            />
                        </Field>
                        <Field
                            label="Work email"
                            required
                            error={linkConflict ? undefined : form.errors.email}
                        >
                            <Input
                                type="email"
                                value={form.data.email}
                                onChange={(e) =>
                                    form.setData('email', e.target.value)
                                }
                                placeholder="ana@example.co.nz"
                            />
                        </Field>
                        <Field
                            label="Work phone"
                            hint="optional"
                            error={form.errors.work_phone}
                        >
                            <Input
                                value={form.data.work_phone}
                                onChange={(e) =>
                                    form.setData('work_phone', e.target.value)
                                }
                                placeholder="021 555 0000"
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={Briefcase}
                        title="Role & placement"
                        blurb="Choose their access role and Primary site. Other job details can be refined later."
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Access role"
                            required
                            error={form.errors.role}
                        >
                            <SelectInput
                                value={form.data.role}
                                onChange={(v) => form.setData('role', v)}
                                placeholder="Select a role"
                                options={formData.roles}
                            />
                        </Field>
                        <Field
                            label="Position"
                            hint="optional"
                            error={form.errors.position_id}
                        >
                            <SelectInput
                                value={form.data.position_id}
                                onChange={(v) => form.setData('position_id', v)}
                                placeholder="Select a position"
                                options={formData.positions.map((p) => ({
                                    value: String(p.id),
                                    label: p.title,
                                }))}
                            />
                        </Field>
                        <Field
                            label="Employment type"
                            hint="optional"
                            error={form.errors.employment_type}
                        >
                            <SelectInput
                                value={form.data.employment_type}
                                onChange={(v) =>
                                    form.setData('employment_type', v)
                                }
                                placeholder="Select a type"
                                options={formData.employmentTypes}
                            />
                        </Field>
                        <Field
                            label="Department"
                            hint="optional"
                            error={form.errors.department_id}
                        >
                            <SelectInput
                                value={form.data.department_id}
                                onChange={(v) =>
                                    form.setData('department_id', v)
                                }
                                placeholder="Select a department"
                                options={departments.map((d) => ({
                                    value: String(d.id),
                                    label: d.name,
                                }))}
                            />
                        </Field>
                        <Field
                            label="Primary site"
                            required
                            error={form.errors.primary_site_id}
                        >
                            <SelectInput
                                value={form.data.primary_site_id}
                                onChange={selectPrimarySite}
                                placeholder="Select a site"
                                options={sites.map((s) => ({
                                    value: String(s.id),
                                    label: s.name,
                                }))}
                            />
                        </Field>
                        {sites.length > 1 ? (
                            <Field
                                label="Additional sites"
                                hint="optional"
                                span
                                error={secondarySiteError}
                            >
                                <div className="flex flex-wrap gap-2">
                                    {sites
                                        .filter(
                                            (site) =>
                                                String(site.id) !==
                                                form.data.primary_site_id,
                                        )
                                        .map((site) => {
                                            const selected =
                                                form.data.secondary_site_ids.includes(
                                                    site.id,
                                                );
                                            return (
                                                <button
                                                    key={site.id}
                                                    type="button"
                                                    onClick={() =>
                                                        toggleSecondarySite(
                                                            site.id,
                                                        )
                                                    }
                                                    aria-pressed={selected}
                                                    className={cn(
                                                        'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                                                        selected
                                                            ? 'border-primary bg-primary/10 text-primary'
                                                            : 'border-border text-muted-foreground hover:bg-muted',
                                                    )}
                                                >
                                                    {site.name}
                                                </button>
                                            );
                                        })}
                                </div>
                            </Field>
                        ) : null}
                        <Field
                            label="Team"
                            hint="optional"
                            error={form.errors.team}
                        >
                            <Input
                                value={form.data.team}
                                onChange={(e) =>
                                    form.setData('team', e.target.value)
                                }
                                placeholder="e.g. Community Support"
                                maxLength={255}
                            />
                        </Field>
                        <Field
                            label="Start date"
                            hint="optional"
                            error={form.errors.start_date}
                        >
                            <Input
                                type="date"
                                value={form.data.start_date}
                                onChange={(e) =>
                                    form.setData('start_date', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Reports to"
                            hint="optional"
                            span
                            error={form.errors.manager_user_id}
                        >
                            <PeoplePicker
                                value={form.data.manager_user_id}
                                onChange={(v) =>
                                    form.setData('manager_user_id', v)
                                }
                                people={managerOptions}
                                placeholder="Select a manager"
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 2 && (
                <WizardStepPane>
                    <StepHead
                        icon={ShieldCheck}
                        title="Right to work"
                        blurb="Confirm their eligibility to work in New Zealand. Optional now — required before they start."
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Work rights status"
                            hint="optional"
                            error={form.errors.work_rights_status}
                        >
                            <SelectInput
                                value={form.data.work_rights_status}
                                onChange={(v) =>
                                    form.setData('work_rights_status', v)
                                }
                                placeholder="Select status"
                                options={WORK_RIGHTS}
                            />
                        </Field>
                        {needsVisa ? (
                            <>
                                <Field
                                    label="Visa type"
                                    hint="optional"
                                    error={form.errors.visa_type}
                                >
                                    <Input
                                        value={form.data.visa_type}
                                        onChange={(e) =>
                                            form.setData(
                                                'visa_type',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="e.g. Essential Skills"
                                    />
                                </Field>
                                <Field
                                    label="Visa expires"
                                    hint="optional"
                                    error={form.errors.visa_expires_at}
                                >
                                    <Input
                                        type="date"
                                        value={form.data.visa_expires_at}
                                        onChange={(e) =>
                                            form.setData(
                                                'visa_expires_at',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                            </>
                        ) : null}
                        <InfoCard icon={ShieldCheck} tone="info">
                            Visa expiry feeds compliance reminders so nobody
                            works past their right-to-work date.
                        </InfoCard>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 3 && (
                <WizardStepPane>
                    <StepHead
                        icon={Contact}
                        title="Emergency contact"
                        blurb="Who should we call in an emergency? Optional — add one or more next of kin."
                    />
                    <div className="space-y-3">
                        {contacts.map((c, i) => (
                            <div
                                key={i}
                                className="rounded-xl border border-border bg-card/60 p-3"
                            >
                                <div className="mb-2 flex items-center justify-between">
                                    <SubHead icon={Contact}>
                                        Contact {i + 1}
                                    </SubHead>
                                    {contacts.length > 1 ? (
                                        <button
                                            type="button"
                                            onClick={() => removeContact(i)}
                                            aria-label={`Remove contact ${i + 1}`}
                                            className="rounded-md p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                                        >
                                            <X className="h-4 w-4" />
                                        </button>
                                    ) : null}
                                </div>
                                <div className="grid gap-3 sm:grid-cols-3">
                                    <Field label="Name">
                                        <Input
                                            value={c.name}
                                            onChange={(e) =>
                                                setContact(
                                                    i,
                                                    'name',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="Full name"
                                        />
                                    </Field>
                                    <Field label="Relationship">
                                        <Input
                                            value={c.relationship}
                                            onChange={(e) =>
                                                setContact(
                                                    i,
                                                    'relationship',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="e.g. Partner"
                                        />
                                    </Field>
                                    <Field label="Phone">
                                        <Input
                                            value={c.phone}
                                            onChange={(e) =>
                                                setContact(
                                                    i,
                                                    'phone',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="021 555 0000"
                                        />
                                    </Field>
                                </div>
                            </div>
                        ))}
                        <button
                            type="button"
                            onClick={addContact}
                            className="inline-flex items-center gap-1.5 rounded-lg border border-dashed border-border px-3 py-2 text-sm font-semibold text-muted-foreground hover:border-primary/50 hover:text-foreground"
                        >
                            <Plus className="h-4 w-4" />
                            Add another contact
                        </button>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 4 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Review & create"
                        blurb="Confirm the details, then create the record and (optionally) start onboarding and send a login invite."
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard
                            icon={UsersRound}
                            title="Person"
                            onEdit={() => wizard.goTo(0)}
                        >
                            <ReviewRow label="Name" value={form.data.name} />
                            <ReviewRow
                                label="Preferred"
                                value={form.data.preferred_name}
                            />
                            <ReviewRow label="Email" value={form.data.email} />
                            <ReviewRow
                                label="Phone"
                                value={form.data.work_phone}
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={Briefcase}
                            title="Job"
                            onEdit={() => wizard.goTo(1)}
                        >
                            <ReviewRow label="Role" value={roleLabel} />
                            <ReviewRow label="Position" value={positionLabel} />
                            <ReviewRow label="Type" value={typeLabel} />
                            <ReviewRow
                                label="Department"
                                value={departmentLabel}
                            />
                            <ReviewRow label="Team" value={form.data.team} />
                            <ReviewRow label="Site" value={siteLabel} />
                            <ReviewRow
                                label="Additional sites"
                                value={additionalSiteLabels}
                            />
                            <ReviewRow
                                label="Start date"
                                value={form.data.start_date}
                            />
                            <ReviewRow
                                label="Reports to"
                                value={managerLabel}
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={ShieldCheck}
                            title="Right to work"
                            onEdit={() => wizard.goTo(2)}
                        >
                            <ReviewRow
                                label="Status"
                                value={
                                    form.data.work_rights_status
                                        ? WORK_RIGHTS_LABEL[
                                              form.data.work_rights_status
                                          ]
                                        : undefined
                                }
                            />
                            {needsVisa ? (
                                <>
                                    <ReviewRow
                                        label="Visa type"
                                        value={form.data.visa_type}
                                    />
                                    <ReviewRow
                                        label="Visa expires"
                                        value={form.data.visa_expires_at}
                                    />
                                </>
                            ) : null}
                        </ReviewCard>
                        <ReviewCard
                            icon={Contact}
                            title="Emergency"
                            onEdit={() => wizard.goTo(3)}
                        >
                            {namedContacts.length === 0 ? (
                                <ReviewRow label="Contacts" value={undefined} />
                            ) : (
                                namedContacts.map((c, i) => (
                                    <ReviewRow
                                        key={i}
                                        label={c.relationship || 'Contact'}
                                        value={[c.name, c.phone]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    />
                                ))
                            )}
                        </ReviewCard>

                        {linkConflict ? (
                            <InfoCard icon={Link2} tone="warn">
                                <div className="font-semibold text-foreground">
                                    This email already belongs to an account.
                                </div>
                                <p className="mt-0.5">
                                    Link only when this is the same active staff
                                    record or the accepted recruitment evidence
                                    has been independently approved.
                                </p>
                                <label className="mt-2 flex cursor-pointer items-center gap-2.5 text-[13px] font-semibold">
                                    <Switch
                                        checked={form.data.link_existing}
                                        onCheckedChange={(v) =>
                                            form.setData('link_existing', v)
                                        }
                                    />
                                    Link to existing record
                                </label>
                            </InfoCard>
                        ) : null}

                        <div className="col-span-full space-y-3 rounded-xl border border-border bg-card/70 p-4">
                            <ToggleRow
                                label="Start onboarding now"
                                desc="Generate the onboarding checklist for this hire."
                                checked={form.data.start_onboarding}
                                onChange={(v) =>
                                    form.setData('start_onboarding', v)
                                }
                            />
                            <ToggleRow
                                label="Send login invite"
                                desc="Email a set-your-password link so they can sign in."
                                checked={form.data.send_invite}
                                onChange={(v) => form.setData('send_invite', v)}
                            />
                        </div>
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

function ToggleRow({
    label,
    desc,
    checked,
    onChange,
}: {
    label: string;
    desc: string;
    checked: boolean;
    onChange: (v: boolean) => void;
}) {
    return (
        <label className="flex cursor-pointer items-center justify-between gap-4">
            <span className="min-w-0">
                <span className="block text-sm font-semibold">{label}</span>
                <span className="block text-xs text-muted-foreground">
                    {desc}
                </span>
            </span>
            <Switch checked={checked} onCheckedChange={onChange} />
        </label>
    );
}

export default AddEmployeeDialog;
