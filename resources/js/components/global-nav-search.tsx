import { buildNavSearchCatalog } from '@/components/app-sidebar';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { router, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

export default function GlobalNavSearch() {
    const page = usePage<any>();
    const auth = page.props?.auth;
    const labels = page.props?.labels;
    const role = auth?.user?.role as string | null | undefined;
    const can = auth?.can;
    const portalClients = auth?.portalClients;
    const unreadMessageCount = auth?.unreadMessageCount;

    const [open, setOpen] = useState(false);

    const catalog = useMemo(
        () =>
            buildNavSearchCatalog({
                role,
                can,
                labels,
                portalClients,
                unreadMessageCount,
            }),
        [role, can, labels, portalClients, unreadMessageCount],
    );

    const grouped = useMemo(() => {
        const map = new Map<string, typeof catalog>();
        for (const item of catalog) {
            const arr = map.get(item.section) ?? [];
            arr.push(item);
            map.set(item.section, arr);
        }
        return Array.from(map.entries());
    }, [catalog]);

    // ⌘K / Ctrl+K global shortcut
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                setOpen((o) => !o);
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    const select = (href: string) => {
        setOpen(false);
        router.visit(href);
    };

    const isMac =
        typeof navigator !== 'undefined' &&
        /mac|iphone|ipad|ipod/i.test(navigator.platform);

    return (
        <>
            {/* Desktop trigger */}
            <Button
                type="button"
                variant="outline"
                className="mr-2 hidden h-9 w-[240px] justify-start gap-2 px-3 text-sm text-slate-500 lg:flex"
                onClick={() => setOpen(true)}
            >
                <Search className="h-4 w-4 opacity-70" />
                <span>Search modules…</span>
                <kbd className="pointer-events-none ml-auto hidden items-center gap-0.5 rounded border bg-muted px-1.5 py-0.5 font-mono text-[10px] font-medium text-muted-foreground sm:inline-flex">
                    <span className="text-xs">{isMac ? '⌘' : 'Ctrl'}</span>K
                </kbd>
            </Button>

            {/* Mobile trigger */}
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="h-9 w-9 lg:hidden"
                onClick={() => setOpen(true)}
                title="Search modules"
            >
                <Search className="!size-5 opacity-80" />
            </Button>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="overflow-hidden p-0 sm:max-w-xl">
                    <DialogTitle className="sr-only">
                        Search modules and pages
                    </DialogTitle>
                    <Command>
                        <CommandInput placeholder="Search modules, pages, reports…" />
                        <CommandList>
                            <CommandEmpty>No matches found.</CommandEmpty>
                            {grouped.map(([section, items]) => (
                                <CommandGroup key={section} heading={section}>
                                    {items.map((item) => {
                                        const Icon = item.icon;
                                        const searchValue = [
                                            item.section,
                                            item.group ?? '',
                                            item.label,
                                        ]
                                            .filter(Boolean)
                                            .join(' ');
                                        return (
                                            <CommandItem
                                                key={item.id}
                                                value={searchValue}
                                                onSelect={() =>
                                                    select(item.href)
                                                }
                                            >
                                                {Icon ? (
                                                    <Icon className="mr-2 h-4 w-4 opacity-70" />
                                                ) : null}
                                                <div className="flex min-w-0 flex-1 flex-col">
                                                    <span className="truncate">
                                                        {item.label}
                                                    </span>
                                                    {item.group && (
                                                        <span className="truncate text-xs text-muted-foreground">
                                                            {item.section} ›{' '}
                                                            {item.group}
                                                        </span>
                                                    )}
                                                </div>
                                            </CommandItem>
                                        );
                                    })}
                                </CommandGroup>
                            ))}
                        </CommandList>
                    </Command>
                </DialogContent>
            </Dialog>
        </>
    );
}
