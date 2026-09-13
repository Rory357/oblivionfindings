/* eslint-disable no-restricted-syntax -- Shared two-tier navigation uses native
 * controls for accessible tab, group, search, and pin interactions. */
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { Search } from 'lucide-react';
import {
    Fragment,
    useEffect,
    useMemo,
    useRef,
    useState,
    type ComponentType,
    type KeyboardEvent as ReactKeyboardEvent,
    type ReactNode,
} from 'react';

type IconType = ComponentType<{ className?: string }>;

export type GroupedProfileNavTab = {
    key: string;
    label: string;
    icon: IconType;
    count?: number;
    warningCount?: number;
    href?: string;
    disabled?: boolean;
};

export type GroupedProfileNavGroup = {
    key: string;
    label: string;
    icon: IconType;
    tabs: GroupedProfileNavTab[];
};

export type TierTwoTabAccessibilityProps = {
    id: string;
    role: 'tab';
    'aria-selected': boolean;
    'aria-controls'?: string;
    tabIndex: number;
    onKeyDown: (event: ReactKeyboardEvent<HTMLElement>) => void;
    'data-test': string;
};

/** Count badge — follows its tab's state colour (DESIGN.md counter rule):
 *  neutral at rest; the active tab passes its tone pair via `className`. */
function CountPill({ n, className }: { n?: number; className?: string }) {
    if (!n) return null;

    return (
        <span
            className={cn(
                'rounded-full px-1.5 py-0.5 text-[10px] leading-none font-bold tabular-nums',
                className ?? 'bg-muted text-muted-foreground',
            )}
        >
            {n}
        </span>
    );
}

/** Warning badge — a fixed verified-contrast status pair, readable on the
 *  hero gradient, the active page-coloured tab, and the page ground alike. */
function WarningPill({ n, label }: { n?: number; label: string }) {
    if (!n) return null;

    return (
        <span
            aria-label={`${label} has ${n} ${n === 1 ? 'item' : 'items'} needing attention`}
            className="rounded-full bg-status-warning-bg px-1.5 py-0.5 text-[10px] leading-none font-bold text-status-warning tabular-nums"
        >
            {n}
        </span>
    );
}

function isEditableTarget(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) return false;

    return (
        target.isContentEditable ||
        target.tagName === 'INPUT' ||
        target.tagName === 'TEXTAREA' ||
        target.tagName === 'SELECT'
    );
}

export function useGroupedProfileSearchShortcut(onSearch: () => void) {
    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (
                event.key !== '/' ||
                event.altKey ||
                event.ctrlKey ||
                event.metaKey ||
                isEditableTarget(event.target)
            ) {
                return;
            }

            event.preventDefault();
            onSearch();
        };

        window.addEventListener('keydown', onKeyDown);
        return () => window.removeEventListener('keydown', onKeyDown);
    }, [onSearch]);
}

/** Tier-1 group pills, designed for a PageHero footer. */
export function GroupPillRail({
    groups,
    openGroup,
    activeTab,
    onOpenGroup,
    onSearch,
    testIdPrefix = 'client',
    ariaLabel = 'Profile groups',
}: {
    groups: GroupedProfileNavGroup[];
    openGroup: string;
    activeTab: string;
    onOpenGroup: (key: string, tabKey: string) => void;
    onSearch: () => void;
    testIdPrefix?: string;
    ariaLabel?: string;
}) {
    const rememberedTabs = useRef<Record<string, string>>({});
    const groupButtonRefs = useRef<Array<HTMLButtonElement | null>>([]);

    useEffect(() => {
        const activeGroup = groups.find((group) =>
            group.tabs.some((tab) => tab.key === activeTab),
        );
        if (activeGroup) {
            rememberedTabs.current[activeGroup.key] = activeTab;
        }
    }, [activeTab, groups]);

    return (
        <div
            role="toolbar"
            aria-label={ariaLabel}
            className="scrollbar-none flex items-end gap-1.5 overflow-x-auto pt-2"
        >
            {groups.map((group, index) => {
                const isOpen = group.key === openGroup;
                const warningCount = group.tabs.reduce(
                    (total, tab) => total + (tab.warningCount ?? 0),
                    0,
                );
                const Icon = group.icon;

                return (
                    <button
                        key={group.key}
                        ref={(node) => {
                            groupButtonRefs.current[index] = node;
                        }}
                        type="button"
                        onClick={() => {
                            const remembered =
                                rememberedTabs.current[group.key];
                            const target = group.tabs.some(
                                (tab) =>
                                    tab.key === remembered && !tab.disabled,
                            )
                                ? remembered
                                : group.tabs.find((tab) => !tab.disabled)?.key;
                            if (target) onOpenGroup(group.key, target);
                        }}
                        onKeyDown={(event) => {
                            const lastIndex = groups.length - 1;
                            const nextIndex =
                                event.key === 'ArrowRight'
                                    ? (index + 1) % groups.length
                                    : event.key === 'ArrowLeft'
                                      ? (index - 1 + groups.length) %
                                        groups.length
                                      : event.key === 'Home'
                                        ? 0
                                        : event.key === 'End'
                                          ? lastIndex
                                          : null;
                            if (nextIndex === null) return;

                            event.preventDefault();
                            groupButtonRefs.current[nextIndex]?.focus();
                        }}
                        aria-pressed={isOpen}
                        data-test={`${testIdPrefix}-group-${group.key}`}
                        className={cn(
                            'inline-flex shrink-0 items-center gap-[7px] text-sm outline-none focus-visible:ring-2 focus-visible:ring-primary-foreground/80',
                            isOpen
                                ? // Rule 1 connected tab: the page --background
                                  // fill merges with the content below the hero.
                                  // pb-3 keeps the label on the inactive pills'
                                  // optical line — their centre sits 42/2 + 8px
                                  // margin = 29px above the hero edge;
                                  // (46 − 12)/2 + 12 = 29px here.
                                  'min-h-[46px] rounded-t-xl bg-background px-[18px] pb-3 font-semibold text-primary'
                                : 'mb-2 min-h-[42px] rounded-full px-[15px] font-medium text-primary-foreground/70 transition-colors hover:bg-primary-foreground/10 hover:text-primary-foreground',
                        )}
                    >
                        <Icon className="h-[15px] w-[15px]" />
                        {group.label}
                        <WarningPill
                            n={warningCount}
                            label={`${group.label} group`}
                        />
                    </button>
                );
            })}
            <button
                type="button"
                onClick={onSearch}
                title="Find a section (/)"
                aria-label="Find a section"
                data-test={`${testIdPrefix}-search`}
                className="mb-2 ml-auto inline-flex min-h-[42px] shrink-0 items-center gap-1.5 rounded-full px-3 text-sm font-medium text-primary-foreground/70 transition-colors hover:bg-primary-foreground/10 hover:text-primary-foreground focus-visible:ring-2 focus-visible:ring-primary-foreground/80 focus-visible:outline-none"
            >
                <Search className="h-[15px] w-[15px]" />
                <span className="hidden sm:inline">Find</span>
                <span className="hidden rounded border border-primary-foreground/30 px-1 text-[10px] sm:inline">
                    /
                </span>
            </button>
        </div>
    );
}

/** Tier-2 tone cycle (NAVIGATION_STYLE_GUIDE.md Rule 2): tones are assigned
 *  by position (`index % 5`), never hand-picked, and appear only on the
 *  active tab — inactive tabs stay fully neutral. Solid icon chips use the
 *  `--tone-chip-*` fills, which stay deep in both themes so the white glyph
 *  keeps contrast (the violet chip pairs `bg-primary` with
 *  `text-primary-foreground`, which branding keeps readable). */
const TONE_CYCLE = [
    {
        active: 'bg-primary/12 text-primary',
        chip: 'bg-primary text-primary-foreground',
        badge: 'bg-primary/18 text-primary',
        bar: 'bg-primary',
    },
    {
        active: 'bg-tone-teal/12 text-tone-teal',
        chip: 'bg-tone-chip-teal text-white',
        badge: 'bg-tone-teal/18 text-tone-teal',
        bar: 'bg-tone-teal',
    },
    {
        active: 'bg-status-success/12 text-status-success',
        chip: 'bg-tone-chip-success text-white',
        badge: 'bg-status-success/18 text-status-success',
        bar: 'bg-status-success',
    },
    {
        active: 'bg-status-warning/12 text-status-warning',
        chip: 'bg-tone-chip-warning text-white',
        badge: 'bg-status-warning/18 text-status-warning',
        bar: 'bg-status-warning',
    },
    {
        active: 'bg-status-critical/12 text-status-critical',
        chip: 'bg-tone-chip-critical text-white',
        badge: 'bg-status-critical/18 text-status-critical',
        bar: 'bg-status-critical',
    },
] as const;

/** Tier-2 toned sub-tab strip for the open group (Rule 2): a bare flex row
 *  on the page background — no card, border, or sticky chrome, and NO pin
 *  affordances (removed 2026-09-06 — sub-tabs are never pinnable). */
export function TierTwoTabs({
    tabs,
    activeTab,
    onTab,
    renderLink,
    testIdPrefix = 'client',
    ariaLabel = 'Profile sections',
    panelId,
}: {
    tabs: GroupedProfileNavTab[];
    activeTab: string;
    onTab: (key: string) => void;
    renderLink: (
        tab: GroupedProfileNavTab,
        className: string,
        inner: ReactNode,
        accessibilityProps: TierTwoTabAccessibilityProps,
    ) => ReactNode;
    testIdPrefix?: string;
    ariaLabel?: string;
    panelId?: string;
}) {
    const moveTab = (event: ReactKeyboardEvent<HTMLElement>, index: number) => {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
            return;
        }

        event.preventDefault();
        const enabled = tabs.filter((tab) => !tab.disabled);
        if (!enabled.length) return;

        const current = enabled.findIndex((tab) => tab.key === tabs[index].key);
        const target =
            event.key === 'Home'
                ? enabled[0]
                : event.key === 'End'
                  ? enabled.at(-1)
                  : enabled[
                        (current +
                            (event.key === 'ArrowRight' ? 1 : -1) +
                            enabled.length) %
                            enabled.length
                    ];
        if (!target) return;

        document.getElementById(`${testIdPrefix}-tab-${target.key}`)?.focus();
        onTab(target.key);
    };

    return (
        <div
            role="tablist"
            aria-label={ariaLabel}
            className="flex flex-wrap items-center gap-1"
        >
            {tabs.map((tab, index) => {
                const isActive = tab.key === activeTab;
                const tone = TONE_CYCLE[index % TONE_CYCLE.length];
                const Icon = tab.icon;
                const className = cn(
                    'relative inline-flex min-h-10 shrink-0 items-center gap-2 rounded-[9px] px-3 text-[13px] font-semibold transition-colors focus-visible:ring-2 focus-visible:ring-ring/55 focus-visible:outline-none',
                    isActive
                        ? tone.active
                        : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                    tab.disabled && 'cursor-not-allowed opacity-50',
                );
                const inner = (
                    <>
                        <span
                            className={cn(
                                'inline-flex h-[22px] w-[22px] shrink-0 items-center justify-center rounded-md',
                                isActive
                                    ? tone.chip
                                    : 'bg-muted text-muted-foreground',
                            )}
                        >
                            <Icon className="h-3.5 w-3.5" />
                        </span>
                        {tab.label}
                        <CountPill
                            n={tab.count}
                            className={isActive ? tone.badge : undefined}
                        />
                        <WarningPill n={tab.warningCount} label={tab.label} />
                        {isActive ? (
                            <span
                                className={cn(
                                    'absolute inset-x-3.5 bottom-0 h-0.5 rounded',
                                    tone.bar,
                                )}
                                aria-hidden="true"
                            />
                        ) : null}
                    </>
                );
                const accessibilityProps: TierTwoTabAccessibilityProps = {
                    id: `${testIdPrefix}-tab-${tab.key}`,
                    role: 'tab',
                    'aria-selected': isActive,
                    ...(panelId ? { 'aria-controls': panelId } : {}),
                    tabIndex: isActive ? 0 : -1,
                    onKeyDown: (event) => moveTab(event, index),
                    'data-test': `${testIdPrefix}-tab-${tab.key}`,
                };
                const tabControl = tab.href ? (
                    renderLink(tab, className, inner, accessibilityProps)
                ) : (
                    <button
                        type="button"
                        onClick={() => onTab(tab.key)}
                        {...accessibilityProps}
                        disabled={tab.disabled}
                        className={className}
                    >
                        {inner}
                    </button>
                );

                return <Fragment key={tab.key}>{tabControl}</Fragment>;
            })}
        </div>
    );
}

/** Search palette for tabs across every visible group. */
export function TabSearchPalette({
    open,
    onClose,
    groups,
    onTab,
    testIdPrefix = 'client',
    searchLabel = 'Find a profile section',
}: {
    open: boolean;
    onClose: () => void;
    groups: GroupedProfileNavGroup[];
    onTab: (key: string) => void;
    testIdPrefix?: string;
    searchLabel?: string;
}) {
    const [query, setQuery] = useState('');
    const inputRef = useRef<HTMLInputElement>(null);
    const flat = useMemo(
        () =>
            groups.flatMap((group) =>
                group.tabs
                    .filter((tab) => !tab.disabled)
                    .map((tab) => ({
                        ...tab,
                        groupLabel: group.label,
                    })),
            ),
        [groups],
    );

    useEffect(() => {
        if (open) setQuery('');
    }, [open]);

    const normalizedQuery = query.trim().toLowerCase();
    const results = normalizedQuery
        ? flat.filter(
              (tab) =>
                  tab.label.toLowerCase().includes(normalizedQuery) ||
                  tab.groupLabel.toLowerCase().includes(normalizedQuery),
          )
        : flat;

    return (
        <Dialog
            open={open}
            onOpenChange={(nextOpen) => {
                if (!nextOpen) onClose();
            }}
        >
            <DialogContent
                className="gap-0 overflow-hidden rounded-2xl p-0 sm:max-w-lg"
                aria-describedby={undefined}
                data-test={`${testIdPrefix}-search-palette`}
                onOpenAutoFocus={(event) => {
                    event.preventDefault();
                    inputRef.current?.focus();
                }}
            >
                <DialogTitle className="sr-only">Jump to a section</DialogTitle>
                <div className="flex items-center gap-2 border-b border-border pr-12 pl-4">
                    <Search className="h-[18px] w-[18px] text-muted-foreground" />
                    <input
                        ref={inputRef}
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        aria-label={searchLabel}
                        placeholder="Jump to a section…"
                        className="h-12 w-full bg-transparent text-sm outline-none focus-visible:ring-0"
                    />
                </div>
                <div className="max-h-[50vh] overflow-y-auto p-2">
                    {results.length === 0 ? (
                        <p className="px-3 py-6 text-center text-sm text-muted-foreground">
                            Nothing matches “{query}”.
                        </p>
                    ) : (
                        results.map((tab) => {
                            const Icon = tab.icon;
                            return (
                                <button
                                    key={tab.key}
                                    type="button"
                                    aria-label={`${tab.label}, ${tab.groupLabel}`}
                                    onClick={() => {
                                        onTab(tab.key);
                                        onClose();
                                    }}
                                    className="flex min-h-11 w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                        <Icon className="h-4 w-4" />
                                    </span>
                                    <span className="text-sm font-medium">
                                        {tab.label}
                                    </span>
                                    <span className="ml-auto text-xs text-muted-foreground">
                                        {tab.groupLabel}
                                    </span>
                                    <CountPill n={tab.count} />
                                    <WarningPill
                                        n={tab.warningCount}
                                        label={tab.label}
                                    />
                                </button>
                            );
                        })
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}
