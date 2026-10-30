import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { WizardShell, WizardSuccessPane } from '@/components/wizard/shell';
import { formatDateOnly } from '@/lib/datetime';
import { Link, router } from '@inertiajs/react';
import axios from 'axios';
import { ClipboardList, FileSignature, ShieldCheck } from 'lucide-react';
import { useRef, useState } from 'react';
import type {
    ProposalAgreementData,
    ProposalAgreementLine,
    ProposalAgreementReceipt,
} from './proposal-types';
import { Nested, NestedRow } from './settings-ui';
import { referralHref, type ReferralDetail } from './types';

/** Agreement types Finance uses (service-agreements/Create.tsx), in plain words. */
const AGREEMENT_TYPES: Record<string, string> = {
    nasc: 'NASC-allocated',
    egl_if: 'Enabling Good Lives or Individualised Funding',
    acc: 'ACC',
    te_whatu_ora: 'Health New Zealand',
    private: 'Private or self-funded',
    charitable: 'Charitable trust or NGO',
    other: 'Other',
};
const UNITS: Record<string, string> = {
    hour: 'hours',
    night: 'nights',
    day: 'days',
    visit: 'visits',
    km: 'km',
    trip: 'trips',
    flat: 'flat amounts',
};
const RATE_UNITS: Record<string, string> = {
    hour: 'hour',
    night: 'night',
    day: 'day',
    visit: 'visit',
    km: 'km',
    trip: 'trip',
    flat: 'flat amount',
};
const PERIODS: Record<string, string> = {
    week: 'week',
    fortnight: 'fortnight',
    month: 'month',
    year: 'year',
    total: 'in total',
};

/** NZD with cents: agreement lines and budgets are recorded to the cent. */
export function formatNzd(amount: string | null | undefined): string {
    if (amount === null || amount === undefined || amount === '')
        return 'Not priced';
    return new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: 'NZD',
    }).format(Number(amount));
}

const perPeriod = (period: string) =>
    period === 'total' ? 'in total' : `a ${PERIODS[period] ?? period}`;

function lineSource(line: ProposalAgreementLine): string {
    return line.proposal_quantity === null
        ? `Quantity not set · ${perPeriod(line.period)}`
        : `${line.proposal_quantity} ${line.proposal_unit} ${perPeriod(line.period)}`;
}

function lineTarget(line: ProposalAgreementLine): string {
    if (line.quantity === null || line.unit === null) return 'Not copied';
    const periods =
        line.period === 'total' || line.periods === null
            ? ''
            : ` (${line.periods} ${PERIODS[line.period]}${line.periods === 1 ? '' : 's'})`;
    return `${line.quantity} ${UNITS[line.unit] ?? line.unit}${periods}`;
}

/**
 * Review pop-up for "Create draft service agreement": the mapped lines and totals,
 * the agreement details and who approves next, then one confirmed create. The draft
 * is created in Service agreements; this dialog never edits an existing agreement.
 */
export function ProposalAgreementDialog({
    referral,
    data,
    onClose,
}: {
    referral: ReferralDetail;
    data: ProposalAgreementData;
    onClose: (created: boolean) => void;
}) {
    const [step, setStep] = useState(0);
    const [confirmed, setConfirmed] = useState(false);
    const [earlierConfirmed, setEarlierConfirmed] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [uncertain, setUncertain] = useState(false);
    const [receipt, setReceipt] = useState<ProposalAgreementReceipt | null>(
        null,
    );
    const pending = useRef(false);
    const body = useRef<HTMLDivElement>(null);
    const preview = data.preview;
    const proposal = data.proposal;
    const earlier = data.agreement;
    const ready = data.state === 'ready' && data.can_create;
    const steps = [
        {
            key: 'lines',
            label: 'Lines and totals',
            blurb: 'Copied from the proposal',
            icon: ClipboardList,
        },
        {
            key: 'details',
            label: 'Agreement details',
            blurb: 'Person, funder and dates',
            icon: FileSignature,
        },
        {
            key: 'approval',
            label: 'Confirm and create',
            blurb: 'Who approves the draft next',
            icon: ShieldCheck,
        },
    ];
    const go = (index: number) => {
        setStep(index);
        requestAnimationFrame(() => {
            body.current?.scrollTo({ top: 0 });
            body.current?.querySelector<HTMLElement>('[data-heading]')?.focus();
        });
    };
    async function create() {
        if (pending.current || uncertain || !proposal || !ready) return;
        if (!confirmed || (earlier && !earlierConfirmed)) {
            setError('Confirm the statements below before creating the draft.');
            return;
        }
        pending.current = true;
        setBusy(true);
        setError('');
        try {
            const response = await axios.post<ProposalAgreementReceipt>(
                `${referralHref(referral.reference)}/service-agreement`,
                {
                    version: data.version,
                    proposal_id: proposal.id,
                    proposal_version: proposal.version,
                    acknowledge_draft: true,
                    ...(earlier ? { acknowledge_earlier_agreement: true } : {}),
                },
            );
            if (!response.data.saved) throw new Error('Not confirmed');
            setReceipt(response.data);
            router.reload({ only: ['referral'], preserveScroll: true });
        } catch (caught) {
            const response = axios.isAxiosError(caught)
                ? caught.response
                : undefined;
            setUncertain(!response || response.status === 409);
            setError(
                response?.data?.errors
                    ? Object.values(
                          response.data.errors as Record<string, string[]>,
                      )
                          .flat()
                          .join(' ')
                    : (response?.data?.message ??
                          'The draft was not confirmed. Close this and check the service agreement on the referral before trying again.'),
            );
        } finally {
            pending.current = false;
            setBusy(false);
        }
    }
    return (
        <WizardShell
            open
            onClose={() => !busy && onClose(receipt !== null)}
            maxWidth="min(92vw, 1100px)"
            title="Create draft service agreement"
            description={`${referral.name} · proposal version ${proposal?.version ?? ''}`}
            railTitle="Service agreement"
            railIcon={FileSignature}
            railSub="The agreement starts as a draft. Finance approval makes it active; nothing is invoiced or started here."
            steps={steps}
            stepIndex={step}
            onStepClick={(index) => !busy && go(index)}
            bodyRef={body}
            footerStart={
                <Button
                    variant="ghost"
                    disabled={busy}
                    onClick={() => (step ? go(step - 1) : onClose(false))}
                >
                    {step ? 'Back' : 'Cancel'}
                </Button>
            }
            footerEnd={
                step < 2 ? (
                    <Button onClick={() => go(step + 1)}>Continue</Button>
                ) : (
                    <Button
                        disabled={busy || uncertain || !ready}
                        onClick={() => void create()}
                    >
                        {busy ? 'Creating…' : 'Create draft agreement'}
                    </Button>
                )
            }
            success={
                receipt ? (
                    <WizardSuccessPane
                        title={
                            receipt.replayed
                                ? 'This draft already exists'
                                : 'Draft agreement created'
                        }
                        blurb={`It is a draft in Service agreements, made from proposal version ${receipt.proposal_version}. Finance submits it for approval, and it becomes active only after a different colleague approves it.`}
                        actions={
                            <>
                                {receipt.agreement.can_open && (
                                    <Button asChild variant="outline">
                                        <Link href={receipt.agreement.href}>
                                            Open agreement
                                        </Link>
                                    </Button>
                                )}
                                <Button onClick={() => onClose(true)}>
                                    Back to the referral
                                </Button>
                            </>
                        }
                    />
                ) : undefined
            }
        >
            <div className="space-y-5">
                <h2 data-heading tabIndex={-1} className="text-section-title">
                    {steps[step].label}
                </h2>
                {error && (
                    <Alert variant="warning">
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}
                {!ready && (
                    <Alert variant="warning">
                        <AlertDescription>
                            {data.permission_reason ??
                                'This proposal version cannot become an agreement yet.'}
                            {data.blockers.length > 0 && (
                                <ul className="mt-2 list-disc space-y-1 pl-4">
                                    {data.blockers.map((blocker) => (
                                        <li key={blocker}>{blocker}</li>
                                    ))}
                                </ul>
                            )}
                        </AlertDescription>
                    </Alert>
                )}
                {step === 0 && preview && (
                    <>
                        <p className="text-subtle">
                            Each proposal line becomes one agreement line with
                            the same unit, rate and GST basis. Quantities and
                            budgets cover every stated period between{' '}
                            {formatDateOnly(preview.header.starts_at)} and{' '}
                            {formatDateOnly(preview.header.ends_at)}, including
                            a final part period. Nothing is prorated.
                        </p>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Support line</TableHead>
                                    <TableHead>In the proposal</TableHead>
                                    <TableHead>In the agreement</TableHead>
                                    <TableHead className="text-right">
                                        Rate
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Budget
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {preview.lines.map((line) => (
                                    <TableRow key={line.key}>
                                        <TableCell className="align-top whitespace-normal">
                                            <span className="font-medium">
                                                {line.description}
                                            </span>
                                            {line.service && (
                                                <span className="text-subtle block">
                                                    {line.service}
                                                </span>
                                            )}
                                            {line.issue && (
                                                <span className="mt-1 block">
                                                    <StatusBadge
                                                        size="sm"
                                                        variant="warning"
                                                    >
                                                        Not ready
                                                    </StatusBadge>{' '}
                                                    <span className="text-subtle">
                                                        {line.issue}
                                                    </span>
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="align-top">
                                            {lineSource(line)}
                                        </TableCell>
                                        <TableCell className="align-top">
                                            {lineTarget(line)}
                                        </TableCell>
                                        <TableCell className="text-right align-top">
                                            {line.unit_price === null
                                                ? 'Not priced'
                                                : `${formatNzd(line.unit_price)} / ${RATE_UNITS[line.unit ?? ''] ?? 'unit'}`}
                                        </TableCell>
                                        <TableCell className="text-right align-top">
                                            {formatNzd(line.budget)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                            <TableFooter>
                                <TableRow>
                                    <TableCell colSpan={4}>
                                        Total budget ·{' '}
                                        {preview.header.gst_inclusive
                                            ? 'including GST'
                                            : 'excluding GST'}
                                    </TableCell>
                                    <TableCell className="text-right font-semibold">
                                        {preview.lines.some(
                                            (line) => line.issue !== null,
                                        )
                                            ? 'Not complete'
                                            : formatNzd(
                                                  preview.header.total_budget,
                                              )}
                                    </TableCell>
                                </TableRow>
                            </TableFooter>
                        </Table>
                    </>
                )}
                {step === 1 && preview && (
                    <Nested icon={FileSignature} title="The draft agreement">
                        <NestedRow
                            label="Person"
                            value={data.person?.name ?? 'The linked person'}
                        />
                        <NestedRow label="Title" value={preview.header.title} />
                        <NestedRow
                            label="Status when created"
                            value={
                                <StatusBadge size="sm" variant="neutral">
                                    Draft
                                </StatusBadge>
                            }
                        />
                        <NestedRow
                            label="Agreement type"
                            value={
                                AGREEMENT_TYPES[
                                    preview.header.agreement_type
                                ] ?? 'Other'
                            }
                        />
                        <NestedRow
                            label="Funder or payer"
                            value={
                                preview.header.funding_body ??
                                'Not recorded on the funding cases'
                            }
                        />
                        <NestedRow
                            label="Funding reference"
                            value={
                                preview.header.funding_reference ??
                                'Not yet received'
                            }
                        />
                        <NestedRow
                            label="Agreement period"
                            value={`${formatDateOnly(preview.header.starts_at)} – ${formatDateOnly(preview.header.ends_at)}`}
                        />
                        <NestedRow
                            label="First service date"
                            value={
                                preview.header.first_service_date
                                    ? formatDateOnly(
                                          preview.header.first_service_date,
                                      )
                                    : 'Set when the start is approved'
                            }
                        />
                        <NestedRow
                            label="GST"
                            value={
                                preview.header.gst_inclusive
                                    ? 'Prices include GST'
                                    : 'Prices exclude GST'
                            }
                        />
                        <NestedRow
                            label="Source"
                            value={`Support proposal version ${proposal?.version ?? ''}`}
                        />
                    </Nested>
                )}
                {step === 2 && (
                    <>
                        <Nested icon={ShieldCheck} title="Who approves next">
                            <ol className="space-y-3">
                                {data.approval_steps.map((item, index) => (
                                    <li
                                        key={item.title}
                                        className="flex items-start gap-3"
                                    >
                                        <span
                                            aria-hidden
                                            className="flex size-6 shrink-0 items-center justify-center rounded-full bg-card text-xs font-semibold ring-1 ring-border"
                                        >
                                            {index + 1}
                                        </span>
                                        <div className="min-w-0">
                                            <p className="text-sm font-semibold">
                                                {item.title}
                                            </p>
                                            <p className="text-subtle">
                                                {item.detail}
                                            </p>
                                        </div>
                                    </li>
                                ))}
                            </ol>
                        </Nested>
                        {earlier && (
                            <Alert variant="warning">
                                <AlertDescription>
                                    The agreement from proposal version{' '}
                                    {earlier.proposal_version} stays exactly as
                                    it is. This creates a separate draft for
                                    version {proposal?.version}; Finance decides
                                    which agreement applies.
                                </AlertDescription>
                            </Alert>
                        )}
                        <div className="flex items-start gap-3">
                            <Checkbox
                                id="agreement-draft-confirm"
                                checked={confirmed}
                                disabled={!ready}
                                onCheckedChange={(value) =>
                                    setConfirmed(value === true)
                                }
                            />
                            <Label htmlFor="agreement-draft-confirm">
                                I have checked these lines against proposal
                                version {proposal?.version}. The agreement
                                starts as a draft and needs Finance approval.
                            </Label>
                        </div>
                        {earlier && (
                            <div className="flex items-start gap-3">
                                <Checkbox
                                    id="agreement-earlier-confirm"
                                    checked={earlierConfirmed}
                                    disabled={!ready}
                                    onCheckedChange={(value) =>
                                        setEarlierConfirmed(value === true)
                                    }
                                />
                                <Label htmlFor="agreement-earlier-confirm">
                                    Leave the agreement from version{' '}
                                    {earlier.proposal_version} unchanged.
                                </Label>
                            </div>
                        )}
                    </>
                )}
            </div>
        </WizardShell>
    );
}
