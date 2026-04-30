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
import { Activity, Eye, HelpCircle, Pill, Shield, User } from 'lucide-react';
import type { ComponentType } from 'react';

export type IncidentType =
    | 'injury'
    | 'behaviour'
    | 'medication'
    | 'safeguarding'
    | 'near_miss'
    | 'other';
export type IncidentSeverity = 'low' | 'medium' | 'high';

export type StepOneData = {
    client_id: string;
    type: IncidentType;
    severity: IncidentSeverity;
    occurred_at: string;
};

type Client = { id: number; first_name: string; last_name: string };

type Props = {
    data: StepOneData;
    onChange: (patch: Partial<StepOneData>) => void;
    clients: Client[];
    clientLabel: string;
    errors?: Partial<Record<keyof StepOneData, string>>;
};

const INCIDENT_TYPES: Array<{
    value: IncidentType;
    label: string;
    icon: ComponentType<{ className?: string }>;
    color: string;
}> = [
    {
        value: 'injury',
        label: 'Injury',
        icon: Activity,
        color: 'border-status-critical/40 bg-status-critical-bg text-foreground',
    },
    {
        value: 'behaviour',
        label: 'Behaviour',
        icon: User,
        color: 'border-status-info/40 bg-status-info-bg text-foreground',
    },
    {
        value: 'medication',
        label: 'Medication',
        icon: Pill,
        color: 'border-primary bg-primary/10 text-primary',
    },
    {
        value: 'safeguarding',
        label: 'Safeguarding',
        icon: Shield,
        color: 'border-status-warning/40 bg-status-warning-bg text-foreground',
    },
    {
        value: 'near_miss',
        label: 'Near miss',
        icon: Eye,
        color: 'border-status-warning/40 bg-status-warning-bg text-foreground',
    },
    {
        value: 'other',
        label: 'Other',
        icon: HelpCircle,
        color: 'border-border bg-muted text-foreground',
    },
];

const SEVERITY_OPTIONS: Array<{
    value: IncidentSeverity;
    label: string;
    hint: string;
    ring: string;
    dot: string;
}> = [
    {
        value: 'low',
        label: 'Low',
        hint: 'Minor, no lasting impact',
        ring: 'ring-status-success bg-status-success-bg text-foreground',
        dot: 'bg-status-success',
    },
    {
        value: 'medium',
        label: 'Medium',
        hint: 'Needs a follow-up',
        ring: 'ring-status-warning bg-status-warning-bg text-foreground',
        dot: 'bg-status-warning',
    },
    {
        value: 'high',
        label: 'High',
        hint: 'Serious — tell a manager now',
        ring: 'ring-status-critical bg-status-critical-bg text-foreground',
        dot: 'bg-status-critical',
    },
];

function nowLocalInputValue() {
    const d = new Date();
    const pad = (n: number) => n.toString().padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

export default function StepWhoWhat({
    data,
    onChange,
    clients,
    clientLabel,
    errors,
}: Props) {
    return (
        <div className="space-y-6">
            <div className="space-y-1">
                <h2 className="text-lg font-semibold">Who and what</h2>
                <p className="text-sm text-muted-foreground">
                    The quick basics — so we can log this fast.
                </p>
            </div>

            <div className="space-y-2">
                <Label
                    htmlFor="incident-client"
                    className="text-sm font-medium"
                >
                    {clientLabel}
                </Label>
                <Select
                    value={data.client_id || '__none__'}
                    onValueChange={(v) =>
                        onChange({ client_id: v === '__none__' ? '' : v })
                    }
                >
                    <SelectTrigger
                        id="incident-client"
                        data-test="incident-client-select"
                        aria-label={clientLabel}
                        aria-invalid={!!errors?.client_id}
                        aria-describedby={
                            errors?.client_id
                                ? 'incident-client-error'
                                : undefined
                        }
                        className="h-12 text-base"
                    >
                        <SelectValue
                            placeholder={`Select a ${clientLabel.toLowerCase()}`}
                        />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="__none__">Select…</SelectItem>
                        {clients.map((c) => (
                            <SelectItem key={c.id} value={String(c.id)}>
                                {c.first_name} {c.last_name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                {errors?.client_id && (
                    <p
                        id="incident-client-error"
                        data-test="incident-client-error"
                        className="text-xs text-status-critical"
                    >
                        {errors.client_id}
                    </p>
                )}
            </div>

            <div className="space-y-2">
                <Label className="text-sm font-medium">
                    What kind of incident
                </Label>
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    {INCIDENT_TYPES.map((t) => {
                        const Icon = t.icon;
                        const selected = data.type === t.value;
                        return (
                            <Button
                                key={t.value}
                                data-test={`incident-type-${t.value}`}
                                type="button"
                                variant="outline"
                                aria-pressed={selected}
                                onClick={() => onChange({ type: t.value })}
                                className={`h-auto min-h-[72px] flex-col gap-1.5 rounded-lg border-2 p-3 text-center ${
                                    selected
                                        ? `${t.color} shadow-sm ring-2 ring-current`
                                        : 'border-border bg-background text-muted-foreground hover:bg-muted/50'
                                }`}
                            >
                                <Icon className="h-5 w-5" />
                                <span className="text-sm font-medium">
                                    {t.label}
                                </span>
                            </Button>
                        );
                    })}
                </div>
            </div>

            <div className="space-y-2">
                <Label className="text-sm font-medium">How serious</Label>
                <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
                    {SEVERITY_OPTIONS.map((s) => {
                        const selected = data.severity === s.value;
                        return (
                            <Button
                                key={s.value}
                                data-test={`incident-severity-${s.value}`}
                                type="button"
                                variant="outline"
                                aria-pressed={selected}
                                onClick={() => onChange({ severity: s.value })}
                                className={`h-auto min-h-[64px] justify-start gap-3 rounded-lg border-2 px-4 py-3 text-left ${
                                    selected
                                        ? `border-transparent ring-2 ${s.ring}`
                                        : 'border-border bg-background hover:bg-muted/50'
                                }`}
                            >
                                <span
                                    className={`h-3 w-3 shrink-0 rounded-full ${s.dot}`}
                                />
                                <div className="flex flex-col">
                                    <span className="text-sm font-semibold">
                                        {s.label}
                                    </span>
                                    <span
                                        className={
                                            selected
                                                ? 'text-xs text-foreground'
                                                : 'text-xs text-muted-foreground'
                                        }
                                    >
                                        {s.hint}
                                    </span>
                                </div>
                            </Button>
                        );
                    })}
                </div>
            </div>

            <div className="space-y-2">
                <div className="flex items-center justify-between">
                    <Label
                        htmlFor="incident-occurred-at"
                        className="text-sm font-medium"
                    >
                        When it happened
                    </Label>
                    <Button
                        type="button"
                        variant="link"
                        size="sm"
                        data-test="incident-use-now"
                        className="h-auto p-0 text-xs font-medium text-primary"
                        onClick={() =>
                            onChange({ occurred_at: nowLocalInputValue() })
                        }
                    >
                        Use now
                    </Button>
                </div>
                <Input
                    id="incident-occurred-at"
                    data-test="incident-occurred-at"
                    type="datetime-local"
                    className="h-12 text-base"
                    value={data.occurred_at}
                    onChange={(e) => onChange({ occurred_at: e.target.value })}
                />
                <p className="text-xs text-muted-foreground">
                    Leave blank to use right now.
                </p>
            </div>
        </div>
    );
}

export { nowLocalInputValue };
