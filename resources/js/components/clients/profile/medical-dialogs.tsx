import { EntityTable } from '@/components/lists/entity-table';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput } from '@/components/wizard/primitives';
import { WizardShell } from '@/components/wizard/shell';
import { ReviewWizard } from '@/pages/emar/connected/_shared';
import type { Page } from '@inertiajs/core';
import { useForm } from '@inertiajs/react';
import { ClipboardList, Phone, Plus } from 'lucide-react';
import { useState } from 'react';

export type MedicalSection = 'profile' | 'conditions' | 'emergency_contacts';
type Condition = {
    id: number;
    label: string;
    severity?: string | null;
    notes?: string | null;
};
type Contact = {
    id: number;
    name: string;
    relationship?: string | null;
    phone?: string | null;
    alternate_phone?: string | null;
    email?: string | null;
    address?: string | null;
    preferred_method?: string | null;
    availability?: string | null;
    is_primary_contact?: boolean;
    can_view_medical?: boolean;
    can_view_medications?: boolean;
    can_view_incidents?: boolean;
    can_receive_updates?: boolean;
    notes?: string | null;
};
type Medical = {
    profile: {
        medical_history?: string | null;
        disabilities?: string[] | null;
        notes?: string | null;
    } | null;
    conditions: Condition[];
    emergency_contacts: Contact[];
};

/** These editors use the client profile commands; allergies have their own canonical editor. */
export function MedicalDialogs({
    section,
    clientId,
    medical,
    onClose,
}: {
    section: MedicalSection;
    clientId: number;
    medical: Medical;
    onClose: () => void;
}) {
    const [editing, setEditing] = useState<Condition | Contact | 'new' | null>(
        null,
    );
    if (section === 'profile')
        return (
            <ProfileEditor
                clientId={clientId}
                profile={medical.profile}
                onClose={onClose}
            />
        );
    if (editing)
        return section === 'conditions' ? (
            <ConditionEditor
                clientId={clientId}
                row={editing === 'new' ? undefined : (editing as Condition)}
                onClose={() => setEditing(null)}
            />
        ) : (
            <ContactEditor
                clientId={clientId}
                row={editing === 'new' ? undefined : (editing as Contact)}
                onClose={() => setEditing(null)}
            />
        );
    const conditions = section === 'conditions';
    return (
        <WizardShell
            open
            onClose={onClose}
            title={conditions ? 'Medical conditions' : 'Emergency contacts'}
            description="Manage this person’s health profile."
            railIcon={conditions ? ClipboardList : Phone}
            railTitle={conditions ? 'Medical conditions' : 'Emergency contacts'}
            railSub="Client health profile"
            steps={[
                {
                    key: 'records',
                    label: 'Records',
                    blurb: 'Add or update details',
                    icon: conditions ? ClipboardList : Phone,
                },
            ]}
            stepIndex={0}
            onStepClick={() => {}}
            sequential={false}
            headerLabel="Records"
            footerEnd={
                <Button variant="outline" onClick={onClose}>
                    Close
                </Button>
            }
        >
            <div className="space-y-4 p-6">
                <Button onClick={() => setEditing('new')}>
                    <Plus className="size-4" />{' '}
                    {conditions ? 'Add condition' : 'Add contact'}
                </Button>
                {conditions ? (
                    <EntityTable
                        rows={medical.conditions}
                        rowKey={(row) => row.id}
                        identity={(row) => ({
                            name: row.label,
                            subline: row.severity ?? undefined,
                            icon: ClipboardList,
                        })}
                        columns={[
                            {
                                key: 'notes',
                                label: 'Notes',
                                width: '2fr',
                                cell: (row) => row.notes ?? '—',
                            },
                        ]}
                        onOpen={setEditing}
                        actionsFor={(row) => [
                            {
                                label: 'Edit condition',
                                onClick: () => setEditing(row),
                            },
                        ]}
                    />
                ) : (
                    <EntityTable
                        rows={medical.emergency_contacts}
                        rowKey={(row) => row.id}
                        identity={(row) => ({
                            name: row.name,
                            subline: row.relationship ?? undefined,
                            icon: Phone,
                        })}
                        columns={[
                            {
                                key: 'contact',
                                label: 'Contact',
                                width: '2fr',
                                cell: (row) =>
                                    row.phone ||
                                    row.email ||
                                    'No contact details recorded',
                            },
                        ]}
                        onOpen={setEditing}
                        actionsFor={(row) => [
                            {
                                label: 'Edit contact',
                                onClick: () => setEditing(row),
                            },
                        ]}
                    />
                )}
                {(conditions ? medical.conditions : medical.emergency_contacts)
                    .length === 0 && (
                    <p className="text-caption">
                        No records yet. Add the first one above.
                    </p>
                )}
            </div>
        </WizardShell>
    );
}

function ProfileEditor({
    clientId,
    profile,
    onClose,
}: {
    clientId: number;
    profile: Medical['profile'];
    onClose: () => void;
}) {
    const form = useForm({
        medical_history: profile?.medical_history ?? '',
        disabilities: profile?.disabilities ?? [],
        notes: profile?.notes ?? '',
    });
    const [saved, setSaved] = useState(false);
    const [saveError, setSaveError] = useState('');
    return (
        <ReviewWizard
            title="Edit medical profile"
            description="Medical history, disabilities and support notes. Manage allergies in the Allergy record on this page."
            onClose={onClose}
            busy={form.processing}
            dirty={form.isDirty}
            saved={saved}
            error={saveError || Object.values(form.errors).join(' ')}
            onSave={() =>
                form.put(`/operations/clients/${clientId}/medical/profile`, {
                    preserveScroll: true,
                    onSuccess: (page: Page) => {
                        const error = (
                            page.props.flash as { error?: string } | undefined
                        )?.error;
                        if (error) setSaveError(error);
                        else setSaved(true);
                    },
                })
            }
            steps={[
                {
                    label: 'Profile',
                    content: (
                        <div className="space-y-4">
                            <Field
                                htmlFor="medical-history"
                                label="Medical history"
                            >
                                <Textarea
                                    id="medical-history"
                                    value={form.data.medical_history}
                                    onChange={(e) =>
                                        form.setData(
                                            'medical_history',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                htmlFor="medical-disabilities"
                                label="Disabilities"
                                hint="One per line."
                            >
                                <Textarea
                                    id="medical-disabilities"
                                    value={form.data.disabilities.join('\n')}
                                    onChange={(e) =>
                                        form.setData(
                                            'disabilities',
                                            e.target.value.split('\n'),
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                htmlFor="medical-notes"
                                label="Support notes"
                            >
                                <Textarea
                                    id="medical-notes"
                                    value={form.data.notes}
                                    onChange={(e) =>
                                        form.setData('notes', e.target.value)
                                    }
                                />
                            </Field>
                        </div>
                    ),
                },
            ]}
            review={[
                {
                    label: 'Medical history',
                    value: form.data.medical_history || 'None recorded',
                },
                {
                    label: 'Disabilities',
                    value:
                        form.data.disabilities.filter(Boolean).join(', ') ||
                        'None recorded',
                },
                {
                    label: 'Support notes',
                    value: form.data.notes || 'None recorded',
                },
            ]}
        />
    );
}

function ConditionEditor({
    clientId,
    row,
    onClose,
}: {
    clientId: number;
    row?: Condition;
    onClose: () => void;
}) {
    const form = useForm({
        label: row?.label ?? '',
        severity: row?.severity ?? '',
        notes: row?.notes ?? '',
    });
    const [saved, setSaved] = useState(false);
    const [saveError, setSaveError] = useState('');
    const save = () => {
        const url = `/operations/clients/${clientId}/medical/conditions`;
        const options = {
            preserveScroll: true,
            onSuccess: (page: Page) => {
                const error = (
                    page.props.flash as { error?: string } | undefined
                )?.error;
                if (error) setSaveError(error);
                else setSaved(true);
            },
        };
        if (row) form.put(`${url}/${row.id}`, options);
        else form.post(url, options);
    };
    return (
        <ReviewWizard
            title={row ? 'Edit condition' : 'Add condition'}
            description="Record the condition and its support implications."
            onClose={onClose}
            busy={form.processing}
            dirty={form.isDirty}
            saved={saved}
            error={saveError || Object.values(form.errors).join(' ')}
            onSave={save}
            steps={[
                {
                    label: 'Condition',
                    valid: !!form.data.label.trim(),
                    content: (
                        <div className="space-y-4">
                            <Field
                                htmlFor="condition-label"
                                label="Condition"
                                required
                            >
                                <Input
                                    id="condition-label"
                                    maxLength={255}
                                    value={form.data.label}
                                    onChange={(e) =>
                                        form.setData('label', e.target.value)
                                    }
                                />
                            </Field>
                            <Field
                                htmlFor="condition-severity"
                                label="Severity"
                            >
                                <Input
                                    id="condition-severity"
                                    maxLength={80}
                                    value={form.data.severity}
                                    onChange={(e) =>
                                        form.setData('severity', e.target.value)
                                    }
                                />
                            </Field>
                            <Field
                                htmlFor="condition-notes"
                                label="Support notes"
                            >
                                <Textarea
                                    id="condition-notes"
                                    value={form.data.notes}
                                    onChange={(e) =>
                                        form.setData('notes', e.target.value)
                                    }
                                />
                            </Field>
                        </div>
                    ),
                },
            ]}
            review={[
                { label: 'Condition', value: form.data.label },
                {
                    label: 'Severity',
                    value: form.data.severity || 'Not recorded',
                },
                { label: 'Notes', value: form.data.notes || 'None recorded' },
            ]}
        />
    );
}

function ContactEditor({
    clientId,
    row,
    onClose,
}: {
    clientId: number;
    row?: Contact;
    onClose: () => void;
}) {
    const form = useForm({
        name: row?.name ?? '',
        relationship: row?.relationship ?? '',
        phone: row?.phone ?? '',
        alternate_phone: row?.alternate_phone ?? '',
        email: row?.email ?? '',
        address: row?.address ?? '',
        preferred_method: row?.preferred_method ?? '',
        availability: row?.availability ?? '',
        notes: row?.notes ?? '',
    });
    const [saved, setSaved] = useState(false);
    const [saveError, setSaveError] = useState('');
    const save = () => {
        const url = `/operations/clients/${clientId}/medical/emergency-contacts`;
        const options = {
            preserveScroll: true,
            onSuccess: (page: Page) => {
                const error = (
                    page.props.flash as { error?: string } | undefined
                )?.error;
                if (error) setSaveError(error);
                else setSaved(true);
            },
        };
        if (row) form.put(`${url}/${row.id}`, options);
        else form.post(url, options);
    };
    const fields = [
        ['name', 'Name'],
        ['relationship', 'Relationship'],
        ['phone', 'Phone'],
        ['alternate_phone', 'Alternate phone'],
        ['email', 'Email'],
        ['address', 'Address'],
        ['availability', 'Availability'],
    ] as const;
    return (
        <ReviewWizard
            title={row ? 'Edit emergency contact' : 'Add emergency contact'}
            description="How staff reach this person for help. Existing sharing permissions are kept."
            onClose={onClose}
            busy={form.processing}
            dirty={form.isDirty}
            saved={saved}
            error={saveError || Object.values(form.errors).join(' ')}
            onSave={save}
            steps={[
                {
                    label: 'Contact',
                    valid: !!form.data.name.trim(),
                    content: (
                        <div className="grid grid-cols-2 gap-4">
                            {fields.map(([key, label]) => (
                                <Field
                                    key={key}
                                    htmlFor={`contact-${key}`}
                                    label={label}
                                    required={key === 'name'}
                                >
                                    <Input
                                        id={`contact-${key}`}
                                        type={
                                            key === 'email' ? 'email' : 'text'
                                        }
                                        maxLength={255}
                                        value={form.data[key]}
                                        onChange={(e) =>
                                            form.setData(key, e.target.value)
                                        }
                                    />
                                </Field>
                            ))}
                            <Field label="Preferred contact method">
                                <SelectInput
                                    ariaLabel="Preferred contact method"
                                    placeholder="Not specified"
                                    value={form.data.preferred_method}
                                    onChange={(value) =>
                                        form.setData('preferred_method', value)
                                    }
                                    options={[
                                        { value: 'phone', label: 'Phone' },
                                        { value: 'text', label: 'Text' },
                                        { value: 'email', label: 'Email' },
                                    ]}
                                />
                            </Field>
                            <Field htmlFor="contact-notes" label="Notes">
                                <Textarea
                                    id="contact-notes"
                                    value={form.data.notes}
                                    onChange={(e) =>
                                        form.setData('notes', e.target.value)
                                    }
                                />
                            </Field>
                        </div>
                    ),
                },
            ]}
            review={[
                ...fields.map(([key, label]) => ({
                    label,
                    value: form.data[key] || 'Not recorded',
                })),
                {
                    label: 'Preferred method',
                    value: form.data.preferred_method || 'Not specified',
                },
                { label: 'Notes', value: form.data.notes || 'None recorded' },
            ]}
        />
    );
}
