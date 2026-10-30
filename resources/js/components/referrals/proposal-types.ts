import type { Page } from './types';

export type ProposalLine = {
    key: string;
    service_id: string;
    description: string;
    quantity: string | null;
    unit: string;
    unit_label: string | null;
    period: string;
    currency: string;
    unit_rate: string | null;
    tax_rate_id: number | null;
    price_basis: string;
    price_source: string;
    tax?: {
        id: number;
        name: string;
        code: string;
        type: string;
        rate: string;
    } | null;
    estimate?: {
        known: boolean;
        net: string | null;
        tax: string | null;
        gross: string | null;
    };
};
export type ProposalAllocation = {
    line_key: string;
    funding_case_id: number;
    decision_id: number;
    decision_line: number;
    quantity: string;
    support_quantity: string;
};
export type SupportProposal = {
    id: number;
    version: number;
    authored_by: number;
    authored_at: string;
    terms: {
        title: string;
        starts_on: string;
        ends_on: string;
        support_rationale: string;
        funding_position: string;
        capacity_plan: string;
        person_participation: string;
        reason: string;
        lines: ProposalLine[];
        allocations: ProposalAllocation[];
        totals: {
            currency: string;
            period: string;
            known: boolean;
            known_gross: string;
            unknown_lines: number;
        }[];
    };
};
export type ProposalReview = {
    id: number;
    kind: string;
    funding_case_id: number | null;
    result: string;
    recorded_by: number;
    recorded_at: string;
    details: Record<string, unknown>;
};
/** The draft service agreement made from one proposal version (ReferralServiceAgreements). */
export type ProposalAgreementRecord = {
    id: number;
    title: string | null;
    /** The agreement's Finance status, or "deleted" when it was removed there. */
    status: string;
    total_budget: string | null;
    gst_inclusive: boolean | null;
    starts_on: string | null;
    ends_on: string | null;
    proposal_id: number;
    proposal_version: number;
    created_at: string;
    created_by_name: string | null;
    outdated: boolean;
    warning: string | null;
    can_open: boolean;
    href: string;
};
export type ProposalAgreementLine = {
    key: string;
    description: string;
    service: string | null;
    proposal_quantity: string | null;
    proposal_unit: string;
    period: string;
    periods: number | null;
    unit: string | null;
    unit_price: string | null;
    quantity: string | null;
    budget: string | null;
    price_basis: string;
    funding_reference: string | null;
    /** Why this line cannot be copied yet; null when it maps cleanly. */
    issue: string | null;
};
export type ProposalAgreementData = {
    state: 'no_proposal' | 'not_converted' | 'blocked' | 'ready' | 'created';
    version: number;
    proposal: { id: number; version: number; title: string | null } | null;
    blockers: string[];
    can_create: boolean;
    permission_reason: string | null;
    person: { id: number; name: string } | null;
    preview: {
        header: {
            title: string;
            agreement_type: string;
            funding_type: string | null;
            funding_body: string | null;
            funding_reference: string | null;
            starts_at: string;
            ends_at: string;
            first_service_date: string | null;
            total_budget: string;
            gst_inclusive: boolean;
        };
        lines: ProposalAgreementLine[];
        currency: string;
    } | null;
    agreement: ProposalAgreementRecord | null;
    earlier: ProposalAgreementRecord[];
    approval_steps: { title: string; detail: string }[];
};
export type ProposalAgreementReceipt = {
    saved: boolean;
    replayed: boolean;
    proposal_version: number;
    agreement: {
        id: number;
        title: string | null;
        status: string;
        href: string;
        can_open: boolean;
    };
};
export type ProposalData = {
    proposals: Page<SupportProposal>;
    latest_id: number | null;
    current: SupportProposal | null;
    tax_rates: {
        id: number;
        name: string;
        code: string;
        rate: string;
        type: string;
    }[];
    readiness: {
        ready: boolean;
        issues: string[];
        internal_approval_current: boolean;
        person_acceptance_current: boolean;
        private_payers: {
            funding_case_id: number;
            payer: string;
            accepted: boolean;
        }[];
        coverage: {
            usable: boolean;
            issues: string[];
            lines: {
                line_key: string;
                covered_quantity: string;
                requested_quantity: string | null;
                unit: string;
                period: string;
            }[];
        };
    } | null;
};
