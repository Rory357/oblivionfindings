import { router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface LaravelPaginationProps {
    links: PaginationLink[];
    lastPage?: number;
    className?: string;
    preserveState?: boolean;
}

function stripHtml(html: string): string {
    return html.replace(/&laquo;/g, '').replace(/&raquo;/g, '').replace(/<[^>]*>/g, '').trim();
}

function isNavLabel(label: string): 'prev' | 'next' | false {
    if (label.includes('laquo') || label.includes('Previous')) return 'prev';
    if (label.includes('raquo') || label.includes('Next')) return 'next';
    return false;
}

export function LaravelPagination({ links, lastPage, className, preserveState = true }: LaravelPaginationProps) {
    if (!links || links.length <= 3) return null;
    if (lastPage !== undefined && lastPage <= 1) return null;

    return (
        <nav aria-label="Pagination" className={cn('flex items-center justify-center gap-1', className)}>
            {links.map((link, i) => {
                const nav = isNavLabel(link.label);

                if (nav === 'prev') {
                    return (
                        <Button
                            key={i}
                            variant="outline"
                            size="sm"
                            disabled={!link.url}
                            onClick={() => link.url && router.get(link.url, {}, { preserveState })}
                            aria-label="Previous page"
                        >
                            <ChevronLeft className="h-4 w-4" />
                        </Button>
                    );
                }

                if (nav === 'next') {
                    return (
                        <Button
                            key={i}
                            variant="outline"
                            size="sm"
                            disabled={!link.url}
                            onClick={() => link.url && router.get(link.url, {}, { preserveState })}
                            aria-label="Next page"
                        >
                            <ChevronRight className="h-4 w-4" />
                        </Button>
                    );
                }

                return (
                    <Button
                        key={i}
                        variant={link.active ? 'default' : 'outline'}
                        size="sm"
                        disabled={!link.url}
                        onClick={() => link.url && router.get(link.url, {}, { preserveState })}
                        aria-current={link.active ? 'page' : undefined}
                        className="min-w-[36px]"
                    >
                        {stripHtml(link.label)}
                    </Button>
                );
            })}
        </nav>
    );
}
