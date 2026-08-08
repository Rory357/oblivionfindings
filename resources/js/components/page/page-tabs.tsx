import { MoreHorizontal } from 'lucide-react';
import {
    type ComponentType,
    type ReactNode,
    useCallback,
    useEffect,
    useRef,
    useState,
} from 'react';

import { Badge } from '@/components/ui/badge';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    TabsContent,
    TabsList,
    TabsRoot,
    TabsTrigger,
} from '@/components/ui/tabs';
import { cn } from '@/lib/utils';

export type PageTabItem = {
    value: string;
    label: ReactNode;
    icon?: ComponentType<{ className?: string }>;
    /** Inline badge after the label (count, percentage). */
    badge?: ReactNode;
    /** Collapse into the More dropdown below 2xl breakpoint. */
    overflowable?: boolean;
    /** Permission/feature gating — when true the tab is filtered out entirely. */
    hidden?: boolean;
    'data-test'?: string;
};

export interface PageTabsProps {
    value: string;
    onValueChange: (next: string) => void;
    items: PageTabItem[];
    /** TabsContent siblings render here (standard Radix pattern). */
    children?: ReactNode;
    /** Sticky tab strip under the hero. Default off (web-first density). */
    sticky?: boolean;
    /**
     * Render on a dark/gradient background (e.g. inside a PageHero footer).
     * Flips the colour palette to use --primary-foreground tokens.
     */
    onDark?: boolean;
    /** Slightly tighter padding/font for in-card tab strips. */
    dense?: boolean;
    className?: string;
    /** Wrapper class for the tabs list row only. */
    listClassName?: string;
}

const TRIGGER_LIGHT =
    'inline-flex h-auto shrink-0 items-center gap-1.5 rounded-md border-0 border-b-2 border-transparent bg-transparent px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground data-[state=active]:border-primary data-[state=active]:bg-primary/10 data-[state=active]:text-primary data-[state=active]:shadow-none';

const TRIGGER_DARK =
    'inline-flex h-auto shrink-0 items-center gap-1.5 rounded-md border-0 border-b-2 border-transparent bg-transparent px-3 py-2 text-sm font-medium text-primary-foreground/70 transition-colors hover:bg-primary-foreground/10 hover:text-primary-foreground data-[state=active]:border-primary-foreground data-[state=active]:bg-primary-foreground/15 data-[state=active]:text-primary-foreground data-[state=active]:shadow-none';

const TRIGGER_DENSE_OVERRIDE = 'px-2.5 py-1.5 text-[12.5px]';

export function PageTabs({
    value,
    onValueChange,
    items,
    children,
    sticky = false,
    onDark = false,
    dense = false,
    className,
    listClassName,
}: PageTabsProps) {
    const triggerBase = onDark ? TRIGGER_DARK : TRIGGER_LIGHT;
    const fadeFromClass = onDark ? 'from-primary' : 'from-background';
    const fadeFromClassRight = onDark ? 'from-primary' : 'from-background';
    const stickyBg = onDark ? 'bg-primary' : 'bg-background';
    const listBorder = onDark ? 'border-primary-foreground/20' : '';
    const overflowActiveBadge = onDark
        ? 'border-primary-foreground bg-primary-foreground/15 text-primary-foreground'
        : 'border-primary bg-primary/10 text-primary';
    const listRef = useRef<HTMLDivElement | null>(null);
    const [canScrollLeft, setCanScrollLeft] = useState(false);
    const [canScrollRight, setCanScrollRight] = useState(false);

    const updateScrollState = useCallback(() => {
        const el = listRef.current;
        if (!el) return;
        setCanScrollLeft(el.scrollLeft > 0);
        setCanScrollRight(el.scrollLeft + el.clientWidth < el.scrollWidth - 1);
    }, []);

    useEffect(() => {
        const el = listRef.current;
        if (!el) return;
        updateScrollState();
        el.addEventListener('scroll', updateScrollState, { passive: true });
        const ro =
            typeof ResizeObserver !== 'undefined'
                ? new ResizeObserver(updateScrollState)
                : null;
        ro?.observe(el);
        window.addEventListener('resize', updateScrollState);
        return () => {
            el.removeEventListener('scroll', updateScrollState);
            ro?.disconnect();
            window.removeEventListener('resize', updateScrollState);
        };
    }, [updateScrollState, items.length]);

    const visible = items.filter((item) => !item.hidden);
    const overflow = visible.filter((item) => item.overflowable);
    const hasOverflow = overflow.length > 0;
    const activeOverflow = overflow.find((item) => item.value === value);

    return (
        <TabsRoot
            value={value}
            onValueChange={onValueChange}
            className={cn('space-y-4', className)}
        >
            <div
                className={cn(
                    'relative',
                    sticky && 'sticky top-0 z-20',
                    sticky && stickyBg,
                )}
            >
                {canScrollLeft ? (
                    <span
                        className={cn(
                            'pointer-events-none absolute top-0 left-0 z-10 h-full w-6 bg-gradient-to-r to-transparent',
                            fadeFromClass,
                        )}
                    />
                ) : null}
                {canScrollRight ? (
                    <span
                        className={cn(
                            'pointer-events-none absolute top-0 right-0 z-10 h-full w-6 bg-gradient-to-l to-transparent',
                            fadeFromClassRight,
                        )}
                    />
                ) : null}

                <TabsList
                    ref={listRef}
                    className={cn(
                        'scrollbar-pretty flex h-auto w-full justify-start gap-1 overflow-x-auto rounded-none border-b bg-transparent p-0 pb-1',
                        listBorder,
                        listClassName,
                    )}
                >
                    {visible.map((item) => {
                        const Icon = item.icon;
                        const inOverflowGroup = item.overflowable === true;
                        return (
                            <TabsTrigger
                                key={item.value}
                                value={item.value}
                                data-test={item['data-test']}
                                className={cn(
                                    triggerBase,
                                    dense && TRIGGER_DENSE_OVERRIDE,
                                    inOverflowGroup
                                        ? 'hidden 2xl:inline-flex'
                                        : 'inline-flex',
                                )}
                            >
                                {Icon ? <Icon className="h-4 w-4" /> : null}
                                <span>{item.label}</span>
                                {item.badge != null ? (
                                    <span className="ml-1">{item.badge}</span>
                                ) : null}
                            </TabsTrigger>
                        );
                    })}

                    {hasOverflow ? (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <button
                                    type="button"
                                    className={cn(
                                        triggerBase,
                                        dense && TRIGGER_DENSE_OVERRIDE,
                                        'inline-flex 2xl:hidden',
                                        activeOverflow
                                            ? overflowActiveBadge
                                            : '',
                                    )}
                                    aria-label="More tabs"
                                >
                                    <MoreHorizontal className="h-4 w-4" />
                                    <span>More</span>
                                    {activeOverflow ? (
                                        <Badge
                                            variant="outline"
                                            className="ml-1 px-1.5 py-0 text-xs"
                                        >
                                            {activeOverflow.label}
                                        </Badge>
                                    ) : null}
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-56">
                                {overflow.map((item) => {
                                    const Icon = item.icon;
                                    return (
                                        <DropdownMenuItem
                                            key={item.value}
                                            onClick={() =>
                                                onValueChange(item.value)
                                            }
                                            data-test={item['data-test']}
                                            className={cn(
                                                item.value === value &&
                                                    'bg-primary/10 text-primary',
                                            )}
                                        >
                                            {Icon ? (
                                                <Icon className="h-4 w-4" />
                                            ) : null}
                                            <span>{item.label}</span>
                                            {item.badge != null ? (
                                                <span className="ml-auto">
                                                    {item.badge}
                                                </span>
                                            ) : null}
                                        </DropdownMenuItem>
                                    );
                                })}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    ) : null}
                </TabsList>
            </div>

            {children ??
                /*
                 * PageTabs is sometimes used as a pure segmented filter (e.g.
                 * the /my-day resident switcher) with no TabsContent panels of
                 * its own. Radix still wires every trigger's `aria-controls` to
                 * a generated panel id, so the *active* trigger would point at a
                 * non-existent element → axe `aria-valid-attr-value` (critical,
                 * WCAG 4.1.2 Name, Role, Value). Emit an empty, force-mounted
                 * panel per value so every reference resolves; `hidden` keeps
                 * them out of layout and the tab order so the UI stays
                 * panel-less.
                 */
                visible.map((item) => (
                    <TabsContent
                        key={item.value}
                        value={item.value}
                        forceMount
                        className="hidden"
                    />
                ))}
        </TabsRoot>
    );
}

export default PageTabs;
