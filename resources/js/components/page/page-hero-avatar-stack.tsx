import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { type ComponentType, type ReactNode, useState } from 'react';

import { cn } from '@/lib/utils';

export type PageHeroAvatarPopover = {
    /** Heading line — the subject's display name. */
    title: string;
    /** Sub-heading line (e.g. "Resident · Rimu House"). */
    subtitle?: string;
    /** Optional note quote rendered in a brand-tinted block. */
    note?: ReactNode;
    /** Primary call-to-action — usually "Open profile". */
    primaryAction?: { label: string; href: string };
    /** 2-column grid of secondary quick actions. */
    actions?: { icon: ComponentType<{ className?: string }>; label: string; href: string }[];
};

export type PageHeroStackAvatar = {
    id: string | number;
    /** 2-letter initials shown inside the puck. */
    initials: string;
    /** Hue (0-360) controls the puck colour. Use a stable hash for parity with backend. */
    hue: number;
    /** Display name (used for aria + tooltip). */
    name: string;
    /** Optional hover popover with quick actions. */
    popover?: PageHeroAvatarPopover;
};

interface PageHeroAvatarStackProps {
    residents: PageHeroStackAvatar[];
    /** Max visible avatars before showing the "+N" puck. Default 3. */
    max?: number;
    className?: string;
}

/**
 * Overlapping avatar stack used in the hero when a shift covers multiple
 * subjects (e.g. a multi-resident house). On hover the avatar lifts and its
 * siblings dim; a popover with quick actions opens below.
 *
 * Visual-only. The page renders this through `PageHero.avatarStack`.
 */
export function PageHeroAvatarStack({ residents, max = 3, className }: PageHeroAvatarStackProps) {
    const [hoverIdx, setHoverIdx] = useState<number | null>(null);
    const visible = residents.slice(0, max);
    const extra = residents.length - visible.length;
    const anyHover = hoverIdx !== null;

    return (
        <div className={cn('flex shrink-0 items-center py-3', className)}>
            {visible.map((r, i) => (
                <StackedAvatar
                    key={r.id}
                    resident={r}
                    index={i}
                    total={visible.length}
                    isHover={hoverIdx === i}
                    anyHover={anyHover}
                    onEnter={() => setHoverIdx(i)}
                    onLeave={() => setHoverIdx((cur) => (cur === i ? null : cur))}
                />
            ))}
            {extra > 0 ? (
                <div
                    className={cn(
                        'relative z-0 -ml-[18px] flex h-14 w-14 items-center justify-center rounded-full',
                        'border-4 border-primary-foreground/20 bg-primary-foreground/20',
                        'text-base font-semibold text-primary-foreground',
                    )}
                >
                    +{extra}
                </div>
            ) : null}
        </div>
    );
}

interface StackedAvatarProps {
    resident: PageHeroStackAvatar;
    index: number;
    total: number;
    isHover: boolean;
    anyHover: boolean;
    onEnter: () => void;
    onLeave: () => void;
}

function StackedAvatar({ resident, index, total, isHover, anyHover, onEnter, onLeave }: StackedAvatarProps) {
    const background = `oklch(0.85 0.10 ${resident.hue})`;
    const foreground = `oklch(0.28 0.16 ${resident.hue})`;
    return (
        <div
            className="relative transition-[transform,opacity] duration-200 ease-out"
            style={{
                marginLeft: index === 0 ? 0 : -18,
                zIndex: isHover ? 50 : total - index,
                transform: isHover ? 'translateY(-6px) scale(1.06)' : 'none',
                opacity: anyHover && !isHover ? 0.78 : 1,
            }}
            onMouseEnter={onEnter}
            onMouseLeave={onLeave}
            onFocus={onEnter}
            onBlur={onLeave}
        >
            <button
                type="button"
                aria-label={resident.name}
                className={cn(
                    'flex h-[76px] w-[76px] cursor-pointer items-center justify-center rounded-full',
                    'border-4 border-primary-foreground/20 text-2xl font-semibold',
                    'shadow-[0_6px_22px_-8px_rgba(0,0,0,0.30)] transition-shadow duration-200',
                    isHover && 'shadow-[0_14px_30px_-10px_rgba(0,0,0,0.45),0_0_0_3px_var(--primary-foreground)]',
                )}
                style={{ background, color: foreground }}
            >
                {resident.initials}
            </button>

            {isHover && resident.popover ? (
                <AvatarPopoverContent popover={resident.popover} resident={resident} />
            ) : null}
        </div>
    );
}

function AvatarPopoverContent({
    popover,
    resident,
}: {
    popover: PageHeroAvatarPopover;
    resident: PageHeroStackAvatar;
}) {
    return (
        <div
            className={cn(
                'absolute left-1/2 top-[calc(100%+12px)] z-[100] w-[260px] -translate-x-1/2',
                'rounded-xl border border-border bg-popover p-3.5 text-popover-foreground',
                'shadow-[0_18px_50px_-12px_rgba(0,0,0,0.30),0_4px_12px_-4px_rgba(0,0,0,0.18)]',
                'animate-in fade-in-0 slide-in-from-top-2 duration-150',
            )}
            onMouseDown={(e) => e.stopPropagation()}
        >
            {/* arrow */}
            <div
                className="absolute -top-[7px] left-1/2 h-3 w-3 -translate-x-1/2 rotate-45 border-l border-t border-border bg-popover"
                aria-hidden="true"
            />

            <div className="mb-2.5 flex items-center gap-2.5">
                <div
                    className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-base font-semibold"
                    style={{
                        background: `oklch(0.85 0.10 ${resident.hue})`,
                        color: `oklch(0.28 0.16 ${resident.hue})`,
                    }}
                >
                    {resident.initials}
                </div>
                <div className="min-w-0 flex-1">
                    <div className="truncate text-sm font-semibold tracking-tight text-foreground">{popover.title}</div>
                    {popover.subtitle ? (
                        <div className="text-[11.5px] text-muted-foreground">{popover.subtitle}</div>
                    ) : null}
                </div>
            </div>

            {popover.note ? (
                <div className="mb-2.5 rounded-md border-l-2 border-primary bg-muted px-2.5 py-2 text-[11.5px] leading-snug text-muted-foreground">
                    <span className="font-medium text-foreground">Care note:</span> {popover.note}
                </div>
            ) : null}

            {popover.primaryAction ? (
                <Link
                    href={popover.primaryAction.href}
                    className="mb-2 flex items-center gap-2 rounded-md bg-primary px-2.5 py-2 text-[12.5px] font-semibold text-primary-foreground"
                >
                    <span className="flex-1">{popover.primaryAction.label}</span>
                    <ArrowRight className="h-3.5 w-3.5" />
                </Link>
            ) : null}

            {popover.actions && popover.actions.length > 0 ? (
                <div className="grid grid-cols-2 gap-1">
                    {popover.actions.map((action) => {
                        const Icon = action.icon;
                        return (
                            <Link
                                key={action.label}
                                href={action.href}
                                className="flex items-center gap-1.5 rounded-md px-2 py-1.5 text-[11.5px] font-medium text-foreground transition-colors hover:bg-muted"
                            >
                                <Icon className="h-3 w-3 text-muted-foreground" />
                                <span>{action.label}</span>
                            </Link>
                        );
                    })}
                </div>
            ) : null}
        </div>
    );
}

export default PageHeroAvatarStack;
