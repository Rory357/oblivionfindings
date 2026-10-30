import { type ReactNode, useState } from 'react';
import { ActionDialog, type ActionSpec } from './action-dialog';
import { CountDialog } from './count-dialog';
import { OverrideFollowupDialog } from './override-followup-dialog';
import type { ControlledWorkspace } from './product-client';
import type { ControlledOverride } from './product-types';
import { DetailDialog, type DetailSpec } from './record-views';

type Modal =
    | { type: 'count'; medicineIds: number[] }
    | { type: 'action'; spec: ActionSpec }
    | { type: 'detail'; spec: DetailSpec }
    | { type: 'followup'; override: ControlledOverride }
    | null;
export function useControlledDialogs(workspace: ControlledWorkspace) {
    const [modal, setModal] = useState<Modal>(null);
    const payload = workspace.payload;
    const close = () => setModal(null);
    const action = (spec: ActionSpec) => setModal({ type: 'action', spec });
    const detail = (spec: DetailSpec) => setModal({ type: 'detail', spec });
    const count = (medicineIds: number[]) =>
        setModal({ type: 'count', medicineIds });
    const followup = (override: ControlledOverride) =>
        setModal({ type: 'followup', override });
    let node: ReactNode = null;
    if (payload && modal) {
        if (modal.type === 'action')
            node = (
                <ActionDialog
                    key={`${modal.spec.action}:${modal.spec.targetId ?? modal.spec.medicineId ?? modal.spec.siteId ?? 'new'}`}
                    spec={modal.spec}
                    payload={payload}
                    act={workspace.act}
                    onClose={close}
                />
            );
        else if (modal.type === 'detail')
            node = (
                <DetailDialog
                    key={`${modal.spec.kind}:${modal.spec.id}`}
                    spec={modal.spec}
                    payload={payload}
                    onAction={action}
                    onDetail={detail}
                    onFollowup={followup}
                    onClose={close}
                />
            );
        else if (modal.type === 'followup')
            node = (
                <OverrideFollowupDialog
                    override={modal.override}
                    payload={payload}
                    act={workspace.act}
                    refresh={workspace.refresh}
                    onClose={close}
                />
            );
        else {
            const medicines = payload.medicines.filter((medicine) =>
                modal.medicineIds.includes(medicine.id),
            );
            if (medicines.length)
                node = (
                    <CountDialog
                        medicines={medicines}
                        payload={payload}
                        act={workspace.act}
                        refresh={workspace.refresh}
                        onClose={close}
                    />
                );
        }
    }
    return { action, detail, count, followup, node };
}
