import { PageHeaderGlassButton } from '@/components/page/page-header';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    fleetWorkspaceForUrl,
    visibleFleetGroups,
    type FleetNavigationPermissions,
} from '@/lib/fleet-navigation';
import { fleetScopeHref } from '@/lib/fleet-queue-context';
import { Link, usePage } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';

/** Contextual destinations belong inside the page hero, not the breadcrumb strip. */
export function FleetPageMenu() {
    const page = usePage<{ auth?: { can?: FleetNavigationPermissions } }>();
    const match = fleetWorkspaceForUrl(page.url);
    if (!match) return null;
    const groups = visibleFleetGroups(match.workspace, page.props.auth?.can);
    if (!groups.length) return null;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <PageHeaderGlassButton
                    icon={ChevronDown}
                    aria-label={`More ${match.workspace.label} pages`}
                    className="max-sm:min-h-11"
                >
                    More pages
                </PageHeaderGlassButton>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="end"
                className="max-h-[min(24rem,var(--radix-dropdown-menu-content-available-height))] overflow-y-auto"
            >
                {groups.map((group, index) => (
                    <DropdownMenuGroup key={group.label}>
                        {index > 0 && <DropdownMenuSeparator />}
                        <DropdownMenuLabel>{group.label}</DropdownMenuLabel>
                        {group.links.map((item) => (
                            <DropdownMenuItem key={item.href} asChild>
                                <Link
                                    href={fleetScopeHref(item.href, page.url)}
                                    aria-current={
                                        item.href === match.item.href
                                            ? 'page'
                                            : undefined
                                    }
                                >
                                    {item.label}
                                </Link>
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuGroup>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
