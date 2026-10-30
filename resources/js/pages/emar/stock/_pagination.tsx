import { ListCaption } from '@/components/lists/list-caption';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useId, type ReactNode } from 'react';
import type { StockPagination } from './_hub-types';

export function StockPagedList({
    title,
    page,
    onPage,
    onPageSize,
    children,
}: {
    title: string;
    page: StockPagination;
    onPage: (page: number) => void;
    onPageSize: (size: number) => void;
    children: ReactNode;
}) {
    const label = useId();
    const controls = (
        <nav aria-label={`${title} pages`} className="flex items-center gap-2">
            <Button
                size="sm"
                variant="outline"
                disabled={page.current_page <= 1}
                onClick={() => onPage(page.current_page - 1)}
                aria-label={`Previous ${title.toLowerCase()} page`}
            >
                <ChevronLeft className="size-3.5" />
                Previous
            </Button>
            <span className="text-xs text-muted-foreground tabular-nums">
                Page {page.current_page} of {page.last_page}
            </span>
            <Button
                size="sm"
                variant="outline"
                disabled={page.current_page >= page.last_page}
                onClick={() => onPage(page.current_page + 1)}
                aria-label={`Next ${title.toLowerCase()} page`}
            >
                Next
                <ChevronRight className="size-3.5" />
            </Button>
        </nav>
    );
    return (
        <section aria-label={title} className="grid min-w-0 gap-5">
            <ListCaption
                title={title}
                caption={
                    <span role="status">
                        {page.from ?? 0}–{page.to ?? 0} of {page.total}{' '}
                        {page.total === 1 ? 'item' : 'items'}
                    </span>
                }
                right={controls}
            />
            {children}
            <div className="flex items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                    <span id={label} className="text-xs text-muted-foreground">
                        Items per page
                    </span>
                    <Select
                        value={String(page.per_page)}
                        onValueChange={(value) => onPageSize(Number(value))}
                    >
                        <SelectTrigger
                            aria-labelledby={label}
                            className="h-8 w-20"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {[10, 25, 50].map((size) => (
                                <SelectItem key={size} value={String(size)}>
                                    {size}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                {controls}
            </div>
        </section>
    );
}
