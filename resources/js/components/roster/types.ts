import type { ShiftState } from '@/lib/status-vocab';

export interface RosterClient {
    id: number;
    name: string;
    photo_url: string | null;
}

export interface RosterTask {
    id: number;
    label: string;
    scheduled_time?: string | null;
    scheduled_for?: string | null;
    is_completed: boolean;
    completed_at: string | null;
}

export interface RosterTimesheet {
    id: number;
    status: string;
    return_notes: string | null;
}

export interface RosterShift {
    id: number;
    starts_at: string | null;
    ends_at: string | null;
    actual_starts_at: string | null;
    actual_ends_at: string | null;
    status: string;
    status_state: ShiftState;
    location: string | null;
    service_type: string | null;
    client: RosterClient | null;
    tasks: RosterTask[];
    task_progress: number;
    is_today: boolean;
    day_key: string | null;
    timesheet: RosterTimesheet | null;
}
