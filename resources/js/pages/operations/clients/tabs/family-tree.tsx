import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { cn } from '@/lib/utils';
import {
    AlertTriangle,
    Eye,
    Heart,
    Mail,
    Phone,
    Shield,
    UserRound,
    Users,
} from 'lucide-react';
import type { ComponentType } from 'react';

export type FamilyMember = {
    id: number | string;
    name?: string | null;
    relationship?: string | null;
    phone?: string | null;
    alternate_phone?: string | null;
    email?: string | null;
    address?: string | null;
    notes?: string | null;
    is_primary?: boolean;
    is_emergency_contact?: boolean;
    can_view_medical?: boolean;
    can_view_medications?: boolean;
    can_view_incidents?: boolean;
    can_receive_updates?: boolean;
    has_portal_access?: boolean;
};

export type PortalUser = {
    id: number;
    name?: string | null;
    email?: string | null;
    relation?: string | null;
};

export type EmergencyContact = {
    id: number | string;
    name?: string | null;
    relationship?: string | null;
    phone?: string | null;
    email?: string | null;
    is_primary?: boolean;
};

type FamilyTreeTabProps = {
    clientName: string;
    nextOfKins?: FamilyMember[];
    portalUsers?: PortalUser[];
    emergencyContacts?: EmergencyContact[];
};

function groupColor(category: string): string {
    return (
        {
            primary: 'bg-primary/10 text-primary',
            emergency: 'bg-status-critical-bg text-status-critical',
            family: 'bg-status-info-bg text-status-info',
            portal: 'bg-status-success-bg text-status-success',
        }[category] ?? 'bg-muted text-muted-foreground'
    );
}

function categoriseRelationship(rel?: string | null): string {
    const r = (rel ?? '').toLowerCase();
    if (
        ['mother', 'father', 'parent', 'grandparent', 'sibling', 'sister', 'brother'].some(
            (k) => r.includes(k),
        )
    ) {
        return 'family';
    }
    if (['guardian', 'welfare', 'legal'].some((k) => r.includes(k))) {
        return 'guardian';
    }
    if (['friend', 'advocate'].some((k) => r.includes(k))) {
        return 'friend';
    }
    return 'other';
}

function MemberCard({
    person,
    badgeTone,
}: {
    person: FamilyMember;
    badgeTone: 'primary' | 'emergency' | 'family' | 'portal';
}) {
    return (
        <div className="rounded-lg border bg-card p-4">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="font-medium">{person.name ?? 'Unnamed person'}</p>
                    {person.relationship ? (
                        <p className="text-xs text-muted-foreground capitalize">
                            {person.relationship}
                        </p>
                    ) : null}
                </div>
                <div className="flex shrink-0 flex-col items-end gap-1">
                    {person.is_primary ? (
                        <Badge className={cn(groupColor(badgeTone))}>
                            Primary
                        </Badge>
                    ) : null}
                    {person.has_portal_access ? (
                        <Badge variant="outline" className="gap-1">
                            <Eye className="h-3 w-3" />
                            Portal
                        </Badge>
                    ) : null}
                </div>
            </div>

            {(person.phone || person.email) && (
                <div className="mt-3 flex flex-wrap gap-3 text-xs">
                    {person.phone ? (
                        <a
                            href={`tel:${person.phone}`}
                            className="inline-flex items-center gap-1 text-primary hover:underline"
                        >
                            <Phone className="h-3 w-3" />
                            {person.phone}
                        </a>
                    ) : null}
                    {person.email ? (
                        <a
                            href={`mailto:${person.email}`}
                            className="inline-flex items-center gap-1 text-primary hover:underline"
                        >
                            <Mail className="h-3 w-3" />
                            {person.email}
                        </a>
                    ) : null}
                </div>
            )}

            {(person.can_view_medical ||
                person.can_view_medications ||
                person.can_view_incidents ||
                person.can_receive_updates) && (
                <div className="mt-3 flex flex-wrap gap-1">
                    {person.can_view_medical ? (
                        <Badge variant="outline" className="text-[10px]">
                            <Shield className="mr-1 h-2.5 w-2.5" />
                            Medical
                        </Badge>
                    ) : null}
                    {person.can_view_medications ? (
                        <Badge variant="outline" className="text-[10px]">
                            <Shield className="mr-1 h-2.5 w-2.5" />
                            Medications
                        </Badge>
                    ) : null}
                    {person.can_view_incidents ? (
                        <Badge variant="outline" className="text-[10px]">
                            <Shield className="mr-1 h-2.5 w-2.5" />
                            Incidents
                        </Badge>
                    ) : null}
                    {person.can_receive_updates ? (
                        <Badge variant="outline" className="text-[10px]">
                            <Mail className="mr-1 h-2.5 w-2.5" />
                            Updates
                        </Badge>
                    ) : null}
                </div>
            )}

            {person.notes ? (
                <p className="mt-3 text-xs text-muted-foreground">
                    {person.notes}
                </p>
            ) : null}
        </div>
    );
}

function GroupSection({
    icon: Icon,
    title,
    description,
    members,
    emptyLabel,
    tone,
}: {
    icon: ComponentType<{ className?: string }>;
    title: string;
    description: string;
    members: FamilyMember[];
    emptyLabel: string;
    tone: 'primary' | 'emergency' | 'family' | 'portal';
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                    <Icon
                        className={cn(
                            'h-4 w-4',
                            tone === 'primary' && 'text-primary',
                            tone === 'emergency' && 'text-status-critical',
                            tone === 'family' && 'text-status-info',
                            tone === 'portal' && 'text-status-success',
                        )}
                    />
                    {title}
                    <Badge variant="outline" className="ml-auto">
                        {members.length}
                    </Badge>
                </CardTitle>
                <p className="text-xs text-muted-foreground">{description}</p>
            </CardHeader>
            <CardContent>
                {members.length > 0 ? (
                    <div className="grid gap-3 md:grid-cols-2">
                        {members.map((m) => (
                            <MemberCard
                                key={`${tone}-${m.id}`}
                                person={m}
                                badgeTone={tone}
                            />
                        ))}
                    </div>
                ) : (
                    <p className="text-sm italic text-muted-foreground">
                        {emptyLabel}
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

export function FamilyTreeTab({
    clientName,
    nextOfKins = [],
    portalUsers = [],
    emergencyContacts = [],
}: FamilyTreeTabProps) {
    const primaryContacts = nextOfKins.filter((k) => k.is_primary);
    const family = nextOfKins.filter(
        (k) => categoriseRelationship(k.relationship) === 'family' && !k.is_primary,
    );
    const guardians = nextOfKins.filter(
        (k) => categoriseRelationship(k.relationship) === 'guardian',
    );
    const others = nextOfKins.filter((k) => {
        const cat = categoriseRelationship(k.relationship);
        return !k.is_primary && cat !== 'family' && cat !== 'guardian';
    });

    const portalMembers: FamilyMember[] = portalUsers.map((u) => ({
        id: u.id,
        name: u.name,
        relationship: u.relation,
        email: u.email,
        has_portal_access: true,
    }));

    const emergencyMembers: FamilyMember[] = emergencyContacts.map((c) => ({
        id: c.id,
        name: c.name,
        relationship: c.relationship,
        phone: c.phone,
        email: c.email,
        is_primary: c.is_primary,
        is_emergency_contact: true,
    }));

    const totalPeople =
        nextOfKins.length + portalUsers.length + emergencyContacts.length;

    if (totalPeople === 0) {
        return (
            <EmptyState
                icon={Users}
                title="No important people on record"
                description={`Add family, guardians, or emergency contacts to build ${clientName}'s relationship map.`}
            />
        );
    }

    return (
        <div className="space-y-6" data-test="client-family-tree-tab">
            <div className="rounded-lg border bg-card p-4">
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-semibold">
                            Important people for {clientName}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Relationships, contact details, and what each
                            person is authorised to see.
                        </p>
                    </div>
                    <Badge variant="outline" className="text-base">
                        {totalPeople}
                    </Badge>
                </div>
            </div>

            <GroupSection
                icon={Heart}
                title="Primary contacts"
                description="People to talk to first about day-to-day decisions."
                members={primaryContacts}
                emptyLabel="No primary contact has been nominated. Mark a next-of-kin as primary so workers know who to call."
                tone="primary"
            />

            <GroupSection
                icon={AlertTriangle}
                title="Emergency contacts"
                description="Reach these people for safeguarding or urgent situations."
                members={emergencyMembers}
                emptyLabel="No emergency contacts on record. Add at least one from the Medical tab."
                tone="emergency"
            />

            <GroupSection
                icon={Users}
                title="Family"
                description="Whānau and close family members."
                members={family}
                emptyLabel="No family members captured yet."
                tone="family"
            />

            <GroupSection
                icon={Shield}
                title="Guardians, welfare or legal"
                description="People with formal authority for welfare, decisions, or representation."
                members={guardians}
                emptyLabel="No guardian or welfare contacts recorded."
                tone="primary"
            />

            <GroupSection
                icon={UserRound}
                title="Friends, advocates & other"
                description="Friends, support advocates, and other important people."
                members={others}
                emptyLabel="No friends or advocates recorded."
                tone="family"
            />

            <GroupSection
                icon={Eye}
                title="Family portal access"
                description="People with logins to view this client's portal."
                members={portalMembers}
                emptyLabel="No family portal users invited yet."
                tone="portal"
            />
        </div>
    );
}
