import { Button } from '@/components/ui/button';
import { WizardShell, WizardStepPane } from '@/components/wizard/shell';
import { Package, UserRound } from 'lucide-react';
import { useState } from 'react';
import { ProvisioningCommandDialog } from './provisioning-command-dialog';
import {
    ProvisioningPicker,
    type ProvisioningOption,
} from './provisioning-picker';

export function ProvisioningManualDialog({
    actorId,
    onClose,
    onDenied,
}: {
    actorId: number;
    onClose: () => void;
    onDenied: () => void;
}) {
    const [employee, setEmployee] = useState<ProvisioningOption | null>(null);
    const [continueForm, setContinueForm] = useState(false);
    if (employee && continueForm)
        return (
            <ProvisioningCommandDialog
                context={{
                    actorId,
                    kind: 'manual',
                    targetId: employee.id,
                    operation: 'create',
                }}
                version={1}
                title={'Work task · ' + employee.label}
                description="Record a single provisioning task. The assigned person must complete the work and retain verification evidence. This does not change an external account or device."
                fields={[
                    {
                        key: 'type',
                        label: 'Work type',
                        kind: 'select',
                        options: ['account', 'access', 'equipment', 'other'],
                        required: true,
                    },
                    {
                        key: 'item',
                        label: 'Work needed',
                        kind: 'text',
                        required: true,
                    },
                    {
                        key: 'notes',
                        label: 'Instructions and reason',
                        kind: 'textarea',
                        required: true,
                    },
                    {
                        key: 'priority',
                        label: 'Priority',
                        kind: 'select',
                        options: ['low', 'normal', 'high', 'urgent'],
                        required: true,
                    },
                    { key: 'due_date', label: 'Due date', kind: 'date' },
                    {
                        key: 'assigned_to_user_id',
                        label: 'Responsible person',
                        kind: 'agent',
                        required: true,
                    },
                ]}
                initial={{ priority: 'normal' }}
                review={[
                    { label: 'Employee', value: employee.label },
                    {
                        label: 'Account and access work',
                        value: 'Requires a separate approval decision before fulfilment.',
                    },
                ]}
                onClose={onClose}
                onDenied={onDenied}
            />
        );
    return (
        <WizardShell
            open
            onClose={onClose}
            title="New provisioning task"
            description="Choose the employee who needs this work."
            railIcon={Package}
            railTitle="Work task"
            railSub="Employee and instructions"
            steps={[
                {
                    key: 'employee',
                    label: 'Employee',
                    blurb: 'Current permitted profile',
                    icon: UserRound,
                },
            ]}
            stepIndex={0}
            onStepClick={() => {}}
            footerStart={
                <Button variant="outline" onClick={onClose}>
                    Cancel
                </Button>
            }
            footerEnd={
                <Button
                    disabled={!employee}
                    onClick={() => setContinueForm(true)}
                >
                    Continue
                </Button>
            }
        >
            <WizardStepPane>
                <ProvisioningPicker
                    actorId={actorId}
                    kind="employees"
                    label="Employee"
                    value={employee?.id ?? null}
                    onChange={setEmployee}
                    onDenied={onDenied}
                />
            </WizardStepPane>
        </WizardShell>
    );
}
