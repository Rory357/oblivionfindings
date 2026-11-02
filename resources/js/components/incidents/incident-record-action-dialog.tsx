import {
    ACTION_META,
    ActionPane,
    EditPane,
    type IncidentDetail,
    type LifecycleAction,
} from '@/components/incidents/incident-detail-dialog';
import { PopupContext, PopupHeader } from '@/components/popup';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { WizardShell } from '@/components/wizard/shell';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import type { PendingVisit } from '@inertiajs/core';
import { ClipboardList, Pencil, type LucideIcon } from 'lucide-react';
import { cloneElement, useCallback, useState } from 'react';

/** Only the selected command is modal; the incident remains a permanent page. */
export function IncidentRecordActionDialog({
    detail,
    action,
    onClose,
}: {
    detail: IncidentDetail;
    action: LifecycleAction | 'edit';
    onClose: () => void;
}) {
    const [draft, setDraft] = useState({ dirty: false, busy: false });
    const preserveCurrentIncident = useCallback(
        (visit: PendingVisit) =>
            visit.url.origin === window.location.origin &&
            visit.url.pathname === `/incidents/${detail.id}` &&
            visit.preserveState === true,
        [detail.id],
    );
    const guard = useSettingsLeaveConfirmation(
        draft.dirty || draft.busy,
        'Discard unsaved incident work?',
        'Your unsaved entries will be discarded. A request already sent may still finish; check the incident before retrying. Cancel to keep editing.',
        undefined,
        false,
        preserveCurrentIncident,
    );
    const requestClose = () => {
        if (!draft.busy) guard.request(onClose);
    };
    const reference = detail.ref ?? `INC-${detail.id}`;

    return (
        <>
            {action === 'edit' ? (
                <WizardShell
                    open
                    frontline
                    onClose={requestClose}
                    title={`Edit ${reference}`}
                    description="Edit the saved incident draft."
                    railIcon={Pencil}
                    railTitle={reference}
                    railSub="Incident draft"
                    steps={[
                        {
                            key: 'details',
                            label: 'Draft details',
                            blurb: 'What happened and the response',
                            icon: Pencil,
                        },
                    ]}
                    stepIndex={0}
                    onStepClick={() => undefined}
                    headerLabel="Edit draft"
                >
                    <EditPane
                        d={detail}
                        onDone={onClose}
                        onCancel={requestClose}
                        onDraftStateChange={setDraft}
                        confirmCurrentIncident
                    />
                </WizardShell>
            ) : (
                <Dialog
                    open
                    onOpenChange={(open) => {
                        if (!open) requestClose();
                    }}
                >
                    <DialogContent
                        showCloseButton={false}
                        data-popup-size="md"
                        className="frontline-dialog flex max-h-[88vh] flex-col gap-0 overflow-hidden rounded-[18px] border border-border bg-card p-0 text-card-foreground shadow-2xl sm:max-w-none"
                        style={{
                            width: 'min(92vw, 720px)',
                            maxWidth: 'min(92vw, 720px)',
                        }}
                    >
                        <PopupHeader
                            icon={ACTION_META[action].icon as LucideIcon}
                            title={ACTION_META[action].title}
                            description={ACTION_META[action].blurb}
                            onClose={requestClose}
                            closeDisabled={draft.busy}
                        />
                        <div className="scrollbar-pretty grid min-h-0 flex-1 content-start gap-3.5 overflow-y-auto px-[22px] pt-1 pb-[18px]">
                            <PopupContext
                                title={reference}
                                icon={ClipboardList}
                            />
                            <ActionPane
                                d={detail}
                                action={action}
                                onDone={onClose}
                                onCancel={requestClose}
                                onDraftStateChange={setDraft}
                                confirmCurrentIncident
                                hideHead
                            />
                        </div>
                    </DialogContent>
                </Dialog>
            )}
            {cloneElement(guard.confirmation, { frontline: true })}
        </>
    );
}
