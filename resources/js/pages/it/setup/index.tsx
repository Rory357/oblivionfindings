import {
    ItApiIdentities,
    type ItApiIdentity,
    type OneTimeApiCredential,
} from '@/components/it/it-api-identities';
import { ItModuleShell } from '@/components/it/it-module-shell';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import {
    Boxes,
    KeyRound,
    Network,
    Pencil,
    Plus,
    RefreshCw,
    Route,
    UsersRound,
} from 'lucide-react';
import { type FormEvent, type ReactNode, useState } from 'react';

interface Agent {
    id: number;
    name: string;
}
interface TeamMember extends Agent {
    role: string;
}
interface WorkloadTeam {
    open_tickets: number;
    open_tasks: number;
    queues: number;
    members: number;
}
interface Team {
    id: number;
    name: string;
    description: string | null;
    is_active: boolean;
    manager: Agent | null;
    members: TeamMember[];
    workload: WorkloadTeam;
}
interface QueueRules {
    routing_priority?: number;
    is_default?: boolean;
    work_types?: string[];
    categories?: string[];
    priorities?: string[];
    service_ids?: number[];
    site_ids?: number[];
    default_assignee_user_id?: number | null;
}
interface Queue {
    id: number;
    key: string;
    name: string;
    description: string | null;
    is_active: boolean;
    team: Agent | null;
    filter_rules: QueueRules;
    workload: { open_tickets: number; unassigned: number; sla_risk: number };
}
interface Service {
    id: number;
    key: string;
    name: string;
    description: string | null;
    is_active: boolean;
    status: string;
    criticality: string;
    owner: Agent | null;
    workload: { open_tickets: number; sla_risk: number };
}
interface Props {
    teams: Team[];
    queues: Queue[];
    services: Service[];
    agents: Agent[];
    sites: Agent[];
    apiIdentities: ItApiIdentity[];
    oneTimeApiCredential: OneTimeApiCredential | null;
    generatedAt?: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'IT & Support', href: '/it' },
    { title: 'Teams, queues & services', href: '/it/setup' },
];
const labels = (value: string) =>
    value
        .replace(/_/g, ' ')
        .replace(/^\w/, (character) => character.toUpperCase());
const WORK_TYPES = [
    'incident',
    'service_request',
    'security_request',
    'problem',
    'change',
    'task',
    'major_incident',
];
const CATEGORIES = ['hardware', 'account', 'network', 'other'];
const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

export default function ItSetupIndex({
    teams,
    queues,
    services,
    agents,
    sites,
    apiIdentities,
    oneTimeApiCredential,
    generatedAt,
}: Props) {
    const [tab, setTab] = useState<'teams' | 'queues' | 'services' | 'api'>(
        oneTimeApiCredential ? 'api' : 'teams',
    );
    const [teamOpen, setTeamOpen] = useState(false);
    const [queueOpen, setQueueOpen] = useState(false);
    const [serviceOpen, setServiceOpen] = useState(false);
    const [editingTeamId, setEditingTeamId] = useState<number | null>(null);
    const [editingQueueId, setEditingQueueId] = useState<number | null>(null);
    const [editingServiceId, setEditingServiceId] = useState<number | null>(
        null,
    );

    const teamForm = useForm({
        name: '',
        description: '',
        manager_user_id: '',
        is_active: true,
        members: [] as Array<{ user_id: number; role: string }>,
    });
    const queueForm = useForm({
        key: '',
        name: '',
        description: '',
        team_id: '',
        routing_priority: 0,
        is_default: false,
        work_types: [] as string[],
        categories: [] as string[],
        priorities: [] as string[],
        service_ids: [] as number[],
        default_assignee_user_id: '',
        is_active: true,
    });
    const serviceForm = useForm({
        key: '',
        name: '',
        description: '',
        owner_user_id: '',
        status: 'operational',
        criticality: 'medium',
        is_active: true,
    });

    const openTeam = (team?: Team) => {
        setEditingTeamId(team?.id ?? null);
        teamForm.setData({
            name: team?.name ?? '',
            description: team?.description ?? '',
            manager_user_id: String(team?.manager?.id ?? ''),
            is_active: team?.is_active ?? true,
            members:
                team?.members.map((member) => ({
                    user_id: member.id,
                    role: member.role,
                })) ?? [],
        });
        setTeamOpen(true);
    };
    const openQueue = (queue?: Queue) => {
        setEditingQueueId(queue?.id ?? null);
        queueForm.setData({
            key: queue?.key ?? '',
            name: queue?.name ?? '',
            description: queue?.description ?? '',
            team_id: String(queue?.team?.id ?? ''),
            routing_priority: queue?.filter_rules.routing_priority ?? 0,
            is_default: queue?.filter_rules.is_default ?? false,
            work_types: queue?.filter_rules.work_types ?? [],
            categories: queue?.filter_rules.categories ?? [],
            priorities: queue?.filter_rules.priorities ?? [],
            service_ids: queue?.filter_rules.service_ids ?? [],
            default_assignee_user_id: String(
                queue?.filter_rules.default_assignee_user_id ?? '',
            ),
            is_active: queue?.is_active ?? true,
        });
        setQueueOpen(true);
    };
    const openService = (service?: Service) => {
        setEditingServiceId(service?.id ?? null);
        serviceForm.setData({
            key: service?.key ?? '',
            name: service?.name ?? '',
            description: service?.description ?? '',
            owner_user_id: String(service?.owner?.id ?? ''),
            status: service?.status ?? 'operational',
            criticality: service?.criticality ?? 'medium',
            is_active: service?.is_active ?? true,
        });
        setServiceOpen(true);
    };

    const saveTeam = (event: FormEvent) => {
        event.preventDefault();
        const options = { onSuccess: () => setTeamOpen(false) };
        if (editingTeamId) {
            teamForm.patch(`/it/setup/teams/${editingTeamId}`, options);
        } else {
            teamForm.post('/it/setup/teams', options);
        }
    };
    const saveQueue = (event: FormEvent) => {
        event.preventDefault();
        const options = { onSuccess: () => setQueueOpen(false) };
        if (editingQueueId) {
            queueForm.patch(`/it/setup/queues/${editingQueueId}`, options);
        } else {
            queueForm.post('/it/setup/queues', options);
        }
    };
    const saveService = (event: FormEvent) => {
        event.preventDefault();
        const options = { onSuccess: () => setServiceOpen(false) };
        if (editingServiceId) {
            serviceForm.patch(
                `/it/setup/services/${editingServiceId}`,
                options,
            );
        } else {
            serviceForm.post('/it/setup/services', options);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Teams, queues & services" />
            <ItModuleShell>
                <main className="space-y-6 py-2">
                    <header className="rounded-2xl border border-border bg-card p-5 shadow-sm">
                        <div className="flex flex-col justify-between gap-4 xl:flex-row xl:items-center">
                            <div className="flex items-start gap-3">
                                <span className="grid h-11 w-11 flex-none place-items-center rounded-xl bg-primary/10 text-primary">
                                    <Route
                                        className="h-5 w-5"
                                        aria-hidden="true"
                                    />
                                </span>
                                <div>
                                    <p className="text-xs font-bold tracking-wide text-primary uppercase">
                                        IT & Support setup
                                    </p>
                                    <h1 className="mt-1 text-2xl font-bold tracking-tight">
                                        Teams, queues & services
                                    </h1>
                                    <p className="mt-1 max-w-3xl text-sm text-muted-foreground">
                                        Define accountable ownership, workload
                                        routing, and safe default assignment
                                        without hiding where work went.
                                    </p>
                                    <p className="mt-2 flex items-center gap-1.5 text-xs text-muted-foreground">
                                        <RefreshCw
                                            className="h-3.5 w-3.5"
                                            aria-hidden="true"
                                        />
                                        Workload snapshot{' '}
                                        {generatedAt ? (
                                            <time dateTime={generatedAt}>
                                                {new Date(
                                                    generatedAt,
                                                ).toLocaleString('en-NZ', {
                                                    dateStyle: 'medium',
                                                    timeStyle: 'short',
                                                })}
                                            </time>
                                        ) : (
                                            ' from this page load'
                                        )}
                                    </p>
                                </div>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    variant="outline"
                                    onClick={() => openTeam()}
                                >
                                    <Plus
                                        className="h-4 w-4"
                                        aria-hidden="true"
                                    />{' '}
                                    New team
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={() => openQueue()}
                                >
                                    <Plus
                                        className="h-4 w-4"
                                        aria-hidden="true"
                                    />{' '}
                                    New queue
                                </Button>
                                <Button onClick={() => openService()}>
                                    <Plus
                                        className="h-4 w-4"
                                        aria-hidden="true"
                                    />{' '}
                                    New service
                                </Button>
                            </div>
                        </div>
                    </header>

                    <div
                        role="tablist"
                        aria-label="Service management setup"
                        className="flex flex-wrap gap-2 rounded-2xl border border-border bg-card p-2"
                    >
                        <Tab
                            active={tab === 'teams'}
                            onClick={() => setTab('teams')}
                            icon={UsersRound}
                        >
                            Teams
                        </Tab>
                        <Tab
                            active={tab === 'queues'}
                            onClick={() => setTab('queues')}
                            icon={Network}
                        >
                            Queues
                        </Tab>
                        <Tab
                            active={tab === 'services'}
                            onClick={() => setTab('services')}
                            icon={Boxes}
                        >
                            Services
                        </Tab>
                        <Tab
                            active={tab === 'api'}
                            onClick={() => setTab('api')}
                            icon={KeyRound}
                        >
                            API identities
                        </Tab>
                    </div>

                    {tab === 'teams' ? (
                        <Register
                            title="Teams"
                            description="Membership, role, manager, and current workload."
                            empty="No teams configured."
                        >
                            {teams.map((team) => (
                                <TeamCard
                                    key={team.id}
                                    team={team}
                                    onEdit={() => openTeam(team)}
                                />
                            ))}
                        </Register>
                    ) : null}
                    {tab === 'queues' ? (
                        <Register
                            title="Queues"
                            description="Transparent rules route work to the right accountable team."
                            empty="No queues configured."
                        >
                            {queues.map((queue) => (
                                <QueueCard
                                    key={queue.id}
                                    queue={queue}
                                    onEdit={() => openQueue(queue)}
                                />
                            ))}
                        </Register>
                    ) : null}
                    {tab === 'services' ? (
                        <Register
                            title="Services"
                            description="Business-facing services, owners, health, and criticality."
                            empty="No services configured."
                        >
                            {services.map((service) => (
                                <ServiceCard
                                    key={service.id}
                                    service={service}
                                    onEdit={() => openService(service)}
                                />
                            ))}
                        </Register>
                    ) : null}
                    {tab === 'api' ? (
                        <ItApiIdentities
                            identities={apiIdentities}
                            oneTimeCredential={oneTimeApiCredential}
                            agents={agents}
                            sites={sites}
                        />
                    ) : null}
                </main>
            </ItModuleShell>

            <Dialog open={teamOpen} onOpenChange={setTeamOpen}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <form onSubmit={saveTeam}>
                        <DialogHeader>
                            <DialogTitle>
                                {editingTeamId ? 'Edit team' : 'New team'}
                            </DialogTitle>
                            <DialogDescription>
                                Managers and members must be IT agents in this
                                organisation.
                            </DialogDescription>
                        </DialogHeader>
                        <FormErrors errors={teamForm.errors} />
                        <div className="mt-5 grid gap-4 sm:grid-cols-2">
                            <Field label="Team name" className="sm:col-span-2">
                                <Input
                                    value={teamForm.data.name}
                                    onChange={(event) =>
                                        teamForm.setData(
                                            'name',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                            </Field>
                            <Field
                                label="Description"
                                className="sm:col-span-2"
                            >
                                <Textarea
                                    value={teamForm.data.description}
                                    onChange={(event) =>
                                        teamForm.setData(
                                            'description',
                                            event.target.value,
                                        )
                                    }
                                    rows={3}
                                />
                            </Field>
                            <AgentSelect
                                label="Manager"
                                value={teamForm.data.manager_user_id}
                                agents={agents}
                                onChange={(value) =>
                                    teamForm.setData('manager_user_id', value)
                                }
                            />
                            <ActiveToggle
                                checked={teamForm.data.is_active}
                                onChange={(value) =>
                                    teamForm.setData('is_active', value)
                                }
                            />
                            <fieldset className="rounded-xl border border-border p-3 sm:col-span-2">
                                <legend className="px-1 text-sm font-medium">
                                    Members and roles
                                </legend>
                                <div className="mt-2 max-h-64 space-y-1 overflow-y-auto">
                                    {agents.map((agent) => {
                                        const member =
                                            teamForm.data.members.find(
                                                (item) =>
                                                    item.user_id === agent.id,
                                            );
                                        return (
                                            <div
                                                key={agent.id}
                                                className="flex min-h-11 items-center gap-3 rounded-lg px-2 hover:bg-muted/40"
                                            >
                                                <label className="flex min-w-0 flex-1 items-center gap-2 text-sm">
                                                    <input
                                                        type="checkbox"
                                                        checked={Boolean(
                                                            member,
                                                        )}
                                                        onChange={(event) =>
                                                            teamForm.setData(
                                                                'members',
                                                                event.target
                                                                    .checked
                                                                    ? [
                                                                          ...teamForm
                                                                              .data
                                                                              .members,
                                                                          {
                                                                              user_id:
                                                                                  agent.id,
                                                                              role: 'member',
                                                                          },
                                                                      ]
                                                                    : teamForm.data.members.filter(
                                                                          (
                                                                              item,
                                                                          ) =>
                                                                              item.user_id !==
                                                                              agent.id,
                                                                      ),
                                                            )
                                                        }
                                                    />
                                                    <span className="truncate">
                                                        {agent.name}
                                                    </span>
                                                </label>
                                                {member ? (
                                                    <select
                                                        aria-label={`${agent.name} team role`}
                                                        className="min-h-9 rounded-md border border-input bg-background px-2 text-sm"
                                                        value={member.role}
                                                        onChange={(event) =>
                                                            teamForm.setData(
                                                                'members',
                                                                teamForm.data.members.map(
                                                                    (item) =>
                                                                        item.user_id ===
                                                                        agent.id
                                                                            ? {
                                                                                  ...item,
                                                                                  role: event
                                                                                      .target
                                                                                      .value,
                                                                              }
                                                                            : item,
                                                                ),
                                                            )
                                                        }
                                                    >
                                                        <option value="member">
                                                            Member
                                                        </option>
                                                        <option value="lead">
                                                            Lead
                                                        </option>
                                                        <option value="manager">
                                                            Manager
                                                        </option>
                                                    </select>
                                                ) : null}
                                            </div>
                                        );
                                    })}
                                </div>
                            </fieldset>
                        </div>
                        <DialogFooter className="mt-6">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setTeamOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={teamForm.processing}
                            >
                                Save team
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={queueOpen} onOpenChange={setQueueOpen}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                    <form onSubmit={saveQueue}>
                        <DialogHeader>
                            <DialogTitle>
                                {editingQueueId ? 'Edit queue' : 'New queue'}
                            </DialogTitle>
                            <DialogDescription>
                                Specific rules are evaluated before the default
                                queue; higher routing priority wins.
                            </DialogDescription>
                        </DialogHeader>
                        <FormErrors errors={queueForm.errors} />
                        <div className="mt-5 grid gap-4 sm:grid-cols-2">
                            <Field label="Queue name">
                                <Input
                                    value={queueForm.data.name}
                                    onChange={(event) =>
                                        queueForm.setData(
                                            'name',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                            </Field>
                            <Field label="Stable key">
                                <Input
                                    value={queueForm.data.key}
                                    onChange={(event) =>
                                        queueForm.setData(
                                            'key',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="network-urgent"
                                    required
                                />
                            </Field>
                            <Field
                                label="Description"
                                className="sm:col-span-2"
                            >
                                <Textarea
                                    value={queueForm.data.description}
                                    onChange={(event) =>
                                        queueForm.setData(
                                            'description',
                                            event.target.value,
                                        )
                                    }
                                    rows={2}
                                />
                            </Field>
                            <NativeSelect
                                label="Accountable team"
                                value={queueForm.data.team_id}
                                onChange={(value) =>
                                    queueForm.setData('team_id', value)
                                }
                                options={[
                                    { value: '', label: 'No team' },
                                    ...teams.map((team) => ({
                                        value: String(team.id),
                                        label: team.name,
                                    })),
                                ]}
                            />
                            <AgentSelect
                                label="Default assignee"
                                value={queueForm.data.default_assignee_user_id}
                                agents={agents}
                                onChange={(value) =>
                                    queueForm.setData(
                                        'default_assignee_user_id',
                                        value,
                                    )
                                }
                            />
                            <Field label="Routing priority">
                                <Input
                                    type="number"
                                    min={0}
                                    max={1000}
                                    value={queueForm.data.routing_priority}
                                    onChange={(event) =>
                                        queueForm.setData(
                                            'routing_priority',
                                            Number(event.target.value),
                                        )
                                    }
                                />
                            </Field>
                            <div className="flex flex-col gap-2">
                                <ActiveToggle
                                    checked={queueForm.data.is_active}
                                    onChange={(value) =>
                                        queueForm.setData('is_active', value)
                                    }
                                />
                                <label className="flex min-h-11 items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={queueForm.data.is_default}
                                        onChange={(event) =>
                                            queueForm.setData(
                                                'is_default',
                                                event.target.checked,
                                            )
                                        }
                                    />
                                    Use as fallback queue
                                </label>
                            </div>
                            <RuleChoices
                                label="Work types"
                                values={WORK_TYPES}
                                selected={queueForm.data.work_types}
                                onChange={(value) =>
                                    queueForm.setData('work_types', value)
                                }
                            />
                            <RuleChoices
                                label="Categories"
                                values={CATEGORIES}
                                selected={queueForm.data.categories}
                                onChange={(value) =>
                                    queueForm.setData('categories', value)
                                }
                            />
                            <RuleChoices
                                label="Priorities"
                                values={PRIORITIES}
                                selected={queueForm.data.priorities}
                                onChange={(value) =>
                                    queueForm.setData('priorities', value)
                                }
                            />
                            <fieldset className="rounded-xl border border-border p-3">
                                <legend className="px-1 text-sm font-medium">
                                    Services
                                </legend>
                                <div className="mt-1 max-h-36 space-y-1 overflow-y-auto">
                                    {services.map((service) => (
                                        <CheckChoice
                                            key={service.id}
                                            label={service.name}
                                            checked={queueForm.data.service_ids.includes(
                                                service.id,
                                            )}
                                            onChange={(checked) =>
                                                queueForm.setData(
                                                    'service_ids',
                                                    checked
                                                        ? [
                                                              ...queueForm.data
                                                                  .service_ids,
                                                              service.id,
                                                          ]
                                                        : queueForm.data.service_ids.filter(
                                                              (id) =>
                                                                  id !==
                                                                  service.id,
                                                          ),
                                                )
                                            }
                                        />
                                    ))}
                                </div>
                            </fieldset>
                        </div>
                        <DialogFooter className="mt-6">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setQueueOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={queueForm.processing}
                            >
                                Save queue
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={serviceOpen} onOpenChange={setServiceOpen}>
                <DialogContent className="sm:max-w-2xl">
                    <form onSubmit={saveService}>
                        <DialogHeader>
                            <DialogTitle>
                                {editingServiceId
                                    ? 'Edit service'
                                    : 'New service'}
                            </DialogTitle>
                            <DialogDescription>
                                Owners are accountable for service health and
                                the work routed against it.
                            </DialogDescription>
                        </DialogHeader>
                        <FormErrors errors={serviceForm.errors} />
                        <div className="mt-5 grid gap-4 sm:grid-cols-2">
                            <Field label="Service name">
                                <Input
                                    value={serviceForm.data.name}
                                    onChange={(event) =>
                                        serviceForm.setData(
                                            'name',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                            </Field>
                            <Field label="Stable key">
                                <Input
                                    value={serviceForm.data.key}
                                    onChange={(event) =>
                                        serviceForm.setData(
                                            'key',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="identity-access"
                                    required
                                />
                            </Field>
                            <Field
                                label="Description"
                                className="sm:col-span-2"
                            >
                                <Textarea
                                    value={serviceForm.data.description}
                                    onChange={(event) =>
                                        serviceForm.setData(
                                            'description',
                                            event.target.value,
                                        )
                                    }
                                    rows={3}
                                />
                            </Field>
                            <AgentSelect
                                label="Service owner"
                                value={serviceForm.data.owner_user_id}
                                agents={agents}
                                onChange={(value) =>
                                    serviceForm.setData('owner_user_id', value)
                                }
                            />
                            <NativeSelect
                                label="Health status"
                                value={serviceForm.data.status}
                                onChange={(value) =>
                                    serviceForm.setData('status', value)
                                }
                                options={[
                                    'operational',
                                    'degraded',
                                    'outage',
                                    'maintenance',
                                    'retired',
                                ].map((value) => ({
                                    value,
                                    label: labels(value),
                                }))}
                            />
                            <NativeSelect
                                label="Criticality"
                                value={serviceForm.data.criticality}
                                onChange={(value) =>
                                    serviceForm.setData('criticality', value)
                                }
                                options={[
                                    'low',
                                    'medium',
                                    'high',
                                    'critical',
                                ].map((value) => ({
                                    value,
                                    label: labels(value),
                                }))}
                            />
                            <ActiveToggle
                                checked={serviceForm.data.is_active}
                                onChange={(value) =>
                                    serviceForm.setData('is_active', value)
                                }
                            />
                        </div>
                        <DialogFooter className="mt-6">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setServiceOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={serviceForm.processing}
                            >
                                Save service
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

function Tab({
    active,
    onClick,
    icon: Icon,
    children,
}: {
    active: boolean;
    onClick: () => void;
    icon: typeof UsersRound;
    children: ReactNode;
}) {
    return (
        // eslint-disable-next-line no-restricted-syntax -- semantic tab with custom selected-state styling
        <button
            type="button"
            role="tab"
            aria-selected={active}
            onClick={onClick}
            className={`frontline-focus flex min-h-11 items-center gap-2 rounded-xl px-4 text-sm font-semibold ${active ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted hover:text-foreground'}`}
        >
            <Icon className="h-4 w-4" aria-hidden="true" />
            {children}
        </button>
    );
}
function Register({
    title,
    description,
    empty,
    children,
}: {
    title: string;
    description: string;
    empty: string;
    children: ReactNode[];
}) {
    return (
        <section className="overflow-hidden rounded-2xl border border-border bg-card">
            <div className="border-b border-border px-5 py-4">
                <h2 className="font-semibold">{title}</h2>
                <p className="text-xs text-muted-foreground">{description}</p>
            </div>
            {children.length ? (
                <div className="grid gap-4 p-4 xl:grid-cols-2">{children}</div>
            ) : (
                <p className="px-5 py-14 text-center text-sm text-muted-foreground">
                    {empty}
                </p>
            )}
        </section>
    );
}
function TeamCard({ team, onEdit }: { team: Team; onEdit: () => void }) {
    return (
        <article className="rounded-xl border border-border/70 p-4">
            <CardHeader
                title={team.name}
                active={team.is_active}
                onEdit={onEdit}
            />
            <p className="mt-2 text-sm text-muted-foreground">
                {team.description || 'No description.'}
            </p>
            <p className="mt-3 text-xs">
                <span className="font-semibold">Manager:</span>{' '}
                {team.manager?.name ?? 'Unassigned'}
            </p>
            <div className="mt-3 flex flex-wrap gap-2">
                <Metric
                    value={`${team.workload.open_tickets} open`}
                    label="tickets"
                />
                <Metric
                    value={String(team.workload.open_tasks)}
                    label="tasks"
                />
                <Metric value={String(team.workload.members)} label="members" />
                <Metric value={String(team.workload.queues)} label="queues" />
            </div>
        </article>
    );
}
function QueueCard({ queue, onEdit }: { queue: Queue; onEdit: () => void }) {
    const rules = [
        ...(queue.filter_rules.work_types ?? []),
        ...(queue.filter_rules.categories ?? []),
        ...(queue.filter_rules.priorities ?? []),
    ];
    return (
        <article className="rounded-xl border border-border/70 p-4">
            <CardHeader
                title={queue.name}
                active={queue.is_active}
                onEdit={onEdit}
            />
            <p className="mt-1 font-mono text-xs text-primary">{queue.key}</p>
            <p className="mt-2 text-sm text-muted-foreground">
                {queue.team?.name ?? 'No accountable team'}
            </p>
            <div className="mt-3 flex flex-wrap gap-1.5">
                {queue.filter_rules.is_default ? (
                    <StatusBadge variant="info">Fallback</StatusBadge>
                ) : null}
                {rules.slice(0, 5).map((rule) => (
                    <StatusBadge key={rule} variant="neutral">
                        {labels(rule)}
                    </StatusBadge>
                ))}
            </div>
            <div className="mt-3 flex flex-wrap gap-2">
                <Metric
                    value={`${queue.workload.open_tickets} open`}
                    label="tickets"
                />
                <Metric
                    value={String(queue.workload.unassigned)}
                    label="unassigned"
                />
                <Metric
                    value={String(queue.workload.sla_risk)}
                    label="SLA risk"
                />
            </div>
        </article>
    );
}
function ServiceCard({
    service,
    onEdit,
}: {
    service: Service;
    onEdit: () => void;
}) {
    return (
        <article className="rounded-xl border border-border/70 p-4">
            <CardHeader
                title={service.name}
                active={service.is_active}
                onEdit={onEdit}
            />
            <p className="mt-1 font-mono text-xs text-primary">{service.key}</p>
            <div className="mt-3 flex flex-wrap gap-2">
                <StatusBadge
                    variant={
                        service.status === 'operational'
                            ? 'success'
                            : service.status === 'outage'
                              ? 'critical'
                              : 'warning'
                    }
                >
                    {labels(service.status)}
                </StatusBadge>
                <StatusBadge
                    variant={
                        service.criticality === 'critical' ||
                        service.criticality === 'high'
                            ? 'critical'
                            : 'neutral'
                    }
                >
                    {labels(service.criticality)} criticality
                </StatusBadge>
            </div>
            <p className="mt-3 text-xs">
                <span className="font-semibold">Owner:</span>{' '}
                {service.owner?.name ?? 'Unassigned'}
            </p>
            <div className="mt-3 flex flex-wrap gap-2">
                <Metric
                    value={`${service.workload.open_tickets} open`}
                    label="tickets"
                />
                <Metric
                    value={String(service.workload.sla_risk)}
                    label="SLA risk"
                />
            </div>
        </article>
    );
}
function CardHeader({
    title,
    active,
    onEdit,
}: {
    title: string;
    active: boolean;
    onEdit: () => void;
}) {
    return (
        <div className="flex items-start justify-between gap-3">
            <div>
                <h3 className="font-semibold">{title}</h3>
                <StatusBadge variant={active ? 'success' : 'neutral'} size="sm">
                    {active ? 'Active' : 'Inactive'}
                </StatusBadge>
            </div>
            <Button size="sm" variant="ghost" onClick={onEdit}>
                <Pencil className="h-4 w-4" aria-hidden="true" /> Edit
            </Button>
        </div>
    );
}
function Metric({ value, label }: { value: string; label: string }) {
    return (
        <span className="rounded-lg bg-muted/50 px-2.5 py-1.5 text-xs">
            <strong>{value}</strong> {label}
        </span>
    );
}
function Field({
    label,
    className = '',
    children,
}: {
    label: string;
    className?: string;
    children: ReactNode;
}) {
    return (
        <label className={`space-y-1.5 text-sm font-medium ${className}`}>
            {label}
            {children}
        </label>
    );
}
function AgentSelect({
    label,
    value,
    agents,
    onChange,
}: {
    label: string;
    value: string;
    agents: Agent[];
    onChange: (value: string) => void;
}) {
    return (
        <NativeSelect
            label={label}
            value={value}
            onChange={onChange}
            options={[
                { value: '', label: 'Unassigned' },
                ...agents.map((agent) => ({
                    value: String(agent.id),
                    label: agent.name,
                })),
            ]}
        />
    );
}
function NativeSelect({
    label,
    value,
    onChange,
    options,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    options: Array<{ value: string; label: string }>;
}) {
    return (
        <label className="space-y-1.5 text-sm font-medium">
            {label}
            <select
                className="min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm"
                value={value}
                onChange={(event) => onChange(event.target.value)}
            >
                {options.map((option) => (
                    <option
                        key={`${label}-${option.value}`}
                        value={option.value}
                    >
                        {option.label}
                    </option>
                ))}
            </select>
        </label>
    );
}
function ActiveToggle({
    checked,
    onChange,
}: {
    checked: boolean;
    onChange: (value: boolean) => void;
}) {
    return (
        <label className="flex min-h-11 items-center gap-2 text-sm font-medium">
            <input
                type="checkbox"
                checked={checked}
                onChange={(event) => onChange(event.target.checked)}
            />
            Active
        </label>
    );
}
function RuleChoices({
    label,
    values,
    selected,
    onChange,
}: {
    label: string;
    values: string[];
    selected: string[];
    onChange: (values: string[]) => void;
}) {
    return (
        <fieldset className="rounded-xl border border-border p-3">
            <legend className="px-1 text-sm font-medium">{label}</legend>
            <div className="mt-1 space-y-1">
                {values.map((value) => (
                    <CheckChoice
                        key={value}
                        label={labels(value)}
                        checked={selected.includes(value)}
                        onChange={(checked) =>
                            onChange(
                                checked
                                    ? [...selected, value]
                                    : selected.filter((item) => item !== value),
                            )
                        }
                    />
                ))}
            </div>
        </fieldset>
    );
}
function CheckChoice({
    label,
    checked,
    onChange,
}: {
    label: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
}) {
    return (
        <label className="flex min-h-11 items-center gap-2 rounded-md px-2 text-sm hover:bg-muted/50">
            <input
                type="checkbox"
                checked={checked}
                onChange={(event) => onChange(event.target.checked)}
            />
            {label}
        </label>
    );
}
function FormErrors({ errors }: { errors: Record<string, string> }) {
    const messages = Object.values(errors);
    return messages.length ? (
        <div
            role="alert"
            className="mt-4 rounded-xl border border-status-critical/30 bg-status-critical-bg p-3 text-sm text-status-critical"
        >
            <p className="font-semibold">
                Check the highlighted setup details.
            </p>
            <ul className="mt-1 list-disc pl-5">
                {messages.map((message) => (
                    <li key={message}>{message}</li>
                ))}
            </ul>
        </div>
    ) : null;
}
