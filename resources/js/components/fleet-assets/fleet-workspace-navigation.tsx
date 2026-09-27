import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    fleetNavigationPath,
    fleetWorkspaceForUrl,
    visibleFleetGroups,
    type FleetNavigationPermissions,
} from '@/lib/fleet-navigation';
import { Link, usePage } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';

/** Fleet-only contextual links in the shell breadcrumb strip; page headers and view tabs stay owned by their pages. */
export function FleetWorkspaceNavigation() {
    const page = usePage<{ auth?: { can?: FleetNavigationPermissions } }>();
    const match = fleetWorkspaceForUrl(page.url);
    if (!match) return null;
    const groups = visibleFleetGroups(match.workspace, page.props.auth?.can);
    if (!groups.length) return null;
    const hrefFor = (href: string) =>
        fleetNavigationPath(page.url) === href ? page.url : href;

    return (
        <nav
            aria-label={`${match.workspace.label} pages`}
            className="flex flex-wrap items-center gap-1"
        >
            {groups.map((group) => {
                if (group.links.length === 1) {
                    const item = group.links[0];
                    return (
                        <Button
                            key={group.label}
                            variant="ghost"
                            size="sm"
                            asChild
                        >
                            <Link
                                href={hrefFor(item.href)}
                                aria-current={
                                    item.href === match.item.href
                                        ? 'page'
                                        : undefined
                                }
                            >
                                {item.label}
                            </Link>
                        </Button>
                    );
                }
                const active = group.links.some(
                    (item) => item.href === match.item.href,
                );
                return (
                    <DropdownMenu key={group.label}>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="ghost"
                                size="sm"
                                aria-label={`${group.label} pages`}
                                className={
                                    active
                                        ? 'bg-accent text-accent-foreground'
                                        : undefined
                                }
                            >
                                {group.label}
                                <ChevronDown
                                    className="size-3.5"
                                    aria-hidden="true"
                                />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            align="start"
                            aria-label={`${group.label} pages`}
                        >
                            {group.links.map((item) => (
                                <DropdownMenuItem key={item.href} asChild>
                                    <Link
                                        href={hrefFor(item.href)}
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
                        </DropdownMenuContent>
                    </DropdownMenu>
                );
            })}
        </nav>
    );
}
