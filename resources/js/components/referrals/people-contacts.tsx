import { ConfirmDialog } from '@/components/confirm-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput } from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import {
    formatDateTimeLong,
    toDatetimeLocal,
    workerTimeOffset,
} from '@/lib/datetime';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { ClipboardCheck, ShieldCheck, UserRound, Users } from 'lucide-react';
import { useRef, useState } from 'react';
import { ReferralDateTime, validReferralDateTime } from './date-time';
import { label, referralHref, type ReferralDetail } from './types';

type Details = {
    name: string;
    role: string;
    relationship: string;
    organisation: string | null;
    phone: string | null;
    email: string | null;
    channel: string;
    safe_time: string | null;
    leave_message: boolean;
    communication: string | null;
    state: string;
    may_contact: boolean;
    purpose: string;
    authority_note: string | null;
    review_note: string;
    review_due_at: string | null;
    confirmed: boolean;
};
export type PeopleContact = {
    id: string;
    version: number;
    details: Details;
    review_current: boolean;
    reviewed_at: string | null;
    updated_at: string;
};
const roles = [
    'family',
    'supporter',
    'advocate',
    'representative',
    'referrer',
    'professional',
    'other',
];
const localInstant = (value: string | null) =>
    value ? toDatetimeLocal(value) + workerTimeOffset(value) : '';
const empty: Details = {
    name: '',
    role: 'supporter',
    relationship: '',
    organisation: '',
    phone: '',
    email: '',
    channel: 'none',
    safe_time: '',
    leave_message: false,
    communication: '',
    state: 'pending',
    may_contact: false,
    purpose: '',
    authority_note: '',
    review_note: '',
    review_due_at: '',
    confirmed: false,
};

export function PeopleContactsPanel({
    referral,
    canManage,
}: {
    referral: ReferralDetail;
    canManage: boolean;
}) {
    const [editing, setEditing] = useState<PeopleContact | 'new' | null>(null);
    const contacts = referral.people_contacts ?? [];
    const editable = canManage && !referral.general_enquiry;
    return (
        <div className="space-y-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-section-title">People involved</h2>
                    <p className="text-subtle">
                        Keep family, supporters and agency contacts distinct
                        from the website callback contact.
                    </p>
                </div>
                {editable && (
                    <Button
                        onClick={() => setEditing('new')}
                        disabled={
                            !referral.authority_scopes?.collect ||
                            referral.stage === 'closed'
                        }
                    >
                        Add contact
                    </Button>
                )}
            </div>
            <p className="text-subtle">
                Listing someone here does not create portal access, authorize
                disclosure or grant decision-making powers. Review authority and
                information sharing separately. Only current, explicitly
                permitted contact details should be used.
            </p>
            {!referral.authority_scopes?.collect && (
                <p className="text-subtle">
                    Review collection authority before adding or renewing a
                    contact. Existing permissions can still be withdrawn.
                </p>
            )}
            {referral.stage === 'closed' && (
                <p className="text-subtle">
                    This referral is closed. Existing contact permission can
                    still be withdrawn or archived; reopen the referral before
                    adding or renewing contacts.
                </p>
            )}
            {!contacts.length && (
                <EmptyState
                    variant="compact"
                    icon={Users}
                    title="No reviewed contacts recorded yet"
                    description="The submitted callback contact stays on the Overview until its details and role are verified."
                />
            )}
            <div className="grid gap-5 lg:grid-cols-2">
                {contacts.map((contact) => (
                    <ReviewCard
                        key={contact.id}
                        icon={Users}
                        title={contact.details.name}
                    >
                        <ReviewRow
                            label="Role"
                            value={[
                                label(contact.details.role),
                                contact.details.relationship,
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                        />
                        <ReviewRow
                            label="Status"
                            value={
                                <StatusBadge
                                    status={
                                        contact.review_current
                                            ? 'success'
                                            : 'warning'
                                    }
                                >
                                    {contact.review_current
                                        ? contact.details.may_contact
                                            ? 'Contact permitted'
                                            : 'Do not contact'
                                        : contact.details.state === 'current'
                                          ? 'Review needed'
                                          : label(contact.details.state)}
                                </StatusBadge>
                            }
                        />
                        <ReviewRow
                            label="Why they are involved"
                            value={contact.details.purpose}
                        />
                        <ReviewRow
                            label="Phone"
                            value={contact.details.phone}
                        />
                        <ReviewRow
                            label="Email"
                            value={contact.details.email}
                        />
                        <ReviewRow
                            label="Preferred channel"
                            value={
                                contact.details.channel === 'none'
                                    ? 'No direct contact'
                                    : label(contact.details.channel)
                            }
                        />
                        <ReviewRow
                            label="Safe contact"
                            value={contact.details.safe_time}
                        />
                        <ReviewRow
                            label="Messages"
                            value={
                                contact.review_current &&
                                contact.details.may_contact &&
                                contact.details.leave_message
                                    ? 'Permitted'
                                    : 'Do not leave messages'
                            }
                        />
                        <ReviewRow
                            label="Review due"
                            value={
                                contact.details.review_due_at
                                    ? formatDateTimeLong(
                                          contact.details.review_due_at,
                                      )
                                    : null
                            }
                        />
                        {!contact.review_current && (
                            <p className="text-subtle pt-2">
                                Do not rely on previous permission. Review the
                                current authority and this contact first.
                            </p>
                        )}
                        <details className="pt-2">
                            <summary className="cursor-pointer rounded-sm text-[13px] font-semibold focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                                Review details
                            </summary>
                            <div className="pt-1">
                                <ReviewRow
                                    label="Organisation"
                                    value={contact.details.organisation}
                                />
                                <ReviewRow
                                    label="Communication"
                                    value={contact.details.communication}
                                />
                                <ReviewRow
                                    label="Role and authority limits"
                                    value={contact.details.authority_note}
                                />
                                <ReviewRow
                                    label="Review note"
                                    value={contact.details.review_note}
                                />
                            </div>
                        </details>
                        {editable && (
                            <Button
                                variant="link"
                                className="h-auto px-0 pt-2"
                                onClick={() => setEditing(contact)}
                            >
                                Review contact
                            </Button>
                        )}
                    </ReviewCard>
                ))}
            </div>
            {editing && (
                <PeopleContactDialog
                    referral={referral}
                    contact={editing === 'new' ? null : editing}
                    onClose={() => setEditing(null)}
                />
            )}
        </div>
    );
}

export function PeopleContactDialog({
    referral,
    contact,
    onClose,
}: {
    referral: ReferralDetail;
    contact: PeopleContact | null;
    onClose: () => void;
}) {
    const [form, setForm] = useState<Details>(() => ({
        ...(contact?.details ?? empty),
        review_due_at: localInstant(contact?.details.review_due_at ?? null),
        review_note: '',
        confirmed: false,
    }));
    const [step, setStep] = useState(0);
    const [busy, setBusy] = useState(false);
    const [uncertain, setUncertain] = useState(false);
    const [conflict, setConflict] = useState(false);
    const [saved, setSaved] = useState(false);
    const [discard, setDiscard] = useState(false);
    const [error, setError] = useState('');
    const issued = useRef<Record<string, unknown> | null>(null);
    const sending = useRef(false);
    const body = useRef<HTMLDivElement>(null);
    const initial = useRef(form);
    const leave = useSettingsLeaveConfirmation(
        !saved && JSON.stringify(form) !== JSON.stringify(initial.current),
        'Discard contact review?',
        'Your unsaved contact review will be discarded. A save already sent may still finish; check the saved contact before beginning another review.',
        undefined,
        busy,
    );
    const retaining = ['withdrawn', 'archived'].includes(form.state);
    const closed = referral.stage === 'closed';
    const steps = [
        {
            key: 'person',
            label: 'Person and role',
            blurb: 'Who is involved',
            icon: UserRound,
        },
        {
            key: 'permission',
            label: 'Contact review',
            blurb: 'Safe contact and permission',
            icon: ShieldCheck,
        },
        {
            key: 'confirm',
            label: 'Confirm review',
            blurb: 'Check what will be recorded',
            icon: ClipboardCheck,
        },
    ];
    function change<K extends keyof Details>(key: K, value: Details[K]) {
        setForm((current) => ({ ...current, [key]: value, confirmed: false }));
    }
    function state(value: string) {
        setForm((current) => ({
            ...(['withdrawn', 'archived'].includes(value) && contact
                ? contact.details
                : current),
            state: value,
            review_note: '',
            review_due_at:
                ['withdrawn', 'archived'].includes(value) && contact
                    ? localInstant(contact.details.review_due_at)
                    : current.review_due_at,
            confirmed: false,
            may_contact: value === 'current' ? current.may_contact : false,
            leave_message: value === 'current' ? current.leave_message : false,
        }));
    }
    function go(value: number) {
        setStep(value);
        requestAnimationFrame(() => {
            body.current?.scrollTo({ top: 0 });
            body.current?.querySelector<HTMLElement>('[data-heading]')?.focus();
        });
    }
    function valid(index: number) {
        const ok =
            index === 0
                ? form.name.trim().length >= 2 &&
                  form.relationship.trim().length >= 2 &&
                  form.purpose.trim().length >= 10
                : index === 1
                  ? (!closed || retaining) &&
                    form.review_note.trim().length >= 10 &&
                    (form.state !== 'current' ||
                        (validReferralDateTime(form.review_due_at ?? '') &&
                            (form.role !== 'representative' ||
                                (form.authority_note?.trim().length ?? 0) >=
                                    10) &&
                            (!form.may_contact ||
                                (form.channel !== 'none' &&
                                    !!(form.channel === 'phone'
                                        ? form.phone?.trim()
                                        : form.email?.trim())))))
                  : form.state !== 'current' || form.confirmed;
        if (!ok) {
            setError(
                'Complete the contact details, review notes, date and required confirmations before saving.',
            );
            go(index);
        } else setError('');
        return ok;
    }
    async function save() {
        if (sending.current || conflict) return;
        if (!uncertain) {
            for (let index = 0; index < 3; index++) if (!valid(index)) return;
            issued.current = {
                version: referral.version,
                submission_key: crypto.randomUUID(),
                contact_id: contact?.id ?? crypto.randomUUID(),
                contact_version: contact?.version ?? 0,
                ...form,
                review_due_at: form.review_due_at || null,
            };
        }
        sending.current = true;
        setBusy(true);
        setError('');
        try {
            const { data } = await axios.post<{ saved: boolean }>(
                `${referralHref(referral.reference)}/people-contacts`,
                issued.current,
            );
            if (!data.saved) throw new Error('Missing receipt');
            setSaved(true);
            leave.preserveAndRun(() =>
                router.reload({ only: ['referral'], preserveScroll: true }),
            );
        } catch (caught) {
            const status = axios.isAxiosError(caught)
                ? caught.response?.status
                : undefined;
            setUncertain(!status || status >= 500);
            setConflict([403, 404, 409].includes(status ?? 0));
            setError(
                axios.isAxiosError(caught)
                    ? (caught.response?.data?.message ??
                          'Saving was not confirmed. Retry the same review to check its receipt.')
                    : 'Saving was not confirmed. Retry the same review to check its receipt.',
            );
        } finally {
            sending.current = false;
            setBusy(false);
        }
    }
    const textField = (
        key: keyof Details,
        title: string,
        max: number,
        long = false,
    ) => (
        <Field key={key} label={title}>
            {long ? (
                <Textarea
                    value={String(form[key] ?? '')}
                    maxLength={max}
                    onChange={(event) => change(key, event.target.value)}
                    readOnly={(retaining || closed) && key !== 'review_note'}
                />
            ) : (
                <Input
                    value={String(form[key] ?? '')}
                    maxLength={max}
                    onChange={(event) => change(key, event.target.value)}
                    readOnly={retaining || closed}
                />
            )}
        </Field>
    );
    return (
        <>
            {leave.confirmation}
            <WizardShell
                open
                onClose={() => !busy && (saved ? onClose() : setDiscard(true))}
                title={
                    contact ? 'Review referral contact' : 'Add referral contact'
                }
                description={referral.name}
                railIcon={UserRound}
                railTitle="People involved"
                railSub="A reviewed contact role does not grant portal access or decision-making powers."
                steps={steps}
                stepIndex={step}
                bodyRef={body}
                onStepClick={(next) => {
                    if (
                        !busy &&
                        !uncertain &&
                        !conflict &&
                        (next < step || valid(step))
                    )
                        go(Math.min(next, step + 1));
                }}
                footerStart={
                    <Button
                        variant="ghost"
                        disabled={busy}
                        onClick={() =>
                            step && !uncertain && !conflict
                                ? go(step - 1)
                                : setDiscard(true)
                        }
                    >
                        {step && !uncertain && !conflict ? 'Back' : 'Close'}
                    </Button>
                }
                footerEnd={
                    <Button
                        disabled={busy || conflict}
                        onClick={() =>
                            uncertain || step === 2
                                ? void save()
                                : valid(step) && go(step + 1)
                        }
                    >
                        {busy
                            ? 'Saving…'
                            : uncertain
                              ? 'Retry same review'
                              : step === 2
                                ? 'Save contact review'
                                : 'Continue'}
                    </Button>
                }
                success={
                    saved ? (
                        <WizardSuccessPane
                            title="Contact review saved"
                            blurb="The review and its history are saved on this referral. No account was created and no message was sent."
                            actions={
                                <Button onClick={onClose}>
                                    Return to contacts
                                </Button>
                            }
                        />
                    ) : undefined
                }
            >
                <div className="space-y-5">
                    <h2
                        data-heading
                        tabIndex={-1}
                        className="text-section-title"
                    >
                        {steps[step].label}
                    </h2>
                    {error && (
                        <Alert variant="warning">
                            <AlertDescription>
                                {error}
                                {conflict && (
                                    <p>
                                        Close and reload the referral before
                                        making another review.
                                    </p>
                                )}
                                {closed && (
                                    <Alert>
                                        <AlertDescription>
                                            This referral is closed. Choose
                                            Withdrawn or Archived and record a
                                            reason. Reopen the referral before
                                            renewing contact permission.
                                        </AlertDescription>
                                    </Alert>
                                )}
                            </AlertDescription>
                        </Alert>
                    )}
                    <fieldset
                        disabled={busy || uncertain || conflict}
                        className="space-y-4"
                    >
                        {step === 0 && (
                            <>
                                {textField('name', 'Contact name', 200)}
                                <Field label="Role">
                                    <SelectInput
                                        value={form.role}
                                        placeholder="Choose contact role"
                                        options={roles.map((value) => ({
                                            value,
                                            label: label(value),
                                        }))}
                                        onChange={(value) =>
                                            change('role', value)
                                        }
                                        disabled={retaining || closed}
                                    />
                                </Field>
                                {textField(
                                    'relationship',
                                    'Relationship to the person',
                                    160,
                                )}
                                {textField(
                                    'organisation',
                                    'Organisation, if applicable',
                                    200,
                                )}
                                {textField(
                                    'purpose',
                                    'Why is this person involved?',
                                    2000,
                                    true,
                                )}
                                <p className="text-subtle">
                                    Record the actual role. A family
                                    relationship or representative label is not
                                    evidence of legal authority.
                                </p>
                            </>
                        )}
                        {step === 1 && (
                            <>
                                <Field label="Review status">
                                    <SelectInput
                                        value={form.state}
                                        placeholder="Choose review status"
                                        options={[
                                            'pending',
                                            'current',
                                            ...(contact
                                                ? ['withdrawn', 'archived']
                                                : []),
                                        ].map((value) => ({
                                            value,
                                            label: label(value),
                                        }))}
                                        onChange={state}
                                    />
                                </Field>
                                {retaining && (
                                    <p className="text-subtle">
                                        The existing details are retained.
                                        Record why permission is being withdrawn
                                        or the contact archived.
                                    </p>
                                )}
                                <div className="grid gap-4 sm:grid-cols-2">
                                    {textField('phone', 'Phone', 80)}
                                    {textField('email', 'Email', 254)}
                                </div>
                                <Field label="Agreed contact channel">
                                    <SelectInput
                                        value={form.channel}
                                        placeholder="Choose contact channel"
                                        options={[
                                            {
                                                value: 'none',
                                                label: 'No direct contact',
                                            },
                                            { value: 'phone', label: 'Phone' },
                                            { value: 'email', label: 'Email' },
                                        ]}
                                        disabled={retaining || closed}
                                        onChange={(value) =>
                                            setForm((current) => ({
                                                ...current,
                                                channel: value,
                                                may_contact:
                                                    value === 'none'
                                                        ? false
                                                        : current.may_contact,
                                                leave_message: false,
                                                confirmed: false,
                                            }))
                                        }
                                    />
                                </Field>
                                {textField(
                                    'safe_time',
                                    'Safe times and contact instructions',
                                    1000,
                                    true,
                                )}
                                {textField(
                                    'communication',
                                    'Communication support',
                                    1000,
                                    true,
                                )}
                                <div className="flex items-start gap-3">
                                    <Checkbox
                                        id="people-contact-permission"
                                        checked={form.may_contact}
                                        disabled={
                                            closed ||
                                            form.state !== 'current' ||
                                            form.channel === 'none'
                                        }
                                        onCheckedChange={(checked) =>
                                            setForm((current) => ({
                                                ...current,
                                                may_contact: checked === true,
                                                leave_message: false,
                                                confirmed: false,
                                            }))
                                        }
                                    />
                                    <Label
                                        htmlFor="people-contact-permission"
                                        className="flex items-center"
                                    >
                                        Contact is permitted for the recorded
                                        purpose under current authority.
                                    </Label>
                                </div>
                                <div className="flex items-start gap-3">
                                    <Checkbox
                                        id="people-contact-message"
                                        checked={form.leave_message}
                                        disabled={
                                            closed ||
                                            !form.may_contact ||
                                            form.channel !== 'phone'
                                        }
                                        onCheckedChange={(checked) =>
                                            change(
                                                'leave_message',
                                                checked === true,
                                            )
                                        }
                                    />
                                    <Label
                                        htmlFor="people-contact-message"
                                        className="flex items-center"
                                    >
                                        A phone message may be left using the
                                        safe-contact instructions.
                                    </Label>
                                </div>
                                {textField(
                                    'authority_note',
                                    'Verified role, authority basis and limits',
                                    2000,
                                    true,
                                )}
                                <fieldset disabled={retaining || closed}>
                                    <ReferralDateTime
                                        id="people-contact-review-due"
                                        label="Review due"
                                        value={form.review_due_at ?? ''}
                                        onChange={(value) =>
                                            change('review_due_at', value)
                                        }
                                    />
                                </fieldset>
                                {textField(
                                    'review_note',
                                    'What was checked or why is the status changing?',
                                    2000,
                                    true,
                                )}
                            </>
                        )}
                        {step === 2 && (
                            <>
                                <ReviewCard
                                    title="Contact review"
                                    icon={UserRound}
                                >
                                    {Object.entries({
                                        Name: form.name,
                                        Role: label(form.role),
                                        Relationship: form.relationship,
                                        Purpose: form.purpose,
                                        Status: label(form.state),
                                        Phone: form.phone,
                                        Email: form.email,
                                        Channel:
                                            form.channel === 'none'
                                                ? 'No direct contact'
                                                : label(form.channel),
                                        'Safe contact': form.safe_time,
                                        Communication: form.communication,
                                        'Contact permission': form.may_contact
                                            ? 'Permitted for the recorded purpose'
                                            : 'Do not contact',
                                        Messages: form.leave_message
                                            ? 'May leave a phone message'
                                            : 'Do not leave a message',
                                        'Authority limits': form.authority_note,
                                        'Review due': form.review_due_at
                                            ? formatDateTimeLong(
                                                  form.review_due_at,
                                              )
                                            : null,
                                        'Review note': form.review_note,
                                    }).map(([title, value]) => (
                                        <ReviewRow
                                            key={title}
                                            label={title}
                                            value={value || 'Not recorded'}
                                        />
                                    ))}
                                </ReviewCard>
                                {form.state === 'current' && (
                                    <div className="flex items-start gap-3">
                                        <Checkbox
                                            id="people-contact-confirm"
                                            checked={form.confirmed}
                                            onCheckedChange={(checked) =>
                                                setForm((current) => ({
                                                    ...current,
                                                    confirmed: checked === true,
                                                }))
                                            }
                                        />
                                        <Label
                                            htmlFor="people-contact-confirm"
                                            className="flex items-center"
                                        >
                                            I verified this person’s identity,
                                            role, contact details and agreed
                                            purpose under current authority.
                                            This record does not grant portal
                                            access, disclosure permission or
                                            legal powers.
                                        </Label>
                                    </div>
                                )}
                            </>
                        )}
                    </fieldset>
                </div>
            </WizardShell>
            <ConfirmDialog
                variant="destructive"
                open={discard}
                onClose={() => setDiscard(false)}
                title="Close this contact review?"
                description={
                    uncertain
                        ? 'Saving may already have succeeded. Check the contact history before starting another review.'
                        : 'Unsaved contact details will be discarded.'
                }
                confirmText="Close review"
                onConfirm={onClose}
            />
        </>
    );
}
