import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { LucideIcon } from 'lucide-react';
import { Archive, History, ShieldCheck, UserMinus, Users } from 'lucide-react';
import type { ReactNode } from 'react';
import { InvestigationAccess } from './investigation-access';
import { Offboarding } from './offboarding';
import { PortalRelationships } from './portal-relationships';
import { PreservationHolds } from './preservation-holds';
import { SettingsAudit } from './settings-audit';

export type ChatGovernanceDialog =
    | 'investigation'
    | 'holds'
    | 'offboarding'
    | 'settings-audit'
    | 'portal-access'
    | 'effective-access';

const dialogs: Record<
    ChatGovernanceDialog,
    { title: string; description: string; icon: LucideIcon; width: number }
> = {
    investigation: {
        title: 'Authorised investigation access',
        description:
            'Open one private staff conversation, read-only, for a recorded case.',
        icon: ShieldCheck,
        width: 720,
    },
    holds: {
        title: 'Exports and preservation',
        description: 'Preserve a conversation’s record for a case.',
        icon: Archive,
        width: 720,
    },
    offboarding: {
        title: 'Offboarding and expired relationships',
        description:
            'End Chat access that has expired and move unresolved work.',
        icon: UserMinus,
        width: 720,
    },
    'settings-audit': {
        title: 'Settings audit',
        description: 'Who changed Chat policy, and when.',
        icon: History,
        width: 720,
    },
    'portal-access': {
        title: 'Client portal relationships',
        description:
            'Approved relatives, named workers and authorised cover for one person supported.',
        icon: Users,
        width: 720,
    },
    'effective-access': {
        title: 'Effective Chat access',
        description:
            'Who can reach one person’s family conversations, and under which policy.',
        icon: Users,
        width: 720,
    },
};

/** The M22 governance pop-ups opened from Global Settings › Chat. */
export function ChatGovernanceDialogs({
    open,
    onClose,
    actorId,
    investigator,
    sites,
    onViewPolicy,
}: {
    open: ChatGovernanceDialog | null;
    onClose: () => void;
    actorId: number;
    investigator: boolean;
    sites: { id: number; name: string }[];
    onViewPolicy: (scope: string) => void;
}) {
    const meta = open ? dialogs[open] : null;
    let body: ReactNode = null;
    if (open === 'investigation')
        body = (
            <InvestigationAccess
                actorId={actorId}
                investigator={investigator}
                autoload
            />
        );
    else if (open === 'holds')
        body = (
            <PreservationHolds
                actorId={actorId}
                investigator={investigator}
                autoload
            />
        );
    else if (open === 'offboarding') body = <Offboarding actorId={actorId} />;
    else if (open === 'settings-audit')
        body = <SettingsAudit actorId={actorId} sites={sites} />;
    else if (open === 'portal-access' || open === 'effective-access')
        body = (
            <PortalRelationships
                actorId={actorId}
                onViewPolicy={(scope) => {
                    onClose();
                    onViewPolicy(scope);
                }}
            />
        );
    const Icon = meta?.icon;

    return (
        <Dialog open={!!open} onOpenChange={(next) => !next && onClose()}>
            {meta && Icon && (
                <DialogContent
                    className="max-h-[90vh] overflow-y-auto"
                    style={{
                        maxWidth: `min(92vw, ${meta.width}px)`,
                        width: `min(92vw, ${meta.width}px)`,
                    }}
                >
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <Icon className="size-4 text-primary" />
                            {meta.title}
                        </DialogTitle>
                        <DialogDescription>
                            {meta.description}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="mt-3">{body}</div>
                </DialogContent>
            )}
        </Dialog>
    );
}
