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

function NavItemComponent({ item, isActive }: { item: NavItem; isActive: boolean }) {

    return (
        <SidebarMenuItem>
            <SidebarMenuButton
                asChild
                isActive={isActive}
                tooltip={{ children: item.title }}
            >
                <Link href={item.href} prefetch preserveScroll>
                    {item.icon && <item.icon />}
                    <span>{item.title}</span>
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

    return (
        <SidebarGroup className="px-2 py-0">
            <Collapsible open={isOpen} onOpenChange={setIsOpen}>
                <CollapsibleTrigger asChild>
                    <SidebarGroupLabel className="group/label cursor-pointer">
                        <span className="flex-1">{group.label}</span>
                        <ChevronDown
                            className={`ml-auto h-4 w-4 transition-transform duration-200 ${
                                isOpen ? 'rotate-180' : ''
                            }`}
                        />
                    </SidebarGroupLabel>
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <SidebarMenu>
                        {group.items.map((item, index) => (
                            <NavItemComponent
                                key={`${group.id}:${index}:${resolveUrl(item.href)}`}
                                item={item}
                                isActive={index === activeIndex}
                            />
                        ))}
                    </SidebarMenu>
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
