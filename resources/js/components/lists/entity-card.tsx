/* eslint-disable no-restricted-syntax -- The card is THE canonical list
 * surface (LIST_STYLE_GUIDE.md §2): a styled Card shell with slot anatomy,
 * bound to semantic tokens throughout. */
/**
 * The Event Horizon entity card (design_styles/LIST_STYLE_GUIDE.md §2).
 * One fixed skeleton for EVERY listable record — sites, clients,
 * incidents, staff, assets… Entities fill slots; the anatomy never forks:
 *
 *   1. status meridian (3px, worst ALERT state — record status never
 *      drives it)
 *   2. identity row (40px mark, name 15/650, one muted sub-line, kebab)
 *   3. fact chips row (status chip may lead)
 *   4. metric slot (at most one; omit when the entity has none)
 *   5. alert/safety chips row
 *   6. utility footer (person disc + lines + brand "Open →")
 *
 * Every card carries the kebab AND the right-click context menu, fed by
 * one MenuItem[] (entity-menu.tsx). No readiness/onboarding displays.
 */
import { ArrowRight, Check } from 'lucide-react';
import type {
    ComponentType,
    KeyboardEvent,
    MouseEvent,
    ReactNode,
} from 'react';

import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';

import { PersonDisc, ProgressValue } from './entity-cells';
import { EntityKebab, type MenuItem } from './entity-menu';

type IconType = ComponentType<{ className?: string }>;

export type EntityMeridian = 'critical' | 'warning' | 'success';

const MERIDIAN: Record<EntityMeridian, string> = {
    critical: 'bg-status-critical',
    warning: 'bg-status-warning',
    success: 'bg-status-success',
};

export interface EntityCardMetric {
    /** 10px uppercase label, e.g. "Occupancy". */
    label: string;
    /** Value line rendered right of the label. */
    value: ReactNode;
    /** 0–100 bar fill; null = empty muted track. */
    percent: number | null;
    tone?: 'brand' | 'warning' | 'critical' | 'success';
}

export interface EntityCardFooter {
    /** Person disc: brand-tint initials; muted icon disc when null. */
    personName?: string | null;
    personIcon?: IconType;
    /** Primary line (12/600), e.g. the lead's name or "No site lead". */
    primary: ReactNode;
    /** Context sub-line (10.5 muted), e.g. "Site lead · 6 clients". */
    secondary?: ReactNode;
    /** Right-edge extra after the Open link (e.g. a signal dot). */
    trailing?: ReactNode;
}

export interface EntityCardProps {
    meridian: EntityMeridian;
    /** 40px brand-tinted icon tile (places/things)… */
    icon?: IconType;
    /** …or a custom 40px mark (person avatar/initials disc). */
    mark?: ReactNode;
    name: string;
    /** ONE muted sub-line: location, or id · age. */
    subline?: ReactNode;
    sublineIcon?: IconType;
    /** The one MenuItem[] feeding kebab AND context menu. */
    actions: MenuItem[];
    onOpen?: () => void;
    onContextMenu?: (e: MouseEvent) => void;
    /** Fact chips row (EntityChip / EntityStatusChip). */
    chips?: ReactNode;
    /** At most one metric. Omit when the entity has none. */
    metric?: EntityCardMetric;
    /** Alert/safety chips row (EntityStatusChip). */
    alerts?: ReactNode;
    footer?: EntityCardFooter;
    /** Dim archived/inactive records. */
    muted?: boolean;
    openLabel?: string;
    /* Multi-select support (page-owned state). */
    selectMode?: boolean;
    selected?: boolean;
    onToggleSelect?: () => void;
    className?: string;
}

export function EntityCard({
    meridian,
    icon: Icon,
    mark,
    name,
    subline,
    sublineIcon: SubIcon,
    actions,
    onOpen,
    onContextMenu,
    chips,
    metric,
    alerts,
    footer,
    muted = false,
    openLabel = 'Open',
    selectMode = false,
    selected = false,
    onToggleSelect,
    className,
}: EntityCardProps) {
    const activate = () => (selectMode ? onToggleSelect?.() : onOpen?.());
    const onKeyDown = (e: KeyboardEvent) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            activate();
        }
    };

    return (
        <Card
            role="button"
            tabIndex={0}
            onClick={activate}
            onContextMenu={onContextMenu}
            onKeyDown={onKeyDown}
            className={cn(
                'group relative flex flex-col gap-0 overflow-hidden rounded-[14px] py-0 shadow-sm transition-all duration-150 outline-none hover:-translate-y-0.5 hover:border-primary hover:shadow-lg focus-visible:ring-2 focus-visible:ring-ring',
                (selectMode || onOpen) && 'cursor-pointer',
                selected && 'border-primary ring-2 ring-primary/45',
                muted && 'opacity-75',
                className,
            )}
        >
            {/* 1 — status meridian */}
            <div className={cn('h-[3px] w-full', MERIDIAN[meridian])} />

            <div className="flex flex-1 flex-col gap-3 px-4 pt-3.5 pb-3.5">
                {/* 2 — identity row */}
                <div className="flex items-start gap-3">
                    {selectMode ? (
                        <span
                            aria-hidden="true"
                            className={cn(
                                'mt-0.5 flex h-[22px] w-[22px] shrink-0 items-center justify-center rounded-[7px] border transition-colors',
                                selected
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'border-input bg-card text-transparent',
                            )}
                        >
                            <Check className="size-3.5" />
                        </span>
                    ) : (
                        (mark ??
                        (Icon ? (
                            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-[10px] bg-primary/15 text-primary">
                                <Icon className="size-5" />
                            </span>
                        ) : null))
                    )}
                    <div className="min-w-0 flex-1">
                        <h3 className="truncate text-[15px] leading-tight font-[650] tracking-tight">
                            {name}
                        </h3>
                        {subline ? (
                            <div className="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground">
                                {SubIcon ? (
                                    <SubIcon className="size-3 shrink-0" />
                                ) : null}
                                <span className="truncate">{subline}</span>
                            </div>
                        ) : null}
                    </div>
                    {!selectMode ? (
                        <EntityKebab
                            actions={actions}
                            className="-mt-0.5 -mr-1"
                        />
                    ) : null}
                </div>

                {/* 3 — fact chips */}
                {chips ? (
                    <div className="flex flex-wrap items-center gap-1.5">
                        {chips}
                    </div>
                ) : null}

                {/* 4 — metric slot (at most one) */}
                {metric ? (
                    <div className="min-w-0">
                        <div className="flex items-baseline justify-between gap-2">
                            <span className="text-[10px] font-semibold tracking-[0.07em] text-muted-foreground uppercase">
                                {metric.label}
                            </span>
                            <span className="text-xs font-semibold tabular-nums">
                                {metric.value}
                            </span>
                        </div>
                        <div className="mt-1.5">
                            <ProgressValue
                                percent={metric.percent}
                                tone={metric.tone ?? 'brand'}
                            />
                        </div>
                    </div>
                ) : null}

                {/* 5 — alert/safety chips */}
                {alerts ? (
                    <div className="flex flex-wrap items-center gap-1.5">
                        {alerts}
                    </div>
                ) : null}
            </div>

            {/* 6 — utility footer, bleeding to the card edges */}
            {footer ? (
                <div className="mt-auto flex items-center gap-2.5 border-t border-border bg-muted/40 px-4 py-2.5">
                    <PersonDisc
                        name={footer.personName}
                        icon={footer.personIcon}
                        size={24}
                    />
                    <div className="min-w-0 flex-1">
                        <div className="truncate text-xs font-semibold">
                            {footer.primary}
                        </div>
                        {footer.secondary ? (
                            <div className="truncate text-[10.5px] text-muted-foreground">
                                {footer.secondary}
                            </div>
                        ) : null}
                    </div>
                    {/* No onOpen (e.g. an archived record) → no Open affordance. */}
                    {!selectMode && onOpen ? (
                        <span className="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-primary">
                            {openLabel}
                            <ArrowRight className="size-3.5 transition-transform duration-150 group-hover:translate-x-0.5" />
                        </span>
                    ) : null}
                    {footer.trailing}
                </div>
            ) : null}
        </Card>
    );
}

/** The canonical card grid: `gap-5`, equal-height rows, 3-up at desktop. */
export function EntityCardGrid({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'grid [grid-template-columns:repeat(auto-fill,minmax(330px,1fr))] gap-5 [&>*]:h-full',
                className,
            )}
        >
            {children}
        </div>
    );
}
