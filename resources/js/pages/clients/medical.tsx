import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';

type Props = {
    client: { id: number; first_name: string; last_name: string };
    can_edit: boolean;
    can_record: boolean;
    can_stock: boolean;
    profile: any | null;
    medications: Array<any>;
    conditions: Array<any>;
    emergency_contacts: Array<any>;
    administrations: Array<any>;
};

export default function ClientMedical({
    client,
    can_edit,
    can_record,
    can_stock,
    profile,
    medications,
    conditions,
    emergency_contacts,
    administrations,
}: Props) {
    const name = `${client.first_name} ${client.last_name}`.trim();

    const profileForm = useForm({
        medical_history: profile?.medical_history || '',
        disabilities: profile?.disabilities || '',
        allergies: profile?.allergies || '',
        notes: profile?.notes || '',
    });

    const medForm = useForm({
        name: '',
        dosage: '',
        frequency: '',
        is_prn: false,
        prn_reason: '',
        max_per_day: '',
        route: '',
        prescriber: '',
        start_date: '',
        end_date: '',
        instructions: '',
        active: true,
    });

    const administrationForm = useForm({
        medication_id: medications?.[0]?.id ?? '',
        status: 'given',
        dose_given: '',
        administered_at: new Date().toISOString().slice(0, 16),
        scheduled_for: '',
        shift_id: '',
        notes: '',
    });

    const stockForm = useForm({
        medication_id: medications?.[0]?.id ?? '',
        on_hand: '',
        unit: '',
        reorder_level: '',
        last_counted_at: '',
        notes: '',
    });
    const conditionForm = useForm({
        label: '',
        severity: '',
        notes: '',
    });

    const contactForm = useForm({
        name: '',
        relationship: '',
        phone: '',
        email: '',
        notes: '',
    });

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Clients', href: '/clients' },
                { title: name, href: `/clients/${client.id}` },
                { title: 'Medical', href: `/clients/${client.id}/medical` },
            ]}
        >
            <Head title={`Medical - ${name}`} />

            <div className="grid auto-rows-fr grid-cols-2 gap-4 md:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Medical profile
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div>
                            <Label>Medical history</Label>
                            <Textarea
                                value={profileForm.data.medical_history}
                                onChange={(e) =>
                                    profileForm.setData(
                                        'medical_history',
                                        e.target.value,
                                    )
                                }
                                disabled={!can_edit}
                            />
                        </div>
                        <div>
                            <Label>Disabilities</Label>
                            <Textarea
                                value={profileForm.data.disabilities}
                                onChange={(e) =>
                                    profileForm.setData(
                                        'disabilities',
                                        e.target.value,
                                    )
                                }
                                disabled={!can_edit}
                            />
                        </div>
                        <div>
                            <Label>Allergies</Label>
                            <Textarea
                                value={profileForm.data.allergies}
                                onChange={(e) =>
                                    profileForm.setData(
                                        'allergies',
                                        e.target.value,
                                    )
                                }
                                disabled={!can_edit}
                            />
                        </div>
                        <div>
                            <Label>Notes</Label>
                            <Textarea
                                value={profileForm.data.notes}
                                onChange={(e) =>
                                    profileForm.setData('notes', e.target.value)
                                }
                                disabled={!can_edit}
                            />
                        </div>

                        {can_edit && (
                            <Button
                                onClick={() =>
                                    profileForm.put(
                                        `/clients/${client.id}/medical/profile`,
                                        {
                                            preserveScroll: true,
                                        },
                                    )
                                }
                                disabled={profileForm.processing}
                            >
                                Save profile
                            </Button>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Medications</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {can_edit && (
                            <div className="rounded-md border p-3">
                                <div className="text-sm font-medium">
                                    Add medication
                                </div>
                                <div className="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                                    <div>
                                        <Label>Name</Label>
                                        <Input
                                            value={medForm.data.name}
                                            onChange={(e) =>
                                                medForm.setData(
                                                    'name',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div>
                                        <Label>Dosage</Label>
                                        <Input
                                            value={medForm.data.dosage}
                                            onChange={(e) =>
                                                medForm.setData(
                                                    'dosage',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div>
                                        <Label>Frequency</Label>
                                        <Input
                                            value={medForm.data.frequency}
                                            onChange={(e) =>
                                                medForm.setData(
                                                    'frequency',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="flex items-center gap-2 pt-6">
                                        <Checkbox
                                            checked={!!medForm.data.is_prn}
                                            onCheckedChange={(v) =>
                                                medForm.setData('is_prn', !!v)
                                            }
                                        />
                                        <Label className="!mt-0">PRN (as needed)</Label>
                                    </div>
                                    {medForm.data.is_prn && (
                                        <>
                                            <div>
                                                <Label>PRN reason</Label>
                                                <Input
                                                    value={medForm.data.prn_reason}
                                                    onChange={(e) =>
                                                        medForm.setData(
                                                            'prn_reason',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </div>
                                            <div>
                                                <Label>Max per day</Label>
                                                <Input
                                                    value={medForm.data.max_per_day}
                                                    onChange={(e) =>
                                                        medForm.setData(
                                                            'max_per_day',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </div>
                                        </>
                                    )}
                                    <div>
                                        <Label>Route</Label>
                                        <Input
                                            value={medForm.data.route}
                                            onChange={(e) =>
                                                medForm.setData(
                                                    'route',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div>
                                        <Label>Prescriber</Label>
                                        <Input
                                            value={medForm.data.prescriber}
                                            onChange={(e) =>
                                                medForm.setData(
                                                    'prescriber',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div>
                                        <Label>Start date</Label>
                                        <Input
                                            type="date"
                                            value={medForm.data.start_date}
                                            onChange={(e) =>
                                                medForm.setData(
                                                    'start_date',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div>
                                        <Label>End date</Label>
                                        <Input
                                            type="date"
                                            value={medForm.data.end_date}
                                            onChange={(e) =>
                                                medForm.setData(
                                                    'end_date',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="md:col-span-2">
                                        <Label>Instructions</Label>
                                        <Textarea
                                            value={medForm.data.instructions}
                                            onChange={(e) =>
                                                medForm.setData(
                                                    'instructions',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="flex items-center gap-2 pt-6">
                                        <Checkbox
                                            checked={!!medForm.data.active}
                                            onCheckedChange={(v) =>
                                                medForm.setData('active', !!v)
                                            }
                                        />
                                        <Label className="!mt-0">Active</Label>
                                    </div>
                                </div>
                                <div className="mt-3">
                                    <Button
                                        onClick={() =>
                                            medForm.post(
                                                `/clients/${client.id}/medical/medications`,
                                                {
                                                    preserveScroll: true,
                                                    onSuccess: () =>
                                                        medForm.reset(),
                                                },
                                            )
                                        }
                                        disabled={
                                            medForm.processing ||
                                            !medForm.data.name.trim()
                                        }
                                    >
                                        Add
                                    </Button>
                                </div>
                            </div>
                        )}

                        <Separator />

                        <div className="space-y-2">
                            {medications.map((m) => (
                                <div
                                    key={m.id}
                                    className="rounded-md border p-3"
                                >
                                    <div className="flex items-center justify-between gap-3">
                                        <div className="text-sm font-medium">
                                            {m.name}
                                        </div>
                                        {can_edit && (
                                            <Button
                                                variant="destructive"
                                                onClick={() =>
                                                    medForm.delete(
                                                        `/clients/${client.id}/medical/medications/${m.id}`,
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Remove
                                            </Button>
                                        )}
                                    </div>
                                    <div className="mt-1 text-xs text-slate-500">
                                        {[
                                            m.dosage && `Dosage: ${m.dosage}`,
                                            m.frequency &&
                                                `Frequency: ${m.frequency}`,
                                            m.route && `Route: ${m.route}`,
                                        ]
                                            .filter(Boolean)
                                            .join(' - ')}
                                    </div>
                                    {m.instructions && (
                                        <div className="mt-2 text-xs whitespace-pre-wrap text-slate-600">
                                            {m.instructions}
                                        </div>
                                    )}
                                </div>
                            ))}
                            {!medications.length && (
                                <div className="text-sm text-slate-500">
                                    No medications listed.
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Conditions</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {can_edit && (
                            <div className="rounded-md border p-3">
                                <div className="text-sm font-medium">
                                    Add condition
                                </div>
                                <div className="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                                    <div>
                                        <Label>Label</Label>
                                        <Input
                                            value={conditionForm.data.label}
                                            onChange={(e) =>
                                                conditionForm.setData(
                                                    'label',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div>
                                        <Label>Severity</Label>
                                        <Input
                                            value={conditionForm.data.severity}
                                            onChange={(e) =>
                                                conditionForm.setData(
                                                    'severity',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="mild / moderate / severe"
                                        />
                                    </div>
                                    <div className="md:col-span-2">
                                        <Label>Notes</Label>
                                        <Textarea
                                            value={conditionForm.data.notes}
                                            onChange={(e) =>
                                                conditionForm.setData(
                                                    'notes',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                </div>
                                <div className="mt-3">
                                    <Button
                                        onClick={() =>
                                            conditionForm.post(
                                                `/clients/${client.id}/medical/conditions`,
                                                {
                                                    preserveScroll: true,
                                                    onSuccess: () =>
                                                        conditionForm.reset(),
                                                },
                                            )
                                        }
                                        disabled={
                                            conditionForm.processing ||
                                            !conditionForm.data.label.trim()
                                        }
                                    >
                                        Add
                                    </Button>
                                </div>
                            </div>
                        )}

                        <Separator />

                        <div className="space-y-2">
                            {conditions.map((c) => (
                                <div
                                    key={c.id}
                                    className="rounded-md border p-3"
                                >
                                    <div className="flex items-center justify-between gap-3">
                                        <div className="text-sm font-medium">
                                            {c.label}
                                            {c.severity ? (
                                                <span className="ml-2 text-xs text-slate-500">
                                                    ({c.severity})
                                                </span>
                                            ) : null}
                                        </div>
                                        {can_edit && (
                                            <Button
                                                variant="destructive"
                                                onClick={() =>
                                                    conditionForm.delete(
                                                        `/clients/${client.id}/medical/conditions/${c.id}`,
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Remove
                                            </Button>
                                        )}
                                    </div>
                                    {c.notes && (
                                        <div className="mt-2 text-xs whitespace-pre-wrap text-slate-600">
                                            {c.notes}
                                        </div>
                                    )}
                                </div>
                            ))}
                            {!conditions.length && (
                                <div className="text-sm text-slate-500">
                                    No conditions listed.
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Emergency contacts
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {can_edit && (
                            <div className="rounded-md border p-3">
                                <div className="text-sm font-medium">
                                    Add emergency contact
                                </div>
                                <div className="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                                    <div>
                                        <Label>Name</Label>
                                        <Input
                                            value={contactForm.data.name}
                                            onChange={(e) =>
                                                contactForm.setData(
                                                    'name',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div>
                                        <Label>Relationship</Label>
                                        <Input
                                            value={
                                                contactForm.data.relationship
                                            }
                                            onChange={(e) =>
                                                contactForm.setData(
                                                    'relationship',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div>
                                        <Label>Phone</Label>
                                        <Input
                                            value={contactForm.data.phone}
                                            onChange={(e) =>
                                                contactForm.setData(
                                                    'phone',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div>
                                        <Label>Email</Label>
                                        <Input
                                            value={contactForm.data.email}
                                            onChange={(e) =>
                                                contactForm.setData(
                                                    'email',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="md:col-span-2">
                                        <Label>Notes</Label>
                                        <Textarea
                                            value={contactForm.data.notes}
                                            onChange={(e) =>
                                                contactForm.setData(
                                                    'notes',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                </div>
                                <div className="mt-3">
                                    <Button
                                        onClick={() =>
                                            contactForm.post(
                                                `/clients/${client.id}/medical/emergency-contacts`,
                                                {
                                                    preserveScroll: true,
                                                    onSuccess: () =>
                                                        contactForm.reset(),
                                                },
                                            )
                                        }
                                        disabled={
                                            contactForm.processing ||
                                            !contactForm.data.name.trim()
                                        }
                                    >
                                        Add
                                    </Button>
                                </div>
                            </div>
                        )}

                        <Separator />

                        <div className="space-y-2">
                            {emergency_contacts.map((e) => (
                                <div
                                    key={e.id}
                                    className="rounded-md border p-3"
                                >
                                    <div className="flex items-center justify-between gap-3">
                                        <div className="text-sm font-medium">
                                            {e.name}
                                        </div>
                                        {can_edit && (
                                            <Button
                                                variant="destructive"
                                                onClick={() =>
                                                    contactForm.delete(
                                                        `/clients/${client.id}/medical/emergency-contacts/${e.id}`,
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Remove
                                            </Button>
                                        )}
                                    </div>
                                    <div className="mt-1 text-xs text-slate-500">
                                        {[
                                            e.relationship &&
                                                `Relationship: ${e.relationship}`,
                                            e.phone && `Phone: ${e.phone}`,
                                            e.email && `Email: ${e.email}`,
                                        ]
                                            .filter(Boolean)
                                            .join(' - ')}
                                    </div>
                                    {e.notes && (
                                        <div className="mt-2 text-xs whitespace-pre-wrap text-slate-600">
                                            {e.notes}
                                        </div>
                                    )}
                                </div>
                            ))}
                            {!emergency_contacts.length && (
                                <div className="text-sm text-slate-500">
                                    No emergency contacts listed.
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>
            </div>

            {/* MAR + Stock */}
            <div className="grid gap-4 md:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Medication administration (MAR)
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {can_record && medications.length > 0 && (
                            <div className="rounded-md border p-3">
                                <div className="text-sm font-medium">
                                    Record administration
                                </div>
                                <div className="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                                    <div className="md:col-span-2">
                                        <Label>Medication</Label>
                                        <Select
                                            value={`${administrationForm.data.medication_id}`}
                                            onValueChange={(v) =>
                                                administrationForm.setData(
                                                    'medication_id',
                                                    v,
                                                )
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select medication" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {medications.map((m) => (
                                                    <SelectItem
                                                        key={m.id}
                                                        value={`${m.id}`}
                                                    >
                                                        {m.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div>
                                        <Label>Status</Label>
                                        <Select
                                            value={administrationForm.data.status}
                                            onValueChange={(v) =>
                                                administrationForm.setData(
                                                    'status',
                                                    v,
                                                )
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="given">
                                                    Given
                                                </SelectItem>
                                                <SelectItem value="refused">
                                                    Refused
                                                </SelectItem>
                                                <SelectItem value="missed">
                                                    Missed
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div>
                                        <Label>Dose given</Label>
                                        <Input
                                            value={administrationForm.data.dose_given}
                                            onChange={(e) =>
                                                administrationForm.setData(
                                                    'dose_given',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>

                                    <div>
                                        <Label>Administered at</Label>
                                        <Input
                                            type="datetime-local"
                                            value={administrationForm.data.administered_at}
                                            onChange={(e) =>
                                                administrationForm.setData(
                                                    'administered_at',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>

                                    <div>
                                        <Label>Scheduled for (optional)</Label>
                                        <Input
                                            type="datetime-local"
                                            value={administrationForm.data.scheduled_for}
                                            onChange={(e) =>
                                                administrationForm.setData(
                                                    'scheduled_for',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>

                                    <div className="md:col-span-2">
                                        <Label>Notes</Label>
                                        <Textarea
                                            value={administrationForm.data.notes}
                                            onChange={(e) =>
                                                administrationForm.setData(
                                                    'notes',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                </div>
                                <div className="mt-3">
                                    <Button
                                        onClick={() =>
                                            administrationForm.post(
                                                `/clients/${client.id}/medical/medications/${administrationForm.data.medication_id}/administrations`,
                                                {
                                                    preserveScroll: true,
                                                    onSuccess: () =>
                                                        administrationForm.reset(
                                                            'dose_given',
                                                            'scheduled_for',
                                                            'notes',
                                                        ),
                                                },
                                            )
                                        }
                                        disabled={
                                            administrationForm.processing ||
                                            !administrationForm.data
                                                .medication_id
                                        }
                                    >
                                        Save
                                    </Button>
                                </div>
                            </div>
                        )}

                        <div className="space-y-2">
                            {administrations.map((a) => (
                                <div
                                    key={a.id}
                                    className="rounded-md border p-3"
                                >
                                    <div className="flex items-center justify-between gap-3">
                                        <div className="text-sm font-medium">
                                            {a.medication?.name || 'Medication'}
                                        </div>
                                        <div className="text-xs text-slate-500">
                                            {a.status}
                                        </div>
                                    </div>
                                    <div className="mt-1 text-xs text-slate-500">
                                        {a.administered_at
                                            ? `Administered: ${new Date(a.administered_at).toLocaleString()}`
                                            : ''}
                                        {a.administeredBy?.name
                                            ? ` • By: ${a.administeredBy.name}`
                                            : ''}
                                        {a.dose_given
                                            ? ` • Dose: ${a.dose_given}`
                                            : ''}
                                    </div>
                                    {a.notes && (
                                        <div className="mt-2 text-xs whitespace-pre-wrap text-slate-600">
                                            {a.notes}
                                        </div>
                                    )}
                                </div>
                            ))}
                            {!administrations.length && (
                                <div className="text-sm text-slate-500">
                                    No administrations recorded yet.
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Stock</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {can_stock && medications.length > 0 && (
                            <div className="rounded-md border p-3">
                                <div className="text-sm font-medium">
                                    Update stock
                                </div>
                                <div className="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                                    <div className="md:col-span-2">
                                        <Label>Medication</Label>
                                        <Select
                                            value={`${stockForm.data.medication_id}`}
                                            onValueChange={(v) =>
                                                stockForm.setData(
                                                    'medication_id',
                                                    v,
                                                )
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select medication" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {medications.map((m) => (
                                                    <SelectItem
                                                        key={m.id}
                                                        value={`${m.id}`}
                                                    >
                                                        {m.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div>
                                        <Label>On hand</Label>
                                        <Input
                                            value={stockForm.data.on_hand}
                                            onChange={(e) =>
                                                stockForm.setData(
                                                    'on_hand',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div>
                                        <Label>Unit</Label>
                                        <Input
                                            value={stockForm.data.unit}
                                            onChange={(e) =>
                                                stockForm.setData(
                                                    'unit',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div>
                                        <Label>Reorder level</Label>
                                        <Input
                                            value={stockForm.data.reorder_level}
                                            onChange={(e) =>
                                                stockForm.setData(
                                                    'reorder_level',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div>
                                        <Label>Last counted (optional)</Label>
                                        <Input
                                            type="date"
                                            value={stockForm.data.last_counted_at}
                                            onChange={(e) =>
                                                stockForm.setData(
                                                    'last_counted_at',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="md:col-span-2">
                                        <Label>Notes</Label>
                                        <Textarea
                                            value={stockForm.data.notes}
                                            onChange={(e) =>
                                                stockForm.setData(
                                                    'notes',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                </div>
                                <div className="mt-3">
                                    <Button
                                        onClick={() =>
                                            stockForm.put(
                                                `/clients/${client.id}/medical/medications/${stockForm.data.medication_id}/stock`,
                                                {
                                                    preserveScroll: true,
                                                },
                                            )
                                        }
                                        disabled={
                                            stockForm.processing ||
                                            !stockForm.data.medication_id
                                        }
                                    >
                                        Save
                                    </Button>
                                </div>
                            </div>
                        )}

                        <div className="space-y-2">
                            {medications.map((m) => (
                                <div
                                    key={m.id}
                                    className="rounded-md border p-3"
                                >
                                    <div className="flex items-center justify-between gap-3">
                                        <div className="text-sm font-medium">
                                            {m.name}
                                        </div>
                                        <div className="text-xs text-slate-500">
                                            {m.stock?.on_hand !== null &&
                                            m.stock?.on_hand !== undefined
                                                ? `${m.stock.on_hand}${m.stock.unit ? ` ${m.stock.unit}` : ''}`
                                                : '—'}
                                        </div>
                                    </div>
                                    {m.stock?.reorder_level !== null &&
                                        m.stock?.reorder_level !== undefined && (
                                            <div className="mt-1 text-xs text-slate-500">
                                                Reorder at: {m.stock.reorder_level}
                                            </div>
                                        )}
                                    {m.stock?.notes && (
                                        <div className="mt-2 text-xs whitespace-pre-wrap text-slate-600">
                                            {m.stock.notes}
                                        </div>
                                    )}
                                </div>
                            ))}
                            {!medications.length && (
                                <div className="text-sm text-slate-500">
                                    No medications listed.
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
