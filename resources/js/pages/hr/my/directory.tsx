import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { MyHrShell, type MyHrShellData } from '@/components/hr';
import {
    Building2,
    Flame,
    HeartPulse,
    Mail,
    MapPin,
    Phone,
    Search,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';

interface Person {
    id: number;
    name: string;
    initials: string;
    role: string | null;
    department: string | null;
    site: string | null;
    email: string | null;
    phone: string | null;
    avatar: string | null;
    is_first_aider: boolean;
    is_fire_warden: boolean;
    is_self: boolean;
}

interface Props {
    myHr: MyHrShellData;
    people: Person[];
}

const AVATAR_COLORS = [
    'bg-status-info-bg text-status-info',
    'bg-primary/15 text-primary',
    'bg-status-success-bg text-status-success',
    'bg-status-warning-bg text-status-warning',
    'bg-status-critical-bg text-status-critical',
];

function avatarColor(id: number): string {
    return AVATAR_COLORS[id % AVATAR_COLORS.length];
}

/**
 * The all-staff "who is who" phonebook, shown as a My HR tab. Self-contained
 * (cards don't link to the manager-only /hr/people profile, which frontline
 * can't open) and work-contact-only — the controller never sends personal
 * phone/email here.
 */
export default function MyDirectory({ myHr, people }: Props) {
    const [q, setQ] = useState('');
    const [filter, setFilter] = useState<'all' | 'first_aiders' | 'fire_wardens'>(
        'all',
    );

    const firstAiderCount = useMemo(
        () => people.filter((p) => p.is_first_aider).length,
        [people],
    );
    const fireWardenCount = useMemo(
        () => people.filter((p) => p.is_fire_warden).length,
        [people],
    );

    const filtered = useMemo(() => {
        const needle = q.trim().toLowerCase();
        return people.filter((p) => {
            if (filter === 'first_aiders' && !p.is_first_aider) return false;
            if (filter === 'fire_wardens' && !p.is_fire_warden) return false;
            if (!needle) return true;
            return [p.name, p.role, p.site, p.department]
                .filter(Boolean)
                .some((v) => (v as string).toLowerCase().includes(needle));
        });
    }, [people, q, filter]);

    return (
        <MyHrShell active="directory" myHr={myHr} title="Directory · My HR">
            <div className="space-y-4">
                {/* Search + emergency-role quick filters */}
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="relative w-full sm:max-w-sm">
                        <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <input
                            type="search"
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder="Search by name, role or site…"
                            aria-label="Search the staff directory"
                            className="h-10 w-full rounded-lg border border-input bg-background pl-9 pr-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        />
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {firstAiderCount > 0 && (
                            <Button
                                type="button"
                                variant={
                                    filter === 'first_aiders' ? 'default' : 'outline'
                                }
                                size="sm"
                                onClick={() =>
                                    setFilter((f) =>
                                        f === 'first_aiders' ? 'all' : 'first_aiders',
                                    )
                                }
                            >
                                <HeartPulse className="mr-1.5 size-4" />
                                First aiders ({firstAiderCount})
                            </Button>
                        )}
                        {fireWardenCount > 0 && (
                            <Button
                                type="button"
                                variant={
                                    filter === 'fire_wardens' ? 'default' : 'outline'
                                }
                                size="sm"
                                onClick={() =>
                                    setFilter((f) =>
                                        f === 'fire_wardens' ? 'all' : 'fire_wardens',
                                    )
                                }
                            >
                                <Flame className="mr-1.5 size-4" />
                                Fire wardens ({fireWardenCount})
                            </Button>
                        )}
                    </div>
                </div>

                <p className="text-sm text-muted-foreground">
                    {filtered.length} {filtered.length === 1 ? 'person' : 'people'}
                </p>

                {filtered.length > 0 ? (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        {filtered.map((p) => (
                            <Card key={p.id} className="h-full overflow-hidden">
                                <div className="h-12 bg-gradient-to-r from-primary/20 via-primary/10 to-transparent" />
                                <CardContent className="-mt-8 flex flex-col items-center px-5 pb-5 text-center">
                                    <Avatar className="size-16 border-4 border-background shadow-md">
                                        <AvatarImage
                                            src={
                                                p.avatar
                                                    ? `/storage/${p.avatar}`
                                                    : undefined
                                            }
                                            alt={p.name}
                                        />
                                        <AvatarFallback
                                            className={`text-lg font-bold ${avatarColor(p.id)}`}
                                        >
                                            {p.initials}
                                        </AvatarFallback>
                                    </Avatar>
                                    <div className="mt-3 flex items-center justify-center gap-2">
                                        <h3 className="text-sm font-semibold">
                                            {p.name}
                                        </h3>
                                        {p.is_self && (
                                            <Badge
                                                variant="outline"
                                                className="px-1.5 py-0 text-[10px]"
                                            >
                                                You
                                            </Badge>
                                        )}
                                    </div>
                                    {p.role && (
                                        <p className="mt-0.5 line-clamp-1 text-xs text-muted-foreground">
                                            {p.role}
                                        </p>
                                    )}

                                    <div className="mt-2.5 flex flex-wrap items-center justify-center gap-1.5">
                                        {p.site && (
                                            <Badge
                                                variant="outline"
                                                className="gap-0.5 px-2 py-0 text-[10px]"
                                            >
                                                <MapPin className="size-2.5" />
                                                {p.site}
                                            </Badge>
                                        )}
                                        {p.department && (
                                            <Badge
                                                variant="secondary"
                                                className="gap-0.5 px-2 py-0 text-[10px]"
                                            >
                                                <Building2 className="size-2.5" />
                                                {p.department}
                                            </Badge>
                                        )}
                                    </div>

                                    {(p.is_first_aider || p.is_fire_warden) && (
                                        <div className="mt-2 flex flex-wrap items-center justify-center gap-1.5">
                                            {p.is_first_aider && (
                                                <Badge
                                                    variant="outline"
                                                    className="gap-0.5 border-status-success/30 bg-status-success-bg px-2 py-0 text-[10px] text-status-success-foreground"
                                                >
                                                    <HeartPulse className="size-2.5" />
                                                    First aider
                                                </Badge>
                                            )}
                                            {p.is_fire_warden && (
                                                <Badge
                                                    variant="outline"
                                                    className="gap-0.5 border-status-warning/30 bg-status-warning-bg px-2 py-0 text-[10px] text-status-warning-foreground"
                                                >
                                                    <Flame className="size-2.5" />
                                                    Fire warden
                                                </Badge>
                                            )}
                                        </div>
                                    )}

                                    {(p.email || p.phone) && (
                                        <div className="mt-3 flex w-full flex-col gap-1.5 text-xs">
                                            {p.email && (
                                                <a
                                                    href={`mailto:${p.email}`}
                                                    className="flex items-center justify-center gap-1.5 text-primary hover:underline"
                                                >
                                                    <Mail className="size-3.5 shrink-0" />
                                                    <span className="truncate">
                                                        {p.email}
                                                    </span>
                                                </a>
                                            )}
                                            {p.phone && (
                                                <a
                                                    href={`tel:${p.phone}`}
                                                    className="flex items-center justify-center gap-1.5 text-primary hover:underline"
                                                >
                                                    <Phone className="size-3.5 shrink-0" />
                                                    <span className="truncate">
                                                        {p.phone}
                                                    </span>
                                                </a>
                                            )}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                ) : (
                    <Card>
                        <CardContent className="flex flex-col items-center gap-3 py-16">
                            <div className="flex size-16 items-center justify-center rounded-full bg-muted">
                                <Users className="size-8 text-muted-foreground/40" />
                            </div>
                            <div className="text-center">
                                <p className="text-lg font-medium">No people found</p>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Try a different search or clear the filters.
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </MyHrShell>
    );
}
