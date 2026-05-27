export type JobPostScheduleConflict = {
    type: string;
    label: string | null;
    severity: string;
};

export type JobPostScheduleFatigue = {
    label: string | null;
    severity: string;
};

export type JobPostScheduleTimeOff = {
    label: string | null;
};

export type JobPostSchedule = {
    conflict: JobPostScheduleConflict | null;
    time_off: JobPostScheduleTimeOff | null;
    fatigue: JobPostScheduleFatigue | null;
    free: boolean;
};

export type JobPostTaskKind = 'med' | 'meal' | 'care' | 'access';

export type JobPostTask = {
    label: string;
    kind: JobPostTaskKind | string;
};

export type JobPostEligibility = {
    is_eligible: boolean;
    blocked_reasons: string[];
    warning_count: number;
    first_warning: string | null;
};

export type JobPostClient = {
    id: number;
    first_name: string;
    last_name: string;
    display_name: string;
    suburb: string | null;
    is_redacted: boolean;
};

export type JobPostReplacement = {
    id: number;
    status: string;
    reason: string | null;
    requested_at?: string | null;
    current_staff?: { id: number; name: string } | null;
    requested_by?: { id: number; name: string } | null;
    replacement_staff?: { id: number; name: string } | null;
};

export type JobPost = {
    id: number;
    title: string;
    status: string;
    date: string | null;
    start_time: string | null;
    end_time: string | null;
    location: string | null;
    required_skills: string[];
    your_skills: string[];
    coverage_roles: string[];
    coverage: string | null;
    privacy: {
        can_view_sensitive_details: boolean;
    };
    client: JobPostClient | null;
    replacement: JobPostReplacement | null;
    claimed_by: { id: number; name: string } | null;
    eligibility: JobPostEligibility | null;
    viewer_eligibility: JobPostEligibility | null;
    your_schedule: JobPostSchedule | null;
    tasks: JobPostTask[];
    tasks_total: number;
    past_shifts_here: number;
    site_familiar: boolean;
};

export type JobBoardStats = {
    open: number;
    claimed: number;
    filled_today: number;
    eligible_for_you: number;
    expiring_soon: number;
    mine: number;
    replacements: number;
};

export type JobBoardWeek = {
    start: string;
    end: string;
    start_label: string;
    end_label: string;
    prev: string;
    next: string;
    is_current: boolean;
};

export type JobBoardScope = 'for-you' | 'all' | 'mine' | 'replacements';
