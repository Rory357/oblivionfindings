import { useEffect } from 'react';
import { hubRequest } from './_api';
import type { HubDocument } from './document';

/** Private reading history; never an acknowledgement or a draft read. */
export function DocumentReadReceipt({ file }: { file: HubDocument }) {
    const { id, actor_id, media_id, revision_id, edition } = file;
    useEffect(() => {
        if (edition !== 'published' || !actor_id || !revision_id) return;
        void hubRequest(`/company-hub/documents/${id}/read`, 'POST', {
            actor_id,
            media_id,
            revision_id,
        }).catch(() => {
            /* Reading remains available when personal history cannot be saved. */
        });
    }, [id, actor_id, media_id, revision_id, edition]);
    return null;
}
