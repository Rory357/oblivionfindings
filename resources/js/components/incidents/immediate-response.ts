/**
 * Decision 18 (Stephan, 1 Nov 2026, board G4-2): the incident report's "Straight away" ticks. The report form asks
 * them (each with the time it was done); the incident page shows them under "Done straight away". The keys match
 * App\Support\Incidents\ImmediateResponse::KEYS.
 */
export type ImmediateResponseKey =
    | 'made_safe'
    | 'first_aid'
    | 'medical_rung'
    | 'manager_told'
    | 'family_told';

/** One row of the form: ticked or not, the wall-clock time (HH:mm) and an optional short note. */
export type ImmediateResponseRow = {
    key: ImmediateResponseKey;
    done: boolean;
    time: string;
    detail: string;
};

/** One stored tick, as the incident payload carries it. */
export type ImmediateResponseTick = {
    key: ImmediateResponseKey | string;
    done: boolean;
    at: string | null;
    detail: string | null;
};

export const IMMEDIATE_RESPONSE: {
    key: ImmediateResponseKey;
    label: string;
    /** The note's label, when the row asks for one. */
    detail?: string;
    placeholder?: string;
}[] = [
    { key: 'made_safe', label: 'Checked the person and made them safe' },
    {
        key: 'first_aid',
        label: 'First aid given',
        detail: 'Who gave it',
        placeholder: 'e.g. you, or their name',
    },
    {
        key: 'medical_rung',
        label: 'Nurse, GP or 111 rung',
        detail: 'Who you rang',
        placeholder: 'e.g. On-call nurse',
    },
    {
        key: 'manager_told',
        label: 'Manager told',
        detail: 'Which manager',
        placeholder: 'e.g. their name',
    },
    {
        key: 'family_told',
        label: 'Family/whānau told',
        detail: 'Who, or when they’ll be told',
        placeholder: 'e.g. Hemi will ring tomorrow morning',
    },
];

export function immediateResponseLabel(key: string): string {
    return (
        IMMEDIATE_RESPONSE.find((item) => item.key === key)?.label ??
        key.replace(/_/g, ' ')
    );
}

export function emptyImmediateResponse(): ImmediateResponseRow[] {
    return IMMEDIATE_RESPONSE.map((item) => ({
        key: item.key,
        done: false,
        time: '',
        detail: '',
    }));
}

/** What the report sends: ticked rows, and unticked rows that carry a note. */
export function immediateResponsePayload(rows: ImmediateResponseRow[]) {
    return rows
        .filter((row) => row.done || row.detail.trim())
        .map((row) => ({
            key: row.key,
            done: row.done,
            time: row.done ? row.time || null : null,
            detail: row.detail.trim() || null,
        }));
}

/** "7:45 pm" from "19:45" (a wall-clock time, no date). */
export function wallTime(value: string): string {
    const match = value.match(/^(\d{2}):(\d{2})$/);
    if (!match) return value;
    const hour = Number(match[1]);
    return `${hour % 12 || 12}:${match[2]} ${hour >= 12 ? 'pm' : 'am'}`;
}
