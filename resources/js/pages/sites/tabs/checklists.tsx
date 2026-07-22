import type { ChecklistsData, SiteRef } from '@/components/checklists/types';
import { ChecklistsWorkspace } from '@/components/checklists/workspace';
import { SiteProfileLockedState } from './site-profile-states';

export type SiteProfileChecklistsData = ChecklistsData & {
    locked?: boolean;
    site: SiteRef;
    backHref: string;
};

export function SiteProfileChecklists({
    data,
}: {
    data: SiteProfileChecklistsData;
}) {
    if (data.locked) return <SiteProfileLockedState label="Checklists" />;

    const { locked: _locked, site, backHref, ...workspaceData } = data;

    return (
        <ChecklistsWorkspace
            scope={{ mode: 'site', site, backHref }}
            data={workspaceData as ChecklistsData}
            embedded
        />
    );
}
