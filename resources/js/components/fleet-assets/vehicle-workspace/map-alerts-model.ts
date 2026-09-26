import type { StatusVariant } from '@/components/ui/status-badge';
import { formatDateTime } from '@/lib/datetime';
import type {
    AlertDetail,
    AlertItem,
    AlertPlan,
    AlertStatus,
    AssessmentOutcome,
    RecordedEvent,
    TriageDecision,
} from './alerts-types';
import { routeSummary } from './map-driving-model';

export const ALERT_FILTERS = [
    { value: 'open', label: 'Open' },
    { value: 'all', label: 'All' },
    { value: 'resolved', label: 'Resolved' },
] as const;

export const DECISIONS: Array<{
    value: TriageDecision;
    label: string;
    detail: string;
}> = [
    {
        value: 'maintenance',
        label: 'Maintenance assessment required',
        detail: 'Control Room decision · create or link Maintenance next',
    },
    {
        value: 'monitor',
        label: 'Monitor and follow up',
        detail: 'Control Room decision',
    },
    {
        value: 'escalate',
        label: 'Escalate response',
        detail: 'Control Room decision · raises the escalation level',
    },
    {
        value: 'no_action',
        label: 'No further action · evidence recorded',
        detail: 'Control Room decision',
    },
];

export const OUTCOMES: Array<{
    value: AssessmentOutcome;
    label: string;
    detail: string;
}> = [
    {
        value: 'incident_confirmed',
        label: 'Incident confirmed',
        detail: 'Human assessment',
    },
    { value: 'false_alarm', label: 'False alarm', detail: 'Human assessment' },
    {
        value: 'duplicate',
        label: 'Duplicate of reviewed incident',
        detail: 'Human assessment',
    },
    {
        value: 'unable_to_confirm',
        label: 'Unable to confirm · follow-up assigned',
        detail: 'Human assessment',
    },
];

export type LifecycleAction =
    | 'acknowledge'
    | 'triage'
    | 'escalate'
    | 'resolve'
    | 'retry';

const TERMINAL: AlertStatus[] = ['resolved', 'closed', 'dismissed'];

export function isTerminal(status: AlertStatus): boolean {
    return TERMINAL.includes(status);
}

export function statusLabel(item: {
    status: AlertStatus;
    escalation_level: number;
}): string {
    const escalated = item.escalation_level > 0;
    switch (item.status) {
        case 'delivery_failed':
            return 'Delivery failed';
        case 'delivery_pending':
            return 'Waiting for Control Room';
        case 'open':
            return escalated ? 'Escalated' : 'New';
        case 'ack':
            return escalated ? 'Escalated' : 'Acknowledged';
        case 'triaging':
            return escalated ? 'Escalated' : 'Triaged';
        case 'confirmed':
            return escalated ? 'Escalated' : 'Confirmed';
        case 'dismissed':
            return 'Dismissed';
        default:
            return 'Resolved';
    }
}

export function statusVariant(status: AlertStatus): StatusVariant {
    if (status === 'delivery_failed') return 'critical';
    if (status === 'delivery_pending') return 'info';
    if (status === 'dismissed') return 'neutral';
    return isTerminal(status) ? 'success' : 'warning';
}

/** The design's action set: New → acknowledge/triage/escalate; open → triage/escalate/resolve. */
export function availableActions(status: AlertStatus): LifecycleAction[] {
    if (status === 'delivery_failed') return ['retry'];
    if (status === 'delivery_pending') return [];
    if (isTerminal(status)) return [];
    if (status === 'open') return ['acknowledge', 'triage', 'escalate'];
    return ['triage', 'escalate', 'resolve'];
}

export const ACTION_LABELS: Record<LifecycleAction, string> = {
    acknowledge: 'Acknowledge',
    triage: 'Triage',
    escalate: 'Escalate',
    resolve: 'Resolve',
    retry: 'Retry delivery',
};

export function minutesBetween(
    from: string | null,
    now: number,
): number | null {
    if (!from) return null;
    const at = Date.parse(from);
    return Number.isFinite(at)
        ? Math.max(0, Math.floor((now - at) / 60000))
        : null;
}

/** The queue row's response-clock line, from Control Room's acknowledgement target. */
export function ackText(item: AlertItem, now: number): string {
    if (item.status === 'delivery_failed') return 'Delivery retry required';
    if (item.status === 'delivery_pending')
        return 'Waiting for Control Room receipt';
    if (item.acknowledged_at) return 'Acknowledged';
    if (isTerminal(item.status)) return 'Closed without acknowledgement';
    if (!item.acknowledge_by) return 'No acknowledgement target set';
    const due = Date.parse(item.acknowledge_by);
    if (item.acknowledge_breached || due <= now)
        return item.escalation_level > 0
            ? `Acknowledgement overdue · escalated to level ${item.escalation_level}`
            : 'Acknowledgement overdue';
    return `Acknowledge in ${Math.max(0, Math.ceil((due - now) / 60000))} min`;
}

export function deadlineText(detail: AlertDetail, now: number): string {
    if (detail.acknowledged_at)
        return `Acknowledged · ${formatDateTime(detail.acknowledged_at)}`;
    if (isTerminal(detail.status)) return 'Closed';
    if (!detail.acknowledge_by)
        return 'No acknowledgement target set in Control Room';
    const due = Date.parse(detail.acknowledge_by);
    if (detail.acknowledge_breached || due <= now)
        return detail.escalation_level > 0
            ? `Overdue since ${formatDateTime(detail.acknowledge_by)} · escalated to level ${detail.escalation_level}`
            : `Overdue since ${formatDateTime(detail.acknowledge_by)}`;
    return `${Math.max(0, Math.ceil((due - now) / 60000))} min remaining · by ${formatDateTime(detail.acknowledge_by)}`;
}

export function duplicatesText(duplicates: number): string {
    return duplicates > 0
        ? `${duplicates} duplicate ${duplicates === 1 ? 'report' : 'reports'} correlated`
        : 'Original signal retained';
}

export function noticeFor(kindKey: string): { title: string; body: string } {
    return {
        title:
            kindKey === 'collision'
                ? 'Potential collision, not a confirmed accident'
                : 'Assess context before concluding',
        body: 'Confirm occupant welfare and circumstances through the approved response process. Closing this alert does not release the vehicle or close linked work.',
    };
}

export function routeSource(tracker: { model: string | null }): string {
    return tracker.model ? `${tracker.model} event` : 'Tracker event';
}

export function planHeading(plan: AlertPlan | null): string {
    if (!plan) return 'No response plan drafted';
    return `${plan.owner?.name ?? 'Owner not set'} → ${plan.backup?.name ?? 'Backup not set'}`;
}

export function planParagraph(plan: AlertPlan | null): string {
    if (!plan)
        return 'Draft who responds and which overspeed, voltage and reporting thresholds Control Room should review against. A draft is not a live device setting.';
    return `Overspeed: above ${plan.speed_threshold_kph} km/h plus ${plan.speed_tolerance_kph} km/h tolerance for ${plan.speed_duration_s} seconds → Control Room. A new episode needs ${plan.speed_cooldown_s} seconds below the trigger. Potential collision: urgent human review. Low voltage: below ${plan.low_voltage_v.toLocaleString('en-NZ', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} V for ${plan.low_voltage_minutes} minutes. Tracker overdue: ${plan.offline_minutes} minutes after an expected report.`;
}

export type PlanForm = {
    owner_user_id: number | null;
    backup_user_id: number | null;
    speed_threshold_kph: string;
    speed_tolerance_kph: string;
    speed_duration_s: string;
    speed_cooldown_s: string;
    offline_minutes: string;
    low_voltage_v: string;
    low_voltage_minutes: string;
    notes: string;
};

export const PLAN_PRESETS: Record<
    | 'speed_threshold_kph'
    | 'speed_tolerance_kph'
    | 'speed_duration_s'
    | 'speed_cooldown_s',
    string[]
> = {
    speed_threshold_kph: ['30', '50', '80', '100'],
    speed_tolerance_kph: ['3', '5', '10'],
    speed_duration_s: ['10', '15', '30', '60'],
    speed_cooldown_s: ['30', '60', '120'],
};

export function planFormFrom(plan: AlertPlan | null): PlanForm {
    return {
        owner_user_id: plan?.owner?.id ?? null,
        backup_user_id: plan?.backup?.id ?? null,
        speed_threshold_kph: plan ? String(plan.speed_threshold_kph) : '',
        speed_tolerance_kph: plan ? String(plan.speed_tolerance_kph) : '',
        speed_duration_s: plan ? String(plan.speed_duration_s) : '',
        speed_cooldown_s: plan ? String(plan.speed_cooldown_s) : '',
        offline_minutes: plan ? String(plan.offline_minutes) : '',
        low_voltage_v: plan ? String(plan.low_voltage_v) : '',
        low_voltage_minutes: plan ? String(plan.low_voltage_minutes) : '',
        notes: plan?.notes ?? '',
    };
}

const whole = (value: string) => /^\d+$/.test(value.trim());

/** Mirrors the server rules for a draft plan, by wizard step. */
export function planStepError(form: PlanForm, step: number): string {
    if (step === 0) {
        if (!form.owner_user_id || !form.backup_user_id)
            return 'Choose the primary and backup response owners.';
        if (form.owner_user_id === form.backup_user_id)
            return 'Choose a different person as the backup owner.';
        return '';
    }
    if (step === 1) {
        const values = [
            form.speed_threshold_kph,
            form.speed_tolerance_kph,
            form.speed_duration_s,
            form.speed_cooldown_s,
        ];
        if (
            !values.every((value) => whole(value) && Number(value) > 0) ||
            Number(form.speed_threshold_kph) > 150 ||
            Number(form.speed_tolerance_kph) > 20
        )
            return 'Use positive speed and duration values; fleet threshold up to 150 km/h and tolerance up to 20 km/h.';
        return '';
    }
    if (step === 2) {
        const voltage = Number(form.low_voltage_v);
        if (
            !whole(form.offline_minutes) ||
            Number(form.offline_minutes) <= 0 ||
            !Number.isFinite(voltage) ||
            voltage < 8 ||
            voltage > 32 ||
            !whole(form.low_voltage_minutes) ||
            Number(form.low_voltage_minutes) <= 0
        )
            return 'Use positive durations and a vehicle threshold within 8–32 V.';
        if (!form.notes.trim())
            return 'Record the response, cooldown and escalation instructions.';
        return '';
    }
    return '';
}

/** Picker option for a recorded event that can be sent to Control Room. */
export function recordedEventOption(
    event: RecordedEvent,
    when: (iso: string) => string,
): { value: string; label: string; description: string } {
    const sent = routeSummary(event.route);
    if (event.kind === 'overspeed') {
        const peak =
            event.peak_kph === null
                ? 'Peak not recorded'
                : `${Math.round(event.peak_kph)} km/h peak`;
        const seconds =
            event.seconds === null
                ? 'duration not recorded'
                : `${event.seconds} sec at or above the fleet threshold`;
        return {
            value: event.source_key,
            label: `Overspeed · ${peak} · ${when(event.at)}`,
            description: [
                event.trip_reference,
                seconds,
                'road limit not checked',
                sent,
            ]
                .filter(Boolean)
                .join(' · '),
        };
    }
    return {
        value: event.source_key,
        label: `${event.title} · ${when(event.at)}`,
        description: [event.detail, sent].filter(Boolean).join(' · '),
    };
}
