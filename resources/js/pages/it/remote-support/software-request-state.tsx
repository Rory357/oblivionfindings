import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';

export type SoftwareRequest = {
    packageUuid: string | null;
    packageName: string | null;
    publisher: string | null;
    productCode: string | null;
    productVersion: string | null;
    architecture: 'amd64' | 'arm64' | null;
    consentMode: 'attended' | 'unattended' | null;
    state:
        | 'requested'
        | 'awaiting_observation'
        | 'confirmed'
        | 'uncertain'
        | 'expired'
        | 'failed';
    rebootRequired: boolean | null;
    exitCode: number | null;
};
const labels: Record<SoftwareRequest['state'], string> = {
    requested: 'Installation requested',
    awaiting_observation: 'Checking installed version',
    confirmed: 'Installed version verified',
    uncertain: 'Installation result needs checking',
    expired: 'Installation window closed',
    failed: 'Installation did not complete',
};
export function SoftwareRequestState({
    software,
    onArrangeRestart,
}: {
    software: SoftwareRequest;
    onArrangeRestart?: () => void;
}) {
    const confirmed = software.state === 'confirmed';
    return (
        <section
            aria-label="Software installation result"
            className="mt-3 min-w-0 space-y-3 rounded-lg border p-3 [overflow-wrap:anywhere]"
        >
            <div>
                <p className="font-medium">
                    {software.packageName ?? 'Approved software'}
                    {software.productVersion
                        ? ' · ' + software.productVersion
                        : ''}
                </p>
                {software.publisher && (
                    <p className="text-subtle">{software.publisher}</p>
                )}
            </div>
            <StatusBadge
                variant={
                    ['failed', 'expired', 'uncertain'].includes(
                        software.state,
                    ) || software.rebootRequired
                        ? 'warning'
                        : confirmed
                          ? 'success'
                          : 'info'
                }
            >
                {confirmed && software.rebootRequired
                    ? 'Installed · restart needed'
                    : (labels[software.state] ??
                      'Installation state unavailable')}
            </StatusBadge>
            {software.state === 'requested' && (
                <p className="text-subtle">
                    Approval and delivery follow the request status above. The
                    computer must check the approved installer and current
                    permission before installation.
                </p>
            )}
            {software.state === 'awaiting_observation' && (
                <p className="text-subtle">
                    The installer reported completion. A separate check of the
                    installed product and version must confirm the result.
                </p>
            )}
            {confirmed && (
                <p className="text-subtle">
                    The computer’s installed product and version were
                    independently checked and accepted.
                </p>
            )}
            {software.state === 'uncertain' && (
                <p className="text-subtle">
                    The installer may have changed the computer. Check its
                    actual state before sending another request. A restart alone
                    does not prove the installation completed.
                </p>
            )}
            {software.state === 'expired' && (
                <p className="text-subtle">
                    The approval window closed. Review the result before
                    arranging another installation.
                </p>
            )}
            {software.state === 'failed' && (
                <p className="text-subtle">
                    Review the recorded failure and current computer state
                    before trying again.
                </p>
            )}
            {software.rebootRequired === true && (
                <div className="space-y-2">
                    <p className="text-subtle">
                        A restart is still needed to apply changes. Arrange it
                        through the separate approved restart workflow.
                    </p>
                    {onArrangeRestart && confirmed && (
                        <Button variant="outline" onClick={onArrangeRestart}>
                            Arrange restart
                        </Button>
                    )}
                </div>
            )}
            {software.consentMode && (
                <p className="text-caption text-muted-foreground">
                    {software.consentMode === 'attended'
                        ? 'Staff consent required on the computer'
                        : 'Authorised unattended installation'}
                </p>
            )}
        </section>
    );
}
