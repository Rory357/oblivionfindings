import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { Columns3, type LucideIcon } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import type { PageMeta } from './_types';

/** A block's head: title, one line, and its controls on the right. */
export function BlockHead({
    title,
    sub,
    right,
}: {
    title: string;
    sub?: ReactNode;
    right?: ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-3">
            <div className="min-w-0">
                <h2 className="text-section-title">{title}</h2>
                {sub ? <p className="text-subtle mt-0.5">{sub}</p> : null}
            </div>
            {right ? <div className="flex flex-wrap items-center gap-2">{right}</div> : null}
        </div>
    );
}

/** The switch above a block: Cards | List, Batteries | Updates. */
export function ViewSwitch<K extends string>({
    label,
    value,
    options,
    onChange,
}: {
    label: string;
    value: K;
    options: { value: K; label: string; icon: LucideIcon }[];
    onChange: (value: K) => void;
}) {
    return (
        <ToggleGroup
            type="single"
            variant="outline"
            aria-label={label}
            value={value}
            onValueChange={(next) => {
                if (next) onChange(next as K);
            }}
            className="bg-card"
        >
            {options.map((option) => {
                const Icon = option.icon;
                return (
                    <ToggleGroupItem key={option.value} value={option.value} className="gap-1.5 px-3 text-[13px]">
                        <Icon className="size-4" />
                        {option.label}
                    </ToggleGroupItem>
                );
            })}
        </ToggleGroup>
    );
}

export type ColumnChoice = { key: string; label: string; fixed?: boolean };

/** Remembered for each person in this browser; falls back to the drawn columns. */
export function useColumns(storageKey: string, all: ColumnChoice[], initial: string[]) {
    const [shown, setShown] = useState<string[]>(() => {
        try {
            const saved = JSON.parse(window.localStorage.getItem(storageKey) ?? 'null');
            if (Array.isArray(saved)) {
                const keys = saved.filter((key): key is string => typeof key === 'string' && all.some((column) => column.key === key));
                if (keys.length > 0) return keys;
            }
        } catch {
            // Storage blocked or unreadable: use the drawn columns.
        }
        return initial;
    });
    const toggle = (key: string, on: boolean) => {
        const next = on ? [...shown, key] : shown.filter((item) => item !== key);
        const ordered = all.map((column) => column.key).filter((item) => next.includes(item));
        setShown(ordered);
        try {
            window.localStorage.setItem(storageKey, JSON.stringify(ordered));
        } catch {
            // Not saved; it still applies until the page reloads.
        }
    };
    return { shown, toggle };
}

export function ColumnsMenu({
    all,
    shown,
    onToggle,
}: {
    all: ColumnChoice[];
    shown: string[];
    onToggle: (key: string, on: boolean) => void;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button type="button" variant="outline" size="sm">
                    <Columns3 className="size-4" />
                    Columns
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-52">
                <DropdownMenuLabel>Show columns</DropdownMenuLabel>
                {all
                    .filter((column) => !column.fixed)
                    .map((column) => (
                        <DropdownMenuCheckboxItem
                            key={column.key}
                            checked={shown.includes(column.key)}
                            onCheckedChange={(checked) => onToggle(column.key, checked === true)}
                            onSelect={(event) => event.preventDefault()}
                        >
                            {column.label}
                        </DropdownMenuCheckboxItem>
                    ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** "Showing 1–25 of 86" and the pager. */
export function ListPager({ meta, noun, onPage }: { meta: PageMeta; noun: string; onPage: (page: number) => void }) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3">
            <span className="text-subtle">
                {meta.total === 0 ? `No ${noun}` : `Showing ${meta.from}–${meta.to} of ${meta.total}`}
            </span>
            {meta.last_page > 1 ? (
                <LaravelPagination
                    lastPage={meta.last_page}
                    navigation={{
                        currentPage: meta.page,
                        previousPage: meta.page > 1 ? meta.page - 1 : null,
                        nextPage: meta.page < meta.last_page ? meta.page + 1 : null,
                        onPageChange: onPage,
                    }}
                />
            ) : null}
        </div>
    );
}
