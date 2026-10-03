/** The person medication record's data (eMAR P02, approved v1). */

export type RecordSupport = 'administer' | 'assist' | 'prompt' | 'independent';

export interface RecordPerson {
    id: number;
    name: string;
    preferred: string;
    initials: string;
    age: number | null;
    nhi: string | null;
    status: string | null;
    house: string | null;
    service: string | null;
}

export interface RecordMeters {
    medicines: {
        count: number;
        hidden: number;
        as_needed: number;
        to_check: number;
    };
    allergies: {
        status: 'recorded' | 'none' | 'no_known' | 'unavailable';
        count: number;
        reviewed?: { at: string; by: string | null; how: string } | null;
    };
    inr: {
        value: number;
        tested: string | null;
        target: [number, number] | null;
        next: string | null;
    } | null;
    driver:
        | { concealed: true }
        | { concealed: false; last_check: string | null }
        | null;
}

export interface RecordMedicine {
    key: string;
    id: number;
    name: string;
    strength: string | null;
    amount: string | null;
    route: string | null;
    form: string | null;
    kind: 'scheduled' | 'prn';
    when: string;
    support: RecordSupport;
    status: 'active' | 'awaiting' | 'paused' | 'stopped';
    controlled: boolean;
    witness: boolean;
    high_risk: boolean;
    started: string | null;
    verified: { at: string; by: string | null } | null;
    stopped: { at: string; by: string | null; reason: string | null } | null;
}

/** A controlled medicine shown to a reader without controlled-medicine access. */
export interface ConcealedRow {
    concealed: true;
    key: string;
}

export type MaybeConcealed<T> = T | ConcealedRow;

export function isConcealed<T>(row: MaybeConcealed<T>): row is ConcealedRow {
    return (
        typeof row === 'object' &&
        row !== null &&
        (row as ConcealedRow).concealed === true
    );
}

export interface RecordMedicineDetail {
    medicine: RecordMedicine & {
        instructions: string | null;
        indication: string | null;
        prescriber: string | null;
        review: string | null;
    };
    recent_doses: {
        id: number;
        status: string;
        at: string | null;
        by: string | null;
    }[];
}

export interface RecordSupportPlan {
    assessment: {
        outcome: string;
        assessed: string | null;
        by: string | null;
        reassess: string | null;
        agreement: { signed: string; by: string | null } | null;
        storage: string | null;
    } | null;
    rows: MaybeConcealed<RecordMedicine>[];
    hidden: number;
}

/** The record page's props: the summary, or a boundary state. */
export type RecordPageProps =
    | {
          unavailable?: undefined;
          person: RecordPerson;
          meters: RecordMeters;
          can: {
              manage_orders: boolean;
              view_controlled: boolean;
              view_audit: boolean;
          };
          as_at: string;
      }
    | { unavailable: 'no_access' | 'not_found' };
