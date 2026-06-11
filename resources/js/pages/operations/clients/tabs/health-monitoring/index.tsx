import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { Link, router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Calendar,
    Droplets,
    FileText,
    HeartPulse,
    Moon,
    Plus,
    Stethoscope,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useMemo, useState } from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

type Recorder = { id: number; name: string } | null;

type BowelEntry = {
    id: number;
    occurred_at?: string | null;
    bristol_type?: number | null;
    volume?: string | null;
    notes?: string | null;
    recorder?: Recorder;
};

type FluidEntry = {
    id: number;
    occurred_at?: string | null;
    direction?: 'in' | 'out' | string | null;
    fluid_type?: string | null;
    volume_ml?: number | null;
    notes?: string | null;
    recorder?: Recorder;
};

type SeizureEntry = {
    id: number;
    occurred_at?: string | null;
    duration_seconds?: number | null;
    seizure_type?: string | null;
    trigger?: string | null;
    response_taken?: string | null;
    recovery_notes?: string | null;
    escalated?: boolean;
    follow_up_action?: string | null;
    recorder?: Recorder;
};

type SleepEntry = {
    id: number;
    slept_at?: string | null;
    hours_slept?: number | null;
    quality?: 'good' | 'fair' | 'poor' | string | null;
    interruptions?: number | null;
    settled_by?: string | null;
    woke_at?: string | null;
    notes?: string | null;
    recorder?: Recorder;
};

export type HealthMonitoringData = {
    bowel?: BowelEntry[];
    fluid?: FluidEntry[];
    seizure?: SeizureEntry[];
    sleep?: SleepEntry[];
    sleep_summary?: {
        target_hours?: number | null;
        average_7_nights?: number | null;
        below_target?: boolean | null;
    };
};

type HealthMonitoringTabProps = {
    clientId: number;
    data: HealthMonitoringData;
    isLoading?: boolean;
};

type SectionKey =
    | 'fluid'
    | 'bowel'
    | 'seizure'
    | 'sleep'
    | 'appointments'
    | 'docs';

function dateLabel(value?: string | null) {
    if (!value) return 'No date';
    return new Intl.DateTimeFormat('en-NZ', {
        day: 'numeric',
        month: 'short',
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(value));
}

function dateKey(value?: string | null) {
    if (!value) return 'No date';
    return new Intl.DateTimeFormat('en-NZ', {
        day: '2-digit',
        month: 'short',
    }).format(new Date(value));
}

const sections: Array<{
    key: SectionKey;
    label: string;
    icon: typeof Activity;
}> = [
    { key: 'fluid', label: 'Fluid', icon: Droplets },
    { key: 'bowel', label: 'Bowel', icon: Stethoscope },
    { key: 'seizure', label: 'Seizure', icon: HeartPulse },
    { key: 'sleep', label: 'Sleep', icon: Moon },
    { key: 'appointments', label: 'Appointments', icon: Calendar },
    { key: 'docs', label: 'Docs', icon: FileText },
];

export function HealthMonitoringTab({
    clientId,
    data,
    isLoading = false,
}: HealthMonitoringTabProps) {
    const [section, setSection] = useState<SectionKey>('fluid');
    const [bowel, setBowel] = useState({
        bristol_type: '4',
        volume: '',
        notes: '',
    });
    const [fluid, setFluid] = useState({
        direction: 'in',
        fluid_type: '',
        volume_ml: '',
        notes: '',
    });
    const [seizure, setSeizure] = useState({
        seizure_type: '',
        duration_minutes: '',
        trigger: '',
        response_taken: '',
        recovery_notes: '',
        escalated: false,
        follow_up_action: '',
    });
    const [sleep, setSleep] = useState({
        slept_at: new Date().toISOString().slice(0, 10),
        hours_slept: '',
        quality: 'good',
        interruptions: '',
        settled_by: '',
        woke_at: '',
        notes: '',
    });

    const bowelEntries = useMemo(() => data.bowel ?? [], [data.bowel]);
    const fluidEntries = useMemo(() => data.fluid ?? [], [data.fluid]);
    const seizureEntries = useMemo(() => data.seizure ?? [], [data.seizure]);
    const sleepEntries = useMemo(() => data.sleep ?? [], [data.sleep]);
    const sleepSummary = data.sleep_summary ?? {};

    const fluidChart = useMemo(() => {
        const buckets = new Map<
            string,
            { day: string; intake: number; output: number }
        >();

        fluidEntries
            .slice()
            .reverse()
            .forEach((entry) => {
                const day = dateKey(entry.occurred_at);
                const bucket = buckets.get(day) ?? {
                    day,
                    intake: 0,
                    output: 0,
                };
                if (entry.direction === 'out') {
                    bucket.output += entry.volume_ml ?? 0;
                } else {
                    bucket.intake += entry.volume_ml ?? 0;
                }
                buckets.set(day, bucket);
            });

        return Array.from(buckets.values()).slice(-14);
    }, [fluidEntries]);

    const bowelChart = bowelEntries
        .slice()
        .reverse()
        .slice(-14)
        .map((entry) => ({
            day: dateKey(entry.occurred_at),
            bristol: entry.bristol_type ?? 0,
        }));

    const seizureChart = seizureEntries
        .slice()
        .reverse()
        .slice(-14)
        .map((entry) => ({
            day: dateKey(entry.occurred_at),
            minutes: Math.round(((entry.duration_seconds ?? 0) / 60) * 10) / 10,
        }));

    const sleepChart = sleepEntries
        .slice()
        .reverse()
        .slice(-14)
        .map((entry) => ({
            day: dateKey(entry.slept_at),
            hours: Number(entry.hours_slept ?? 0),
            target: Number(sleepSummary.target_hours ?? 7),
        }));

    const submitBowel = () => {
        router.post(
            `/operations/clients/${clientId}/health/bowel`,
            {
                bristol_type: Number(bowel.bristol_type),
                volume: bowel.volume || null,
                notes: bowel.notes || null,
            },
            {
                preserveScroll: true,
                preserveState: false,
                onSuccess: () =>
                    setBowel({ bristol_type: '4', volume: '', notes: '' }),
            },
        );
    };

    const submitFluid = () => {
        if (!fluid.volume_ml) return;
        router.post(
            `/operations/clients/${clientId}/health/fluid`,
            {
                direction: fluid.direction,
                fluid_type: fluid.fluid_type || null,
                volume_ml: Number(fluid.volume_ml),
                notes: fluid.notes || null,
            },
            {
                preserveScroll: true,
                preserveState: false,
                onSuccess: () =>
                    setFluid({
                        direction: 'in',
                        fluid_type: '',
                        volume_ml: '',
                        notes: '',
                    }),
            },
        );
    };

    const submitSeizure = () => {
        router.post(
            `/operations/clients/${clientId}/health/seizure`,
            {
                seizure_type: seizure.seizure_type || null,
                duration_seconds: seizure.duration_minutes
                    ? Math.max(
                          1,
                          Math.round(Number(seizure.duration_minutes) * 60),
                      )
                    : null,
                trigger: seizure.trigger || null,
                response_taken: seizure.response_taken || null,
                recovery_notes: seizure.recovery_notes || null,
                escalated: seizure.escalated,
                follow_up_action: seizure.follow_up_action || null,
            },
            {
                preserveScroll: true,
                preserveState: false,
                onSuccess: () =>
                    setSeizure({
                        seizure_type: '',
                        duration_minutes: '',
                        trigger: '',
                        response_taken: '',
                        recovery_notes: '',
                        escalated: false,
                        follow_up_action: '',
                    }),
            },
        );
    };

    const submitSleep = () => {
        if (!sleep.slept_at || !sleep.hours_slept) return;
        router.post(
            `/operations/clients/${clientId}/health/sleep`,
            {
                slept_at: sleep.slept_at,
                hours_slept: Number(sleep.hours_slept),
                quality: sleep.quality || null,
                interruptions: sleep.interruptions
                    ? Number(sleep.interruptions)
                    : null,
                settled_by: sleep.settled_by || null,
                woke_at: sleep.woke_at || null,
                notes: sleep.notes || null,
            },
            {
                preserveScroll: true,
                preserveState: false,
                onSuccess: () =>
                    setSleep({
                        slept_at: new Date().toISOString().slice(0, 10),
                        hours_slept: '',
                        quality: 'good',
                        interruptions: '',
                        settled_by: '',
                        woke_at: '',
                        notes: '',
                    }),
            },
        );
    };

    if (isLoading) {
        return (
            <div className="space-y-6" aria-busy="true">
                <div className="grid gap-3 md:grid-cols-3">
                    {[0, 1, 2].map((item) => (
                        <div key={item} className="rounded-lg border p-4">
                            <Skeleton className="h-3 w-24" />
                            <Skeleton className="mt-3 h-8 w-12" />
                        </div>
                    ))}
                </div>
                <Skeleton className="h-80 rounded-lg" />
            </div>
        );
    }

    return (
        <div className="space-y-6">
            <div className="grid gap-3 md:grid-cols-4">
                <MetricCard
                    label="Fluid entries"
                    value={fluidEntries.length}
                    icon={Droplets}
                    tone="bg-status-info-bg text-status-info"
                />
                <MetricCard
                    label="Bowel entries"
                    value={bowelEntries.length}
                    icon={Stethoscope}
                    tone="bg-primary/10 text-primary"
                />
                <MetricCard
                    label="Seizure events"
                    value={seizureEntries.length}
                    icon={HeartPulse}
                    tone={
                        seizureEntries.some((entry) => entry.escalated)
                            ? 'bg-status-critical-bg text-status-critical'
                            : 'bg-status-success-bg text-status-success'
                    }
                />
                <MetricCard
                    label="Sleep avg"
                    value={
                        Math.round(
                            Number(sleepSummary.average_7_nights ?? 0) * 10,
                        ) / 10
                    }
                    icon={Moon}
                    tone={
                        Number(sleepSummary.average_7_nights ?? 0) >=
                        Number(sleepSummary.target_hours ?? 7)
                            ? 'bg-status-success-bg text-status-success'
                            : 'bg-status-warning-bg text-status-warning'
                    }
                />
            </div>

            <div className="flex flex-wrap gap-2">
                {sections.map((item) => {
                    const Icon = item.icon;
                    const active = section === item.key;

                    return (
                        <Button
                            key={item.key}
                            type="button"
                            variant={active ? 'default' : 'outline'}
                            onClick={() => setSection(item.key)}
                            className="min-h-11"
                        >
                            <Icon className="mr-2 h-4 w-4" />
                            {item.label}
                        </Button>
                    );
                })}
            </div>

            {section === 'fluid' ? (
                <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem]">
                    <ChartCard title="Fluid Trend">
                        {fluidChart.length > 0 ? (
                            <ResponsiveContainer width="100%" height={260}>
                                <BarChart data={fluidChart}>
                                    <CartesianGrid strokeDasharray="3 3" />
                                    <XAxis dataKey="day" fontSize={12} />
                                    <YAxis fontSize={12} />
                                    <Tooltip />
                                    <Bar
                                        dataKey="intake"
                                        fill="#2563eb"
                                        name="Intake ml"
                                    />
                                    <Bar
                                        dataKey="output"
                                        fill="#14b8a6"
                                        name="Output ml"
                                    />
                                </BarChart>
                            </ResponsiveContainer>
                        ) : (
                            <EmptyState
                                icon={Droplets}
                                title="No fluid chart entries"
                                variant="compact"
                            />
                        )}
                    </ChartCard>
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Plus className="h-4 w-4 text-primary" />
                                Add Fluid Entry
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
                                <div className="space-y-2">
                                    <Label>Direction</Label>
                                    <Select
                                        value={fluid.direction}
                                        onValueChange={(value) =>
                                            setFluid((current) => ({
                                                ...current,
                                                direction: value,
                                            }))
                                        }
                                    >
                                        <SelectTrigger className="min-h-11">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="in">
                                                Intake
                                            </SelectItem>
                                            <SelectItem value="out">
                                                Output
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-2">
                                    <Label>Volume ml</Label>
                                    <Input
                                        type="number"
                                        min="1"
                                        value={fluid.volume_ml}
                                        onChange={(event) =>
                                            setFluid((current) => ({
                                                ...current,
                                                volume_ml: event.target.value,
                                            }))
                                        }
                                        className="min-h-11"
                                    />
                                </div>
                            </div>
                            <Input
                                value={fluid.fluid_type}
                                onChange={(event) =>
                                    setFluid((current) => ({
                                        ...current,
                                        fluid_type: event.target.value,
                                    }))
                                }
                                placeholder="Fluid type"
                                className="min-h-11"
                            />
                            <Textarea
                                value={fluid.notes}
                                onChange={(event) =>
                                    setFluid((current) => ({
                                        ...current,
                                        notes: event.target.value,
                                    }))
                                }
                                placeholder="Notes"
                                className="min-h-24"
                            />
                            <Button
                                type="button"
                                onClick={submitFluid}
                                disabled={!fluid.volume_ml}
                                className="min-h-11 w-full"
                            >
                                Save Fluid Entry
                            </Button>
                        </CardContent>
                    </Card>
                </div>
            ) : null}

            {section === 'bowel' ? (
                <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem]">
                    <ChartCard title="Bristol Scale">
                        {bowelChart.length > 0 ? (
                            <ResponsiveContainer width="100%" height={260}>
                                <LineChart data={bowelChart}>
                                    <CartesianGrid strokeDasharray="3 3" />
                                    <XAxis dataKey="day" fontSize={12} />
                                    <YAxis
                                        fontSize={12}
                                        domain={[1, 7]}
                                        ticks={[1, 2, 3, 4, 5, 6, 7]}
                                    />
                                    <Tooltip />
                                    <Line
                                        dataKey="bristol"
                                        stroke="#7c3aed"
                                        strokeWidth={2}
                                        name="Bristol type"
                                    />
                                </LineChart>
                            </ResponsiveContainer>
                        ) : (
                            <EmptyState
                                icon={Stethoscope}
                                title="No bowel chart entries"
                                variant="compact"
                            />
                        )}
                    </ChartCard>
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Plus className="h-4 w-4 text-primary" />
                                Add Bowel Entry
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="space-y-2">
                                <Label>Bristol type</Label>
                                <Select
                                    value={bowel.bristol_type}
                                    onValueChange={(value) =>
                                        setBowel((current) => ({
                                            ...current,
                                            bristol_type: value,
                                        }))
                                    }
                                >
                                    <SelectTrigger className="min-h-11">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {[1, 2, 3, 4, 5, 6, 7].map((value) => (
                                            <SelectItem
                                                key={value}
                                                value={String(value)}
                                            >
                                                Type {value}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <Input
                                value={bowel.volume}
                                onChange={(event) =>
                                    setBowel((current) => ({
                                        ...current,
                                        volume: event.target.value,
                                    }))
                                }
                                placeholder="Volume"
                                className="min-h-11"
                            />
                            <Textarea
                                value={bowel.notes}
                                onChange={(event) =>
                                    setBowel((current) => ({
                                        ...current,
                                        notes: event.target.value,
                                    }))
                                }
                                placeholder="Notes"
                                className="min-h-24"
                            />
                            <Button
                                type="button"
                                onClick={submitBowel}
                                className="min-h-11 w-full"
                            >
                                Save Bowel Entry
                            </Button>
                        </CardContent>
                    </Card>
                </div>
            ) : null}

            {section === 'seizure' ? (
                <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem]">
                    <ChartCard title="Seizure Duration">
                        {seizureChart.length > 0 ? (
                            <ResponsiveContainer width="100%" height={260}>
                                <BarChart data={seizureChart}>
                                    <CartesianGrid strokeDasharray="3 3" />
                                    <XAxis dataKey="day" fontSize={12} />
                                    <YAxis fontSize={12} />
                                    <Tooltip />
                                    <Bar
                                        dataKey="minutes"
                                        fill="#dc2626"
                                        name="Minutes"
                                    />
                                </BarChart>
                            </ResponsiveContainer>
                        ) : (
                            <EmptyState
                                icon={HeartPulse}
                                title="No seizure chart entries"
                                variant="compact"
                            />
                        )}
                    </ChartCard>
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Plus className="h-4 w-4 text-primary" />
                                Add Seizure Entry
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <Input
                                value={seizure.seizure_type}
                                onChange={(event) =>
                                    setSeizure((current) => ({
                                        ...current,
                                        seizure_type: event.target.value,
                                    }))
                                }
                                placeholder="Type"
                                className="min-h-11"
                            />
                            <Input
                                type="number"
                                min="0"
                                step="0.1"
                                value={seizure.duration_minutes}
                                onChange={(event) =>
                                    setSeizure((current) => ({
                                        ...current,
                                        duration_minutes: event.target.value,
                                    }))
                                }
                                placeholder="Duration minutes"
                                className="min-h-11"
                            />
                            <Input
                                value={seizure.trigger}
                                onChange={(event) =>
                                    setSeizure((current) => ({
                                        ...current,
                                        trigger: event.target.value,
                                    }))
                                }
                                placeholder="Trigger"
                                className="min-h-11"
                            />
                            <Textarea
                                value={seizure.response_taken}
                                onChange={(event) =>
                                    setSeizure((current) => ({
                                        ...current,
                                        response_taken: event.target.value,
                                    }))
                                }
                                placeholder="Response taken"
                                className="min-h-20"
                            />
                            <Textarea
                                value={seizure.recovery_notes}
                                onChange={(event) =>
                                    setSeizure((current) => ({
                                        ...current,
                                        recovery_notes: event.target.value,
                                    }))
                                }
                                placeholder="Recovery notes"
                                className="min-h-20"
                            />
                            <Input
                                value={seizure.follow_up_action}
                                onChange={(event) =>
                                    setSeizure((current) => ({
                                        ...current,
                                        follow_up_action: event.target.value,
                                    }))
                                }
                                placeholder="Follow-up action"
                                className="min-h-11"
                            />
                            <label className="frontline-focus flex min-h-11 items-center gap-3 rounded-lg border p-3 text-sm">
                                <Checkbox
                                    checked={seizure.escalated}
                                    onCheckedChange={(checked) =>
                                        setSeizure((current) => ({
                                            ...current,
                                            escalated: checked === true,
                                        }))
                                    }
                                />
                                Escalated
                            </label>
                            <Button
                                type="button"
                                onClick={submitSeizure}
                                className="min-h-11 w-full"
                            >
                                Save Seizure Entry
                            </Button>
                        </CardContent>
                    </Card>
                </div>
            ) : null}

            {section === 'sleep' ? (
                <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem]">
                    <ChartCard title="Sleep Hours">
                        {sleepChart.length > 0 ? (
                            <ResponsiveContainer width="100%" height={260}>
                                <LineChart data={sleepChart}>
                                    <CartesianGrid strokeDasharray="3 3" />
                                    <XAxis dataKey="day" fontSize={12} />
                                    <YAxis fontSize={12} />
                                    <Tooltip />
                                    <Line
                                        dataKey="hours"
                                        stroke="#2563eb"
                                        strokeWidth={2}
                                        name="Hours slept"
                                    />
                                    <Line
                                        dataKey="target"
                                        stroke="#f59e0b"
                                        strokeDasharray="4 4"
                                        strokeWidth={1.5}
                                        name="Target"
                                        dot={false}
                                    />
                                </LineChart>
                            </ResponsiveContainer>
                        ) : (
                            <EmptyState
                                icon={Moon}
                                title="No sleep chart entries"
                                variant="compact"
                            />
                        )}
                    </ChartCard>
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Plus className="h-4 w-4 text-primary" />
                                Add Sleep Entry
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
                                <div className="space-y-2">
                                    <Label>Night</Label>
                                    <Input
                                        type="date"
                                        value={sleep.slept_at}
                                        onChange={(event) =>
                                            setSleep((current) => ({
                                                ...current,
                                                slept_at: event.target.value,
                                            }))
                                        }
                                        className="min-h-11"
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label>Hours slept</Label>
                                    <Input
                                        type="number"
                                        min="0"
                                        max="24"
                                        step="0.1"
                                        value={sleep.hours_slept}
                                        onChange={(event) =>
                                            setSleep((current) => ({
                                                ...current,
                                                hours_slept: event.target.value,
                                            }))
                                        }
                                        className="min-h-11"
                                    />
                                </div>
                            </div>
                            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
                                <div className="space-y-2">
                                    <Label>Quality</Label>
                                    <Select
                                        value={sleep.quality}
                                        onValueChange={(value) =>
                                            setSleep((current) => ({
                                                ...current,
                                                quality: value,
                                            }))
                                        }
                                    >
                                        <SelectTrigger className="min-h-11">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="good">
                                                Good
                                            </SelectItem>
                                            <SelectItem value="fair">
                                                Fair
                                            </SelectItem>
                                            <SelectItem value="poor">
                                                Poor
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-2">
                                    <Label>Interruptions</Label>
                                    <Input
                                        type="number"
                                        min="0"
                                        value={sleep.interruptions}
                                        onChange={(event) =>
                                            setSleep((current) => ({
                                                ...current,
                                                interruptions:
                                                    event.target.value,
                                            }))
                                        }
                                        className="min-h-11"
                                    />
                                </div>
                            </div>
                            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
                                <Input
                                    type="time"
                                    value={sleep.settled_by}
                                    onChange={(event) =>
                                        setSleep((current) => ({
                                            ...current,
                                            settled_by: event.target.value,
                                        }))
                                    }
                                    aria-label="Settled by"
                                    className="min-h-11"
                                />
                                <Input
                                    type="time"
                                    value={sleep.woke_at}
                                    onChange={(event) =>
                                        setSleep((current) => ({
                                            ...current,
                                            woke_at: event.target.value,
                                        }))
                                    }
                                    aria-label="Woke at"
                                    className="min-h-11"
                                />
                            </div>
                            <Textarea
                                value={sleep.notes}
                                onChange={(event) =>
                                    setSleep((current) => ({
                                        ...current,
                                        notes: event.target.value,
                                    }))
                                }
                                placeholder="Notes"
                                className="min-h-24"
                            />
                            <Button
                                type="button"
                                onClick={submitSleep}
                                disabled={!sleep.slept_at || !sleep.hours_slept}
                                className="min-h-11 w-full"
                            >
                                Save Sleep Entry
                            </Button>
                        </CardContent>
                    </Card>
                </div>
            ) : null}

            {section === 'appointments' ? (
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Calendar className="h-4 w-4 text-primary" />
                            Appointment Records
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-sm text-muted-foreground">
                            Appointments remain in the profile appointments
                            list; this shortcut keeps the health monitoring
                            workflow grouped for clinical review.
                        </p>
                        <Button asChild variant="outline" className="min-h-11">
                            <Link
                                href={`/operations/clients/${clientId}?tab=calendar`}
                            >
                                Open Appointments
                            </Link>
                        </Button>
                    </CardContent>
                </Card>
            ) : null}

            {section === 'docs' ? (
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <FileText className="h-4 w-4 text-primary" />
                            Health Documents
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-sm text-muted-foreground">
                            Medical authorities, plans, and expiring documents
                            are surfaced in Documents and Actions & Reviews.
                        </p>
                        <Button asChild variant="outline" className="min-h-11">
                            <Link
                                href={`/operations/clients/${clientId}?tab=documents`}
                            >
                                Open Documents
                            </Link>
                        </Button>
                    </CardContent>
                </Card>
            ) : null}

            <div className="grid gap-3 lg:grid-cols-3">
                <RecentEntries
                    title="Recent fluid"
                    entries={fluidEntries}
                    render={(entry: FluidEntry) => (
                        <>
                            <p className="font-medium">
                                {entry.direction === 'out'
                                    ? 'Output'
                                    : 'Intake'}{' '}
                                {entry.volume_ml ?? 0} ml
                            </p>
                            <p className="text-muted-foreground">
                                {entry.fluid_type ?? 'Fluid'} -{' '}
                                {dateLabel(entry.occurred_at)}
                            </p>
                        </>
                    )}
                />
                <RecentEntries
                    title="Recent bowel"
                    entries={bowelEntries}
                    render={(entry: BowelEntry) => (
                        <>
                            <p className="font-medium">
                                Bristol type {entry.bristol_type ?? '-'}
                            </p>
                            <p className="text-muted-foreground">
                                {entry.volume ?? 'Volume not recorded'} -{' '}
                                {dateLabel(entry.occurred_at)}
                            </p>
                        </>
                    )}
                />
                <RecentEntries
                    title="Recent seizures"
                    entries={seizureEntries}
                    render={(entry: SeizureEntry) => (
                        <>
                            <div className="flex items-center gap-2">
                                <p className="font-medium">
                                    {entry.seizure_type ?? 'Seizure event'}
                                </p>
                                {entry.escalated ? (
                                    <Badge className="bg-status-critical-bg text-status-critical">
                                        <AlertTriangle className="mr-1 h-3.5 w-3.5" />
                                        Escalated
                                    </Badge>
                                ) : null}
                            </div>
                            <p className="text-muted-foreground">
                                {Math.round(
                                    ((entry.duration_seconds ?? 0) / 60) * 10,
                                ) / 10}{' '}
                                min - {dateLabel(entry.occurred_at)}
                            </p>
                        </>
                    )}
                />
                <RecentEntries
                    title="Recent sleep"
                    entries={sleepEntries}
                    render={(entry: SleepEntry) => (
                        <>
                            <p className="font-medium">
                                {entry.hours_slept ?? '-'}h
                                {entry.quality ? ` · ${entry.quality}` : ''}
                            </p>
                            <p className="text-muted-foreground">
                                {dateLabel(entry.slept_at)}
                                {entry.interruptions != null
                                    ? ` · ${entry.interruptions} interruption${entry.interruptions === 1 ? '' : 's'}`
                                    : ''}
                            </p>
                        </>
                    )}
                />
            </div>
        </div>
    );
}

function MetricCard({
    label,
    value,
    icon: Icon,
    tone,
}: {
    label: string;
    value: number;
    icon: typeof Activity;
    tone: string;
}) {
    return (
        // eslint-disable-next-line no-restricted-syntax -- MetricCard is a compact stat tile reused inside this chart tab.
        <div className="rounded-lg border bg-card p-4">
            <div className="flex items-center justify-between gap-3">
                <div>
                    <p className="text-xs text-muted-foreground">{label}</p>
                    <p className="mt-1 text-2xl font-semibold">{value}</p>
                </div>
                <span className={cn('rounded-lg p-2', tone)}>
                    <Icon className="h-5 w-5" />
                </span>
            </div>
        </div>
    );
}

function ChartCard({
    title,
    children,
}: {
    title: string;
    children: ReactNode;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">{title}</CardTitle>
            </CardHeader>
            <CardContent>{children}</CardContent>
        </Card>
    );
}

function RecentEntries<T extends { id: number }>({
    title,
    entries,
    render,
}: {
    title: string;
    entries: T[];
    render: (entry: T) => ReactNode;
}) {
    return (
        // eslint-disable-next-line no-restricted-syntax -- RecentEntries is a compact chart-tab list panel.
        <div className="rounded-lg border bg-card p-4">
            <h3 className="text-sm font-semibold">{title}</h3>
            <div className="mt-3 space-y-2">
                {entries.length > 0 ? (
                    entries.slice(0, 5).map((entry) => (
                        // eslint-disable-next-line no-restricted-syntax -- Entry rows are compact repeated list items inside the chart panel.
                        <div
                            key={entry.id}
                            className="rounded-md border bg-background p-3 text-sm"
                        >
                            {render(entry)}
                        </div>
                    ))
                ) : (
                    <p className="text-sm text-muted-foreground">
                        No entries recorded.
                    </p>
                )}
            </div>
        </div>
    );
}
