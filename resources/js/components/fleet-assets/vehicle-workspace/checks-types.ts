/** Server DTOs for Checks & inspections (VehicleChecksPresenter). */
import type { Person } from './types';

export type CheckQuestionKind =
    | 'condition'
    | 'text'
    | 'number'
    | 'select'
    | 'checkbox';

export type CheckOption = { value: string; label: string };

export type CheckQuestion = {
    id: string;
    label: string;
    kind: CheckQuestionKind;
    required: boolean;
    options: CheckOption[];
};

export type CheckAssignment =
    | 'all_vehicles'
    | 'accessible_vehicles'
    | 'vehicle';

/** A checklist in the library, at its current version. */
export type CheckTemplate = {
    id: number;
    /** Null until the current content is first recorded as a version. */
    version_id: number | null;
    version: number;
    name: string;
    use: string;
    assignment: CheckAssignment;
    assignment_label: string;
    evidence_required: boolean;
    items_sha256: string;
    questions: CheckQuestion[];
    source: 'library' | 'existing_template';
    published_at: string | null;
    published_by: string | null;
    /** The approved check rule covering this exact version, if any. */
    rule_version_id: number | null;
};

export type CheckRunAnswer = {
    id: string;
    label: string;
    value: string | null;
    note: string | null;
    evidence: { name: string; url: string | null } | null;
};

export type CheckRunFile = {
    id: number;
    name: string;
    state: string | null;
    url: string | null;
};

export type CheckRun = {
    id: number;
    reference: string;
    template: string;
    template_id: number;
    version: number | null;
    outcome: string;
    check_kind: string;
    rule_applied: boolean;
    observed_at: string | null;
    submitted_at: string | null;
    recorded_by: string | null;
    notes: string | null;
    answers: CheckRunAnswer[];
    files: CheckRunFile[];
    evidence_count: number;
    amendments: Array<{
        id: number;
        note: string;
        recorded_by: string | null;
        recorded_at: string | null;
    }>;
    linked: boolean;
    linked_work: {
        id: number;
        reference: string | null;
        title: string | null;
        status: string;
    } | null;
};

export type CheckRequirement = {
    template_id: number | null;
    source: 'vehicle' | 'approved_rule' | 'library' | null;
    due_on: string | null;
    owner: Person | null;
    lock_version: number;
};

export type ReportRoute = {
    approved: boolean;
    coordinator: string | null;
    backup: string | null;
    site: string | null;
};

export type ChecksCan = {
    start: boolean;
    amend: boolean;
    manage_templates: boolean;
    manage_requirement: boolean;
    upload: boolean;
    report: boolean;
    link_work: boolean;
    view_maintenance: boolean;
    view_files: boolean;
    view_answer_files: boolean;
};

export type VehicleChecks = {
    requirement: CheckRequirement;
    templates: CheckTemplate[];
    runs: { data: CheckRun[]; total: number };
    route: ReportRoute | null;
    can: ChecksCan;
};
