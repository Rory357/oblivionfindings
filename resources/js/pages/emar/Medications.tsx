import DrugInteractionManager from '@/components/medications/DrugInteractionManager';
import MedicationVersionHistory from '@/components/medications/MedicationVersionHistory';
import FleetHero from '@/components/fleet-hero';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
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
import { Textarea } from '@/components/ui/textarea';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';
import axios from 'axios';
import { AlertTriangle, Ban, Clock, FileUp, Pencil, Pill, Plus } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';

type Props = {
    medications: { data: any[]; links: any };
    clients: { id: number; first_name: string; last_name: string }[];
    staff: { id: number; name: string }[];
    filters: {
        search?: string;
        status?: string;
        type?: string;
        client_id?: string;
    };
    interactionMap: Record<number, string>;
    selectedClient: {
        id: number;
        first_name: string;
        last_name: string;
    } | null;
    clientContext: {
        profile: {
            medical_history?: string | null;
            mental_health_history?: string | null;
            surgical_history?: string | null;
            gp_name?: string | null;
            gp_practice?: string | null;
            gp_phone?: string | null;
            hospital_preference?: string | null;
            blood_type?: string | null;
            organ_donor?: boolean;
            immunisation_notes?: string | null;
            disabilities?: string[];
            allergies?: string[];
            notes?: string | null;
        } | null;
        conditions: Array<{
            id: number;
            label: string;
            severity?: string | null;
            notes?: string | null;
        }>;
        emergency_contacts: Array<{
            id: number;
            name: string;
            relationship?: string | null;
            phone?: string | null;
            email?: string | null;
            notes?: string | null;
            preferred_method?: string | null;
            availability?: string | null;
            authorised_health_info?: boolean;
        }>;
        medication_charts: Array<{
            id: number;
            title?: string | null;
            original_name?: string | null;
            version?: string | null;
            effective_date?: string | null;
            expiry_date?: string | null;
            notes?: string | null;
            mime_type?: string | null;
            uploaded_at?: string | null;
            uploaded_by?: string | null;
            download_url: string;
        }>;
    } | null;
    can: {
        manage_allergies: boolean;
        manage_interactions: boolean;
    };
};

type MedicationAllergy = {
    id: number;
    allergen: string;
    reaction?: string | null;
    severity?: string | null;
    is_severe?: boolean;
    notes?: string | null;
    identified_date?: string | null;
    recorded_by?: string | null;
};

const doseUnits = [
    'mg',
    'mcg',
    'g',
    'ml',
    'units',
    'tablets',
    'capsules',
    'drops',
    'puffs',
];
const frequencies = [
    'Once daily',
    'Twice daily',
    'Three times daily',
    'Four times daily',
    'Every 4 hours',
    'Every 6 hours',
    'Every 8 hours',
    'Every 12 hours',
    'Weekly',
    'Fortnightly',
    'Monthly',
    'PRN',
    'Stat',
];
const routes = [
    'oral',
    'sublingual',
    'topical',
    'transdermal',
    'inhaled',
    'nebulised',
    'subcutaneous',
    'intramuscular',
    'intravenous',
    'rectal',
    'vaginal',
    'optic',
    'otic',
    'nasal',
];
const forms = [
    'tablet',
    'capsule',
    'liquid',
    'cream',
    'ointment',
    'gel',
    'patch',
    'inhaler',
    'injection',
    'suppository',
    'drops',
    'spray',
    'powder',
];

/** Maps frequency values to their calculated dose times (mirrors PHP DoseSchedulingService). */
function calculateDoseTimes(frequency: string): string[] {
    const normalised = frequency.toLowerCase().replace(/[\s\-_]/g, '');
    const map: Record<string, string[]> = {
        oncedaily: ['08:00'],
        daily: ['08:00'],
        od: ['08:00'],
        twicedaily: ['08:00', '20:00'],
        bd: ['08:00', '20:00'],
        bid: ['08:00', '20:00'],
        threetimesdaily: ['08:00', '14:00', '20:00'],
        tds: ['08:00', '14:00', '20:00'],
        tid: ['08:00', '14:00', '20:00'],
        fourtimesdaily: ['08:00', '12:00', '18:00', '22:00'],
        qds: ['08:00', '12:00', '18:00', '22:00'],
        qid: ['08:00', '12:00', '18:00', '22:00'],
        every4hours: ['06:00', '10:00', '14:00', '18:00', '22:00'],
        q4h: ['06:00', '10:00', '14:00', '18:00', '22:00'],
        every6hours: ['06:00', '12:00', '18:00', '00:00'],
        q6h: ['06:00', '12:00', '18:00', '00:00'],
        every8hours: ['06:00', '14:00', '22:00'],
        q8h: ['06:00', '14:00', '22:00'],
        every12hours: ['08:00', '20:00'],
        q12h: ['08:00', '20:00'],
        everymorning: ['08:00'],
        mane: ['08:00'],
        everynight: ['22:00'],
        nocte: ['22:00'],
        weekly: ['08:00'],
        fortnightly: ['08:00'],
        monthly: ['08:00'],
        prn: [],
        asneeded: [],
        whenrequired: [],
        stat: [],
    };
    return map[normalised] ?? ['08:00'];
}

function DoseTimesPreview({ frequency }: { frequency: string }) {
    const times = useMemo(
        () => (frequency ? calculateDoseTimes(frequency) : []),
        [frequency],
    );

    if (!frequency) return null;

    return (
        <div className="rounded-md border border-status-success/30 bg-status-success-bg p-3 dark:border-status-success/30 dark:bg-status-success">
            <div className="flex items-center gap-2 text-sm font-medium text-status-success dark:text-status-success">
                <Clock className="h-4 w-4" />
                Scheduled Dose Times
            </div>
            {times.length > 0 ? (
                <div className="mt-2 flex flex-wrap gap-2">
                    {times.map((t) => (
                        <span
                            key={t}
                            className="inline-flex items-center rounded-full bg-status-success-bg px-2.5 py-0.5 text-xs font-medium text-status-success dark:bg-status-success-bg dark:text-status-success"
                        >
                            {t}
                        </span>
                    ))}
                </div>
            ) : (
                <p className="mt-1 text-xs text-status-success dark:text-status-success">
                    No fixed schedule — administered as needed or one-off.
                </p>
            )}
        </div>
    );
}

function defaultFormData() {
    return {
        client_id: '',
        medication_name: '',
        brand_name: '',
        dose: '',
        dose_unit: '',
        frequency: '',
        route: '',
        form: '',
        instructions: '',
        indication: '',
        is_prn: false as boolean,
        prn_reason: '',
        max_per_day: '',
        min_hours_between_doses: '',
        controlled_drug: false as boolean,
        high_risk: false as boolean,
        witness_required: false as boolean,
        start_date: '',
        prescriber: '',
    };
}

function getEditableDose(med: any) {
    if (med.dose_amount !== null && med.dose_amount !== undefined) {
        return med.dose_amount.toString();
    }

    const dosage = med.dosage ?? '';
    const doseUnit = med.dose_unit ?? '';

    if (dosage && doseUnit) {
        const suffix = ` ${doseUnit}`;
        if (dosage.endsWith(suffix)) {
            return dosage.slice(0, -suffix.length);
        }
    }

    return dosage;
}

function MedicationFormFields({
    form,
    clients,
    idPrefix,
}: {
    form: ReturnType<typeof useForm<ReturnType<typeof defaultFormData>>>;
    clients: Props['clients'];
    idPrefix: string;
}) {
    return (
        <>
            <div className="grid grid-cols-2 gap-4">
                <div className="space-y-1.5">
                    <Label htmlFor={`${idPrefix}_client_id`}>Client *</Label>
                    <Select
                        value={form.data.client_id}
                        onValueChange={(v) => form.setData('client_id', v)}
                    >
                        <SelectTrigger id={`${idPrefix}_client_id`}>
                            <SelectValue placeholder="Select client" />
                        </SelectTrigger>
                        <SelectContent>
                            {clients.map((c) => (
                                <SelectItem key={c.id} value={c.id.toString()}>
                                    {c.last_name}, {c.first_name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {form.errors.client_id && (
                        <p className="text-xs text-status-critical">
                            {form.errors.client_id}
                        </p>
                    )}
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor={`${idPrefix}_medication_name`}>
                        Medication Name *
                    </Label>
                    <Input
                        id={`${idPrefix}_medication_name`}
                        value={form.data.medication_name}
                        onChange={(e) =>
                            form.setData('medication_name', e.target.value)
                        }
                    />
                    {form.errors.medication_name && (
                        <p className="text-xs text-status-critical">
                            {form.errors.medication_name}
                        </p>
                    )}
                </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div className="space-y-1.5">
                    <Label htmlFor={`${idPrefix}_brand_name`}>Brand Name</Label>
                    <Input
                        id={`${idPrefix}_brand_name`}
                        value={form.data.brand_name}
                        onChange={(e) =>
                            form.setData('brand_name', e.target.value)
                        }
                    />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor={`${idPrefix}_dose`}>Dose *</Label>
                    <Input
                        id={`${idPrefix}_dose`}
                        value={form.data.dose}
                        onChange={(e) => form.setData('dose', e.target.value)}
                    />
                    {form.errors.dose && (
                        <p className="text-xs text-status-critical">
                            {form.errors.dose}
                        </p>
                    )}
                </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div className="space-y-1.5">
                    <Label htmlFor={`${idPrefix}_dose_unit`}>Dose Unit *</Label>
                    <Select
                        value={form.data.dose_unit}
                        onValueChange={(v) => form.setData('dose_unit', v)}
                    >
                        <SelectTrigger id={`${idPrefix}_dose_unit`}>
                            <SelectValue placeholder="Select unit" />
                        </SelectTrigger>
                        <SelectContent>
                            {doseUnits.map((u) => (
                                <SelectItem key={u} value={u}>
                                    {u}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {form.errors.dose_unit && (
                        <p className="text-xs text-status-critical">
                            {form.errors.dose_unit}
                        </p>
                    )}
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor={`${idPrefix}_frequency`}>Frequency *</Label>
                    <Select
                        value={form.data.frequency}
                        onValueChange={(v) => form.setData('frequency', v)}
                    >
                        <SelectTrigger id={`${idPrefix}_frequency`}>
                            <SelectValue placeholder="Select frequency" />
                        </SelectTrigger>
                        <SelectContent>
                            {frequencies.map((f) => (
                                <SelectItem key={f} value={f}>
                                    {f}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {form.errors.frequency && (
                        <p className="text-xs text-status-critical">
                            {form.errors.frequency}
                        </p>
                    )}
                </div>
            </div>

            <DoseTimesPreview frequency={form.data.frequency} />

            <div className="grid grid-cols-2 gap-4">
                <div className="space-y-1.5">
                    <Label htmlFor={`${idPrefix}_route`}>Route *</Label>
                    <Select
                        value={form.data.route}
                        onValueChange={(v) => form.setData('route', v)}
                    >
                        <SelectTrigger id={`${idPrefix}_route`}>
                            <SelectValue placeholder="Select route" />
                        </SelectTrigger>
                        <SelectContent>
                            {routes.map((r) => (
                                <SelectItem key={r} value={r}>
                                    {r}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {form.errors.route && (
                        <p className="text-xs text-status-critical">
                            {form.errors.route}
                        </p>
                    )}
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor={`${idPrefix}_form`}>Form *</Label>
                    <Select
                        value={form.data.form}
                        onValueChange={(v) => form.setData('form', v)}
                    >
                        <SelectTrigger id={`${idPrefix}_form`}>
                            <SelectValue placeholder="Select form" />
                        </SelectTrigger>
                        <SelectContent>
                            {forms.map((f) => (
                                <SelectItem key={f} value={f}>
                                    {f}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {form.errors.form && (
                        <p className="text-xs text-status-critical">
                            {form.errors.form}
                        </p>
                    )}
                </div>
            </div>

            <div className="space-y-1.5">
                <Label htmlFor={`${idPrefix}_instructions`}>Instructions</Label>
                <Textarea
                    id={`${idPrefix}_instructions`}
                    rows={3}
                    value={form.data.instructions}
                    onChange={(e) =>
                        form.setData('instructions', e.target.value)
                    }
                />
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div className="space-y-1.5">
                    <Label htmlFor={`${idPrefix}_indication`}>Indication</Label>
                    <Input
                        id={`${idPrefix}_indication`}
                        value={form.data.indication}
                        onChange={(e) =>
                            form.setData('indication', e.target.value)
                        }
                    />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor={`${idPrefix}_prescriber`}>Prescriber</Label>
                    <Input
                        id={`${idPrefix}_prescriber`}
                        value={form.data.prescriber}
                        onChange={(e) =>
                            form.setData('prescriber', e.target.value)
                        }
                    />
                </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div className="space-y-1.5">
                    <Label htmlFor={`${idPrefix}_start_date`}>Start Date</Label>
                    <Input
                        id={`${idPrefix}_start_date`}
                        type="date"
                        value={form.data.start_date}
                        onChange={(e) =>
                            form.setData('start_date', e.target.value)
                        }
                    />
                    {form.errors.start_date && (
                        <p className="text-xs text-status-critical">
                            {form.errors.start_date}
                        </p>
                    )}
                </div>
            </div>

            {/* Checkbox flags */}
            <div className="grid grid-cols-2 gap-4">
                <div className="flex items-center space-x-2">
                    <Checkbox
                        id={`${idPrefix}_is_prn`}
                        checked={form.data.is_prn}
                        onCheckedChange={(v) =>
                            form.setData('is_prn', v === true)
                        }
                    />
                    <Label htmlFor={`${idPrefix}_is_prn`}>
                        PRN (as needed)
                    </Label>
                </div>
                <div className="flex items-center space-x-2">
                    <Checkbox
                        id={`${idPrefix}_controlled_drug`}
                        checked={form.data.controlled_drug}
                        onCheckedChange={(v) =>
                            form.setData('controlled_drug', v === true)
                        }
                    />
                    <Label htmlFor={`${idPrefix}_controlled_drug`}>
                        Controlled Drug
                    </Label>
                </div>
                <div className="flex items-center space-x-2">
                    <Checkbox
                        id={`${idPrefix}_high_risk`}
                        checked={form.data.high_risk}
                        onCheckedChange={(v) =>
                            form.setData('high_risk', v === true)
                        }
                    />
                    <Label htmlFor={`${idPrefix}_high_risk`}>High Risk</Label>
                </div>
                <div className="flex items-center space-x-2">
                    <Checkbox
                        id={`${idPrefix}_witness_required`}
                        checked={form.data.witness_required}
                        onCheckedChange={(v) =>
                            form.setData('witness_required', v === true)
                        }
                    />
                    <Label htmlFor={`${idPrefix}_witness_required`}>
                        Witness Required
                    </Label>
                </div>
            </div>

            {/* PRN fields - shown when is_prn is checked */}
            {form.data.is_prn && (
                <div className="rounded-md border border-status-info/30 bg-status-info-bg p-4 dark:border-status-info/30 dark:bg-status-info">
                    <p className="mb-3 text-sm font-medium text-status-info dark:text-status-info">
                        PRN Details
                    </p>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div className="space-y-1.5">
                            <Label htmlFor={`${idPrefix}_prn_reason`}>
                                PRN Reason
                            </Label>
                            <Input
                                id={`${idPrefix}_prn_reason`}
                                value={form.data.prn_reason}
                                onChange={(e) =>
                                    form.setData('prn_reason', e.target.value)
                                }
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor={`${idPrefix}_max_doses`}>
                                Max Doses / Day
                            </Label>
                            <Input
                                id={`${idPrefix}_max_doses`}
                                type="number"
                                value={form.data.max_per_day}
                                onChange={(e) =>
                                    form.setData('max_per_day', e.target.value)
                                }
                            />
                            {form.errors.max_per_day && (
                                <p className="text-xs text-status-critical">
                                    {form.errors.max_per_day}
                                </p>
                            )}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor={`${idPrefix}_min_hours`}>
                                Min Hours Between
                            </Label>
                            <Input
                                id={`${idPrefix}_min_hours`}
                                type="number"
                                value={form.data.min_hours_between_doses}
                                onChange={(e) =>
                                    form.setData(
                                        'min_hours_between_doses',
                                        e.target.value,
                                    )
                                }
                            />
                            {form.errors.min_hours_between_doses && (
                                <p className="text-xs text-status-critical">
                                    {form.errors.min_hours_between_doses}
                                </p>
                            )}
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}

function AddMedicationDialog({ clients }: { clients: Props['clients'] }) {
    const [open, setOpen] = useState(false);
    const form = useForm(defaultFormData());

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.post('/emar/medications', {
            onSuccess: () => {
                setOpen(false);
                form.reset();
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm">
                    <Plus className="mr-1 h-4 w-4" /> Add Medication
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>Add Medication</DialogTitle>
                    <DialogDescription>
                        Create a medication profile with scheduling, safety
                        flags, and administration details.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <MedicationFormFields
                        form={form}
                        clients={clients}
                        idPrefix="add"
                    />
                    <div className="flex justify-end gap-2 pt-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? 'Saving...' : 'Add Medication'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function EditMedicationDialog({
    med,
    clients,
}: {
    med: any;
    clients: Props['clients'];
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        client_id: med.client_id?.toString() ?? '',
        medication_name: med.name ?? '',
        brand_name: med.brand_name ?? '',
        dose: getEditableDose(med),
        dose_unit: med.dose_unit ?? '',
        frequency: med.frequency ?? '',
        route: med.route ?? '',
        form: med.form ?? '',
        instructions: med.instructions ?? '',
        indication: med.indication ?? '',
        is_prn: !!med.is_prn,
        prn_reason: med.prn_reason ?? '',
        max_per_day: med.max_per_day?.toString() ?? '',
        min_hours_between_doses: med.min_hours_between_doses?.toString() ?? '',
        controlled_drug: !!med.controlled_drug,
        high_risk: !!med.high_risk,
        witness_required: !!med.witness_required,
        start_date: med.start_date ?? '',
        prescriber: med.prescriber ?? '',
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.put(`/emar/medications/${med.id}`, {
            onSuccess: () => {
                setOpen(false);
                form.reset();
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant="ghost" className="h-7 w-7 p-0">
                    <Pencil className="h-3.5 w-3.5" />
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>Edit Medication</DialogTitle>
                    <DialogDescription>
                        Update the medication schedule, dosage, or safety
                        requirements for this client.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <MedicationFormFields
                        form={form}
                        clients={clients}
                        idPrefix={`edit_${med.id}`}
                    />
                    <div className="flex justify-end gap-2 pt-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing
                                ? 'Saving...'
                                : 'Update Medication'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function ImportCsvDialog() {
    const [open, setOpen] = useState(false);
    const [uploading, setUploading] = useState(false);
    const fileRef = useRef<HTMLInputElement>(null);

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        const file = fileRef.current?.files?.[0];
        if (!file) return;

        setUploading(true);
        const formData = new FormData();
        formData.append('csv_file', file);

        router.post('/emar/medications/import', formData, {
            forceFormData: true,
            onFinish: () => {
                setUploading(false);
                setOpen(false);
                if (fileRef.current) fileRef.current.value = '';
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant="outline">
                    <FileUp className="mr-1 h-4 w-4" /> Import CSV
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Import Medications from CSV</DialogTitle>
                    <DialogDescription>
                        Upload a CSV of medication rows using the documented
                        client and dose format.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="rounded-md border border-status-info/30 bg-status-info-bg p-3 text-sm dark:border-status-info/30 dark:bg-status-info">
                        <p className="font-medium text-status-info dark:text-status-info">
                            CSV Format
                        </p>
                        <p className="mt-1 text-xs text-status-info dark:text-status-info">
                            client_name, medication_name, dose, frequency, route
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Client name should match &quot;Last, First&quot; or
                            &quot;First Last&quot; format. First row can be a
                            header (it will be skipped if it contains
                            &quot;client_name&quot;).
                        </p>
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="csv_file">CSV File</Label>
                        <Input
                            id="csv_file"
                            ref={fileRef}
                            type="file"
                            accept=".csv"
                        />
                    </div>
                    <div className="flex justify-end gap-2 pt-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={uploading}>
                            {uploading ? 'Importing...' : 'Import'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function AddAllergyDialog({
    clientId,
    onCreated,
}: {
    clientId: number;
    onCreated: (allergy: MedicationAllergy) => void;
}) {
    const [open, setOpen] = useState(false);
    const [saving, setSaving] = useState(false);
    const [form, setForm] = useState({
        allergen: '',
        reaction: '',
        severity: 'moderate',
        notes: '',
        identified_date: '',
    });

    async function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        setSaving(true);

        try {
            const response = await axios.post(
                `/api/medications/clients/${clientId}/allergies`,
                {
                    allergen: form.allergen,
                    reaction: form.reaction || null,
                    severity: form.severity || null,
                    notes: form.notes || null,
                    identified_date: form.identified_date || null,
                },
            );

            onCreated({
                id: response.data.allergy.id,
                allergen: response.data.allergy.allergen,
                severity: response.data.allergy.severity,
            });
            toast.success('Medication allergy recorded.');
            setOpen(false);
            setForm({
                allergen: '',
                reaction: '',
                severity: 'moderate',
                notes: '',
                identified_date: '',
            });
        } catch (error: unknown) {
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'Failed to save allergy.',
            );
        } finally {
            setSaving(false);
        }
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant="outline">
                    <Plus className="mr-1 h-4 w-4" /> Add Allergy
                </Button>
            </DialogTrigger>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Add Medication Allergy</DialogTitle>
                    <DialogDescription>
                        Record a medication allergy for the selected client so
                        it is visible on medication workflows.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label htmlFor="allergen">Allergen *</Label>
                        <Input
                            id="allergen"
                            value={form.allergen}
                            onChange={(e) =>
                                setForm((current) => ({
                                    ...current,
                                    allergen: e.target.value,
                                }))
                            }
                            required
                        />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="reaction">Reaction</Label>
                        <Input
                            id="reaction"
                            value={form.reaction}
                            onChange={(e) =>
                                setForm((current) => ({
                                    ...current,
                                    reaction: e.target.value,
                                }))
                            }
                        />
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="severity">Severity</Label>
                            <Select
                                value={form.severity}
                                onValueChange={(value) =>
                                    setForm((current) => ({
                                        ...current,
                                        severity: value,
                                    }))
                                }
                            >
                                <SelectTrigger id="severity">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="mild">Mild</SelectItem>
                                    <SelectItem value="moderate">
                                        Moderate
                                    </SelectItem>
                                    <SelectItem value="severe">
                                        Severe
                                    </SelectItem>
                                    <SelectItem value="life_threatening">
                                        Life Threatening
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="identified_date">
                                Identified Date
                            </Label>
                            <Input
                                id="identified_date"
                                type="date"
                                value={form.identified_date}
                                onChange={(e) =>
                                    setForm((current) => ({
                                        ...current,
                                        identified_date: e.target.value,
                                    }))
                                }
                            />
                        </div>
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="allergy_notes">Notes</Label>
                        <Textarea
                            id="allergy_notes"
                            rows={3}
                            value={form.notes}
                            onChange={(e) =>
                                setForm((current) => ({
                                    ...current,
                                    notes: e.target.value,
                                }))
                            }
                        />
                    </div>
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={saving || !form.allergen.trim()}
                        >
                            {saving ? 'Saving...' : 'Save Allergy'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function Medications({
    medications,
    clients,
    staff,
    filters,
    interactionMap = {},
    selectedClient,
    clientContext,
    can,
}: Props) {
    const [allergies, setAllergies] = useState<MedicationAllergy[]>([]);
    const [loadingAllergies, setLoadingAllergies] = useState(false);

    useEffect(() => {
        if (!selectedClient) {
            setAllergies([]);
            return;
        }

        setLoadingAllergies(true);

        axios
            .get(`/api/medications/clients/${selectedClient.id}/allergies`)
            .then((response) => {
                setAllergies(response.data.allergies ?? []);
            })
            .catch(() => {
                toast.error(
                    'Failed to load medication allergies for this client.',
                );
            })
            .finally(() => {
                setLoadingAllergies(false);
            });
    }, [selectedClient?.id]);

    function updateFilter(key: string, value: string) {
        router.get(
            '/emar/medications',
            { ...filters, [key]: value || undefined },
            { preserveState: true },
        );
    }

    function handleDiscontinue(med: any) {
        const reason = prompt('Reason for discontinuation:');
        if (reason !== null) {
            router.post(`/emar/medications/${med.id}/discontinue`, { reason });
        }
    }

    return (
        <AppLayout>
            <Head title="eMAR - Medications" />
            <div className="flex flex-col gap-6 p-6">
                <FleetHero
                    title="Medications Database"
                    description="Central medication directory with search, filtering, and status tracking"
                    icon={<Pill className="h-7 w-7 text-white" />}
                    backHref="/emar"
                    backLabel="Back"
                />
                {/* Filters */}
                <div className="mb-6 flex flex-wrap items-center gap-3">
                    <Input
                        placeholder="Search medications..."
                        value={filters.search ?? ''}
                        onChange={(e) => updateFilter('search', e.target.value)}
                        className="w-64"
                    />
                    <Select
                        value={filters.status ?? ''}
                        onValueChange={(v) => updateFilter('status', v)}
                    >
                        <SelectTrigger className="w-40">
                            <SelectValue placeholder="All statuses" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="ceased">Ceased</SelectItem>
                            <SelectItem value="paused">Paused</SelectItem>
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters.type ?? ''}
                        onValueChange={(v) => updateFilter('type', v)}
                    >
                        <SelectTrigger className="w-40">
                            <SelectValue placeholder="All types" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="prn">PRN Only</SelectItem>
                            <SelectItem value="controlled">
                                Controlled
                            </SelectItem>
                            <SelectItem value="high_risk">High Risk</SelectItem>
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters.client_id ?? ''}
                        onValueChange={(v) => updateFilter('client_id', v)}
                    >
                        <SelectTrigger className="w-56">
                            <SelectValue placeholder="All clients" />
                        </SelectTrigger>
                        <SelectContent>
                            {clients.map((c) => (
                                <SelectItem key={c.id} value={c.id.toString()}>
                                    {c.last_name}, {c.first_name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <div className="ml-auto flex gap-2">
                        <DrugInteractionManager
                            canManage={can.manage_interactions}
                        />
                        <ImportCsvDialog />
                        <AddMedicationDialog clients={clients} />
                    </div>
                </div>

                {selectedClient && clientContext && (
                    <div className="mb-6 grid gap-4 xl:grid-cols-3">
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">
                                    {selectedClient.last_name},{' '}
                                    {selectedClient.first_name}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <div>
                                    <div className="text-xs tracking-wide text-muted-foreground uppercase">
                                        GP
                                    </div>
                                    <div>
                                        {clientContext.profile?.gp_name ??
                                            'Not recorded'}
                                    </div>
                                    {clientContext.profile?.gp_practice && (
                                        <div className="text-muted-foreground">
                                            {clientContext.profile.gp_practice}
                                        </div>
                                    )}
                                    {clientContext.profile?.gp_phone && (
                                        <div className="text-muted-foreground">
                                            {clientContext.profile.gp_phone}
                                        </div>
                                    )}
                                </div>
                                <div>
                                    <div className="text-xs tracking-wide text-muted-foreground uppercase">
                                        Hospital Preference
                                    </div>
                                    <div>
                                        {clientContext.profile
                                            ?.hospital_preference ??
                                            'Not recorded'}
                                    </div>
                                </div>
                                <div>
                                    <div className="text-xs tracking-wide text-muted-foreground uppercase">
                                        Medical Notes
                                    </div>
                                    <p className="line-clamp-4 whitespace-pre-wrap text-muted-foreground">
                                        {clientContext.profile
                                            ?.medical_history ||
                                            clientContext.profile?.notes ||
                                            'No medical notes recorded.'}
                                    </p>
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">
                                    Conditions & Contacts
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4 text-sm">
                                <div>
                                    <div className="mb-2 text-xs tracking-wide text-muted-foreground uppercase">
                                        Conditions
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        {clientContext.conditions.length > 0 ? (
                                            clientContext.conditions.map(
                                                (condition) => (
                                                    <Badge
                                                        key={condition.id}
                                                        variant="outline"
                                                    >
                                                        {condition.label}
                                                        {condition.severity
                                                            ? ` • ${condition.severity}`
                                                            : ''}
                                                    </Badge>
                                                ),
                                            )
                                        ) : (
                                            <span className="text-muted-foreground">
                                                No conditions recorded.
                                            </span>
                                        )}
                                    </div>
                                </div>
                                <div>
                                    <div className="mb-2 text-xs tracking-wide text-muted-foreground uppercase">
                                        Emergency Contacts
                                    </div>
                                    <div className="space-y-2">
                                        {clientContext.emergency_contacts
                                            .length > 0 ? (
                                            clientContext.emergency_contacts.map(
                                                (contact) => (
                                                    <div
                                                        key={contact.id}
                                                        className="rounded-md border p-2"
                                                    >
                                                        <div className="font-medium">
                                                            {contact.name}
                                                        </div>
                                                        <div className="text-xs text-muted-foreground">
                                                            {contact.relationship ??
                                                                'Relationship not recorded'}
                                                            {contact.phone
                                                                ? ` • ${contact.phone}`
                                                                : ''}
                                                        </div>
                                                    </div>
                                                ),
                                            )
                                        ) : (
                                            <span className="text-muted-foreground">
                                                No emergency contacts recorded.
                                            </span>
                                        )}
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="pb-3">
                                <div className="flex items-center justify-between gap-2">
                                    <CardTitle className="text-base">
                                        Allergies & Charts
                                    </CardTitle>
                                    {can.manage_allergies && (
                                        <AddAllergyDialog
                                            clientId={selectedClient.id}
                                            onCreated={(allergy) =>
                                                setAllergies((current) => [
                                                    allergy,
                                                    ...current,
                                                ])
                                            }
                                        />
                                    )}
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-4 text-sm">
                                <div>
                                    <div className="mb-2 text-xs tracking-wide text-muted-foreground uppercase">
                                        Medication Allergies
                                    </div>
                                    <div className="space-y-2">
                                        {loadingAllergies ? (
                                            <div className="text-muted-foreground">
                                                Loading allergies…
                                            </div>
                                        ) : allergies.length > 0 ? (
                                            allergies.map((allergy) => (
                                                <div
                                                    key={allergy.id}
                                                    className="rounded-md border p-2"
                                                >
                                                    <div className="flex items-center gap-2">
                                                        <span className="font-medium">
                                                            {allergy.allergen}
                                                        </span>
                                                        {allergy.severity && (
                                                            <Badge
                                                                variant={
                                                                    allergy.is_severe
                                                                        ? 'destructive'
                                                                        : 'outline'
                                                                }
                                                            >
                                                                {allergy.severity.replace(
                                                                    '_',
                                                                    ' ',
                                                                )}
                                                            </Badge>
                                                        )}
                                                    </div>
                                                    {(allergy.reaction ||
                                                        allergy.notes) && (
                                                        <p className="mt-1 text-xs text-muted-foreground">
                                                            {allergy.reaction ??
                                                                allergy.notes}
                                                        </p>
                                                    )}
                                                </div>
                                            ))
                                        ) : (
                                            <div className="text-muted-foreground">
                                                No medication allergies
                                                recorded.
                                            </div>
                                        )}
                                    </div>
                                </div>
                                <div>
                                    <div className="mb-2 text-xs tracking-wide text-muted-foreground uppercase">
                                        Medication Charts
                                    </div>
                                    <div className="space-y-2">
                                        {clientContext.medication_charts
                                            .length > 0 ? (
                                            clientContext.medication_charts.map(
                                                (chart) => (
                                                    <a
                                                        key={chart.id}
                                                        href={
                                                            chart.download_url
                                                        }
                                                        className="block rounded-md border p-2 transition hover:border-primary/40 hover:bg-muted/40"
                                                    >
                                                        <div className="font-medium">
                                                            {chart.title ||
                                                                chart.original_name ||
                                                                `Chart ${chart.id}`}
                                                        </div>
                                                        <div className="text-xs text-muted-foreground">
                                                            {chart.version
                                                                ? `Version ${chart.version}`
                                                                : 'Current chart'}
                                                            {chart.effective_date
                                                                ? ` • Effective ${chart.effective_date}`
                                                                : ''}
                                                        </div>
                                                    </a>
                                                ),
                                            )
                                        ) : (
                                            <div className="text-muted-foreground">
                                                No medication charts uploaded.
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                )}

                <Card>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b bg-muted/50">
                                    <th className="p-3 text-left font-medium">
                                        Medication
                                    </th>
                                    <th className="p-3 text-left font-medium">
                                        Client
                                    </th>
                                    <th className="p-3 text-left font-medium">
                                        Dose
                                    </th>
                                    <th className="p-3 text-left font-medium">
                                        Frequency
                                    </th>
                                    <th className="p-3 text-left font-medium">
                                        Route
                                    </th>
                                    <th className="p-3 text-left font-medium">
                                        Flags
                                    </th>
                                    <th className="p-3 text-left font-medium">
                                        State
                                    </th>
                                    <th className="p-3 text-left font-medium">
                                        Stock
                                    </th>
                                    <th className="p-3 text-right font-medium">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {medications.data.map((m: any) => (
                                    <tr
                                        key={m.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="p-3">
                                            <span className="font-medium">
                                                {m.name}
                                            </span>
                                            {m.instructions && (
                                                <p className="mt-0.5 line-clamp-1 text-xs text-muted-foreground">
                                                    {m.instructions}
                                                </p>
                                            )}
                                        </td>
                                        <td className="p-3">
                                            {m.client?.last_name},{' '}
                                            {m.client?.first_name}
                                        </td>
                                        <td className="p-3 text-xs">
                                            {m.dose_amount !== null &&
                                            m.dose_amount !== undefined &&
                                            m.dose_unit
                                                ? `${m.dose_amount} ${m.dose_unit}`
                                                : (m.dosage ?? '—')}
                                        </td>
                                        <td className="p-3 text-xs">
                                            {m.frequency}
                                        </td>
                                        <td className="p-3 text-xs">
                                            {m.route ?? '—'}
                                        </td>
                                        <td className="p-3">
                                            <div className="flex gap-1">
                                                {m.is_prn && (
                                                    <Badge
                                                        variant="outline"
                                                        className="text-[10px]"
                                                    >
                                                        PRN
                                                    </Badge>
                                                )}
                                                {m.controlled_drug && (
                                                    <Badge
                                                        variant="destructive"
                                                        className="text-[10px]"
                                                    >
                                                        CD
                                                    </Badge>
                                                )}
                                                {m.high_risk && (
                                                    <Badge className="bg-status-warning-bg text-[10px] text-status-warning">
                                                        HR
                                                    </Badge>
                                                )}
                                                {m.witness_required && (
                                                    <Badge
                                                        variant="secondary"
                                                        className="text-[10px]"
                                                    >
                                                        W
                                                    </Badge>
                                                )}
                                                {interactionMap[m.id] && (
                                                    <TooltipProvider>
                                                        <Tooltip>
                                                            <TooltipTrigger>
                                                                <AlertTriangle
                                                                    className={`h-4 w-4 ${
                                                                        interactionMap[
                                                                            m.id
                                                                        ] ===
                                                                        'contraindicated'
                                                                            ? 'text-status-critical'
                                                                            : interactionMap[
                                                                                    m
                                                                                        .id
                                                                                ] ===
                                                                                'major'
                                                                              ? 'text-status-warning'
                                                                              : 'text-status-warning'
                                                                    }`}
                                                                />
                                                            </TooltipTrigger>
                                                            <TooltipContent>
                                                                Drug interaction
                                                                (
                                                                {
                                                                    interactionMap[
                                                                        m.id
                                                                    ]
                                                                }
                                                                )
                                                            </TooltipContent>
                                                        </Tooltip>
                                                    </TooltipProvider>
                                                )}
                                            </div>
                                        </td>
                                        <td className="p-3">
                                            <Badge
                                                variant={
                                                    m.state === 'active'
                                                        ? 'default'
                                                        : m.state === 'paused'
                                                          ? 'secondary'
                                                          : 'outline'
                                                }
                                                className="text-xs"
                                            >
                                                {m.state}
                                            </Badge>
                                        </td>
                                        <td className="p-3 font-mono text-xs">
                                            {m.stock?.on_hand ?? '—'}
                                        </td>
                                        <td className="p-3 text-right">
                                            <div className="flex items-center justify-end gap-1">
                                                {m.client_id && (
                                                    <MedicationVersionHistory
                                                        clientId={m.client_id}
                                                        medicationId={m.id}
                                                        medicationName={m.name}
                                                    />
                                                )}
                                                <EditMedicationDialog
                                                    med={m}
                                                    clients={clients}
                                                />
                                                {m.state === 'active' && (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        className="h-7 px-2 text-xs text-status-critical hover:text-status-critical"
                                                        onClick={() =>
                                                            handleDiscontinue(m)
                                                        }
                                                    >
                                                        <Ban className="mr-1 h-3 w-3" />{' '}
                                                        Discontinue
                                                    </Button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                                {medications.data.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={9}
                                            className="p-6 text-center text-muted-foreground"
                                        >
                                            No medications found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
