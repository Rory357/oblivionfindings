import {
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
} from '@/components/page/page-header';
import { type ReactNode } from 'react';
import { createPortal } from 'react-dom';

export type FleetHeaderTargets = {
    meters: HTMLElement | null;
    actions: HTMLElement | null;
    filters: HTMLElement | null;
};

/** Keep each view's controls with its source state, in the shared page header. */
export function FleetHeaderSlot({
    target,
    children,
}: {
    target?: HTMLElement | null;
    children: ReactNode;
}) {
    return target === undefined
        ? children
        : target
          ? createPortal(children, target)
          : null;
}

export function FleetEvidenceMeters({
    total,
    inUse,
    maintenance,
    onAssess,
}: {
    total: number;
    inUse: number;
    maintenance: number;
    onAssess: () => void;
}) {
    return (
        <>
            <PageHeaderMeterBlock label="Evidence current" onClick={onAssess}>
                <PageHeaderMeterBig>Not assessed</PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    Check the vehicle and exact trip
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="In use / unreturned"
                tone="warning"
                href="/fleet-assets/bookings?status=checked_out"
            >
                <PageHeaderMeterBig>{inUse}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    Permitted fleet · return not recorded
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Maintenance status"
                tone={maintenance > 0 ? 'critical' : 'brand'}
                href="/fleet-assets/maintenance/work-orders"
            >
                <PageHeaderMeterBig>{maintenance}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    Recorded status · review source restrictions
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Evidence to assess"
                tone="warning"
                onClick={onAssess}
            >
                <PageHeaderMeterBig>{total}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    Exact trip checks required · unknown is not clear
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
        </>
    );
}
