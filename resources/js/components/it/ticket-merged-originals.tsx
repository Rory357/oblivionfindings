import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Link } from '@inertiajs/react';

export interface MergedOriginals {
    data: {
        id: number;
        reference: string | null;
        title: string;
        href: string;
        history_href: string;
        tasks_href: string | null;
        approvals_href: string | null;
        links_href: string | null;
    }[];
    previous_page_url: string | null;
    next_page_url: string | null;
}

export function TicketMergedOriginals({
    originals,
}: {
    originals?: MergedOriginals;
}) {
    if (!originals || (!originals.data.length && !originals.previous_page_url))
        return null;
    return (
        <section
            aria-label="Preserved original records"
            className="rounded-2xl border border-border bg-card p-4"
        >
            <Collapsible defaultOpen={originals.previous_page_url !== null}>
                <CollapsibleTrigger asChild>
                    <Button variant="ghost" className="w-full justify-start">
                        View preserved original records
                    </Button>
                </CollapsibleTrigger>
                <CollapsibleContent className="space-y-4 pt-3">
                    <div>
                        <h2 className="text-sm font-semibold">
                            Preserved original records
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            These records were merged directly into this ticket.
                            Their original work and history remain read-only.
                            Open an original to find any earlier merged records.
                        </p>
                    </div>
                    {originals.data.length === 0 && (
                        <p className="text-sm">
                            No original records are available on this page.
                            Return to the previous page.
                        </p>
                    )}
                    <ul className="divide-y divide-border">
                        {originals.data.map((original) => (
                            <li
                                key={original.id}
                                className="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"
                            >
                                <Link
                                    href={original.href}
                                    className="min-w-0 flex-1 text-sm font-semibold break-words text-primary hover:underline focus-visible:outline-ring"
                                >
                                    <span className="mr-2 font-mono">
                                        {original.reference ??
                                            `#${original.id}`}
                                    </span>
                                    {original.title}
                                </Link>
                                <div
                                    className="flex flex-wrap gap-2"
                                    aria-label={`Preserved evidence for ${original.reference ?? `#${original.id}`}`}
                                >
                                    <Button asChild variant="outline" size="sm">
                                        <Link href={original.history_href}>
                                            History
                                        </Link>
                                    </Button>
                                    {original.tasks_href && (
                                        <Button
                                            asChild
                                            variant="outline"
                                            size="sm"
                                        >
                                            <Link href={original.tasks_href}>
                                                Tasks & evidence
                                            </Link>
                                        </Button>
                                    )}
                                    {original.approvals_href && (
                                        <Button
                                            asChild
                                            variant="outline"
                                            size="sm"
                                        >
                                            <Link
                                                href={original.approvals_href}
                                            >
                                                Approvals
                                            </Link>
                                        </Button>
                                    )}
                                    {original.links_href && (
                                        <Button
                                            asChild
                                            variant="outline"
                                            size="sm"
                                        >
                                            <Link href={original.links_href}>
                                                Linked records
                                            </Link>
                                        </Button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                    {(originals.previous_page_url ||
                        originals.next_page_url) && (
                        <nav
                            aria-label="Original record pages"
                            className="flex justify-end gap-2"
                        >
                            {originals.previous_page_url ? (
                                <Button asChild variant="outline">
                                    <Link
                                        href={originals.previous_page_url}
                                        preserveScroll
                                    >
                                        Previous originals
                                    </Link>
                                </Button>
                            ) : (
                                <Button variant="outline" disabled>
                                    Previous originals
                                </Button>
                            )}
                            {originals.next_page_url ? (
                                <Button asChild variant="outline">
                                    <Link
                                        href={originals.next_page_url}
                                        preserveScroll
                                    >
                                        More originals
                                    </Link>
                                </Button>
                            ) : (
                                <Button variant="outline" disabled>
                                    More originals
                                </Button>
                            )}
                        </nav>
                    )}
                </CollapsibleContent>
            </Collapsible>
        </section>
    );
}
