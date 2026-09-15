import type { ProvisioningCommandField } from './provisioning-command-dialog';

/** The shape a task command needs; satisfied by list rows and the task detail. */
export interface ProvisioningCommandTask {
    type: string;
    canonical_target_id?: number | null;
    readiness: { manual_evidence: boolean };
}

export const provisioningReasonField: ProvisioningCommandField = {
    key: 'reason',
    label: 'Reason and next action',
    kind: 'textarea',
    required: true,
};

export const provisioningApprovalRequestFields: ProvisioningCommandField[] = [
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
    provisioningReasonField,
];

/** Fields for a single-task command; shared by the task page and register rows. */
export function taskCommandFields(
    task: ProvisioningCommandTask,
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
            provisioningReasonField,
        ];
    if (operation === 'request_approval')
        return provisioningApprovalRequestFields;
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
    return [provisioningReasonField];
}

/** Plain-language explanation shown above each task command form. */
export function taskCommandDescription(operation: string): string {
    switch (operation) {
        case 'fulfil':
            return 'Complete the actual work and record its verification. The original instructions and earlier decisions remain in history.';
        case 'reverse':
            return 'The completed action stays recorded. A new corrective task is created that needs its own approval and evidence; nothing is changed in external accounts or equipment until that task is fulfilled.';
        case 'request_approval':
            return 'Choose two distinct eligible approvers. The requester and beneficiary cannot approve their own work. Approval expires at the end of the chosen NZ date.';
        default:
            return 'Review the current task and the reason for this action. The requester and beneficiary cannot approve their own work.';
    }
}
