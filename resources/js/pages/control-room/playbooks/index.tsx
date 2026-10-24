import { plainLabel } from '@/components/control-room/alert-record/alert-labels';
import { ControlRoomPage } from '@/components/control-room/control-room-page';
import {
    ControlRoomRowActions,
    type ControlRoomRowAction,
} from '@/components/control-room/control-room-row-actions';
import { PlaybookWizard } from '@/components/control-room/playbook-wizard';
import {
    TurnOffProcedureConfirm,
    turnOnProcedure as turnOn,
} from '@/components/control-room/procedure-turn-off-confirm';
import {
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
} from '@/components/page';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatRelative } from '@/lib/datetime';
import { Link, router } from '@inertiajs/react';
import { BookOpen, Eye, Layers, Plus, Power, ToggleRight, Zap } from 'lucide-react';
import { useState } from 'react';

// --- Types ---

interface PlaybookSummary {
    id: number;
    name: string;
    code: string | null;
    description: string | null;
    category: string;
    version: number;
    is_active: boolean;
    auto_attach: boolean;
    /** "Start on its own" is on but no alert type or severity is chosen, so it never starts on its own. */
    auto_start_missing_triggers: boolean;
    requires_approval: boolean;
    sla_acknowledge_minutes: number | null;
    sla_response_minutes: number | null;
    sla_resolution_minutes: number | null;
    steps_count: number;
    runs_count: number;
    last_run_at: string | null;
    created_at: string | null;
}

interface Props {
    playbooks: PlaybookSummary[];
    filters: {
        category?: string;
        is_active?: string;
    };
    categories: Record<string, string>;
    stepTypes: Record<string, string>;
    alertTypes: string[];
    can: {
        manage: boolean;
    };
}

type StatusFilter = 'all' | '1' | '0';

const BASE = '/control-room/playbooks';

/** The procedure page, opened on its edit wizard at "Starting and targets". */
const chooseTriggersHref = (id: number) => `${BASE}/${id}?edit=starting`;

const STATUS_OPTIONS = [
    { value: 'all', label: 'Available and off' },
    { value: '1', label: 'Available' },
    { value: '0', label: 'Off' },
];

function initialStatus(value: string | undefined): StatusFilter {
    if (value === '1' || value === 'true') return '1';
    if (value === '0' || value === 'false') return '0';
    return 'all';
}

/**
 * Control Room › Settings › Procedures — the step-by-step responses
 * operators follow for a type of alert. Anyone who can see Control Room can
 * read them; editors add, edit and turn them on or off.
 */
export default function PlaybooksIndex({
    playbooks,
    filters,
    categories,
    stepTypes,
    alertTypes,
    can,
}: Props) {
    const [status, setStatus] = useState<StatusFilter>(() =>
        initialStatus(filters.is_active),
    );
    const [search, setSearch] = useState('');
    const [turningOff, setTurningOff] = useState<PlaybookSummary | null>(null);

    // ?new=1 deep-links straight into the procedure wizard (house pattern).
    const [wizardOpen, setWizardOpen] = useState<boolean>(
        () =>
            can.manage &&
            typeof window !== 'undefined' &&
            new URLSearchParams(window.location.search).get('new') === '1',
    );

    const category = filters.category || 'all';
    // The server filters by category; on/off and search run on this list so
    // the header numbers stay true for the category in view. An old
    // ?is_active= link still arrives filtered, so the first change reloads
    // without it.
    const visit = (nextCategory: string) =>
        router.get(BASE, nextCategory === 'all' ? {} : { category: nextCategory }, {
            preserveState: true,
            preserveScroll: true,
        });
    const changeStatus = (next: StatusFilter) => {
        setStatus(next);
        if (filters.is_active !== undefined && filters.is_active !== '') visit(category);
    };

    const available = playbooks.filter((pb) => pb.is_active).length;
    const off = playbooks.length - available;
    const lastUsed = playbooks
        .filter((pb) => pb.last_run_at)
        .sort((a, b) => (b.last_run_at ?? '').localeCompare(a.last_run_at ?? ''))[0];

    const query = search.trim().toLowerCase();
    const shown = playbooks.filter(
        (pb) =>
            (status === 'all' || pb.is_active === (status === '1')) &&
            (!query ||
                [pb.name, pb.description, pb.code, categories[pb.category] ?? pb.category]
                    .filter(Boolean)
                    .some((text) => (text as string).toLowerCase().includes(query))),
    );
    const narrowed = status !== 'all' || query !== '';
    const inCategory = category !== 'all';

    const rowActions = (pb: PlaybookSummary): ControlRoomRowAction[] => [
        {
            key: 'open',
            label: 'Open procedure',
            icon: Eye,
            onSelect: () => router.visit(`${BASE}/${pb.id}`),
        },
        ...(can.manage && pb.auto_start_missing_triggers
            ? [
                  {
                      key: 'triggers',
                      label: 'Choose which alerts start it',
                      icon: Zap,
                      onSelect: () => router.visit(chooseTriggersHref(pb.id)),
                  },
              ]
            : []),
        ...(can.manage
            ? [
                  pb.is_active
                      ? {
                            key: 'off',
                            label: 'Turn off',
                            icon: Power,
                            onSelect: () => setTurningOff(pb),
                        }
                      : {
                            key: 'on',
                            label: 'Turn on',
                            icon: Power,
                            onSelect: () => turnOn(pb),
                        },
              ]
            : []),
    ];

    return (
        <ControlRoomPage
            headTitle="Procedures"
            header={{
                subline: 'The step-by-step responses operators follow for a type of alert',
                actions: (
                    <>
                        <PageHeaderSearch
                            value={search}
                            onChange={setSearch}
                            placeholder="Search procedures"
                        />
                        {can.manage ? (
                            <PageHeaderPrimaryButton icon={Plus} onClick={() => setWizardOpen(true)}>
                                Add response procedure
                            </PageHeaderPrimaryButton>
                        ) : null}
                    </>
                ),
                meters: (
                    <>
                        <PageHeaderMeterBlock
                            label="Procedures"
                            onClick={() => {
                                setStatus('all');
                                setSearch('');
                                if (inCategory || filters.is_active) visit('all');
                            }}
                            pressed={!narrowed && !inCategory}
                            ariaLabel="Show every procedure"
                        >
                            <PageHeaderMeterBig>{playbooks.length}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {inCategory
                                    ? `in ${categories[category] ?? category}`
                                    : 'set up'}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Available"
                            onClick={() => changeStatus('1')}
                            pressed={status === '1'}
                            ariaLabel="Show procedures operators can start"
                        >
                            <PageHeaderMeterBig>{available}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>can be started from an alert</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Off"
                            onClick={() => changeStatus('0')}
                            pressed={status === '0'}
                            ariaLabel="Show procedures that are off"
                        >
                            <PageHeaderMeterBig>{off}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>can&apos;t be started</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        {lastUsed?.last_run_at ? (
                            <PageHeaderMeterBlock
                                label="Last used"
                                href={`${BASE}/${lastUsed.id}?tab=runs`}
                                ariaLabel={`Open the run history of ${lastUsed.name}`}
                            >
                                <PageHeaderMeterBig>
                                    {formatRelative(lastUsed.last_run_at)}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>{lastUsed.name}</PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        ) : null}
                    </>
                ),
                filters: (
                    <>
                        <PageHeaderFilterSelect
                            icon={Layers}
                            label="All categories"
                            value={category}
                            options={[
                                { value: 'all', label: 'All categories' },
                                ...Object.entries(categories).map(([value, label]) => ({
                                    value,
                                    label,
                                })),
                            ]}
                            onChange={visit}
                        />
                        <PageHeaderFilterSelect
                            icon={ToggleRight}
                            label="Available and off"
                            value={status}
                            options={STATUS_OPTIONS}
                            onChange={(value) => changeStatus(value as StatusFilter)}
                        />
                    </>
                ),
            }}
        >
            <Card className="min-w-0 gap-0 overflow-hidden py-0">
                <div className="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
                    <div className="min-w-0">
                        <h2 className="text-section-title">Response procedures</h2>
                        <p className="text-subtle mt-0.5">
                            Read a procedure before starting it from an alert.
                        </p>
                    </div>
                    {playbooks.length > 0 ? (
                        <StatusBadge variant="neutral" className="tabular-nums">
                            {narrowed
                                ? `Showing ${shown.length} of ${playbooks.length}`
                                : `${playbooks.length} procedure${playbooks.length === 1 ? '' : 's'}`}
                        </StatusBadge>
                    ) : null}
                </div>
                {shown.length === 0 ? (
                    <div className="px-5 py-10">
                        <EmptyState
                            icon={BookOpen}
                            title={
                                playbooks.length === 0 && !inCategory
                                    ? 'No procedures yet'
                                    : 'No procedures match these filters'
                            }
                            description={
                                playbooks.length === 0 && !inCategory
                                    ? can.manage
                                        ? 'Add one so operators have the same steps to follow for each type of alert.'
                                        : 'Your Control Room lead adds the procedures operators follow.'
                                    : 'Change the search or filters to see the others.'
                            }
                        />
                    </div>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="pl-5">Procedure</TableHead>
                                <TableHead>Category</TableHead>
                                <TableHead className="text-right">Steps</TableHead>
                                <TableHead>How it starts</TableHead>
                                <TableHead>Last used</TableHead>
                                <TableHead>Version</TableHead>
                                <TableHead>{can.manage ? 'On' : 'State'}</TableHead>
                                <TableHead className="w-12 pr-3">
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {shown.map((pb) => (
                                <ProcedureRow
                                    key={pb.id}
                                    playbook={pb}
                                    category={categories[pb.category] ?? plainLabel(pb.category)}
                                    canManage={can.manage}
                                    items={rowActions(pb)}
                                    onTurnOff={() => setTurningOff(pb)}
                                />
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Card>

            {turningOff ? (
                <TurnOffProcedureConfirm
                    procedure={turningOff}
                    onClose={() => setTurningOff(null)}
                />
            ) : null}

            {/* Guided procedure builder — mounted only while open so every run starts fresh. */}
            {wizardOpen ? (
                <PlaybookWizard
                    open
                    onClose={() => setWizardOpen(false)}
                    categories={categories}
                    stepTypes={stepTypes}
                    alertTypes={alertTypes}
                />
            ) : null}
        </ControlRoomPage>
    );
}

// --- Procedure row ---

function ProcedureRow({
    playbook: pb,
    category,
    canManage,
    items,
    onTurnOff,
}: {
    playbook: PlaybookSummary;
    category: string;
    canManage: boolean;
    items: ControlRoomRowAction[];
    onTurnOff: () => void;
}) {
    const href = `${BASE}/${pb.id}`;
    return (
        <ControlRoomRowActions label={`Actions for ${pb.name}`} items={items}>
            {({ rowProps, overflowButton }) => (
                <TableRow
                    {...rowProps}
                    className="cursor-pointer outline-none focus-visible:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
                    onClick={(event) => {
                        // The name link, the switch and the kebab handle their own clicks.
                        if ((event.target as HTMLElement).closest('a,button')) return;
                        router.visit(href);
                    }}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter' && event.target === event.currentTarget) {
                            router.visit(href);
                        }
                    }}
                >
                    <TableCell className="pl-5">
                        <Link href={href} className="font-semibold text-foreground hover:underline">
                            {pb.name}
                        </Link>
                        {pb.description ? (
                            <span className="text-caption line-clamp-2 block max-w-[32rem]">
                                {pb.description}
                            </span>
                        ) : null}
                    </TableCell>
                    <TableCell className="text-subtle whitespace-nowrap">{category}</TableCell>
                    <TableCell className="text-right tabular-nums">{pb.steps_count}</TableCell>
                    <TableCell className="whitespace-nowrap">
                        {pb.auto_start_missing_triggers ? (
                            <>
                                <StatusBadge variant="warning" size="sm">
                                    Won&apos;t start on its own
                                </StatusBadge>
                                {canManage ? (
                                    <Link
                                        href={chooseTriggersHref(pb.id)}
                                        className="text-caption block font-medium text-primary hover:underline"
                                    >
                                        Choose which alerts start it
                                    </Link>
                                ) : (
                                    <span className="text-caption block">
                                        No alerts are chosen to start it
                                    </span>
                                )}
                            </>
                        ) : pb.auto_attach ? (
                            'On its own'
                        ) : (
                            'From an alert'
                        )}
                        {pb.requires_approval ? (
                            <span className="text-caption block">Needs sign-off</span>
                        ) : null}
                    </TableCell>
                    <TableCell className="whitespace-nowrap">
                        {pb.last_run_at ? (
                            <>
                                <span className="block">{formatRelative(pb.last_run_at)}</span>
                                <span className="text-caption block tabular-nums">
                                    {pb.runs_count} run{pb.runs_count === 1 ? '' : 's'}
                                </span>
                            </>
                        ) : (
                            <span className="text-subtle">Not used yet</span>
                        )}
                    </TableCell>
                    <TableCell className="text-subtle whitespace-nowrap tabular-nums">
                        Version {pb.version}
                    </TableCell>
                    <TableCell>
                        {canManage ? (
                            <span className="inline-flex items-center gap-2">
                                <Switch
                                    aria-label={`Procedure ${pb.name} on`}
                                    checked={pb.is_active}
                                    onCheckedChange={(on) => (on ? turnOn(pb) : onTurnOff())}
                                />
                                <span className="text-sm text-muted-foreground" aria-hidden="true">
                                    {pb.is_active ? 'On' : 'Off'}
                                </span>
                            </span>
                        ) : (
                            <StatusBadge variant={pb.is_active ? 'success' : 'neutral'} size="sm">
                                {pb.is_active ? 'Available' : 'Off'}
                            </StatusBadge>
                        )}
                    </TableCell>
                    <TableCell className="pr-3 text-right">{overflowButton}</TableCell>
                </TableRow>
            )}
        </ControlRoomRowActions>
    );
}
