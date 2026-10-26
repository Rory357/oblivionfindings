import { ItModuleShell } from '@/components/it/it-module-shell';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderSearch,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/datetime';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowRight,
    ExternalLink,
    FileCode,
    Monitor,
    Search,
    ShieldCheck,
    Ticket,
} from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { RemoteConnectionDialog } from './_dialogs';
import { RemotePolicyEditor } from './policy-editor';
import { RestartPolicyEditor } from './restart-policy';
import { ScriptPolicyEditor } from './script-policy';
import { ServiceActionPolicyEditor } from './service-action-policy';
import { SoftwarePolicyEditor } from './software-policy';
import { TechnicianObli } from './technician-obli';

interface Computer {
    id: number;
    name: string;
    uid: string;
    category: string;
    status: string;
    last_seen_at: string | null;
    href: string;
    management_href?: string;
}
export interface RemoteSupportProps {
    actor_id: number;
    can_manage_remote_policy: boolean;
    can_manage_action_policy?: boolean;
    can_manage_restart_policy?: boolean;
    can_manage_software_policy?: boolean;
    can_view_software_catalogue?: boolean;
    can_view_script_catalogue?: boolean;
    computers: {
        data: Computer[];
        total: number;
        links: { url: string | null; label: string; active: boolean }[];
    };
    selected: Computer | null;
    ticket: { id: number; reference: string; href: string } | null;
    filters: { q: string };
    summary: { computers: number; endpoints: number; servers: number };
    availability: {
        remote_support: boolean;
        obli: boolean;
        script_execution?: boolean;
    };
}

export default function RemoteSupport(props: RemoteSupportProps) {
    return <RemoteSupportWorkspace key={props.filters.q} {...props} />;
}

function RemoteSupportWorkspace({
    actor_id,
    can_manage_remote_policy,
    can_manage_action_policy,
    can_manage_restart_policy,
    can_manage_software_policy,
    can_view_software_catalogue,
    can_view_script_catalogue,
    computers,
    selected,
    ticket,
    filters,
    summary,
    availability,
}: RemoteSupportProps) {
    const [search, setSearch] = useState(filters.q);
    const [connectionOpen, setConnectionOpen] = useState(false);
    const searchComputers = (event: FormEvent) => {
        event.preventDefault();
        router.get(
            '/it/remote-support',
            {
                q: search.trim() || undefined,
                device: selected?.id,
                ticket: ticket?.id,
            },
            { preserveScroll: true },
        );
    };
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'IT & Support', href: '/it' },
                { title: 'Remote support', href: '/it/remote-support' },
            ]}
        >
            <Head title="Remote support" />
            <ItModuleShell>
                <PageHeader
                    icon={Monitor}
                    title="Remote support"
                    subline="Computer support and Obli assistance"
                    actions={
                        <>
                            <form
                                onSubmit={searchComputers}
                                className="flex items-center gap-2"
                            >
                                <PageHeaderSearch
                                    value={search}
                                    onChange={setSearch}
                                    placeholder="Search computers…"
                                />
                                <PageHeaderGlassButton type="submit">
                                    <Search aria-hidden="true" />
                                    Search
                                </PageHeaderGlassButton>
                            </form>
                            {can_view_script_catalogue && (
                                <PageHeaderGlassButton asChild>
                                    <Link
                                        href={
                                            '/security-devices/scripts?expected_actor_id=' +
                                            actor_id
                                        }
                                    >
                                        <FileCode aria-hidden="true" />
                                        Approved scripts
                                    </Link>
                                </PageHeaderGlassButton>
                            )}
                        </>
                    }
                    meters={
                        <>
                            <PageHeaderMeterBlock
                                label="Computers"
                                href="/it/remote-support"
                            >
                                <PageHeaderMeterBig>
                                    {summary.computers}
                                </PageHeaderMeterBig>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Workstations"
                                href="#computer-list"
                            >
                                <PageHeaderMeterBig>
                                    {summary.endpoints}
                                </PageHeaderMeterBig>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Servers"
                                href="#computer-list"
                            >
                                <PageHeaderMeterBig>
                                    {summary.servers}
                                </PageHeaderMeterBig>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Service desk"
                                href="/it?tab=tickets"
                            >
                                <span className="text-sm">
                                    Tickets & queues
                                </span>
                            </PageHeaderMeterBlock>
                        </>
                    }
                />
                <div className="mt-5 grid min-w-0 gap-5 lg:grid-cols-[minmax(280px,1fr)_minmax(0,2fr)]">
                    <section
                        id="computer-list"
                        aria-labelledby="computers-title"
                        className="min-w-0 rounded-xl border bg-card"
                    >
                        <div className="border-b p-4">
                            <h2
                                id="computers-title"
                                className="text-section-title"
                            >
                                Choose a computer
                            </h2>
                            <p className="text-subtle">
                                Computers within your device access. Selecting
                                one does not connect.
                            </p>
                        </div>
                        {computers.data.length ? (
                            <ul className="divide-y">
                                {computers.data.map((computer) => (
                                    <li key={computer.id}>
                                        <Link
                                            href={`/it/remote-support?device=${computer.id}${filters.q ? '&q=' + encodeURIComponent(filters.q) : ''}`}
                                            aria-current={
                                                selected?.id === computer.id
                                                    ? 'page'
                                                    : undefined
                                            }
                                            className={`frontline-focus flex items-center gap-3 px-4 py-3 ${selected?.id === computer.id ? 'bg-accent text-accent-foreground' : 'hover:bg-muted'}`}
                                        >
                                            <Monitor
                                                className="h-4 w-4 shrink-0"
                                                aria-hidden="true"
                                            />
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate font-medium">
                                                    {computer.name}
                                                </span>
                                                <span className="text-caption block">
                                                    {computer.uid} ·{' '}
                                                    {computer.category ===
                                                    'server'
                                                        ? 'Server'
                                                        : 'Workstation'}
                                                </span>
                                            </span>
                                            <ArrowRight
                                                className="h-4 w-4 shrink-0"
                                                aria-hidden="true"
                                            />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <div className="p-5">
                                <p className="font-medium">
                                    {filters.q
                                        ? 'No matching computers'
                                        : 'No computers available'}
                                </p>
                                <p className="text-subtle mt-1">
                                    {filters.q
                                        ? 'Try another name or device reference.'
                                        : 'Computers will appear here when recorded in Security & Devices and assigned to a site you can access.'}
                                </p>
                            </div>
                        )}
                        <div className="border-t p-3">
                            <LaravelPagination links={computers.links} />
                        </div>
                    </section>
                    <section
                        aria-labelledby="support-title"
                        className="flex min-w-0 flex-col gap-5"
                    >
                        <Card className="gap-0 p-5 shadow-none">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h2
                                        id="support-title"
                                        className="text-section-title"
                                    >
                                        {selected?.name ?? 'Computer support'}
                                    </h2>
                                    <p className="text-subtle">
                                        {selected
                                            ? selected.uid
                                            : 'Choose a computer to open its support workspace.'}
                                    </p>
                                </div>
                                {availability.remote_support && (
                                    <StatusBadge variant="neutral">
                                        {connectionOpen
                                            ? 'Remote workspace open'
                                            : 'No remote session'}
                                    </StatusBadge>
                                )}
                            </div>
                            {selected && (
                                <div className="mt-4 flex flex-wrap items-center justify-between gap-3 border-t pt-4">
                                    <p className="text-subtle">
                                        Last device observation:{' '}
                                        {formatDateTime(
                                            selected.last_seen_at,
                                            'Not yet reported',
                                        )}
                                    </p>
                                    <Button variant="outline" asChild>
                                        <Link href={selected.href}>
                                            <ExternalLink aria-hidden="true" />
                                            Device record
                                        </Link>
                                    </Button>
                                </div>
                            )}
                            {selected && can_manage_remote_policy && (
                                <div className="mt-4">
                                    <RemotePolicyEditor
                                        key={actor_id + ':' + selected.id}
                                        actorId={actor_id}
                                        device={selected}
                                    />
                                </div>
                            )}
                            {selected && availability.script_execution && (
                                <div className="mt-4">
                                    <ScriptPolicyEditor
                                        actorId={actor_id}
                                        device={selected}
                                    />
                                </div>
                            )}
                            {selected &&
                                (selected.management_href ||
                                    can_manage_action_policy ||
                                    can_manage_restart_policy ||
                                    can_manage_software_policy ||
                                    can_view_software_catalogue) && (
                                    <div className="mt-4 flex flex-wrap gap-2 border-t pt-4">
                                        {selected.management_href && (
                                            <Button variant="outline" asChild>
                                                <Link
                                                    href={
                                                        selected.management_href
                                                    }
                                                >
                                                    Manage services and actions
                                                </Link>
                                            </Button>
                                        )}
                                        {can_view_software_catalogue && (
                                            <Button variant="outline" asChild>
                                                <Link
                                                    href={
                                                        '/security-devices/software?expected_actor_id=' +
                                                        actor_id +
                                                        '&device_id=' +
                                                        selected.id +
                                                        '&status=published'
                                                    }
                                                >
                                                    Approved software
                                                </Link>
                                            </Button>
                                        )}
                                        {can_manage_software_policy && (
                                            <SoftwarePolicyEditor
                                                key={
                                                    actor_id + ':' + selected.id
                                                }
                                                actorId={actor_id}
                                                device={selected}
                                            />
                                        )}
                                        {can_manage_restart_policy && (
                                            <RestartPolicyEditor
                                                key={
                                                    actor_id + ':' + selected.id
                                                }
                                                actorId={actor_id}
                                                device={selected}
                                            />
                                        )}
                                        {can_manage_action_policy && (
                                            <ServiceActionPolicyEditor
                                                key={
                                                    actor_id + ':' + selected.id
                                                }
                                                actorId={actor_id}
                                                device={selected}
                                            />
                                        )}
                                    </div>
                                )}
                            {ticket && (
                                <div className="mt-4 flex items-center gap-2 border-t pt-4">
                                    <Ticket
                                        className="h-4 w-4"
                                        aria-hidden="true"
                                    />
                                    <Link
                                        href={ticket.href}
                                        className="frontline-focus text-primary underline"
                                    >
                                        Return to {ticket.reference}
                                    </Link>
                                    <span className="text-caption">
                                        Support context
                                    </span>
                                </div>
                            )}
                        </Card>
                        {availability.remote_support && (
                            <Card className="gap-4 p-5 shadow-none sm:flex-row sm:items-center">
                                <Monitor
                                    className="h-5 w-5 shrink-0 text-primary"
                                    aria-hidden="true"
                                />
                                <div className="min-w-0 flex-1 space-y-1">
                                    <h3 className="text-section-title">
                                        Connect to computer
                                    </h3>
                                    <p
                                        id="connection-readiness"
                                        className="text-subtle"
                                    >
                                        Screen viewing and control follow the
                                        consent policy and your current
                                        permissions.
                                    </p>
                                </div>
                                <Button
                                    disabled={!selected}
                                    onClick={() => setConnectionOpen(true)}
                                    aria-describedby="connection-readiness"
                                >
                                    Connect
                                </Button>
                            </Card>
                        )}
                        {selected && (
                            <TechnicianObli
                                scope={{
                                    actorId: actor_id,
                                    deviceId: selected.id,
                                    ticketId: ticket?.id ?? null,
                                }}
                                computerName={selected.name}
                                available={availability.obli}
                            />
                        )}
                        <div className="flex items-start gap-3 rounded-xl border bg-muted p-4">
                            <ShieldCheck
                                className="mt-0.5 h-5 w-5 shrink-0"
                                aria-hidden="true"
                            />
                            <p className="text-subtle">
                                Opening this workspace does not read the
                                computer, share information with an AI provider,
                                or grant remote access.
                            </p>
                        </div>
                    </section>
                </div>
                {selected && availability.remote_support && (
                    <RemoteConnectionDialog
                        key={
                            actor_id +
                            ':' +
                            selected.id +
                            ':' +
                            (ticket?.id ?? '')
                        }
                        open={connectionOpen}
                        onClose={() => setConnectionOpen(false)}
                        actorId={actor_id}
                        device={selected}
                        ticketId={ticket?.id}
                    />
                )}
            </ItModuleShell>
        </AppLayout>
    );
}
