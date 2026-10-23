import { router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface SharedPaginationProps {
    lastPage?: number;
    className?: string;
    preserveState?: boolean;
    preserveScroll?: boolean;
    /** Same-page partial visits preserve an owning settings draft. */
    only?: string[];
    /**
     * A list loaded as JSON (not a page visit) pages through this with the
     * link's `?page=N` instead of an Inertia visit.
     */
    onPage?: (page: number) => void;
    ariaLabel?: string;
}

type LaravelPaginationProps = SharedPaginationProps & (
    | { links: PaginationLink[]; navigation?: never; cursor?: never }
    | {
        links?: never;
        cursor?: never;
        /** Uses only server-supplied adjacent pages; the owner handles draft-safe visits. */
        navigation: {
            currentPage: number;
            previousPage: number | null;
            nextPage: number | null;
            pending?: boolean;
            onPageChange: (page: number) => void;
        };
    }
    | {
        links?: never;
        navigation?: never;
        /** Cursor lists supply no trustworthy page number or total. */
        cursor: {
            previousHref: string | null; nextHref: string | null;
            pending?: boolean;
            /** Inline lists retain their owning form and use the canonical supplied cursor. */
            onPageChange?: (href: string) => void;
        };
    }
);

function stripHtml(html: string): string {
    return html
        .replace(/&laquo;/g, '')
        .replace(/&raquo;/g, '')
        .replace(/<[^>]*>/g, '')
        .trim();
}

function isNavLabel(label: string): 'prev' | 'next' | false {
    if (label.includes('laquo') || label.includes('Previous')) return 'prev';
    if (label.includes('raquo') || label.includes('Next')) return 'next';
    return false;
}

export function LaravelPagination({
    links,
    lastPage,
    className,
    preserveState = true,
    preserveScroll = false,
    only,
    onPage,
    navigation,
    cursor,
    ariaLabel = 'Pagination',
}: LaravelPaginationProps) {
    if (cursor) {
        if (cursor.previousHref === null && cursor.nextHref === null) return null;
    } else if (navigation) {
        if (navigation.previousPage === null && navigation.nextPage === null) return null;
    } else {
        if (!links || links.length <= 3) return null;
        if (lastPage !== undefined && lastPage <= 1) return null;
    }
    const visitOptions = {
        preserveState,
        ...(only ? { only } : {}),
        ...(preserveScroll ? { preserveScroll: true } : {}),
    };
    const open = (url: string | null) => {
        if (!url) return;
        if (onPage) {
            const page = Number(
                new URL(url, 'https://pagination.invalid').searchParams.get(
                    'page',
                ),
            );
            if (page > 0) onPage(page);
            return;
        }
        router.get(url, {}, visitOptions);
    };
    const controls = cursor
        ? [
            { label: 'Previous', url: cursor.previousHref },
            { label: 'Next', url: cursor.nextHref },
        ].map((link) => ({
            ...link, active: false, disabled: cursor.pending === true || link.url === null,
            visit: () => {
                if (!link.url || cursor.pending) return;
                if (cursor.onPageChange) cursor.onPageChange(link.url);
                else router.get(link.url, {}, visitOptions);
            },
        }))
        : navigation
        ? [
            { label: 'Previous', active: false, page: navigation.previousPage },
            { label: String(navigation.currentPage), active: true, page: null },
            { label: 'Next', active: false, page: navigation.nextPage },
        ].map((link) => ({
            ...link,
            disabled: navigation.pending === true || link.page === null,
            visit: () => {
                if (!navigation.pending && link.page !== null) navigation.onPageChange(link.page);
            },
        }))
        : (links ?? []).map((link) => ({
            ...link,
            disabled: !link.url,
            visit: () => open(link.url),
        }));

    // The buttons keep the compact sm size; frontline-hit gives each a 44 px
    // target, and the 36 px minimum width plus gap-2.5 keep neighbouring
    // targets from overlapping.
    return (
        <nav
            aria-label={ariaLabel}
            className={cn(
                'flex items-center justify-center gap-2.5',
                className,
            )}
        >
            {controls.map((link, i) => {
                const nav = isNavLabel(link.label);

                if (nav === 'prev') {
                    return (
                        <Button
                            key={i}
                            variant="outline"
                            size="sm"
                            disabled={link.disabled}
                            onClick={link.visit}
                            aria-label="Previous page"
                            className="frontline-hit min-w-[36px]"
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
                            disabled={link.disabled}
                            onClick={link.visit}
                            aria-label="Next page"
                            className="frontline-hit min-w-[36px]"
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
                        disabled={link.disabled}
                        onClick={link.visit}
                        aria-current={link.active ? 'page' : undefined}
                        className="frontline-hit min-w-[36px]"
                    >
                        {stripHtml(link.label)}
                    </Button>
                );
            })}
        </nav>
    );
}
