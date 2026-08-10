import {
    GovernanceAttachmentsPanel,
    type GovernanceAttachment,
} from '@/components/governance/GovernanceAttachmentsPanel';

// Public type re-export so existing imports (`import { type Attachment } from './_attachments'`)
// keep working without changes.
export type Attachment = GovernanceAttachment;

interface AttachmentsPanelProps {
    reportId: number | null;
    attachments: Attachment[];
    canManage: boolean;
    onChanged?: () => void;
}

/**
 * Thin wrapper around the shared GovernanceAttachmentsPanel that wires the
 * CEO-report-specific routes (upload, delete, reload prop).
 */
export function AttachmentsPanel({
    reportId,
    attachments,
    canManage,
    onChanged,
}: AttachmentsPanelProps) {
    const urls = reportId
        ? {
              upload: `/governance/ceo-reports/${reportId}/attachments`,
              delete: (attachmentId: string) =>
                  `/governance/ceo-reports/${reportId}/attachments/${attachmentId}`,
          }
        : null;

    return (
        <GovernanceAttachmentsPanel
            canManage={canManage}
            attachments={attachments}
            urls={urls}
            reloadProp="report"
            onChanged={onChanged}
            emptyText={{
                managed: 'No attachments yet. Drop files above to add one.',
                readOnly:
                    'The CEO has not attached any documents to this report.',
            }}
        />
    );
}

export default AttachmentsPanel;
