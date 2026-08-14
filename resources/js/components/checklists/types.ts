// Shared types for the Checklists workspace — the contract produced by
// app/Support/ChecklistsDashboardData.php (forOrg / forSite).

export type CategoryTone =
    | 'critical'
    | 'warning'
    | 'success'
    | 'info'
    | 'ops'
    | 'sites'
    | 'fleet'
    | 'governance'
    | 'compliance';

export type ResponseType =
    | 'yes_no'
    | 'yes_no_na'
    | 'pass_fail'
    | 'numeric'
    | 'text'
    | 'photo';

export interface Category {
    key: string;
    label: string;
    short: string;
    icon: string;
    tone: CategoryTone;
    blurb: string;
}

export interface TemplateFlags {
    hazard: boolean;
    photo: boolean;
    sign: boolean;
}

export interface SiteRef {
    id: number;
    name: string;
    type?: string;
}

export interface TemplateRef {
    id: number;
    name: string;
    frequency?: string;
    category?: string | null;
    flags?: TemplateFlags;
}

export interface ChecklistTemplate {
    id: number;
    key: string;
    name: string;
    description?: string | null;
    category: string | null;
    applicable_to_type: 'house' | 'head_office' | 'facility' | 'all';
    frequency: string;
    is_active: boolean;
    items_count: number;
    assignments_count: number;
    flags: TemplateFlags;
    spotlight: boolean;
}

export interface ChecklistRun {
    id: number;
    status: 'scheduled' | 'in_progress' | 'completed' | 'overdue' | 'skipped';
    can_run: boolean;
    scheduled_date: string | null;
    started_at?: string | null;
    completed_at?: string | null;
    pct: number;
    completion_percentage: number;
    is_overdue?: boolean;
    items_passed?: number;
    items_failed?: number;
    site: SiteRef | null;
    template: TemplateRef | null;
    assignee: string;
    assigned_to_id?: number | null;
}

export interface AssignableUser {
    id: number;
    name: string;
}

export interface ChecklistAssignment {
    id: number;
    frequency: string;
    site: SiteRef | null;
    template: TemplateRef | null;
    assignee: string;
}

export interface SiteOverview {
    id: number;
    name: string;
    type: string;
    active_assignments: number;
    overdue_runs: number;
    scheduled_runs: number;
    on_track_rate: number;
}

export interface ReportCategory {
    key: string;
    label: string;
    tone: CategoryTone;
    rate: number;
    overdue: number;
}

export interface TrendPoint {
    w: string;
    done: number;
    overdue: number;
}

export interface TopFailure {
    item: string;
    cat: string;
    count: number;
    hazards: number;
}

export interface Reports {
    complianceByCategory: ReportCategory[];
    trend: TrendPoint[];
    topFailures: TopFailure[];
}

export interface RunItemDef {
    id: number;
    question: string;
    response_type: ResponseType;
    response_config: { min?: number; max?: number; unit?: string } | null;
    is_required: boolean;
    guidance?: string | null;
    failure_creates_hazard: boolean;
    failure_creates_damage: boolean;
}

export interface RunResponse {
    template_item_id: number;
    response_value: string | null;
    notes: string | null;
    photo_path: string | null;
    is_failed: boolean;
}

export interface RunDetail {
    id: number;
    status: ChecklistRun['status'];
    can_run: boolean;
    scheduled_date: string | null;
    completion_percentage: number;
    overall_notes: string | null;
    site: SiteRef;
    template: TemplateRef & { category: string | null; flags: TemplateFlags };
    items: RunItemDef[];
    responses: RunResponse[];
}

export interface TemplateDetailItem {
    id?: number;
    question: string;
    response_type: ResponseType;
    response_config: { min?: number; max?: number; unit?: string } | null;
    is_required: boolean;
    guidance?: string | null;
    failure_creates_hazard: boolean;
    failure_creates_damage: boolean;
    has_responses?: boolean;
}

export interface TemplateDetail {
    id: number;
    key: string;
    name: string;
    description: string | null;
    category: string | null;
    applicable_to_type: 'house' | 'head_office' | 'facility' | 'all';
    frequency: string;
    is_active: boolean;
    requires_photo: boolean;
    requires_signature: boolean;
    assignments_count: number;
    items: TemplateDetailItem[];
}

export interface ChecklistCan {
    view: boolean;
    manageTemplates: boolean;
    schedule: boolean;
    run: boolean;
}

export interface ChecklistStats {
    onTrack: number;
    overdue: number;
    dueToday: number;
    inProgress: number;
    scheduled: number;
    completed30: number;
    failures: number;
}

export interface ChecklistsData {
    categories: Category[];
    frequencyLabels: Record<string, string>;
    typeLabels: Record<string, string>;
    today: string;
    templates: ChecklistTemplate[];
    activeRuns: ChecklistRun[];
    recentRuns: ChecklistRun[];
    skippedRuns: ChecklistRun[];
    assignments: ChecklistAssignment[];
    assignableUsers: AssignableUser[];
    sitesOverview: SiteOverview[];
    reports: Reports;
    stats: ChecklistStats;
    runDetail: RunDetail | null;
    templateDetail: TemplateDetail | null;
    can: ChecklistCan;
}

export type ChecklistScope =
    | { mode: 'org' }
    | { mode: 'site'; site: SiteRef; backHref: string };
