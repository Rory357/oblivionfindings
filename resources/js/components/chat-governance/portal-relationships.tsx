import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import { cn } from '@/lib/utils';
import { ArrowUpRight, Info, Search } from 'lucide-react';
import { useState } from 'react';
import { ADMINISTRATION_API, governanceRequest } from './types';

type ClientOption = { id: number; name: string; site: string | null };
type Person = { id: number; name: string; access: string };
type Relationships = {
    client: {
        id: number;
        name: string;
        site: { id: number; name: string } | null;
    };
    relatives: (Person & {
        relationship: string | null;
        status: 'approved' | 'not_approved';
    })[];
    named_workers: Person[];
    cover: Person[];
    cover_total: number;
    policy: {
        source: 'organisation' | 'site_override';
        scope: string;
        family_enabled: boolean;
        family_calls_enabled: boolean;
        response_expectation: string;
    };
    links: { portal_users?: string; family_portal?: string };
};

/**
 * Mockup M22 "Client portal relationships" / "Effective Chat access".
 * Read-only: relationships are managed in the client portal
 * administration this links to, never stored here.
 */
export function PortalRelationships({
    actorId,
    onViewPolicy,
}: {
    actorId: number;
    onViewPolicy: (scope: string) => void;
}) {
    const [query, setQuery] = useState('');
    const [clients, setClients] = useState<ClientOption[] | null>(null);
    const [view, setView] = useState<Relationships | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    async function search() {
        if (query.trim().length < 2 || busy) return;
        setBusy(true);
        setError('');
        try {
            const result = await governanceRequest<{ clients: ClientOption[] }>(
                actorId,
                `${ADMINISTRATION_API}/relationships?q=${encodeURIComponent(query.trim())}`,
            );
            setClients(result.clients);
        } catch (failure) {
            setError((failure as Error).message);
        } finally {
            setBusy(false);
        }
    }

    async function open(client: ClientOption) {
        setBusy(true);
        setError('');
        try {
            setView(
                await governanceRequest<Relationships>(
                    actorId,
                    `${ADMINISTRATION_API}/relationships/${client.id}`,
                ),
            );
        } catch (failure) {
            setError((failure as Error).message);
        } finally {
            setBusy(false);
        }
    }

    const rows = view
        ? [
              ...view.relatives.map((person) => ({
                  ...person,
                  role:
                      person.status === 'approved'
                          ? 'Approved family'
                          : 'Not currently approved',
                  tone: person.status === 'approved' ? 'success' : 'neutral',
              })),
              ...view.named_workers.map((person) => ({
                  ...person,
                  role: 'Named worker',
                  tone: 'info',
              })),
              ...view.cover.map((person) => ({
                  ...person,
                  role: 'Authorised cover',
                  tone: 'neutral',
              })),
          ]
        : [];

    return (
        <div className="grid gap-4">
            <div className="grid gap-2">
                <Label htmlFor="relationships-client">Person supported</Label>
                <div className="flex gap-2">
                    <Input
                        id="relationships-client"
                        value={query}
                        placeholder="Name of the person supported"
                        disabled={busy}
                        onChange={(event) => setQuery(event.target.value)}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                void search();
                            }
                        }}
                    />
                    <Button
                        type="button"
                        variant="outline"
                        disabled={busy || query.trim().length < 2}
                        onClick={() => void search()}
                    >
                        <Search className="size-4" />
                        Find
                    </Button>
                </div>
            </div>
            {clients && clients.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    No one you can access matches that name.
                </p>
            )}
            {clients && clients.length > 0 && !view && (
                <ul
                    aria-label="Matching people supported"
                    className="grid gap-1"
                >
                    {clients.map((client) => (
                        <li key={client.id}>
                            {/* eslint-disable-next-line no-restricted-syntax -- selectable result tile with a two-part row layout */}
                            <button
                                type="button"
                                onClick={() => void open(client)}
                                className={cn(
                                    'flex min-h-[44px] w-full items-center justify-between gap-3 rounded-md border px-3 py-2 text-left text-sm hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                )}
                            >
                                <span>{client.name}</span>
                                <span className="text-muted-foreground">
                                    {client.site ?? 'No site'}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            {error && (
                <p role="alert" className="text-sm text-status-critical">
                    {error}
                </p>
            )}
            {view && (
                <section
                    aria-labelledby="relationships-client-name"
                    className="grid gap-3"
                >
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <p id="relationships-client-name">
                            <strong>
                                {view.client.name}
                                {view.client.site
                                    ? ` · ${view.client.site.name}`
                                    : ''}
                            </strong>
                        </p>
                        <Button
                            variant="link"
                            className="h-auto p-0"
                            onClick={() => setView(null)}
                        >
                            Choose someone else
                        </Button>
                    </div>
                    <Alert variant="info" role="note">
                        <Info />
                        <AlertDescription>
                            One organisation. Access follows approved sites,
                            client relationships, roles and conversation
                            membership.
                        </AlertDescription>
                    </Alert>
                    {rows.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            No approved relatives or care-team members yet.
                        </p>
                    )}
                    <ul className="grid gap-2">
                        {rows.map((row) => (
                            <li
                                key={`${row.role}-${row.id}`}
                                className="rounded-lg border bg-muted/40 p-3 text-sm"
                            >
                                <div className="flex flex-wrap items-center gap-2">
                                    <h3 className="font-medium">{row.name}</h3>
                                    <StatusBadge
                                        variant={
                                            row.tone as
                                                | 'success'
                                                | 'info'
                                                | 'neutral'
                                        }
                                    >
                                        {row.role}
                                    </StatusBadge>
                                </div>
                                <p className="mt-1 text-muted-foreground">
                                    {row.access}
                                </p>
                            </li>
                        ))}
                    </ul>
                    {view.cover_total > view.cover.length && (
                        <p className="text-caption">
                            Showing {view.cover.length} of {view.cover_total}{' '}
                            authorised cover.
                        </p>
                    )}
                    <div className="rounded-lg border p-3 text-sm">
                        <p>
                            Effective policy:{' '}
                            {view.policy.source === 'site_override'
                                ? `${view.client.site?.name} overrides + organisation defaults`
                                : 'Organisation defaults'}
                        </p>
                        <p className="mt-1 text-muted-foreground">
                            Family Chat{' '}
                            {view.policy.family_enabled ? 'on' : 'off'} · Family
                            calls{' '}
                            {view.policy.family_calls_enabled ? 'on' : 'off'} ·{' '}
                            {view.policy.response_expectation ||
                                'No response-time promise configured'}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button onClick={() => onViewPolicy(view.policy.scope)}>
                            View effective Chat policy
                        </Button>
                        {view.links.portal_users && (
                            <Button asChild variant="outline">
                                <a href={view.links.portal_users}>
                                    Manage portal relationships
                                    <ArrowUpRight className="size-4" />
                                </a>
                            </Button>
                        )}
                        {view.links.family_portal && (
                            <Button asChild variant="outline">
                                <a href={view.links.family_portal}>
                                    Family portal settings
                                    <ArrowUpRight className="size-4" />
                                </a>
                            </Button>
                        )}
                    </div>
                </section>
            )}
        </div>
    );
}
