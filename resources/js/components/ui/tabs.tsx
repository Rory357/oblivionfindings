import { Tab } from '@headlessui/react';
import { cn } from '@/lib/utils';
import { ReactNode, useCallback, useEffect, useRef, useState } from 'react';
import { ChevronLeft, ChevronRight, X } from 'lucide-react';
import { Button } from './button';

export type TabItem = {
    key: string;
    label: ReactNode;
    content?: ReactNode;
    closable?: boolean;
    icon?: ReactNode;
};

type TabsProps = {
    tabs: TabItem[];
    listClassName?: string;
    panelClassName?: string;
    scrollable?: boolean;
    persistKey?: string;
} & (
    | {
          // Uncontrolled mode
          defaultIndex?: number;
          value?: never;
          onValueChange?: never;
          onClose?: never;
      }
    | {
          // Controlled mode
          value: string;
          onValueChange: (value: string) => void;
          onClose?: (key: string) => void;
          defaultIndex?: never;
      }
);

// Local storage helper for persistence
function usePersistentTab(persistKey: string | undefined, tabs: TabItem[]) {
    const [mounted, setMounted] = useState(false);

    useEffect(() => {
        setMounted(true);
    }, []);

    const getInitialIndex = useCallback(() => {
        if (!persistKey || typeof window === 'undefined') return 0;
        const saved = localStorage.getItem(`tabs:${persistKey}`);
        if (saved) {
            const index = tabs.findIndex((t) => t.key === saved);
            return index >= 0 ? index : 0;
        }
        return 0;
    }, [persistKey, tabs]);

    const saveTab = useCallback(
        (key: string) => {
            if (persistKey && typeof window !== 'undefined') {
                localStorage.setItem(`tabs:${persistKey}`, key);
            }
        },
        [persistKey]
    );

    return { mounted, getInitialIndex, saveTab };
}

export function Tabs({
    tabs,
    defaultIndex,
    value,
    onValueChange,
    onClose,
    listClassName,
    panelClassName,
    scrollable = false,
    persistKey,
}: TabsProps) {
    const safeTabs = tabs ?? [];
    const scrollRef = useRef<HTMLDivElement>(null);
    const [canScrollLeft, setCanScrollLeft] = useState(false);
    const [canScrollRight, setCanScrollRight] = useState(false);

    const { mounted, getInitialIndex, saveTab } = usePersistentTab(
        persistKey,
        safeTabs
    );

    // Check scrollability
    const checkScroll = useCallback(() => {
        const el = scrollRef.current;
        if (!el) return;
        setCanScrollLeft(el.scrollLeft > 0);
        setCanScrollRight(el.scrollLeft < el.scrollWidth - el.clientWidth - 1);
    }, []);

    useEffect(() => {
        checkScroll();
        const el = scrollRef.current;
        if (el) {
            el.addEventListener('scroll', checkScroll);
            return () => el.removeEventListener('scroll', checkScroll);
        }
    }, [checkScroll, tabs]);

    const scroll = (direction: 'left' | 'right') => {
        const el = scrollRef.current;
        if (!el) return;
        const scrollAmount = 200;
        el.scrollBy({
            left: direction === 'left' ? -scrollAmount : scrollAmount,
            behavior: 'smooth',
        });
    };

    // Handle tab change with persistence
    const handleChange = (index: number) => {
        const key = safeTabs[index]?.key;
        if (key) {
            saveTab(key);
            onValueChange?.(key);
        }
    };

    // Get selected index for controlled mode
    const selectedIndex =
        value !== undefined ? safeTabs.findIndex((t) => t.key === value) : undefined;

    // Don't render until mounted to prevent hydration mismatch with persisted tabs
    if (persistKey && !mounted) {
        return (
            <div className="animate-pulse">
                <div className="h-10 rounded-xl bg-muted" />
            </div>
        );
    }

    const initialIndex = persistKey ? getInitialIndex() : defaultIndex || 0;

    const tabList = (
        <Tab.List
            className={cn(
                'inline-flex w-full items-center gap-1 rounded-xl border bg-background p-1',
                scrollable ? 'flex-nowrap overflow-x-auto scrollbar-hide' : 'flex-wrap',
                listClassName
            )}
            ref={scrollRef}
        >
            {safeTabs.map((t) => (
                <Tab
                    key={t.key}
                    className={({ selected }) =>
                        cn(
                            'group relative flex shrink-0 items-center gap-2 rounded-lg px-3 py-1.5 text-sm outline-none transition-all',
                            'hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring',
                            selected
                                ? 'bg-muted font-medium text-foreground shadow-sm'
                                : 'text-muted-foreground',
                            t.closable && 'pr-8'
                        )
                    }
                >
                    {t.icon && <span className="shrink-0">{t.icon}</span>}
                    <span className="truncate">{t.label}</span>
                    {t.closable && onClose && (
                        <button
                            onClick={(e) => {
                                e.stopPropagation();
                                onClose(t.key);
                            }}
                            className="absolute right-1 rounded p-0.5 opacity-0 transition-opacity hover:bg-muted-foreground/20 group-hover:opacity-100"
                            aria-label={`Close ${t.label}`}
                        >
                            <X className="h-3 w-3" />
                        </button>
                    )}
                </Tab>
            ))}
        </Tab.List>
    );

    const TabGroup = (
        <Tab.Group
            selectedIndex={selectedIndex}
            defaultIndex={initialIndex}
            onChange={handleChange}
        >
            <div className="relative">
                {scrollable && canScrollLeft && (
                    <Button
                        variant="ghost"
                        size="icon"
                        className="absolute left-0 top-1/2 z-10 h-7 w-7 -translate-y-1/2 rounded-full bg-background/90 shadow-sm"
                        onClick={() => scroll('left')}
                    >
                        <ChevronLeft className="h-4 w-4" />
                    </Button>
                )}
                {tabList}
                {scrollable && canScrollRight && (
                    <Button
                        variant="ghost"
                        size="icon"
                        className="absolute right-0 top-1/2 z-10 h-7 w-7 -translate-y-1/2 rounded-full bg-background/90 shadow-sm"
                        onClick={() => scroll('right')}
                    >
                        <ChevronRight className="h-4 w-4" />
                    </Button>
                )}
            </div>

            {safeTabs.some((t) => t.content) && (
                <Tab.Panels className={cn('mt-4', panelClassName)}>
                    {safeTabs.map((t) => (
                        <Tab.Panel key={t.key} className="outline-none">
                            {t.content}
                        </Tab.Panel>
                    ))}
                </Tab.Panels>
            )}
        </Tab.Group>
    );

    return TabGroup;
}

// Vertical tabs variant
interface VerticalTabsProps extends Omit<TabsProps, 'scrollable'> {
    sidebarClassName?: string;
    contentClassName?: string;
}

export function VerticalTabs({
    tabs,
    sidebarClassName,
    contentClassName,
    ...props
}: VerticalTabsProps) {
    const safeTabs = tabs ?? [];
    const TabGroup = (
        <Tab.Group defaultIndex={0}>
            <div className="flex gap-6">
                <Tab.List
                    className={cn(
                        'flex w-48 flex-col gap-1 rounded-xl border bg-background p-2',
                        sidebarClassName
                    )}
                >
                    {safeTabs.map((t) => (
                        <Tab
                            key={t.key}
                            className={({ selected }) =>
                                cn(
                                    'flex items-center gap-2 rounded-lg px-3 py-2 text-left text-sm outline-none transition-all',
                                    'hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring',
                                    selected
                                        ? 'bg-muted font-medium text-foreground'
                                        : 'text-muted-foreground'
                                )
                            }
                        >
                            {t.icon && <span>{t.icon}</span>}
                            <span className="truncate">{t.label}</span>
                        </Tab>
                    ))}
                </Tab.List>

                {safeTabs.some((t) => t.content) && (
                    <Tab.Panels className={cn('flex-1', contentClassName)}>
                        {safeTabs.map((t) => (
                            <Tab.Panel key={t.key} className="outline-none">
                                {t.content}
                            </Tab.Panel>
                        ))}
                    </Tab.Panels>
                )}
            </div>
        </Tab.Group>
    );

    return TabGroup;
}

// Shadcn/ui compatible exports for components that use the standard API
import * as TabsPrimitive from '@radix-ui/react-tabs';
import * as React from "react"

const TabsRoot = TabsPrimitive.Root

const TabsList = React.forwardRef<
  React.ElementRef<typeof TabsPrimitive.List>,
  React.ComponentPropsWithoutRef<typeof TabsPrimitive.List>
>(({ className, ...props }, ref) => (
  <TabsPrimitive.List
    ref={ref}
    className={cn(
      "inline-flex h-10 items-center justify-center rounded-md bg-muted p-1 text-muted-foreground",
      className
    )}
    {...props}
  />
))
TabsList.displayName = TabsPrimitive.List.displayName

const TabsTrigger = React.forwardRef<
  React.ElementRef<typeof TabsPrimitive.Trigger>,
  React.ComponentPropsWithoutRef<typeof TabsPrimitive.Trigger>
>(({ className, ...props }, ref) => (
  <TabsPrimitive.Trigger
    ref={ref}
    className={cn(
      "inline-flex items-center justify-center whitespace-nowrap rounded-sm px-3 py-1.5 text-sm font-medium ring-offset-background transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 data-[state=active]:bg-background data-[state=active]:text-foreground data-[state=active]:shadow-sm",
      className
    )}
    {...props}
  />
))
TabsTrigger.displayName = TabsPrimitive.Trigger.displayName

const TabsContent = React.forwardRef<
  React.ElementRef<typeof TabsPrimitive.Content>,
  React.ComponentPropsWithoutRef<typeof TabsPrimitive.Content>
>(({ className, ...props }, ref) => (
  <TabsPrimitive.Content
    ref={ref}
    className={cn(
      "mt-2 ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2",
      className
    )}
    {...props}
  />
))
TabsContent.displayName = TabsPrimitive.Content.displayName

export { TabsRoot, TabsList, TabsTrigger, TabsContent }
