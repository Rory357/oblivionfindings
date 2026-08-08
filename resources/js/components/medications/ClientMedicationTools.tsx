import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
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
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { router } from '@inertiajs/react';
import {
    Activity,
    BellOff,
    Plus,
    Settings2,
    Syringe,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

export const CARE_LEVELS = [
    { value: 'rest_home', label: 'Rest Home' },
    { value: 'hospital', label: 'Hospital' },
    { value: 'dementia', label: 'Dementia' },
    { value: 'psychogeriatric', label: 'Psychogeriatric' },
    { value: 'supported_independent', label: 'Supported Independent' },
    { value: 'respite', label: 'Respite' },
];

const ALERT_TYPES = [
    { value: 'warfarin', label: 'Warfarin' },
    { value: 'paper_prescription', label: 'Paper prescription' },
    { value: 'chart_warning', label: 'Other warning' },
];

type StaffMember = { id: number; name: string };
type MedicationOption = { id: number; name: string };

type AttentionAlert = {
    id: number;
    type: string;
    title: string;
    detail?: string | null;
    prompt_on_open: boolean;
};

type InrRecord = {
    id: number;
    medication_name?: string | null;
    inr_value: string | number;
    tested_on?: string | null;
    next_test_date?: string | null;
    disabled_at?: string | null;
};

type SyringeDriver = {
    id: number;
    status: string;
    commenced_at?: string | null;
    rate?: string | null;
    rate_unit?: string | null;
    contents?: Array<Record<string, unknown>>;
    site_of_insertion?: string | null;
};

type Settings = {
    suppress_med_admin_alerts?: boolean;
    med_alerts_suppressed_reason?: string | null;
    next_chart_review_date?: string | null;
    chart_review_interval_months?: number | null;
    care_level?: string | null;
};

type Props = {
    client: { id: number; first_name: string; last_name: string };
    staff: StaffMember[];
    medications: MedicationOption[];
    attentionAlerts: AttentionAlert[];
    inrRecords: InrRecord[];
    syringeDrivers: SyringeDriver[];
    settings: Settings;
    can: {
        manage_settings?: boolean;
        manage_inr?: boolean;
        manage_syringe_drivers?: boolean;
    };
};

const today = () => new Date().toISOString().split('T')[0];
const nowLocal = () => {
    const d = new Date();
    d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
    return d.toISOString().slice(0, 16);
};

export default function ClientMedicationTools({
    client,
    staff,
    medications,
    attentionAlerts,
    inrRecords,
    syringeDrivers,
    settings,
    can,
}: Props) {
    const [alertsOpen, setAlertsOpen] = useState(false);
    const [inrOpen, setInrOpen] = useState(false);
    const [syringeOpen, setSyringeOpen] = useState(false);
    const [settingsOpen, setSettingsOpen] = useState(false);

    const canManage =
        can.manage_settings || can.manage_inr || can.manage_syringe_drivers;
    if (!canManage) return null;

    return (
        <>
            <div className="flex flex-wrap gap-2">
                {can.manage_settings && (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setAlertsOpen(true)}
                    >
                        <Plus className="mr-1.5 h-4 w-4" />
                        Chart Alerts
                    </Button>
                )}
                {can.manage_inr && (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setInrOpen(true)}
                    >
                        <Activity className="mr-1.5 h-4 w-4" />
                        INR / Warfarin
                    </Button>
                )}
                {can.manage_syringe_drivers && (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setSyringeOpen(true)}
                    >
                        <Syringe className="mr-1.5 h-4 w-4" />
                        Syringe Driver
                    </Button>
                )}
                {can.manage_settings && (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setSettingsOpen(true)}
                    >
                        <Settings2 className="mr-1.5 h-4 w-4" />
                        Chart Settings
                    </Button>
                )}
            </div>

            <AttentionAlertsDialog
                open={alertsOpen}
                onOpenChange={setAlertsOpen}
                client={client}
                alerts={attentionAlerts}
            />
            <InrDialog
                open={inrOpen}
                onOpenChange={setInrOpen}
                client={client}
                records={inrRecords}
                medications={medications}
            />
            <SyringeDriverDialog
                open={syringeOpen}
                onOpenChange={setSyringeOpen}
                client={client}
                drivers={syringeDrivers}
                medications={medications}
                staff={staff}
            />
            <ChartSettingsDialog
                open={settingsOpen}
                onOpenChange={setSettingsOpen}
                client={client}
                settings={settings}
            />
        </>
    );
}

// ─── Attention alerts ────────────────────────────────────────
function AttentionAlertsDialog({
    open,
    onOpenChange,
    client,
    alerts,
}: {
    open: boolean;
    onOpenChange: (o: boolean) => void;
    client: { id: number };
    alerts: AttentionAlert[];
}) {
    const [type, setType] = useState('warfarin');
    const [title, setTitle] = useState('');
    const [detail, setDetail] = useState('');
    const [promptOnOpen, setPromptOnOpen] = useState(true);
    const [saving, setSaving] = useState(false);

    function add() {
        if (!title.trim()) {
            toast.error('Enter a title for the chart alert.');
            return;
        }
        router.post(
            `/emar/clients/${client.id}/attention-alerts`,
            {
                type,
                title: title.trim(),
                detail: detail.trim() || null,
                prompt_on_open: promptOnOpen,
                enabled: true,
            },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () => {
                    setTitle('');
                    setDetail('');
                },
            },
        );
    }

    function resolve(id: number) {
        router.post(
            `/emar/attention-alerts/${id}/resolve`,
            {},
            { preserveScroll: true },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Chart attention alerts</DialogTitle>
                    <DialogDescription>
                        Warnings shown to every staff member on this
                        client&apos;s MAR. Optionally prompt when the chart is
                        opened.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-3">
                    {alerts.length > 0 ? (
                        alerts.map((alert) => (
                            <div
                                key={alert.id}
                                className="flex items-start justify-between gap-3 rounded-md border p-3 text-sm"
                            >
                                <div>
                                    <div className="flex items-center gap-2 font-medium">
                                        {alert.title}
                                        {alert.prompt_on_open && (
                                            <Badge
                                                variant="outline"
                                                className="text-[10px]"
                                            >
                                                Prompt on open
                                            </Badge>
                                        )}
                                    </div>
                                    {alert.detail && (
                                        <p className="mt-0.5 text-xs text-muted-foreground">
                                            {alert.detail}
                                        </p>
                                    )}
                                </div>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => resolve(alert.id)}
                                >
                                    Resolve
                                </Button>
                            </div>
                        ))
                    ) : (
                        <p className="rounded-md border border-dashed p-4 text-center text-sm text-muted-foreground">
                            No active chart alerts.
                        </p>
                    )}
                </div>

                <div className="space-y-3 border-t pt-4">
                    <div className="space-y-1.5">
                        <Label>Alert type</Label>
                        <div className="grid grid-cols-3 gap-2">
                            {ALERT_TYPES.map((t) => (
                                <Button
                                    key={t.value}
                                    type="button"
                                    size="sm"
                                    variant={
                                        type === t.value ? 'default' : 'outline'
                                    }
                                    onClick={() => setType(t.value)}
                                >
                                    {t.label}
                                </Button>
                            ))}
                        </div>
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="alert-title">Title</Label>
                        <Input
                            id="alert-title"
                            value={title}
                            onChange={(e) => setTitle(e.target.value)}
                            placeholder="e.g. On warfarin — check INR"
                        />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="alert-detail">Detail (optional)</Label>
                        <Textarea
                            id="alert-detail"
                            value={detail}
                            onChange={(e) => setDetail(e.target.value)}
                            placeholder="e.g. Paper prescription kept in the medication folder."
                            rows={2}
                        />
                    </div>
                    <label className="flex items-center justify-between rounded-md border p-3 text-sm">
                        <span className="font-medium">
                            Prompt when chart is opened
                        </span>
                        <Switch
                            checked={promptOnOpen}
                            onCheckedChange={setPromptOnOpen}
                        />
                    </label>
                </div>

                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Close
                    </Button>
                    <Button onClick={add} disabled={saving}>
                        <Plus className="mr-1.5 h-4 w-4" />
                        Add alert
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

// ─── INR ─────────────────────────────────────────────────────
function InrDialog({
    open,
    onOpenChange,
    client,
    records,
    medications,
}: {
    open: boolean;
    onOpenChange: (o: boolean) => void;
    client: { id: number };
    records: InrRecord[];
    medications: MedicationOption[];
}) {
    const [value, setValue] = useState('');
    const [testedOn, setTestedOn] = useState(today());
    const [rangeLow, setRangeLow] = useState('2');
    const [rangeHigh, setRangeHigh] = useState('3');
    const [doseMg, setDoseMg] = useState('');
    const [nextTest, setNextTest] = useState('');
    const [medId, setMedId] = useState('none');
    const [notes, setNotes] = useState('');
    const [saving, setSaving] = useState(false);

    function add() {
        if (!value.trim()) {
            toast.error('Enter the INR value.');
            return;
        }
        router.post(
            `/emar/clients/${client.id}/inr`,
            {
                inr_value: Number(value),
                tested_on: testedOn,
                target_range_low: rangeLow ? Number(rangeLow) : null,
                target_range_high: rangeHigh ? Number(rangeHigh) : null,
                dose_mg: doseMg ? Number(doseMg) : null,
                next_test_date: nextTest || null,
                client_medication_id: medId !== 'none' ? Number(medId) : null,
                notes: notes.trim() || null,
            },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () => {
                    setValue('');
                    setDoseMg('');
                    setNotes('');
                },
            },
        );
    }

    function disable(id: number) {
        if (
            !window.confirm(
                'Disable this INR result? It cannot be deleted, only disabled.',
            )
        )
            return;
        router.post(`/emar/inr/${id}/disable`, {}, { preserveScroll: true });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        INR results — {client ? 'warfarin monitoring' : ''}
                    </DialogTitle>
                    <DialogDescription>
                        Record INR results with target range and dose. Results
                        can be disabled but never deleted.
                    </DialogDescription>
                </DialogHeader>

                <div className="max-h-48 overflow-y-auto rounded-md border">
                    <table className="w-full text-sm">
                        <thead className="sticky top-0 bg-muted/50">
                            <tr className="text-left text-xs font-medium text-muted-foreground">
                                <th className="p-2">Tested</th>
                                <th className="p-2">INR</th>
                                <th className="p-2">Target</th>
                                <th className="p-2">Next test</th>
                                <th className="p-2 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            {records.length > 0 ? (
                                records.map((r) => (
                                    <tr
                                        key={r.id}
                                        className={`border-t ${r.disabled_at ? 'text-muted-foreground line-through' : ''}`}
                                    >
                                        <td className="p-2">
                                            {r.tested_on ?? '—'}
                                        </td>
                                        <td className="p-2 font-medium tabular-nums">
                                            {r.inr_value}
                                        </td>
                                        <td className="p-2 text-muted-foreground">
                                            —
                                        </td>
                                        <td className="p-2 tabular-nums">
                                            {r.next_test_date ?? '—'}
                                        </td>
                                        <td className="p-2 text-right">
                                            {!r.disabled_at && (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    className="h-7 text-status-critical"
                                                    onClick={() =>
                                                        disable(r.id)
                                                    }
                                                >
                                                    Disable
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td
                                        colSpan={5}
                                        className="p-4 text-center text-muted-foreground"
                                    >
                                        No INR results recorded.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <div className="grid grid-cols-2 gap-3 border-t pt-4 sm:grid-cols-3">
                    <div className="space-y-1.5">
                        <Label htmlFor="inr-value">INR value</Label>
                        <Input
                            id="inr-value"
                            type="number"
                            step="0.1"
                            value={value}
                            onChange={(e) => setValue(e.target.value)}
                        />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="inr-tested">Tested on</Label>
                        <Input
                            id="inr-tested"
                            type="date"
                            value={testedOn}
                            onChange={(e) => setTestedOn(e.target.value)}
                        />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="inr-next">Next test</Label>
                        <Input
                            id="inr-next"
                            type="date"
                            value={nextTest}
                            onChange={(e) => setNextTest(e.target.value)}
                        />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="inr-low">Target low</Label>
                        <Input
                            id="inr-low"
                            type="number"
                            step="0.1"
                            value={rangeLow}
                            onChange={(e) => setRangeLow(e.target.value)}
                        />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="inr-high">Target high</Label>
                        <Input
                            id="inr-high"
                            type="number"
                            step="0.1"
                            value={rangeHigh}
                            onChange={(e) => setRangeHigh(e.target.value)}
                        />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="inr-dose">Dose (mg)</Label>
                        <Input
                            id="inr-dose"
                            type="number"
                            step="0.01"
                            value={doseMg}
                            onChange={(e) => setDoseMg(e.target.value)}
                        />
                    </div>
                    <div className="space-y-1.5 sm:col-span-3">
                        <Label>Linked medication (optional)</Label>
                        <Select value={medId} onValueChange={setMedId}>
                            <SelectTrigger>
                                <SelectValue placeholder="None" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">None</SelectItem>
                                {medications.map((m) => (
                                    <SelectItem
                                        key={m.id}
                                        value={m.id.toString()}
                                    >
                                        {m.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="space-y-1.5 sm:col-span-3">
                        <Label htmlFor="inr-notes">Notes</Label>
                        <Textarea
                            id="inr-notes"
                            value={notes}
                            onChange={(e) => setNotes(e.target.value)}
                            rows={2}
                        />
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Close
                    </Button>
                    <Button onClick={add} disabled={saving}>
                        Record INR
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

// ─── Syringe driver ──────────────────────────────────────────
type DriverContent = {
    client_medication_id: string;
    name: string;
    dose: string;
    unit: string;
    requires_witness: boolean;
};

function SyringeDriverDialog({
    open,
    onOpenChange,
    client,
    drivers,
    medications,
    staff,
}: {
    open: boolean;
    onOpenChange: (o: boolean) => void;
    client: { id: number };
    drivers: SyringeDriver[];
    medications: MedicationOption[];
    staff: StaffMember[];
}) {
    const [commencedAt, setCommencedAt] = useState(nowLocal());
    const [rate, setRate] = useState('');
    const [rateUnit, setRateUnit] = useState('mL/hr');
    const [durationHours, setDurationHours] = useState('24');
    const [site, setSite] = useState('');
    const [notes, setNotes] = useState('');
    const [contents, setContents] = useState<DriverContent[]>([
        {
            client_medication_id: 'none',
            name: '',
            dose: '',
            unit: 'mg',
            requires_witness: false,
        },
    ]);
    const [witnessId, setWitnessId] = useState('');
    const [witnessCredential, setWitnessCredential] = useState('');
    const [saving, setSaving] = useState(false);

    const running = drivers.filter((d) => d.status === 'running');
    const needsWitness = contents.some((c) => c.requires_witness);

    function updateContent(index: number, patch: Partial<DriverContent>) {
        setContents((current) =>
            current.map((c, i) => (i === index ? { ...c, ...patch } : c)),
        );
    }

    function addContent() {
        setContents((c) => [
            ...c,
            {
                client_medication_id: 'none',
                name: '',
                dose: '',
                unit: 'mg',
                requires_witness: false,
            },
        ]);
    }

    function removeContent(index: number) {
        setContents((c) =>
            c.length > 1 ? c.filter((_, i) => i !== index) : c,
        );
    }

    function start() {
        const cleaned = contents
            .map((c) => ({
                client_medication_id:
                    c.client_medication_id !== 'none'
                        ? Number(c.client_medication_id)
                        : null,
                name:
                    c.client_medication_id !== 'none'
                        ? (medications.find(
                              (m) => m.id.toString() === c.client_medication_id,
                          )?.name ?? c.name)
                        : c.name.trim(),
                dose: c.dose.trim() || null,
                unit: c.unit.trim() || null,
                requires_witness: c.requires_witness,
            }))
            .filter((c) => c.client_medication_id || c.name);

        if (cleaned.length === 0) {
            toast.error('Add at least one medication to the driver.');
            return;
        }
        if (needsWitness && (!witnessId || !witnessCredential)) {
            toast.error(
                'A controlled-drug syringe driver needs a witness and their password/PIN.',
            );
            return;
        }

        router.post(
            `/emar/clients/${client.id}/syringe-drivers`,
            {
                commenced_at: commencedAt,
                rate: rate.trim() || null,
                rate_unit: rateUnit.trim() || null,
                duration_hours: durationHours ? Number(durationHours) : null,
                site_of_insertion: site.trim() || null,
                notes: notes.trim() || null,
                contents: cleaned,
                witnessed_by: needsWitness ? Number(witnessId) : null,
                witness_credential: needsWitness ? witnessCredential : null,
            },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () => {
                    setWitnessCredential('');
                    setNotes('');
                },
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>Syringe drivers</DialogTitle>
                    <DialogDescription>
                        Commence a continuous subcutaneous infusion, record
                        routine checks, and complete the driver.
                    </DialogDescription>
                </DialogHeader>

                {running.length > 0 && (
                    <div className="space-y-2">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Running
                        </p>
                        {running.map((d) => (
                            <RunningDriverRow
                                key={d.id}
                                driver={d}
                                staff={staff}
                            />
                        ))}
                    </div>
                )}

                <div className="space-y-3 border-t pt-4">
                    <p className="text-sm font-medium">Commence a new driver</p>
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div className="space-y-1.5 sm:col-span-2">
                            <Label htmlFor="sd-commenced">Commenced at</Label>
                            <Input
                                id="sd-commenced"
                                type="datetime-local"
                                value={commencedAt}
                                onChange={(e) => setCommencedAt(e.target.value)}
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="sd-rate">Rate</Label>
                            <Input
                                id="sd-rate"
                                value={rate}
                                onChange={(e) => setRate(e.target.value)}
                                placeholder="2"
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="sd-rate-unit">Unit</Label>
                            <Input
                                id="sd-rate-unit"
                                value={rateUnit}
                                onChange={(e) => setRateUnit(e.target.value)}
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="sd-duration">Duration (hrs)</Label>
                            <Input
                                id="sd-duration"
                                type="number"
                                step="0.5"
                                value={durationHours}
                                onChange={(e) =>
                                    setDurationHours(e.target.value)
                                }
                            />
                        </div>
                        <div className="space-y-1.5 sm:col-span-3">
                            <Label htmlFor="sd-site">Site of insertion</Label>
                            <Input
                                id="sd-site"
                                value={site}
                                onChange={(e) => setSite(e.target.value)}
                                placeholder="e.g. Left upper arm"
                            />
                        </div>
                    </div>

                    <div className="space-y-2">
                        <Label>Contents</Label>
                        {contents.map((c, i) => (
                            <div
                                key={i}
                                className="grid grid-cols-12 items-end gap-2 rounded-md border p-2"
                            >
                                <div className="col-span-5 space-y-1">
                                    <span className="text-[10px] text-muted-foreground">
                                        Medication
                                    </span>
                                    <Select
                                        value={c.client_medication_id}
                                        onValueChange={(v) =>
                                            updateContent(i, {
                                                client_medication_id: v,
                                            })
                                        }
                                    >
                                        <SelectTrigger className="h-9">
                                            <SelectValue placeholder="Select / other" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="none">
                                                Other (type name)
                                            </SelectItem>
                                            {medications.map((m) => (
                                                <SelectItem
                                                    key={m.id}
                                                    value={m.id.toString()}
                                                >
                                                    {m.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {c.client_medication_id === 'none' && (
                                        <Input
                                            className="mt-1 h-8"
                                            placeholder="Medication name"
                                            value={c.name}
                                            onChange={(e) =>
                                                updateContent(i, {
                                                    name: e.target.value,
                                                })
                                            }
                                        />
                                    )}
                                </div>
                                <div className="col-span-2 space-y-1">
                                    <span className="text-[10px] text-muted-foreground">
                                        Dose
                                    </span>
                                    <Input
                                        className="h-9"
                                        value={c.dose}
                                        onChange={(e) =>
                                            updateContent(i, {
                                                dose: e.target.value,
                                            })
                                        }
                                    />
                                </div>
                                <div className="col-span-2 space-y-1">
                                    <span className="text-[10px] text-muted-foreground">
                                        Unit
                                    </span>
                                    <Input
                                        className="h-9"
                                        value={c.unit}
                                        onChange={(e) =>
                                            updateContent(i, {
                                                unit: e.target.value,
                                            })
                                        }
                                    />
                                </div>
                                <label className="col-span-2 flex items-center gap-1.5 pb-2 text-xs">
                                    <Switch
                                        checked={c.requires_witness}
                                        onCheckedChange={(checked) =>
                                            updateContent(i, {
                                                requires_witness: checked,
                                            })
                                        }
                                    />
                                    Controlled
                                </label>
                                <div className="col-span-1 flex justify-end pb-1">
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        className="h-8 w-8 text-status-critical"
                                        onClick={() => removeContent(i)}
                                        aria-label="Remove content"
                                    >
                                        <Trash2 className="h-4 w-4" />
                                    </Button>
                                </div>
                            </div>
                        ))}
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={addContent}
                        >
                            <Plus className="mr-1.5 h-4 w-4" />
                            Add medication
                        </Button>
                    </div>

                    {needsWitness && (
                        <div className="grid grid-cols-2 gap-3 rounded-md border border-status-warning/40 bg-status-warning-bg/40 p-3">
                            <div className="space-y-1.5">
                                <Label>Witness</Label>
                                <Select
                                    value={witnessId}
                                    onValueChange={setWitnessId}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select witness" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {staff.map((s) => (
                                            <SelectItem
                                                key={s.id}
                                                value={s.id.toString()}
                                            >
                                                {s.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="sd-witness-cred">
                                    Witness password / PIN
                                </Label>
                                <Input
                                    id="sd-witness-cred"
                                    type="password"
                                    value={witnessCredential}
                                    onChange={(e) =>
                                        setWitnessCredential(e.target.value)
                                    }
                                />
                            </div>
                        </div>
                    )}

                    <div className="space-y-1.5">
                        <Label htmlFor="sd-notes">Notes</Label>
                        <Textarea
                            id="sd-notes"
                            value={notes}
                            onChange={(e) => setNotes(e.target.value)}
                            rows={2}
                        />
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Close
                    </Button>
                    <Button onClick={start} disabled={saving}>
                        <Syringe className="mr-1.5 h-4 w-4" />
                        Commence driver
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function RunningDriverRow({
    driver,
    staff,
}: {
    driver: SyringeDriver;
    staff: StaffMember[];
}) {
    const [checkOpen, setCheckOpen] = useState(false);
    const [running, setRunning] = useState(true);
    const [siteCondition, setSiteCondition] = useState('');
    const [volume, setVolume] = useState('');
    const [notes, setNotes] = useState('');

    void staff;

    function recordCheck() {
        router.post(
            `/emar/syringe-drivers/${driver.id}/checks`,
            {
                infusion_running: running,
                site_condition: siteCondition.trim() || null,
                volume_remaining: volume.trim() || null,
                notes: notes.trim() || null,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setCheckOpen(false);
                    setSiteCondition('');
                    setVolume('');
                    setNotes('');
                },
            },
        );
    }

    function complete(status: 'completed' | 'stopped') {
        if (!window.confirm(`Mark this syringe driver as ${status}?`)) return;
        router.post(
            `/emar/syringe-drivers/${driver.id}/complete`,
            { status },
            { preserveScroll: true },
        );
    }

    return (
        <div className="rounded-md border p-3 text-sm">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <div className="font-medium">
                        Driver #{driver.id}
                        {driver.rate
                            ? ` • ${driver.rate} ${driver.rate_unit ?? ''}`
                            : ''}
                    </div>
                    <div className="text-xs text-muted-foreground">
                        {(driver.contents ?? [])
                            .map((c) => (c as { name?: string }).name)
                            .filter(Boolean)
                            .join(', ') || 'No contents recorded'}
                        {driver.site_of_insertion
                            ? ` • ${driver.site_of_insertion}`
                            : ''}
                    </div>
                </div>
                <div className="flex gap-1.5">
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setCheckOpen((o) => !o)}
                    >
                        Record check
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => complete('completed')}
                    >
                        Complete
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        className="text-status-critical"
                        onClick={() => complete('stopped')}
                    >
                        Stop
                    </Button>
                </div>
            </div>
            {checkOpen && (
                <div className="mt-3 grid grid-cols-2 gap-2 border-t pt-3">
                    <label className="col-span-2 flex items-center justify-between rounded-md border p-2 text-xs">
                        <span className="font-medium">Infusion running</span>
                        <Switch
                            checked={running}
                            onCheckedChange={setRunning}
                        />
                    </label>
                    <Input
                        placeholder="Site condition"
                        value={siteCondition}
                        onChange={(e) => setSiteCondition(e.target.value)}
                    />
                    <Input
                        placeholder="Volume remaining"
                        value={volume}
                        onChange={(e) => setVolume(e.target.value)}
                    />
                    <Input
                        className="col-span-2"
                        placeholder="Notes"
                        value={notes}
                        onChange={(e) => setNotes(e.target.value)}
                    />
                    <div className="col-span-2 flex justify-end">
                        <Button size="sm" onClick={recordCheck}>
                            Save check
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}

// ─── Chart settings (suppression + cadence + care level) ─────
function ChartSettingsDialog({
    open,
    onOpenChange,
    client,
    settings,
}: {
    open: boolean;
    onOpenChange: (o: boolean) => void;
    client: { id: number };
    settings: Settings;
}) {
    const [suppress, setSuppress] = useState(
        Boolean(settings.suppress_med_admin_alerts),
    );
    const [reason, setReason] = useState(
        settings.med_alerts_suppressed_reason ?? '',
    );
    const [suppressBasis, setSuppressBasis] = useState('');
    const [careLevel, setCareLevel] = useState(settings.care_level ?? 'none');
    const [interval, setInterval] = useState(
        (settings.chart_review_interval_months ?? 3).toString(),
    );
    const [nextReview, setNextReview] = useState(
        settings.next_chart_review_date ?? '',
    );
    const [saving, setSaving] = useState(false);

    function saveSuppression() {
        if (suppress && !reason.trim()) {
            toast.error('Enter why medication alerts are being suppressed.');
            return;
        }
        if (suppress && !suppressBasis) {
            toast.error('Select the basis for suppressing these alerts.');
            return;
        }
        router.post(
            `/emar/clients/${client.id}/alert-suppression`,
            {
                suppress_med_admin_alerts: suppress,
                reason: reason.trim() || null,
                basis: suppressBasis || null,
            },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
            },
        );
    }

    function saveSettings() {
        router.post(
            `/emar/clients/${client.id}/medication-settings`,
            {
                care_level: careLevel !== 'none' ? careLevel : null,
                chart_review_interval_months: Number(interval) || 3,
                next_chart_review_date: nextReview || null,
            },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () => onOpenChange(false),
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Medication chart settings</DialogTitle>
                    <DialogDescription>
                        Care level, chart review cadence, and whether
                        due/overdue medication alerts are suppressed.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4">
                    <div className="space-y-3 rounded-md border p-3">
                        <div className="flex items-center justify-between">
                            <div>
                                <p className="flex items-center gap-1.5 text-sm font-medium">
                                    <BellOff className="h-4 w-4" /> Suppress
                                    med-admin alerts
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    For independent residents or extended social
                                    leave.
                                </p>
                            </div>
                            <Switch
                                checked={suppress}
                                onCheckedChange={setSuppress}
                            />
                        </div>
                        {suppress && (
                            <>
                                <Select
                                    value={suppressBasis}
                                    onValueChange={setSuppressBasis}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Basis for suppression (required)" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="capacity_assessment">
                                            Capacity assessment
                                        </SelectItem>
                                        <SelectItem value="mdt_decision">
                                            MDT decision
                                        </SelectItem>
                                        <SelectItem value="clinical_judgement">
                                            Clinical judgement
                                        </SelectItem>
                                        <SelectItem value="client_preference">
                                            Client preference
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <Textarea
                                    value={reason}
                                    onChange={(e) => setReason(e.target.value)}
                                    placeholder="Reason for suppression (required)"
                                    rows={2}
                                />
                            </>
                        )}
                        <div className="flex justify-end">
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={saveSuppression}
                                disabled={saving}
                            >
                                Save suppression
                            </Button>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div className="space-y-1.5">
                            <Label>Care level</Label>
                            <Select
                                value={careLevel}
                                onValueChange={setCareLevel}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Not set" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">
                                        Not set
                                    </SelectItem>
                                    {CARE_LEVELS.map((c) => (
                                        <SelectItem
                                            key={c.value}
                                            value={c.value}
                                        >
                                            {c.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="cs-interval">
                                Review interval (months)
                            </Label>
                            <Input
                                id="cs-interval"
                                type="number"
                                min={1}
                                max={12}
                                value={interval}
                                onChange={(e) => setInterval(e.target.value)}
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="cs-next">Next chart review</Label>
                            <Input
                                id="cs-next"
                                type="date"
                                value={nextReview}
                                onChange={(e) => setNextReview(e.target.value)}
                            />
                        </div>
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Close
                    </Button>
                    <Button onClick={saveSettings} disabled={saving}>
                        Save settings
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
