import type { OutstandingMedicationWorkData } from '@/components/emar/followups/types';
import type {
    ClientInfo,
    CompetencyNotice,
    NotGivenReasonOption,
    PrnMedication,
    ScheduleRow,
    WitnessOption,
} from '@/pages/meds/today/types';

/**
 * A dose on the day view: Meds today's schedule row, plus the projection's
 * raw state and window so the grid can say "Due now" (inside the window)
 * from "Due" (shows as due soon) and "Not recorded" (an earlier day) from
 * "Overdue" (today).
 */
export type DayDose = ScheduleRow & {
    state?: string | null;
    window_opens_at?: string;
    window_ends_at?: string;
    support_mode?: 'self_managed' | 'prompted' | 'assisted' | 'staff_given';
};

export interface DayMedicine {
    id: number;
    /** Separates immutable historical versions while retaining the canonical order id. */
    key?: string;
    name: string;
    dose: string | null;
    route: string | null;
    is_controlled: boolean;
    requires_witness: boolean;
    /** Doses keyed by dose time (HH:mm), usually one each. */
    cells: Record<string, DayDose[]>;
}

export type DayPrnMedication = PrnMedication & {
    key?: string;
    given_on_day: number;
    last_given_on_day: string | null;
    is_today: boolean;
};

export interface DayAllergyEntry {
    allergen: string;
    severity: string | null;
    reaction: string | null;
    source: string;
}

export interface MedicationDay extends OutstandingMedicationWorkData {
    date: string;
    today: string;
    tomorrow: string;
    now: string;
    timezone: string;
    coverage: {
        available_from: string;
        complete: boolean;
        notice: string | null;
    };
    times: string[];
    medicines: DayMedicine[];
    hidden_controlled: { total: number; overdue: number };
    prn: { rows: DayPrnMedication[]; hidden: number };
    allergies: {
        status: 'recorded' | 'none' | 'no_known' | 'unavailable';
        entries: DayAllergyEntry[];
        reviewed?: { at: string; by: string | null; how: string } | null;
    };
    chart_alerts: { id: number | null; type: string | null; title: string }[];
    can: {
        record: boolean;
        record_reason: 'no_permission' | 'no_shift' | null;
        record_controlled: boolean;
        report: boolean;
    };
    recorder: {
        client: ClientInfo | null;
        witnesses: WitnessOption[];
        not_given_reasons: NotGivenReasonOption[];
        signed_as: {
            name: string;
            role_label: string | null;
            competency_notice?: CompetencyNotice | null;
        };
    } | null;
}
