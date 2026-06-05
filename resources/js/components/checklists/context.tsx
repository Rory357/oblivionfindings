import { createContext, useContext } from 'react';

import type {
    AssignableUser,
    Category,
    ChecklistAssignment,
    ChecklistCan,
    ChecklistRun,
    ChecklistScope,
    ChecklistTemplate,
    Reports,
    SiteOverview,
} from './types';

/** The scoped, filter-aware view of the data handed to each tab pane. */
export interface PaneCtx {
    runs: ChecklistRun[]; // active: scheduled + in_progress (overdue derived)
    history: ChecklistRun[]; // completed
    skippedRuns: ChecklistRun[];
    assignments: ChecklistAssignment[];
    templates: ChecklistTemplate[];
    sites: SiteOverview[]; // org: all sites; site mode: the single site
    reports: Reports;
    query: string;
    cat: string; // 'all' | category key
    setCat: (v: string) => void;
    today: string;
}

export type GoTab = (tab: string) => void;

export interface ChecklistConfig {
    categories: Category[];
    categoryMap: Record<string, Category>;
    freqLabels: Record<string, string>;
    typeLabels: Record<string, string>;
    today: string;
    can: ChecklistCan;
    scope: ChecklistScope;
    /** Org staff selectable as a run assignee (the Schedule "Reassign" action). */
    assignableUsers: AssignableUser[];
    /** Open the run modal for a run id (drives a partial reload of runDetail). */
    openRun: (runId: number) => void;
    /** Open the template builder modal — 'new' for create, or a template id to edit. */
    openBuilder: (template: number | 'new') => void;
}

const ChecklistConfigContext = createContext<ChecklistConfig | null>(null);

export const ChecklistConfigProvider = ChecklistConfigContext.Provider;

export function useChecklistConfig(): ChecklistConfig {
    const value = useContext(ChecklistConfigContext);
    if (!value) {
        throw new Error(
            'useChecklistConfig must be used within <ChecklistConfigProvider>',
        );
    }
    return value;
}

export function freqLabel(cfg: ChecklistConfig, key?: string | null): string {
    if (!key) return '—';
    return cfg.freqLabels[key] ?? key;
}

export function typeLabel(cfg: ChecklistConfig, key?: string | null): string {
    if (!key) return '';
    return cfg.typeLabels[key] ?? key;
}
