/**
 * Shared types for the Health & Safety Analytics page — mirrors the payload
 * built by App\Services\HealthSafety\HsAnalyticsService.
 *
 * NZ-only metrics: LTIFR / TRIFR (per 1,000,000 hours), WorkSafe notifiable
 * events, Nga Paerewa NZS 8134:2021, ACC. Never the US "TRIR".
 */

export type TrendDir = 'improving' | 'watch' | 'flat';

export type TrendPoint = {
    month: string;
    label: string;
    ltifr: number | null;
    trifr: number | null;
    incidents: number;
    near_miss_ratio: number;
    hazards_opened: number;
    hazards_closed: number;
    hazards_open: number;
    ca_avg_days: number | null;
    ca_pct_on_time: number | null;
    compliance_pct: number | null;
    worker_engagement: number | null;
    worker_consultation: number | null;
    worksafe_notified: number;
    worksafe_awaiting: number;
};

export type HeroStat = {
    value: number | null;
    delta: number | null;
    dir: TrendDir;
};

export type ScorecardItem = {
    key: string;
    label: string;
    value: number | null;
    suffix: string;
    delta: number | null;
    dir: string;
};

export type SiteRow = {
    id: number;
    name: string;
    total_incidents: number;
    open_hazards: number;
    lost_time_days: number;
    ltifr: number | null;
    trifr: number | null;
    drill_status: string;
    compliance_score: number;
};

export type RootCauseRow = {
    cause: string;
    count: number;
    pct: number;
    cumulative_pct: number;
};

export type TypeCount = { type: string; count: number };
export type SeverityCount = { severity: string; count: number };
export type BodyPartCount = { body_part: string; count: number };
export type RiskCount = { risk_rating: string; count: number };

export type AnalyticsFilters = {
    period: string;
    from: string;
    to: string;
    site_id: number | null;
    lens: string;
};

export type AnalyticsProps = {
    incident_data: TypeCount[];
    severity_data: SeverityCount[];
    root_cause_data: RootCauseRow[];
    injury_data: {
        by_type: TypeCount[];
        by_body_part: BodyPartCount[];
    };
    hazard_data: RiskCount[];
    site_comparison: SiteRow[];
    trends: TrendPoint[];
    hero_stats: {
        ltifr: HeroStat;
        trifr: HeroStat;
        near_miss_ratio: HeroStat;
        compliance_pct: HeroStat;
    };
    scorecard: {
        leading: ScorecardItem[];
        lagging: ScorecardItem[];
    };
    period_summary: {
        incidents: number;
        near_misses: number;
        worksafe_awaiting: number;
        open_hazards: number;
        actions_on_time_pct: number | null;
        drills_complete: number;
        drills_total: number;
    };
    worksafe_notifiable: { notified: number; awaiting: number };
    hours_meta: { source: string; total_hours: number };
    role_note: string;
    filters: AnalyticsFilters;
    sites: { id: number; name: string }[];
    active_site: { id: number; name: string } | null;
    site_brand_colour: string | null;
};

/** A drill target — what was clicked, for the read-only detail modal + ctx menu. */
export type DrillTarget = {
    view: 'incidents' | 'injuries' | 'hazards' | 'sites' | 'root_cause';
    label: string;
    /** extra query filters for the records endpoint (type/severity/cause/risk/body_part/site_id) */
    filters?: Record<string, string | number>;
    /** the register to "open" from the modal Options bar */
    register?: string;
    clientHref?: string;
    staffHref?: string;
};
