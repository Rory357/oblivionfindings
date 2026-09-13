import {
    ProvisioningCommandDialog,
    type ProvisioningCommandField,
} from '@/components/it/provisioning-command-dialog';
import {
    ProvisioningSubmittedFiles,
    type ProvisioningAttachment,
} from '@/components/it/provisioning-request-files';
import {
    ProvisioningActions,
    ProvisioningFacts,
    ProvisioningHistory,
    ProvisioningProfileHeader,
    ProvisioningSection,
    provisioningLabel,
    type ProvisioningEvent,
    type ProvisioningTask,
} from '@/components/it/provisioning-workspace';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import type { SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface TaskDetail extends ProvisioningTask {
    instructions: string | null;
    evidence_summary: string | null;
    external_ref: string | null;
    failure_reason: string | null;
    fulfilment_mode: string | null;
    canonical_target_type: string | null;
    canonical_target_id: number | null;
    canonical_target: {
        id: number;
        label: string;
        href: string;
        type: string;
    } | null;
    fulfilled_at: string | null;
    approval_expires_at: string | null;
    approval_requested_at: string | null;
    primary_approver_user_id: number | null;
    cover_approver_user_id: number | null;
    dependencies: { id: number; title: string; status: string; href: string }[];
    events: ProvisioningEvent[];
    attachments: ProvisioningAttachment[];
    linked_tickets: { title: string; href: string }[];
}
const reason: ProvisioningCommandField = {
    key: 'reason',
    label: 'Reason and next action',
    kind: 'textarea',
    required: true,
};

function commandFields(
    task: TaskDetail,
    operation: string,
): ProvisioningCommandField[] {
    if (operation === 'assign')
        return [
            {
                key: 'assigned_to_user_id',
                label: 'Task assignee',
                kind: 'agent',
                required: true,
            },
            reason,
        ];
    if (operation === 'request_approval')
        return [
            {
                key: 'primary_approver_user_id',
                label: 'Primary approver',
                kind: 'agent',
                required: true,
            },
            {
                key: 'cover_approver_user_id',
                label: 'Absence cover',
                kind: 'agent',
                required: true,
            },
            {
                key: 'approval_expires_on',
                label: 'Approval deadline (NZ date)',
                kind: 'date',
                required: true,
                help: 'Approval must be decided by the end of this date in New Zealand.',
            },
            reason,
        ];
    if (operation === 'fulfil')
        return [
            ...(!task.canonical_target_id &&
            ['account', 'access', 'equipment'].includes(task.type)
                ? [
                      {
                          key: 'canonical_target_type',
                          label: 'Link an existing canonical record (optional)',
                          kind: 'select' as const,
                          options:
                              task.type === 'equipment'
                                  ? ['asset_assignment', 'device_assignment']
                                  : ['identity'],
                          help:
                              task.type === 'equipment'
                                  ? 'Choose the employee’s actual assignment. Recovery releases that exact assignment.'
                                  : 'Only your own linked sign-in account is available here. For another employee, record the actual external work reference below.',
                      },
                      {
                          key: 'canonical_target_id',
                          label: 'Existing account or assignment',
                          kind: 'target' as const,
                      },
                  ]
                : []),
            {
                key: 'external_ref',
                label: 'Account, access or work reference',
                kind: 'text',
                required:
                    task.readiness.manual_evidence &&
                    ['account', 'access'].includes(task.type),
                help: 'Use the actual work reference. Do not enter passwords, tokens or recovery codes.',
            },
            {
                key: 'evidence_summary',
                label: 'Work performed and verification evidence',
                kind: 'textarea',
                required: true,
                help: task.readiness.manual_evidence
                    ? 'Record what you did, when and how you checked it. This records manual work; it does not change an external account.'
                    : 'Recording completion releases the original canonical assignment after current access and ownership checks.',
            },
        ];
    return [reason];
}

export default function ProvisioningTaskPage({
    actorId,
    task,
}: {
    actorId: number;
    task: TaskDetail;
}) {
    const currentActor = usePage<SharedData>().props.auth.user.id;
    const [visible, setVisible] = useState(true);
    const [tab, setTab] = useState('details');
    const [search, setSearch] = useState('');
    const [operation, setOperation] = useState<string | null>(null);
    if (!visible || currentActor !== actorId)
        return (
            <AppLayout>
                <Head title="Provisioning access changed" />
                <p role="alert" className="p-6">
                    This work is hidden because your account or access changed.
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
                { title: 'Provisioning', href: '/it/provisioning' },
                { title: task.reference, href: task.href },
            ]}
        >
            <Head title={task.reference + ' · ' + task.title} />
            <ProvisioningProfileHeader
                title={task.title}
                status={task.status}
                subline={task.reference + ' · ' + task.employee.name}
                tab={tab}
                onTab={setTab}
                search={search}
                onSearch={setSearch}
                meters={[
                    {
                        label: 'Due',
                        value: formatDateOnly(task.due_date),
                        caption: 'Current target date',
                        tab: 'details',
                    },
                    {
                        label: 'Approval',
                        value: provisioningLabel(task.approval_status),
                        caption:
                            task.readiness.approver?.name ??
                            'Current approval state',
                        tab: 'work',
                    },
                    {
                        label: 'Prerequisites',
                        value: task.dependencies.length,
                        caption: 'Visible linked tasks',
                        tab: 'work',
                    },
                    {
                        label: 'History',
                        value: task.events.length,
                        caption: 'Recorded events',
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
                <ProvisioningSection title="Next action">
                    <p className="text-sm">{task.readiness.next_action}</p>
                    <ProvisioningActions
                        actions={task.readiness.actions}
                        onAction={setOperation}
                    />
                </ProvisioningSection>
                {tab === 'details' ? (
                    <>
                        <ProvisioningSection title="Requested work">
                            <ProvisioningFacts
                                search={search}
                                facts={[
                                    {
                                        label: 'Employee',
                                        value: task.employee.name,
                                    },
                                    {
                                        label: 'Work',
                                        value:
                                            provisioningLabel(task.action) +
                                            ' · ' +
                                            provisioningLabel(
                                                task.category ?? task.type,
                                            ),
                                    },
                                    {
                                        label: 'Responsible person',
                                        value:
                                            task.readiness.worker?.name ??
                                            'Responsibility needs attention',
                                    },
                                    { label: 'Team', value: task.team },
                                    {
                                        label: 'Due date',
                                        value: formatDateOnly(task.due_date),
                                    },
                                    {
                                        label: 'Original instructions',
                                        value: task.instructions,
                                    },
                                    {
                                        label: 'Workflow',
                                        value: task.workflow ? (
                                            <Link
                                                className="text-primary underline"
                                                href={task.workflow.href}
                                            >
                                                {provisioningLabel(
                                                    task.workflow
                                                        .lifecycle_type,
                                                )}{' '}
                                                workflow
                                            </Link>
                                        ) : (
                                            'Standalone request'
                                        ),
                                    },
                                    ...(task.reversal_of_request_id
                                        ? [
                                              {
                                                  label: 'Reverses original work',
                                                  value: (
                                                      <Link
                                                          className="text-primary underline"
                                                          href={
                                                              '/it/provisioning/tasks/' +
                                                              task.reversal_of_request_id
                                                          }
                                                      >
                                                          Open original task
                                                      </Link>
                                                  ),
                                              },
                                          ]
                                        : []),
                                ]}
                            />
                        </ProvisioningSection>
                        <ProvisioningSubmittedFiles files={task.attachments} />
                        {task.canonical_target && (
                            <ProvisioningSection title="Canonical account or assignment">
                                <Link
                                    href={task.canonical_target.href}
                                    className="text-primary underline"
                                >
                                    {task.canonical_target.label}
                                </Link>
                                <p className="text-sm text-muted-foreground">
                                    Current source permissions still apply when
                                    opening this record.
                                </p>
                            </ProvisioningSection>
                        )}
                        {task.linked_tickets.length > 0 && (
                            <ProvisioningSection title="Related support tickets">
                                {task.linked_tickets.map((ticket) => (
                                    <Link
                                        key={ticket.href}
                                        className="block text-sm text-primary underline"
                                        href={ticket.href}
                                    >
                                        {ticket.title}
                                    </Link>
                                ))}
                            </ProvisioningSection>
                        )}
                    </>
                ) : tab === 'work' ? (
                    <>
                        <ProvisioningSection title="Approval and dependencies">
                            <ProvisioningFacts
                                search={search}
                                facts={[
                                    {
                                        label: 'Approval',
                                        value: provisioningLabel(
                                            task.approval_status,
                                        ),
                                    },
                                    {
                                        label: 'Current approver',
                                        value: task.readiness.approver?.name,
                                    },
                                    {
                                        label: 'Approval requested',
                                        value: formatDateTime(
                                            task.approval_requested_at,
                                        ),
                                    },
                                    {
                                        label: 'Approval deadline',
                                        value: formatDateTime(
                                            task.approval_expires_at,
                                        ),
                                    },
                                ]}
                            />
                            {task.dependencies.map((dependency) => (
                                <Link
                                    key={dependency.id}
                                    href={dependency.href}
                                    className="block text-sm text-primary underline"
                                >
                                    {dependency.title} ·{' '}
                                    {provisioningLabel(dependency.status)}
                                </Link>
                            ))}
                        </ProvisioningSection>
                        <ProvisioningSection title="Fulfilment evidence">
                            <ProvisioningFacts
                                search={search}
                                facts={[
                                    {
                                        label: 'Work reference',
                                        value: task.external_ref,
                                    },
                                    {
                                        label: 'Evidence',
                                        value: task.evidence_summary,
                                    },
                                    {
                                        label: 'Recorded completion',
                                        value: formatDateTime(
                                            task.fulfilled_at,
                                        ),
                                    },
                                    {
                                        label: 'Fulfilment method',
                                        value: task.fulfilment_mode
                                            ? provisioningLabel(
                                                  task.fulfilment_mode,
                                              )
                                            : 'Work not yet recorded',
                                    },
                                    {
                                        label: 'Failure and corrective action',
                                        value: task.failure_reason,
                                    },
                                ]}
                            />
                        </ProvisioningSection>
                    </>
                ) : (
                    <ProvisioningHistory events={task.events} search={search} />
                )}
            </main>
            {operation && (
                <ProvisioningCommandDialog
                    key={operation}
                    context={{
                        actorId,
                        kind: 'request',
                        targetId: task.id,
                        operation,
                    }}
                    version={task.version}
                    title={task.title}
                    description={
                        operation === 'fulfil'
                            ? 'Complete the actual work and record its verification. The original instructions and earlier decisions remain in history.'
                            : 'Review the current task and the reason for this action. The requester and beneficiary cannot approve their own work.'
                    }
                    fields={commandFields(task, operation)}
                    initial={
                        operation === 'fulfil'
                            ? {
                                  external_ref: task.external_ref,
                                  evidence_summary: task.evidence_summary,
                              }
                            : {}
                    }
                    review={[
                        { label: 'Employee', value: task.employee.name },
                        { label: 'Task', value: task.title },
                        {
                            label: 'Current state',
                            value: provisioningLabel(task.status),
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
