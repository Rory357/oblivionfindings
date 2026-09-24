import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { ArrowRight, ShieldCheck, Upload, Wrench } from 'lucide-react';
import { useState } from 'react';
import {
    useVehicleCollectionView,
    VehicleCollectionToggle,
    VehicleRecordCollection,
} from './record-collection';
import {
    openWorkOrder,
    SectionHeading,
    StudioFooterAction,
} from './studio-kit';
import type { VehicleWorkspace } from './types';
import { StudioNotice } from './wizard-kit';
import { WorkEvidenceDialog } from './work-evidence-dialog';
import type { WorkspaceLocation } from './workspace-model';

type OpenWork = VehicleWorkspace['work']['open'][number];

/** The stage the work is at, in the design's words. */
function workStage(order: OpenWork): string {
    if (order.restricted) return 'Hold active';
    if (order.status === 'on_hold') return 'On hold';
    if (order.provider_state === 'confirmed') return 'Provider confirmed';
    if (order.provider_state === 'completed') return 'Provider completed';
    if (order.provider_state === 'planned') return 'Internal plan';
    if (order.status === 'in_progress') return 'In progress';
    return 'Awaiting assessment';
}

/** Maintenance › Open work, built to the approved design. */
export function OpenWorkPanel({
    workspace,
    onNavigate,
    onChanged,
}: {
    workspace: VehicleWorkspace;
    onNavigate: (location: WorkspaceLocation) => void;
    onChanged: () => void;
}) {
    const { view, setView } = useVehicleCollectionView('maintenance-open');
    const [upload, setUpload] = useState<{ id: number; label: string } | null>(
        null,
    );
    const { work, vehicle, can } = workspace;
    if (!work.can_view) {
        return (
            <section className="studio-card">
                <SectionHeading eyebrow="MAINTENANCE" title="Open work" />
                {work.site_restricted ? (
                    <StudioNotice title="Maintenance stays with the vehicle’s Site">
                        You can see this vehicle across Sites. Its Maintenance
                        work is shown to people with Maintenance access at{' '}
                        {vehicle.home_site?.name ??
                            vehicle.site?.name ??
                            'its Site'}
                        .
                    </StudioNotice>
                ) : (
                    <StudioNotice title="Maintenance access required">
                        Maintenance records for this vehicle are available to
                        people with Maintenance access at its site.
                    </StudioNotice>
                )}
            </section>
        );
    }
    const open = (id: number) =>
        openWorkOrder(id, vehicle.id, { tab: 'maintenance', view: 'open' });

    return (
        <section className="studio-card">
            <SectionHeading eyebrow="MAINTENANCE" title="Open work">
                <VehicleCollectionToggle
                    label="Open maintenance work"
                    view={view}
                    onChange={setView}
                />
                <StatusBadge variant="info">
                    {work.open_count ?? work.open.length} work records
                </StatusBadge>
            </SectionHeading>
            <VehicleRecordCollection
                label="Open maintenance work"
                view={view}
                total={work.open_count ?? undefined}
                columns={[
                    { label: 'Status' },
                    { label: 'Source' },
                    { label: 'Responsible owner' },
                ]}
                empty={{
                    title: 'No open work',
                    description:
                        'Use Report a problem when a concern needs assessment.',
                }}
                records={work.open.map((order) => {
                    const alert = order.restricted || order.source.failed_check;
                    const owner = order.owner ?? 'Unassigned';
                    return {
                        id: order.id,
                        name: order.title ?? 'Maintenance work',
                        subline: order.reference ?? `Work #${order.id}`,
                        icon: Wrench,
                        tone: alert ? 'critical' : undefined,
                        fields: [
                            <StatusBadge
                                key="status"
                                variant={alert ? 'critical' : 'info'}
                            >
                                {workStage(order)}
                            </StatusBadge>,
                            <span key="source">{order.source.label}</span>,
                            <span key="owner">{owner}</span>,
                        ],
                        onOpen: () => open(order.id),
                        footer: {
                            personName: order.owner ?? undefined,
                            primary: owner,
                            secondary: 'Maintenance owner',
                        },
                        actions: [
                            {
                                label: 'Open work record',
                                icon: Wrench,
                                onClick: () => open(order.id),
                            },
                            ...(can.schedule_service
                                ? [
                                      {
                                          label: 'Upload evidence',
                                          icon: Upload,
                                          onClick: () =>
                                              setUpload({
                                                  id: order.id,
                                                  label:
                                                      order.reference ??
                                                      `Work #${order.id}`,
                                              }),
                                      },
                                  ]
                                : []),
                        ],
                    };
                })}
            />
            <StudioFooterAction
                icon={<ShieldCheck className="size-[17px]" aria-hidden />}
                action={
                    <Button
                        variant="ghost"
                        onClick={() =>
                            onNavigate({ tab: 'maintenance', view: 'history' })
                        }
                    >
                        View work history <ArrowRight className="size-[14px]" />
                    </Button>
                }
            >
                Work completion and authorised release remain separate.
            </StudioFooterAction>
            {upload && (
                <WorkEvidenceDialog
                    workOrderId={upload.id}
                    workLabel={upload.label}
                    onClose={() => setUpload(null)}
                    onSaved={onChanged}
                />
            )}
        </section>
    );
}
