import type { ChecklistsData, SiteRef } from '@/components/checklists/types';
import { ChecklistsWorkspace } from '@/components/checklists/workspace';
import { SiteProfileLockedState } from './site-profile-states';

type SiteProfileChecklistsScope = {
    site: SiteRef;
    backHref: string;
};

export type SiteProfileChecklistsData =
    | ({ locked: true } & SiteProfileChecklistsScope)
    | (ChecklistsData &
          SiteProfileChecklistsScope & {
              locked?: false;
          });

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
