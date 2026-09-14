import { ProvisioningBulkDialog } from '@/components/it/provisioning-bulk-dialog';
import { ProvisioningCommandDialog } from '@/components/it/provisioning-command-dialog';
import {
    taskCommandDescription,
    taskCommandFields,
} from '@/components/it/provisioning-command-fields';
import { ProvisioningLaunchDialog } from '@/components/it/provisioning-launch-dialog';
import { ProvisioningManualDialog } from '@/components/it/provisioning-manual-dialog';
import {
    provisioningActionLabel,
    provisioningLabel,
    provisioningTaskRow,
    provisioningTone,
    type ProvisioningTask,
    type ProvisioningWorkflow,
} from '@/components/it/provisioning-workspace';
import {
    SpecialistRecordList,
    type SpecialistListItem,
} from '@/components/it/specialist-record-list';
import {
    PROVISIONING_BULK_OPERATIONS,
    hasProvisioningBulkReference,
    type ProvisioningBulkOperation,
} from '@/components/it/use-provisioning-bulk-command';
import type { MenuItem } from '@/components/lists/entity-menu';
import {
    GroupPillRail,
    TabSearchPalette,
    TierTwoTabs,
    type GroupedProfileNavGroup,
} from '@/components/page/grouped-profile-nav';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageHeaderViewToggle,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly } from '@/lib/datetime';
import type { SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ClipboardList,
    FileText,
    GitBranch,
    LayoutGrid,
    List,
    Package,
    Plus,
    RefreshCw,
    ShieldCheck,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface Props {
    actorId: number;
    canManage: boolean;
    storageReady: boolean;
    filters: {
        q?: string;
        status?: string;
        lifecycle_type?: string;
        view: 'tasks' | 'workflows' | 'templates' | 'approvals';
        list_view?: string;
    };
    summary: {
        open: number;
        awaiting_approval: number;
        my_decisions?: number;
        failed: number;
        overdue: number;
    };
    records: {
        data: (ProvisioningTask | ProvisioningWorkflow)[];
        total: number;
        links: { url: string | null; label: string; active: boolean }[];
    } | null;
    templates: {
        id: number;
        name: string;
        lifecycle_type: string;
        version: number;
        published_version: number | null;
        href: string;
    }[];
}
export default function ProvisioningIndex(props: Props) {
    const { auth } = usePage<SharedData>().props;
    return (
        <ProvisioningRegister
            key={auth.user.id}
            {...props}
            currentActorId={auth.user.id}
        />
    );
}
function ProvisioningRegister({
    actorId,
    currentActorId,
    canManage,
    storageReady,
    filters,
    summary,
    records,
    templates,
}: Props & { currentActorId: number }) {
    const [selectedIds, setSelectedIds] = useState<Set<number>>(
        () => new Set(),
    );
    const [bulk, setBulk] = useState<
        | { operation: ProvisioningBulkOperation; tasks: ProvisioningTask[] }
        | 'recover'
        | null
    >(null);
    const [hasBulkRecovery, setHasBulkRecovery] = useState(() =>
        hasProvisioningBulkReference(actorId),
    );
    const [search, setSearch] = useState(filters.q ?? '');
    const [find, setFind] = useState(false);
    const [launch, setLaunch] = useState(false);
    const [manual, setManual] = useState(false);
    const [visible, setVisible] = useState(true);
    const [refreshing, setRefreshing] = useState(false);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const { url } = usePage();
    useEffect(() => {
        setSelectedIds(new Set());
    }, [url, records]);
    const isTaskView = filters.view === 'tasks' || filters.view === 'approvals';
    const [rowCommand, setRowCommand] = useState<{
        task: ProvisioningTask;
        operation: string;
    } | null>(null);
    const selectedTasks = isTaskView
        ? ((records?.data as ProvisioningTask[]) ?? []).filter((task) =>
              selectedIds.has(task.id),
          )
        : [];
    const bulkOperations: ProvisioningBulkOperation[] =
        PROVISIONING_BULK_OPERATIONS;
    useEffect(() => {
        if (timer.current) clearTimeout(timer.current);
        setSearch(filters.q ?? '');
        return () => {
            if (timer.current) clearTimeout(timer.current);
        };
    }, [url, filters.q]);
    const navigate = (changes: Record<string, string | undefined>) => {
        if (timer.current) clearTimeout(timer.current);
        const parameters = new URL(url, 'http://local.invalid').searchParams;
        if (Object.keys(changes).some((key) => key !== 'list_view'))
            parameters.delete('page');
        for (const [key, value] of Object.entries(changes)) {
            if (!value || value === 'all') parameters.delete(key);
            else parameters.set(key, value);
        }
        router.get('/it/provisioning', Object.fromEntries(parameters), {
            preserveState: true,
            preserveScroll: true,
        });
    };
    const groups: GroupedProfileNavGroup[] = [
        {
            key: 'work',
            label: 'Work',
            icon: Package,
            tabs: [
                {
                    key: 'tasks',
                    label: 'Work tasks',
                    icon: ClipboardList,
                    href: '/it/provisioning?view=tasks',
                },
                {
                    key: 'workflows',
                    label: 'Staff workflows',
                    icon: GitBranch,
                    href: '/it/provisioning?view=workflows',
                },
                {
                    key: 'approvals',
                    label: 'Approvals',
                    icon: ShieldCheck,
                    href: '/it/provisioning?view=approvals',
                },
                ...(canManage
                    ? [
                          {
                              key: 'templates',
                              label: 'Templates',
                              icon: FileText,
                              href: '/it/provisioning?view=templates',
                          },
                      ]
                    : []),
            ],
        },
    ];
    for (const group of groups)
        for (const entry of group.tabs) {
            const parameters = new URL(url, 'http://local.invalid')
                .searchParams;
            parameters.delete('page');
            parameters.delete('status');
            parameters.set('view', entry.key);
            entry.href = '/it/provisioning?' + parameters.toString();
        }
    const meters = [
        {
            key: 'open',
            label: 'Open work',
            value: summary.open,
            href: '/it/provisioning?view=tasks&status=open',
        },
        {
            key: 'awaiting_approval',
            label: 'Waiting for approval',
            value: summary.awaiting_approval,
            href: '/it/provisioning?view=approvals',
        },
        {
            key: 'my_decisions',
            label: 'Your decisions',
            value: summary.my_decisions ?? 0,
            href: '/it/provisioning?view=approvals&status=mine',
        },
        {
            key: 'failed',
            label: 'Failed work',
            value: summary.failed,
            href: '/it/provisioning?view=tasks&status=failed',
        },
        {
            key: 'overdue',
            label: 'Overdue',
            value: summary.overdue,
            href: '/it/provisioning?view=tasks&status=overdue',
        },
    ];
    if (!visible || actorId !== currentActorId)
        return (
            <AppLayout>
                <p role="alert" className="p-6">
                    Provisioning details are hidden because your account or
                    access changed.
                </p>
                <Button onClick={() => router.visit('/it/provisioning')}>
                    Open with current access
                </Button>
            </AppLayout>
        );
    const taskRows = isTaskView
        ? ((records?.data as ProvisioningTask[]) ?? []).map(provisioningTaskRow)
        : ((records?.data as ProvisioningWorkflow[]) ?? []).map((workflow) => ({
              id: workflow.id,
              reference: provisioningLabel(workflow.lifecycle_type),
              title: workflow.employee.name,
              href: workflow.href,
              state: {
                  label: provisioningLabel(workflow.status),
                  tone: provisioningTone(workflow.status),
              },
              priority: 'Normal',
              impact: workflow.template.name,
              owner: workflow.owner?.name,
              facts: [
                  {
                      label: 'Effective',
                      value: formatDateOnly(workflow.effective_at),
                  },
                  {
                      label: 'Completed',
                      value:
                          workflow.progress.done +
                          ' of ' +
                          workflow.progress.total,
                  },
                  {
                      label: 'Owner',
                      value:
                          workflow.owner?.name ??
                          'Responsibility needs attention',
                  },
              ],
          }));
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'IT & Support', href: '/it' },
                { title: 'Provisioning', href: '/it/provisioning' },
            ]}
        >
            <Head title="Provisioning" />
            <main className="min-w-0 space-y-5">
                <PageHeader
                    icon={Package}
                    title="Provisioning"
                    subline="Staff joiner, mover and leaver work, approvals and fulfilment evidence"
                    actions={
                        <>
                            <PageHeaderSearch
                                value={search}
                                placeholder="Search provisioning…"
                                onChange={(value) => {
                                    setSearch(value);
                                    if (timer.current)
                                        clearTimeout(timer.current);
                                    timer.current = setTimeout(
                                        () =>
                                            navigate({
                                                q: value.trim() || undefined,
                                            }),
                                        350,
                                    );
                                }}
                            />
                            <PageHeaderGlassButton
                                icon={RefreshCw}
                                disabled={refreshing}
                                onClick={() => {
                                    setRefreshing(true);
                                    router.reload({
                                        onFinish: () => setRefreshing(false),
                                    });
                                }}
                            >
                                {refreshing ? 'Refreshing…' : 'Refresh'}
                            </PageHeaderGlassButton>
                            {canManage && storageReady && (
                                <PageHeaderPrimaryButton
                                    icon={Plus}
                                    onClick={() => setLaunch(true)}
                                >
                                    Start workflow
                                </PageHeaderPrimaryButton>
                            )}
                        </>
                    }
                    meters={meters.map((meter) => (
                        <PageHeaderMeterBlock
                            key={meter.key}
                            label={meter.label}
                            href={meter.href}
                        >
                            <PageHeaderMeterBig>
                                {meter.value}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                Work within your access
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    ))}
                    filters={
                        <>
                            <PageHeaderViewToggle
                                value={
                                    filters.list_view === 'cards'
                                        ? 'cards'
                                        : 'table'
                                }
                                onChange={(value) =>
                                    navigate({ list_view: value })
                                }
                                ariaLabel="List layout"
                                options={[
                                    {
                                        value: 'table',
                                        label: 'Table',
                                        icon: List,
                                    },
                                    {
                                        value: 'cards',
                                        label: 'Cards',
                                        icon: LayoutGrid,
                                    },
                                ]}
                            />
                            <PageHeaderFilterSelect
                                label="Lifecycle"
                                value={filters.lifecycle_type ?? 'all'}
                                allValue="all"
                                options={['joiner', 'mover', 'leaver'].map(
                                    (value) => ({
                                        value,
                                        label: provisioningLabel(value),
                                    }),
                                )}
                                onChange={(value) =>
                                    navigate({ lifecycle_type: value })
                                }
                            />
                            {filters.view !== 'templates' && (
                                <PageHeaderFilterSelect
                                    label="Status"
                                    value={filters.status ?? 'all'}
                                    allValue="all"
                                    options={(filters.view === 'approvals'
                                        ? ['mine', 'waiting', 'unrequested']
                                        : isTaskView
                                          ? [
                                                'open',
                                                'pending',
                                                'in_progress',
                                                'failed',
                                                'done',
                                                'cancelled',
                                                'awaiting_approval',
                                                'overdue',
                                            ]
                                          : [
                                                'pending',
                                                'in_progress',
                                                'partially_failed',
                                                'completed',
                                                'cancelled',
                                            ]
                                    ).map((value) => ({
                                        value,
                                        label: provisioningLabel(value),
                                    }))}
                                    onChange={(value) =>
                                        navigate({ status: value })
                                    }
                                />
                            )}
                        </>
                    }
                    rail={
                        <GroupPillRail
                            groups={groups}
                            openGroup="work"
                            activeTab={filters.view}
                            onOpenGroup={(_, key) =>
                                navigate({ view: key, status: undefined })
                            }
                            onSearch={() => setFind(true)}
                            testIdPrefix="provisioning-register"
                            ariaLabel="Provisioning workspaces"
                        />
                    }
                />
                <TierTwoTabs
                    tabs={groups[0].tabs}
                    activeTab={filters.view}
                    onTab={(key) => navigate({ view: key, status: undefined })}
                    testIdPrefix="provisioning-register"
                    ariaLabel="Provisioning registers"
                    renderLink={(entry, className, inner, accessibility) => (
                        <Link
                            key={entry.key}
                            href={entry.href ?? '/it/provisioning'}
                            className={className}
                            {...accessibility}
                        >
                            {inner}
                        </Link>
                    )}
                />
                {!storageReady && (
                    <p
                        role="status"
                        className="rounded-lg border border-border bg-muted/30 p-4 text-sm"
                    >
                        Provisioning history setup is incomplete. Existing work
                        remains visible. Complete the reviewed database update
                        before using workflow commands.
                    </p>
                )}
                {canManage &&
                    storageReady &&
                    (hasBulkRecovery ||
                        (isTaskView && selectedTasks.length > 0)) && (
                        <div
                            className="flex flex-wrap items-center gap-2"
                            aria-label="Selected task actions"
                        >
                            {hasBulkRecovery ? (
                                <Button
                                    variant="outline"
                                    onClick={() => setBulk('recover')}
                                >
                                    Check bulk outcomes
                                </Button>
                            ) : (
                                <>
                                    <span className="text-sm">
                                        {selectedTasks.length} of 20 tasks
                                        selected
                                    </span>
                                    {bulkOperations.map((operation) => (
                                        <Button
                                            key={operation}
                                            variant="outline"
                                            disabled={
                                                selectedTasks.length === 0 ||
                                                !selectedTasks.every((task) =>
                                                    task.readiness.actions.includes(
                                                        operation,
                                                    ),
                                                )
                                            }
                                            onClick={() => {
                                                if (
                                                    hasProvisioningBulkReference(
                                                        actorId,
                                                    )
                                                ) {
                                                    setHasBulkRecovery(true);
                                                    setBulk('recover');
                                                } else
                                                    setBulk({
                                                        operation,
                                                        tasks: selectedTasks,
                                                    });
                                            }}
                                        >
                                            {(provisioningActionLabel[
                                                operation
                                            ] ?? provisioningLabel(operation)) +
                                                ' · selected'}
                                        </Button>
                                    ))}
                                    <Button
                                        variant="ghost"
                                        onClick={() =>
                                            setSelectedIds(new Set())
                                        }
                                    >
                                        Clear selection
                                    </Button>
                                </>
                            )}
                        </div>
                    )}
                {filters.view === 'templates' ? (
                    <>
                        <div className="flex items-center justify-between">
                            <h2 className="text-lg font-semibold">
                                Workflow templates
                            </h2>
                            <Button asChild variant="outline">
                                <Link href="/it/setup?tab=provisioning">
                                    Author templates
                                </Link>
                            </Button>
                        </div>
                        <SpecialistRecordList
                            title="Templates"
                            icon={FileText}
                            total={templates.length}
                            links={[]}
                            rows={templates.map((template) => ({
                                id: template.id,
                                reference: provisioningLabel(
                                    template.lifecycle_type,
                                ),
                                title: template.name,
                                href: template.href,
                                state: {
                                    label: template.published_version
                                        ? 'Published'
                                        : 'Draft',
                                    tone: template.published_version
                                        ? 'success'
                                        : 'neutral',
                                },
                                priority: 'Normal',
                                impact: 'Version ' + template.version,
                                facts: [
                                    {
                                        label: 'Published version',
                                        value: template.published_version
                                            ? String(template.published_version)
                                            : 'Not published',
                                    },
                                ],
                            }))}
                        />
                    </>
                ) : (
                    <SpecialistRecordList
                        title={
                            filters.view === 'approvals'
                                ? 'Approval queue'
                                : isTaskView
                                  ? 'Work tasks'
                                  : 'Staff workflows'
                        }
                        icon={
                            filters.view === 'approvals'
                                ? ShieldCheck
                                : isTaskView
                                  ? ClipboardList
                                  : GitBranch
                        }
                        selection={
                            canManage && storageReady && isTaskView
                                ? {
                                      keys: selectedIds,
                                      labelFor: (row) =>
                                          'Select ' + row.reference,
                                      disabled: bulk !== null,
                                      canSelect: (row) => {
                                          const task = (
                                              records?.data as
                                                  | ProvisioningTask[]
                                                  | undefined
                                          )?.find((item) => item.id === row.id);
                                          return (
                                              !!task &&
                                              bulkOperations.some((operation) =>
                                                  task.readiness.actions.includes(
                                                      operation,
                                                  ),
                                              ) &&
                                              (selectedIds.has(row.id) ||
                                                  selectedIds.size < 20)
                                          );
                                      },
                                      onToggle: (row, checked) =>
                                          setSelectedIds((current) => {
                                              const updated = new Set(current);
                                              if (checked && updated.size < 20)
                                                  updated.add(row.id);
                                              else if (!checked)
                                                  updated.delete(row.id);
                                              return updated;
                                          }),
                                  }
                                : undefined
                        }
                        rows={taskRows}
                        total={records?.total ?? 0}
                        links={records?.links ?? []}
                        {...(isTaskView && canManage && storageReady
                            ? {
                                  extraActions: (row: SpecialistListItem) => {
                                      const task = (
                                          records?.data as
                                              | ProvisioningTask[]
                                              | undefined
                                      )?.find((item) => item.id === row.id);
                                      if (!task) return [];
                                      return task.readiness.actions.map(
                                          (action): MenuItem => ({
                                              label:
                                                  provisioningActionLabel[
                                                      action
                                                  ] ??
                                                  provisioningLabel(action),
                                              icon: ShieldCheck,
                                              onClick: () =>
                                                  action === 'fulfil'
                                                      ? router.visit(task.href)
                                                      : setRowCommand({
                                                            task,
                                                            operation: action,
                                                        }),
                                          }),
                                      );
                                  },
                              }
                            : {})}
                    />
                )}
            </main>
            {rowCommand && (
                <ProvisioningCommandDialog
                    key={rowCommand.task.id + ':' + rowCommand.operation}
                    context={{
                        actorId,
                        kind: 'request',
                        targetId: rowCommand.task.id,
                        operation: rowCommand.operation,
                    }}
                    version={rowCommand.task.version}
                    title={rowCommand.task.title}
                    description={taskCommandDescription(rowCommand.operation)}
                    fields={taskCommandFields(
                        rowCommand.task,
                        rowCommand.operation,
                    )}
                    review={[
                        {
                            label: 'Employee',
                            value: rowCommand.task.employee.name,
                        },
                        { label: 'Task', value: rowCommand.task.title },
                        {
                            label: 'Current state',
                            value: provisioningLabel(rowCommand.task.status),
                        },
                    ]}
                    onClose={() => {
                        setRowCommand(null);
                        router.reload();
                    }}
                    onDenied={() => {
                        setRowCommand(null);
                        setVisible(false);
                    }}
                />
            )}
            <TabSearchPalette
                open={find}
                onClose={() => setFind(false)}
                groups={groups}
                onTab={(key) => {
                    setFind(false);
                    navigate({ view: key, status: undefined });
                }}
                testIdPrefix="provisioning-register"
                searchLabel="Find a provisioning workspace"
            />
            {launch && (
                <ProvisioningLaunchDialog
                    actorId={actorId}
                    onClose={() => setLaunch(false)}
                    onDenied={() => {
                        setLaunch(false);
                        setVisible(false);
                    }}
                />
            )}
            {canManage && storageReady && (
                <div className="pb-5">
                    <Button variant="outline" onClick={() => setManual(true)}>
                        New individual work task
                    </Button>
                </div>
            )}
            {bulk !== null && (
                <ProvisioningBulkDialog
                    actorId={actorId}
                    {...(bulk === 'recover' ? {} : { selection: bulk })}
                    onClose={() => {
                        setBulk(null);
                        setSelectedIds(new Set());
                        setHasBulkRecovery(
                            hasProvisioningBulkReference(actorId),
                        );
                        router.reload();
                    }}
                    onDenied={() => {
                        setBulk(null);
                        setSelectedIds(new Set());
                        setVisible(false);
                    }}
                />
            )}
            {manual && (
                <ProvisioningManualDialog
                    actorId={actorId}
                    onClose={() => setManual(false)}
                    onDenied={() => {
                        setManual(false);
                        setVisible(false);
                    }}
                />
            )}
        </AppLayout>
    );
}
