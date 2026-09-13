import { ProvisioningCommandDialog } from '@/components/it/provisioning-command-dialog';
import {
    ProvisioningPicker,
    type ProvisioningOption,
} from '@/components/it/provisioning-picker';
import { Button } from '@/components/ui/button';
import { WizardShell, WizardStepPane } from '@/components/wizard/shell';
import { Package, UserRound } from 'lucide-react';
import { useState } from 'react';

export function ProvisioningLaunchDialog({
    actorId,
    onClose,
    onDenied,
}: {
    actorId: number;
    onClose: () => void;
    onDenied: () => void;
}) {
    const [employee, setEmployee] = useState<ProvisioningOption | null>(null);
    const [reviewing, setReviewing] = useState(false);
    if (employee && reviewing)
        return (
            <ProvisioningCommandDialog
                context={{
                    actorId,
                    kind: 'launch',
                    targetId: employee.id,
                    operation: 'launch',
                }}
                version={1}
                title={'Provisioning · ' + employee.label}
                description="This starts the selected published instructions for this employee and retains their original version. External accounts change only through completed, verified work. Mover work compares the last recorded provisioning context with the current HR profile."
                fields={[
                    {
                        key: 'template_version_id',
                        label: 'Published workflow template',
                        kind: 'template',
                        required: true,
                    },
                    {
                        key: 'effective_date',
                        label: 'Effective date',
                        kind: 'date',
                        required: true,
                    },
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
                    {
                        key: 'reason',
                        label: 'Reason for starting this workflow',
                        kind: 'textarea',
                        required: true,
                    },
                ]}
                review={[{ label: 'Employee', value: employee.label }]}
                onClose={onClose}
                onDenied={onDenied}
            />
        );
    return (
        <WizardShell
            open
            onClose={onClose}
            title="Start provisioning"
            description="Choose the employee whose canonical work needs to start."
            railIcon={Package}
            railTitle="Start workflow"
            railSub="Employee and published instructions"
            steps={[
                {
                    key: 'employee',
                    label: 'Employee',
                    blurb: 'Choose a current permitted profile',
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
                <Button disabled={!employee} onClick={() => setReviewing(true)}>
                    Continue
                </Button>
            }
        >
            <WizardStepPane>
                <div className="space-y-5">
                    <h2 className="text-lg font-semibold">
                        Who is this work for?
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Choose an employee within your current approved Site
                        access. HR continues to own their employment and role
                        details.
                    </p>
                    <ProvisioningPicker
                        actorId={actorId}
                        kind="employees"
                        label="Employee"
                        value={employee?.id ?? null}
                        onChange={setEmployee}
                        onDenied={onDenied}
                    />
                </div>
            </WizardStepPane>
        </WizardShell>
    );
}
