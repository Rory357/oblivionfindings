import { router } from '@inertiajs/react';
import { FolderOpen, LayoutTemplate } from 'lucide-react';

import { HrTabs, type HrTabItem } from './hr-tabs';

export type DocumentsTab = 'library' | 'templates';

const TAB_URLS: Record<DocumentsTab, string> = {
    library: '/hr/documents',
    templates: '/hr/documents/templates',
};

/**
 * Section-level tab strip shared across the HR Documents pages so the cluster
 * reads as one hub. Templates is hr.documents.manage-gated, so the tab only
 * appears for managers (pass `canManage` from the page's `can.manage` prop) —
 * no view-only user is shown a tab that would 403.
 */
export function DocumentsTabs({
    active,
    canManage = false,
}: {
    active: DocumentsTab;
    canManage?: boolean;
}) {
    const items: HrTabItem[] = [
        { id: 'library', label: 'Documents', icon: FolderOpen, tone: 'primary' },
    ];
    if (canManage) {
        items.push({
            id: 'templates',
            label: 'Templates',
            icon: LayoutTemplate,
            tone: 'info',
        });
    }

    return (
        <HrTabs
            value={active}
            onChange={(id) => {
                if (id !== active) router.visit(TAB_URLS[id as DocumentsTab]);
            }}
            items={items}
            ariaLabel="Documents views"
        />
    );
}

export default DocumentsTabs;
