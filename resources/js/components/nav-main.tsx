import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { resolveUrl } from '@/lib/utils';
import { type NavGroup, type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

interface NavMainProps {
    groups: NavGroup[];
}

function normalizePath(url: string): string {
    const path = url.split('?')[0] ?? '/';
    const trimmed = path.replace(/\/+$/, '');
    return trimmed.length > 0 ? trimmed : '/';
}

function matchScore(currentUrl: string, itemHref: NavItem['href']): number {
    const current = resolveUrl(currentUrl);
    const item = resolveUrl(itemHref);

    const [currentPath, currentQuery = ''] = current.split('?');
    const [itemPath, itemQuery = ''] = item.split('?');

    const normalizedCurrentPath = normalizePath(currentPath);
    const normalizedItemPath = normalizePath(itemPath);

    if (itemQuery.length > 0) {
        return normalizedCurrentPath === normalizedItemPath && currentQuery === itemQuery
            ? 3000 + item.length
            : -1;
    }

    if (normalizedCurrentPath === normalizedItemPath) {
        return 2000 + item.length;
    }

    if (normalizedCurrentPath.startsWith(`${normalizedItemPath}/`)) {
        return 1000 + item.length;
    }

    return -1;
}

function getActiveIndex(currentUrl: string, items: NavItem[]): number {
    let bestIndex = -1;
    let bestScore = -1;

    items.forEach((item, index) => {
        const score = matchScore(currentUrl, item.href);
        if (score > bestScore) {
            bestScore = score;
            bestIndex = index;
        }
    });

    return bestIndex;
}

function NavItemComponent({ 
    item, 
    isActive,
    isNested = false,
    isLast = false,
}: { 
    item: NavItem; 
    isActive: boolean;
    isNested?: boolean;
    isLast?: boolean;
}) {
    return (
        <SidebarMenuItem className={`${isNested ? 'relative' : ''} group/item`}>
            {/* Tree line for nested items */}
            {isNested && (
                <>
                    {/* Horizontal line connecting to parent */}
                    <div className="absolute left-4 top-1/2 w-3 h-px bg-slate-200 dark:bg-slate-700" />
                    {/* Vertical line continuing down (if not last) */}
                    {!isLast && (
                        <div className="absolute left-4 top-1/2 w-px h-[calc(100%+8px)] bg-slate-200 dark:bg-slate-700" />
                    )}
                </>
            )}
            <SidebarMenuButton
                asChild
                isActive={isActive}
                tooltip={{ children: item.title }}
                className={`
                    relative transition-all duration-200
                    ${isNested ? 'pl-8' : ''}
                    ${isActive 
                        ? 'bg-primary/10 text-primary font-medium' 
                        : 'hover:bg-slate-100 dark:hover:bg-slate-800'
                    }
                    rounded-lg my-0.5
                `}
            >
                <Link href={item.href} prefetch className="flex items-center gap-3">
                    {item.icon && (
                        <span className={`
                            flex items-center justify-center w-7 h-7 rounded-md transition-all duration-300
                            ${isActive 
                                ? 'bg-primary text-white shadow-sm' 
                                : 'bg-slate-100 text-slate-500 group-hover:shadow-sm dark:bg-slate-800 dark:text-slate-400 icon-gradient-bg'
                            }
                        `}>
                            <item.icon className="w-4 h-4" />
                        </span>
                    )}
                    <span className="flex-1">{item.title}</span>
                    {/* Active indicator - left border accent */}
                    {isActive && (
                        <span className="absolute left-0 top-1/2 -translate-y-1/2 w-1 h-6 bg-primary rounded-r-full" />
                    )}
                </Link>
            </SidebarMenuButton>
        </SidebarMenuItem>
    );
}

function NavGroupComponent({
    group,
    defaultOpen = true,
}: {
    group: NavGroup;
    defaultOpen?: boolean;
}) {
    const [isOpen, setIsOpen] = useState(defaultOpen);
    const page = usePage();
    const activeIndex = useMemo(() => getActiveIndex(page.url, group.items), [page.url, group.items]);

    // Auto-expand group if it contains active item
    useEffect(() => {
        const hasActiveItem = activeIndex >= 0;
        if (hasActiveItem) {
            setIsOpen(true);
        }
    }, [activeIndex]);

    // Check if any items have nested structure (indicated by title containing "/")
    const hasNestedItems = group.items.some(item => item.title.includes(' > '));

    return (
        <SidebarGroup className="px-2 py-0">
            <Collapsible open={isOpen} onOpenChange={setIsOpen}>
                <CollapsibleTrigger asChild>
                    <SidebarGroupLabel className="group/label cursor-pointer">
                        {/* Group header accent - left border */}
                        <span className="absolute left-0 top-1/2 -translate-y-1/2 w-0.5 h-4 bg-gradient-to-b from-primary/60 to-primary/20 rounded-r-full opacity-0 group-hover/label:opacity-100 transition-opacity duration-200" />
                        <span className="flex-1 flex items-center gap-2">
                            <span className={`
                                text-xs font-semibold uppercase tracking-wider
                                ${isOpen ? 'text-slate-700 dark:text-slate-300' : 'text-slate-400 dark:text-slate-500'}
                            `}>
                                {group.label}
                            </span>
                        </span>
                        <ChevronDown
                            className={`ml-auto h-4 w-4 transition-all duration-200 ${
                                isOpen 
                                    ? 'rotate-180 text-slate-600 dark:text-slate-400' 
                                    : 'text-slate-400 dark:text-slate-500 group-hover/label:text-slate-600'
                            }`}
                        />
                    </SidebarGroupLabel>
                </CollapsibleTrigger>
                <CollapsibleContent>
                    {/* Subtle background tint for group content */}
                    <div className={`
                        relative ml-1 pl-2 py-1 rounded-lg
                        ${hasNestedItems ? '' : 'border-l border-slate-100 dark:border-slate-800'}
                    `}>
                        <SidebarMenu className="gap-0.5">
                            {group.items.map((item, index) => {
                                // Check if this item should be nested (contains " > " in title)
                                const isNested = item.title.includes(' > ');
                                const cleanTitle = isNested ? item.title.split(' > ').pop() : item.title;
                                const cleanItem = { ...item, title: cleanTitle || item.title };
                                
                                return (
                                    <NavItemComponent
                                        key={`${group.id}:${index}:${resolveUrl(item.href)}`}
                                        item={cleanItem}
                                        isActive={index === activeIndex}
                                        isNested={isNested}
                                        isLast={index === group.items.length - 1 || (index < group.items.length - 1 && !group.items[index + 1]?.title.includes(' > '))}
                                    />
                                );
                            })}
                        </SidebarMenu>
                    </div>
                </CollapsibleContent>
            </Collapsible>
        </SidebarGroup>
    );
}

export function NavMain({ groups }: NavMainProps) {
    return (
        <div className="flex flex-col gap-2">
            {groups.map((group) => (
                <NavGroupComponent
                    key={group.id}
                    group={group}
                    defaultOpen={group.id === 'main'}
                />
            ))}
        </div>
    );
}

export default NavMain;
