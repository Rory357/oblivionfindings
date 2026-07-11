/* eslint-disable no-restricted-syntax -- Grouped two-tier navigation per the
 * redesign handoff (nav.jsx): group pills live inside the hero banner footer
 * (on-gradient styling), tier-2 renders underline tabs, and the ⌘K palette is
 * a bespoke overlay. Semantic tokens only. */
import { cn } from '@/lib/utils';
import { Search } from 'lucide-react';
import {
    useEffect,
    useMemo,
    useRef,
    useState,
    type ComponentType,
    type ReactNode,
} from 'react';

type IconType = ComponentType<{ className?: string }>;

export type ProfileNavTab = {
    key: string;
    label: string;
    icon: IconType;
    count?: number;
    href?: string;
};

export type ProfileNavGroup = {
    key: string;
    label: string;
    icon: IconType;
    tabs: ProfileNavTab[];
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

/** Tier-1 group pills — rendered inside the hero banner footer (on gradient). */
export function GroupPillRail({
    groups,
    openGroup,
    activeTab,
    onOpenGroup,
    onSearch,
}: {
    groups: ProfileNavGroup[];
    openGroup: string;
    activeTab: string;
    onOpenGroup: (key: string, tabKey: string) => void;
    onSearch: () => void;
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
            {groups.map((g) => {
                const isOpen = g.key === openGroup;
                const hasActive = g.tabs.some((t) => t.key === activeTab);
                const Icon = g.icon;
                return (
                    <button
                        key={g.key}
                        type="button"
                        onClick={() => {
                            const remembered = rememberedTabs.current[g.key];
                            const target = g.tabs.some(
                                (tab) => tab.key === remembered,
                            )
                                ? remembered
                                : g.tabs[0]?.key;
                            if (target) onOpenGroup(g.key, target);
                        }}
                        aria-pressed={isOpen}
                        data-test={`client-group-${g.key}`}
                        className={cn(
                            'inline-flex shrink-0 items-center gap-1.5 rounded-full px-3.5 py-1.5 text-sm font-medium transition-colors',
                            isOpen
                                ? 'bg-primary-foreground text-primary shadow-sm'
                                : hasActive
                                  ? 'bg-primary-foreground/20 text-primary-foreground'
                                  : 'text-primary-foreground/70 hover:bg-primary-foreground/10 hover:text-primary-foreground',
                        )}
                    >
                        <Icon className="h-[15px] w-[15px]" />
                        {g.label}
                    </button>
                );
            })}
            <button
                type="button"
                onClick={onSearch}
                title="Find a section (/)"
                aria-label="Find a section"
                className="ml-auto inline-flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-medium text-primary-foreground/70 transition-colors hover:bg-primary-foreground/10 hover:text-primary-foreground"
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

/** Tier-2 underline tabs for the open group — sticky under the hero. */
export function TierTwoTabs({
    tabs,
    activeTab,
    onTab,
    renderLink,
}: {
    tabs: ProfileNavTab[];
    activeTab: string;
    onTab: (key: string) => void;
    /** Render a navigation tab (with href) as an anchor — keeps Inertia links. */
    renderLink: (
        tab: ProfileNavTab,
        className: string,
        inner: ReactNode,
    ) => ReactNode;
}) {
    return (
        <div className="sticky top-0 z-20 -mx-4 border-b border-border bg-background/85 px-4 backdrop-blur md:-mx-6 md:px-6">
            <div className="scrollbar-none flex items-center gap-0.5 overflow-x-auto">
                {tabs.map((t) => {
                    const isActive = t.key === activeTab;
                    const Icon = t.icon;
                    const className = cn(
                        'inline-flex h-auto shrink-0 items-center gap-1.5 border-b-2 px-3 py-2.5 text-sm font-medium transition-colors',
                        isActive
                            ? 'border-primary text-primary'
                            : 'border-transparent text-muted-foreground hover:text-foreground',
                    );
                    const inner = (
                        <>
                            <Icon className="h-[15px] w-[15px]" />
                            {t.label}
                            <CountPill n={t.count} active={isActive} />
                        </>
                    );
                    if (t.href) {
                        return renderLink(t, className, inner);
                    }
                    return (
                        <button
                            key={t.key}
                            type="button"
                            onClick={() => onTab(t.key)}
                            aria-pressed={isActive}
                            data-test={`client-tab-${t.key}`}
                            className={className}
                        >
                            {inner}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

/** ⌘K / "/" tab search palette — fuzzy-matches all tabs across groups. */
export function TabSearchPalette({
    open,
    onClose,
    groups,
    onTab,
}: {
    open: boolean;
    onClose: () => void;
    groups: ProfileNavGroup[];
    onTab: (key: string) => void;
}) {
    const [query, setQuery] = useState('');
    const inputRef = useRef<HTMLInputElement>(null);

    const flat = useMemo(
        () =>
            groups.flatMap((g) =>
                g.tabs.map((t) => ({ ...t, groupLabel: g.label })),
            ),
        [groups],
    );

    useEffect(() => {
        if (open) {
            setQuery('');
            const t = window.setTimeout(() => inputRef.current?.focus(), 30);
            return () => window.clearTimeout(t);
        }
    }, [open]);

    useEffect(() => {
        if (!open) return;
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [open, onClose]);

    if (!open) return null;

    const q = query.trim().toLowerCase();
    const results = q
        ? flat.filter(
              (t) =>
                  t.label.toLowerCase().includes(q) ||
                  t.groupLabel.toLowerCase().includes(q),
          )
        : flat;

    return (
        <div
            className="fixed inset-0 z-50 flex items-start justify-center bg-black/40 px-4 pt-[12vh]"
            onMouseDown={onClose}
            role="dialog"
            aria-label="Jump to a section"
        >
            <div
                className="w-full max-w-lg overflow-hidden rounded-2xl border border-border bg-popover shadow-2xl motion-safe:animate-in motion-safe:duration-200 motion-safe:fade-in-0 motion-safe:slide-in-from-top-2"
                onMouseDown={(e) => e.stopPropagation()}
            >
                <div className="flex items-center gap-2 border-b border-border px-4">
                    <Search className="h-[18px] w-[18px] text-muted-foreground" />
                    <input
                        ref={inputRef}
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Jump to a section…"
                        className="h-12 w-full bg-transparent text-sm outline-none"
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
                        results.map((t) => {
                            const Icon = t.icon;
                            return (
                                <button
                                    key={t.key}
                                    type="button"
                                    onClick={() => {
                                        onTab(t.key);
                                        onClose();
                                    }}
                                    className="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left hover:bg-accent"
                                >
                                    <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                        <Icon className="h-4 w-4" />
                                    </span>
                                    <span className="text-sm font-medium">
                                        {t.label}
                                    </span>
                                    <span className="ml-auto text-xs text-muted-foreground">
                                        {t.groupLabel}
                                    </span>
                                    <CountPill n={t.count} />
                                </button>
                            );
                        })
                    )}
                </div>
            </div>
        </div>
    );
}
