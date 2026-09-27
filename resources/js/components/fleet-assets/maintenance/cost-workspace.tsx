import { DocumentPreview } from '@/components/finance/document-preview';
import { formatMoney } from '@/components/finance/money';
import {
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import {
    ReviewRequestDialog,
    type ReviewRequest,
} from '@/pages/finance/vehicle-reviews/_dialogs';
import { Link, router } from '@inertiajs/react';
import {
    ArrowUpRight,
    FileText,
    History,
    Link2,
    ReceiptText,
    ShieldCheck,
    Upload,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';
import {
    isReviewFileResponse,
    isReviewFilesResponse,
    isReviewRequestResponse,
} from '../vehicle-workspace/finance-command-results';
import {
    isSavedResponse,
    useVehicleRecordCommand,
} from '../vehicle-workspace/record-command';
import {
    StagedFilesField,
    WizardField,
    WorkspaceWizard,
} from '../vehicle-workspace/wizard-kit';
import { todayInAuckland } from '../vehicle-workspace/workspace-model';

export type CostWork = {
    id: number;
    title: string;
    reference_number: string | null;
    status: string;
    version: number;
    description: string | null;
    asset: { id: number; name: string; registration_number: string | null };
    assigned_to: { name: string } | null;
};
type Estimate = {
    id: number;
    version: number;
    vendor_id: number;
    vendor_name: string;
    quote_reference: string;
    amount: string;
    cost_centre_id: number | null;
    document_ids: number[];
    reason: string;
    actor: string;
    at: string;
};
type CostDocument = {
    id: number;
    name: string;
    state: string;
    mime: string;
    revision: number;
    url: string | null;
};
export type CostWorkspaceData = {
    summary: {
        committed: string | null;
        invoice: string | null;
        posted: string | null;
        paid: string | null;
        incomplete: boolean;
    };
    site_name: string | null;
    estimates: Estimate[];
    documents: CostDocument[];
    bills: Array<{
        id: number;
        reference: string;
        status: string;
        total: string;
        paid: string;
        vendor: string | null;
        url: string;
        purchase_order: {
            id: number;
            po_number: string;
            total_amount: string;
            status: string;
        } | null;
        journal: { id: number; journal_number: string; status: string } | null;
    }>;
    unavailable_bill_count: number;
    requests: ReviewRequest[];
    vendors: Array<{ id: number; name: string }>;
    cost_centres: Array<{ id: number; code: string; name: string }>;
    can: { estimate: boolean; upload: boolean; request: boolean };
};

export function WorkCostMeters({ data }: { data: CostWorkspaceData }) {
    const estimate = data.estimates[0];
    const hasPosting = data.bills.some(
        (bill) =>
            bill.status !== 'cancelled' && bill.journal?.status === 'posted',
    );
    const { summary } = data;
    const stages = [
        {
            label: 'Estimate',
            value: estimate ? formatMoney(estimate.amount) : '—',
            caption: estimate
                ? `${estimate.quote_reference} · incl. quoted tax`
                : 'Not recorded',
            href: '#work-cost-records',
        },
        {
            label: 'Committed',
            value:
                summary.committed === null
                    ? '—'
                    : formatMoney(summary.committed),
            caption:
                summary.committed === null
                    ? 'No approved linked order'
                    : 'Linked approved order totals',
            href: '#work-cost-records',
        },
        {
            label: 'Invoice',
            value:
                summary.invoice === null ? '—' : formatMoney(summary.invoice),
            caption:
                summary.invoice === null
                    ? 'No active linked bill'
                    : 'Active linked bills · incl. tax',
            href: '#work-cost-records',
        },
        {
            label: 'Posted',
            value:
                summary.posted === null
                    ? '—'
                    : !hasPosting
                      ? 'Not posted'
                      : formatMoney(summary.posted),
            caption: 'Gross bills with a posted journal',
            href: '#work-cost-records',
        },
        {
            label: 'Paid',
            value: summary.paid === null ? '—' : formatMoney(summary.paid),
            caption:
                summary.paid === null
                    ? 'Unknown without an active bill'
                    : 'Bill payment allocations to date',
            href: '#work-cost-records',
        },
    ];
    return stages.map((stage, index) => (
        <PageHeaderMeterBlock
            key={stage.label}
            label={stage.label}
            className="[&>span:last-child]:whitespace-normal"
            ariaLabel={`View ${stage.label.toLowerCase()} source records`}
            onClick={() => {
                const target = document.getElementById(stage.href.slice(1));
                target?.scrollIntoView({ block: 'start', behavior: 'instant' });
                target?.focus({ preventScroll: true });
            }}
        >
            <PageHeaderMeterBig>
                {index > 0 && summary.incomplete ? 'Incomplete' : stage.value}
            </PageHeaderMeterBig>
            <PageHeaderMeterCaption>
                {index > 0 && summary.incomplete
                    ? 'Some linked records are unavailable'
                    : stage.caption}
            </PageHeaderMeterCaption>
        </PageHeaderMeterBlock>
    ));
}
const context = (work: CostWork) => ({
    name: work.reference_number ?? `Work ${work.id}`,
    detail: work.title,
});
const steps = [
    {
        key: 'context',
        label: 'Quote & context',
        blurb: 'Supplier and estimate',
        icon: ReceiptText,
    },
    {
        key: 'evidence',
        label: 'Evidence',
        blurb: 'Quote and supporting files',
        icon: FileText,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check before recording',
        icon: ShieldCheck,
    },
];
function Panel({
    title,
    id,
    action,
    children,
}: {
    title: string;
    id?: string;
    action?: ReactNode;
    children: ReactNode;
}) {
    return (
        <Card
            id={id}
            tabIndex={id ? -1 : undefined}
            className="min-w-0 scroll-mt-5"
        >
            <CardHeader className="flex flex-row items-center justify-between gap-3">
                <CardTitle>{title}</CardTitle>
                {action}
            </CardHeader>
            <CardContent className="space-y-4">{children}</CardContent>
        </Card>
    );
}

export function WorkCostWorkspace({
    work,
    data,
    canManage,
    notes,
    attachments,
    onWork,
    onComplete,
    estimateOpen = false,
    onEstimateClose,
    showHeading = true,
    safetyHeld,
    onRelease,
}: {
    work: CostWork;
    data: CostWorkspaceData | null;
    canManage: boolean;
    currentUserId?: number;
    notes: Array<{ id: number; text: string; actor: string; at: string }>;
    attachments: Array<{ id: number; original_name: string }>;
    onWork: () => void;
    onComplete: () => void;
    estimateOpen?: boolean;
    onEstimateClose?: () => void;
    showHeading?: boolean;
    safetyHeld?: boolean;
    onRelease?: () => void;
}) {
    const [modal, setModal] = useState<'estimate' | 'link' | 'request' | null>(
        null,
    );
    const [review, setReview] = useState<ReviewRequest | null>(null);
    const [preview, setPreview] = useState<CostDocument | null>(null);
    const [history, setHistory] = useState(false);
    const [note, setNote] = useState('');
    const command = useVehicleRecordCommand(isSavedResponse);
    const retryScan = useVehicleRecordCommand(isReviewFileResponse);
    const [retryFile, setRetryFile] = useState<number | null>(null);
    const estimate = data?.estimates[0];
    const latestRequest = data?.requests[0];
    const addNote = async () => {
        if (!note.trim()) return;
        const result = await command.submit(
            `/fleet-assets/maintenance/work-orders/${work.id}`,
            { operation: 'note', version: work.version, note },
            { method: 'PUT' },
        );
        if (result) {
            setNote('');
            command.reset();
            router.reload();
        }
    };
    if (!data)
        return (
            <Panel title="Cost & evidence">
                <p>
                    Finance details require accounts payable access at this
                    Site.
                </p>
                <Button variant="outline" onClick={onWork}>
                    Back to work detail
                </Button>
            </Panel>
        );
    return (
        <section
            className="pkg03-cost-workspace space-y-5"
            aria-label="Cost and evidence"
        >
            {showHeading && (
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 className="text-section-title">Cost & evidence</h2>
                        <p className="text-caption">
                            {work.asset.name} · {data.site_name ?? 'Site'} ·{' '}
                            {work.reference_number}
                        </p>
                    </div>
                    {data.can.estimate && (
                        <Button onClick={() => setModal('estimate')}>
                            <ReceiptText className="size-4" />
                            Record estimate
                        </Button>
                    )}
                </div>
            )}
            <div className="grid gap-5 xl:grid-cols-[minmax(0,2fr)_minmax(300px,1fr)]">
                <div className="min-w-0 space-y-5">
                    <Panel
                        title="Cost context"
                        action={
                            <StatusBadge
                                variant={
                                    work.status === 'completed'
                                        ? 'success'
                                        : 'warning'
                                }
                            >
                                {work.status.replaceAll('_', ' ')}
                            </StatusBadge>
                        }
                    >
                        <p className="text-sm leading-relaxed">
                            {work.description || work.title}
                        </p>
                        <p className="text-caption">
                            Repair completion, safety release, Finance review
                            and payment retain their own decisions.
                        </p>
                        <Button variant="outline" size="sm" onClick={onWork}>
                            Open original work record
                            <ArrowUpRight className="size-4" />
                        </Button>
                    </Panel>
                    <Panel
                        title="Linked cost records"
                        id="work-cost-records"
                        action={
                            canManage && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setModal('link')}
                                >
                                    <Link2 className="size-4" />
                                    Link bill
                                </Button>
                            )
                        }
                    >
                        <p className="text-caption">
                            Each stage describes the same spend. Values are not
                            added together.
                        </p>
                        <div className="flex flex-wrap justify-between gap-3 border-b pb-4">
                            <div className="min-w-0 break-words">
                                <strong>Recorded estimate</strong>
                                <p className="text-caption">
                                    {estimate
                                        ? `${estimate.vendor_name} · ${estimate.quote_reference}`
                                        : 'No estimate recorded'}
                                </p>
                            </div>
                            <strong className="tabular-nums">
                                {estimate ? formatMoney(estimate.amount) : '—'}
                            </strong>
                        </div>
                        {data.bills.map((bill) => (
                            <div
                                key={bill.id}
                                className="space-y-2 border-b pb-4"
                            >
                                <div className="flex flex-wrap justify-between gap-3">
                                    <Link
                                        href={bill.url}
                                        className="font-semibold text-primary hover:underline"
                                    >
                                        {bill.reference} · Supplier bill
                                    </Link>
                                    <strong className="shrink-0 tabular-nums">
                                        {formatMoney(bill.total)}
                                    </strong>
                                </div>
                                <p className="text-caption">
                                    {bill.vendor} ·{' '}
                                    {bill.status.replaceAll('_', ' ')} ·{' '}
                                    {formatMoney(bill.paid)} paid
                                </p>
                                {bill.purchase_order && (
                                    <p className="text-sm">
                                        <Link
                                            className="text-primary hover:underline"
                                            href={`/finance/purchase-orders/${bill.purchase_order.id}`}
                                        >
                                            Purchase order{' '}
                                            {bill.purchase_order.po_number}
                                        </Link>{' '}
                                        ·{' '}
                                        {formatMoney(
                                            bill.purchase_order.total_amount,
                                        )}{' '}
                                        ·{' '}
                                        {bill.purchase_order.status.replaceAll(
                                            '_',
                                            ' ',
                                        )}{' '}
                                        order
                                    </p>
                                )}
                                {bill.journal && (
                                    <Link
                                        href={`/finance/journals/${bill.journal.id}`}
                                        className="text-sm text-primary hover:underline"
                                    >
                                        Journal {bill.journal.journal_number} ·{' '}
                                        {bill.journal.status}
                                    </Link>
                                )}
                                {estimate && (
                                    <p className="text-caption">
                                        {formatMoney(
                                            Math.abs(
                                                Number(bill.total) -
                                                    Number(estimate.amount),
                                            ),
                                        )}{' '}
                                        {Number(bill.total) >=
                                        Number(estimate.amount)
                                            ? 'above'
                                            : 'below'}{' '}
                                        the recorded estimate
                                    </p>
                                )}
                            </div>
                        ))}
                        {!data.bills.length && (
                            <p className="text-sm text-muted-foreground">
                                No supplier bill linked. Link an existing bill
                                for this resource and Site.
                            </p>
                        )}
                        {data.unavailable_bill_count > 0 && (
                            <p role="status" className="text-status-warning">
                                A linked Finance source is unavailable. Accounts
                                payable needs to reconcile the link.
                            </p>
                        )}
                    </Panel>
                    <Panel
                        title="Evidence"
                        action={
                            data.can.estimate && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setModal('estimate')}
                                >
                                    <Upload className="size-4" />
                                    Add / replace quote
                                </Button>
                            )
                        }
                    >
                        {data.documents.map((file) => (
                            <div
                                className="flex flex-wrap items-center justify-between gap-3 border-b pb-3"
                                key={file.id}
                            >
                                <div>
                                    <strong className="text-sm">
                                        {file.name}
                                    </strong>
                                    <p className="text-caption">
                                        Version {file.revision} ·{' '}
                                        {file.state.replaceAll('_', ' ')}
                                    </p>
                                </div>
                                {file.url ? (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setPreview(file)}
                                    >
                                        Preview
                                    </Button>
                                ) : data.can.upload &&
                                  [
                                      'stored',
                                      'scan_unavailable',
                                      'publication_failed',
                                  ].includes(file.state) ? (
                                    <Button
                                        disabled={retryScan.locked}
                                        variant="outline"
                                        size="sm"
                                        onClick={async () => {
                                            setRetryFile(file.id);
                                            if (
                                                await retryScan.submit(
                                                    `/fleet-assets/vehicles/${work.asset.id}/document-files/${file.id}/retry`,
                                                    {},
                                                )
                                            ) {
                                                retryScan.reset();
                                                router.reload();
                                            }
                                        }}
                                    >
                                        Retry virus check
                                    </Button>
                                ) : (
                                    <span className="text-caption">
                                        Preview unavailable
                                    </span>
                                )}
                            </div>
                        ))}
                        {!data.documents.length && (
                            <p className="text-sm text-muted-foreground">
                                No quote evidence recorded. Add files when
                                recording the estimate.
                            </p>
                        )}
                        {retryScan.message && (
                            <p role="alert">{retryScan.message}</p>
                        )}
                        {retryScan.uncertain && retryFile && (
                            <Button
                                variant="outline"
                                disabled={retryScan.processing}
                                onClick={async () => {
                                    if (
                                        await retryScan.submit(
                                            `/fleet-assets/vehicles/${work.asset.id}/document-files/${retryFile}/retry`,
                                            {},
                                        )
                                    ) {
                                        retryScan.reset();
                                        router.reload();
                                    }
                                }}
                            >
                                Retry this check
                            </Button>
                        )}
                        {retryScan.requiresReload && (
                            <Button
                                variant="outline"
                                onClick={() => router.reload()}
                            >
                                Reload evidence
                            </Button>
                        )}
                        {preview && (
                            <div className="space-y-3">
                                <div className="flex justify-between">
                                    <strong>{preview.name}</strong>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => setPreview(null)}
                                    >
                                        Close preview
                                    </Button>
                                </div>
                                <DocumentPreview
                                    url={`${preview.url}?inline=1`}
                                    name={preview.name}
                                    mime={preview.mime}
                                />
                                <a
                                    className="text-primary underline"
                                    href={preview.url!}
                                >
                                    Download original
                                </a>
                            </div>
                        )}
                        {!!attachments.length && (
                            <div>
                                <h3 className="text-sm font-semibold">
                                    Operational evidence
                                </h3>
                                {attachments.map((file) => (
                                    <a
                                        key={file.id}
                                        className="mt-2 flex items-center gap-2 text-sm text-primary hover:underline"
                                        href={`/fleet-assets/maintenance/work-orders/${work.id}/attachments/${file.id}`}
                                    >
                                        <FileText className="size-4" />
                                        {file.original_name}
                                    </a>
                                ))}
                            </div>
                        )}
                    </Panel>
                    <Panel
                        title="Notes & activity"
                        action={
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => setHistory(!history)}
                            >
                                <History className="size-4" />
                                {history ? 'Recent notes' : 'Estimate history'}
                            </Button>
                        }
                    >
                        {history
                            ? data.estimates.map((item) => (
                                  <div
                                      key={item.id}
                                      className="border-l-2 border-primary/25 pl-3"
                                  >
                                      <strong>
                                          {item.quote_reference} ·{' '}
                                          {formatMoney(item.amount)}
                                      </strong>
                                      <p className="text-sm">{item.reason}</p>
                                      <p className="text-caption">
                                          {item.actor} ·{' '}
                                          {formatDateTime(item.at)} · Work
                                          revision {item.version}
                                      </p>
                                  </div>
                              ))
                            : notes.slice(-5).map((item) => (
                                  <div key={item.id}>
                                      <p className="text-sm whitespace-pre-wrap">
                                          {item.text}
                                      </p>
                                      <p className="text-caption">
                                          {item.actor} ·{' '}
                                          {formatDateTime(item.at)}
                                      </p>
                                  </div>
                              ))}
                        {canManage && (
                            <div className="space-y-2">
                                <label
                                    htmlFor="cost-note"
                                    className="text-sm font-medium"
                                >
                                    Add a work note
                                </label>
                                <Textarea
                                    id="cost-note"
                                    value={note}
                                    disabled={command.locked}
                                    onChange={(e) => setNote(e.target.value)}
                                    placeholder="Record what the next person needs to know."
                                />
                                <p className="text-caption">
                                    {note
                                        ? 'Draft retained until this page is closed.'
                                        : 'Notes remain with the work record.'}
                                </p>
                                {command.message && (
                                    <p role="alert">{command.message}</p>
                                )}
                                {command.requiresReload ? (
                                    <Button onClick={() => router.reload()}>
                                        Review latest work
                                    </Button>
                                ) : (
                                    <Button
                                        disabled={
                                            command.processing || !note.trim()
                                        }
                                        onClick={addNote}
                                    >
                                        {command.uncertain
                                            ? 'Retry note'
                                            : 'Add note'}
                                    </Button>
                                )}
                            </div>
                        )}
                    </Panel>
                </div>
                <aside
                    className="min-w-0 space-y-5"
                    aria-label="Next action and independent outcomes"
                >
                    <Panel title="Next action · Finance">
                        <h3 className="text-lg font-semibold">
                            {latestRequest?.status === 'changes_requested'
                                ? 'Respond to Finance'
                                : latestRequest?.status === 'preparing'
                                  ? 'Finish preparing evidence'
                                  : latestRequest?.status === 'submitted'
                                    ? 'Ready for Finance review'
                                    : latestRequest?.status === 'resolved'
                                      ? 'Review complete'
                                      : data.bills.length
                                        ? 'Review the supplier invoice'
                                        : 'Prepare the cost evidence'}
                        </h3>
                        <p className="text-sm text-muted-foreground">
                            {latestRequest?.decision_note ||
                                latestRequest?.note ||
                                'Keep the quote and invoice with this work, then hand the evidence to Finance.'}
                        </p>
                        {latestRequest && (
                            <>
                                <p className="text-caption">
                                    {latestRequest.reference} ·{' '}
                                    {latestRequest.assigned_to ??
                                        'Finance team'}
                                    {latestRequest.due_on
                                        ? ` · Due ${formatDateOnly(latestRequest.due_on)}`
                                        : ''}
                                </p>
                                <Button
                                    className="w-full"
                                    onClick={() => setReview(latestRequest)}
                                >
                                    Open Finance review
                                </Button>
                                {latestRequest.own_request &&
                                    ['preparing', 'changes_requested'].includes(
                                        latestRequest.status,
                                    ) &&
                                    data.can.request && (
                                        <WorkEvidenceResponse
                                            work={work}
                                            request={latestRequest}
                                        />
                                    )}
                            </>
                        )}
                        {data.can.request &&
                            !data.requests.some((r) =>
                                [
                                    'preparing',
                                    'submitted',
                                    'changes_requested',
                                ].includes(r.status),
                            ) && (
                                <Button
                                    className="w-full"
                                    onClick={() => setModal('request')}
                                >
                                    Request Finance review
                                </Button>
                            )}
                        {data.bills[0] && (
                            <Button
                                asChild
                                variant="outline"
                                className="w-full"
                            >
                                <Link href={data.bills[0].url}>
                                    Open supplier bill
                                    <ArrowUpRight className="size-4" />
                                </Link>
                            </Button>
                        )}
                    </Panel>
                    <Panel title="Independent outcomes">
                        <ReviewRow
                            label="Repair work"
                            value={work.status.replaceAll('_', ' ')}
                        />
                        {safetyHeld !== undefined && (
                            <ReviewRow
                                label="Safety restriction"
                                value={
                                    <Button
                                        variant="link"
                                        className="h-auto p-0 text-right whitespace-normal"
                                        onClick={onRelease}
                                    >
                                        {safetyHeld
                                            ? 'Hold active · review release'
                                            : 'No active hold · view release'}
                                    </Button>
                                }
                            />
                        )}
                        <ReviewRow
                            label="Finance review"
                            value={
                                latestRequest?.status_label ?? 'Not requested'
                            }
                        />
                        <ReviewRow
                            label="Bill approval"
                            value={
                                data.bills[0]?.status.replaceAll('_', ' ') ??
                                'No linked bill'
                            }
                        />
                        <ReviewRow
                            label="Ledger posting"
                            value={
                                data.bills[0]?.journal?.status ?? 'No journal'
                            }
                        />
                        <ReviewRow
                            label="Payment"
                            value={
                                data.bills[0]
                                    ? `${formatMoney(data.bills[0].paid)} recorded`
                                    : 'No linked bill'
                            }
                        />
                        <p className="text-caption">
                            Completing work or resolving a review does not
                            approve spending, pay a bill or release a safety
                            hold.
                        </p>
                        {canManage &&
                            !['completed', 'cancelled'].includes(
                                work.status,
                            ) && (
                                <Button variant="outline" onClick={onComplete}>
                                    Check work completion
                                </Button>
                            )}
                    </Panel>
                    {data.requests.length > 1 && (
                        <Panel title="Other review requests">
                            {data.requests.slice(1).map((item) => (
                                <Button
                                    key={item.id}
                                    variant="outline"
                                    className="w-full justify-start"
                                    onClick={() => setReview(item)}
                                >
                                    {item.reference} · {item.status_label}
                                </Button>
                            ))}
                        </Panel>
                    )}
                </aside>
            </div>
            {(modal === 'estimate' || estimateOpen) && (
                <EstimateWizard
                    work={work}
                    data={data}
                    onClose={() => {
                        setModal(null);
                        onEstimateClose?.();
                    }}
                />
            )}
            {modal === 'link' && (
                <LinkWorkBillWizard
                    work={work}
                    onClose={() => setModal(null)}
                />
            )}
            {modal === 'request' && (
                <WorkReviewWizard
                    work={work}
                    data={data}
                    onClose={() => setModal(null)}
                />
            )}
            {review && (
                <ReviewRequestDialog
                    request={review}
                    onClose={() => setReview(null)}
                />
            )}
        </section>
    );
}

function EstimateWizard({
    work,
    data,
    onClose,
}: {
    work: CostWork;
    data: CostWorkspaceData;
    onClose: () => void;
}) {
    const initial = data.estimates[0];
    const [version] = useState(work.version);
    const [step, setStep] = useState(0);
    const [vendor, setVendor] = useState(String(initial?.vendor_id ?? ''));
    const [reference, setReference] = useState(initial?.quote_reference ?? '');
    const [amount, setAmount] = useState(initial?.amount ?? '');
    const [centre, setCentre] = useState(String(initial?.cost_centre_id ?? ''));
    const [reason, setReason] = useState('');
    const [files, setFiles] = useState<File[]>([]);
    const [uploaded, setUploaded] = useState<number[] | null>(null);
    const [saved, setSaved] = useState(false);
    const [error, setError] = useState('');
    const [query, setQuery] = useState('');
    const [vendors, setVendors] = useState(data.vendors);
    const command = useVehicleRecordCommand(isSavedResponse);
    const upload = useVehicleRecordCommand(isReviewFilesResponse);
    const close = () => {
        onClose();
        if (saved) router.reload();
    };
    const valid = () => {
        const ok =
            !!vendor &&
            !!reference.trim() &&
            /^\d+(\.\d{1,2})?$/.test(amount) &&
            Number(amount) > 0 &&
            !!reason.trim();
        setError(
            ok
                ? ''
                : 'Choose a supplier, quote reference, positive amount and reason.',
        );
        return ok;
    };
    const submit = async () => {
        if (!command.uncertain && !upload.uncertain && !valid()) {
            setStep(0);
            return;
        }
        let ids = uploaded ?? initial?.document_ids ?? [];
        if (files.length && !uploaded) {
            const form = new FormData();
            form.append('category', 'Maintenance quote');
            form.append('reference', reference);
            form.append('document_date', todayInAuckland());
            form.append('reason', reason);
            files.forEach((file) => form.append('files[]', file));
            const result = await upload.submit(
                `/fleet-assets/vehicles/${work.asset.id}/documents`,
                form,
            );
            if (!result) return;
            ids = result.files.map((file) => file.id);
            setUploaded(ids);
        }
        const result = await command.submit(
            `/fleet-assets/maintenance/work-orders/${work.id}`,
            {
                operation: 'record_cost_estimate',
                version,
                vendor_id: Number(vendor),
                quote_reference: reference.trim(),
                amount,
                cost_centre_id: centre ? Number(centre) : null,
                document_ids: ids,
                reason: reason.trim(),
            },
            { method: 'PUT' },
        );
        if (result) setSaved(true);
    };
    return (
        <WorkspaceWizard
            title="Record estimate"
            description="Record the quote context. Estimates do not approve spending."
            railIcon={ReceiptText}
            railSub={work.reference_number ?? 'Maintenance'}
            steps={steps}
            step={step}
            setStep={setStep}
            pct={saved ? 100 : reason ? 80 : 25}
            context={context(work)}
            command={{
                ...command,
                processing: command.processing || upload.processing,
                uncertain: command.uncertain || upload.uncertain,
                requiresReload: command.requiresReload || upload.requiresReload,
                locked: command.locked || upload.locked,
                message:
                    Object.values(command.errors).join(' ') ||
                    Object.values(upload.errors).join(' ') ||
                    command.message ||
                    upload.message ||
                    error,
            }}
            dirty={!!reason || files.length > 0}
            saved={saved}
            submitLabel="Record estimate"
            discardDescription={
                uploaded
                    ? 'Unsent estimate changes will be removed. Uploaded files remain in the vehicle’s Documents workspace.'
                    : undefined
            }
            onValidateStep={(at) => (at === 0 ? valid() : true)}
            onSubmit={submit}
            onClose={close}
            onReload={() => {
                onClose();
                router.reload();
            }}
            errorKey={error + JSON.stringify(command.errors)}
            success={
                <WizardSuccessPane
                    title="Estimate recorded"
                    blurb="The quote revision and reason are retained with this work. Finance approval stays separate."
                    actions={
                        <Button onClick={close}>Back to cost & evidence</Button>
                    }
                />
            }
        >
            {step === 0 && (
                <div className="space-y-4">
                    <WizardField id="estimate-vendor" label="Supplier">
                        <select
                            id="estimate-vendor"
                            className="w-full rounded-md border bg-background p-2"
                            value={vendor}
                            onChange={(e) => setVendor(e.target.value)}
                        >
                            <option value="">Choose supplier</option>
                            {initial &&
                                !vendors.some(
                                    (v) => v.id === initial.vendor_id,
                                ) && (
                                    <option value={initial.vendor_id}>
                                        {initial.vendor_name}
                                    </option>
                                )}
                            {vendors.map((v) => (
                                <option key={v.id} value={v.id}>
                                    {v.name}
                                </option>
                            ))}
                        </select>
                    </WizardField>
                    <div className="flex gap-2">
                        <Input
                            aria-label="Find supplier"
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder="Find another supplier…"
                        />
                        <Button
                            variant="outline"
                            onClick={async () => {
                                try {
                                    const res = await fetch(
                                        `/fleet-assets/maintenance/work-orders/options/search?type=finance_vendors&q=${encodeURIComponent(query)}`,
                                        {
                                            headers: {
                                                Accept: 'application/json',
                                            },
                                        },
                                    );
                                    if (!res.ok) throw Error();
                                    setVendors((await res.json()).results);
                                } catch {
                                    setError(
                                        'Suppliers could not be loaded. Try again.',
                                    );
                                }
                            }}
                            disabled={query.length < 2}
                        >
                            Search
                        </Button>
                    </div>
                    <WizardField
                        id="estimate-reference"
                        label="Quote reference"
                    >
                        <Input
                            id="estimate-reference"
                            value={reference}
                            onChange={(e) => setReference(e.target.value)}
                        />
                    </WizardField>
                    <WizardField
                        id="estimate-amount"
                        label="Amount including GST · NZD"
                    >
                        <Input
                            id="estimate-amount"
                            inputMode="decimal"
                            value={amount}
                            onChange={(e) => setAmount(e.target.value)}
                        />
                    </WizardField>
                    <WizardField
                        id="estimate-centre"
                        label="Cost centre"
                        optional
                    >
                        <select
                            id="estimate-centre"
                            className="w-full rounded-md border bg-background p-2"
                            value={centre}
                            onChange={(e) => setCentre(e.target.value)}
                        >
                            <option value="">No allocation recorded</option>
                            {data.cost_centres.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.code} · {c.name}
                                </option>
                            ))}
                        </select>
                    </WizardField>
                    <WizardField
                        id="estimate-reason"
                        label="Reason for this estimate"
                    >
                        <Textarea
                            id="estimate-reason"
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                        />
                    </WizardField>
                </div>
            )}
            {step === 1 && (
                <div className="space-y-4">
                    {uploaded ? (
                        <p>
                            {uploaded.length} file(s) uploaded and retained.
                            Review the estimate, then save it to link these
                            files to the work.
                        </p>
                    ) : data.can.upload ? (
                        <StagedFilesField
                            files={files}
                            onChange={setFiles}
                            label="Quote and supporting files"
                        />
                    ) : (
                        <p>
                            Your current permissions allow recording the
                            estimate; a document manager can attach its
                            evidence.
                        </p>
                    )}
                    <p className="text-caption">
                        New files replace the current quote selection for this
                        estimate. Earlier revisions remain in history. Files
                        remain unavailable until their virus check passes.
                    </p>
                    {!files.length && initial?.document_ids.length ? (
                        <p>
                            {initial.document_ids.length} existing quote file(s)
                            retained.
                        </p>
                    ) : null}
                </div>
            )}
            {step === 2 && (
                <ReviewCard icon={ReceiptText} title="Estimate">
                    <ReviewRow
                        label="Supplier"
                        value={
                            vendors.find((v) => String(v.id) === vendor)
                                ?.name ?? initial?.vendor_name
                        }
                    />
                    <ReviewRow label="Quote" value={reference} />
                    <ReviewRow
                        label="Amount · NZD"
                        value={formatMoney(amount)}
                    />
                    <ReviewRow label="Reason" value={reason} />
                    <ReviewRow
                        label="Cost centre"
                        value={
                            data.cost_centres.find(
                                (item) => String(item.id) === centre,
                            )?.name ?? 'No allocation recorded'
                        }
                    />
                    <ReviewRow
                        label="Retained quote files"
                        value={
                            files.length
                                ? 'Replaced by the new evidence'
                                : `${initial?.document_ids.length ?? 0} file(s)`
                        }
                    />
                    <ReviewRow
                        label="New evidence"
                        value={`${files.length} file(s)`}
                    />
                </ReviewCard>
            )}
        </WorkspaceWizard>
    );
}

function LinkWorkBillWizard({
    work,
    onClose,
}: {
    work: CostWork;
    onClose: () => void;
}) {
    const [query, setQuery] = useState('');
    const [choices, setChoices] = useState<
        Array<{ id: number; bill_number: string; status: string }>
    >([]);
    const [selected, setSelected] = useState<(typeof choices)[number] | null>(
        null,
    );
    const [step, setStep] = useState(0);
    const [error, setError] = useState('');
    const [saved, setSaved] = useState(false);
    const command = useVehicleRecordCommand(isSavedResponse);
    const close = () => {
        onClose();
        if (saved) router.reload();
    };
    return (
        <WorkspaceWizard
            title="Link existing bill"
            description="Choose the canonical Finance bill for this resource and Site."
            railIcon={Link2}
            railSub={work.reference_number ?? 'Maintenance'}
            steps={[
                {
                    key: 'choose',
                    label: 'Choose bill',
                    blurb: 'Search Finance records',
                    icon: ReceiptText,
                },
                {
                    key: 'review',
                    label: 'Review link',
                    blurb: 'Confirm the source',
                    icon: ShieldCheck,
                },
            ]}
            step={step}
            setStep={setStep}
            pct={selected ? 100 : 0}
            context={context(work)}
            command={{ ...command, message: command.message || error }}
            dirty={!!selected}
            saved={saved}
            submitLabel="Link bill"
            onValidateStep={() => !!selected}
            onSubmit={async () => {
                if (
                    selected &&
                    (await command.submit(
                        `/fleet-assets/maintenance/work-orders/${work.id}/finance-bills`,
                        { fin_bill_id: selected.id },
                    ))
                )
                    setSaved(true);
            }}
            onClose={close}
            onReload={() => {
                onClose();
                router.reload();
            }}
            errorKey={error}
            success={
                <WizardSuccessPane
                    title="Bill linked"
                    blurb="The work now shows Finance’s current approval, journal and payment state."
                    actions={<Button onClick={close}>Back to work</Button>}
                />
            }
        >
            {step === 0 ? (
                <div className="space-y-4">
                    <div className="flex gap-2">
                        <Input
                            aria-label="Find Finance bill"
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder="Bill number…"
                        />
                        <Button
                            variant="outline"
                            disabled={query.length < 2}
                            onClick={async () => {
                                setError('');
                                try {
                                    const res = await fetch(
                                        `/fleet-assets/maintenance/work-orders/options/search?type=finance_bills&asset_id=${work.asset.id}&q=${encodeURIComponent(query)}`,
                                        {
                                            headers: {
                                                Accept: 'application/json',
                                            },
                                        },
                                    );
                                    if (!res.ok) throw Error();
                                    const value = await res.json();
                                    setChoices(value.results);
                                    if (!value.results.length)
                                        setError(
                                            'No unlinked bills match this resource, Site and search.',
                                        );
                                } catch {
                                    setError(
                                        'Bills could not be loaded. Try again.',
                                    );
                                }
                            }}
                        >
                            Search
                        </Button>
                    </div>
                    {choices.map((item) => (
                        <Button
                            key={item.id}
                            variant={
                                selected?.id === item.id ? 'default' : 'outline'
                            }
                            className="w-full justify-start"
                            onClick={() => setSelected(item)}
                        >
                            {item.bill_number} ·{' '}
                            {item.status.replaceAll('_', ' ')}
                        </Button>
                    ))}
                </div>
            ) : (
                <ReviewCard icon={Link2} title="Source link">
                    <ReviewRow label="Work" value={work.reference_number} />
                    <ReviewRow label="Bill" value={selected?.bill_number} />
                    <p className="text-caption">
                        This link does not approve, post or copy the cost.
                    </p>
                </ReviewCard>
            )}
        </WorkspaceWizard>
    );
}

function WorkReviewWizard({
    work,
    data,
    onClose,
}: {
    work: CostWork;
    data: CostWorkspaceData;
    onClose: () => void;
}) {
    const [step, setStep] = useState(0);
    const [type, setType] = useState('supplier_invoice_review');
    const [note, setNote] = useState('');
    const [selected, setSelected] = useState('');
    const [files, setFiles] = useState<File[]>([]);
    const [created, setCreated] = useState<{
        id: number;
        reference: string;
    } | null>(null);
    const [saved, setSaved] = useState(false);
    const [error, setError] = useState('');
    const command = useVehicleRecordCommand(isReviewRequestResponse);
    const upload = useVehicleRecordCommand(isReviewFilesResponse);
    const close = () => {
        onClose();
        if (saved || created) router.reload();
    };
    const submit = async () => {
        if (
            !created &&
            !command.uncertain &&
            (!note.trim() || (!selected && !files.length))
        ) {
            setError(
                'Record what Finance needs to review and select or upload supporting evidence.',
            );
            setStep(0);
            return;
        }
        let record = created;
        if (!record) {
            const result = await command.submit(
                `/fleet-assets/vehicles/${work.asset.id}/finance/review-requests`,
                {
                    request_type: type,
                    source: `work_order:${work.id}`,
                    amount: data.estimates[0]?.amount ?? null,
                    note,
                    existing_document_id: selected ? Number(selected) : null,
                    expected_file_count: files.length,
                },
            );
            if (!result) return;
            record = result.request;
            setCreated(record);
        }
        if (files.length) {
            const form = new FormData();
            form.append('category', 'Finance supporting evidence');
            form.append('document_date', todayInAuckland());
            form.append('reason', note);
            form.append('source_type', 'finance_review_request');
            form.append('source_id', String(record.id));
            files.forEach((file) => form.append('files[]', file));
            if (
                !(await upload.submit(
                    `/fleet-assets/vehicles/${work.asset.id}/documents`,
                    form,
                ))
            )
                return;
        }
        setSaved(true);
    };
    return (
        <WorkspaceWizard
            title="Request Finance review"
            description="Hand the evidence to Finance while retaining the work record."
            railIcon={ReceiptText}
            railSub={work.reference_number ?? 'Maintenance'}
            steps={[
                {
                    key: 'request',
                    label: 'Request',
                    blurb: 'Source and question',
                    icon: ReceiptText,
                },
                {
                    key: 'evidence',
                    label: 'Evidence',
                    blurb: 'Supporting documents',
                    icon: FileText,
                },
                {
                    key: 'review',
                    label: 'Review',
                    blurb: 'Confirm the handoff',
                    icon: ShieldCheck,
                },
            ]}
            step={step}
            setStep={setStep}
            pct={note ? 70 : 0}
            context={context(work)}
            command={{
                ...command,
                processing: command.processing || upload.processing,
                uncertain: command.uncertain || upload.uncertain,
                requiresReload: command.requiresReload || upload.requiresReload,
                locked: command.locked || upload.locked,
                message:
                    Object.values(command.errors).join(' ') ||
                    Object.values(upload.errors).join(' ') ||
                    command.message ||
                    upload.message ||
                    error,
            }}
            dirty={!!note || files.length > 0}
            saved={saved}
            submitLabel="Create Finance review request"
            discardDescription={
                created
                    ? 'The request already exists and remains in Preparing evidence. Unsent file selections will be removed; you can finish its evidence from the request.'
                    : undefined
            }
            onValidateStep={() => true}
            onSubmit={submit}
            onClose={close}
            onReload={() => {
                onClose();
                router.reload();
            }}
            errorKey={error}
            success={
                <WizardSuccessPane
                    title="Finance review requested"
                    blurb={`${created?.reference ?? 'The request'} is saved.${files.length ? ' Finish submitting its evidence once every file has passed its check.' : ''}`}
                    actions={
                        <Button onClick={close}>
                            Back to work and request
                        </Button>
                    }
                />
            }
        >
            {step === 0 && (
                <div className="space-y-4">
                    {created && (
                        <p className="text-caption">
                            {created.reference} is already saved. Finish or
                            replace the selected supporting files in the
                            Evidence step.
                        </p>
                    )}
                    <WizardField id="work-review-type" label="Request type">
                        <select
                            id="work-review-type"
                            disabled={!!created}
                            className="w-full rounded-md border bg-background p-2"
                            value={type}
                            onChange={(e) => setType(e.target.value)}
                        >
                            {[
                                [
                                    'supplier_invoice_review',
                                    'Supplier invoice review',
                                ],
                                ['purchase_approval', 'Purchase approval'],
                                [
                                    'cost_allocation_correction',
                                    'Cost allocation correction',
                                ],
                                [
                                    'fixed_asset_update',
                                    'Fixed asset / ownership update',
                                ],
                            ].map(([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </WizardField>
                    <WizardField
                        id="work-review-note"
                        label="What Finance needs to review"
                    >
                        <Textarea
                            id="work-review-note"
                            disabled={!!created}
                            value={note}
                            onChange={(e) => setNote(e.target.value)}
                        />
                    </WizardField>
                </div>
            )}
            {step === 1 && (
                <div className="space-y-4">
                    <WizardField
                        id="work-review-existing"
                        label="Existing quote evidence"
                        optional
                    >
                        <select
                            id="work-review-existing"
                            disabled={!!created}
                            className="w-full rounded-md border bg-background p-2"
                            value={selected}
                            onChange={(e) => setSelected(e.target.value)}
                        >
                            <option value="">Choose an available file</option>
                            {data.documents
                                .filter((file) => file.state === 'available')
                                .map((file) => (
                                    <option key={file.id} value={file.id}>
                                        {file.name}
                                    </option>
                                ))}
                        </select>
                    </WizardField>
                    {data.can.upload && (
                        <StagedFilesField
                            label="Supporting files"
                            files={files}
                            onChange={setFiles}
                        />
                    )}
                </div>
            )}
            {step === 2 && (
                <ReviewCard icon={ShieldCheck} title="Review handoff">
                    <ReviewRow label="Work" value={work.reference_number} />
                    <ReviewRow label="Question" value={note} />
                    <ReviewRow
                        label="Evidence"
                        value={`${files.length} new file(s)${selected ? ' and existing quote' : ''}`}
                    />
                    <p className="text-caption">
                        An independent Finance reviewer decides the request.
                        Bill approval and payment remain separate.
                    </p>
                </ReviewCard>
            )}
        </WorkspaceWizard>
    );
}

function WorkEvidenceResponse({
    work,
    request,
}: {
    work: CostWork;
    request: ReviewRequest;
}) {
    const [open, setOpen] = useState(false);
    const [note, setNote] = useState('');
    const [saved, setSaved] = useState(false);
    const command = useVehicleRecordCommand(isReviewRequestResponse);
    if (!open)
        return (
            <Button
                className="w-full"
                variant="outline"
                onClick={() => setOpen(true)}
            >
                {request.status === 'changes_requested'
                    ? 'Respond and resubmit'
                    : 'Submit prepared evidence'}
            </Button>
        );
    return (
        <WorkspaceWizard
            title="Submit Finance evidence"
            description="All selected files must be available before submitting."
            railIcon={Upload}
            railSub={request.reference ?? 'Finance review'}
            steps={[
                {
                    key: 'response',
                    label: 'Response',
                    blurb: 'Explain the evidence',
                    icon: FileText,
                },
            ]}
            step={0}
            setStep={() => {}}
            pct={100}
            context={context(work)}
            command={{
                ...command,
                message:
                    command.message || Object.values(command.errors).join(' '),
            }}
            dirty={!!note}
            saved={saved}
            submitLabel="Submit evidence"
            onValidateStep={() => true}
            onSubmit={async () => {
                if (
                    await command.submit(
                        `/fleet-assets/vehicles/${work.asset.id}/finance/review-requests/${request.id}/submit`,
                        { expected_version: request.lock_version, note },
                    )
                )
                    setSaved(true);
            }}
            onClose={() => {
                setOpen(false);
                if (saved) router.reload();
            }}
            onReload={() => {
                setOpen(false);
                router.reload();
            }}
            errorKey={JSON.stringify(command.errors)}
            success={
                <WizardSuccessPane
                    title="Evidence submitted"
                    blurb="Finance can now review the current evidence and your response."
                    actions={
                        <Button onClick={() => router.reload()}>
                            Back to work
                        </Button>
                    }
                />
            }
        >
            <WizardField
                id="evidence-response"
                label="Response to Finance"
                optional={request.status !== 'changes_requested'}
            >
                <Textarea
                    id="evidence-response"
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                />
            </WizardField>
            <p className="text-caption mt-4">
                Need to add or replace files? Open this request from the
                vehicle’s Finance workspace.
            </p>
            <Link
                className="text-primary underline"
                href={`/fleet-assets/vehicles/${work.asset.id}?view=finance`}
            >
                Open vehicle Finance workspace
            </Link>
        </WorkspaceWizard>
    );
}
