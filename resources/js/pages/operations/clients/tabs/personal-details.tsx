import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { cn } from '@/lib/utils';
import {
    Cake,
    Globe2,
    HandHeart,
    Heart,
    Home,
    Languages,
    Mail,
    MapPin,
    Phone,
    Sparkles,
    UserCircle2,
    Users,
} from 'lucide-react';
import type { ComponentType } from 'react';

type ClientPersonalDetailsClient = {
    id: number;
    first_name?: string | null;
    last_name?: string | null;
    preferred_name?: string | null;
    preferred_pronouns?: string | null;
    date_of_birth?: string | null;
    gender?: string | null;
    nhi_number?: string | null;
    status?: string | null;
    phone?: string | null;
    email?: string | null;
    address_line_1?: string | null;
    address_line_2?: string | null;
    suburb?: string | null;
    city?: string | null;
    postcode?: string | null;
    ethnicity?: string | null;
    languages?: string[] | null;
    religion?: string | null;
    funding_type?: string | null;
    funding_notes?: string | null;
    service_start_date?: string | null;
    interests_hobbies?: string | null;
    strengths_abilities?: string | null;
    life_story?: string | null;
    site?: { id: number; name?: string | null } | null;
    key_worker?: { id: number; name?: string | null } | null;
};

type PersonRecord = {
    id?: number | string;
    name?: string | null;
    relationship?: string | null;
    phone?: string | null;
    email?: string | null;
    notes?: string | null;
    is_primary?: boolean;
};

type PersonalDetailsTabProps = {
    client: ClientPersonalDetailsClient;
    supportWorkers?: { id: number; name?: string | null }[];
    emergencyContacts?: PersonRecord[];
    nextOfKins?: PersonRecord[];
};

function formatDate(value?: string | null) {
    if (!value) return '—';
    try {
        return new Intl.DateTimeFormat('en-NZ', {
            day: 'numeric',
            month: 'long',
            year: 'numeric',
        }).format(new Date(value));
    } catch {
        return value;
    }
}

function calculateAge(dob?: string | null): number | null {
    if (!dob) return null;
    const birth = new Date(dob);
    if (Number.isNaN(birth.getTime())) return null;
    const now = new Date();
    let age = now.getFullYear() - birth.getFullYear();
    const m = now.getMonth() - birth.getMonth();
    if (m < 0 || (m === 0 && now.getDate() < birth.getDate())) age -= 1;
    return age;
}

function joinAddress(client: ClientPersonalDetailsClient): string {
    return [
        client.address_line_1,
        client.address_line_2,
        client.suburb,
        client.city,
        client.postcode,
    ]
        .filter(Boolean)
        .join(', ');
}

function DetailRow({
    icon: Icon,
    label,
    value,
}: {
    icon: ComponentType<{ className?: string }>;
    label: string;
    value: React.ReactNode;
}) {
    const display = typeof value === 'string' ? value.trim() : value;
    const empty = display === '' || display == null || display === '—';

    return (
        <div className="flex items-start gap-3 py-2">
            <span className="mt-0.5 rounded-md bg-muted p-1.5">
                <Icon className="h-3.5 w-3.5 text-muted-foreground" />
            </span>
            <div className="min-w-0 flex-1">
                <p className="text-xs text-muted-foreground">{label}</p>
                <p
                    className={cn(
                        'mt-0.5 text-sm',
                        empty ? 'italic text-muted-foreground' : 'font-medium',
                    )}
                >
                    {empty ? 'Not recorded' : display}
                </p>
            </div>
        </div>
    );
}

export function PersonalDetailsTab({
    client,
    supportWorkers = [],
    emergencyContacts = [],
    nextOfKins = [],
}: PersonalDetailsTabProps) {
    const name = [client.first_name, client.last_name]
        .filter(Boolean)
        .join(' ');
    const age = calculateAge(client.date_of_birth);
    const address = joinAddress(client);
    const languages = (client.languages ?? []).filter(Boolean);

    return (
        <div className="space-y-6" data-test="client-personal-details-tab">
            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <UserCircle2 className="h-4 w-4 text-primary" />
                            Identity
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="divide-y">
                        <DetailRow
                            icon={UserCircle2}
                            label="Legal name"
                            value={name || '—'}
                        />
                        <DetailRow
                            icon={Sparkles}
                            label="Preferred / known as"
                            value={client.preferred_name}
                        />
                        <DetailRow
                            icon={UserCircle2}
                            label="Pronouns"
                            value={client.preferred_pronouns}
                        />
                        <DetailRow
                            icon={Cake}
                            label="Date of birth"
                            value={
                                client.date_of_birth ? (
                                    <span>
                                        {formatDate(client.date_of_birth)}
                                        {age != null ? (
                                            <span className="ml-1 text-muted-foreground">
                                                ({age} yrs)
                                            </span>
                                        ) : null}
                                    </span>
                                ) : null
                            }
                        />
                        <DetailRow
                            icon={UserCircle2}
                            label="Gender"
                            value={client.gender}
                        />
                        <DetailRow
                            icon={UserCircle2}
                            label="NHI number"
                            value={client.nhi_number}
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Phone className="h-4 w-4 text-primary" />
                            Contact
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="divide-y">
                        <DetailRow
                            icon={Phone}
                            label="Phone"
                            value={
                                client.phone ? (
                                    <a
                                        className="text-primary underline-offset-2 hover:underline"
                                        href={`tel:${client.phone}`}
                                    >
                                        {client.phone}
                                    </a>
                                ) : null
                            }
                        />
                        <DetailRow
                            icon={Mail}
                            label="Email"
                            value={
                                client.email ? (
                                    <a
                                        className="text-primary underline-offset-2 hover:underline"
                                        href={`mailto:${client.email}`}
                                    >
                                        {client.email}
                                    </a>
                                ) : null
                            }
                        />
                        <DetailRow
                            icon={MapPin}
                            label="Address"
                            value={address}
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Globe2 className="h-4 w-4 text-primary" />
                            Cultural identity
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="divide-y">
                        <DetailRow
                            icon={Globe2}
                            label="Ethnicity"
                            value={client.ethnicity}
                        />
                        <DetailRow
                            icon={Languages}
                            label="Languages"
                            value={
                                languages.length > 0 ? (
                                    <div className="mt-1 flex flex-wrap gap-1">
                                        {languages.map((lang) => (
                                            <Badge
                                                key={lang}
                                                variant="outline"
                                            >
                                                {lang}
                                            </Badge>
                                        ))}
                                    </div>
                                ) : null
                            }
                        />
                        <DetailRow
                            icon={Heart}
                            label="Religion"
                            value={client.religion}
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Home className="h-4 w-4 text-primary" />
                            Service context
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="divide-y">
                        <DetailRow
                            icon={Home}
                            label="Site"
                            value={client.site?.name}
                        />
                        <DetailRow
                            icon={UserCircle2}
                            label="Key worker"
                            value={client.key_worker?.name}
                        />
                        <DetailRow
                            icon={Cake}
                            label="Service started"
                            value={formatDate(client.service_start_date)}
                        />
                        <DetailRow
                            icon={UserCircle2}
                            label="Status"
                            value={
                                client.status ? (
                                    <Badge
                                        variant="outline"
                                        className="capitalize"
                                    >
                                        {client.status}
                                    </Badge>
                                ) : null
                            }
                        />
                        <DetailRow
                            icon={Sparkles}
                            label="Funding"
                            value={
                                client.funding_type ? (
                                    <span>
                                        {client.funding_type}
                                        {client.funding_notes ? (
                                            <span className="ml-1 text-muted-foreground">
                                                · {client.funding_notes}
                                            </span>
                                        ) : null}
                                    </span>
                                ) : null
                            }
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <HandHeart className="h-4 w-4 text-primary" />
                            Story & strengths
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {(client.life_story?.trim() ||
                            client.strengths_abilities?.trim() ||
                            client.interests_hobbies?.trim()) ? (
                            <>
                                {client.life_story?.trim() ? (
                                    <div>
                                        <p className="text-xs text-muted-foreground">
                                            Life story
                                        </p>
                                        <p className="mt-1 text-sm leading-6 whitespace-pre-wrap">
                                            {client.life_story}
                                        </p>
                                    </div>
                                ) : null}
                                {client.strengths_abilities?.trim() ? (
                                    <div>
                                        <p className="text-xs text-muted-foreground">
                                            Strengths & abilities
                                        </p>
                                        <p className="mt-1 text-sm leading-6 whitespace-pre-wrap">
                                            {client.strengths_abilities}
                                        </p>
                                    </div>
                                ) : null}
                                {client.interests_hobbies?.trim() ? (
                                    <div>
                                        <p className="text-xs text-muted-foreground">
                                            Interests & hobbies
                                        </p>
                                        <p className="mt-1 text-sm leading-6 whitespace-pre-wrap">
                                            {client.interests_hobbies}
                                        </p>
                                    </div>
                                ) : null}
                            </>
                        ) : (
                            <p className="text-sm italic text-muted-foreground">
                                No story, strengths, or interests captured yet.
                                Add them from the edit dialog.
                            </p>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Users className="h-4 w-4 text-primary" />
                            Support team
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {supportWorkers.length > 0 ? (
                            <ul className="space-y-2">
                                {supportWorkers.map((worker) => (
                                    <li
                                        key={worker.id}
                                        className="flex items-center justify-between rounded-md border bg-card p-2 text-sm"
                                    >
                                        <span>{worker.name ?? `User #${worker.id}`}</span>
                                        <Badge variant="outline">Support</Badge>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <EmptyState
                                icon={Users}
                                title="No assigned support workers"
                                description="Assign workers from the Assignments tab to surface them here."
                            />
                        )}
                    </CardContent>
                </Card>
            </div>

            <div className="grid gap-4 md:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Phone className="h-4 w-4 text-status-critical" />
                            Emergency contacts
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {emergencyContacts.length > 0 ? (
                            emergencyContacts.map((contact) => (
                                <PersonCard
                                    key={`em-${contact.id}`}
                                    person={contact}
                                />
                            ))
                        ) : (
                            <EmptyState
                                icon={Phone}
                                title="No emergency contacts on record"
                                description="Add a contact under Relationships so workers can reach someone quickly."
                            />
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Users className="h-4 w-4 text-primary" />
                            Next of kin / guardians
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {nextOfKins.length > 0 ? (
                            nextOfKins.map((kin) => (
                                <PersonCard
                                    key={`nok-${kin.id}`}
                                    person={kin}
                                />
                            ))
                        ) : (
                            <EmptyState
                                icon={Users}
                                title="No next of kin on record"
                                description="Add guardians or family from the Family Tree tab."
                            />
                        )}
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}

function PersonCard({ person }: { person: PersonRecord }) {
    return (
        <div className="rounded-lg border bg-card p-3 text-sm">
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="font-medium">{person.name ?? 'Unnamed'}</p>
                    {person.relationship ? (
                        <p className="text-xs text-muted-foreground">
                            {person.relationship}
                        </p>
                    ) : null}
                </div>
                {person.is_primary ? (
                    <Badge variant="outline" className="shrink-0">
                        Primary
                    </Badge>
                ) : null}
            </div>
            {(person.phone || person.email) && (
                <div className="mt-2 flex flex-wrap items-center gap-2 text-xs">
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
            {person.notes ? (
                <p className="mt-2 text-xs text-muted-foreground">
                    {person.notes}
                </p>
            ) : null}
        </div>
    );
}
