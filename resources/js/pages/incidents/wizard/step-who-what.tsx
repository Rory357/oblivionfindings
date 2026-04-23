import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Activity, Eye, HelpCircle, Pill, Shield, User } from 'lucide-react';
import type { ComponentType } from 'react';

export type IncidentType = 'injury' | 'behaviour' | 'medication' | 'safeguarding' | 'near_miss' | 'other';
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
    { value: 'injury', label: 'Injury', icon: Activity, color: 'border-red-300 bg-red-50 text-red-700' },
    { value: 'behaviour', label: 'Behaviour', icon: User, color: 'border-blue-300 bg-blue-50 text-blue-700' },
    { value: 'medication', label: 'Medication', icon: Pill, color: 'border-primary bg-primary/10 text-primary' },
    { value: 'safeguarding', label: 'Safeguarding', icon: Shield, color: 'border-orange-300 bg-orange-50 text-orange-700' },
    { value: 'near_miss', label: 'Near miss', icon: Eye, color: 'border-amber-300 bg-amber-50 text-amber-700' },
    { value: 'other', label: 'Other', icon: HelpCircle, color: 'border-border bg-muted text-foreground' },
];

const SEVERITY_OPTIONS: Array<{
    value: IncidentSeverity;
    label: string;
    hint: string;
    ring: string;
    dot: string;
}> = [
    { value: 'low', label: 'Low', hint: 'Minor, no lasting impact', ring: 'ring-emerald-500 bg-emerald-50 text-emerald-800', dot: 'bg-emerald-500' },
    { value: 'medium', label: 'Medium', hint: 'Needs a follow-up', ring: 'ring-amber-500 bg-amber-50 text-amber-800', dot: 'bg-amber-500' },
    { value: 'high', label: 'High', hint: 'Serious — tell a manager now', ring: 'ring-red-500 bg-red-50 text-red-800', dot: 'bg-red-500' },
];

function nowLocalInputValue() {
    const d = new Date();
    const pad = (n: number) => n.toString().padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

export default function StepWhoWhat({ data, onChange, clients, clientLabel, errors }: Props) {
    return (
        <div className="space-y-6">
            <div className="space-y-1">
                <h2 className="text-lg font-semibold">Who and what</h2>
                <p className="text-sm text-muted-foreground">The quick basics — so we can log this fast.</p>
            </div>

            <div className="space-y-2">
                <Label className="text-sm font-medium">{clientLabel}</Label>
                <Select
                    value={data.client_id || '__none__'}
                    onValueChange={(v) => onChange({ client_id: v === '__none__' ? '' : v })}
                >
                    <SelectTrigger className="h-12 text-base">
                        <SelectValue placeholder={`Select a ${clientLabel.toLowerCase()}`} />
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
                {errors?.client_id && <p className="text-xs text-red-600">{errors.client_id}</p>}
            </div>

            <div className="space-y-2">
                <Label className="text-sm font-medium">What kind of incident</Label>
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    {INCIDENT_TYPES.map((t) => {
                        const Icon = t.icon;
                        const selected = data.type === t.value;
                        return (
                            <button
                                key={t.value}
                                type="button"
                                onClick={() => onChange({ type: t.value })}
                                className={`flex min-h-[72px] flex-col items-center justify-center gap-1.5 rounded-lg border-2 p-3 text-center transition-all ${
                                    selected
                                        ? `${t.color} shadow-sm ring-2 ring-current`
                                        : 'border-border bg-background text-muted-foreground hover:bg-muted/50'
                                }`}
                            >
                                <Icon className="h-5 w-5" />
                                <span className="text-sm font-medium">{t.label}</span>
                            </button>
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
                            <button
                                key={s.value}
                                type="button"
                                onClick={() => onChange({ severity: s.value })}
                                className={`flex min-h-[64px] items-center gap-3 rounded-lg border-2 px-4 py-3 text-left transition-all ${
                                    selected ? `border-transparent ring-2 ${s.ring}` : 'border-border bg-background hover:bg-muted/50'
                                }`}
                            >
                                <span className={`h-3 w-3 shrink-0 rounded-full ${s.dot}`} />
                                <div className="flex flex-col">
                                    <span className="text-sm font-semibold">{s.label}</span>
                                    <span className="text-xs text-muted-foreground">{s.hint}</span>
                                </div>
                            </button>
                        );
                    })}
                </div>
            </div>

            <div className="space-y-2">
                <div className="flex items-center justify-between">
                    <Label className="text-sm font-medium">When it happened</Label>
                    <button
                        type="button"
                        className="text-xs font-medium text-primary hover:underline"
                        onClick={() => onChange({ occurred_at: nowLocalInputValue() })}
                    >
                        Use now
                    </button>
                </div>
                <Input
                    type="datetime-local"
                    className="h-12 text-base"
                    value={data.occurred_at}
                    onChange={(e) => onChange({ occurred_at: e.target.value })}
                />
                <p className="text-xs text-muted-foreground">Leave blank to use right now.</p>
            </div>
        </div>
    );
}

export { nowLocalInputValue };
