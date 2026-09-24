import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { SkeletonTable } from '@/components/ui/skeleton-table';
import { Spinner } from '@/components/ui/spinner';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import { formatCurrency } from '@/lib/fleet-utils';
import { Link } from '@inertiajs/react';
import {
    ArrowUpRight,
    CalendarClock,
    FileText,
    History,
    Landmark,
    Link2,
    ReceiptText,
    RefreshCw,
    ShieldCheck,
    Unlink,
    Upload,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { uploadSummary } from './evidence-upload';
import {
    FinanceNotice,
    useFinanceEvidenceUpload,
    vehicleReference,
} from './finance-shared';
import type {
    FinanceFile,
    FinanceLinkedRecord,
    FinanceRequestEvent,
    FinanceReviewRequest,
    VehicleFinanceWorkspace,
} from './finance-types';
import { FinanceReviewWizard, LinkFinanceWizard } from './finance-wizards';
import './finance.css';
import {
    useVehicleCollectionView,
    VehicleCollectionToggle,
    VehicleRecordCollection,
} from './record-collection';
import { isJsonObject, useVehicleRecordCommand } from './record-command';
import './studio.css';
import type { VehicleProfile, VehicleWorkspace } from './types';
import {
    fieldProps,
    RecordDialog,
    StagedFilesField,
    WizardField,
} from './wizard-kit';
import { FILE_STATE_LABELS } from './workspace-model';

export type FinanceDialogTarget =
    | { kind: 'record'; key: string }
    | { kind: 'request'; id: number };

const HEADING = {
    title: 'Ownership, purchasing & costs',
    blurb: 'Linked records keep approvals and payment with Finance.',
};

/**
 * Overview › Finance, built to the approved PKG-02B v13 FinanceStudio: the
 * vehicle's connection to Finance, with linked records and Finance review
 * requests. Finance stays the owner of every approval and payment.
 */
export function FinanceStudio({
    workspace,
    finance,
    onChanged,
}: {
    workspace: VehicleWorkspace;
    /** The lazy Inertia prop; undefined while it loads. */
    finance: VehicleFinanceWorkspace | undefined;
    onChanged: () => void;
}) {
    const layout = useVehicleCollectionView('finance');
    const [target, setTarget] = useState<FinanceDialogTarget | null>(null);
    const [wizard, setWizard] = useState<'link' | 'request' | null>(null);
    const [unlinking, setUnlinking] = useState<FinanceLinkedRecord | null>(
        null,
    );
    const vehicle = workspace.vehicle;
    const reference = vehicleReference(vehicle);

    if (finance === undefined) {
        return <FinanceLoading reference={reference} onReload={onChanged} />;
    }
    if (finance.site_restricted) {
        return (
            <div className="vehicle-studio">
                <FinanceNotice title="Finance records stay with the vehicle’s Site">
                    You can see this vehicle across Sites, but its Finance
                    records are shown only to Finance viewers at{' '}
                    {vehicle.site?.name ?? 'its Site'}.
                </FinanceNotice>
            </div>
        );
    }
    if (!finance.can.view) {
        return (
            <div className="vehicle-studio">
                <FinanceNotice title="Finance access required">
                    This role can report vehicle concerns. Financial records are
                    available to permitted Finance viewers.
                </FinanceNotice>
            </div>
        );
    }
    const { can } = finance;
    // Hidden when nothing is changeable: Finance's own fixed-asset link is
    // locked and purchase orders and invoices need accounts payable access.
    const canLink =
        can.link && (!finance.link_state.finance_fixed_asset || can.link_spend);
    const openRecord = (record: FinanceLinkedRecord) =>
        setTarget({ kind: 'record', key: record.key });
    const openRequest = (request: FinanceReviewRequest) =>
        setTarget({ kind: 'request', id: request.id });

    return (
        <div className="vehicle-studio">
            <div className="studio-page record-workspace">
                <div className="studio-section-heading">
                    <div>
                        <span className="studio-eyebrow">
                            Finance connection · {reference}
                        </span>
                        <h2>{HEADING.title}</h2>
                        <p>{HEADING.blurb}</p>
                    </div>
                    {(canLink || can.request_review) && (
                        <div className="studio-inline">
                            {canLink && (
                                <Button
                                    variant="outline"
                                    onClick={() => setWizard('link')}
                                >
                                    <Link2 className="size-4" />
                                    Link records
                                </Button>
                            )}
                            {can.request_review && (
                                <Button onClick={() => setWizard('request')}>
                                    <ReceiptText className="size-4" />
                                    Request Finance review
                                </Button>
                            )}
                        </div>
                    )}
                </div>

                <div className="record-summary-grid">
                    <section className="studio-card" aria-label="Fixed asset">
                        <Landmark size={20} aria-hidden />
                        <strong>Fixed asset</strong>
                        <span>
                            {finance.fixed_asset?.label ?? 'Not linked'}
                        </span>
                        <small>
                            Financial asset recognition
                            {finance.fixed_asset &&
                            finance.fixed_asset.count > 1
                                ? ` · ${finance.fixed_asset.count} records for Finance to review`
                                : ''}
                        </small>
                    </section>
                    <section className="studio-card" aria-label="Cost centre">
                        <Link2 size={20} aria-hidden />
                        <strong>Cost centre</strong>
                        <span>
                            {finance.cost_centre?.name ?? 'Not assigned'}
                        </span>
                        <small>
                            {finance.cost_centre
                                ? `${finance.cost_centre.code}${finance.cost_centre.active ? '' : ' · inactive'}`
                                : 'No site cost centre in Finance'}
                        </small>
                    </section>
                    <section className="studio-card" aria-label="Review queue">
                        <CalendarClock size={20} aria-hidden />
                        <strong>Review queue</strong>
                        <span>{finance.pending_requests} pending</span>
                        <small>Finance owns the next decision</small>
                    </section>
                </div>

                <section className="studio-card">
                    <div className="studio-section-heading">
                        <h3>Linked Finance records</h3>
                        <VehicleCollectionToggle
                            label="Finance records"
                            view={layout.view}
                            onChange={layout.setView}
                        />
                    </div>
                    <VehicleRecordCollection
                        label="Linked Finance records"
                        view={layout.view}
                        total={finance.records_total}
                        columns={[
                            { label: 'Type' },
                            { label: 'Status' },
                            { label: 'Amount (NZD)' },
                        ]}
                        records={finance.records.map((record) => ({
                            id: record.key,
                            name: record.name,
                            subline: record.restricted
                                ? 'Accounts payable access needed'
                                : (record.reference ?? undefined),
                            icon:
                                record.type === 'fixed_asset'
                                    ? Landmark
                                    : ReceiptText,
                            tone: record.available ? undefined : 'warning',
                            fields: [
                                <span key="type">{record.kind}</span>,
                                <StatusBadge key="status" variant={record.tone}>
                                    {record.status_label}
                                </StatusBadge>,
                                record.amount === null ? (
                                    <span
                                        key="amount"
                                        className="text-muted-foreground"
                                    >
                                        {record.restricted
                                            ? 'Restricted'
                                            : 'Not recorded'}
                                    </span>
                                ) : (
                                    <strong key="amount">
                                        {formatCurrency(record.amount)}
                                    </strong>
                                ),
                            ],
                            onOpen: () => openRecord(record),
                            footer: {
                                primary: record.kind,
                                secondary:
                                    'Approval and payment owned by Finance',
                            },
                            actions: [
                                {
                                    label: 'Open Finance record',
                                    icon: Landmark,
                                    onClick: () => openRecord(record),
                                },
                                ...(record.can_unlink
                                    ? [
                                          { separator: true },
                                          {
                                              label: 'Remove link',
                                              icon: Unlink,
                                              danger: true,
                                              onClick: () =>
                                                  setUnlinking(record),
                                          },
                                      ]
                                    : []),
                            ],
                        }))}
                        empty={{ title: 'No Finance records linked yet' }}
                    />
                    <p className="studio-footnote">
                        Purchase orders and invoices represent different stages
                        of the same spend. They are not added together as
                        vehicle costs.
                    </p>
                </section>

                <section className="studio-card">
                    <h3>Finance review requests</h3>
                    <VehicleRecordCollection
                        label="Finance review requests"
                        view={layout.view}
                        columns={[{ label: 'Source' }, { label: 'Status' }]}
                        records={finance.requests.map((request) => ({
                            id: request.id,
                            name: request.type_label,
                            subline: request.reference ?? undefined,
                            icon: CalendarClock,
                            fields: [
                                <span key="source">
                                    {request.source.label}
                                </span>,
                                <StatusBadge
                                    key="status"
                                    variant={request.tone}
                                >
                                    {request.status_label}
                                </StatusBadge>,
                            ],
                            onOpen: () => openRequest(request),
                            footer: {
                                primary: 'Finance review',
                                secondary: 'Supporting records retained',
                            },
                            actions: [
                                {
                                    label: 'Open review request',
                                    icon: Landmark,
                                    onClick: () => openRequest(request),
                                },
                            ],
                        }))}
                        empty={{
                            title: 'No requests yet',
                            description:
                                'Submit a quote, invoice or ownership correction with its supporting files.',
                        }}
                    />
                </section>

                <FinanceNotice title="Finance remains the owner">
                    Vehicle edits and Maintenance completion do not approve
                    spend, pay invoices, post depreciation or dispose of the
                    fixed asset. Review requests wait for Finance in All Tasks;
                    nothing is sent outside this app.
                </FinanceNotice>
            </div>

            {target && (
                <FinanceRecordDialog
                    vehicle={vehicle}
                    finance={finance}
                    target={target}
                    onClose={() => setTarget(null)}
                    onChanged={onChanged}
                />
            )}
            {wizard === 'link' && (
                <LinkFinanceWizard
                    workspace={workspace}
                    finance={finance}
                    onClose={() => setWizard(null)}
                    onSaved={onChanged}
                />
            )}
            {wizard === 'request' && (
                <FinanceReviewWizard
                    workspace={workspace}
                    finance={finance}
                    onClose={() => setWizard(null)}
                    onSaved={onChanged}
                />
            )}
            {unlinking && (
                <UnlinkDialog
                    vehicleId={vehicle.id}
                    record={unlinking}
                    onClose={() => setUnlinking(null)}
                    onSaved={onChanged}
                />
            )}
        </div>
    );
}

/** The layout of the view with placeholders while the Finance records load. */
function FinanceLoading({
    reference,
    onReload,
}: {
    reference: string;
    onReload: () => void;
}) {
    const [slow, setSlow] = useState(false);
    useEffect(() => {
        const timer = window.setTimeout(() => setSlow(true), 10000);
        return () => window.clearTimeout(timer);
    }, []);

    return (
        <div className="vehicle-studio" aria-busy="true">
            <div className="studio-page record-workspace">
                <div className="studio-section-heading">
                    <div>
                        <span className="studio-eyebrow">
                            Finance connection · {reference}
                        </span>
                        <h2>{HEADING.title}</h2>
                        <p>{HEADING.blurb}</p>
                    </div>
                </div>
                <p className="sr-only" role="status">
                    Loading Finance records…
                </p>
                <div className="record-summary-grid">
                    {['Fixed asset', 'Cost centre', 'Review queue'].map(
                        (tile) => (
                            <section key={tile} className="studio-card">
                                <Skeleton className="size-5" />
                                <strong>{tile}</strong>
                                <Skeleton className="h-4 w-28" />
                                <Skeleton className="h-3 w-40" />
                            </section>
                        ),
                    )}
                </div>
                <section className="studio-card">
                    <h3>Linked Finance records</h3>
                    <SkeletonTable rows={3} columns={4} />
                </section>
                <section className="studio-card">
                    <h3>Finance review requests</h3>
                    <SkeletonTable rows={2} columns={3} />
                </section>
                {slow && (
                    <div className="flex flex-wrap items-center gap-3 text-sm text-muted-foreground">
                        Finance records are taking longer than usual.
                        <Button size="sm" variant="outline" onClick={onReload}>
                            <RefreshCw className="size-4" />
                            Reload Finance records
                        </Button>
                    </div>
                )}
            </div>
        </div>
    );
}

function relationship(vehicle: VehicleProfile): string {
    return (
        [vehicle.asset_tag, vehicle.registration_number]
            .filter(Boolean)
            .join(' · ') || vehicle.name
    );
}

function historyLine(event: FinanceRequestEvent): string {
    return [
        event.occurred_at ? formatDateTime(event.occurred_at) : null,
        event.actor ?? 'A former user',
        event.note ? `${event.label}: ${event.note}` : event.label,
    ]
        .filter(Boolean)
        .join(' · ');
}

/**
 * The linked record or review request, as in the approved
 * FinanceRecordDialog. Finance users decide open requests here; approval and
 * payment of the record itself stay on its Finance page.
 */
export function FinanceRecordDialog({
    vehicle,
    finance,
    target,
    onClose,
    onChanged,
}: {
    vehicle: VehicleProfile;
    finance: VehicleFinanceWorkspace;
    target: FinanceDialogTarget;
    onClose: () => void;
    onChanged: () => void;
}) {
    const [decision, setDecision] = useState<'resolved' | 'declined' | null>(
        null,
    );
    const [addingFiles, setAddingFiles] = useState(false);
    const retry = useVehicleRecordCommand(isJsonObject);
    const record =
        target.kind === 'record'
            ? finance.records.find((item) => item.key === target.key)
            : undefined;
    const request =
        target.kind === 'request'
            ? finance.requests.find((item) => item.id === target.id)
            : undefined;
    const accessible =
        record && record.available && !record.restricted ? record : null;
    const files = record?.files ?? request?.files ?? [];
    const title = accessible
        ? [accessible.kind, accessible.reference].filter(Boolean).join(' · ')
        : request
          ? ['Finance review', request.reference].filter(Boolean).join(' · ')
          : 'Finance record unavailable';
    const retryCheck = async (file: FinanceFile) => {
        if (
            await retry.submit(
                `/fleet-assets/vehicles/${vehicle.id}/document-files/${file.id}/retry`,
                {},
            )
        ) {
            retry.reset();
            onChanged();
        }
    };

    return (
        <>
            <Dialog open onOpenChange={(next) => !next && onClose()}>
                <DialogContent
                    className="vehicle-studio finance-record-dialog flex max-h-[90vh] flex-col gap-0 overflow-hidden bg-card p-0"
                    style={{
                        width: 'min(92vw, 720px)',
                        maxWidth: 'min(92vw, 720px)',
                    }}
                >
                    <DialogHeader className="flex shrink-0 flex-row items-start gap-3.5 border-b pt-6 pr-13 pb-5 pl-6 text-left">
                        <span className="grid size-[42px] shrink-0 place-items-center rounded-xl border border-primary/25 bg-primary/10 text-primary">
                            <Landmark className="size-5" aria-hidden />
                        </span>
                        <div className="min-w-0">
                            <DialogTitle className="text-section-title">
                                {title}
                            </DialogTitle>
                            <DialogDescription className="mt-1.5">
                                {request
                                    ? 'Finance review request'
                                    : 'Linked Finance record'}
                            </DialogDescription>
                        </div>
                    </DialogHeader>
                    <div className="grid min-h-0 gap-4.5 overflow-x-hidden overflow-y-auto px-6 py-5">
                        {accessible ? (
                            <>
                                <div className="finance-detail-hero">
                                    <span className="feature-icon">
                                        <Landmark size={25} aria-hidden />
                                    </span>
                                    <div>
                                        <h3>{accessible.name}</h3>
                                        <small>{accessible.owner}</small>
                                    </div>
                                    <StatusBadge variant={accessible.tone}>
                                        {accessible.status_label}
                                    </StatusBadge>
                                </div>
                                <dl className="facts-grid">
                                    <div>
                                        <dt>Recorded amount</dt>
                                        <dd>
                                            {accessible.amount === null
                                                ? 'Not recorded'
                                                : formatCurrency(
                                                      accessible.amount,
                                                  )}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>Record date</dt>
                                        <dd>
                                            {formatDateOnly(
                                                accessible.date,
                                                'Not recorded',
                                            )}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>Vehicle relationship</dt>
                                        <dd>{relationship(vehicle)}</dd>
                                    </div>
                                    <div>
                                        <dt>Finance workspace</dt>
                                        <dd>{accessible.workspace}</dd>
                                    </div>
                                </dl>
                                <FinanceNotice title="Record context">
                                    {accessible.detail && (
                                        <p>{accessible.detail}</p>
                                    )}
                                    {accessible.basis_note && (
                                        <p>{accessible.basis_note}</p>
                                    )}
                                </FinanceNotice>
                                <div className="record-flow">
                                    <span>
                                        <FileText size={17} aria-hidden />
                                        Source evidence
                                    </span>
                                    <span>
                                        <Link2 size={17} aria-hidden />
                                        Finance review
                                    </span>
                                    <span>
                                        <ShieldCheck size={17} aria-hidden />
                                        Separate approval / payment
                                    </span>
                                </div>
                            </>
                        ) : request ? (
                            <>
                                <div className="finance-detail-hero">
                                    <ReceiptText size={25} aria-hidden />
                                    <h3>{request.type_label}</h3>
                                    <StatusBadge variant={request.tone}>
                                        {request.status_label}
                                    </StatusBadge>
                                </div>
                                <dl className="facts-grid">
                                    <div>
                                        <dt>Source</dt>
                                        <dd>{request.source.label}</dd>
                                    </div>
                                    <div>
                                        <dt>Requested amount</dt>
                                        <dd>
                                            {request.amount === null
                                                ? 'Not supplied'
                                                : formatCurrency(
                                                      request.amount,
                                                  )}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>Queue owner</dt>
                                        <dd>Finance team</dd>
                                    </div>
                                    <div>
                                        <dt>Delivery</dt>
                                        <dd>All Tasks for Finance</dd>
                                    </div>
                                </dl>
                                <p className="finance-request-note">
                                    {request.note}
                                </p>
                                <div className="compact-timeline">
                                    {request.history.map((event) => (
                                        <div key={event.id}>
                                            <History size={14} aria-hidden />
                                            <span>{historyLine(event)}</span>
                                        </div>
                                    ))}
                                </div>
                            </>
                        ) : (
                            <FinanceNotice title="No accessible linked record">
                                {record?.restricted
                                    ? 'Purchase orders and supplier invoices need accounts payable access. Request Finance review if something needs checking.'
                                    : record
                                      ? 'This record is no longer in Finance. Remove the link, or request Finance review.'
                                      : 'Choose an existing record or request Finance review.'}
                            </FinanceNotice>
                        )}
                        <h3>Linked documents · {files.length}</h3>
                        {retry.message && (
                            <p
                                role="alert"
                                className="text-sm text-status-warning"
                            >
                                {retry.message}
                            </p>
                        )}
                        {files.length > 0 && (
                            <div className="attachment-list">
                                {files.map((file) => (
                                    <AttachmentItem
                                        key={file.id}
                                        file={file}
                                        onRetry={
                                            finance.can.attach_files &&
                                            file.waiting &&
                                            !file.url
                                                ? () => void retryCheck(file)
                                                : undefined
                                        }
                                        retrying={retry.processing}
                                    />
                                ))}
                            </div>
                        )}
                        {!files.length && (
                            <p className="studio-footnote">
                                No file attached to this record.
                            </p>
                        )}
                    </div>
                    <DialogFooter className="flex shrink-0 flex-row flex-wrap items-center justify-between gap-2 border-t bg-muted px-6 py-4 sm:justify-between">
                        <Button variant="outline" onClick={onClose}>
                            Back to vehicle
                        </Button>
                        <div className="flex flex-wrap items-center gap-2">
                            {request?.can_add_files && (
                                <Button
                                    variant="outline"
                                    onClick={() => setAddingFiles(true)}
                                >
                                    <Upload className="size-4" />
                                    Add supporting files
                                </Button>
                            )}
                            {request?.can_decide && (
                                <>
                                    <Button
                                        variant="outline"
                                        onClick={() => setDecision('declined')}
                                    >
                                        Decline
                                    </Button>
                                    <Button
                                        onClick={() => setDecision('resolved')}
                                    >
                                        Resolve
                                    </Button>
                                </>
                            )}
                            {accessible?.href && (
                                <Button asChild>
                                    <Link href={accessible.href}>
                                        Open in Finance
                                        <ArrowUpRight className="size-4" />
                                    </Link>
                                </Button>
                            )}
                        </div>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            {request && decision && (
                <DecisionDialog
                    vehicleId={vehicle.id}
                    request={request}
                    decision={decision}
                    onClose={() => setDecision(null)}
                    onSaved={onChanged}
                />
            )}
            {request && addingFiles && (
                <AddRequestFilesDialog
                    vehicleId={vehicle.id}
                    request={request}
                    onClose={() => setAddingFiles(false)}
                    onSaved={onChanged}
                />
            )}
        </>
    );
}

function AttachmentItem({
    file,
    onRetry,
    retrying,
}: {
    file: FinanceFile;
    onRetry?: () => void;
    retrying: boolean;
}) {
    const state =
        file.state && file.state !== 'available'
            ? FILE_STATE_LABELS[file.state]
            : null;
    const body = (
        <>
            <FileText size={20} aria-hidden />
            <div>
                <strong>{file.name}</strong>
                {state && <small>{state.label}</small>}
            </div>
        </>
    );
    if (file.url) {
        return (
            <a
                className="attachment-item"
                href={file.url}
                target="_blank"
                rel="noreferrer"
            >
                {body}
            </a>
        );
    }

    return (
        <div className="attachment-item">
            {body}
            {onRetry && (
                <Button
                    size="sm"
                    variant="ghost"
                    disabled={retrying}
                    onClick={onRetry}
                >
                    {retrying ? (
                        <Spinner className="size-4" />
                    ) : (
                        <RefreshCw className="size-4" />
                    )}
                    Retry check
                </Button>
            )}
        </div>
    );
}

function UnlinkDialog({
    vehicleId,
    record,
    onClose,
    onSaved,
}: {
    vehicleId: number;
    record: FinanceLinkedRecord;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [reason, setReason] = useState('');
    const [error, setError] = useState('');
    const command = useVehicleRecordCommand(isJsonObject);
    const shown = error || command.errors.reason;
    const label =
        [record.reference, record.name].filter(Boolean).join(' · ') ||
        record.kind;
    const save = async () => {
        if (!reason.trim()) {
            setError('Record why this link is removed.');
            return;
        }
        const result = await command.submit(
            `/fleet-assets/vehicles/${vehicleId}/finance/links/${String(record.link_id)}/unlink`,
            { reason: reason.trim() },
        );
        if (result) {
            onSaved();
            onClose();
        }
    };

    return (
        <RecordDialog
            title="Remove Finance link"
            description={`${label} stays in Finance. Only its link to this vehicle is removed, and the reason stays in the link history.`}
            command={command}
            submitLabel="Remove link"
            onSubmit={save}
            onClose={() => {
                if (command.requiresReload) onSaved();
                onClose();
            }}
        >
            <WizardField
                id="finance-unlink-reason"
                label="Reason"
                error={shown}
            >
                <Textarea
                    {...fieldProps('finance-unlink-reason', shown)}
                    rows={3}
                    maxLength={2000}
                    value={reason}
                    onChange={(event) => {
                        setReason(event.target.value);
                        setError('');
                        command.clearError('reason');
                    }}
                />
            </WizardField>
        </RecordDialog>
    );
}

function DecisionDialog({
    vehicleId,
    request,
    decision,
    onClose,
    onSaved,
}: {
    vehicleId: number;
    request: FinanceReviewRequest;
    decision: 'resolved' | 'declined';
    onClose: () => void;
    onSaved: () => void;
}) {
    const [note, setNote] = useState('');
    const [error, setError] = useState('');
    const command = useVehicleRecordCommand(isJsonObject);
    const shown = error || command.errors.note;
    const resolving = decision === 'resolved';
    const save = async () => {
        if (!note.trim()) {
            setError('Record the Finance decision and any next step.');
            return;
        }
        const result = await command.submit(
            `/fleet-assets/vehicles/${vehicleId}/finance/review-requests/${request.id}/decision`,
            {
                decision,
                note: note.trim(),
                expected_version: request.lock_version,
            },
        );
        if (result) {
            onSaved();
            onClose();
        }
    };

    return (
        <RecordDialog
            title={
                resolving ? 'Resolve Finance review' : 'Decline Finance review'
            }
            description={`${[request.reference, request.type_label].filter(Boolean).join(' · ')}. The requester sees this note. Approvals, payments and postings stay in their own Finance records.`}
            command={command}
            submitLabel={resolving ? 'Resolve request' : 'Decline request'}
            onSubmit={save}
            onClose={() => {
                if (command.requiresReload) onSaved();
                onClose();
            }}
        >
            <WizardField
                id="finance-decision-note"
                label="Decision note"
                error={shown}
                hint={
                    resolving
                        ? 'What Finance did or will do, for example a credit note requested.'
                        : 'Why Finance is not taking this further, and who should.'
                }
            >
                <Textarea
                    {...fieldProps('finance-decision-note', shown)}
                    rows={3}
                    maxLength={2000}
                    value={note}
                    onChange={(event) => {
                        setNote(event.target.value);
                        setError('');
                        command.clearError('note');
                    }}
                />
            </WizardField>
        </RecordDialog>
    );
}

/** Add supporting files to an open request, for example after an upload failed. */
function AddRequestFilesDialog({
    vehicleId,
    request,
    onClose,
    onSaved,
}: {
    vehicleId: number;
    request: FinanceReviewRequest;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [files, setFiles] = useState<File[]>([]);
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [done, setDone] = useState<string | null>(null);
    const { upload, command } = useFinanceEvidenceUpload(vehicleId);
    const save = async () => {
        const found: Record<string, string> = {};
        if (!files.length) found.files = 'Attach at least one file.';
        if (!reason.trim()) found.reason = 'Record what these files show.';
        setErrors(found);
        if (Object.keys(found).length) return;
        const outcome = await upload(files, {
            category: request.type_label,
            reason: reason.trim(),
            requestId: request.id,
        });
        if (outcome) {
            setDone(
                `Supporting files added to ${request.reference ?? 'the request'}.${uploadSummary(outcome, files.length)}`,
            );
            onSaved();
        }
    };

    return (
        <Dialog
            open
            onOpenChange={(next) => !next && !command.processing && onClose()}
        >
            <DialogContent className="max-w-xl">
                <DialogHeader>
                    <DialogTitle>Add supporting files</DialogTitle>
                    <DialogDescription>
                        {[request.reference, request.type_label]
                            .filter(Boolean)
                            .join(' · ')}{' '}
                        · files are stored privately and open for Finance
                        viewers once they pass a virus check.
                    </DialogDescription>
                </DialogHeader>
                {done ? (
                    <p className="text-sm" role="status">
                        {done}
                    </p>
                ) : (
                    <div className="grid gap-4">
                        {command.message && (
                            <p
                                role="alert"
                                className="rounded-lg bg-status-warning-bg p-3 text-sm text-status-warning"
                            >
                                {command.errors['files.0'] ??
                                    command.errors.source_id ??
                                    command.message}
                            </p>
                        )}
                        <StagedFilesField
                            label="Files"
                            files={files}
                            onChange={setFiles}
                            error={errors.files}
                            optional={false}
                        />
                        <WizardField
                            id="finance-files-reason"
                            label="What these files show"
                            error={errors.reason ?? command.errors.reason}
                        >
                            <Textarea
                                {...fieldProps(
                                    'finance-files-reason',
                                    errors.reason,
                                )}
                                rows={2}
                                maxLength={2000}
                                value={reason}
                                onChange={(event) =>
                                    setReason(event.target.value)
                                }
                            />
                        </WizardField>
                    </div>
                )}
                <DialogFooter>
                    {done ? (
                        <Button onClick={onClose}>Done</Button>
                    ) : (
                        <>
                            <Button
                                variant="outline"
                                disabled={command.processing}
                                onClick={onClose}
                            >
                                Cancel
                            </Button>
                            <Button
                                disabled={
                                    command.processing || command.requiresReload
                                }
                                onClick={save}
                            >
                                {command.processing && (
                                    <Spinner className="size-4" />
                                )}
                                {command.uncertain
                                    ? 'Retry upload'
                                    : 'Save files'}
                            </Button>
                        </>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
