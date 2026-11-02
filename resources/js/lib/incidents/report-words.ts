/**
 * A reporter's four status words (boards G4-1 and G4-4, approved by Stephan 1 Nov 2026): the same words on My Day's
 * "What you reported" and on a support worker's Incidents list — never the register's own stages.
 */
import type { StatusVariant } from '@/components/ui/status-badge';

export type ReportWord = 'draft' | 'sent' | 'followed_up' | 'closed';

export const REPORT_WORDS: Record<
    ReportWord,
    { label: string; variant: StatusVariant }
> = {
    draft: { label: 'Draft', variant: 'warning' },
    sent: { label: 'Sent', variant: 'neutral' },
    followed_up: { label: 'Being followed up', variant: 'info' },
    closed: { label: 'Closed', variant: 'success' },
};

/** The word for an incident: Sent until someone has taken it on; Being followed up once they have. */
export function incidentReportWord(
    status: string | null | undefined,
    hasOwner: boolean,
): ReportWord {
    if (!status || status === 'draft') return 'draft';
    if (status === 'closed') return 'closed';
    if (status === 'reviewed' || hasOwner) return 'followed_up';
    return 'sent';
}
