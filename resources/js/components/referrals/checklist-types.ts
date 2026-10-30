export type ChecklistItem = {
    id: string;
    title: string;
    instruction: string | null;
    enabled: boolean;
    required: boolean;
};
export type ChecklistTemplate = {
    id: string;
    label: string;
    enabled: boolean;
    audience: 'intake' | 'assessment' | 'funding';
    role_id: number | null;
    service_ids: string[];
    gate: 'none' | 'assessment' | 'funding_assistance' | 'handover';
    items: ChecklistItem[];
};
export type ChecklistDecision = {
    completed: boolean;
    note: string;
    recorded_at: string;
    recorded_by: number;
    recorded_by_name: string;
    settings_version_id: number;
    template_label: string;
    item: ChecklistItem;
    signature: string;
};
export type ChecklistRecord = Omit<ChecklistTemplate, 'items'> & {
    editable: boolean;
    items: (ChecklistItem & {
        completed: boolean;
        decision: ChecklistDecision | null;
    })[];
};
export const checklistGateLabel = (gate: string) =>
    ({
        none: 'Tracking only',
        assessment: 'Before assessment',
        funding_assistance: 'Before funding assistance',
        handover: 'Before handover',
    })[gate] ?? gate;
