// New governance pages: see docs/GOVERNANCE_HERO_GUIDE.md
// for the required hero contract (category, icon, stats, etc).
import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import type { CSSProperties, ComponentType, ReactNode } from 'react';
import { isValidElement, useState } from 'react';

import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { cn } from '@/lib/utils';

import {
    AvatarPopoverContent,
    PageHeroAvatarStack,
    type PageHeroAvatarPopover,
    type PageHeroStackAvatar,
} from './page-hero-avatar-stack';
import { PageHeroActions } from './page-hero-actions';
import { PageHeroBadges, type PageHeroBadge } from './page-hero-badges';
import { PageHeroMeta, type PageHeroMetaItem } from './page-hero-meta';
import {
    PageHeroQuickActions,
    type PageHeroQuickAction,
} from './page-hero-quick-actions';
import { PageHeroStats, type PageHeroStat } from './page-hero-stats';

export type PageHeroVariant = 'hero' | 'compact' | 'inline';

export type PageHeroCategory =
    | 'ops'
    | 'hr'
    | 'compliance'
    | 'incidents'
    | 'governance'
    | 'sites'
    | 'fleet';

type IconLike = ComponentType<{ className?: string }>;

export type PageHeroAvatar = {
    src?: string | null;
    fallback: string;
    /** Hue (0-360) for the hover popover's mini-avatar. Only read when `popover` is set. */
    hue?: number;
    /** Optional hover popover with quick actions — parity with the multi-resident stack. */
    popover?: PageHeroAvatarPopover;
};

export interface PageHeroProps {
    /** Visual density. Default 'hero' renders the full gradient banner. */
    variant?: PageHeroVariant;

    /** Optional category-themed gradient — swaps the base from --primary to --category-*. */
    category?: PageHeroCategory;

    /** Top-of-hero back link. */
    backHref?: string;
    backLabel?: string;

    /** Big circular icon on the left (variant='hero' only). */
    icon?: IconLike | ReactNode;
    /** Avatar takes precedence over icon when both supplied (use for person detail). */
    avatar?: PageHeroAvatar;
    /**
     * Multi-subject avatar stack. Overrides `avatar` and `icon` when set.
     * Renders an overlapping ring of avatars with hover popovers.
     */
    avatarStack?: PageHeroStackAvatar[];

    title: ReactNode;
    description?: ReactNode;
    /** Alias for `description` — preserved for FleetHero migration compatibility. */
    subtitle?: ReactNode;

    /** Sub-meta line(s) under title (e.g. address with MapPin). */
    meta?: PageHeroMetaItem[];

    badges?: PageHeroBadge[];
    stats?: PageHeroStat[];

    /** Right-column buttons. Auto-wrapped in PageHeroActions for hero variant. */
    actions?: ReactNode;

    /** Icon-only quick-action strip rendered in the right column below stats. */
    quickActions?: PageHeroQuickAction[];

    /** Optional heading shown above the quick-action grid. */
    quickActionsHeading?: string;

    /** Escape hatch — rendered under the badges row, full width. */
    children?: ReactNode;

    /**
     * Full-width footer rendered inside the banner, separated by a border-top.
     * Use for resident tabs, secondary filter strips, etc.
     */
    footer?: ReactNode;

    className?: string;
}

function renderIcon(icon: IconLike | ReactNode, className: string): ReactNode {
    if (icon == null) return null;
    // Already a JSX element (e.g. <Building2 className="..." />): render as-is.
    if (isValidElement(icon)) return icon;
    // Otherwise treat as a component reference (function OR forwardRef object).
    // lucide-react icons are forwardRef exotic components — `typeof` is 'object',
    // NOT 'function' — so we can't gate on typeof alone.
    const IconComp = icon as IconLike;
    return <IconComp className={className} />;
}

function HeroVariant(props: PageHeroProps) {
    const {
        category,
        backHref,
        backLabel = 'Back',
        icon,
        avatar,
        avatarStack,
        title,
        description,
        subtitle,
        meta,
        badges,
        stats,
        actions,
        quickActions,
        quickActionsHeading,
        children,
        footer,
        className,
    } = props;
    const supportingText = description ?? subtitle;

    const style: CSSProperties | undefined = category
        ? ({ ['--hero-base' as string]: `var(--category-${category})` } as CSSProperties)
        : undefined;

    const gradientClass = category
        ? 'bg-[linear-gradient(to_bottom_right,color-mix(in_oklch,var(--hero-base)_90%,transparent),var(--hero-base),color-mix(in_oklch,var(--hero-base)_80%,transparent))]'
        : 'bg-gradient-to-br from-primary/90 via-primary to-primary/80';

    const renderedIcon = avatar || avatarStack ? null : renderIcon(icon, 'h-12 w-12 text-primary-foreground md:h-14 md:w-14');

    return (
        // OUTER: relative + rounded but NOT overflow-hidden, so hover popovers
        // (e.g. resident popover on the avatar stack) can extend past the
        // banner's bottom edge.
        <div
            style={style}
            className={cn(
                'relative rounded-2xl text-primary-foreground',
                gradientClass,
                className,
            )}
        >
            {/* INNER ORB CLIP — purely visual; clipped to the rounded shape so the
                three decorative circles don't bleed past the banner. */}
            <div className="pointer-events-none absolute inset-0 overflow-hidden rounded-2xl">
                <div className="absolute -right-16 -top-16 h-64 w-64 rounded-full bg-primary-foreground/5" />
                <div className="absolute -bottom-20 -left-20 h-48 w-48 rounded-full bg-primary-foreground/5" />
                <div className="absolute right-1/3 top-1/4 h-24 w-24 rounded-full bg-primary-foreground/5" />
            </div>

            <div className="relative p-6 md:p-8">
                {backHref ? (
                    <Link
                        href={backHref}
                        className="mb-3 inline-flex items-center gap-1.5 text-xs text-primary-foreground/60 transition-colors hover:text-primary-foreground/90"
                    >
                        <ArrowLeft className="h-3.5 w-3.5" />
                        {backLabel}
                    </Link>
                ) : null}

                <div className="flex flex-col items-center gap-6 md:flex-row md:items-start">
                    {avatarStack && avatarStack.length > 0 ? (
                        <PageHeroAvatarStack residents={avatarStack} />
                    ) : avatar ? (
                        <HeroSingleAvatar avatar={avatar} />
                    ) : renderedIcon ? (
                        <div className="flex h-24 w-24 shrink-0 items-center justify-center rounded-full border-4 border-primary-foreground/20 bg-primary-foreground/10 shadow-xl md:h-28 md:w-28">
                            {renderedIcon}
                        </div>
                    ) : null}

                    <div className="min-w-0 flex-1 text-center md:text-left">
                        <h1 className="text-2xl font-bold tracking-tight md:text-3xl">{title}</h1>
                        {supportingText ? (
                            <p className="mt-1 text-sm text-primary-foreground/70">{supportingText}</p>
                        ) : null}
                        {meta && meta.length > 0 ? <PageHeroMeta items={meta} /> : null}
                        {badges && badges.length > 0 ? <PageHeroBadges badges={badges} /> : null}
                        {children ? <div className="mt-3">{children}</div> : null}
                    </div>

                    {(actions || (stats && stats.length > 0) || (quickActions && quickActions.length > 0)) && (
                        <div className="flex w-full flex-col items-center gap-3 md:w-auto md:items-end">
                            {actions ? <PageHeroActions>{actions}</PageHeroActions> : null}
                            {stats && stats.length > 0 ? (
                                <PageHeroStats stats={stats} layout="inline" />
                            ) : null}
                            {quickActions && quickActions.length > 0 ? (
                                <PageHeroQuickActions
                                    heading={quickActionsHeading}
                                    actions={quickActions}
                                />
                            ) : null}
                        </div>
                    )}
                </div>
            </div>

            {footer ? (
                <div className="relative overflow-hidden rounded-b-2xl border-t border-primary-foreground/20 px-4">
                    {footer}
                </div>
            ) : null}
        </div>
    );
}

/**
 * Single-subject hero avatar. When `avatar.popover` is supplied it gains the
 * same hover quick-actions popover as the multi-resident stack; otherwise it
 * renders as a plain avatar (the behaviour every other caller relies on). The
 * popover lives inside the hover wrapper so the cursor can travel from the
 * avatar into it without the gap dismissing it.
 */
function HeroSingleAvatar({ avatar }: { avatar: PageHeroAvatar }) {
    const [hover, setHover] = useState(false);

    const avatarEl = (
        <Avatar
            className={cn(
                'h-24 w-24 shrink-0 border-4 border-primary-foreground/20 shadow-xl md:h-28 md:w-28',
                avatar.popover && 'cursor-pointer transition-shadow duration-200',
                avatar.popover &&
                    hover &&
                    'shadow-[0_14px_30px_-10px_rgba(0,0,0,0.45),0_0_0_3px_var(--primary-foreground)]',
            )}
        >
            {avatar.src ? <AvatarImage src={avatar.src} alt={avatar.fallback} /> : null}
            <AvatarFallback className="bg-primary-foreground/10 text-2xl font-semibold text-primary-foreground">
                {avatar.fallback}
            </AvatarFallback>
        </Avatar>
    );

    if (!avatar.popover) return avatarEl;

    return (
        <div
            className="relative shrink-0"
            onMouseEnter={() => setHover(true)}
            onMouseLeave={() => setHover(false)}
            onFocus={() => setHover(true)}
            onBlur={() => setHover(false)}
        >
            {avatarEl}
            {hover ? (
                <AvatarPopoverContent popover={avatar.popover} initials={avatar.fallback} hue={avatar.hue ?? 0} />
            ) : null}
        </div>
    );
}

function CompactVariant(props: PageHeroProps) {
    const { backHref, backLabel = 'Back', title, description, subtitle, actions, children, className } = props;
    const supportingText = description ?? subtitle;
    return (
        <div
            className={cn(
                'flex flex-col gap-4 md:flex-row md:items-start md:justify-between',
                className,
            )}
        >
            <div className="min-w-0">
                {backHref ? (
                    <Link
                        href={backHref}
                        className="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="h-4 w-4" />
                        {backLabel}
                    </Link>
                ) : null}
                <h1 className="mt-1 text-xl font-semibold tracking-tight md:text-2xl">{title}</h1>
                {supportingText ? (
                    <p className="mt-2 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                        {supportingText}
                    </p>
                ) : null}
                {children ? <div className="mt-3">{children}</div> : null}
            </div>
            {actions ? (
                <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>
            ) : null}
        </div>
    );
}

function InlineVariant(props: PageHeroProps) {
    const { title, description, subtitle, actions, className } = props;
    const supportingText = description ?? subtitle;
    return (
        <div className={cn('flex items-start justify-between gap-3', className)}>
            <div className="min-w-0">
                <h1 className="text-lg font-semibold tracking-tight">{title}</h1>
                {supportingText ? (
                    <p className="mt-1 text-sm text-muted-foreground">{supportingText}</p>
                ) : null}
            </div>
            {actions ? (
                <div className="flex shrink-0 items-center gap-2">{actions}</div>
            ) : null}
        </div>
    );
}

export function PageHero(props: PageHeroProps) {
    const variant = props.variant ?? 'hero';
    if (variant === 'compact') return <CompactVariant {...props} />;
    if (variant === 'inline') return <InlineVariant {...props} />;
    return <HeroVariant {...props} />;
}

export default PageHero;
