import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { router } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

export const OBSERVATION_TYPES = [
    { value: 'vitals', label: 'Vital Signs', clinical: true },
    { value: 'weight', label: 'Weight', clinical: false },
    { value: 'bowel', label: 'Bowel Chart', clinical: false },
    { value: 'sleep', label: 'Sleep Log', clinical: false },
    { value: 'fluid_intake', label: 'Fluid Intake', clinical: false },
    { value: 'pain', label: 'Pain Assessment', clinical: true },
    { value: 'general', label: 'General Observation', clinical: false },
] as const;

type ObsType = (typeof OBSERVATION_TYPES)[number]['value'];

interface Props {
    clientId: number;
    shiftId?: number;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    canRecordClinical: boolean;
    defaultType?: string | null;
    protocolScheduleId?: number | null;
    onRecorded?: () => void;
}

const INITIAL_DATA: Record<ObsType, Record<string, any>> = {
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

export default function ObservationRecordSheet({
    clientId,
    shiftId,
    open,
    onOpenChange,
    canRecordClinical,
    defaultType,
    protocolScheduleId,
    onRecorded,
}: Props) {
    const initialType = (defaultType as ObsType) || 'general';
    const [type, setType] = useState<ObsType>(initialType);
    const [data, setData] = useState<Record<string, any>>(INITIAL_DATA[initialType] ?? INITIAL_DATA.general);
    const [notes, setNotes] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    // Reset to defaultType when sheet opens with a new type
    useEffect(() => {
        if (open && defaultType) {
            const dt = defaultType as ObsType;
            if (dt !== type) {
                setType(dt);
                setData({ ...INITIAL_DATA[dt] });
            }
        }
    }, [open, defaultType]); // eslint-disable-line react-hooks/exhaustive-deps

    const availableTypes = OBSERVATION_TYPES.filter(
        (t) => !t.clinical || canRecordClinical,
    );

    const handleTypeChange = useCallback((val: string) => {
        const newType = val as ObsType;
        setType(newType);
        setData({ ...INITIAL_DATA[newType] });
        setErrors({});
    }, []);

    const updateData = useCallback(
        (key: string, value: any) => {
            setData((prev) => ({ ...prev, [key]: value }));
        },
        [],
    );

    const handleSubmit = useCallback(() => {
        setSubmitting(true);
        setErrors({});

        // Clean empty strings from data
        const cleanedData: Record<string, any> = {};
        for (const [k, v] of Object.entries(data)) {
            if (v !== '' && v !== null && v !== undefined) {
                cleanedData[k] = typeof v === 'string' && !isNaN(Number(v)) && v.trim() !== ''
                    ? Number(v)
                    : v;
            }
        }

        const url = shiftId
            ? `/shifts/${shiftId}/clinical/observations`
            : `/clients/${clientId}/clinical/observations`;

        router.post(
            url,
            {
                observation_type: type,
                data: cleanedData,
                notes: notes || undefined,
                protocol_schedule_id: protocolScheduleId || undefined,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    onOpenChange(false);
                    onRecorded?.();
                    setType('general');
                    setData(INITIAL_DATA.general);
                    setNotes('');
                },
                onError: (errs) => {
                    setErrors(errs as Record<string, string>);
                },
                onFinish: () => {
                    setSubmitting(false);
                },
            },
        );
    }, [clientId, shiftId, type, data, notes, protocolScheduleId, onOpenChange]);

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent className="sm:max-w-lg overflow-y-auto">
                <SheetHeader>
                    <SheetTitle>Record Observation</SheetTitle>
                    <SheetDescription>
                        Record a clinical observation for this client.
                    </SheetDescription>
                </SheetHeader>

                <div className="mt-4 space-y-4">
                    {/* Type selector */}
                    <div className="space-y-2">
                        <Label>Observation Type</Label>
                        <Select value={type} onValueChange={handleTypeChange}>
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {availableTypes.map((t) => (
                                    <SelectItem key={t.value} value={t.value}>
                                        {t.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    {/* Type-specific fields */}
                    {type === 'vitals' && (
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1">
                                <Label className="text-xs">Systolic</Label>
                                <Input
                                    type="number"
                                    placeholder="120"
                                    value={data.systolic}
                                    onChange={(e) => updateData('systolic', e.target.value)}
                                />
                            </div>
                            <div className="space-y-1">
                                <Label className="text-xs">Diastolic</Label>
                                <Input
                                    type="number"
                                    placeholder="80"
                                    value={data.diastolic}
                                    onChange={(e) => updateData('diastolic', e.target.value)}
                                />
                            </div>
                            <div className="space-y-1">
                                <Label className="text-xs">Pulse (bpm)</Label>
                                <Input
                                    type="number"
                                    placeholder="72"
                                    value={data.pulse}
                                    onChange={(e) => updateData('pulse', e.target.value)}
                                />
                            </div>
                            <div className="space-y-1">
                                <Label className="text-xs">Temp (&deg;C)</Label>
                                <Input
                                    type="number"
                                    step="0.1"
                                    placeholder="36.8"
                                    value={data.temperature}
                                    onChange={(e) => updateData('temperature', e.target.value)}
                                />
                            </div>
                            <div className="space-y-1">
                                <Label className="text-xs">Resp Rate</Label>
                                <Input
                                    type="number"
                                    placeholder="16"
                                    value={data.respiration_rate}
                                    onChange={(e) => updateData('respiration_rate', e.target.value)}
                                />
                            </div>
                            <div className="space-y-1">
                                <Label className="text-xs">O&#8322; Sat (%)</Label>
                                <Input
                                    type="number"
                                    placeholder="98"
                                    value={data.o2_saturation}
                                    onChange={(e) => updateData('o2_saturation', e.target.value)}
                                />
                            </div>
                        </div>
                    )}

                    {type === 'weight' && (
                        <div className="space-y-1">
                            <Label className="text-xs">Weight (kg)</Label>
                            <Input
                                type="number"
                                step="0.1"
                                placeholder="72.5"
                                value={data.weight_kg}
                                onChange={(e) => updateData('weight_kg', e.target.value)}
                            />
                        </div>
                    )}

                    {type === 'bowel' && (
                        <div className="space-y-1">
                            <Label className="text-xs">Bristol Stool Type (1-7)</Label>
                            <Select
                                value={String(data.bristol_type)}
                                onValueChange={(v) => updateData('bristol_type', v)}
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
                        </div>
                    )}

                    {type === 'sleep' && (
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1">
                                <Label className="text-xs">Bed Time</Label>
                                <Input
                                    type="time"
                                    value={data.bed_time}
                                    onChange={(e) => updateData('bed_time', e.target.value)}
                                />
                            </div>
                            <div className="space-y-1">
                                <Label className="text-xs">Wake Time</Label>
                                <Input
                                    type="time"
                                    value={data.wake_time}
                                    onChange={(e) => updateData('wake_time', e.target.value)}
                                />
                            </div>
                            <div className="space-y-1">
                                <Label className="text-xs">Quality</Label>
                                <Select
                                    value={data.quality}
                                    onValueChange={(v) => updateData('quality', v)}
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
                            </div>
                            <div className="space-y-1">
                                <Label className="text-xs">Interruptions</Label>
                                <Input
                                    type="number"
                                    min="0"
                                    value={data.interruptions}
                                    onChange={(e) => updateData('interruptions', e.target.value)}
                                />
                            </div>
                        </div>
                    )}

                    {type === 'fluid_intake' && (
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1">
                                <Label className="text-xs">Amount (ml)</Label>
                                <Input
                                    type="number"
                                    placeholder="250"
                                    value={data.amount_ml}
                                    onChange={(e) => updateData('amount_ml', e.target.value)}
                                />
                            </div>
                            <div className="space-y-1">
                                <Label className="text-xs">Fluid Type</Label>
                                <Select
                                    value={data.fluid_type}
                                    onValueChange={(v) => updateData('fluid_type', v)}
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
                            </div>
                        </div>
                    )}

                    {type === 'pain' && (
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1">
                                <Label className="text-xs">Pain Score (0-10)</Label>
                                <Input
                                    type="number"
                                    min="0"
                                    max="10"
                                    placeholder="0"
                                    value={data.score}
                                    onChange={(e) => updateData('score', e.target.value)}
                                />
                            </div>
                            <div className="space-y-1">
                                <Label className="text-xs">Location</Label>
                                <Input
                                    placeholder="e.g. lower back"
                                    value={data.location}
                                    onChange={(e) => updateData('location', e.target.value)}
                                />
                            </div>
                        </div>
                    )}

                    {/* Notes (all types) */}
                    <div className="space-y-1">
                        <Label className="text-xs">Notes (optional)</Label>
                        <Textarea
                            placeholder="Additional notes..."
                            value={notes}
                            onChange={(e) => setNotes(e.target.value)}
                            rows={2}
                        />
                    </div>

                    {/* Validation errors */}
                    {Object.keys(errors).length > 0 && (
                        <div className="rounded-md border border-red-200 bg-red-50 p-3">
                            {Object.entries(errors).map(([key, msg]) => (
                                <p key={key} className="text-xs text-red-600">
                                    {msg}
                                </p>
                            ))}
                        </div>
                    )}
                </div>

                <SheetFooter className="mt-4">
                    <Button
                        variant="ghost"
                        onClick={() => onOpenChange(false)}
                        disabled={submitting}
                    >
                        Cancel
                    </Button>
                    <Button onClick={handleSubmit} disabled={submitting}>
                        {submitting ? 'Saving...' : 'Record Observation'}
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}
