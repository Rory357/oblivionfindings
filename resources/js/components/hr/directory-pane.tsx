import { Grid3X3, LayoutList, Mail, MapPin, Phone, Users } from 'lucide-react';
import { useState } from 'react';

import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';

import { StaffDetailsModal } from './staff-details-modal';

export interface DirectoryPerson {
    id: number;
    profile_id: number | null;
    position_title: string | null;
    department: string | null;
    preferred_name?: string | null;
    profile_photo_path?: string | null;
    work_email?: string | null;
    phone?: string | null;
    user: { id: number; name: string; email: string };
    primary_site: { id: number; name: string } | null;
}

function getInitials(name: string): string {
    return name
        .split(' ')
        .map((n) => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
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

function displayName(p: DirectoryPerson): string {
    return p.preferred_name || p.user.name;
}

/**
 * Directory card/list view of the People list — folds the former standalone
 * /hr/directory into a People-hub tab, backed by the same paginated payload.
 * Clicking a person opens a read-only staff-details modal (the heavy full-page
 * profile was dropped as the directory's click target).
 */
export function DirectoryPane({ people }: { people: DirectoryPerson[] }) {
    const [viewMode, setViewMode] = useState<'grid' | 'list'>('grid');
    const [selectedId, setSelectedId] = useState<number | null>(null);

    if (people.length === 0) {
        return (
            <Card>
                <CardContent className="flex flex-col items-center gap-3 py-16">
                    <div className="flex h-16 w-16 items-center justify-center rounded-full bg-muted">
                        <Users className="h-8 w-8 text-muted-foreground/40" />
                    </div>
                    <div className="text-center">
                        <p className="text-lg font-medium">No people found</p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Try adjusting the filters on the People tab.
                        </p>
                    </div>
                </CardContent>
            </Card>
        );
    }

    return (
        <>
            <div className="space-y-4">
                <div className="flex items-center justify-end">
                    {/* eslint-disable-next-line no-restricted-syntax -- segmented view-toggle control, not a card surface */}
                    <div className="flex items-center gap-1 rounded-lg border border-border bg-card p-0.5">
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            onClick={() => setViewMode('grid')}
                            title="Grid view"
                            className={
                                viewMode === 'grid'
                                    ? 'h-auto w-auto rounded-md bg-accent p-1.5 text-foreground'
                                    : 'h-auto w-auto rounded-md p-1.5 text-muted-foreground'
                            }
                        >
                            <Grid3X3 className="h-4 w-4" />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            onClick={() => setViewMode('list')}
                            title="List view"
                            className={
                                viewMode === 'list'
                                    ? 'h-auto w-auto rounded-md bg-accent p-1.5 text-foreground'
                                    : 'h-auto w-auto rounded-md p-1.5 text-muted-foreground'
                            }
                        >
                            <LayoutList className="h-4 w-4" />
                        </Button>
                    </div>
                </div>

                {viewMode === 'grid' ? (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        {people.map((p) => {
                            const name = displayName(p);
                            const inner = (
                                <Card className="h-full overflow-hidden transition-all group-hover:-translate-y-0.5 group-hover:border-primary/40 group-hover:shadow-lg">
                                    <div className="h-16 bg-gradient-to-r from-primary/20 via-primary/10 to-transparent" />
                                    <CardContent className="-mt-10 flex flex-col items-center px-5 pb-5 text-center">
                                        <Avatar className="h-20 w-20 border-4 border-background shadow-md">
                                            <AvatarImage
                                                src={
                                                    p.profile_photo_path
                                                        ? `/storage/${p.profile_photo_path}`
                                                        : undefined
                                                }
                                            />
                                            <AvatarFallback
                                                className={`text-xl font-bold ${avatarColor(p.id)}`}
                                            >
                                                {getInitials(name)}
                                            </AvatarFallback>
                                        </Avatar>
                                        <h3 className="mt-3 text-sm font-semibold transition-colors group-hover:text-primary">
                                            {name}
                                        </h3>
                                        {p.position_title ? (
                                            <p className="mt-0.5 line-clamp-1 text-xs text-muted-foreground">
                                                {p.position_title}
                                            </p>
                                        ) : null}
                                        <div className="mt-2.5 flex flex-wrap items-center justify-center gap-1.5">
                                            {p.department ? (
                                                <Badge
                                                    variant="secondary"
                                                    className="px-2 py-0 text-[10px]"
                                                >
                                                    {p.department}
                                                </Badge>
                                            ) : null}
                                            {p.primary_site ? (
                                                <Badge
                                                    variant="outline"
                                                    className="gap-0.5 px-2 py-0 text-[10px]"
                                                >
                                                    <MapPin className="h-2.5 w-2.5" />
                                                    {p.primary_site.name}
                                                </Badge>
                                            ) : null}
                                        </div>
                                        <div className="mt-3 flex items-center gap-3">
                                            {(p.work_email || p.user.email) && (
                                                <span
                                                    className="flex h-8 w-8 items-center justify-center rounded-full bg-muted/60 text-muted-foreground transition-colors group-hover:bg-primary/10 group-hover:text-primary"
                                                    title={p.work_email || p.user.email}
                                                >
                                                    <Mail className="h-3.5 w-3.5" />
                                                </span>
                                            )}
                                            {p.phone ? (
                                                <span
                                                    className="flex h-8 w-8 items-center justify-center rounded-full bg-muted/60 text-muted-foreground transition-colors group-hover:bg-primary/10 group-hover:text-primary"
                                                    title={p.phone}
                                                >
                                                    <Phone className="h-3.5 w-3.5" />
                                                </span>
                                            ) : null}
                                        </div>
                                    </CardContent>
                                </Card>
                            );
                            return p.profile_id ? (
                                // eslint-disable-next-line no-restricted-syntax -- whole card is a selector that opens the staff-details modal
                                <button
                                    key={p.id}
                                    type="button"
                                    onClick={() => setSelectedId(p.profile_id)}
                                    className="group block w-full text-left"
                                >
                                    {inner}
                                </button>
                            ) : (
                                <div key={p.id} className="group block">
                                    {inner}
                                </div>
                            );
                        })}
                    </div>
                ) : (
                    <Card>
                        <CardContent className="divide-y p-0">
                            {people.map((p) => {
                                const name = displayName(p);
                                const row = (
                                    <>
                                        <Avatar className="h-12 w-12 shrink-0">
                                            <AvatarImage
                                                src={
                                                    p.profile_photo_path
                                                        ? `/storage/${p.profile_photo_path}`
                                                        : undefined
                                                }
                                            />
                                            <AvatarFallback
                                                className={`font-semibold ${avatarColor(p.id)}`}
                                            >
                                                {getInitials(name)}
                                            </AvatarFallback>
                                        </Avatar>
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm font-semibold">{name}</p>
                                            {p.position_title ? (
                                                <p className="text-xs text-muted-foreground">
                                                    {p.position_title}
                                                </p>
                                            ) : null}
                                        </div>
                                        <div className="hidden items-center gap-4 sm:flex">
                                            {p.department ? (
                                                <Badge
                                                    variant="secondary"
                                                    className="text-[10px]"
                                                >
                                                    {p.department}
                                                </Badge>
                                            ) : null}
                                            {p.primary_site ? (
                                                <span className="flex items-center gap-1 text-xs text-muted-foreground">
                                                    <MapPin className="h-3 w-3" />
                                                    {p.primary_site.name}
                                                </span>
                                            ) : null}
                                        </div>
                                    </>
                                );
                                return p.profile_id ? (
                                    // eslint-disable-next-line no-restricted-syntax -- whole row is a selector that opens the staff-details modal
                                    <button
                                        key={p.id}
                                        type="button"
                                        onClick={() => setSelectedId(p.profile_id)}
                                        className="flex w-full items-center gap-4 p-4 text-left transition-colors hover:bg-muted/30"
                                    >
                                        {row}
                                    </button>
                                ) : (
                                    <div
                                        key={p.id}
                                        className="flex items-center gap-4 p-4"
                                    >
                                        {row}
                                    </div>
                                );
                            })}
                        </CardContent>
                    </Card>
                )}
            </div>

            <StaffDetailsModal
                profileId={selectedId}
                open={selectedId !== null}
                onClose={() => setSelectedId(null)}
            />
        </>
    );
}

export default DirectoryPane;
