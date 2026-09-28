import {
    isJsonObject,
    useVehicleRecordCommand,
} from '@/components/fleet-assets/vehicle-workspace/record-command';
import {
    StagedFilesField,
    WorkspaceWizard,
} from '@/components/fleet-assets/vehicle-workspace/wizard-kit';
import { Button } from '@/components/ui/button';
import {
    ReviewCard,
    ReviewRow,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { formatDateOnly } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import { CheckCircle2, FileText, ReceiptText, Upload } from 'lucide-react';
import { useState } from 'react';
import type { BillWorkContext } from './bill-workspace';
import { DocumentPreview } from './document-preview';
import { formatMoney } from './money';

export type BillEvidence = {
    id: number;
    name: string;
    mime: string;
    state: string;
};
export type DuplicateBill = {
    id: number;
    bill_number: string;
    total_amount: string;
    status: string;
};
type ReviewBill = {
    subtotal?: string;
    gst_amount?: string;
    id: number;
    bill_number: string;
    total_amount: string;
    vendor_reference: string | null;
    site_id: number | null;
    site_name?: string | null;
    vendor?: { name: string } | null;
    bill_date?: string;
    due_date?: string;
    documents?: BillEvidence[];
    lines: Array<{
        id: number;
        description: string;
        line_total: string;
        account: { code: string; name: string } | null;
        cost_centre?: { name: string } | null;
        funding_stream?: { name: string } | null;
    }>;
};

type ApprovalReceipt = {
    bill_id: number;
    bill_number: string;
    total: string;
    journal_id: number;
    journal_number: string;
};
const isApprovalResponse = (
    value: unknown,
): value is { receipt: ApprovalReceipt } => {
    if (!isJsonObject(value) || !isJsonObject(value.receipt)) return false;
    const receipt = value.receipt;
    return (
        Number.isSafeInteger(receipt.bill_id) &&
        Number(receipt.bill_id) > 0 &&
        typeof receipt.bill_number === 'string' &&
        !!receipt.bill_number &&
        typeof receipt.total === 'string' &&
        /^-?\d+(\.\d+)?$/.test(receipt.total) &&
        Number.isSafeInteger(receipt.journal_id) &&
        Number(receipt.journal_id) > 0 &&
        typeof receipt.journal_number === 'string' &&
        !!receipt.journal_number
    );
};
const isDocumentResponse = (
    value: unknown,
): value is { document: { id: number; bill_id: number; state: string } } =>
    isJsonObject(value) &&
    isJsonObject(value.document) &&
    Number.isSafeInteger(value.document.id) &&
    Number(value.document.id) > 0 &&
    Number.isSafeInteger(value.document.bill_id) &&
    Number(value.document.bill_id) > 0 &&
    typeof value.document.state === 'string' &&
    [
        'reserved',
        'storage_failed',
        'stored',
        'scan_unavailable',
        'quarantined',
        'available',
        'withdrawn',
    ].includes(value.document.state);

export function DuplicateBillWarning({ bills }: { bills: DuplicateBill[] }) {
    return bills.length ? (
        <div
            role="status"
            className="rounded-lg border border-status-warning/30 bg-status-warning-bg p-4 text-sm"
        >
            <strong>Possible duplicate supplier invoice</strong>
            <p>
                These accessible bills have the same supplier and invoice
                reference. Check them before approving.
            </p>
            <ul>
                {bills.map((bill) => (
                    <li key={bill.id}>
                        <a
                            className="text-primary underline"
                            href={'/finance/bills/' + bill.id}
                        >
                            {bill.bill_number}
                        </a>{' '}
                        · {formatMoney(bill.total_amount)} · {bill.status}
                    </li>
                ))}
            </ul>
        </div>
    ) : null;
}

export function BillApprovalWizard({
    bill,
    snapshot,
    duplicates,
    context,
    onClose,
}: {
    bill: ReviewBill;
    snapshot: string;
    duplicates: DuplicateBill[];
    context?: BillWorkContext | null;
    onClose: () => void;
}) {
    const [step, setStep] = useState(0);
    const [reviewed] = useState({ bill, snapshot, duplicates, context });
    const command = useVehicleRecordCommand(
        (value): value is { receipt: ApprovalReceipt } =>
            isApprovalResponse(value) &&
            value.receipt.bill_id === reviewed.bill.id,
    );
    const [receipt, setReceipt] = useState<ApprovalReceipt | null>(null);
    const [confirmed, setConfirmed] = useState(false);
    const [error, setError] = useState('');
    const steps = [
        {
            key: 'bill',
            label: 'Bill and allocations',
            blurb: 'Supplier invoice and lines',
            icon: ReceiptText,
        },
        {
            key: 'evidence',
            label: 'Supporting evidence',
            blurb: 'Review scanned files',
            icon: FileText,
        },
        {
            key: 'approve',
            label: 'Approve and post',
            blurb: 'Confirm ledger impact',
            icon: CheckCircle2,
        },
    ];
    const submit = async () => {
        if (!confirmed && !command.uncertain) {
            setError(
                'Confirm that you reviewed the bill, allocations and supporting evidence.',
            );
            setStep(2);
            return;
        }
        const result = await command.submit(
            '/finance/bills/' + reviewed.bill.id + '/approve',
            { approval_snapshot: reviewed.snapshot },
        );
        if (result) setReceipt(result.receipt);
    };
    const close = () => {
        onClose();
        if (receipt) router.reload();
    };
    return (
        <WorkspaceWizard
            title="Review & approve"
            description="Review this bill and its evidence before posting the journal."
            railIcon={ReceiptText}
            railSub={reviewed.bill.bill_number}
            steps={steps}
            step={step}
            setStep={setStep}
            pct={confirmed ? 100 : 0}
            context={{
                name: reviewed.bill.bill_number,
                detail: formatMoney(reviewed.bill.total_amount) + ' · NZD',
            }}
            command={{
                ...command,
                message:
                    command.message ||
                    error ||
                    Object.values(command.errors).join(' '),
            }}
            dirty={confirmed}
            saved={!!receipt}
            submitLabel="Approve bill and post journal"
            onValidateStep={() => true}
            onSubmit={submit}
            onClose={close}
            onReload={() => {
                onClose();
                router.reload();
            }}
            errorKey={JSON.stringify(command.errors)}
            success={
                <WizardSuccessPane
                    title="Bill approved"
                    blurb={
                        <>
                            <span className="block">
                                {String(receipt?.bill_number ?? '')} ·{' '}
                                {formatMoney(String(receipt?.total ?? '0'))}
                            </span>
                            <span className="block">
                                Journal {String(receipt?.journal_number ?? '')}{' '}
                                is posted. This receipt also confirms a safely
                                retried approval.
                            </span>
                            <a
                                className="text-primary underline"
                                href={
                                    '/finance/journals/' +
                                    String(receipt?.journal_id)
                                }
                            >
                                Open journal
                            </a>
                        </>
                    }
                    actions={
                        <Button onClick={close}>
                            Back to bill and payment history
                        </Button>
                    }
                />
            }
        >
            {step === 0 && (
                <div className="space-y-5">
                    <ReviewCard
                        icon={ReceiptText}
                        title="Current supplier bill"
                    >
                        <ReviewRow
                            label="Supplier"
                            value={reviewed.bill.vendor?.name ?? 'Not supplied'}
                        />
                        <ReviewRow
                            label="Bill"
                            value={reviewed.bill.bill_number}
                        />
                        <ReviewRow
                            label="Invoice"
                            value={`${reviewed.bill.vendor_reference ?? 'Not supplied'} · ${formatDateOnly(reviewed.bill.bill_date?.slice(0, 10))}`}
                        />
                        <ReviewRow
                            label="Net"
                            value={
                                reviewed.bill.subtotal
                                    ? formatMoney(reviewed.bill.subtotal)
                                    : 'See bill lines'
                            }
                        />
                        <ReviewRow
                            label="Source tax"
                            value={
                                reviewed.bill.gst_amount
                                    ? formatMoney(reviewed.bill.gst_amount)
                                    : 'See bill lines'
                            }
                        />
                        <ReviewRow
                            label="Total · NZD"
                            value={formatMoney(reviewed.bill.total_amount)}
                        />
                    </ReviewCard>
                    <ReviewCard icon={CheckCircle2} title="Allocation & period">
                        <ReviewRow
                            label="Site"
                            value={
                                reviewed.bill.site_name ??
                                'Assign an approved Site before approval'
                            }
                        />
                        <ReviewRow
                            label="Cost centres"
                            value={
                                [
                                    ...new Set(
                                        reviewed.bill.lines
                                            .map(
                                                (line) =>
                                                    line.cost_centre?.name,
                                            )
                                            .filter(Boolean),
                                    ),
                                ].join(' · ') || 'No cost centre recorded'
                            }
                        />
                        <ReviewRow
                            label="Work order"
                            value={
                                reviewed.context?.work?.reference ??
                                'No linked work'
                            }
                        />
                        <ReviewRow
                            label="Posting period"
                            value={
                                reviewed.context?.period
                                    ? `${reviewed.context.period.name} · ${reviewed.context.period.status}`
                                    : 'Rechecked on approval'
                            }
                        />
                        {reviewed.bill.lines.map((line) => (
                            <ReviewRow
                                key={line.id}
                                label={line.description}
                                value={`${line.account?.code ?? 'Account missing'} · ${formatMoney(line.line_total)}`}
                            />
                        ))}
                    </ReviewCard>
                    <DuplicateBillWarning bills={reviewed.duplicates} />
                    <p className="text-caption">
                        Finance rechecks current access, allocations and
                        configured approval requirements when you confirm.
                    </p>
                </div>
            )}{' '}
            {step === 1 && (
                <div className="space-y-5">
                    <BillFiles
                        billId={reviewed.bill.id}
                        documents={reviewed.bill.documents ?? []}
                    />
                    {reviewed.context?.estimate && (
                        <ReviewCard title="Linked quote" icon={ReceiptText}>
                            <ReviewRow
                                label="Quote"
                                value={
                                    reviewed.context.estimate.quote_reference
                                }
                            />
                            <ReviewRow
                                label="Supplier"
                                value={reviewed.context.estimate.vendor_name}
                            />
                            <ReviewRow
                                label="Amount"
                                value={formatMoney(
                                    reviewed.context.estimate.amount,
                                )}
                            />
                        </ReviewCard>
                    )}
                    <QuoteReviewFiles
                        documents={reviewed.context?.documents ?? []}
                    />
                </div>
            )}
            {step === 2 && (
                <div className="space-y-5">
                    <p>
                        Approving {reviewed.bill.bill_number} for{' '}
                        <strong>
                            {formatMoney(reviewed.bill.total_amount)}
                        </strong>{' '}
                        posts a journal to the ledger. Payment is handled
                        separately in a payment run.
                    </p>
                    <Button
                        variant={confirmed ? 'default' : 'outline'}
                        aria-pressed={confirmed}
                        onClick={() => {
                            setConfirmed(!confirmed);
                            setError('');
                        }}
                    >
                        {confirmed
                            ? 'Review confirmed'
                            : 'I have reviewed the bill, allocations and evidence'}
                    </Button>
                </div>
            )}
        </WorkspaceWizard>
    );
}

function QuoteReviewFiles({
    documents,
}: {
    documents: NonNullable<BillWorkContext>['documents'];
}) {
    const [selected, setSelected] = useState<number | null>(null);
    const file = documents.find((item) => item.id === selected);
    if (!documents.length) return null;
    return (
        <div className="space-y-3">
            <h3 className="font-semibold">Quote evidence</h3>
            {documents.map((item) => (
                <div
                    key={item.id}
                    className="flex items-center justify-between gap-3 rounded-lg border p-3"
                >
                    <span className="text-sm">{item.name}</span>
                    {item.url ? (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setSelected(item.id)}
                        >
                            Preview {item.name}
                        </Button>
                    ) : (
                        <span className="text-caption">
                            {item.state.replaceAll('_', ' ')} · preview
                            unavailable
                        </span>
                    )}
                </div>
            ))}
            {file?.url && (
                <DocumentPreview
                    url={`${file.url}?inline=1`}
                    name={file.name}
                    mime={file.mime}
                />
            )}
        </div>
    );
}

export function BillFiles({
    billId,
    documents,
}: {
    billId: number;
    documents: BillEvidence[];
}) {
    const [preview, setPreview] = useState<BillEvidence | null>(null);
    const visible = documents.filter((file) => file.state !== 'withdrawn');
    const url = (file: BillEvidence) =>
        '/finance/bills/' + billId + '/documents/' + file.id;
    return (
        <div className="space-y-4">
            {!visible.length && <p>No supporting files attached.</p>}
            {visible.map((file) => (
                <div
                    key={file.id}
                    className="flex flex-wrap items-center gap-3 border-b py-3"
                >
                    <FileText className="size-4" />
                    <span>{file.name}</span>
                    <span className="text-sm text-muted-foreground">
                        {file.state.replaceAll('_', ' ')}
                    </span>
                    {file.state === 'available' && (
                        <>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setPreview(file)}
                            >
                                Preview {file.name}
                            </Button>
                            <a
                                className="text-primary underline"
                                href={url(file)}
                            >
                                Download
                            </a>
                        </>
                    )}
                </div>
            ))}
            {preview && (
                <section aria-label="Supporting document preview">
                    <div className="flex items-center justify-between">
                        <strong>{preview.name}</strong>
                        <Button
                            variant="ghost"
                            onClick={() => setPreview(null)}
                        >
                            Close preview
                        </Button>
                    </div>
                    <DocumentPreview
                        url={url(preview) + '?inline=1'}
                        name={preview.name}
                        mime={preview.mime}
                    />
                    <a
                        className="text-primary underline"
                        href={url(preview) + '?inline=1'}
                        target="_blank"
                        rel="noreferrer"
                    >
                        Open preview in a new tab
                    </a>
                </section>
            )}
        </div>
    );
}

export function BillEvidenceSection({
    billId,
    documents,
    editable,
}: {
    billId: number;
    documents: BillEvidence[];
    editable: boolean;
}) {
    const [adding, setAdding] = useState(false);
    const command = useVehicleRecordCommand(
        (
            value,
        ): value is {
            document: { id: number; bill_id: number; state: string };
        } => isDocumentResponse(value) && value.document.bill_id === billId,
    );
    const [operation, setOperation] = useState<{
        id: number;
        action: string;
    } | null>(null);
    const run = async (id: number, action: string) => {
        setOperation({ id, action });
        const result = await command.submit(
            '/finance/bills/' + billId + '/documents/' + id + '/' + action,
            {},
        );
        if (result) {
            setOperation(null);
            command.reset();
            router.reload();
        }
    };
    return (
        <section className="space-y-4" aria-label="Supporting documents">
            <h2 className="text-section-title">Supporting documents</h2>
            <BillFiles billId={billId} documents={documents} />
            {command.message && <p role="alert">{command.message}</p>}
            {command.uncertain && operation && (
                <Button onClick={() => run(operation.id, operation.action)}>
                    Retry this action
                </Button>
            )}
            {command.requiresReload && (
                <Button
                    onClick={() => router.reload({ onSuccess: command.reset })}
                >
                    Reload documents
                </Button>
            )}
            {editable && (
                <>
                    <Button
                        variant="outline"
                        disabled={command.locked}
                        onClick={() => setAdding(true)}
                    >
                        <Upload className="size-4" />
                        Add supporting file
                    </Button>
                    {documents
                        .filter((file) => file.state !== 'withdrawn')
                        .map((file) => (
                            <div className="flex gap-3" key={file.id}>
                                {['stored', 'scan_unavailable'].includes(
                                    file.state,
                                ) && (
                                    <Button
                                        variant="outline"
                                        disabled={command.locked}
                                        onClick={() => run(file.id, 'retry')}
                                    >
                                        Retry virus check: {file.name}
                                    </Button>
                                )}
                                {file.state !== 'available' && (
                                    <Button
                                        variant="outline"
                                        disabled={command.locked}
                                        onClick={() => run(file.id, 'withdraw')}
                                    >
                                        Withdraw failed file: {file.name}
                                    </Button>
                                )}
                            </div>
                        ))}
                </>
            )}
            {adding && (
                <UploadBillFile
                    billId={billId}
                    onClose={() => setAdding(false)}
                />
            )}
        </section>
    );
}
function UploadBillFile({
    billId,
    onClose,
}: {
    billId: number;
    onClose: () => void;
}) {
    const command = useVehicleRecordCommand(
        (
            value,
        ): value is {
            document: { id: number; bill_id: number; state: string };
        } => isDocumentResponse(value) && value.document.bill_id === billId,
    );
    const [files, setFiles] = useState<File[]>([]);
    const [result, setResult] = useState<string | null>(null);
    const [error, setError] = useState('');
    const submit = async () => {
        if (files.length !== 1) {
            setError('Choose one PDF, JPEG or PNG file, up to 20 MB.');
            return;
        }
        const data = new FormData();
        data.append('file', files[0]);
        const saved = await command.submit(
            '/finance/bills/' + billId + '/documents',
            data,
        );
        if (saved) setResult(saved.document.state);
    };
    const close = () => {
        onClose();
        if (result) router.reload();
    };
    return (
        <WorkspaceWizard
            title="Add bill evidence"
            description="Store a private supporting file and check it for viruses."
            railIcon={Upload}
            railSub={'Bill ' + billId}
            steps={[
                {
                    key: 'upload',
                    label: 'Supporting file',
                    blurb: 'PDF, JPEG or PNG',
                    icon: Upload,
                },
            ]}
            step={0}
            setStep={() => {}}
            pct={files.length ? 100 : 0}
            context={{
                name: 'Bill supporting evidence',
                detail: 'Private · up to 20 MB',
            }}
            command={{
                ...command,
                message:
                    command.message ||
                    error ||
                    Object.values(command.errors).join(' '),
            }}
            dirty={!!files.length}
            saved={!!result}
            submitLabel="Upload and check"
            onValidateStep={() => true}
            onSubmit={submit}
            onClose={close}
            onReload={() => {
                onClose();
                router.reload();
            }}
            errorKey={JSON.stringify(command.errors)}
            success={
                <WizardSuccessPane
                    title={
                        result === 'available'
                            ? 'File ready to review'
                            : 'File needs attention'
                    }
                    blurb={
                        result === 'available'
                            ? 'The file passed its virus check.'
                            : 'The file is retained with its upload or scan status. Retry its virus check, or withdraw the failed file and upload a replacement from the bill.'
                    }
                    actions={<Button onClick={close}>Back to bill</Button>}
                />
            }
        >
            <StagedFilesField
                label="Supporting file"
                files={files}
                onChange={setFiles}
                multiple={false}
                optional={false}
            />
        </WorkspaceWizard>
    );
}
