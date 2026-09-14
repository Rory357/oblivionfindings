import {
    ProvisioningCommandDialog,
    type ProvisioningCommandField,
} from '@/components/it/provisioning-command-dialog';
import { provisioningApprovalRequestFields } from '@/components/it/provisioning-command-fields';
import {
    ProvisioningActions,
    ProvisioningFacts,
    ProvisioningHistory,
    ProvisioningProfileHeader,
    ProvisioningSection,
    ProvisioningTaskList,
    provisioningLabel,
    type ProvisioningEvent,
    type ProvisioningTask,
    type ProvisioningWorkflow,
} from '@/components/it/provisioning-workspace';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import type { SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface WorkflowDetail extends ProvisioningWorkflow {
    tasks: ProvisioningTask[];
    events: ProvisioningEvent[];
    /** Server verdict of the operations this actor may attempt right now. */
    actions?: string[];
    original_contract: {
        tasks?: {
            task_key: string;
            title: string;
            description?: string;
            stage: number;
        }[];
    } | null;
}
const reason: ProvisioningCommandField = {
    key: 'reason',
    label: 'Reason and next action',
    kind: 'textarea',
    required: true,
};
export default function ProvisioningWorkflowPage({
    actorId,
    workflow,
}: {
    actorId: number;
    workflow: WorkflowDetail;
}) {
    const currentActor = usePage<SharedData>().props.auth.user.id;
    const [visible, setVisible] = useState(true);
    const [tab, setTab] = useState('work');
    const [search, setSearch] = useState('');
    const [operation, setOperation] = useState<string | null>(null);
    const actions = !workflow.can_manage
        ? []
        : workflow.actions
          ? workflow.actions
          : [
                'assign',
                ...(!workflow.cancelled_at &&
                workflow.status !== 'completed' &&
                !['hr_onboarding', 'hr_offboarding'].includes(
                    workflow.source_type,
                )
                    ? ['reschedule']
                    : []),
                ...(!workflow.cancelled_at ? ['cancel'] : []),
                ...(workflow.tasks.some(
                    (task) =>
                        task.status === 'done' &&
                        !task.reversal_of_request_id &&
                        !workflow.tasks.some(
                            (candidate) =>
                                candidate.reversal_of_request_id === task.id,
                        ),
                )
                    ? ['reverse']
                    : []),
            ];
    const fields: ProvisioningCommandField[] =
        operation === 'assign'
            ? [
                  {
                      key: 'owner_user_id',
                      label: 'Workflow owner',
                      kind: 'agent',
                      required: true,
                  },
                  {
                      key: 'cover_user_id',
                      label: 'Absence cover',
                      kind: 'agent',
                      required: true,
                  },
                  reason,
              ]
            : operation === 'reschedule'
              ? [
                    {
                        key: 'effective_date',
                        label: 'New effective date',
                        kind: 'date',
                        required: true,
                    },
                    reason,
                ]
              : operation === 'cancel'
                ? [
                      reason,
                      {
                          key: 'create_reversals',
                          label: 'Create explicit reversal tasks for completed work',
                          kind: 'checkbox',
                          help: 'Original completed work stays recorded. Reversal tasks require new approval and evidence.',
                      },
                  ]
                : operation === 'request_approval'
                  ? provisioningApprovalRequestFields
                  : [reason];
    const description =
        operation === 'reschedule'
            ? 'Open task targets move with the effective date. Their approvals are withdrawn for fresh review. Completed evidence keeps its recorded dates.'
            : operation === 'cancel' || operation === 'reverse'
              ? 'Completed actions remain recorded. Reversal tasks require real corrective work, new approval and evidence; creating them does not change external accounts or reassign equipment.'
              : operation === 'resume'
                ? 'Cancelled original tasks reopen without approval and need a fresh review. Completed work keeps its evidence and is never repeated. Resuming is refused while corrective work exists.'
                : operation === 'request_approval'
                  ? 'One request covers every open task in this workflow that still needs approval. Both approvers must be eligible for every task; the requester and beneficiary can never approve their own work.'
                  : operation === 'approve' || operation === 'reject'
                    ? 'Only the tasks where you are the currently responsible approver are decided. A rejection needs the reason below.'
                    : 'Choose a current eligible owner and a distinct cover person. Existing task assignments remain explicit.';
    if (!visible || currentActor !== actorId)
        return (
            <AppLayout>
                <Head title="Provisioning access changed" />
                <p role="alert" className="p-6">
                    This workflow is hidden because your account or access
                    changed.
                </p>
                <Button onClick={() => router.visit('/it/provisioning')}>
                    Open provisioning with current access
                </Button>
            </AppLayout>
        );
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'IT & Support', href: '/it' },
                {
                    title: 'Provisioning',
                    href: '/it/provisioning?view=workflows',
                },
                { title: 'Workflow', href: workflow.href },
            ]}
        >
            <Head
                title={
                    workflow.employee.name +
                    ' · ' +
                    provisioningLabel(workflow.lifecycle_type)
                }
            />
            <ProvisioningProfileHeader
                workflow
                title={
                    workflow.employee.name +
                    ' · ' +
                    provisioningLabel(workflow.lifecycle_type)
                }
                status={workflow.status}
                subline={
                    workflow.template.name +
                    ' · Original version ' +
                    (workflow.template.version ?? 'unavailable')
                }
                tab={tab}
                onTab={setTab}
                search={search}
                onSearch={setSearch}
                meters={[
                    {
                        label: 'Completed work',
                        value:
                            workflow.progress.done +
                            ' / ' +
                            workflow.progress.total,
                        caption: 'Tasks within your access',
                        tab: 'work',
                    },
                    {
                        label: 'Failed tasks',
                        value: workflow.progress.failed,
                        caption: 'Review before retrying',
                        tab: 'work',
                    },
                    {
                        label: 'Effective date',
                        value: formatDateOnly(workflow.effective_at),
                        caption: 'Current workflow target',
                        tab: 'details',
                    },
                    {
                        label: 'History',
                        value: workflow.events.length,
                        caption: 'Recorded workflow decisions',
                        tab: 'history',
                    },
                ]}
            />
            <main
                id="provisioning-panel"
                role="tabpanel"
                aria-labelledby={'provisioning-tab-' + tab}
                className="space-y-5 py-5"
            >
                <ProvisioningSection title="Workflow responsibility">
                    <p className="text-sm">
                        {workflow.owner
                            ? workflow.owner.name +
                              ' owns this workflow. Cover: ' +
                              (workflow.cover?.name ?? 'Needs attention')
                            : 'Workflow responsibility needs attention. Assign an eligible owner and distinct cover.'}
                    </p>
                    <ProvisioningActions
                        actions={actions}
                        onAction={setOperation}
                    />
                    {['hr_onboarding', 'hr_offboarding'].includes(
                        workflow.source_type,
                    ) && (
                        <div className="mt-3 space-y-2 text-sm">
                            <p>
                                The HR checklist owns the effective date and
                                source status.
                            </p>
                            {workflow.source_href && (
                                <Button asChild variant="outline">
                                    <Link href={workflow.source_href}>
                                        Open HR checklist
                                    </Link>
                                </Button>
                            )}
                        </div>
                    )}
                </ProvisioningSection>
                {tab === 'work' ? (
                    <ProvisioningTaskList
                        tasks={workflow.tasks}
                        search={search}
                    />
                ) : tab === 'history' ? (
                    <ProvisioningHistory
                        events={workflow.events}
                        search={search}
                    />
                ) : (
                    <>
                        <ProvisioningSection title="Workflow details">
                            <ProvisioningFacts
                                search={search}
                                facts={[
                                    {
                                        label: 'Employee',
                                        value: workflow.employee.name,
                                    },
                                    {
                                        label: 'Lifecycle',
                                        value: provisioningLabel(
                                            workflow.lifecycle_type,
                                        ),
                                    },
                                    {
                                        label: 'Original effective date',
                                        value: formatDateOnly(
                                            workflow.original_effective_at ??
                                                workflow.effective_at,
                                        ),
                                    },
                                    {
                                        label: 'Current effective date',
                                        value: formatDateOnly(
                                            workflow.effective_at,
                                        ),
                                    },
                                    {
                                        label: 'Original template',
                                        value: workflow.template.name,
                                    },
                                    {
                                        label: 'Original version',
                                        value: String(
                                            workflow.template.version ??
                                                'Unavailable',
                                        ),
                                    },
                                    {
                                        label: 'Source',
                                        value: provisioningLabel(
                                            workflow.source_type,
                                        ),
                                    },
                                    {
                                        label: 'Cancellation',
                                        value: workflow.cancelled_at
                                            ? formatDateTime(
                                                  workflow.cancelled_at,
                                              ) +
                                              ' · ' +
                                              workflow.cancellation_reason
                                            : 'Active workflow history',
                                    },
                                ]}
                            />
                        </ProvisioningSection>
                        {workflow.tasks.length > 0 && (
                            <ProvisioningSection title="Task order and prerequisites">
                                <p className="text-sm text-muted-foreground">
                                    Tasks run by stage. A task cannot be
                                    fulfilled until every prerequisite is
                                    complete; reversal tasks run in reverse
                                    order.
                                </p>
                                <ol className="divide-y divide-border">
                                    {[...workflow.tasks]
                                        .sort(
                                            (a, b) =>
                                                Number(
                                                    a.reversal_of_request_id !==
                                                        null,
                                                ) -
                                                    Number(
                                                        b.reversal_of_request_id !==
                                                            null,
                                                    ) ||
                                                a.stage - b.stage ||
                                                a.id - b.id,
                                        )
                                        .map((task) => {
                                            const prerequisites = (
                                                task.dependency_request_ids ??
                                                []
                                            )
                                                .map((id) =>
                                                    workflow.tasks.find(
                                                        (candidate) =>
                                                            candidate.id === id,
                                                    ),
                                                )
                                                .filter(
                                                    (
                                                        candidate,
                                                    ): candidate is ProvisioningTask =>
                                                        !!candidate,
                                                );
                                            const blocked = prerequisites.some(
                                                (candidate) =>
                                                    candidate.status !== 'done',
                                            );
                                            return (
                                                <li
                                                    key={task.id}
                                                    className="flex flex-wrap items-center justify-between gap-2 py-3 text-sm"
                                                >
                                                    <span className="min-w-0">
                                                        <Link
                                                            className="font-medium text-primary underline"
                                                            href={task.href}
                                                        >
                                                            Stage {task.stage} ·{' '}
                                                            {task.title}
                                                        </Link>
                                                        {task.reversal_of_request_id && (
                                                            <span className="text-muted-foreground">
                                                                {' '}
                                                                · corrective
                                                                work
                                                            </span>
                                                        )}
                                                        {prerequisites.length >
                                                            0 && (
                                                            <span className="block text-muted-foreground">
                                                                After:{' '}
                                                                {prerequisites
                                                                    .map(
                                                                        (
                                                                            candidate,
                                                                        ) =>
                                                                            candidate.title,
                                                                    )
                                                                    .join(', ')}
                                                            </span>
                                                        )}
                                                    </span>
                                                    <span className="flex flex-wrap gap-2">
                                                        {blocked &&
                                                            ![
                                                                'done',
                                                                'cancelled',
                                                            ].includes(
                                                                task.status,
                                                            ) && (
                                                                <StatusBadge
                                                                    status="pending"
                                                                    label="Blocked by prerequisite"
                                                                />
                                                            )}
                                                        <StatusBadge
                                                            status={task.status}
                                                            label={provisioningLabel(
                                                                task.status,
                                                            )}
                                                        />
                                                    </span>
                                                </li>
                                            );
                                        })}
                                </ol>
                            </ProvisioningSection>
                        )}
                        {workflow.original_contract?.tasks && (
                            <ProvisioningSection title="Original instructions">
                                {workflow.original_contract.tasks
                                    .filter((task) =>
                                        (task.title + ' ' + task.description)
                                            .toLocaleLowerCase()
                                            .includes(
                                                search.toLocaleLowerCase(),
                                            ),
                                    )
                                    .map((task) => (
                                        <div
                                            key={task.task_key}
                                            className="space-y-1 border-b pb-3 last:border-0"
                                        >
                                            <h3 className="text-sm font-semibold">
                                                Stage {task.stage} ·{' '}
                                                {task.title}
                                            </h3>
                                            <p className="text-sm whitespace-pre-wrap text-muted-foreground">
                                                {task.description}
                                            </p>
                                        </div>
                                    ))}
                            </ProvisioningSection>
                        )}
                    </>
                )}
            </main>
            {operation && (
                <ProvisioningCommandDialog
                    key={operation}
                    context={{
                        actorId,
                        kind: 'workflow',
                        targetId: workflow.id,
                        operation,
                    }}
                    version={workflow.version}
                    title={
                        workflow.employee.name +
                        ' · ' +
                        provisioningLabel(workflow.lifecycle_type)
                    }
                    fields={fields}
                    description={description}
                    review={[
                        { label: 'Employee', value: workflow.employee.name },
                        {
                            label: 'Original template',
                            value: workflow.template.name,
                        },
                        {
                            label: 'Completed work retained',
                            value: String(workflow.progress.done),
                        },
                    ]}
                    onClose={() => setOperation(null)}
                    onDenied={() => {
                        setOperation(null);
                        setVisible(false);
                    }}
                />
            )}
        </AppLayout>
    );
}
