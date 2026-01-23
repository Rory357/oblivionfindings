import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';

type Props = {
    client: { id: number; first_name: string; last_name: string };
    can_edit: boolean;
    profile: any | null;
    medications: Array<any>;
    conditions: Array<any>;
    emergency_contacts: Array<any>;
};

export default function ClientMedical({
    client,
    can_edit,
    profile,
    medications,
    conditions,
    emergency_contacts,
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
        route: '',
        prescriber: '',
        start_date: '',
        end_date: '',
        instructions: '',
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
        </AppLayout>
    );
}
