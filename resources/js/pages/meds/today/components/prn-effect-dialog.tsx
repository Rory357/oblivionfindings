import { AdministrationFollowupDialog } from '@/components/emar/followups/administration-followup-dialog';
import type { ClientInfo, PrnFollowUp } from '@/pages/meds/today/types';

export function PrnEffectDialog({
    followUp,
    onClose,
}: {
    followUp: PrnFollowUp;
    client: ClientInfo | undefined;
    onClose: () => void;
}) {
    return (
        <AdministrationFollowupDialog
            administrationId={followUp.administration_id}
            onClose={onClose}
        />
    );
}

export default PrnEffectDialog;
