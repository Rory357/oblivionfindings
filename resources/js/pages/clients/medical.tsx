import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { DiscontinueDialog } from '@/pages/emar/_dialogs';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

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
    can_controlled_view: boolean;
    can_controlled_record: boolean;
    can_controlled_witness: boolean;
    witnesses: Array<any>;
    controlled_entries: Array<any>;
    controlled_discrepancies: Array<any>;
    med_charts?: Array<any>;
    has_open_controlled_discrepancy?: boolean;
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
    can_controlled_view,
    can_controlled_record,
    can_controlled_witness,
    witnesses,
    controlled_entries,
    controlled_discrepancies,
    med_charts = [],
    has_open_controlled_discrepancy = false,
}: Props) {
    const { labels, auth } = usePage().props as any;
    const canDiscontinue = Boolean(
        auth?.can?.medications?.view && auth?.can?.medications?.ordersManage,
    );
    const name = `${client.first_name} ${client.last_name}`.trim();
    const [confirmAdminOpen, setConfirmAdminOpen] = useState(false);
    const [medicationToDiscontinue, setMedicationToDiscontinue] = useState<{
        id: number;
        name: string;
    } | null>(null);

    // When navigating from the client profile "Manage" buttons, we pass a section.
    // This keeps the medical workflow focused instead of showing every create form at once.
    const sectionParam =
        typeof window !== 'undefined'
            ? new URLSearchParams(window.location.search).get('section')
            : null;
    const [focusSection, setFocusSection] = useState<string>(
        sectionParam ?? 'all',
    );

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
        dose_times: '' as any,
        is_prn: false,
        controlled_drug: false,
        prn_reason: '',
        max_per_day: '',
        route: '',
        form: '',
        prescriber: '',
        pharmacy: '',
        state: 'active',
        ceased_at: '',
        ceased_reason: '',
        start_date: '',
        end_date: '',
        instructions: '',
        active: true,
    });

    const administrationForm = useForm({
        medication_id: medications?.[0]?.id ?? '',
        status: 'given',
        reason: '',
        dose_given: '',
        administered_at: new Date().toISOString().slice(0, 16),
        scheduled_for: '',
        shift_id: '',
        witnessed_by: '',
        notes: '',
    });

    const selectedMedication = medications.find(
        (m) => `${m.id}` === `${administrationForm.data.medication_id}`,
    );
    const administrationNeedsReason =
        administrationForm.data.status !== 'given' ||
        !!selectedMedication?.is_prn;

    const stockForm = useForm({
        medication_id: medications?.[0]?.id ?? '',
        on_hand: '',
        unit: '',
        reorder_level: '',
        last_counted_at: '',
        notes: '',
        reason: '',
        witnessed_by: '',
        immediate_action_taken: '',
    });

    const [closeDiscOpen, setCloseDiscOpen] = useState(false);
    const [selectedDiscId, setSelectedDiscId] = useState<number | null>(null);
    const closeDiscForm = useForm({
        resolution_notes: '',
    });

    const selectedStockMedication = medications.find(
        (m) => `${m.id}` === `${stockForm.data.medication_id}`,
    );
    const stockDiscrepancyWillBeRaised = Boolean(
        selectedStockMedication?.controlled_drug &&
        selectedStockMedication?.stock?.on_hand !== null &&
        selectedStockMedication?.stock?.on_hand !== undefined &&
        stockForm.data.on_hand !== '' &&
        Number(stockForm.data.on_hand) !==
            Number(selectedStockMedication.stock.on_hand),
    );
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

    const submitAdministration = () => {
        administrationForm.post(
            `/clients/${client.id}/medical/medications/${administrationForm.data.medication_id}/administrations`,
            {
                preserveScroll: true,
                onSuccess: () => {
                    administrationForm.reset(
                        'dose_given',
                        'scheduled_for',
                        'notes',
                        'reason',
                    );
                    administrationForm.reset('witnessed_by');
                    setConfirmAdminOpen(false);
                },
            },
        );
    };

    return (
        <AppLayout
            breadcrumbs={[
                {
                    title: labels?.['client.plural'] ?? 'Clients',
                    href: '/clients',
                },
                { title: name, href: `/clients/${client.id}` },
                { title: 'Medical', href: `/clients/${client.id}/medical` },
            ]}
        >
            <Head title={`Medical - ${name}`} />

            <div className="mb-4 space-y-3">
                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <div>
                        <div className="text-lg font-semibold">{name}</div>
                        <div className="text-xs text-muted-foreground">
                            Medication orders & medical profile
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button
                            variant="outline"
                            onClick={() =>
                                (window.location.href = `/emar/mar?client_id=${client.id}`)
                            }
                        >
                            eMAR Dashboard
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() =>
                                (window.location.href = `/clients/${client.id}/mar`)
                            }
                        >
                            Open Daily MAR
                        </Button>
                    </div>
                </div>

                {has_open_controlled_discrepancy && (
                    <div className="rounded-md border border-status-warning/30 bg-status-warning-bg p-3 text-sm text-status-warning">
                        There is an open controlled-drug discrepancy for this{' '}
                        {(
                            labels?.['client.singular'] ?? 'Client'
                        ).toLowerCase()}
                        . Review and resolve before further controlled stock
                        edits (unless override is granted).
                    </div>
                )}

                {med_charts.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Medication chart (source of truth)
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {med_charts.map((d: any) => (
                                <div
                                    key={d.id}
                                    className="flex items-center justify-between rounded-md border p-3"
                                >
                                    <div>
                                        <div className="text-sm font-medium">
                                            {d.title}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {d.version
                                                ? `v${d.version} • `
                                                : ''}
                                            {d.effective_date
                                                ? `Effective: ${new Date(d.effective_date).toLocaleDateString()}`
                                                : ''}
                                        </div>
                                    </div>
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            (window.location.href = `/clients/${client.id}/documents/${d.id}/download`)
                                        }
                                    >
                                        Download
                                    </Button>
                                </div>
                            ))}
                            <div className="text-xs text-muted-foreground">
                                To upload/update charts, use the Documents tab
                                (category: Medication chart).
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>

            <div className="mb-4 flex flex-wrap items-center gap-2">
                <Button
                    size="sm"
                    variant={focusSection === 'all' ? 'default' : 'outline'}
                    onClick={() => setFocusSection('all')}
                >
                    All
                </Button>
                <Button
                    size="sm"
                    variant={focusSection === 'profile' ? 'default' : 'outline'}
                    onClick={() => setFocusSection('profile')}
                >
                    Profile
                </Button>
                <Button
                    size="sm"
                    variant={
                        focusSection === 'medications' ? 'default' : 'outline'
                    }
                    onClick={() => setFocusSection('medications')}
                >
                    Medications
                </Button>
                <Button
                    size="sm"
                    variant={
                        focusSection === 'conditions' ? 'default' : 'outline'
                    }
                    onClick={() => setFocusSection('conditions')}
                >
                    Conditions
                </Button>
                <Button
                    size="sm"
                    variant={
                        focusSection === 'emergency_contacts'
                            ? 'default'
                            : 'outline'
                    }
                    onClick={() => setFocusSection('emergency_contacts')}
                >
                    Emergency contacts
                </Button>
                {focusSection !== 'all' && (
                    <div className="text-xs text-muted-foreground">
                        Tip: use “All” to see the full medical dashboard.
                    </div>
                )}
            </div>

            <div className="grid auto-rows-fr grid-cols-2 gap-4 md:grid-cols-2">
                <Card
                    className={cn(
                        focusSection !== 'all' &&
                            focusSection !== 'profile' &&
                            'hidden',
                    )}
                >
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

                <Card
                    className={cn(
                        focusSection !== 'all' &&
                            focusSection !== 'medications' &&
                            'hidden',
                    )}
                >
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
                                        <Label className="!mt-0">
                                            PRN (as needed)
                                        </Label>
                                    </div>
                                    <div className="flex items-center gap-2 pt-6">
                                        <Checkbox
                                            checked={
                                                !!medForm.data.controlled_drug
                                            }
                                            onCheckedChange={(v) =>
                                                medForm.setData(
                                                    'controlled_drug',
                                                    !!v,
                                                )
                                            }
                                        />
                                        <Label className="!mt-0">
                                            Controlled drug (double-sign
                                            required)
                                        </Label>
                                    </div>
                                    {medForm.data.is_prn && (
                                        <>
                                            <div>
                                                <Label>PRN reason</Label>
                                                <Input
                                                    value={
                                                        medForm.data.prn_reason
                                                    }
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
                                                    value={
                                                        medForm.data.max_per_day
                                                    }
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
                                        <Label>Pharmacy</Label>
                                        <Input
                                            value={medForm.data.pharmacy}
                                            onChange={(e) =>
                                                medForm.setData(
                                                    'pharmacy',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div>
                                        <Label>
                                            Form (tablet/liquid/patch)
                                        </Label>
                                        <Input
                                            value={medForm.data.form}
                                            onChange={(e) =>
                                                medForm.setData(
                                                    'form',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div>
                                        <Label>
                                            Dose times (HH:MM, comma separated)
                                        </Label>
                                        <Input
                                            value={
                                                medForm.data.dose_times as any
                                            }
                                            onChange={(e) =>
                                                medForm.setData(
                                                    'dose_times',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="e.g. 08:00, 12:00, 18:00"
                                        />
                                    </div>
                                    <div>
                                        <Label>State</Label>
                                        <Select
                                            value={medForm.data.state}
                                            onValueChange={(v) =>
                                                medForm.setData('state', v)
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select state" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="active">
                                                    Active
                                                </SelectItem>
                                                <SelectItem value="paused">
                                                    Paused
                                                </SelectItem>
                                                <SelectItem value="ceased">
                                                    Ceased
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    {medForm.data.state === 'ceased' && (
                                        <>
                                            <div>
                                                <Label>Ceased date</Label>
                                                <Input
                                                    type="date"
                                                    value={
                                                        medForm.data.ceased_at
                                                    }
                                                    onChange={(e) =>
                                                        medForm.setData(
                                                            'ceased_at',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </div>
                                            <div>
                                                <Label>Ceased reason</Label>
                                                <Input
                                                    value={
                                                        medForm.data
                                                            .ceased_reason
                                                    }
                                                    onChange={(e) =>
                                                        medForm.setData(
                                                            'ceased_reason',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </div>
                                        </>
                                    )}
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
                                        onClick={() => {
                                            // Inertia's useForm().transform() does not always support chaining in all versions.
                                            // Normalize dose_times without relying on chained calls.
                                            const dt =
                                                typeof (medForm.data as any)
                                                    .dose_times === 'string'
                                                    ? (
                                                          medForm.data as any
                                                      ).dose_times
                                                          .split(',')
                                                          .map((s: string) =>
                                                              s.trim(),
                                                          )
                                                          .filter(Boolean)
                                                    : (medForm.data as any)
                                                          .dose_times;
                                            medForm.setData(
                                                'dose_times',
                                                dt as any,
                                            );
                                            medForm.post(
                                                `/clients/${client.id}/medical/medications`,
                                                {
                                                    preserveScroll: true,
                                                    onSuccess: () =>
                                                        medForm.reset(),
                                                },
                                            );
                                        }}
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
                                        <div className="flex items-center gap-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    (window.location.href = `/emar/mar?client_id=${client.id}`)
                                                }
                                            >
                                                View in eMAR
                                            </Button>
                                            {canDiscontinue &&
                                                m.state !== 'ceased' &&
                                                !m.ceased_at && (
                                                    <Button
                                                        variant="destructive"
                                                        onClick={() =>
                                                            setMedicationToDiscontinue(
                                                                {
                                                                    id: m.id,
                                                                    name: m.name,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        Discontinue
                                                    </Button>
                                                )}
                                        </div>
                                    </div>
                                    <div className="mt-1 text-xs text-muted-foreground">
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
                                        <div className="mt-2 text-xs whitespace-pre-wrap text-muted-foreground">
                                            {m.instructions}
                                        </div>
                                    )}
                                </div>
                            ))}
                            {!medications.length && (
                                <div className="text-sm text-muted-foreground">
                                    No medications listed.
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>

                <Card
                    className={cn(
                        focusSection !== 'all' &&
                            focusSection !== 'conditions' &&
                            'hidden',
                    )}
                >
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
                                                <span className="ml-2 text-xs text-muted-foreground">
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
                                        <div className="mt-2 text-xs whitespace-pre-wrap text-muted-foreground">
                                            {c.notes}
                                        </div>
                                    )}
                                </div>
                            ))}
                            {!conditions.length && (
                                <div className="text-sm text-muted-foreground">
                                    No conditions listed.
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>

                <Card
                    className={cn(
                        focusSection !== 'all' &&
                            focusSection !== 'emergency_contacts' &&
                            'hidden',
                    )}
                >
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
                                    <div className="mt-1 text-xs text-muted-foreground">
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
                                        <div className="mt-2 text-xs whitespace-pre-wrap text-muted-foreground">
                                            {e.notes}
                                        </div>
                                    )}
                                </div>
                            ))}
                            {!emergency_contacts.length && (
                                <div className="text-sm text-muted-foreground">
                                    No emergency contacts listed.
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>
            </div>

            {/* MAR + Stock */}
            <div
                className={cn(
                    'grid gap-4 md:grid-cols-2',
                    focusSection !== 'all' &&
                        focusSection !== 'medications' &&
                        'hidden',
                )}
            >
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
                                            value={
                                                administrationForm.data.status
                                            }
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
                                                <SelectItem value="withheld">
                                                    Withheld
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    {administrationNeedsReason && (
                                        <div className="md:col-span-2">
                                            <Label>
                                                {selectedMedication?.is_prn
                                                    ? 'Indication (required for PRN)'
                                                    : 'Reason (required)'}
                                            </Label>
                                            <Input
                                                value={
                                                    administrationForm.data
                                                        .reason
                                                }
                                                onChange={(e) =>
                                                    administrationForm.setData(
                                                        'reason',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder={
                                                    selectedMedication?.is_prn
                                                        ? 'e.g. headache, anxiety, pain'
                                                        : 'e.g. client refused, clinical hold, unavailable'
                                                }
                                            />
                                            {administrationForm.errors
                                                .reason && (
                                                <div className="mt-1 text-xs text-status-critical">
                                                    {
                                                        administrationForm
                                                            .errors.reason
                                                    }
                                                </div>
                                            )}
                                        </div>
                                    )}

                                    {selectedMedication?.controlled_drug &&
                                        administrationForm.data.status ===
                                            'given' && (
                                            <div className="md:col-span-2">
                                                <Label>
                                                    Witness (required)
                                                </Label>
                                                <Select
                                                    value={`${administrationForm.data.witnessed_by}`}
                                                    onValueChange={(v) =>
                                                        administrationForm.setData(
                                                            'witnessed_by',
                                                            v,
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue placeholder="Select witness" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {witnesses.map(
                                                            (w: any) => (
                                                                <SelectItem
                                                                    key={w.id}
                                                                    value={`${w.id}`}
                                                                >
                                                                    {w.name}
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectContent>
                                                </Select>
                                                {administrationForm.errors
                                                    .witnessed_by && (
                                                    <div className="mt-1 text-xs text-status-critical">
                                                        {
                                                            administrationForm
                                                                .errors
                                                                .witnessed_by
                                                        }
                                                    </div>
                                                )}
                                            </div>
                                        )}

                                    <div>
                                        <Label>Dose given</Label>
                                        <Input
                                            value={
                                                administrationForm.data
                                                    .dose_given
                                            }
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
                                            value={
                                                administrationForm.data
                                                    .administered_at
                                            }
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
                                            value={
                                                administrationForm.data
                                                    .scheduled_for
                                            }
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
                                            value={
                                                administrationForm.data.notes
                                            }
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
                                        onClick={() => {
                                            administrationForm.clearErrors();
                                            if (
                                                !administrationForm.data
                                                    .medication_id
                                            )
                                                return;
                                            if (
                                                administrationNeedsReason &&
                                                !administrationForm.data.reason
                                            ) {
                                                administrationForm.setError(
                                                    'reason',
                                                    'A reason/indication is required.',
                                                );
                                                return;
                                            }
                                            if (
                                                selectedMedication?.controlled_drug &&
                                                administrationForm.data
                                                    .status === 'given' &&
                                                !administrationForm.data
                                                    .witnessed_by
                                            ) {
                                                administrationForm.setError(
                                                    'witnessed_by',
                                                    'A witness is required for controlled drug administration.',
                                                );
                                                return;
                                            }
                                            setConfirmAdminOpen(true);
                                        }}
                                        disabled={
                                            administrationForm.processing ||
                                            !administrationForm.data
                                                .medication_id
                                        }
                                    >
                                        Save
                                    </Button>

                                    <Dialog
                                        open={confirmAdminOpen}
                                        onOpenChange={setConfirmAdminOpen}
                                    >
                                        <DialogContent>
                                            <DialogHeader>
                                                <DialogTitle>
                                                    Confirm medication
                                                    administration
                                                </DialogTitle>
                                            </DialogHeader>

                                            <div className="space-y-2 text-sm">
                                                <div>
                                                    <span className="font-medium">
                                                        Medication:
                                                    </span>{' '}
                                                    {selectedMedication?.name ||
                                                        'Medication'}
                                                </div>
                                                <div>
                                                    <span className="font-medium">
                                                        Outcome:
                                                    </span>{' '}
                                                    {
                                                        administrationForm.data
                                                            .status
                                                    }
                                                </div>
                                                {administrationForm.data
                                                    .reason && (
                                                    <div>
                                                        <span className="font-medium">
                                                            Reason:
                                                        </span>{' '}
                                                        {
                                                            administrationForm
                                                                .data.reason
                                                        }
                                                    </div>
                                                )}
                                                <div>
                                                    <span className="font-medium">
                                                        Administered at:
                                                    </span>{' '}
                                                    {
                                                        administrationForm.data
                                                            .administered_at
                                                    }
                                                </div>
                                            </div>

                                            <DialogFooter>
                                                <Button
                                                    variant="outline"
                                                    onClick={() =>
                                                        setConfirmAdminOpen(
                                                            false,
                                                        )
                                                    }
                                                >
                                                    Cancel
                                                </Button>
                                                <Button
                                                    onClick={
                                                        submitAdministration
                                                    }
                                                    disabled={
                                                        administrationForm.processing
                                                    }
                                                >
                                                    Confirm
                                                </Button>
                                            </DialogFooter>
                                        </DialogContent>
                                    </Dialog>
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
                                        <div className="text-xs text-muted-foreground">
                                            {a.status}
                                        </div>
                                    </div>
                                    <div className="mt-1 text-xs text-muted-foreground">
                                        {a.administered_at
                                            ? `Administered: ${new Date(a.administered_at).toLocaleString()}`
                                            : ''}
                                        {a.administeredBy?.name
                                            ? ` • By: ${a.administeredBy.name}`
                                            : ''}
                                        {a.dose_given
                                            ? ` • Dose: ${a.dose_given}`
                                            : ''}
                                        {a.late_minutes && a.late_minutes > 0
                                            ? ` • Late: ${a.late_minutes} min`
                                            : ''}
                                        {a.serviceContext?.name
                                            ? ` • Context: ${a.serviceContext.name}`
                                            : ''}
                                    </div>
                                    {a.reason && a.status !== 'given' && (
                                        <div className="mt-2 text-xs text-muted-foreground">
                                            Reason: {a.reason}
                                        </div>
                                    )}
                                    {a.notes && (
                                        <div className="mt-2 text-xs whitespace-pre-wrap text-muted-foreground">
                                            {a.notes}
                                        </div>
                                    )}
                                </div>
                            ))}
                            {!administrations.length && (
                                <div className="text-sm text-muted-foreground">
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

                                    {selectedStockMedication?.controlled_drug && (
                                        <>
                                            <div className="md:col-span-2">
                                                <Label>
                                                    Reason (required for
                                                    controlled drug stock)
                                                </Label>
                                                <Input
                                                    value={
                                                        stockForm.data.reason
                                                    }
                                                    onChange={(e) =>
                                                        stockForm.setData(
                                                            'reason',
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="e.g. stock count, discrepancy investigation"
                                                />
                                                {stockForm.errors.reason && (
                                                    <div className="mt-1 text-xs text-status-critical">
                                                        {
                                                            stockForm.errors
                                                                .reason
                                                        }
                                                    </div>
                                                )}
                                            </div>
                                            <div className="md:col-span-2">
                                                <Label>
                                                    Witness (required)
                                                </Label>
                                                <Select
                                                    value={`${stockForm.data.witnessed_by}`}
                                                    onValueChange={(v) =>
                                                        stockForm.setData(
                                                            'witnessed_by',
                                                            v,
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue placeholder="Select witness" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {witnesses.map(
                                                            (w: any) => (
                                                                <SelectItem
                                                                    key={w.id}
                                                                    value={`${w.id}`}
                                                                >
                                                                    {w.name}
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectContent>
                                                </Select>
                                                {stockForm.errors
                                                    .witnessed_by && (
                                                    <div className="mt-1 text-xs text-status-critical">
                                                        {
                                                            stockForm.errors
                                                                .witnessed_by
                                                        }
                                                    </div>
                                                )}
                                            </div>
                                        </>
                                    )}
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
                                            value={
                                                stockForm.data.last_counted_at
                                            }
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
                                    {stockDiscrepancyWillBeRaised && (
                                        <div className="md:col-span-2">
                                            <Label>
                                                Immediate action actually taken
                                                (required)
                                            </Label>
                                            <Textarea
                                                value={
                                                    stockForm.data
                                                        .immediate_action_taken
                                                }
                                                onChange={(e) =>
                                                    stockForm.setData(
                                                        'immediate_action_taken',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="What was done immediately to protect the client and secure or reconcile the stock?"
                                            />
                                            {stockForm.errors
                                                .immediate_action_taken && (
                                                <div className="mt-1 text-xs text-status-critical">
                                                    {
                                                        stockForm.errors
                                                            .immediate_action_taken
                                                    }
                                                </div>
                                            )}
                                        </div>
                                    )}
                                </div>
                                <div className="mt-3">
                                    <Button
                                        onClick={() => {
                                            stockForm.clearErrors();
                                            if (!stockForm.data.medication_id)
                                                return;
                                            if (
                                                selectedStockMedication?.controlled_drug
                                            ) {
                                                if (!stockForm.data.reason) {
                                                    stockForm.setError(
                                                        'reason',
                                                        'Reason is required for controlled drug stock updates.',
                                                    );
                                                    return;
                                                }
                                                if (
                                                    !stockForm.data.witnessed_by
                                                ) {
                                                    stockForm.setError(
                                                        'witnessed_by',
                                                        'Witness is required for controlled drug stock updates.',
                                                    );
                                                    return;
                                                }
                                                if (
                                                    stockDiscrepancyWillBeRaised &&
                                                    !stockForm.data.immediate_action_taken.trim()
                                                ) {
                                                    stockForm.setError(
                                                        'immediate_action_taken',
                                                        'Record the immediate action actually taken for this discrepancy.',
                                                    );
                                                    return;
                                                }
                                            }
                                            stockForm.put(
                                                `/clients/${client.id}/medical/medications/${stockForm.data.medication_id}/stock`,
                                                { preserveScroll: true },
                                            );
                                        }}
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
                                        <div className="text-xs text-muted-foreground">
                                            {m.stock?.on_hand !== null &&
                                            m.stock?.on_hand !== undefined
                                                ? `${m.stock.on_hand}${m.stock.unit ? ` ${m.stock.unit}` : ''}`
                                                : '—'}
                                        </div>
                                    </div>
                                    {m.stock?.reorder_level !== null &&
                                        m.stock?.reorder_level !==
                                            undefined && (
                                            <div className="mt-1 text-xs text-muted-foreground">
                                                Reorder at:{' '}
                                                {m.stock.reorder_level}
                                            </div>
                                        )}
                                    {m.stock?.notes && (
                                        <div className="mt-2 text-xs whitespace-pre-wrap text-muted-foreground">
                                            {m.stock.notes}
                                        </div>
                                    )}
                                </div>
                            ))}
                            {!medications.length && (
                                <div className="text-sm text-muted-foreground">
                                    No medications listed.
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>
            </div>

            {can_controlled_view && (
                <div className="mt-4">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Controlled drug register
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {controlled_entries.map((e: any) => (
                                <div
                                    key={e.id}
                                    className="rounded-md border p-3"
                                >
                                    <div className="flex items-center justify-between gap-3">
                                        <div className="text-sm font-medium">
                                            {e.medication?.name || 'Medication'}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {e.entry_type}
                                            {e.recorded_at
                                                ? ` • ${new Date(e.recorded_at).toLocaleString()}`
                                                : ''}
                                        </div>
                                    </div>
                                    <div className="mt-1 text-xs text-muted-foreground">
                                        {e.recordedBy?.name
                                            ? `By: ${e.recordedBy.name}`
                                            : ''}
                                        {e.witnessedBy?.name
                                            ? ` • Witness: ${e.witnessedBy.name}`
                                            : ''}
                                        {e.serviceContext?.name
                                            ? ` • Context: ${e.serviceContext.name}`
                                            : ''}
                                    </div>
                                    {(e.on_hand_before !== null ||
                                        e.on_hand_after !== null) && (
                                        <div className="mt-1 text-xs text-muted-foreground">
                                            Stock: {e.on_hand_before ?? '—'} →{' '}
                                            {e.on_hand_after ?? '—'}
                                            {e.unit ? ` ${e.unit}` : ''}
                                        </div>
                                    )}
                                    {e.reason && (
                                        <div className="mt-2 text-xs text-muted-foreground">
                                            Reason: {e.reason}
                                        </div>
                                    )}
                                    {e.notes && (
                                        <div className="mt-2 text-xs whitespace-pre-wrap text-muted-foreground">
                                            {e.notes}
                                        </div>
                                    )}
                                </div>
                            ))}
                            {!controlled_entries.length && (
                                <div className="text-sm text-muted-foreground">
                                    No controlled drug entries recorded yet.
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            )}

            {can_controlled_view && (
                <div className="mt-4">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Controlled drug discrepancies
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {controlled_discrepancies.map((d: any) => (
                                <div
                                    key={d.id}
                                    className="rounded-md border p-3"
                                >
                                    <div className="flex items-center justify-between gap-3">
                                        <div className="text-sm font-medium">
                                            {d.medication?.name || 'Medication'}
                                        </div>
                                        <div
                                            className={`text-xs ${d.status === 'open' ? 'text-status-warning' : 'text-muted-foreground'}`}
                                        >
                                            {d.status}
                                            {d.reported_at
                                                ? ` • ${new Date(d.reported_at).toLocaleString()}`
                                                : ''}
                                        </div>
                                    </div>
                                    <div className="mt-1 text-xs text-muted-foreground">
                                        {d.reportedBy?.name
                                            ? `Reported by: ${d.reportedBy.name}`
                                            : ''}
                                        {d.witnessedBy?.name
                                            ? ` • Witness: ${d.witnessedBy.name}`
                                            : ''}
                                        {d.serviceContext?.name
                                            ? ` • Context: ${d.serviceContext.name}`
                                            : ''}
                                    </div>
                                    <div className="mt-2 text-xs text-muted-foreground">
                                        Stock: {d.on_hand_before ?? '—'} →{' '}
                                        {d.on_hand_after ?? '—'}
                                        {d.difference !== null &&
                                        d.difference !== undefined
                                            ? ` • Difference: ${d.difference}`
                                            : ''}
                                    </div>
                                    {d.reason && (
                                        <div className="mt-2 text-xs text-muted-foreground">
                                            Reason: {d.reason}
                                        </div>
                                    )}
                                    {d.immediate_action_taken && (
                                        <div className="mt-2 text-xs text-muted-foreground">
                                            Immediate action:{' '}
                                            {d.immediate_action_taken}
                                        </div>
                                    )}
                                    {d.status === 'closed' &&
                                        (d.resolution_notes ||
                                            d.resolvedBy?.name) && (
                                            <div className="mt-2 text-xs text-muted-foreground">
                                                Closed
                                                {d.resolvedBy?.name
                                                    ? ` by ${d.resolvedBy.name}`
                                                    : ''}
                                                {d.resolution_notes
                                                    ? ` • ${d.resolution_notes}`
                                                    : ''}
                                            </div>
                                        )}

                                    {d.status === 'open' &&
                                        can_controlled_record && (
                                            <div className="mt-3 flex justify-end">
                                                <Button
                                                    variant="outline"
                                                    onClick={() => {
                                                        setSelectedDiscId(d.id);
                                                        closeDiscForm.reset(
                                                            'resolution_notes',
                                                        );
                                                        setCloseDiscOpen(true);
                                                    }}
                                                >
                                                    Close discrepancy
                                                </Button>
                                            </div>
                                        )}
                                </div>
                            ))}

                            {!controlled_discrepancies.length && (
                                <div className="text-sm text-muted-foreground">
                                    No controlled drug discrepancies.
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            )}

            {medicationToDiscontinue && (
                <DiscontinueDialog
                    medication={medicationToDiscontinue}
                    action={`/clients/${client.id}/medical/medications/${medicationToDiscontinue.id}/discontinue`}
                    onClose={() => setMedicationToDiscontinue(null)}
                />
            )}

            <Dialog open={closeDiscOpen} onOpenChange={setCloseDiscOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Close discrepancy</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-2">
                        <Label>Resolution notes (optional)</Label>
                        <Textarea
                            value={closeDiscForm.data.resolution_notes}
                            onChange={(e) =>
                                closeDiscForm.setData(
                                    'resolution_notes',
                                    e.target.value,
                                )
                            }
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setCloseDiscOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={() => {
                                if (!selectedDiscId) return;
                                closeDiscForm.post(
                                    `/clients/${client.id}/medical/controlled-discrepancies/${selectedDiscId}/close`,
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            setCloseDiscOpen(false);
                                            setSelectedDiscId(null);
                                        },
                                    },
                                );
                            }}
                            disabled={closeDiscForm.processing}
                        >
                            Close
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
