/**
 * Small shared building blocks for the workspace panes: section header,
 * search box, filter chips, a List/Board toggle, a generic kanban board,
 * and an empty state. Kept dependency-light so panes stay readable.
 */
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { LayoutGrid, List as ListIcon, Search } from 'lucide-react';
import type { ComponentType, ReactNode } from 'react';
import { TONE_DOT, type Tone } from './shared';
import { Button as GuardrailButton } from '@/components/ui/button';

export function PaneHead({
    icon: Icon,
    title,
    count,
    children,
}: {
    icon: ComponentType<{ className?: string }>;
    title: string;
    count?: ReactNode;
    children?: ReactNode;
}) {
    return (
        <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div className="flex items-center gap-2.5">
                <span className="grid h-8 w-8 place-items-center rounded-[9px] bg-primary/10 text-primary">
                    <Icon className="h-4 w-4" />
                </span>
                <h2 className="text-[17px] font-bold tracking-tight">{title}</h2>
                {count != null ? (
                    <span className="text-[13px] font-semibold tabular-nums text-muted-foreground">{count}</span>
                ) : null}
            </div>
            {children ? <div className="flex flex-wrap items-center gap-2">{children}</div> : null}
        </div>
    );
}

export function SearchBox({
    value,
    onChange,
    placeholder,
}: {
    value: string;
    onChange: (v: string) => void;
    placeholder: string;
}) {
    return (
        <div className="relative w-full max-w-xs flex-1">
            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder={placeholder}
                className="pl-9"
            />
        </div>
    );
}

export function FilterChip({
    active,
    onClick,
    tone,
    children,
}: {
    active: boolean;
    onClick: () => void;
    tone?: Tone;
    children: ReactNode;
}) {
    return (
        <GuardrailButton unstyled
            type="button"
            onClick={onClick}
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-[12.5px] font-semibold transition-colors',
                active
                    ? 'border-transparent bg-primary text-primary-foreground'
                    : 'border-border bg-card text-muted-foreground hover:bg-muted',
            )}
        >
            {tone && active ? <span className={cn('h-1.5 w-1.5 rounded-full', TONE_DOT[tone])} /> : null}
            {children}
        </GuardrailButton>
    );
}

export function ViewToggle({ view, setView }: { view: 'list' | 'board'; setView: (v: 'list' | 'board') => void }) {
    const opts: [('list' | 'board'), string, ComponentType<{ className?: string }>][] = [
        ['list', 'List', ListIcon],
        ['board', 'Board', LayoutGrid],
    ];
    return (
        <div className="inline-flex rounded-[9px] border border-border bg-muted p-0.5">
            {opts.map(([k, label, Icon]) => (
                <GuardrailButton unstyled
                    key={k}
                    type="button"
                    onClick={() => setView(k)}
                    className={cn(
                        'inline-flex items-center gap-1.5 rounded-[7px] px-2.5 py-1.5 text-[12.5px] font-semibold transition-colors',
                        view === k ? 'bg-card text-primary shadow-sm' : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    <Icon className="h-3.5 w-3.5" />
                    {label}
                </GuardrailButton>
            ))}
        </div>
    );
}

export function Empty({
    icon: Icon,
    title,
    sub,
}: {
    icon: ComponentType<{ className?: string }>;
    title: string;
    sub?: string;
}) {
    return (
        <div className="px-4 py-14 text-center">
            <Icon className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40" />
            <p className="font-medium text-muted-foreground">{title}</p>
            {sub ? <p className="mt-1 text-sm text-muted-foreground/70">{sub}</p> : null}
        </div>
    );
}

export interface KanbanColumn {
    key: string;
    label: string;
    tone?: Tone;
}

export function Kanban<T>({
    columns,
    items,
    groupKey,
    renderCard,
}: {
    columns: KanbanColumn[];
    items: T[];
    groupKey: (item: T) => string;
    renderCard: (item: T) => ReactNode;
}) {
    return (
        <div className="overflow-x-auto pb-1.5">
            <div className="flex min-w-min gap-3.5">
                {columns.map((col) => {
                    const colItems = items.filter((it) => groupKey(it) === col.key);
                    return (
                        <div key={col.key} className="w-[270px] shrink-0">
                            <div className="flex items-center gap-2 px-1 pb-2.5">
                                <span className={cn('h-2 w-2 rounded-full', TONE_DOT[col.tone ?? 'neutral'])} />
                                <span className="text-[13px] font-bold">{col.label}</span>
                                <span className="rounded-full bg-muted px-2 text-[11px] font-semibold tabular-nums text-muted-foreground">
                                    {colItems.length}
                                </span>
                            </div>
                            <div className="flex min-h-[80px] flex-col gap-2 rounded-xl bg-muted/40 p-2">
                                {colItems.map((it) => renderCard(it))}
                                {colItems.length === 0 ? (
                                    <div className="py-4 text-center text-[12px] text-muted-foreground/60">Nothing here</div>
                                ) : null}
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
