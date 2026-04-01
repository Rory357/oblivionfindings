/**
 * Shared TypeScript types for job postings feature.
 */

/* ------------------------------------------------------------------ */
/*  Screening questions                                                */
/* ------------------------------------------------------------------ */

export type ScreeningQuestion = {
    id: string;
    question: string;
    type: 'yes_no' | 'text' | 'number' | 'date' | 'select';
    required: boolean;
    options?: string[];
};

/* ------------------------------------------------------------------ */
/*  Admin types (HR internal pages)                                    */
/* ------------------------------------------------------------------ */

/** Job posting as returned to the admin index list */
export type JobPostingListItem = {
    id: number;
    title: string;
    slug: string | null;
    department: string | null;
    location: string | null;
    employment_type: string;
    is_remote: boolean;
    is_internal: boolean;
    status: string;
    published_at: string | null;
    closes_at: string | null;
    applications_count: number;
    views_count: number;
    position: { id: number; title: string } | null;
    hiring_manager: string | null;
    created_by: string | null;
    created_at: string;
};

/** Job posting as returned to the admin detail/show page */
export type JobPostingDetail = {
    id: number;
    title: string;
    slug: string | null;
    summary: string | null;
    department: string | null;
    location: string | null;
    employment_type: string;
    is_remote: boolean;
    is_internal: boolean;
    description: string;
    requirements: string | null;
    responsibilities: string | null;
    salary_range_min: number | null;
    salary_range_max: number | null;
    show_salary: boolean;
    salary_range: string | null;
    status: string;
    requires_approval: boolean;
    published_at: string | null;
    closes_at: string | null;
    approved_at: string | null;
    applications_count: number;
    views_count: number;
    screening_questions: ScreeningQuestion[];
    notification_emails: string[];
    position: { id: number; title: string } | null;
    hiring_manager: { id: number; name: string } | null;
    approved_by: string | null;
    created_by: string | null;
    created_at: string;
};

/** Job posting form data (create/edit) */
export type JobPostingFormData = {
    id: number;
    title: string;
    slug: string | null;
    position_id: number | null;
    department: string | null;
    location: string | null;
    employment_type: string;
    is_remote: boolean;
    is_internal: boolean;
    summary: string | null;
    description: string;
    requirements: string | null;
    responsibilities: string | null;
    salary_range_min: number | null;
    salary_range_max: number | null;
    show_salary: boolean;
    requires_approval: boolean;
    hiring_manager_id: number | null;
    notification_emails: string[] | null;
    screening_questions: ScreeningQuestion[] | null;
    closes_at: string | null;
};

/* ------------------------------------------------------------------ */
/*  Public types (career portal pages)                                 */
/* ------------------------------------------------------------------ */

/** Job posting as shown on public career listing */
export type PublicJobListing = {
    id: number;
    slug: string;
    title: string;
    summary: string | null;
    department: string | null;
    location: string | null;
    employment_type: string;
    is_remote: boolean;
    salary_range: string | null;
    published_at: string | null;
    closes_at: string | null;
};

/** Job posting as shown on public detail page */
export type PublicJobDetail = PublicJobListing & {
    description: string;
    requirements: string | null;
    responsibilities: string | null;
    screening_questions: ScreeningQuestion[];
};

/* ------------------------------------------------------------------ */
/*  Application types                                                  */
/* ------------------------------------------------------------------ */

export type RecentApplication = {
    id: number;
    candidate_name: string;
    candidate_email: string | null;
    candidate_stage: string | null;
    applied_at: string;
    status: string;
};

export type ApplicationStatus = {
    position_title: string;
    applied_at: string;
    status: string;
    status_label: string;
    posting: {
        title: string;
        department: string | null;
        location: string | null;
    } | null;
};
