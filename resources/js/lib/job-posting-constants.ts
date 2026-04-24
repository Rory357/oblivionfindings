/**
 * Shared constants for job postings across admin and public pages.
 * Single source of truth — import from here instead of redefining.
 */

/* ------------------------------------------------------------------ */
/*  Status badge config (admin)                                        */
/* ------------------------------------------------------------------ */

export const statusConfig: Record<string, { className: string; label: string }> = {
    draft: { className: 'border-slate-500/30 text-muted-foreground bg-slate-500/10', label: 'Draft' },
    pending_approval: { className: 'border-amber-500/30 text-amber-400 bg-amber-500/10', label: 'Pending Approval' },
    published: { className: 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10', label: 'Published' },
    closed: { className: 'border-red-500/30 text-red-400 bg-red-500/10', label: 'Closed' },
};

/* ------------------------------------------------------------------ */
/*  Employment type labels                                             */
/* ------------------------------------------------------------------ */

export const employmentTypeLabels: Record<string, string> = {
    full_time: 'Full Time',
    part_time: 'Part Time',
    casual: 'Casual',
    fixed_term: 'Fixed Term',
};

/* ------------------------------------------------------------------ */
/*  Recruitment pipeline stage labels                                  */
/* ------------------------------------------------------------------ */

export const stageLabels: Record<string, string> = {
    new: 'New',
    active: 'Active',
    screening: 'Screening',
    interview_scheduled: 'Interview Scheduled',
    interview_completed: 'Interview Done',
    reference_check: 'Reference Check',
    offer_pending: 'Offer Pending',
    offer_sent: 'Offer Sent',
    offer_accepted: 'Offer Accepted',
    offered: 'Offer Extended',
    onboarding: 'Onboarding',
    hired: 'Hired',
    rejected: 'Rejected',
    withdrawn: 'Withdrawn',
};

/** Candidate-facing labels (more descriptive, less jargon) */
export const candidateStageLabels: Record<string, string> = {
    new: 'Application Received',
    active: 'Application Received',
    screening: 'Under Review',
    interview_scheduled: 'Interview Scheduled',
    interview_completed: 'Interview Completed',
    reference_check: 'Reference Check in Progress',
    offer_pending: 'Offer Being Prepared',
    offer_sent: 'Offer Sent',
    offer_accepted: 'Offer Accepted',
    offered: 'Offer Extended',
    onboarding: 'Onboarding',
    hired: 'Hired',
    rejected: 'Application Unsuccessful',
    withdrawn: 'Application Withdrawn',
};

/* ------------------------------------------------------------------ */
/*  Screening question types                                           */
/* ------------------------------------------------------------------ */

export const questionTypeLabels: Record<string, string> = {
    yes_no: 'Yes / No',
    text: 'Free Text',
    number: 'Number',
    date: 'Date',
    select: 'Multiple Choice',
};

/* ------------------------------------------------------------------ */
/*  Source channel labels                                               */
/* ------------------------------------------------------------------ */

export const sourceChannelOptions = [
    { value: 'career_page', label: 'Career page' },
    { value: 'linkedin', label: 'LinkedIn' },
    { value: 'seek', label: 'SEEK' },
    { value: 'indeed', label: 'Indeed' },
    { value: 'referral', label: 'Referral' },
    { value: 'agency', label: 'Agency' },
    { value: 'social', label: 'Social media' },
    { value: 'other', label: 'Other' },
] as const;
