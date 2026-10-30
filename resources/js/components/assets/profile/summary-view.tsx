import { FilePreviewDialog } from '@/components/files/file-preview-dialog';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/fleet-utils';
import type { Props } from '@/pages/fleet-assets/assets/show';
import {
    CircleAlert,
    ClipboardCheck,
    FileText,
    History,
    MapPin,
    Package,
    UserRound,
    Wrench,
} from 'lucide-react';
import { useState } from 'react';
import { checkNeedsAttention, latestAssetCheck } from './latest-check';
import {
    ActionRow,
    Empty,
    Panel,
    SectionHeading,
    State,
    TextAction,
    human,
} from './presentation';
import type { AssetFile, ProfileWorkspace } from './types';

export function AssetSummaryView({
    asset,
    data,
    holds,
    timeline,
    navigate,
}: {
    asset: Props['asset'];
    data: ProfileWorkspace;
    holds: number;
    timeline: Props['timeline'];
    navigate: (view: string, section?: string) => void;
}) {
    const [preview, setPreview] = useState<AssetFile | null>(null);
    const pending = data.movements.find((item) =>
        ['pending_receipt', 'incomplete', 'disputed'].includes(item.state),
    );
    const latestReceipt = data.movements.find(
        (item) => item.state === 'acknowledged',
    );
    const assignment = asset.current_assignment;
    const lastCheck = latestAssetCheck(data);
    const work = data.work.find(
        (item) => !['completed', 'cancelled'].includes(item.status),
    );
    const unavailable = data.documents.filter(
        (file) => !file.archived && !file.downloadUrl,
    );
    const current = data.documents.filter(
        (file) => file.current && !file.archived,
    );
    return (
        <div className="space-y-5">
            <SectionHeading
                eyebrow={`Asset record · ${asset.asset_tag}`}
                title="Summary & next actions"
                description="What needs attention, who is responsible, and where to follow up."
                actions={
                    <Button
                        variant="outline"
                        onClick={() => navigate('overview', 'identity')}
                    >
                        <Package />
                        Asset details
                    </Button>
                }
            />
            <div className="grid gap-5 lg:grid-cols-[1.15fr_1fr]">
                <Panel title="Needs attention" icon={CircleAlert}>
                    {(holds > 0 || work) && (
                        <ActionRow
                            icon={Wrench}
                            title={
                                holds
                                    ? 'Review the Maintenance hold'
                                    : 'Follow up linked Maintenance'
                            }
                            description={
                                work
                                    ? `${work.reference} · ${human(work.status)} · ${work.owner || 'Awaiting allocation'}`
                                    : 'The source work owns the next action.'
                            }
                            detail={
                                work?.next_action ||
                                'Moving or receiving the asset does not release a hold.'
                            }
                            action={
                                <TextAction
                                    onClick={() => navigate('maintenance')}
                                >
                                    Open work
                                </TextAction>
                            }
                        />
                    )}
                    {(pending || !assignment) && (
                        <ActionRow
                            icon={UserRound}
                            title={
                                pending
                                    ? 'Confirm the actual receipt'
                                    : 'Assign asset responsibility'
                            }
                            description={
                                pending
                                    ? `${pending.recipient || 'Recipient not recorded'} · ${pending.received_kit.length} of ${pending.kit.length} kit items confirmed`
                                    : 'No responsible person assigned'
                            }
                            detail="Placement, responsibility and physical receipt are separate records."
                            action={
                                <TextAction onClick={() => navigate('custody')}>
                                    {pending
                                        ? 'Review receipt'
                                        : 'View custody'}
                                </TextAction>
                            }
                        />
                    )}
                    {unavailable.length > 0 && (
                        <ActionRow
                            icon={FileText}
                            title="Resolve unavailable evidence"
                            description={`${unavailable.length} file${unavailable.length === 1 ? '' : 's'} awaiting a successful check or restored access.`}
                            action={
                                <TextAction
                                    onClick={() =>
                                        navigate('overview', 'library')
                                    }
                                >
                                    View records
                                </TextAction>
                            }
                        />
                    )}
                    {lastCheck && checkNeedsAttention(lastCheck.result) && (
                        <ActionRow
                            icon={ClipboardCheck}
                            title="Follow up the latest check"
                            description={
                                lastCheck.notes || human(lastCheck.result)
                            }
                            action={
                                <TextAction onClick={() => navigate('checks')}>
                                    Original checks
                                </TextAction>
                            }
                        />
                    )}
                    {!holds &&
                        !work &&
                        !pending &&
                        assignment &&
                        !unavailable.length &&
                        (!lastCheck ||
                            !checkNeedsAttention(lastCheck.result)) && (
                            <Empty>No outstanding actions recorded.</Empty>
                        )}
                </Panel>
                <Panel
                    title="Location & custody"
                    icon={MapPin}
                    actions={
                        pending ? (
                            <State value={pending.state} />
                        ) : latestReceipt ? (
                            <State value={latestReceipt.state} />
                        ) : undefined
                    }
                >
                    <div>
                        <h3 className="text-section-title">
                            {asset.site?.name || 'Site not recorded'}
                        </h3>
                        <p className="text-subtle mt-1">
                            {data.room || asset.location || 'Room not recorded'}
                        </p>
                        <p className="text-caption mt-2 text-muted-foreground">
                            Canonical assigned location
                        </p>
                    </div>
                    <div className="flex items-center gap-3 border-y py-4">
                        <span className="grid size-11 place-items-center rounded-full bg-primary/10 text-primary">
                            <UserRound className="size-5" />
                        </span>
                        <div>
                            <p className="text-caption text-muted-foreground">
                                Accountable custodian
                            </p>
                            <strong className="text-subtle">
                                {assignment?.assignee?.name ||
                                    latestReceipt?.received_by ||
                                    'No confirmed custodian'}
                            </strong>
                            <p className="text-caption text-muted-foreground">
                                {assignment
                                    ? 'Assigned responsibility'
                                    : latestReceipt
                                      ? 'Latest acknowledged receipt'
                                      : 'Responsibility not yet recorded'}
                            </p>
                        </div>
                    </div>
                    <div className="rounded-lg border bg-muted/40 p-4">
                        <strong className="text-subtle">
                            Latest manual observation
                        </strong>
                        <p className="text-caption mt-1 text-muted-foreground">
                            {data.manual_location
                                ? `${data.manual_location.location} · ${formatDateTime(data.manual_location.observed_at)}`
                                : 'No manual observation recorded. An observation does not confirm custody.'}
                        </p>
                    </div>
                    <div className="flex justify-between gap-3">
                        <TextAction onClick={() => navigate('custody')}>
                            View custody
                        </TextAction>
                        <TextAction onClick={() => navigate('location')}>
                            Location sources
                        </TextAction>
                    </div>
                </Panel>
            </div>
            <div className="grid gap-5 lg:grid-cols-2">
                <Panel
                    title="Original check"
                    icon={ClipboardCheck}
                    actions={lastCheck && <State value={lastCheck.result} />}
                >
                    {lastCheck ? (
                        <>
                            <strong className="text-subtle">
                                {lastCheck.notes || human(lastCheck.result)}
                            </strong>
                            <p className="text-caption text-muted-foreground">
                                {lastCheck.by || 'Author not recorded'} ·{' '}
                                {formatDateTime(lastCheck.at)}
                            </p>
                            <p className="text-subtle">
                                Original answers remain with the submitted
                                check.
                            </p>
                            <TextAction onClick={() => navigate('checks')}>
                                View original checks
                            </TextAction>
                        </>
                    ) : (
                        <Empty>No original checks recorded.</Empty>
                    )}
                </Panel>
                <Panel
                    title="Asset documents"
                    icon={FileText}
                    actions={
                        <TextAction
                            onClick={() => navigate('overview', 'library')}
                        >
                            View all documents
                        </TextAction>
                    }
                >
                    {current.length ? (
                        current
                            .slice(0, 2)
                            .map((file) => (
                                <ActionRow
                                    key={file.id}
                                    icon={FileText}
                                    title={file.name}
                                    description={`Version ${file.version} · ${human(file.state)}`}
                                    action={
                                        <TextAction
                                            onClick={() => setPreview(file)}
                                        >
                                            View file
                                        </TextAction>
                                    }
                                />
                            ))
                    ) : (
                        <Empty>No current documents.</Empty>
                    )}
                </Panel>
            </div>
            <Panel
                title="Recent history"
                icon={History}
                actions={
                    <TextAction onClick={() => navigate('history')}>
                        Full asset history
                    </TextAction>
                }
            >
                {data.events.length ? (
                    data.events
                        .slice(0, 3)
                        .map((event) => (
                            <ActionRow
                                key={event.id}
                                title={human(event.action)}
                                description={event.message}
                                detail={`${formatDateTime(event.at)} · ${event.actor || 'Recorded actor'}`}
                            />
                        ))
                ) : timeline.length ? (
                    timeline
                        .slice(0, 3)
                        .map((event) => (
                            <ActionRow
                                key={`${event.type}-${event.id}`}
                                title={event.summary}
                                detail={formatDateTime(event.date)}
                            />
                        ))
                ) : (
                    <Empty>No history recorded.</Empty>
                )}
            </Panel>
            <FilePreviewDialog
                file={preview}
                onClose={() => setPreview(null)}
            />
        </div>
    );
}
