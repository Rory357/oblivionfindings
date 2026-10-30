export type StartPreview = {
    version: number;
    checked_at: string;
    person: { id: number; name: string; status: string };
    workflow: { status: string | null; url: string | null };
    proposal: {
        id: number;
        version: number;
        title: string;
        starts_on: string;
        ends_on: string;
        lines: {
            key: string;
            description: string;
            quantity: string | null;
            unit: string;
            unit_label: string | null;
            period: string;
            currency: string;
            unit_rate: string | null;
            price_basis: string;
        }[];
    } | null;
    agreements: {
        id: number;
        title: string;
        status: string;
        starts_on: string | null;
        ends_on: string | null;
        total_budget: string | null;
        gst_inclusive: boolean;
        fingerprint: string;
        lines: {
            id: number;
            description: string;
            unit: string;
            unit_price: string;
            quantity: string | null;
            budget_allocated: string;
            budget_used: string;
        }[];
    }[];
    care_plans: {
        id: number;
        title: string;
        status: string;
        version: number;
        next_review_on: string | null;
        digest: string | null;
    }[];
    staff: { id: number; name: string }[];
    more_options: boolean;
    start: {
        status: string;
        agreed_date: string | null;
        commenced_at: string | null;
        approved_at: string | null;
        review_required: boolean;
    } | null;
};
export type StartReadiness = {
    ready: boolean;
    issues: string[];
    agreement_mapping: {
        required_budget: string;
        currency: string;
        budget_basis: string;
        mapping: {
            line_key: string;
            periods: number;
            quantity: string;
            agreement_unit: string;
            required_budget: string;
        }[];
    };
};
export type StartOperation = 'schedule' | 'commence' | 'cancel';
