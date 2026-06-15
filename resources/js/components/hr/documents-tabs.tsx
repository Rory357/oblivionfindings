import { router, usePage } from '@inertiajs/react';
import { BookOpen, FolderOpen, LayoutTemplate, PenSquare } from 'lucide-react';

import { HrTabs, type HrTabItem } from './hr-tabs';

export type DocumentsTab = 'library' | 'policies' | 'signatures' | 'templates';

const TAB_URLS: Record<DocumentsTab, string> = {
    library: '/hr/documents',
    policies: '/hr/documents/policies',
    signatures: '/hr/signatures/pending',
    templates: '/hr/documents/templates',
};

type HrCan = {
    documents?: { view?: boolean; manage?: boolean };
    policies?: { view?: boolean };
};

/**
 * Section-level tab strip shared across the Documents & Policies hub pages.
 * Tabs are filtered by the shared auth.can flags: Documents (library) is
 * hr.documents.view, Policies is hr.policies.view (a DIFFERENT gate), Templates
 * is hr.documents.manage, and Signatures (documents awaiting the user's
 * signature) is auth-only so it is always shown. The active tab is always
 * rendered so the current page never hides its own tab.
 */
export function DocumentsTabs({ active }: { active: DocumentsTab }) {
    const hr = (usePage().props as { auth?: { can?: { hr?: HrCan } } }).auth?.can
        ?.hr;

    const all: Array<{ item: HrTabItem; show: boolean }> = [
        {
            item: { id: 'library', label: 'Documents', icon: FolderOpen, tone: 'primary' },
            show: !!hr?.documents?.view,
        },
        {
            item: { id: 'policies', label: 'Policies', icon: BookOpen, tone: 'violet' },
            show: !!hr?.policies?.view,
        },
        {
            item: { id: 'signatures', label: 'Signatures', icon: PenSquare, tone: 'warning' },
            show: true,
        },
        {
            item: { id: 'templates', label: 'Templates', icon: LayoutTemplate, tone: 'info' },
            show: !!hr?.documents?.manage,
        },
    ];

    const items: HrTabItem[] = all
        .filter((t) => t.show || t.item.id === active)
        .map((t) => t.item);

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
