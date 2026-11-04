/**
 * Site profile › Contacts: add and edit one contact. A contact is either
 * someone who works here (picked from the staff list; name, job title, work
 * phone and work email come from their staff record) or someone outside the
 * organisation (typed in). Same choice as the Add / Edit site pop-up
 * (to-do #10).
 */
import { PopupForm } from '@/components/popup';
import {
    ContactSourceChoice,
    defaultContactSource,
    StaffContactPicker,
    type ContactSource,
    type SiteStaffOption,
} from '@/components/sites/site-contact-fields';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Field } from '@/components/wizard/primitives';
import { cn } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import axios from 'axios';
import { Pencil, UserPlus } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import {
    CONTACT_TYPES,
    getContactType,
    type ContactTypeDef,
    type ContactTypeKey,
} from './_helpers';

// Re-export so existing call sites that imported these from `_dialogs` keep
// working without changes. The single source of truth lives in `_helpers.ts`.
export { CONTACT_TYPES, getContactType };
export type { ContactTypeDef, ContactTypeKey };

// ── Form value shape ──────────────────────────────────────────────────────

export type ContactFormValues = {
    type: ContactTypeKey | string;
    source: ContactSource;
    user_id: string;
    name: string;
    role: string;
    phone: string;
    email: string;
    is_primary: boolean;
    notes: string;
};

export type ContactRecord = {
    id: number;
    type?: string | null;
    /** Set when the contact is someone who works here. */
    user_id?: number | null;
    name: string;
    role?: string | null;
    phone?: string | null;
    email?: string | null;
    is_primary: boolean;
    notes?: string | null;
};

/** The staff the viewer may pick, loaded when a contact pop-up opens. */
function useSiteStaff(siteId: number) {
    const [staff, setStaff] = useState<SiteStaffOption[]>([]);
    const [loading, setLoading] = useState(true);
    const [failed, setFailed] = useState(false);
    const [attempt, setAttempt] = useState(0);

    useEffect(() => {
        const controller = new AbortController();
        axios
            .get<{ staff: SiteStaffOption[] }>(
                `/sites/${siteId}/contacts/staff`,
                {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                },
            )
            .then((response) => {
                setStaff(response.data.staff ?? []);
                setFailed(false);
                setLoading(false);
            })
            .catch((error: unknown) => {
                if (axios.isCancel(error)) return;
                setFailed(true);
                setLoading(false);
            });
        return () => controller.abort();
    }, [siteId, attempt]);

    const retry = useCallback(() => {
        setLoading(true);
        setFailed(false);
        setAttempt((n) => n + 1);
    }, []);

    return { staff, loading, failed, retry };
}

// ── Type picker (Send Kudos-style category grid) ──────────────────────────

function ContactTypePicker({
    value,
    onChange,
}: {
    value: string;
    onChange: (v: ContactTypeKey) => void;
}) {
    return (
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
            {CONTACT_TYPES.map((t) => {
                const Icon = t.icon;
                const active = value === t.key;
                return (
                    <Button
                        unstyled
                        key={t.key}
                        type="button"
                        aria-pressed={active}
                        onClick={() => onChange(t.key)}
                        className={cn(
                            'group flex items-start gap-2 rounded-xl border bg-card/40 p-3 text-left transition-all',
                            'hover:border-primary/50 hover:bg-card focus:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                            active
                                ? 'border-primary bg-primary-fill/10 ring-1 ring-primary/40'
                                : 'border-border',
                        )}
                    >
                        <span className="mt-0.5 shrink-0 rounded-lg bg-background/60 p-1.5">
                            <Icon className={cn('h-4 w-4', t.accent)} />
                        </span>
                        <span className="min-w-0">
                            <span className="block truncate text-sm font-medium">
                                {t.label}
                            </span>
                            <span className="block truncate text-xs text-muted-foreground">
                                {t.description}
                            </span>
                        </span>
                    </Button>
                );
            })}
        </div>
    );
}

// ── Shared field group ────────────────────────────────────────────────────

function ContactFields({
    form,
    lockType = false,
    staff,
    current,
}: {
    form: ReturnType<typeof useForm<ContactFormValues>>;
    lockType?: boolean;
    staff: ReturnType<typeof useSiteStaff>;
    current?: SiteStaffOption | null;
}) {
    const { data, setData, errors } = form;
    const selectedType = getContactType(data.type);
    const SelectedIcon = selectedType.icon;

    const pickStaff = (person: SiteStaffOption | null) =>
        setData((prev) => ({
            ...prev,
            source: 'staff',
            user_id: person ? String(person.id) : '',
            name: person?.name ?? '',
            role: person?.role ?? '',
            phone: person?.phone ?? '',
            email: person?.email ?? '',
        }));
    const changeSource = (source: ContactSource) => {
        if (source === data.source) return;
        // Switching clears the other way's details.
        setData((prev) => ({
            ...prev,
            source,
            user_id: '',
            name: '',
            role: '',
            phone: '',
            email: '',
        }));
    };
    const changeType = (type: ContactTypeKey) => {
        const untouched =
            !data.user_id &&
            !data.name.trim() &&
            !data.role.trim() &&
            !data.phone.trim() &&
            !data.email.trim();
        setData((prev) => ({
            ...prev,
            type,
            source: untouched ? defaultContactSource(type) : prev.source,
        }));
    };

    return (
        <div className="grid gap-4">
            <div>
                <Label className="mb-2 block">
                    Contact type <span className="text-status-critical">*</span>
                </Label>
                {lockType ? (
                    <div className="flex items-start gap-3 rounded-xl border border-border bg-muted/40 p-3">
                        <span className="mt-0.5 shrink-0 rounded-lg bg-background/60 p-1.5">
                            <SelectedIcon
                                className={cn('h-4 w-4', selectedType.accent)}
                            />
                        </span>
                        <div className="min-w-0 flex-1">
                            <span className="text-sm font-medium">
                                {selectedType.label}
                            </span>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                Set by the Overview row you opened.
                            </p>
                        </div>
                    </div>
                ) : (
                    <ContactTypePicker
                        value={data.type}
                        onChange={changeType}
                    />
                )}
                {errors.type ? (
                    <p className="mt-1 text-xs text-status-critical">
                        {errors.type}
                    </p>
                ) : null}
            </div>

            <div className="grid gap-2">
                <Label>Who is it?</Label>
                <ContactSourceChoice
                    value={data.source}
                    onChange={changeSource}
                />
            </div>

            {data.source === 'staff' ? (
                <Field
                    label="Staff member"
                    required
                    error={errors.user_id}
                    span
                >
                    <StaffContactPicker
                        value={data.user_id}
                        onChange={pickStaff}
                        staff={staff.staff}
                        current={current}
                        loading={staff.loading}
                        failed={staff.failed}
                        onRetry={staff.retry}
                    />
                </Field>
            ) : (
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Name" required error={errors.name} span>
                        <Input
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="Full name"
                            aria-invalid={!!errors.name}
                        />
                    </Field>
                    <Field label="Role / title" error={errors.role} span>
                        <Input
                            value={data.role}
                            onChange={(e) => setData('role', e.target.value)}
                            placeholder="e.g. Father, GP, plumber"
                        />
                    </Field>
                    <Field label="Phone" error={errors.phone}>
                        <Input
                            value={data.phone}
                            onChange={(e) => setData('phone', e.target.value)}
                            placeholder="021 123 4567"
                        />
                    </Field>
                    <Field label="Email" error={errors.email}>
                        <Input
                            type="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                        />
                    </Field>
                </div>
            )}

            <div className="flex items-center gap-2">
                <Checkbox
                    id="c-primary"
                    checked={data.is_primary}
                    onCheckedChange={(checked) =>
                        setData('is_primary', !!checked)
                    }
                />
                <Label htmlFor="c-primary" className="text-sm font-normal">
                    Mark as primary contact for this site
                </Label>
            </div>

            <Field label="Notes" error={errors.notes} span>
                <Textarea
                    rows={3}
                    value={data.notes}
                    onChange={(e) => setData('notes', e.target.value)}
                    placeholder="Availability, languages, additional context…"
                />
            </Field>
        </div>
    );
}

/** What the server wants: a staff contact sends only its link. */
function contactSubmission(data: ContactFormValues) {
    const { source, ...rest } = data;
    if (source === 'staff') {
        return {
            type: rest.type,
            user_id: rest.user_id,
            is_primary: rest.is_primary,
            notes: rest.notes,
        };
    }
    return { ...rest, user_id: '' };
}

function contactErrorStep(
    data: ContactFormValues,
): Partial<Record<keyof ContactFormValues, string>> {
    if (data.source === 'staff' && !data.user_id)
        return {
            user_id:
                'Choose who this is, or switch to someone outside the organisation.',
        };
    if (data.source === 'outside' && !data.name.trim())
        return { name: 'Enter their name.' };
    return {};
}

// ── Add ───────────────────────────────────────────────────────────────────

export function AddContactDialog({
    siteId,
    isOpen,
    onClose,
    type,
    lockType = false,
    onSaved,
}: {
    siteId: number;
    isOpen: boolean;
    onClose: () => void;
    type?: ContactTypeKey | string | null;
    lockType?: boolean;
    onSaved?: () => void;
}) {
    return isOpen ? (
        <AddContactBody
            siteId={siteId}
            onClose={onClose}
            type={type}
            lockType={lockType}
            onSaved={onSaved}
        />
    ) : null;
}

function AddContactBody({
    siteId,
    onClose,
    type,
    lockType,
    onSaved,
}: {
    siteId: number;
    onClose: () => void;
    type?: ContactTypeKey | string | null;
    lockType: boolean;
    onSaved?: () => void;
}) {
    const startType = type ?? 'site_contact';
    const staff = useSiteStaff(siteId);
    const form = useForm<ContactFormValues>({
        type: startType,
        source: defaultContactSource(startType),
        user_id: '',
        name: '',
        role: '',
        phone: '',
        email: '',
        is_primary: false,
        notes: '',
    });

    const submit = () => {
        const problems = contactErrorStep(form.data);
        form.clearErrors();
        if (Object.keys(problems).length) {
            form.setError(problems);
            return;
        }
        form.transform(contactSubmission);
        form.post(`/sites/${siteId}/contacts`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                onSaved?.();
                onClose();
            },
        });
    };

    return (
        <PopupForm
            open
            onClose={onClose}
            size="md"
            icon={UserPlus}
            title="Add site contact"
            description="Pick someone who works here, or add someone from outside the organisation."
            submitLabel="Add contact"
            processingLabel="Adding…"
            processing={form.processing}
            onSubmit={submit}
            dirty={form.isDirty}
            draftName="a new site contact"
        >
            <ContactFields form={form} lockType={lockType} staff={staff} />
        </PopupForm>
    );
}

// ── Edit ──────────────────────────────────────────────────────────────────

export function EditContactDialog({
    siteId,
    contact,
    isOpen,
    onClose,
    onSaved,
}: {
    siteId: number;
    contact: ContactRecord | null;
    isOpen: boolean;
    onClose: () => void;
    onSaved?: () => void;
}) {
    return isOpen && contact ? (
        <EditContactBody
            key={contact.id}
            siteId={siteId}
            contact={contact}
            onClose={onClose}
            onSaved={onSaved}
        />
    ) : null;
}

function EditContactBody({
    siteId,
    contact,
    onClose,
    onSaved,
}: {
    siteId: number;
    contact: ContactRecord;
    onClose: () => void;
    onSaved?: () => void;
}) {
    const staff = useSiteStaff(siteId);
    const linked = contact.user_id != null;
    // The saved staff member stays selectable even if this viewer couldn't pick them today.
    const current: SiteStaffOption | null = linked
        ? {
              id: contact.user_id as number,
              name: contact.name,
              role: contact.role,
              phone: contact.phone,
              email: contact.email,
          }
        : null;
    const form = useForm<ContactFormValues>({
        type: (getContactType(contact.type).key ??
            'site_contact') as ContactTypeKey,
        source: linked ? 'staff' : 'outside',
        user_id: linked ? String(contact.user_id) : '',
        name: contact.name ?? '',
        role: contact.role ?? '',
        phone: contact.phone ?? '',
        email: contact.email ?? '',
        is_primary: !!contact.is_primary,
        notes: contact.notes ?? '',
    });

    const submit = () => {
        const problems = contactErrorStep(form.data);
        form.clearErrors();
        if (Object.keys(problems).length) {
            form.setError(problems);
            return;
        }
        form.transform(contactSubmission);
        form.put(`/sites/${siteId}/contacts/${contact.id}`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                onSaved?.();
                onClose();
            },
        });
    };

    return (
        <PopupForm
            open
            onClose={onClose}
            size="md"
            icon={Pencil}
            title="Edit contact"
            description={`Change how ${contact.name || 'this contact'} is listed for this site.`}
            submitLabel="Save changes"
            processingLabel="Saving…"
            processing={form.processing}
            onSubmit={submit}
            dirty={form.isDirty}
            draftName={`changes to ${contact.name || 'this contact'}`}
        >
            <ContactFields form={form} staff={staff} current={current} />
        </PopupForm>
    );
}
