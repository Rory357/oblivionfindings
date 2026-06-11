/* eslint-disable no-restricted-syntax -- The client-profile hero is a bespoke
 * gradient banner per the redesign handoff (hero.jsx): glass chips, stat
 * tiles, expandable next-shift tile and the safety strip are styled-native
 * surfaces bound to semantic tokens (primary gradient + primary-foreground,
 * mirroring components/page/page-hero.tsx). */
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    Bell,
    Briefcase,
    CalendarClock,
    ChevronDown,
    ChevronRight,
    ClipboardList,
    Clock,
    Coffee,
    Flag,
    Hash,
    Home,
    Info,
    ListChecks,
    MessageCircle,
    MessageSquare,
    Minus,
    MoreHorizontal,
    Pencil,
    Phone,
    Pill,
    Plus,
    Printer,
    Shield,
    ShieldAlert,
    Target,
    TrendingDown,
    TrendingUp,
    Users,
    Zap,
} from 'lucide-react';
import type { ComponentType, ReactNode } from 'react';

type IconType = ComponentType<{ className?: string }>;

export type HeroBadge = {
    key: string;
    label: string;
    icon?: IconType;
    tone: 'success' | 'warning' | 'critical' | 'info' | 'neutral';
    onClick?: () => void;
};

export type HeroVital = {
    key: string;
    label: string;
    value: string;
    trend: 'up' | 'down' | 'flat';
    detail: string;
};

export type HeroNextShift = {
    when: string;
    countdown: string | null;
    staffName: string | null;
    typeLabel: string;
    tasksDone: number;
    tasksTotal: number;
    location: string | null;
    breakLabel: string | null;
    medsLabel: string | null;
    handoverSnippet: string | null;
};

export type HeroSafety = {
    allergies: string[];
    alerts: string[];
};

export type HeroAlert = {
    key: string;
    tone: 'critical' | 'warning';
    icon: IconType;
    label: string;
    detail?: string;
    onClick?: () => void;
};

const BADGE_TONE: Record<HeroBadge['tone'], string> = {
    success: 'bg-status-success-bg text-status-success',
    warning: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
    info: 'bg-status-info-bg text-status-info',
    neutral: 'bg-muted text-muted-foreground',
};

const GLASS =
    'border border-primary-foreground/20 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm';

function HeroChip({ icon: Icon, children }: { icon?: IconType; children: ReactNode }) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium',
                GLASS,
            )}
        >
            {Icon ? <Icon className="h-3 w-3 opacity-80" /> : null}
            {children}
        </span>
    );
}

function HeroBadgePill({ badge }: { badge: HeroBadge }) {
    const Icon = badge.icon;
    const inner = (
        <>
            {Icon ? <Icon className="h-3 w-3" /> : null}
            {badge.label}
        </>
    );
    const cls = cn(
        'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold',
        BADGE_TONE[badge.tone],
        badge.onClick && 'cursor-pointer transition-all hover:brightness-95',
    );
    if (badge.onClick) {
        return (
            <button type="button" onClick={badge.onClick} className={cls}>
                {inner}
            </button>
        );
    }
    return <span className={cls}>{inner}</span>;
}

function Trend({ dir }: { dir: HeroVital['trend'] }) {
    if (dir === 'flat')
        return <Minus className="h-[13px] w-[13px] text-primary-foreground/60" />;
    if (dir === 'up')
        return <TrendingUp className="h-[13px] w-[13px] text-status-success" />;
    return <TrendingDown className="h-[13px] w-[13px] text-status-warning" />;
}

function VitalTile({ v }: { v: HeroVital }) {
    return (
        <div className={cn('rounded-xl px-3.5 py-2.5', GLASS)}>
            <div className="flex items-center justify-between">
                <span className="text-[11px] font-medium text-primary-foreground/70">
                    {v.label}
                </span>
                <Trend dir={v.trend} />
            </div>
            <div className="mt-1 text-lg leading-none font-bold">{v.value}</div>
            <div className="mt-1 truncate text-[10px] text-primary-foreground/55">
                {v.detail}
            </div>
        </div>
    );
}

function StatTile({ icon: Icon, label, value }: { icon: IconType; label: string; value: string }) {
    return (
        <div className={cn('rounded-xl px-3 py-2 text-center', GLASS)}>
            <div className="mb-0.5 flex items-center justify-center gap-1 text-[10px] font-semibold tracking-wide text-primary-foreground/65 uppercase">
                <Icon className="h-[11px] w-[11px]" />
                {label}
            </div>
            <div className="text-base leading-none font-bold">{value}</div>
        </div>
    );
}

function ShiftDetailRow({ icon: Icon, label, value }: { icon: IconType; label: string; value: string }) {
    return (
        <div className="flex items-center gap-2">
            <Icon className="h-[13px] w-[13px] text-primary-foreground/60" />
            <span className="text-[11px] text-primary-foreground/60">{label}</span>
            <span className="ml-auto text-[11px] font-semibold">{value}</span>
        </div>
    );
}

function NextShiftTile({ shift, onOpen }: { shift: HeroNextShift | null; onOpen: () => void }) {
    if (!shift) {
        return (
            <div className={cn('flex items-center gap-3 rounded-xl px-3.5 py-2.5', GLASS)}>
                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary-foreground/15">
                    <CalendarClock className="h-[17px] w-[17px]" />
                </span>
                <div className="min-w-0 leading-tight">
                    <div className="text-[11px] text-primary-foreground/65">Next shift</div>
                    <div className="text-sm font-semibold">Nothing rostered</div>
                </div>
            </div>
        );
    }
    const pct = shift.tasksTotal > 0 ? Math.round((shift.tasksDone / shift.tasksTotal) * 100) : 0;
    return (
        <button
            type="button"
            onClick={onOpen}
            className={cn(
                'group/shift cursor-pointer rounded-xl px-3.5 py-2.5 text-left transition-all',
                GLASS,
            )}
        >
            <div className="flex items-center gap-3">
                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary-foreground/15">
                    <CalendarClock className="h-[17px] w-[17px]" />
                </span>
                <div className="min-w-0 flex-1 leading-tight">
                    <div className="flex items-center gap-1.5 text-[11px] text-primary-foreground/65">
                        Next shift
                        {shift.countdown ? (
                            <span className="rounded bg-primary-foreground/15 px-1 py-px text-[9px] font-semibold text-primary-foreground/80 uppercase">
                                {shift.countdown}
                            </span>
                        ) : null}
                    </div>
                    <div className="truncate text-sm font-semibold">{shift.when}</div>
                    <div className="truncate text-[11px] text-primary-foreground/65">
                        {[shift.staffName, shift.typeLabel].filter(Boolean).join(' · ')}
                    </div>
                </div>
                <ChevronDown className="h-[15px] w-[15px] shrink-0 text-primary-foreground/60 transition-transform duration-300 group-hover/shift:rotate-180" />
            </div>

            <div className="grid grid-rows-[0fr] opacity-0 transition-all duration-300 ease-out group-hover/shift:mt-2.5 group-hover/shift:grid-rows-[1fr] group-hover/shift:opacity-100 group-focus-visible/shift:mt-2.5 group-focus-visible/shift:grid-rows-[1fr] group-focus-visible/shift:opacity-100">
                <div className="overflow-hidden">
                    <div className="space-y-2 border-t border-primary-foreground/15 pt-2.5">
                        {shift.tasksTotal > 0 ? (
                            <div>
                                <div className="mb-1 flex items-center justify-between">
                                    <span className="flex items-center gap-1.5 text-[11px] text-primary-foreground/60">
                                        <ListChecks className="h-[13px] w-[13px]" /> Shift tasks
                                    </span>
                                    <span className="text-[11px] font-semibold">
                                        {shift.tasksDone}/{shift.tasksTotal} done
                                    </span>
                                </div>
                                <div className="h-1.5 w-full overflow-hidden rounded-full bg-primary-foreground/20">
                                    <div
                                        className="h-full rounded-full bg-primary-foreground"
                                        style={{ width: `${pct}%` }}
                                    />
                                </div>
                            </div>
                        ) : null}
                        {shift.location ? (
                            <ShiftDetailRow icon={Home} label="Location" value={shift.location} />
                        ) : null}
                        {shift.breakLabel ? (
                            <ShiftDetailRow icon={Coffee} label="Break" value={shift.breakLabel} />
                        ) : null}
                        {shift.medsLabel ? (
                            <ShiftDetailRow icon={Pill} label="Meds" value={shift.medsLabel} />
                        ) : null}
                        {shift.handoverSnippet ? (
                            <div className="rounded-lg bg-primary-foreground/10 px-2.5 py-2 text-left">
                                <div className="mb-0.5 flex items-center gap-1.5 text-[10px] font-semibold tracking-wide text-primary-foreground/55 uppercase">
                                    <MessageSquare className="h-[11px] w-[11px]" /> Pinned handover
                                </div>
                                <p className="line-clamp-2 text-[11px] leading-snug text-primary-foreground/85">
                                    {shift.handoverSnippet}
                                </p>
                            </div>
                        ) : null}
                    </div>
                </div>
            </div>
        </button>
    );
}

function SafetyStrip({ safety, onOpen }: { safety: HeroSafety; onOpen: () => void }) {
    const others = safety.alerts.length;
    if (!safety.allergies.length && !others) {
        return (
            <div className={cn('flex h-full items-center gap-3 rounded-xl px-3.5 py-2.5', GLASS)}>
                <Shield className="h-4 w-4 text-primary-foreground/80" />
                <span className="text-xs font-bold tracking-wide uppercase">Safety information</span>
                <span className="text-xs text-primary-foreground/70">
                    No allergies or active safety alerts recorded
                </span>
            </div>
        );
    }
    return (
        <button
            type="button"
            onClick={onOpen}
            className="flex h-full w-full cursor-pointer flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border border-status-critical/45 bg-status-critical/15 px-3.5 py-2.5 text-left transition-all hover:bg-status-critical/20"
        >
            <ShieldAlert className="h-4 w-4 text-primary-foreground" />
            <span className="text-xs font-bold tracking-wide text-primary-foreground uppercase">
                Safety information
            </span>
            <span className="hidden text-xs text-primary-foreground/70 sm:inline">
                Check before starting shift
            </span>
            <span className="ml-auto flex flex-wrap items-center gap-1.5">
                {safety.allergies.slice(0, 3).map((a) => (
                    <span
                        key={a}
                        className="inline-flex items-center gap-1 rounded-full bg-primary-foreground/15 px-2.5 py-1 text-xs font-semibold text-primary-foreground"
                    >
                        <AlertTriangle className="h-3 w-3" /> Allergy: {a}
                    </span>
                ))}
                {others > 0 ? (
                    <span className="inline-flex items-center gap-1 rounded-full bg-primary-foreground/10 px-2.5 py-1 text-xs font-medium text-primary-foreground/90">
                        <Info className="h-3 w-3" /> +{others} other {others > 1 ? 'risks' : 'risk'}
                    </span>
                ) : null}
            </span>
        </button>
    );
}

export type MoreMenuItem = {
    key: string;
    label: string;
    icon: IconType;
    detail?: string;
    onSelect: () => void;
};

export function ClientProfileHero({
    clientId,
    name,
    photoUrl,
    initials,
    statusLabel,
    statusTone,
    identityLine,
    chips,
    badges,
    vitals,
    nextShift,
    safety,
    stats,
    canEdit,
    chatBadge,
    onAddNote,
    onChat,
    onEdit,
    onOpenShift,
    onOpenSafety,
    moreItems,
    footer,
    backLabel = 'Clients',
}: {
    clientId: number;
    name: string;
    photoUrl?: string | null;
    initials: string;
    statusLabel: string;
    statusTone: HeroBadge['tone'];
    identityLine: string;
    chips: { key: string; icon?: IconType; label: string }[];
    badges: HeroBadge[];
    vitals: HeroVital[];
    nextShift: HeroNextShift | null;
    safety: HeroSafety;
    stats: { key: string; icon: IconType; label: string; value: string }[];
    canEdit: boolean;
    chatBadge?: number;
    onAddNote: (key: 'daily_note' | 'quick_note' | 'comm_note' | 'log_incident') => void;
    onChat: () => void;
    onEdit: () => void;
    onOpenShift: () => void;
    onOpenSafety: () => void;
    moreItems: MoreMenuItem[];
    footer?: ReactNode;
    backLabel?: string;
}) {
    return (
        <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-primary/90 via-primary to-primary/80 text-primary-foreground shadow-lg">
            {/* decorative orbs */}
            <div className="pointer-events-none absolute inset-0 overflow-hidden">
                <div className="absolute -top-20 -right-16 h-64 w-64 rounded-full bg-primary-foreground/5" />
                <div className="absolute -bottom-24 left-1/4 h-52 w-52 rounded-full bg-primary-foreground/5" />
            </div>

            <div className="relative p-5 md:p-6">
                {/* top row */}
                <div className="mb-4 flex items-center justify-between">
                    <Link
                        href="/operations/clients"
                        className="inline-flex items-center gap-1.5 text-xs text-primary-foreground/70 transition-colors hover:text-primary-foreground"
                    >
                        <ArrowLeft className="h-3.5 w-3.5" />
                        {backLabel}
                    </Link>
                    <span className="text-xs font-medium text-primary-foreground/55">
                        #{clientId}
                    </span>
                </div>

                <div className="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                    {/* identity */}
                    <div className="flex items-start gap-4">
                        <div className="relative shrink-0">
                            <Avatar className="h-[76px] w-[76px] border-4 border-primary-foreground/25">
                                {photoUrl ? <AvatarImage src={photoUrl} alt={name} /> : null}
                                <AvatarFallback className="bg-primary-foreground/10 text-2xl font-semibold text-primary-foreground">
                                    {initials}
                                </AvatarFallback>
                            </Avatar>
                            <span
                                className={cn(
                                    'absolute -right-0.5 -bottom-0.5 flex h-5 w-5 items-center justify-center rounded-full ring-2 ring-primary',
                                    statusTone === 'success'
                                        ? 'bg-status-success'
                                        : statusTone === 'warning'
                                          ? 'bg-status-warning'
                                          : 'bg-muted-foreground',
                                )}
                                title={statusLabel}
                            >
                                <span className="h-2 w-2 rounded-full bg-card" />
                            </span>
                        </div>

                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-2.5">
                                <h1 className="text-2xl font-bold tracking-tight">{name}</h1>
                                <HeroBadgePill
                                    badge={{
                                        key: 'status',
                                        label: statusLabel,
                                        tone: statusTone,
                                    }}
                                />
                            </div>
                            {identityLine ? (
                                <p className="mt-0.5 text-sm text-primary-foreground/70">
                                    {identityLine}
                                </p>
                            ) : null}

                            {chips.length ? (
                                <div className="mt-3 flex flex-wrap items-center gap-1.5">
                                    {chips.map((chip) => (
                                        <HeroChip key={chip.key} icon={chip.icon}>
                                            {chip.label}
                                        </HeroChip>
                                    ))}
                                </div>
                            ) : null}

                            {badges.length ? (
                                <div className="mt-2.5 flex flex-wrap items-center gap-1.5">
                                    {badges.map((badge) => (
                                        <HeroBadgePill key={badge.key} badge={badge} />
                                    ))}
                                </div>
                            ) : null}
                        </div>
                    </div>

                    {/* actions + stats */}
                    <div className="flex shrink-0 flex-col items-stretch gap-3 lg:items-end">
                        <div className="flex flex-wrap items-center gap-2">
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <button
                                        type="button"
                                        className="inline-flex h-9 items-center gap-2 rounded-lg bg-primary-foreground px-3.5 text-sm font-semibold text-primary shadow-sm transition-all hover:bg-primary-foreground/90 active:scale-[0.98]"
                                        data-test="client-profile-add-note"
                                    >
                                        <Plus className="h-4 w-4" />
                                        Add note
                                        <ChevronDown className="h-3.5 w-3.5 opacity-70" />
                                    </button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end" className="w-56">
                                    <DropdownMenuItem onSelect={() => onAddNote('daily_note')}>
                                        <ClipboardList className="mr-2 h-4 w-4 text-muted-foreground" />
                                        Daily note
                                    </DropdownMenuItem>
                                    <DropdownMenuItem onSelect={() => onAddNote('quick_note')}>
                                        <Zap className="mr-2 h-4 w-4 text-muted-foreground" />
                                        Quick note
                                    </DropdownMenuItem>
                                    <DropdownMenuItem onSelect={() => onAddNote('comm_note')}>
                                        <MessageSquare className="mr-2 h-4 w-4 text-muted-foreground" />
                                        Communication note
                                    </DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem onSelect={() => onAddNote('log_incident')}>
                                        <AlertTriangle className="mr-2 h-4 w-4 text-status-critical" />
                                        Log incident
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>

                            <button
                                type="button"
                                title="Chat with whānau"
                                onClick={onChat}
                                className={cn(
                                    'relative inline-flex h-9 items-center gap-1.5 rounded-lg px-3 text-sm font-medium transition-all hover:bg-primary-foreground/20 active:scale-95',
                                    GLASS,
                                )}
                                data-test="client-profile-chat"
                            >
                                <MessageCircle className="h-4 w-4" />
                                <span className="hidden sm:inline">Chat</span>
                                {chatBadge ? (
                                    <span className="absolute -top-1 -right-1 flex h-4 w-4 items-center justify-center rounded-full bg-status-success text-[9px] font-bold text-card ring-2 ring-primary">
                                        {chatBadge}
                                    </span>
                                ) : null}
                            </button>

                            {canEdit ? (
                                <button
                                    type="button"
                                    title="Edit profile"
                                    onClick={onEdit}
                                    className={cn(
                                        'inline-flex h-9 w-9 items-center justify-center rounded-lg transition-all hover:bg-primary-foreground/20 active:scale-95',
                                        GLASS,
                                    )}
                                    data-test="client-profile-edit"
                                >
                                    <Pencil className="h-4 w-4" />
                                </button>
                            ) : null}

                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <button
                                        type="button"
                                        title="More"
                                        className={cn(
                                            'inline-flex h-9 w-9 items-center justify-center rounded-lg transition-all hover:bg-primary-foreground/20 active:scale-95',
                                            GLASS,
                                        )}
                                    >
                                        <MoreHorizontal className="h-4 w-4" />
                                    </button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end" className="w-60">
                                    <DropdownMenuLabel>More actions</DropdownMenuLabel>
                                    {moreItems.map((item) => {
                                        const Icon = item.icon;
                                        return (
                                            <DropdownMenuItem
                                                key={item.key}
                                                onSelect={item.onSelect}
                                            >
                                                <Icon className="mr-2 h-4 w-4 text-muted-foreground" />
                                                {item.label}
                                                {item.detail ? (
                                                    <span className="ml-auto text-xs text-muted-foreground">
                                                        {item.detail}
                                                    </span>
                                                ) : null}
                                            </DropdownMenuItem>
                                        );
                                    })}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>

                        <div className="grid w-full grid-cols-3 gap-2 md:w-[300px]">
                            {stats.map((s) => (
                                <StatTile key={s.key} icon={s.icon} label={s.label} value={s.value} />
                            ))}
                        </div>
                    </div>
                </div>

                {/* next shift + safety strip */}
                <div className="mt-4 grid items-stretch gap-2.5 md:grid-cols-[300px_1fr]">
                    <NextShiftTile shift={nextShift} onOpen={onOpenShift} />
                    <SafetyStrip safety={safety} onOpen={onOpenSafety} />
                </div>

                {/* vitals strip */}
                {vitals.length ? (
                    <div className="mt-5 grid grid-cols-2 gap-2.5 sm:grid-cols-4">
                        {vitals.map((v) => (
                            <VitalTile key={v.key} v={v} />
                        ))}
                    </div>
                ) : null}
            </div>

            {footer ? (
                <div className="relative border-t border-primary-foreground/20 px-4 md:px-5">
                    {footer}
                </div>
            ) : null}
        </div>
    );
}

/** "Needs attention" ribbon under the hero. */
export function AlertRibbon({ alerts }: { alerts: HeroAlert[] }) {
    if (!alerts.length) return null;
    return (
        <div className="mt-4 flex flex-wrap items-center gap-2">
            <span className="inline-flex items-center gap-1.5 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                <Bell className="h-[13px] w-[13px]" />
                Needs attention
            </span>
            {alerts.map((a) => {
                const Icon = a.icon;
                return (
                    <button
                        key={a.key}
                        type="button"
                        onClick={a.onClick}
                        className={cn(
                            'group inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-medium transition-all hover:shadow-sm',
                            a.tone === 'critical'
                                ? 'border-status-critical/30 bg-status-critical-bg text-status-critical'
                                : 'border-status-warning/30 bg-status-warning-bg text-status-warning',
                        )}
                    >
                        <Icon className="h-[13px] w-[13px]" />
                        {a.label}
                        {a.detail ? <span className="opacity-60">· {a.detail}</span> : null}
                        <ChevronRight className="h-[13px] w-[13px] opacity-0 transition-opacity group-hover:opacity-70" />
                    </button>
                );
            })}
        </div>
    );
}

export const HERO_ICONS = {
    Hash,
    Home,
    Clock,
    Briefcase,
    Target,
    Flag,
    CalendarClock,
    Phone,
    Users,
    Printer,
};
