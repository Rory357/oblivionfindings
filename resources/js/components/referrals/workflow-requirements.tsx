import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { label, type ReferralDetail } from './types';

type Target = NonNullable<ReferralDetail['workflow_targets']>[number];

export function WorkflowRequirements({ target }: { target?: Target }) {
    if (!target) return null;
    const requirements = target.required_evidence ?? [];
    if (
        !requirements.length &&
        !target.restricted_requirements &&
        !target.requires_readiness
    )
        return null;

    return (
        <Alert variant={target.restricted_requirements ? 'warning' : 'info'}>
            <AlertTitle>Before moving to {target.label}</AlertTitle>
            <AlertDescription className="space-y-2">
                {target.requires_readiness && (
                    <p>
                        Every handover preparation check must pass. They are
                        checked again when you save; if one is missing the move
                        is refused and the Handover section shows what is
                        needed.
                    </p>
                )}
                {requirements.length > 0 && (
                    <>
                        <p>Have current, available documents for:</p>
                        <ul className="list-disc space-y-1 pl-5">
                            {requirements.map((requirement) => (
                                <li key={requirement}>
                                    {requirement
                                        .split(':')
                                        .map(label)
                                        .join(' · ')}
                                </li>
                            ))}
                        </ul>
                        <p>
                            These are the recorded workflow requirements, not a
                            confirmation that the documents are ready. They are
                            checked again when you save. Separate authority and
                            funding approvals still apply.
                        </p>
                    </>
                )}
                {target.restricted_requirements && (
                    <p>
                        Some required evidence is outside your access. A
                        colleague with the required access must make this stage
                        change.
                    </p>
                )}
            </AlertDescription>
        </Alert>
    );
}
