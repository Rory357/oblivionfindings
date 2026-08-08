import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { StatusBadge } from '@/components/ui/status-badge';
import { Link } from '@inertiajs/react';
import {
    ArrowLeft,
    ChevronDown,
    MoreHorizontal,
    Pencil,
    Plus,
    type LucideIcon,
} from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';

export type SiteHeroAction = {
    id: string;
    label: string;
    icon: LucideIcon;
    href?: string;
    onSelect?: () => void;
};

export type SiteHeroStat = {
    id: string;
    label: string;
    value: string;
    detail?: string;
    icon: LucideIcon;
};

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');
}

function foregroundFor(colour: string): string {
    const match = /^#([\da-f]{6})$/i.exec(colour);
    if (!match) return 'var(--primary-foreground)';
    const value = match[1];
    const red = Number.parseInt(value.slice(0, 2), 16);
    const green = Number.parseInt(value.slice(2, 4), 16);
    const blue = Number.parseInt(value.slice(4, 6), 16);
    const luminance = (red * 299 + green * 587 + blue * 114) / 1000;

    return luminance > 155 ? 'var(--foreground)' : 'var(--primary-foreground)';
}

export function SiteProfileHero({
    siteId,
    name,
    description,
    brandColour,
    statusLabel,
    typeLabel,
    region,
    avatars,
    stats,
    actions,
    onEdit,
    footer,
}: {
    siteId: number;
    name: string;
    description: string;
    brandColour?: string | null;
    statusLabel: string;
    typeLabel: string;
    region?: string | null;
    avatars: Array<{
        id: number;
        name: string;
        profile_photo_url?: string | null;
    }>;
    stats: SiteHeroStat[];
    actions: SiteHeroAction[];
    onEdit?: () => void;
    footer?: ReactNode;
}) {
    const colour = brandColour || 'var(--primary)';
    const foreground = foregroundFor(colour);
    const style = {
        '--site-profile-colour': colour,
        '--site-profile-foreground': foreground,
        background: `linear-gradient(135deg, color-mix(in srgb, ${colour} 92%, var(--foreground)), ${colour} 58%, color-mix(in srgb, ${colour} 82%, var(--primary-foreground)))`,
        color: foreground,
    } as CSSProperties;

    return (
        <section
            className="relative overflow-hidden rounded-2xl shadow-lg"
            style={style}
            data-test="site-profile-hero"
        >
            <div
                className="pointer-events-none absolute inset-0 overflow-hidden"
                aria-hidden="true"
            >
                <div className="absolute -top-20 -right-16 h-64 w-64 rounded-full bg-primary-foreground/5" />
                <div className="absolute -bottom-24 left-1/4 h-52 w-52 rounded-full bg-primary-foreground/5" />
            </div>

            <div className="relative p-5 md:p-6">
                <div className="mb-4 flex items-center justify-between text-xs opacity-75">
                    <Link
                        href="/sites"
                        className="frontline-focus frontline-tap inline-flex items-center gap-1.5 rounded-md hover:opacity-100"
                    >
                        <ArrowLeft className="h-3.5 w-3.5" />
                        Sites
                    </Link>
                    <span className="font-medium">Site #{siteId}</span>
                </div>

                <div className="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2.5">
                            <h1 className="text-2xl font-bold tracking-tight">
                                {name}
                            </h1>
                            <StatusBadge status={statusLabel} size="sm">
                                {statusLabel}
                            </StatusBadge>
                        </div>
                        <p className="mt-1 text-sm opacity-75">{description}</p>
                        <div className="mt-3 flex flex-wrap gap-1.5 text-xs font-medium">
                            <span className="rounded-full border border-current/20 bg-primary-foreground/10 px-2.5 py-1">
                                {typeLabel}
                            </span>
                            {region ? (
                                <span className="rounded-full border border-current/20 bg-primary-foreground/10 px-2.5 py-1">
                                    {region}
                                </span>
                            ) : null}
                        </div>
                        {avatars.length ? (
                            <div className="mt-4 flex items-center">
                                <div
                                    className="flex -space-x-2"
                                    aria-label="Clients at this Site"
                                >
                                    {avatars.map((avatar) => (
                                        <Avatar
                                            key={avatar.id}
                                            className="h-9 w-9 border-2 border-primary-foreground/40"
                                        >
                                            {avatar.profile_photo_url ? (
                                                <AvatarImage
                                                    src={
                                                        avatar.profile_photo_url
                                                    }
                                                    alt={avatar.name}
                                                />
                                            ) : null}
                                            <AvatarFallback className="bg-primary-foreground/15 text-xs text-current">
                                                {initials(avatar.name)}
                                            </AvatarFallback>
                                        </Avatar>
                                    ))}
                                </div>
                                <span className="ml-3 text-xs opacity-75">
                                    People supported here
                                </span>
                            </div>
                        ) : null}
                    </div>

                    <div className="flex shrink-0 flex-col gap-3 lg:items-end">
                        <div className="flex flex-wrap items-center gap-2">
                            {actions.length ? (
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button
                                            type="button"
                                            className="frontline-tap rounded-lg bg-primary-foreground px-3.5 font-semibold text-primary shadow-sm hover:bg-primary-foreground/90"
                                        >
                                            <Plus className="h-4 w-4" /> Add /
                                            log{' '}
                                            <ChevronDown className="h-3.5 w-3.5 opacity-70" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent
                                        align="end"
                                        className="w-56"
                                    >
                                        {actions.map((action) => {
                                            const Icon = action.icon;
                                            return action.href ? (
                                                <DropdownMenuItem
                                                    key={action.id}
                                                    asChild
                                                    className="min-h-11"
                                                >
                                                    <Link href={action.href}>
                                                        <Icon className="mr-2 h-4 w-4" />
                                                        {action.label}
                                                    </Link>
                                                </DropdownMenuItem>
                                            ) : (
                                                <DropdownMenuItem
                                                    key={action.id}
                                                    onSelect={action.onSelect}
                                                    className="min-h-11"
                                                >
                                                    <Icon className="mr-2 h-4 w-4" />
                                                    {action.label}
                                                </DropdownMenuItem>
                                            );
                                        })}
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            ) : null}
                            {onEdit ? (
                                <Button
                                    type="button"
                                    onClick={onEdit}
                                    title="Edit Site"
                                    aria-label="Edit Site"
                                    variant="outline"
                                    size="icon"
                                    className="frontline-tap rounded-lg border-current/20 bg-primary-foreground/10 text-current hover:bg-primary-foreground/20 hover:text-current"
                                >
                                    <Pencil className="h-4 w-4" />
                                    <span className="sr-only">Edit Site</span>
                                </Button>
                            ) : null}
                            <Button
                                type="button"
                                title="More Site actions"
                                aria-label="More Site actions"
                                variant="outline"
                                size="icon"
                                className="frontline-tap rounded-lg border-current/20 bg-primary-foreground/10 text-current hover:bg-primary-foreground/20 hover:text-current"
                            >
                                <MoreHorizontal className="h-4 w-4" />
                                <span className="sr-only">
                                    More Site actions
                                </span>
                            </Button>
                        </div>

                        <div className="grid w-full grid-cols-3 gap-2 md:w-[360px]">
                            {stats.map((stat) => {
                                const Icon = stat.icon;
                                return (
                                    <Card
                                        key={stat.id}
                                        className="gap-0 border-current/20 bg-primary-foreground/10 px-3 py-2 text-center text-current shadow-none"
                                    >
                                        <div className="flex items-center justify-center gap-1 text-[10px] font-semibold tracking-wide uppercase opacity-70">
                                            <Icon className="h-3 w-3" />{' '}
                                            {stat.label}
                                        </div>
                                        <div className="mt-1 text-base leading-none font-bold">
                                            {stat.value}
                                        </div>
                                        {stat.detail ? (
                                            <div className="mt-1 truncate text-[10px] opacity-60">
                                                {stat.detail}
                                            </div>
                                        ) : null}
                                    </Card>
                                );
                            })}
                        </div>
                    </div>
                </div>
            </div>

            {footer ? (
                <div className="relative border-t border-current/20 px-4 md:px-5">
                    {footer}
                </div>
            ) : null}
        </section>
    );
}
