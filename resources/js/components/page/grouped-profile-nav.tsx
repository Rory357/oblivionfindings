/* eslint-disable no-restricted-syntax -- Shared two-tier navigation uses native
 * controls for accessible tab, group, search, and pin interactions. */
import { cn } from '@/lib/utils';
import { Pin, PinOff, Search } from 'lucide-react';
import {
    useEffect,
    useMemo,
    useRef,
    useState,
    type ComponentType,
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

function CountPill({ n, active }: { n?: number; active?: boolean }) {
    if (!n) return null;

    return (
        <span
            className={cn(
                'rounded-full px-1.5 py-0.5 text-[10px] leading-none font-bold',
                active
                    ? 'bg-primary/15 text-primary'
                    : 'bg-muted text-muted-foreground',
            )}
        >
            {n}
        </span>
    );
}

function WarningPill({
    n,
    label,
    onHero = false,
}: {
    n?: number;
    label: string;
    onHero?: boolean;
}) {
    if (!n) return null;

    return (
        <span
            aria-label={`${label} has ${n} ${n === 1 ? 'item' : 'items'} needing attention`}
            className={cn(
                'rounded-full px-1.5 py-0.5 text-[10px] leading-none font-bold',
                onHero
                    ? 'bg-warning/20 text-primary-foreground'
                    : 'bg-warning/15 text-warning-foreground',
            )}
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
}: {
    groups: GroupedProfileNavGroup[];
    openGroup: string;
    activeTab: string;
    onOpenGroup: (key: string, tabKey: string) => void;
    onSearch: () => void;
    testIdPrefix?: string;
}) {
    const rememberedTabs = useRef<Record<string, string>>({});

    useEffect(() => {
        const activeGroup = groups.find((group) =>
            group.tabs.some((tab) => tab.key === activeTab),
        );
        if (activeGroup) {
            rememberedTabs.current[activeGroup.key] = activeTab;
        }
    }, [activeTab, groups]);

    return (
        <div className="scrollbar-none flex items-center gap-1.5 overflow-x-auto py-2.5">
            {groups.map((group) => {
                const isOpen = group.key === openGroup;
                const hasActive = group.tabs.some(
                    (tab) => tab.key === activeTab,
                );
                const warningCount = group.tabs.reduce(
                    (total, tab) => total + (tab.warningCount ?? 0),
                    0,
                );
                const Icon = group.icon;

                return (
                    <button
                        key={group.key}
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
                        aria-pressed={isOpen}
                        data-test={`${testIdPrefix}-group-${group.key}`}
                        className={cn(
                            'inline-flex min-h-11 shrink-0 items-center gap-1.5 rounded-full px-3.5 py-1.5 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-primary-foreground focus-visible:ring-offset-2 focus-visible:ring-offset-primary focus-visible:outline-none',
                            isOpen
                                ? 'bg-primary-foreground text-primary shadow-sm'
                                : hasActive
                                  ? 'bg-primary-foreground/20 text-primary-foreground'
                                  : 'text-primary-foreground/70 hover:bg-primary-foreground/10 hover:text-primary-foreground',
                        )}
                    >
                        <Icon className="h-[15px] w-[15px]" />
                        {group.label}
                        <WarningPill
                            n={warningCount}
                            label={`${group.label} group`}
                            onHero
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
                className="ml-auto inline-flex min-h-11 shrink-0 items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-medium text-primary-foreground/70 transition-colors hover:bg-primary-foreground/10 hover:text-primary-foreground focus-visible:ring-2 focus-visible:ring-primary-foreground focus-visible:outline-none"
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

/** Tier-2 underline tabs for the open group. */
export function TierTwoTabs({
    tabs,
    activeTab,
    onTab,
    renderLink,
    testIdPrefix = 'client',
    pinnedTabs = [],
    onPinnedTabsChange,
}: {
    tabs: GroupedProfileNavTab[];
    activeTab: string;
    onTab: (key: string) => void;
    renderLink: (
        tab: GroupedProfileNavTab,
        className: string,
        inner: ReactNode,
    ) => ReactNode;
    testIdPrefix?: string;
    pinnedTabs?: string[];
    onPinnedTabsChange?: (tabs: string[]) => void;
}) {
    return (
        <div className="sticky top-0 z-20 -mx-4 border-b border-border bg-background/85 px-4 backdrop-blur md:-mx-6 md:px-6">
            <div className="scrollbar-none flex items-center gap-0.5 overflow-x-auto">
                {tabs.map((tab) => {
                    const isActive = tab.key === activeTab;
                    const isPinned = pinnedTabs.includes(tab.key);
                    const Icon = tab.icon;
                    const className = cn(
                        'inline-flex min-h-11 shrink-0 items-center gap-1.5 border-b-2 px-3 py-2.5 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                        isActive
                            ? 'border-primary text-primary'
                            : 'border-transparent text-muted-foreground hover:text-foreground',
                        tab.disabled && 'cursor-not-allowed opacity-50',
                    );
                    const inner = (
                        <>
                            <Icon className="h-[15px] w-[15px]" />
                            {tab.label}
                            <CountPill n={tab.count} active={isActive} />
                            <WarningPill
                                n={tab.warningCount}
                                label={tab.label}
                            />
                        </>
                    );
                    const tabControl = tab.href ? (
                        renderLink(tab, className, inner)
                    ) : (
                        <button
                            type="button"
                            onClick={() => onTab(tab.key)}
                            aria-pressed={isActive}
                            disabled={tab.disabled}
                            data-test={`${testIdPrefix}-tab-${tab.key}`}
                            className={className}
                        >
                            {inner}
                        </button>
                    );

                    if (!onPinnedTabsChange) return tabControl;

                    return (
                        <div key={tab.key} className="flex items-center">
                            {tabControl}
                            <button
                                type="button"
                                aria-label={`${isPinned ? 'Unpin' : 'Pin'} ${tab.label}`}
                                onClick={() =>
                                    onPinnedTabsChange(
                                        isPinned
                                            ? pinnedTabs.filter(
                                                  (key) => key !== tab.key,
                                              )
                                            : [...pinnedTabs, tab.key],
                                    )
                                }
                                className="inline-flex h-11 w-8 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                {isPinned ? (
                                    <PinOff className="h-3.5 w-3.5" />
                                ) : (
                                    <Pin className="h-3.5 w-3.5" />
                                )}
                            </button>
                        </div>
                    );
                })}
            </div>
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
}: {
    open: boolean;
    onClose: () => void;
    groups: GroupedProfileNavGroup[];
    onTab: (key: string) => void;
    testIdPrefix?: string;
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
        if (!open) return;

        setQuery('');
        const timeout = window.setTimeout(() => inputRef.current?.focus(), 30);
        return () => window.clearTimeout(timeout);
    }, [open]);

    useEffect(() => {
        if (!open) return;

        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') onClose();
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [open, onClose]);

    if (!open) return null;

    const normalizedQuery = query.trim().toLowerCase();
    const results = normalizedQuery
        ? flat.filter(
              (tab) =>
                  tab.label.toLowerCase().includes(normalizedQuery) ||
                  tab.groupLabel.toLowerCase().includes(normalizedQuery),
          )
        : flat;

    return (
        <div
            className="fixed inset-0 z-50 flex items-start justify-center bg-black/40 px-4 pt-[12vh]"
            onMouseDown={onClose}
            role="dialog"
            aria-modal="true"
            aria-label="Jump to a section"
            data-test={`${testIdPrefix}-search-palette`}
        >
            <div
                className="w-full max-w-lg overflow-hidden rounded-2xl border border-border bg-popover shadow-2xl motion-safe:animate-in motion-safe:duration-200 motion-safe:fade-in-0 motion-safe:slide-in-from-top-2"
                onMouseDown={(event) => event.stopPropagation()}
            >
                <div className="flex items-center gap-2 border-b border-border px-4">
                    <Search className="h-[18px] w-[18px] text-muted-foreground" />
                    <input
                        ref={inputRef}
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Jump to a section…"
                        className="h-12 w-full bg-transparent text-sm outline-none focus-visible:ring-0"
                    />
                    <span className="rounded border border-border px-1.5 py-0.5 text-[10px] text-muted-foreground">
                        Esc
                    </span>
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
            </div>
        </div>
    );
}
