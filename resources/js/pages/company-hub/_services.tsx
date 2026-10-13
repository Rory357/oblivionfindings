import {
    ItServiceCatalogue,
    type CatalogFieldOptions,
    type CatalogItem,
} from '@/components/it/it-service-catalogue';
import { TicketDrawer } from '@/components/it/ticket-drawer';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatRelative } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import { ArrowUpRight, ClipboardList, Search } from 'lucide-react';
import { useState } from 'react';
import { StaffServiceCards, type StaffService } from './_staff-services';
import type { HubPerson } from './_types';

export type HubServiceData = {
    draftRecoveryEnabled?: boolean;
    actorId: number;
    handoffs?: StaffService[];
    peopleContacts?: HubPerson[];
    items: CatalogItem[];
    fieldOptions: CatalogFieldOptions;
    requests: Array<{
        id: number;
        title: string;
        reference: string;
        status: string;
        updated_at: string;
        url: string;
    }>;
    provisioning?: {
        total: number;
        last_page: number;
        data: Array<{
            id: number;
            title: string;
            reference: string;
            status: string;
            href: string;
            updated_at: string;
            progress: { done: number; total: number };
        }>;
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
};
export function ServicesWorkspace({
    data,
    initialSearch = '',
}: {
    data?: HubServiceData;
    initialSearch?: string;
}) {
    const [query, setQuery] = useState(initialSearch);
    const [category, setCategory] = useState('all');
    const [mode, setMode] = useState('catalogue');
    const [ticket, setTicket] = useState<number | null>(null);
    if (!data) return null;
    const needle = query.trim().toLocaleLowerCase();
    const handoffs = (data.handoffs ?? []).filter(
        (item) =>
            (category === 'all' || category === `staff:${item.category}`) &&
            (!needle ||
                [item.name, item.description, item.category].some((value) =>
                    value.toLocaleLowerCase().includes(needle),
                )),
    );
    const catalogueMatches = data.items.some(
        (item) =>
            (category === 'all' || item.category === category) &&
            (!needle ||
                [
                    item.name,
                    item.description,
                    item.category,
                    item.outcome_type,
                ].some((value) => value?.toLocaleLowerCase().includes(needle))),
    );
    return (
        <section className="space-y-5">
            <div className="flex gap-2">
                <Button
                    variant={mode === 'catalogue' ? 'secondary' : 'ghost'}
                    aria-pressed={mode === 'catalogue'}
                    onClick={() => setMode('catalogue')}
                >
                    Find a service
                </Button>
                <Button
                    variant={mode === 'requests' ? 'secondary' : 'ghost'}
                    aria-pressed={mode === 'requests'}
                    onClick={() => setMode('requests')}
                >
                    <ClipboardList className="size-4" />
                    My IT requests ·{' '}
                    {data.requests.length + (data.provisioning?.total ?? 0)}
                </Button>
            </div>
            {mode === 'catalogue' ? (
                <>
                    <div className="hub-library-tools">
                        <label className="hub-library-search">
                            <Search className="size-4" />
                            <Input
                                aria-label="Search services"
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                                placeholder="What do you need a hand with?"
                            />
                        </label>
                        <select
                            className="hub-native-select"
                            aria-label="Service category"
                            value={category}
                            onChange={(e) => setCategory(e.target.value)}
                        >
                            <option value="all">All services</option>
                            {[
                                ...new Set(
                                    (data.handoffs ?? []).map(
                                        (item) => item.category,
                                    ),
                                ),
                            ].map((c) => (
                                <option key={`staff:${c}`} value={`staff:${c}`}>
                                    {c}
                                </option>
                            ))}
                            {[
                                ...new Set(data.items.map((i) => i.category)),
                            ].map((c) => (
                                <option key={c} value={c}>
                                    {c.replace(/_/g, ' ')}
                                </option>
                            ))}
                        </select>
                    </div>
                    {handoffs.length > 0 && (
                        <StaffServiceCards
                            items={handoffs}
                            contacts={data.peopleContacts ?? []}
                        />
                    )}
                    {catalogueMatches && (
                        <div className="space-y-3">
                            <h2 className="text-base font-semibold">
                                IT requests & support
                            </h2>
                            <ItServiceCatalogue
                                actorId={data.actorId}
                                items={data.items}
                                fieldOptions={data.fieldOptions}
                                query={query}
                                category={category === 'all' ? null : category}
                                draftRecoveryEnabled={
                                    data.draftRecoveryEnabled === true
                                }
                            />
                        </div>
                    )}
                    {!handoffs.length && !catalogueMatches && (
                        <div className="hub-empty-note">
                            <p>
                                No services match your search. Try another word
                                or category.
                            </p>
                            <Button
                                variant="link"
                                onClick={() => {
                                    setQuery('');
                                    setCategory('all');
                                }}
                            >
                                Show all services
                            </Button>
                        </div>
                    )}
                </>
            ) : (
                <div className="space-y-3">
                    <p className="text-sm text-muted-foreground">
                        Your IT tickets and service requests are shown here.{' '}
                        <Link
                            href="/hr/my/leave"
                            className="text-primary underline underline-offset-4"
                        >
                            Track leave requests in My HR
                        </Link>
                        .
                    </p>
                    {!!data.provisioning?.data.length && (
                        <h3 className="font-semibold">Service requests</h3>
                    )}
                    {data.provisioning?.data.map((r) => (
                        <Link
                            key={r.id}
                            href={r.href}
                            className="hub-update-row"
                        >
                            <div className="min-w-0 flex-1">
                                <span className="text-xs text-primary">
                                    {r.reference}
                                </span>
                                <h3 className="mt-1 font-medium">{r.title}</h3>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {r.progress.done} of {r.progress.total}{' '}
                                    steps completed · Updated{' '}
                                    {formatRelative(r.updated_at)}
                                </p>
                            </div>
                            <span className="rounded-full bg-muted px-3 py-1 text-xs">
                                {r.status.replace(/_/g, ' ')}
                            </span>
                            <ArrowUpRight className="size-4 text-primary" />
                        </Link>
                    ))}
                    {(data.provisioning?.last_page ?? 0) > 1 && (
                        <nav
                            className="flex flex-wrap gap-2"
                            aria-label="Service request pages"
                        >
                            {data.provisioning?.links.map((link, i) =>
                                link.url ? (
                                    <Button
                                        key={i}
                                        variant={
                                            link.active ? 'default' : 'outline'
                                        }
                                        size="sm"
                                        asChild
                                    >
                                        <Link href={link.url}>
                                            {link.label
                                                .replace(/&laquo; /g, '')
                                                .replace(/ &raquo;/g, '')}
                                        </Link>
                                    </Button>
                                ) : null,
                            )}
                        </nav>
                    )}
                    {!!data.requests.length && (
                        <h3 className="font-semibold">Support tickets</h3>
                    )}
                    {data.requests.map((r) => (
                        <Button
                            variant="ghost"
                            key={r.id}
                            onClick={() => setTicket(r.id)}
                            className="hub-update-row h-auto w-full text-left whitespace-normal"
                        >
                            <div className="min-w-0 flex-1">
                                <span className="text-xs text-primary">
                                    {r.reference}
                                </span>
                                <h3 className="mt-1 font-medium">{r.title}</h3>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Updated {formatRelative(r.updated_at)}
                                </p>
                            </div>
                            <span className="rounded-full bg-muted px-3 py-1 text-xs">
                                {r.status.replace(/_/g, ' ')}
                            </span>
                            <ArrowUpRight className="size-4 text-primary" />
                        </Button>
                    ))}
                    {data.requests.length === 0 &&
                        !data.provisioning?.total && (
                            <p className="hub-empty-note">
                                You don’t have any requests to show. Choose a
                                service to get started.
                            </p>
                        )}
                </div>
            )}
            <TicketDrawer ticketId={ticket} onClose={() => setTicket(null)} />
        </section>
    );
}
