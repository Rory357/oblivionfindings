import {
    AlertCircle,
    BadgeCheck,
    Bell,
    CheckCircle2,
    CircleDot,
    Clock3,
    EyeOff,
    Gauge,
    ShieldAlert,
    Siren,
    TriangleAlert,
    type LucideIcon,
} from 'lucide-react';

export type StatusTone =
    | 'success'
    | 'warning'
    | 'critical'
    | 'info'
    | 'neutral';
export type StatusVocabulary = {
    label: string;
    tone: StatusTone;
    icon: LucideIcon;
};

export const ALERT_STATUS_VOCAB: Record<string, StatusVocabulary> = {
    open: { label: 'Open', tone: 'warning', icon: Bell },
    ack: { label: 'Acknowledged', tone: 'info', icon: BadgeCheck },
    triaging: { label: 'Responding', tone: 'warning', icon: Gauge },
    confirmed: {
        label: 'Confirmed incident',
        tone: 'critical',
        icon: ShieldAlert,
    },
    resolved: { label: 'Resolved', tone: 'success', icon: CheckCircle2 },
    closed: { label: 'Closed', tone: 'success', icon: CheckCircle2 },
    dismissed: { label: 'Dismissed', tone: 'neutral', icon: EyeOff },
};

export const ALERT_SEVERITY_VOCAB: Record<string, StatusVocabulary> = {
    low: { label: 'Low severity', tone: 'neutral', icon: CircleDot },
    medium: { label: 'Medium severity', tone: 'info', icon: AlertCircle },
    high: { label: 'High severity', tone: 'warning', icon: TriangleAlert },
    critical: { label: 'Critical severity', tone: 'critical', icon: Siren },
};

export const ALERT_SLA_VOCAB: Record<string, StatusVocabulary> = {
    on_track: { label: 'On target', tone: 'success', icon: CheckCircle2 },
    green: { label: 'On target', tone: 'success', icon: CheckCircle2 },
    at_risk: { label: 'Target at risk', tone: 'warning', icon: Clock3 },
    yellow: { label: 'Target at risk', tone: 'warning', icon: Clock3 },
    breached: { label: 'Target missed', tone: 'critical', icon: Siren },
    red: { label: 'Target missed', tone: 'critical', icon: Siren },
    resolved: { label: 'Targets met', tone: 'success', icon: CheckCircle2 },
    not_applicable: { label: 'No response target', tone: 'neutral', icon: CircleDot },
};

export function vocabularyFor(
    map: Record<string, StatusVocabulary>,
    value: string | null | undefined,
): StatusVocabulary {
    return (
        (value ? map[value] : undefined) ?? {
            label: 'Status unavailable',
            tone: 'neutral',
            icon: CircleDot,
        }
    );
}
