import type { WorklistsPayload } from './worklists';

/** Search only the current server preview; totals and source-access state remain authoritative. */
export function searchDashboardWorklists(worklists: WorklistsPayload, query: string): WorklistsPayload {
    const terms = query.trim().toLocaleLowerCase('en-NZ').split(/\s+/).filter(Boolean);
    const matches = (...values: Array<string | null | undefined>) => {
        const text = values.filter(Boolean).join(' ').toLocaleLowerCase('en-NZ');
        return terms.every((term) => text.includes(term));
    };
    return {
        ...worklists,
        attention: worklists.attention.map((group) => ({ ...group, items: group.items.filter((row) => matches(row.event_reference, row.title, row.site, row.client, row.owner)) })),
        overdue_corrective_actions: worklists.overdue_corrective_actions.filter((row) => matches(row.reference, row.title, row.owner, row.event_reference)),
        open_investigations: worklists.open_investigations.filter((row) => matches(row.reference, row.type, row.owner, row.event_reference)),
        notifiable_events: worklists.notifiable_events.filter((row) => matches(row.event_reference, row.title, row.incident_type, row.worksafe_ref)),
        expiring: worklists.expiring.filter((row) => {
            if (row.type.startsWith('ppe_') && worklists.ppe_expiring_state?.source_integrity === 'restricted') return false;
            return row.type === 'ppe_repair' ? matches('Equipment source needs review', row.site) : matches(row.label, row.type_label, row.site);
        }),
    };
}
