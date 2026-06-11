/* openDialog() host for the client profile. The hero and tab CTAs call
 * openProfileDialog(key, ctx) — flow keys resolve to the generic
 * WorkflowWizardDialog (flows.tsx); bespoke keys (eMAR, family chat) render
 * their own components. Keys owned by show.tsx's pre-existing dialogs
 * (daily/quick/communication note, edit profile) are delegated before this
 * component is reached. */
import { EmarRecordDialog, type EmarMedication } from './emar-dialog';
import { FamilyChatPopup } from './family-chat';
import { PROFILE_FLOWS, type ProfileFlowContext } from './flows';
import { WorkflowWizardDialog } from './workflow-wizard';

export type ProfileDialogState = {
    key: string;
    ctx?: Record<string, unknown>;
} | null;

export function ProfileDialogs({
    dialog,
    onClose,
    flowContext,
    medications,
}: {
    dialog: ProfileDialogState;
    onClose: () => void;
    flowContext: Omit<ProfileFlowContext, 'dialog'>;
    medications: EmarMedication[];
}) {
    if (!dialog) return null;

    const flowFactory = PROFILE_FLOWS[dialog.key];
    if (flowFactory) {
        const config = flowFactory({ ...flowContext, dialog: dialog.ctx });
        return (
            <WorkflowWizardDialog
                config={config}
                open
                onClose={onClose}
                clientLabel={flowContext.clientLabel}
            />
        );
    }

    if (dialog.key === 'emar') {
        return (
            <EmarRecordDialog
                open
                onClose={onClose}
                clientId={flowContext.clientId}
                clientLabel={flowContext.clientLabel}
                medications={medications}
                staffOptions={flowContext.staffOptions}
                initialMedicationId={
                    typeof dialog.ctx?.medicationId === 'number'
                        ? dialog.ctx.medicationId
                        : undefined
                }
            />
        );
    }

    if (dialog.key === 'family_chat') {
        return (
            <FamilyChatPopup
                open
                onClose={onClose}
                clientId={flowContext.clientId}
                clientName={flowContext.preferredName}
            />
        );
    }

    return null;
}
