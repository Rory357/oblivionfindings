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
import { useState, useEffect } from 'react';

interface NavMainProps {
    groups: NavGroup[];
}

function NavItemComponent({ item }: { item: NavItem }) {
    const page = usePage();
    const isActive = page.url.startsWith(resolveUrl(item.href));

    return (
        <SidebarMenuItem>
            <SidebarMenuButton
                asChild
                isActive={isActive}
                tooltip={{ children: item.title }}
            >
                <Link href={item.href} prefetch>
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

    // Auto-expand group if it contains active item
    useEffect(() => {
        const hasActiveItem = group.items.some((item) =>
            page.url.startsWith(resolveUrl(item.href))
        );
        if (hasActiveItem) {
            setIsOpen(true);
        }
    }, [page.url, group.items]);

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
                        {group.items.map((item) => (
                            <NavItemComponent key={item.href} item={item} />
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
