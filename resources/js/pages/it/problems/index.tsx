import { Button } from '@/components/ui/button';
import { ItModuleShell } from '@/components/it/it-module-shell';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    BookOpenCheck,
    Plus,
    Search,
} from 'lucide-react';
import { FormEvent, useState } from 'react';

interface ProblemRow {
    id: number;
    problem_id: number;
    reference: string;
    title: string;
    priority: string;
    status: string;
    workflow_state: string;
    impact_summary: string | null;
    known_error_at: string | null;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    problems: {
        data: ProblemRow[];
        links: PaginationLink[];
        total: number;
    };
    filters: { state: string | null; q: string | null };
    can: { manage: boolean };
}

export const problemStateVariant: Record<string, StatusVariant> = {
    submitted: 'neutral',
    investigating: 'info',
    waiting: 'warning',
    known_error: 'warning',
    resolved: 'success',
    closed: 'neutral',
};

export const problemLabel = (value: string) =>
    value
        .replace(/_/g, ' ')
        .replace(/^\w/, (character) => character.toUpperCase());

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'IT & Support', href: '/it' },
    { title: 'Problems & known errors', href: '/it/problems' },
];

export default function ItProblemsIndex({ problems, filters, can }: Props) {
    const [creating, setCreating] = useState(false);
    const [query, setQuery] = useState(filters.q ?? '');
    const [state, setState] = useState(filters.state ?? 'all');
    const form = useForm({
        title: '',
        description: '',
        category: 'other',
        priority: 'normal',
        impact_summary: '',
    });

    const search = (event: FormEvent) => {
        event.preventDefault();
        router.get(
            '/it/problems',
            {
                q: query || undefined,
                state: state === 'all' ? undefined : state,
            },
            { preserveState: true, replace: true },
        );
    };

    const create = (event: FormEvent) => {
        event.preventDefault();
        form.post('/it/problems', { onSuccess: () => setCreating(false) });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Problems & known errors" />
            <ItModuleShell>
            <main className="mx-auto w-full max-w-[1500px] space-y-6 px-4 py-6 sm:px-6">
                <header className="rounded-2xl border border-border bg-card p-5 shadow-sm">
                    <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                        <div>
                            <div className="flex items-center gap-2 text-primary">
                                <BookOpenCheck
                                    className="h-5 w-5"
                                    aria-hidden="true"
                                />
                                <span className="text-xs font-bold tracking-wide uppercase">
                                    IT & Support
                                </span>
                            </div>
                            <h1 className="mt-2 text-2xl font-bold tracking-tight">
                                Problems & known errors
                            </h1>
                            <p className="mt-1 max-w-3xl text-sm text-muted-foreground">
                                Investigate recurring incidents once, publish a
                                safe workaround, and govern the permanent fix.
                            </p>
                        </div>
                        {can.manage ? (
                            <Button
                                onClick={() => setCreating(true)}
                                className="min-h-11"
                            >
                                <Plus className="h-4 w-4" aria-hidden="true" />{' '}
                                New problem
                            </Button>
                        ) : null}
                    </div>
                </header>

                <form
                    onSubmit={search}
                    className="flex flex-col gap-3 rounded-2xl border border-border bg-card p-4 sm:flex-row"
                >
                    <label className="relative flex-1">
                        <span className="sr-only">Search problems</span>
                        <Search
                            className="pointer-events-none absolute top-3 left-3 h-4 w-4 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <Input
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            className="min-h-11 pl-9"
                            placeholder="Search reference, title, root cause, or workaround"
                        />
                    </label>
                    <Select value={state} onValueChange={setState}>
                        <SelectTrigger
                            className="min-h-11 w-full sm:w-52"
                            aria-label="Problem state"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All states</SelectItem>
                            {[
                                'investigating',
                                'known_error',
                                'resolved',
                                'closed',
                            ].map((value) => (
                                <SelectItem key={value} value={value}>
                                    {problemLabel(value)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Button
                        type="submit"
                        variant="secondary"
                        className="min-h-11"
                    >
                        Apply filters
                    </Button>
                </form>

                <section
                    aria-label="Problem records"
                    className="overflow-hidden rounded-2xl border border-border bg-card"
                >
                    <div className="flex items-center justify-between border-b border-border px-4 py-3">
                        <h2 className="font-semibold">Problem register</h2>
                        <span className="text-sm text-muted-foreground">
                            {problems.total} records
                        </span>
                    </div>
                    {problems.data.length === 0 ? (
                        <div className="px-6 py-16 text-center">
                            <AlertTriangle
                                className="mx-auto h-6 w-6 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <p className="mt-3 font-medium">
                                No problems match these filters.
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Recurring incidents can be promoted here when a
                                shared cause needs investigation.
                            </p>
                        </div>
                    ) : (
                        <ul className="divide-y divide-border">
                            {problems.data.map((problem) => (
                                <li key={problem.problem_id}>
                                    <Link
                                        href={`/it/problems/${problem.problem_id}`}
                                        className="frontline-focus flex min-h-20 items-center gap-4 px-4 py-4 hover:bg-muted/50"
                                    >
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="font-mono text-xs font-bold text-primary">
                                                    {problem.reference}
                                                </span>
                                                <StatusBadge
                                                    variant={
                                                        problemStateVariant[
                                                            problem
                                                                .workflow_state
                                                        ] ?? 'neutral'
                                                    }
                                                    size="sm"
                                                >
                                                    {problemLabel(
                                                        problem.workflow_state,
                                                    )}
                                                </StatusBadge>
                                                <StatusBadge
                                                    variant={
                                                        problem.priority ===
                                                            'urgent' ||
                                                        problem.priority ===
                                                            'high'
                                                            ? 'critical'
                                                            : 'neutral'
                                                    }
                                                    size="sm"
                                                >
                                                    {problemLabel(
                                                        problem.priority,
                                                    )}
                                                </StatusBadge>
                                            </div>
                                            <h3 className="mt-1 truncate font-semibold">
                                                {problem.title}
                                            </h3>
                                            <p className="mt-1 line-clamp-1 text-sm text-muted-foreground">
                                                {problem.impact_summary ||
                                                    'Impact is still being assessed.'}
                                            </p>
                                        </div>
                                        <ArrowRight
                                            className="h-4 w-4 flex-none text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                    {problems.links.length > 3 ? (
                        <nav
                            aria-label="Problem pages"
                            className="flex flex-wrap gap-1 border-t border-border px-4 py-3"
                        >
                            {problems.links.map((link, index) =>
                                link.url ? (
                                    <Link
                                        key={`${link.label}-${index}`}
                                        href={link.url}
                                        className={`frontline-focus rounded-md px-3 py-2 text-sm ${link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}`}
                                        dangerouslySetInnerHTML={{
                                            __html: link.label,
                                        }}
                                    />
                                ) : null,
                            )}
                        </nav>
                    ) : null}
                </section>
            </main>
            </ItModuleShell>

            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent className="sm:max-w-xl">
                    <form onSubmit={create}>
                        <DialogHeader>
                            <DialogTitle>
                                Open a problem investigation
                            </DialogTitle>
                            <DialogDescription>
                                Create one canonical record for a recurring or
                                high-impact issue.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="mt-5 space-y-4">
                            <Field
                                label="Problem title"
                                error={form.errors.title}
                            >
                                <Input
                                    value={form.data.title}
                                    onChange={(event) =>
                                        form.setData(
                                            'title',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                            </Field>
                            <Field
                                label="What is happening?"
                                error={form.errors.description}
                            >
                                <Textarea
                                    value={form.data.description}
                                    onChange={(event) =>
                                        form.setData(
                                            'description',
                                            event.target.value,
                                        )
                                    }
                                    rows={4}
                                />
                            </Field>
                            <Field
                                label="Impact summary"
                                error={form.errors.impact_summary}
                            >
                                <Textarea
                                    value={form.data.impact_summary}
                                    onChange={(event) =>
                                        form.setData(
                                            'impact_summary',
                                            event.target.value,
                                        )
                                    }
                                    rows={3}
                                />
                            </Field>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Category">
                                    <Select
                                        value={form.data.category}
                                        onValueChange={(value) =>
                                            form.setData('category', value)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {[
                                                'hardware',
                                                'account',
                                                'network',
                                                'other',
                                            ].map((value) => (
                                                <SelectItem
                                                    key={value}
                                                    value={value}
                                                >
                                                    {problemLabel(value)}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </Field>
                                <Field label="Priority">
                                    <Select
                                        value={form.data.priority}
                                        onValueChange={(value) =>
                                            form.setData('priority', value)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {[
                                                'low',
                                                'normal',
                                                'high',
                                                'urgent',
                                            ].map((value) => (
                                                <SelectItem
                                                    key={value}
                                                    value={value}
                                                >
                                                    {problemLabel(value)}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </Field>
                            </div>
                        </div>
                        <DialogFooter className="mt-6">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setCreating(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                Open investigation
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

function Field({
    label,
    error,
    children,
}: {
    label: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <label className="block space-y-1.5 text-sm font-medium">
            <span>{label}</span>
            {children}
            {error ? (
                <span className="block text-xs text-destructive">{error}</span>
            ) : null}
        </label>
    );
}
