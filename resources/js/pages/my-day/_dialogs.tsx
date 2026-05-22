import { router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    CheckCircle2,
    Clock,
    ClipboardList,
    Droplet,
    FileText,
    Heart,
    HeartPulse,
    Home,
    Loader2,
    Moon,
    Scale,
    Stethoscope,
    Users,
    UserRound,
} from 'lucide-react';
import {
    type ComponentType,
    type ReactNode,
    useCallback,
    useEffect,
    useMemo,
    useState,
} from 'react';

import DictateButton from '@/components/dictate-button';
import HandoverWriteForm, {
    emptyHandoverWriteValue,
    type HandoverWriteValue,
} from '@/components/handover-write-form';
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
import { TabsContent, TabsList, TabsRoot, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

import { residentHue, residentInitials } from './lib/resident-hue';
import { ResidentDot } from './components/resident-dot';
import type {
    MyDayResident,
    MyDayTimesheet,
    MyDayTimesheetClientAllocation,
    MyDayTimesheetClientCandidate,
    TimesheetAllocationMethod,
} from './lib/types';

/* -------------------------------------------------------------------------- */
/*  My Day dialogs — popup-pattern per docs/POPUP_STYLE_GUIDE.md              */
/* -------------------------------------------------------------------------- */
/*
 * Web-only (no Sheet fallback). All shells follow the POPUP_STYLE_GUIDE
 * shell+body split: outer <Dialog> with inline `style` width, inner body
 * gated by `{open && <Body />}` so form state resets cleanly between cycles.
 *
 * Co-located here per the `_dialogs.tsx` convention.
 */

// ─── Observation type registry (Send-Kudos style picker source) ──────────────

export type ObsTypeKey =
    | 'vitals'
    | 'weight'
    | 'bowel'
    | 'sleep'
    | 'fluid_intake'
    | 'pain'
    | 'general';

export type ObsTypeDef = {
    key: ObsTypeKey;
    label: string;
    description: string;
    icon: ComponentType<{ className?: string }>;
    accent: string;
    /** Requires `clinical.observations.recordClinical` (vitals + pain). */
    clinical: boolean;
};

export const OBS_TYPES: ObsTypeDef[] = [
    {
        key: 'vitals',
        label: 'Vital signs',
        description: 'BP, pulse, temp, SpO₂',
        icon: HeartPulse,
        accent: 'text-status-critical',
        clinical: true,
    },
    {
        key: 'pain',
        label: 'Pain',
        description: 'Score 0-10 with location',
        icon: AlertTriangle,
        accent: 'text-status-critical',
        clinical: true,
    },
    {
        key: 'weight',
        label: 'Weight',
        description: 'Body weight in kg',
        icon: Scale,
        accent: 'text-status-info',
        clinical: false,
    },
    {
        key: 'bowel',
        label: 'Bowel chart',
        description: 'Bristol stool type 1-7',
        icon: Activity,
        accent: 'text-status-warning',
        clinical: false,
    },
    {
        key: 'sleep',
        label: 'Sleep log',
        description: 'Bed/wake times + quality',
        icon: Moon,
        accent: 'text-status-info',
        clinical: false,
    },
    {
        key: 'fluid_intake',
        label: 'Fluid intake',
        description: 'Amount + fluid type',
        icon: Droplet,
        accent: 'text-status-info',
        clinical: false,
    },
    {
        key: 'general',
        label: 'General observation',
        description: 'Free-text note',
        icon: ClipboardList,
        accent: 'text-muted-foreground',
        clinical: false,
    },
];

function getObsType(key: ObsTypeKey): ObsTypeDef {
    return OBS_TYPES.find((t) => t.key === key) ?? OBS_TYPES[OBS_TYPES.length - 1];
}

const INITIAL_DATA: Record<ObsTypeKey, Record<string, string>> = {
    vitals: {
        systolic: '',
        diastolic: '',
        pulse: '',
        temperature: '',
        respiration_rate: '',
        o2_saturation: '',
    },
    weight: { weight_kg: '' },
    bowel: { bristol_type: '' },
    sleep: { bed_time: '', wake_time: '', quality: 'good', interruptions: '0' },
    fluid_intake: { amount_ml: '', fluid_type: 'water' },
    pain: { score: '', location: '' },
    general: {},
};

function FieldError({ message }: { message?: string }) {
    if (!message) return null;
    return <p className="mt-1 text-xs text-status-critical">{message}</p>;
}

// ─── Resident tile picker (Send-Kudos style) ─────────────────────────────────

function ResidentTilePicker({
    residents,
    onPick,
}: {
    residents: MyDayResident[];
    onPick: (resident: MyDayResident) => void;
}) {
    if (residents.length === 0) {
        return (
            <p className="rounded-lg border border-dashed bg-background/70 px-3 py-4 text-sm text-muted-foreground">
                No residents on this shift.
            </p>
        );
    }
    return (
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
            {residents.map((r) => (
                // eslint-disable-next-line no-restricted-syntax -- Send-Kudos-style tile card per POPUP_STYLE_GUIDE.md, not a shadcn Button.
                <button
                    key={r.id}
                    type="button"
                    onClick={() => onPick(r)}
                    className={cn(
                        'group flex items-start gap-2 rounded-xl border border-border bg-card/40 p-3 text-left transition-all',
                        'hover:border-primary/50 hover:bg-card focus:outline-none focus-visible:ring-2 focus-visible:ring-primary',
                    )}
                >
                    <span className="mt-0.5 shrink-0">
                        <ResidentDot hue={r.hue} initials={r.initials} />
                    </span>
                    <span className="min-w-0">
                        <span className="block truncate text-sm font-medium">
                            {r.name}
                        </span>
                        {r.care_note_preview ? (
                            <span className="block truncate text-xs text-muted-foreground">
                                {r.care_note_preview}
                            </span>
                        ) : (
                            <span className="block truncate text-xs text-muted-foreground">
                                Tap to record an observation
                            </span>
                        )}
                    </span>
                </button>
            ))}
        </div>
    );
}

// ─── Observation type picker (Send-Kudos style) ──────────────────────────────

function ObsTypePicker({
    value,
    onChange,
    canRecordClinical,
}: {
    value: ObsTypeKey;
    onChange: (next: ObsTypeKey) => void;
    canRecordClinical: boolean;
}) {
    const visible = OBS_TYPES.filter((t) => !t.clinical || canRecordClinical);
    return (
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
            {visible.map((t) => {
                const Icon = t.icon;
                const active = value === t.key;
                return (
                    // eslint-disable-next-line no-restricted-syntax -- Send-Kudos-style tile card per POPUP_STYLE_GUIDE.md, not a shadcn Button.
                    <button
                        key={t.key}
                        type="button"
                        onClick={() => onChange(t.key)}
                        aria-pressed={active}
                        className={cn(
                            'group flex items-start gap-2 rounded-xl border bg-card/40 p-3 text-left transition-all',
                            'hover:border-primary/50 hover:bg-card focus:outline-none focus-visible:ring-2 focus-visible:ring-primary',
                            active
                                ? 'border-primary bg-primary/10 ring-1 ring-primary/40'
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
                    </button>
                );
            })}
        </div>
    );
}

// ─── Resident locked-context card (when shift is 1:1) ────────────────────────

function ResidentLockedCard({ resident }: { resident: MyDayResident }) {
    return (
        <div className="flex items-start gap-3 rounded-xl border border-primary/40 bg-primary/10 p-3">
            <span className="mt-0.5 shrink-0">
                <ResidentDot hue={resident.hue} initials={resident.initials} />
            </span>
            <div className="min-w-0 flex-1">
                <div className="text-sm font-medium">{resident.name}</div>
                <p className="mt-0.5 text-xs text-muted-foreground">
                    Recording for this resident.
                </p>
            </div>
        </div>
    );
}

// ─── Record Observation dialog (one resident → one observation) ──────────────

export type RecordObservationDialogProps = {
    /** The resident this observation belongs to. */
    resident: MyDayResident;
    /** Shift the observation should be linked to (best practice — scopes the
     *  audit trail to the worker's current shift). */
    shiftId?: number | null;
    canRecordClinical: boolean;
    /** Default observation type when the dialog opens. */
    defaultType?: ObsTypeKey;
    /** Show the "Back to picker" link in the footer (multi-resident flow). */
    showBack?: boolean;
    onBack?: () => void;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onRecorded?: () => void;
};

export function RecordObservationDialog(props: RecordObservationDialogProps) {
    const { open, onOpenChange } = props;
    return (
        <Dialog open={open} onOpenChange={(next) => !next && onOpenChange(false)}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto"
                style={{ maxWidth: 'min(92vw, 720px)', width: 'min(92vw, 720px)' }}
            >
                {open ? <RecordObservationBody {...props} /> : null}
            </DialogContent>
        </Dialog>
    );
}

function RecordObservationBody({
    resident,
    shiftId,
    canRecordClinical,
    defaultType = 'general',
    showBack = false,
    onBack,
    onOpenChange,
    onRecorded,
}: RecordObservationDialogProps) {
    const [type, setType] = useState<ObsTypeKey>(
        defaultType === 'vitals' && !canRecordClinical ? 'general' : defaultType,
    );
    const [data, setData] = useState<Record<string, string>>({
        ...INITIAL_DATA[type],
    });
    const [notes, setNotes] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const handleTypeChange = useCallback((next: ObsTypeKey) => {
        setType(next);
        setData({ ...INITIAL_DATA[next] });
        setErrors({});
    }, []);

    const updateField = useCallback((key: string, value: string) => {
        setData((prev) => ({ ...prev, [key]: value }));
    }, []);

    const handleSubmit = useCallback(
        (e: React.FormEvent) => {
            e.preventDefault();
            setSubmitting(true);
            setErrors({});

            // Strip empty strings; coerce numerics so the API sees the right
            // PHP types. Mirrors the legacy ObservationRecordSheet behaviour.
            const cleaned: Record<string, string | number> = {};
            for (const [k, v] of Object.entries(data)) {
                if (v === '' || v == null) continue;
                cleaned[k] =
                    typeof v === 'string' && v.trim() !== '' && !isNaN(Number(v))
                        ? Number(v)
                        : v;
            }

            // Shift-scoped endpoint links the observation to today's shift in
            // the audit trail; falls back to the client-scoped endpoint when
            // there's no active shift (e.g. an admin recording out-of-shift).
            const url = shiftId
                ? `/shifts/${shiftId}/clinical/observations`
                : `/clients/${resident.id}/clinical/observations`;

            router.post(
                url,
                {
                    observation_type: type,
                    data: cleaned,
                    notes: notes || undefined,
                    // The client-scoped endpoint expects the client_id in the
                    // URL only; the shift-scoped endpoint also accepts an
                    // explicit client_id when the shift has multiple residents.
                    client_id: shiftId ? resident.id : undefined,
                },
                {
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess: () => {
                        onRecorded?.();
                        onOpenChange(false);
                    },
                    onError: (errs) => setErrors(errs as Record<string, string>),
                    onFinish: () => setSubmitting(false),
                },
            );
        },
        [data, notes, type, resident.id, shiftId, onOpenChange, onRecorded],
    );

    const selected = getObsType(type);
    const SelectedIcon = selected.icon;

    return (
        <form onSubmit={handleSubmit}>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <Stethoscope className="h-4 w-4 text-primary" />
                    Record observation
                </DialogTitle>
                <DialogDescription>
                    Choose the type of observation and capture the values.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-3 space-y-4">
                <ResidentLockedCard resident={resident} />

                <div>
                    <Label className="mb-2 block">
                        Observation type{' '}
                        <span className="text-status-critical">*</span>
                    </Label>
                    <ObsTypePicker
                        value={type}
                        onChange={handleTypeChange}
                        canRecordClinical={canRecordClinical}
                    />
                </div>

                <ObsTypeFields
                    type={type}
                    data={data}
                    onChange={updateField}
                    errors={errors}
                />

                <div>
                    <div className="mb-1 flex items-center justify-between gap-2">
                        <Label htmlFor="obs-notes">Notes (optional)</Label>
                        <DictateButton
                            value={notes}
                            onChange={setNotes}
                            fieldLabel="Observation notes"
                            disabled={submitting}
                        />
                    </div>
                    <Textarea
                        id="obs-notes"
                        rows={2}
                        value={notes}
                        onChange={(e) => setNotes(e.target.value)}
                        placeholder={`Anything else about this ${selected.label.toLowerCase()}?`}
                    />
                </div>

                {Object.keys(errors).length > 0 ? (
                    <div className="rounded-md border border-status-critical/30 bg-status-critical-bg p-3">
                        {Object.entries(errors).map(([k, msg]) => (
                            <p key={k} className="text-xs text-status-critical">
                                {msg}
                            </p>
                        ))}
                    </div>
                ) : null}
            </div>

            <DialogFooter className="mt-4">
                {showBack && onBack ? (
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={onBack}
                        disabled={submitting}
                    >
                        Back
                    </Button>
                ) : null}
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => onOpenChange(false)}
                    disabled={submitting}
                >
                    Cancel
                </Button>
                <Button type="submit" disabled={submitting}>
                    {submitting ? (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    ) : (
                        <SelectedIcon className={cn('mr-2 h-4 w-4', selected.accent)} />
                    )}
                    Save {selected.label.toLowerCase()}
                </Button>
            </DialogFooter>
        </form>
    );
}

// ─── Type-specific fields ────────────────────────────────────────────────────

function ObsTypeFields({
    type,
    data,
    onChange,
    errors,
}: {
    type: ObsTypeKey;
    data: Record<string, string>;
    onChange: (key: string, value: string) => void;
    errors: Record<string, string>;
}) {
    if (type === 'general') return null;

    const fieldGrid = (children: ReactNode) => (
        <div className="grid grid-cols-2 gap-3">{children}</div>
    );

    if (type === 'vitals') {
        return fieldGrid(
            <>
                <NumberField
                    label="Systolic"
                    value={data.systolic}
                    onChange={(v) => onChange('systolic', v)}
                    placeholder="120"
                    error={errors['data.systolic']}
                />
                <NumberField
                    label="Diastolic"
                    value={data.diastolic}
                    onChange={(v) => onChange('diastolic', v)}
                    placeholder="80"
                    error={errors['data.diastolic']}
                />
                <NumberField
                    label="Pulse (bpm)"
                    value={data.pulse}
                    onChange={(v) => onChange('pulse', v)}
                    placeholder="72"
                    error={errors['data.pulse']}
                />
                <NumberField
                    label="Temp (°C)"
                    value={data.temperature}
                    onChange={(v) => onChange('temperature', v)}
                    placeholder="36.8"
                    step="0.1"
                    error={errors['data.temperature']}
                />
                <NumberField
                    label="Resp rate"
                    value={data.respiration_rate}
                    onChange={(v) => onChange('respiration_rate', v)}
                    placeholder="16"
                    error={errors['data.respiration_rate']}
                />
                <NumberField
                    label="O₂ sat (%)"
                    value={data.o2_saturation}
                    onChange={(v) => onChange('o2_saturation', v)}
                    placeholder="98"
                    error={errors['data.o2_saturation']}
                />
            </>,
        );
    }

    if (type === 'weight') {
        return (
            <div>
                <NumberField
                    label="Weight (kg)"
                    value={data.weight_kg}
                    onChange={(v) => onChange('weight_kg', v)}
                    placeholder="72.5"
                    step="0.1"
                    error={errors['data.weight_kg']}
                />
            </div>
        );
    }

    if (type === 'bowel') {
        return (
            <div>
                <Label className="mb-1 block">Bristol stool type</Label>
                <Select
                    value={String(data.bristol_type)}
                    onValueChange={(v) => onChange('bristol_type', v)}
                >
                    <SelectTrigger>
                        <SelectValue placeholder="Select type" />
                    </SelectTrigger>
                    <SelectContent>
                        {[1, 2, 3, 4, 5, 6, 7].map((n) => (
                            <SelectItem key={n} value={String(n)}>
                                Type {n}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <FieldError message={errors['data.bristol_type']} />
            </div>
        );
    }

    if (type === 'sleep') {
        return fieldGrid(
            <>
                <div>
                    <Label className="mb-1 block">Bed time</Label>
                    <Input
                        type="time"
                        value={data.bed_time}
                        onChange={(e) => onChange('bed_time', e.target.value)}
                    />
                    <FieldError message={errors['data.bed_time']} />
                </div>
                <div>
                    <Label className="mb-1 block">Wake time</Label>
                    <Input
                        type="time"
                        value={data.wake_time}
                        onChange={(e) => onChange('wake_time', e.target.value)}
                    />
                    <FieldError message={errors['data.wake_time']} />
                </div>
                <div>
                    <Label className="mb-1 block">Quality</Label>
                    <Select
                        value={data.quality}
                        onValueChange={(v) => onChange('quality', v)}
                    >
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="good">Good</SelectItem>
                            <SelectItem value="fair">Fair</SelectItem>
                            <SelectItem value="poor">Poor</SelectItem>
                        </SelectContent>
                    </Select>
                    <FieldError message={errors['data.quality']} />
                </div>
                <NumberField
                    label="Interruptions"
                    value={data.interruptions}
                    onChange={(v) => onChange('interruptions', v)}
                    placeholder="0"
                    error={errors['data.interruptions']}
                />
            </>,
        );
    }

    if (type === 'fluid_intake') {
        return fieldGrid(
            <>
                <NumberField
                    label="Amount (ml)"
                    value={data.amount_ml}
                    onChange={(v) => onChange('amount_ml', v)}
                    placeholder="250"
                    error={errors['data.amount_ml']}
                />
                <div>
                    <Label className="mb-1 block">Fluid type</Label>
                    <Select
                        value={data.fluid_type}
                        onValueChange={(v) => onChange('fluid_type', v)}
                    >
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="water">Water</SelectItem>
                            <SelectItem value="tea">Tea</SelectItem>
                            <SelectItem value="coffee">Coffee</SelectItem>
                            <SelectItem value="juice">Juice</SelectItem>
                            <SelectItem value="milk">Milk</SelectItem>
                            <SelectItem value="other">Other</SelectItem>
                        </SelectContent>
                    </Select>
                    <FieldError message={errors['data.fluid_type']} />
                </div>
            </>,
        );
    }

    if (type === 'pain') {
        return fieldGrid(
            <>
                <NumberField
                    label="Pain score (0-10)"
                    value={data.score}
                    onChange={(v) => onChange('score', v)}
                    placeholder="0"
                    error={errors['data.score']}
                />
                <div>
                    <Label className="mb-1 block">Location</Label>
                    <Input
                        value={data.location}
                        onChange={(e) => onChange('location', e.target.value)}
                        placeholder="e.g. lower back"
                    />
                    <FieldError message={errors['data.location']} />
                </div>
            </>,
        );
    }

    return null;
}

function NumberField({
    label,
    value,
    onChange,
    placeholder,
    step,
    error,
}: {
    label: string;
    value: string;
    onChange: (next: string) => void;
    placeholder?: string;
    step?: string;
    error?: string;
}) {
    return (
        <div>
            <Label className="mb-1 block">{label}</Label>
            <Input
                type="number"
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder={placeholder}
                step={step}
            />
            <FieldError message={error} />
        </div>
    );
}

// ─── Vitals picker dialog (resident picker → record) ─────────────────────────

export type VitalsRecordDialogProps = {
    residents: MyDayResident[];
    shiftId?: number | null;
    canRecordObservation: boolean;
    canRecordClinical: boolean;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/**
 * Two-step popup: pick a resident → record an observation. On 1:1 shifts the
 * picker step is skipped. Both steps are standard centred Dialogs per the
 * popup style guide (no Sheets, no side-load).
 */
export function VitalsRecordDialog({
    residents,
    shiftId,
    canRecordObservation,
    canRecordClinical,
    open,
    onOpenChange,
}: VitalsRecordDialogProps) {
    const [selected, setSelected] = useState<MyDayResident | null>(null);

    // Reset on close; auto-select on 1:1.
    useEffect(() => {
        if (!open) {
            setSelected(null);
            return;
        }
        if (residents.length === 1 && selected == null) {
            setSelected(residents[0]);
        }
    }, [open, residents, selected]);

    const showPicker = open && selected == null;
    const showRecord = open && selected != null && canRecordObservation;
    const showNoPerm = open && selected != null && !canRecordObservation;

    return (
        <>
            {/* Picker (multi-resident shifts) */}
            <Dialog
                open={showPicker}
                onOpenChange={(next) => !next && onOpenChange(false)}
            >
                <DialogContent
                    style={{ maxWidth: 'min(92vw, 720px)', width: 'min(92vw, 720px)' }}
                    data-test="my-day-vitals-picker"
                >
                    {showPicker ? (
                        <>
                            <DialogHeader>
                                <DialogTitle className="flex items-center gap-2">
                                    <Stethoscope className="h-4 w-4 text-primary" />
                                    Record vitals & observations
                                </DialogTitle>
                                <DialogDescription>
                                    Choose a resident to record an observation for.
                                </DialogDescription>
                            </DialogHeader>
                            <div className="mt-3">
                                <ResidentTilePicker
                                    residents={residents}
                                    onPick={setSelected}
                                />
                            </div>
                            <DialogFooter className="mt-4">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => onOpenChange(false)}
                                >
                                    Cancel
                                </Button>
                            </DialogFooter>
                        </>
                    ) : null}
                </DialogContent>
            </Dialog>

            {/* Record (per-resident form) */}
            {selected ? (
                <RecordObservationDialog
                    resident={selected}
                    shiftId={shiftId ?? null}
                    canRecordClinical={canRecordClinical}
                    showBack={residents.length > 1}
                    onBack={() => setSelected(null)}
                    open={showRecord}
                    onOpenChange={(next) => {
                        if (!next) {
                            // Multi-resident: cancel returns to picker.
                            if (residents.length > 1) {
                                setSelected(null);
                            } else {
                                onOpenChange(false);
                            }
                        }
                    }}
                    onRecorded={() => onOpenChange(false)}
                />
            ) : null}

            {/* No-permission fallback — keeps the flow honest if the parent's
                trigger isn't permission-gated. */}
            <Dialog
                open={showNoPerm}
                onOpenChange={(next) => !next && onOpenChange(false)}
            >
                <DialogContent
                    style={{ maxWidth: 'min(92vw, 480px)', width: 'min(92vw, 480px)' }}
                >
                    {showNoPerm ? (
                        <>
                            <DialogHeader>
                                <DialogTitle>Recording not available</DialogTitle>
                                <DialogDescription>
                                    You don&rsquo;t have permission to record
                                    observations. Ask your manager for the
                                    <span className="font-medium">
                                        {' '}
                                        clinical.observations.record{' '}
                                    </span>
                                    capability.
                                </DialogDescription>
                            </DialogHeader>
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => onOpenChange(false)}
                                >
                                    Close
                                </Button>
                            </DialogFooter>
                        </>
                    ) : null}
                </DialogContent>
            </Dialog>
        </>
    );
}

// ─── Write Handover dialog ───────────────────────────────────────────────────

export type WriteHandoverDialogProps = {
    shiftId: number | null;
    alreadySubmitted?: boolean;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/**
 * Popup version of HandoverWriteSheet for the desktop /my-day. The form
 * itself reuses `HandoverWriteForm` so the back-end contract is identical.
 */
export function WriteHandoverDialog(props: WriteHandoverDialogProps) {
    const { open, onOpenChange } = props;
    return (
        <Dialog open={open} onOpenChange={(next) => !next && onOpenChange(false)}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto"
                style={{ maxWidth: 'min(92vw, 720px)', width: 'min(92vw, 720px)' }}
            >
                {open ? <WriteHandoverBody {...props} /> : null}
            </DialogContent>
        </Dialog>
    );
}

function WriteHandoverBody({
    shiftId,
    alreadySubmitted,
    onOpenChange,
}: WriteHandoverDialogProps) {
    const [value, setValue] = useState<HandoverWriteValue>(emptyHandoverWriteValue);
    const [submitting, setSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!shiftId || alreadySubmitted) {
            onOpenChange(false);
            return;
        }
        setSubmitting(true);
        setErrors({});
        router.post(
            '/attendance/handover',
            {
                shift_id: shiftId,
                meds_completed: value.meds_completed,
                shift_rating: value.shift_rating,
                handover_notes: value.handover_notes,
                follow_up_needed: value.follow_up_needed,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    setValue(emptyHandoverWriteValue);
                    onOpenChange(false);
                },
                onError: (errs) => setErrors(errs as Record<string, string>),
                onFinish: () => setSubmitting(false),
            },
        );
    };

    return (
        <form onSubmit={handleSubmit}>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <FileText className="h-4 w-4 text-primary" />
                    Shift note
                </DialogTitle>
                <DialogDescription>
                    Capture what the next support worker should know.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-3">
                <HandoverWriteForm
                    value={value}
                    onChange={setValue}
                    disabled={submitting}
                    alreadySubmitted={alreadySubmitted}
                />
            </div>

            {Object.keys(errors).length > 0 ? (
                <div className="mt-3 rounded-md border border-status-critical/30 bg-status-critical-bg p-3">
                    {Object.entries(errors).map(([k, msg]) => (
                        <p key={k} className="text-xs text-status-critical">
                            {msg}
                        </p>
                    ))}
                </div>
            ) : null}

            <DialogFooter className="mt-4">
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => onOpenChange(false)}
                    disabled={submitting}
                >
                    Cancel
                </Button>
                <Button type="submit" disabled={submitting || !shiftId}>
                    {submitting ? (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    ) : (
                        <Heart className="mr-2 h-4 w-4" />
                    )}
                    Save handover
                </Button>
            </DialogFooter>
        </form>
    );
}

// ─── Timesheet review dialog (per-client allocations) ────────────────────────

type AllocationMethodDef = {
    key: TimesheetAllocationMethod;
    label: string;
    description: string;
    icon: ComponentType<{ className?: string }>;
    accent: string;
};

const ALLOCATION_METHODS: AllocationMethodDef[] = [
    {
        key: 'single',
        label: 'Single client',
        description: 'All hours to one client (1:1 shift).',
        icon: UserRound,
        accent: 'text-status-info',
    },
    {
        key: 'residential_house',
        label: 'Residential house',
        description: 'House-level billing; system splits per resident.',
        icon: Home,
        accent: 'text-primary',
    },
    {
        key: 'equal_split',
        label: 'Equal split',
        description: 'Same hours to every client on this shift.',
        icon: Users,
        accent: 'text-status-info',
    },
    {
        key: 'manual',
        label: 'Manual',
        description: 'Custom hours per client (weighted support).',
        icon: ClipboardList,
        accent: 'text-status-warning',
    },
    {
        key: 'time_segmented',
        label: 'Time segments',
        description: 'Sequential 1:1 visits with start/end per client.',
        icon: Clock,
        accent: 'text-status-warning',
    },
];

function getAllocationMethod(key: TimesheetAllocationMethod): AllocationMethodDef {
    return ALLOCATION_METHODS.find((m) => m.key === key) ?? ALLOCATION_METHODS[0];
}

// Local editable row — shape mirrors MyDayTimesheetClientAllocation but with
// string-typed fields for form inputs (so partial edits don't NaN).
type AllocationRow = {
    id: number | null;
    client_id: number;
    client_name: string;
    hours_text: string;
    starts_at: string;
    ends_at: string;
    notes: string;
};

function rowFromAllocation(
    a: MyDayTimesheetClientAllocation,
    candidate?: MyDayTimesheetClientCandidate,
): AllocationRow {
    return {
        id: a.id,
        client_id: a.client_id,
        client_name: candidate?.name ?? `Client ${a.client_id}`,
        hours_text: a.hours.toFixed(2),
        starts_at: a.starts_at?.slice(11, 16) ?? '',
        ends_at: a.ends_at?.slice(11, 16) ?? '',
        notes: a.notes ?? '',
    };
}

function rowFromCandidate(
    c: MyDayTimesheetClientCandidate,
    hours: number,
): AllocationRow {
    return {
        id: null,
        client_id: c.id,
        client_name: c.name,
        hours_text: hours.toFixed(2),
        starts_at: '',
        ends_at: '',
        notes: '',
    };
}

export type TimesheetReviewDialogProps = {
    timesheet: MyDayTimesheet | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/**
 * Per-client timesheet review + submit popup.
 *
 * Lets the worker break their total shift hours down across every client
 * they supported on the shift (residents at the site, group-shift clients,
 * etc.). Picking an allocation method tile (residential / equal / manual /
 * time-segmented / single) changes how the rows behave; the live sum at the
 * top mirrors the timesheet's total so the worker can see when their split
 * doesn't balance.
 *
 * On submit, POSTs to `/my-tasks/timesheet/{id}/submit` with both the
 * status flip and the allocation payload — MyDayActionsController
 * validates the rows in a transaction so the timesheet can't end up
 * submitted-but-unallocated.
 */
export function TimesheetReviewDialog(props: TimesheetReviewDialogProps) {
    const { timesheet, open, onOpenChange } = props;
    return (
        <Dialog open={open && !!timesheet} onOpenChange={(next) => !next && onOpenChange(false)}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto"
                style={{ maxWidth: 'min(92vw, 900px)', width: 'min(92vw, 900px)' }}
            >
                {open && timesheet ? (
                    <TimesheetReviewBody
                        timesheet={timesheet}
                        onOpenChange={onOpenChange}
                    />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

function TimesheetReviewBody({
    timesheet,
    onOpenChange,
}: {
    timesheet: MyDayTimesheet;
    onOpenChange: (open: boolean) => void;
}) {
    const candidates = timesheet.clients_candidates ?? [];
    const totalHours = Number(timesheet.hours) || 0;

    // Default the method picker to whatever the server inferred — usually
    // "single" for legacy 1:1 timesheets, "residential_house" when the site
    // already flags it, etc.
    const initialMethod: TimesheetAllocationMethod =
        timesheet.allocation_method
        ?? (timesheet.is_residential_billable
            ? 'residential_house'
            : candidates.length > 1
              ? 'equal_split'
              : 'single');

    const [method, setMethod] = useState<TimesheetAllocationMethod>(initialMethod);

    // Seed the editable rows from the server payload. For methods that
    // require every candidate to appear (residential, equal_split,
    // time_segmented), backfill missing clients with zero-hour rows so the
    // worker can fill them in.
    const seedRows = (m: TimesheetAllocationMethod): AllocationRow[] => {
        const byId = new Map(candidates.map((c) => [c.id, c]));
        const existing = (timesheet.client_allocations ?? []).map((a) =>
            rowFromAllocation(a, byId.get(a.client_id)),
        );

        if (m === 'single') {
            const primary = candidates.find((c) => c.is_primary) ?? candidates[0];
            if (!primary) return existing;
            return [
                existing.find((e) => e.client_id === primary.id) ?? rowFromCandidate(primary, totalHours),
            ];
        }

        if (m === 'residential_house' || m === 'equal_split' || m === 'time_segmented') {
            const per = candidates.length > 0 ? totalHours / candidates.length : totalHours;
            return candidates.map((c) => {
                const found = existing.find((e) => e.client_id === c.id);
                if (found) {
                    return {
                        ...found,
                        hours_text:
                            m === 'equal_split' || m === 'residential_house'
                                ? per.toFixed(2)
                                : found.hours_text,
                    };
                }
                return rowFromCandidate(c, per);
            });
        }

        // manual: keep whatever rows we have, drop zero-hour ones the worker
        // hadn't touched, but always ensure at least one row exists.
        if (existing.length > 0) return existing;
        const primary = candidates.find((c) => c.is_primary) ?? candidates[0];
        return primary ? [rowFromCandidate(primary, totalHours)] : [];
    };

    const [rows, setRows] = useState<AllocationRow[]>(() => seedRows(initialMethod));
    const [activeTab, setActiveTab] = useState<string>(
        rows[0] ? String(rows[0].client_id) : '',
    );
    const [submitting, setSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const handleMethodChange = useCallback(
        (next: TimesheetAllocationMethod) => {
            setMethod(next);
            const newRows = seedRows(next);
            setRows(newRows);
            if (newRows.length > 0) {
                setActiveTab(String(newRows[0].client_id));
            }
            setErrors({});
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps -- seedRows reads the closure-captured timesheet/candidates which don't change while the dialog is open
        [timesheet],
    );

    const updateRow = useCallback(
        (clientId: number, patch: Partial<AllocationRow>) => {
            setRows((prev) =>
                prev.map((r) => (r.client_id === clientId ? { ...r, ...patch } : r)),
            );
        },
        [],
    );

    const applyEqualSplit = useCallback(() => {
        const per = rows.length > 0 ? totalHours / rows.length : 0;
        setRows((prev) =>
            prev.map((r) => ({ ...r, hours_text: per.toFixed(2) })),
        );
    }, [rows.length, totalHours]);

    // Live total + variance.
    const allocatedTotal = useMemo(
        () =>
            rows.reduce(
                (sum, r) => sum + (Number.parseFloat(r.hours_text) || 0),
                0,
            ),
        [rows],
    );
    const remaining = totalHours - allocatedTotal;
    const isResidential = method === 'residential_house';
    const sumOk = isResidential || Math.abs(remaining) <= 0.02;

    const handleSubmit = useCallback(() => {
        setSubmitting(true);
        setErrors({});

        const payload = rows.map((r, i) => ({
            client_id: r.client_id,
            hours: Number.parseFloat(r.hours_text) || 0,
            allocation_method: method,
            starts_at:
                method === 'time_segmented' && r.starts_at && timesheet.work_date_iso
                    ? `${timesheet.work_date_iso}T${r.starts_at}:00`
                    : null,
            ends_at:
                method === 'time_segmented' && r.ends_at && timesheet.work_date_iso
                    ? `${timesheet.work_date_iso}T${r.ends_at}:00`
                    : null,
            notes: r.notes || null,
            sort_order: i,
        }));

        router.post(
            `/my-tasks/timesheet/${timesheet.id}/submit`,
            { client_allocations: payload },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => onOpenChange(false),
                onError: (errs) => setErrors(errs as Record<string, string>),
                onFinish: () => setSubmitting(false),
            },
        );
    }, [rows, method, timesheet.id, timesheet.work_date_iso, onOpenChange]);

    return (
        <>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <FileText className="h-4 w-4 text-primary" />
                    Review timesheet
                </DialogTitle>
                <DialogDescription>
                    Confirm how this shift&rsquo;s hours split across the people
                    you supported, then submit for approval.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-3 space-y-4">
                {/* Locked context — timesheet meta */}
                <div className="flex flex-wrap items-center gap-3 rounded-xl border border-primary/40 bg-primary/10 p-3">
                    <span className="shrink-0 rounded-lg bg-background/60 p-1.5">
                        <Clock className="h-4 w-4 text-primary" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <div className="text-sm font-medium">
                            {timesheet.work_date}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            {totalHours.toFixed(2)}h total
                            {timesheet.starts_at && timesheet.ends_at
                                ? ` · ${formatHM(timesheet.starts_at)} – ${formatHM(timesheet.ends_at)}`
                                : ''}
                            {timesheet.break_minutes > 0
                                ? ` · ${timesheet.break_minutes}m break`
                                : ''}
                        </div>
                    </div>
                    {timesheet.status === 'returned' && timesheet.return_notes ? (
                        <Badge
                            variant="outline"
                            className="border-status-warning/30 bg-status-warning-bg text-[10px] text-status-warning"
                        >
                            Returned: {timesheet.return_notes}
                        </Badge>
                    ) : null}
                </div>

                {/* Allocation method tile picker */}
                <div>
                    <Label className="mb-2 block">
                        How is this time allocated?{' '}
                        <span className="text-status-critical">*</span>
                    </Label>
                    <AllocationMethodPicker
                        value={method}
                        onChange={handleMethodChange}
                        disableMultiClient={candidates.length <= 1}
                    />
                </div>

                {/* Live total */}
                <div
                    className={cn(
                        'flex items-center justify-between rounded-lg border px-3 py-2 text-sm',
                        sumOk
                            ? 'border-status-success/30 bg-status-success-bg text-status-success'
                            : 'border-status-warning/30 bg-status-warning-bg text-status-warning',
                    )}
                >
                    <div className="flex items-center gap-2">
                        {sumOk ? (
                            <CheckCircle2 className="h-4 w-4" />
                        ) : (
                            <AlertTriangle className="h-4 w-4" />
                        )}
                        <span>
                            {isResidential
                                ? 'House-level billing — per-resident split handled downstream.'
                                : sumOk
                                  ? `Sum balances at ${allocatedTotal.toFixed(2)}h of ${totalHours.toFixed(2)}h.`
                                  : `${allocatedTotal.toFixed(2)}h allocated of ${totalHours.toFixed(2)}h — ${remaining > 0 ? `${remaining.toFixed(2)}h left` : `${Math.abs(remaining).toFixed(2)}h over`}.`}
                        </span>
                    </div>
                    {(method === 'equal_split' || method === 'residential_house') && rows.length > 1 ? (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={applyEqualSplit}
                        >
                            Apply equal split
                        </Button>
                    ) : null}
                </div>

                {/* Per-client tabs */}
                {rows.length > 1 ? (
                    <TabsRoot value={activeTab} onValueChange={setActiveTab}>
                        <TabsList className="flex w-full flex-wrap justify-start gap-1">
                            {rows.map((r) => (
                                <TabsTrigger
                                    key={r.client_id}
                                    value={String(r.client_id)}
                                    className="flex items-center gap-1.5"
                                >
                                    <span>
                                        <ResidentDot
                                            hue={residentHue(r.client_id)}
                                            initials={initialsFor(r.client_name)}
                                        />
                                    </span>
                                    <span className="truncate">{r.client_name}</span>
                                    <span className="ml-1 text-[10px] text-muted-foreground">
                                        {(Number.parseFloat(r.hours_text) || 0).toFixed(2)}h
                                    </span>
                                </TabsTrigger>
                            ))}
                        </TabsList>
                        {rows.map((r) => (
                            <TabsContent
                                key={r.client_id}
                                value={String(r.client_id)}
                                className="mt-3"
                            >
                                <AllocationRowEditor
                                    row={r}
                                    method={method}
                                    onChange={(patch) => updateRow(r.client_id, patch)}
                                    errors={errors}
                                />
                            </TabsContent>
                        ))}
                    </TabsRoot>
                ) : rows.length === 1 ? (
                    // Single-row methods (1:1 shift, single allocation) — skip
                    // the tabs entirely and just render the editor.
                    <AllocationRowEditor
                        row={rows[0]}
                        method={method}
                        onChange={(patch) => updateRow(rows[0].client_id, patch)}
                        errors={errors}
                    />
                ) : (
                    <p className="rounded-lg border border-dashed bg-background/70 px-3 py-4 text-sm text-muted-foreground">
                        No clients on this timesheet&rsquo;s shift.
                    </p>
                )}

                {Object.keys(errors).length > 0 ? (
                    <div className="rounded-md border border-status-critical/30 bg-status-critical-bg p-3">
                        {Object.entries(errors).map(([k, msg]) => (
                            <p key={k} className="text-xs text-status-critical">
                                {msg}
                            </p>
                        ))}
                    </div>
                ) : null}
            </div>

            <DialogFooter className="mt-4">
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => onOpenChange(false)}
                    disabled={submitting}
                >
                    Cancel
                </Button>
                <Button
                    type="button"
                    onClick={handleSubmit}
                    disabled={submitting || rows.length === 0 || !sumOk}
                >
                    {submitting ? (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    ) : (
                        <CheckCircle2 className="mr-2 h-4 w-4" />
                    )}
                    {timesheet.status === 'returned' ? 'Save and resubmit' : 'Submit timesheet'}
                </Button>
            </DialogFooter>
        </>
    );
}

function AllocationMethodPicker({
    value,
    onChange,
    disableMultiClient,
}: {
    value: TimesheetAllocationMethod;
    onChange: (v: TimesheetAllocationMethod) => void;
    disableMultiClient: boolean;
}) {
    return (
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
            {ALLOCATION_METHODS.map((m) => {
                const Icon = m.icon;
                const active = value === m.key;
                const disabled = disableMultiClient && m.key !== 'single';
                return (
                    // eslint-disable-next-line no-restricted-syntax -- Send-Kudos-style tile per POPUP_STYLE_GUIDE.md.
                    <button
                        key={m.key}
                        type="button"
                        disabled={disabled}
                        onClick={() => onChange(m.key)}
                        aria-pressed={active}
                        className={cn(
                            'group flex items-start gap-2 rounded-xl border bg-card/40 p-3 text-left transition-all',
                            'hover:border-primary/50 hover:bg-card focus:outline-none focus-visible:ring-2 focus-visible:ring-primary',
                            active
                                ? 'border-primary bg-primary/10 ring-1 ring-primary/40'
                                : 'border-border',
                            disabled && 'cursor-not-allowed opacity-50 hover:bg-card/40 hover:border-border',
                        )}
                    >
                        <span className="mt-0.5 shrink-0 rounded-lg bg-background/60 p-1.5">
                            <Icon className={cn('h-4 w-4', m.accent)} />
                        </span>
                        <span className="min-w-0">
                            <span className="block truncate text-sm font-medium">
                                {m.label}
                            </span>
                            <span className="block truncate text-xs text-muted-foreground">
                                {m.description}
                            </span>
                        </span>
                    </button>
                );
            })}
        </div>
    );
}

function AllocationRowEditor({
    row,
    method,
    onChange,
    errors,
}: {
    row: AllocationRow;
    method: TimesheetAllocationMethod;
    onChange: (patch: Partial<AllocationRow>) => void;
    errors: Record<string, string>;
}) {
    const hoursLocked = method === 'residential_house' || method === 'equal_split';
    return (
        <div className="space-y-3">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                    <Label className="mb-1 block">
                        Hours{' '}
                        <span className="text-status-critical">*</span>
                    </Label>
                    <Input
                        type="number"
                        step="0.25"
                        min="0"
                        max="24"
                        value={row.hours_text}
                        onChange={(e) => onChange({ hours_text: e.target.value })}
                        disabled={hoursLocked}
                        placeholder="0.00"
                    />
                    {hoursLocked ? (
                        <p className="mt-1 text-[11px] text-muted-foreground">
                            Use &ldquo;Apply equal split&rdquo; or switch to{' '}
                            <span className="font-medium">Manual</span> to edit.
                        </p>
                    ) : null}
                </div>

                {method === 'time_segmented' ? (
                    <>
                        <div>
                            <Label className="mb-1 block">Start</Label>
                            <Input
                                type="time"
                                value={row.starts_at}
                                onChange={(e) => onChange({ starts_at: e.target.value })}
                            />
                        </div>
                        <div className="sm:col-span-1">
                            <Label className="mb-1 block">End</Label>
                            <Input
                                type="time"
                                value={row.ends_at}
                                onChange={(e) => onChange({ ends_at: e.target.value })}
                            />
                        </div>
                    </>
                ) : null}
            </div>

            <div>
                <div className="mb-1 flex items-center justify-between gap-2">
                    <Label htmlFor={`alloc-notes-${row.client_id}`}>
                        Notes for {row.client_name} (optional)
                    </Label>
                </div>
                <Textarea
                    id={`alloc-notes-${row.client_id}`}
                    rows={2}
                    value={row.notes}
                    onChange={(e) => onChange({ notes: e.target.value })}
                    placeholder={`Anything to note about ${row.client_name.split(' ')[0]}'s support?`}
                />
            </div>

            {errors[`client_allocations.${row.client_id}.hours`] ? (
                <p className="text-xs text-status-critical">
                    {errors[`client_allocations.${row.client_id}.hours`]}
                </p>
            ) : null}
        </div>
    );
}

function initialsFor(name: string): string {
    const trimmed = name.trim();
    if (!trimmed) return '?';
    const [first = '', ...rest] = trimmed.split(/\s+/);
    return residentInitials(first, rest.join(' '));
}

function formatHM(iso: string): string {
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}
