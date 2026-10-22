import type { Page } from './types';
export type FundingPathway = {
    id: string;
    label: string;
    personal_income: boolean;
    service_ids: string[];
    evidence_checklist: string[];
    source_url: string | null;
    verified_on: string | null;
    review_on: string | null;
    /** Who pays: private arrangements skip funder verification (Settings › Referrals). */
    payer_kind?: 'external' | 'private';
    /** Optional pathway contact directory entry, display only. */
    contact?: {
        organisation?: string | null;
        phone?: string | null;
        email?: string | null;
        website?: string | null;
        region_notes?: string | null;
    } | null;
};
export type FundingLine = {
    service_id: string | null;
    quantity: string | number | null;
    provider_quantity: string | number | null;
    unit: string;
    unit_label: string | null;
    period: string;
    currency: string;
    restrictions: string | null;
};
export type FundingDecision = {
    id: number;
    version: number;
    outcome: string;
    recorded_by: number;
    recorded_at: string;
    terms: {
        decision_maker: string;
        external_reference: string;
        decision_date: string;
        evidence_ids: number[];
        conditions: string;
        valid_from: string | null;
        valid_until: string | null;
        review_date: string | null;
        deadline_source: string | null;
        lines: FundingLine[];
        reason: string;
    };
};
export type FundingCase = {
    id: number;
    reference: string;
    version: number;
    pathway: string;
    pathway_label: string;
    status: string;
    personal_income: boolean;
    navigator_id: number;
    navigator_name: string | null;
    previous_case_id: number | null;
    settings_version_id: number;
    details: {
        service_id: string | null;
        starting_point: string;
        assistance_requested: string;
        person_review_plan: string;
        guide: FundingPathway;
        roles: Record<string, string | null>;
        external_contact?: {
            name: string | null;
            phone: string | null;
            email: string | null;
        };
        preparation: {
            item: string;
            status: string;
            note: string;
            evidence_ids: number[];
        }[];
        blocker?: string | null;
        submission_count: number;
        last_submission?: Record<string, unknown>;
        verification?: {
            decision_id: number;
            status: string;
            conditions_resolved?: boolean;
            provider_compatible?: boolean;
            scope_note?: string;
            review_due_at?: string;
            evidence_ids?: number[];
            verified_by?: number;
        };
        person_outcome?: string;
    };
    next_action: string | null;
    next_due_at: string | null;
    next_work_status: string | null;
    latest_decision: FundingDecision | null;
    availability: {
        usable: boolean;
        issues: string[];
        lines: FundingLine[];
        decision_id?: number;
        decision_version?: number;
    };
};
export type ReferralFundingData = {
    cases: Page<FundingCase>;
    authority: {
        assistance_permitted: boolean;
        version: number;
        sharing: {
            index: number;
            recipient: string;
            purpose: string;
            information: string;
            expires_at: string;
        }[];
    };
};
