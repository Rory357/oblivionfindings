import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Link, router, usePage } from '@inertiajs/react';
import { Search, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import './queue.css';

export type QueueFilters = Record<string, string | number | undefined>;
export type PageLinks = Array<{
    url: string | null;
    label: string;
    active: boolean;
}>;

export function useQueueFilters(path: string, filters: QueueFilters) {
    const { url } = usePage();
    const [search, setSearch] = useState(String(filters.search ?? ''));
    const latest = useRef(filters);
    useEffect(() => {
        latest.current = filters;
    }, [filters]);
    useEffect(() => {
        const main = document.querySelector('main');
        const previous = main?.getAttribute('tabindex');
        main?.setAttribute('tabindex', '-1');
        main?.focus({ preventScroll: true });
        return () => {
            if (previous == null) main?.removeAttribute('tabindex');
            else main?.setAttribute('tabindex', previous);
        };
    }, [path]);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    useEffect(
        () => () => {
            if (timer.current) clearTimeout(timer.current);
        },
        [],
    );
    useEffect(() => {
        setSearch(String(filters.search ?? ''));
    }, [filters.search]);
    const patch = (values: QueueFilters) => {
        if (timer.current) clearTimeout(timer.current);
        const params = new URLSearchParams();
        Object.entries({ ...latest.current, ...values }).forEach(
            ([key, value]) => {
                if (value !== undefined && value !== '')
                    params.set(key, String(value));
            },
        );
        params.delete('page');
        params.delete('cr_page');
        router.get(
            `${path}?${params}`,
            {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: Object.keys(values).every((key) => key === 'search'),
            },
        );
    };
    const onSearch = (value: string) => {
        setSearch(value);
        if (timer.current) clearTimeout(timer.current);
        timer.current = setTimeout(() => patch({ search: value }), 300);
    };
    return { url, search, onSearch, patch };
}

export function QueueCriteria({
    criteria,
    onRemove,
    scope,
}: {
    criteria: Array<{ key: string; label: string }>;
    onRemove: (key: string) => void;
    scope: string;
}) {
    return (
        <div className="text-caption flex flex-wrap items-center gap-2">
            <span>{scope}</span>
            {criteria.map((item) => (
                <Button
                    key={item.key}
                    variant="outline"
                    size="sm"
                    onClick={() => onRemove(item.key)}
                    aria-label={`Remove ${item.label}`}
                >
                    {item.label}
                    <X className="size-3" />
                </Button>
            ))}
        </div>
    );
}

export function QueueEmpty({
    title = 'No matching records',
    onClear,
    hasCriteria,
}: {
    title?: string;
    onClear: () => void;
    hasCriteria: boolean;
}) {
    return (
        <Card>
            <CardContent className="flex flex-col items-center gap-3 py-12 text-center">
                <Search className="size-8 text-muted-foreground" />
                <h2 className="text-section-title">{title}</h2>
                <p className="text-subtle">
                    Try another view within the selected site and resource
                    scope.
                </p>
                <Button variant="outline" onClick={onClear}>
                    {hasCriteria ? 'Clear list filters' : 'Show all records'}
                </Button>
            </CardContent>
        </Card>
    );
}

export function QueuePagination({ links }: { links: PageLinks }) {
    return (
        <nav
            aria-label="Result pages"
            className="flex flex-wrap items-center justify-end gap-1"
        >
            {links.map((link, index) => {
                const label = link.label.replace(/&laquo;|&raquo;/g, '').trim();
                return link.url ? (
                    <Button
                        key={index}
                        variant={link.active ? 'default' : 'outline'}
                        size="sm"
                        asChild
                    >
                        <Link
                            href={link.url}
                            preserveState
                            preserveScroll
                            aria-current={link.active ? 'page' : undefined}
                        >
                            {label}
                        </Link>
                    </Button>
                ) : (
                    <Button key={index} size="sm" variant="ghost" disabled>
                        {label}
                    </Button>
                );
            })}
        </nav>
    );
}
