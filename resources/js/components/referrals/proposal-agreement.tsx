import {
    compactMenu,
    EntityContextMenu,
    EntityKebab,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import { Button } from '@/components/ui/button';
import { ErrorState } from '@/components/ui/error-state';
import { LoadingState } from '@/components/ui/loading-state';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { formatDateLong } from '@/lib/datetime';
import { Link, router } from '@inertiajs/react';
import axios from 'axios';
import {
    ExternalLink,
    FilePlus2,
    FileSignature,
    RefreshCw,
} from 'lucide-react';
import { useEffect, useState, type MouseEvent } from 'react';
import {
    formatNzd,
    ProposalAgreementDialog,
} from './proposal-agreement-dialog';
import type {
    ProposalAgreementData,
    ProposalAgreementRecord,
} from './proposal-types';
import { Lens } from './settings-ui';
import { referralHref, type ReferralDetail } from './types';

/** Finance's agreement statuses in plain words, on the verified status pairs. */
const AGREEMENT_STATUS: Record<
    string,
    { text: string; variant: StatusVariant; detail: string }
> = {
    draft: {
        text: 'Draft',
        variant: 'neutral',
        detail: 'Finance checks the draft and submits it for approval next.',
    },
    pending_approval: {
        text: 'Waiting for approval',
        variant: 'warning',
        detail: 'Waiting for a different colleague to approve it in Service agreements.',
    },
    active: {
        text: 'Active',
        variant: 'success',
        detail: 'Approved and active. The service start can now be reviewed.',
    },
    under_review: {
        text: 'Under review',
        variant: 'warning',
        detail: 'Finance is reviewing this agreement.',
    },
    suspended: {
        text: 'Suspended',
        variant: 'warning',
        detail: 'This agreement is suspended in Service agreements.',
    },
    renewed: {
        text: 'Renewed',
        variant: 'info',
        detail: 'This agreement has been renewed in Service agreements.',
    },
    expired: {
        text: 'Expired',
        variant: 'neutral',
        detail: 'This agreement has expired.',
    },
    terminated: {
        text: 'Ended',
        variant: 'critical',
        detail: 'This agreement was ended in Service agreements.',
    },
    deleted: {
        text: 'Deleted',
        variant: 'critical',
        detail: 'This agreement was deleted in Service agreements. A fresh draft can be made from the same proposal version.',
    },
};
export const agreementStatus = (status: string) =>
    AGREEMENT_STATUS[status] ?? {
        text: status.replace(/_/g, ' '),
        variant: 'neutral' as StatusVariant,
        detail: 'Check this agreement in Service agreements.',
    };

/** The "Service agreement" check's own state, read from the real agreement. */
function checkState(data: ProposalAgreementData): {
    text: string;
    variant: StatusVariant;
    detail: string;
} {
    const version = data.proposal?.version;
    if (data.agreement?.outdated)
        return {
            text: 'Review needed',
            variant: 'warning',
            detail:
                data.agreement.warning ??
                `Agreement is from version ${data.agreement.proposal_version}; review it.`,
        };
    if (data.state === 'created' && data.agreement) {
        const status = agreementStatus(data.agreement.status);
        return { ...status, detail: status.detail };
    }
    switch (data.state) {
        case 'no_proposal':
            return {
                text: 'No proposal yet',
                variant: 'neutral',
                detail: 'Prepare and agree a support proposal first. Its lines become the draft agreement.',
            };
        case 'not_converted':
            return {
                text: 'After handover',
                variant: 'neutral',
                detail: 'Created after the onboarding handover, from the approved and accepted proposal.',
            };
        case 'blocked':
            return {
                text: 'Not ready',
                variant: 'warning',
                detail: `Proposal version ${version} cannot become an agreement yet:`,
            };
        default:
            return {
                text: 'Ready to create',
                variant: 'info',
                detail: data.earlier.some(
                    (record) =>
                        record.status === 'deleted' &&
                        record.proposal_version === version,
                )
                    ? `The agreement made from proposal version ${version} was deleted in Service agreements. Create a fresh draft from the same lines.`
                    : `Proposal version ${version} is approved and accepted. Create the person’s draft agreement from its lines.`,
            };
    }
}

function isAgreementData(value: unknown): value is ProposalAgreementData {
    return (
        typeof value === 'object' &&
        value !== null &&
        typeof (value as { state?: unknown }).state === 'string' &&
        Array.isArray((value as { blockers?: unknown }).blockers)
    );
}

/** One agreement as a record: status, open link, and kebab + right-click from one MenuItem[]. */
function AgreementRecord({
    record,
    actions,
    onContextMenu,
    detail,
}: {
    record: ProposalAgreementRecord;
    actions: MenuItem[];
    onContextMenu: (event: MouseEvent) => void;
    /** What the status means for the next step, when this record is the check itself. */
    detail?: string;
}) {
    const status = agreementStatus(record.status);
    return (
        <div
            className="flex flex-wrap items-start gap-3 rounded-lg bg-muted p-3"
            onContextMenu={onContextMenu}
        >
            <Lens icon={FileSignature} />
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-sm font-semibold break-words">
                        {record.title ?? 'Service agreement'}
                    </span>
                    <StatusBadge size="sm" variant={status.variant}>
                        {status.text}
                    </StatusBadge>
                </div>
                <p className="text-subtle mt-1">
                    From proposal version {record.proposal_version} · created{' '}
                    {formatDateLong(record.created_at)}
                    {record.created_by_name
                        ? ` by ${record.created_by_name}`
                        : ''}
                    {record.total_budget !== null
                        ? ` · ${formatNzd(record.total_budget)} ${record.gst_inclusive ? 'including' : 'excluding'} GST`
                        : ''}
                </p>
                {detail && <p className="text-subtle mt-1">{detail}</p>}
            </div>
            {record.can_open && (
                <Button variant="link" asChild>
                    <Link href={record.href}>Open agreement</Link>
                </Button>
            )}
            <EntityKebab
                actions={actions}
                label={`Actions for ${record.title ?? 'the service agreement'}`}
            />
        </div>
    );
}

/**
 * The "Service agreement" check: shown in the Proposal section and in the handover.
 * It reads the real agreement made from the approved and accepted proposal, offers
 * "Create draft service agreement" when every precondition passes, and never edits an
 * agreement made from an earlier proposal version.
 */
export function ProposalAgreementCheck({
    referral,
    refreshKey = 0,
}: {
    referral: ReferralDetail;
    /** Bump to reload after the parent changes the proposal. */
    refreshKey?: number;
}) {
    const [data, setData] = useState<ProposalAgreementData | null>(null);
    const [busy, setBusy] = useState(true);
    const [error, setError] = useState('');
    const [retry, setRetry] = useState(0);
    const [reviewing, setReviewing] = useState(false);
    const menu = useEntityContextMenu<ProposalAgreementRecord>();
    useEffect(() => {
        const controller = new AbortController();
        setBusy(true);
        setError('');
        axios
            .get<ProposalAgreementData>(
                `${referralHref(referral.reference)}/service-agreement`,
                { signal: controller.signal },
            )
            .then((response) => {
                if (!isAgreementData(response.data))
                    throw new Error('Unexpected response');
                setData(response.data);
            })
            .catch((caught) => {
                if (!controller.signal.aborted && !axios.isCancel(caught)) {
                    setData(null);
                    setError(
                        'The service agreement could not be checked. Your access may have changed. Try again.',
                    );
                }
            })
            .finally(() => {
                if (!controller.signal.aborted) setBusy(false);
            });
        return () => controller.abort();
    }, [referral.reference, referral.version, refreshKey, retry]);

    const canCreate = !!data && data.state === 'ready' && data.can_create;
    const createReason = !data
        ? 'Checking the agreement'
        : !data.can_create
          ? (data.permission_reason ?? 'You cannot create service agreements')
          : data.state !== 'ready'
            ? (data.blockers[0] ??
              'This proposal version cannot become an agreement yet')
            : undefined;
    /** One MenuItem[] feeds each agreement record's kebab and right-click menu. */
    const recordActions = (record: ProposalAgreementRecord): MenuItem[] =>
        compactMenu([
            {
                label: 'Open agreement',
                icon: ExternalLink,
                onClick: () => router.visit(record.href),
                disabled:
                    record.status === 'deleted'
                        ? 'This agreement was deleted in Service agreements'
                        : record.can_open
                          ? undefined
                          : 'You need permission to view service agreements at this person’s site',
            },
            record.outdated &&
                record.id === data?.agreement?.id && {
                    label: `Create draft from version ${data?.proposal?.version ?? ''}`,
                    icon: FilePlus2,
                    onClick: () => setReviewing(true),
                    disabled: createReason,
                },
            { separator: true },
            {
                label: 'Refresh status',
                icon: RefreshCw,
                onClick: () => setRetry((value) => value + 1),
            },
        ]);
    const state = data ? checkState(data) : null;
    return (
        <div className="space-y-3">
            {busy && !data && (
                <div role="status">
                    <LoadingState message="Checking the service agreement…" />
                </div>
            )}
            {error && (
                <ErrorState
                    title="Service agreement unavailable"
                    message={error}
                    onRetry={() => setRetry((value) => value + 1)}
                />
            )}
            {data && state && (
                <>
                    {/* The agreement made from the current version IS the check: its real status. */}
                    {data.state === 'created' && data.agreement ? (
                        <AgreementRecord
                            record={data.agreement}
                            detail={state.detail}
                            actions={recordActions(data.agreement)}
                            onContextMenu={(event) =>
                                data.agreement &&
                                menu.open(event, data.agreement)
                            }
                        />
                    ) : (
                        <div className="flex flex-wrap items-start gap-3 rounded-lg bg-muted p-3">
                            <Lens icon={FileSignature} />
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="text-sm font-semibold">
                                        Service agreement
                                    </span>
                                    <StatusBadge
                                        size="sm"
                                        variant={state.variant}
                                    >
                                        {state.text}
                                    </StatusBadge>
                                </div>
                                <p className="text-subtle mt-1 break-words">
                                    {state.detail}
                                </p>
                                {data.blockers.length > 0 && (
                                    <ul className="text-subtle mt-1 list-disc space-y-1 pl-4">
                                        {data.blockers.map((blocker) => (
                                            <li key={blocker}>{blocker}</li>
                                        ))}
                                    </ul>
                                )}
                                {data.state === 'ready' && !data.can_create && (
                                    <p className="text-subtle mt-1">
                                        {data.permission_reason}
                                    </p>
                                )}
                            </div>
                            {data.state !== 'created' &&
                                data.state !== 'no_proposal' &&
                                data.state !== 'not_converted' && (
                                    <Button
                                        variant={
                                            canCreate ? 'default' : 'outline'
                                        }
                                        disabled={!canCreate || busy}
                                        onClick={() => setReviewing(true)}
                                    >
                                        <FilePlus2 className="size-4" />
                                        Create draft service agreement
                                    </Button>
                                )}
                        </div>
                    )}
                    {[
                        data.state === 'created' ? null : data.agreement,
                        ...data.earlier,
                    ]
                        .filter(
                            (record): record is ProposalAgreementRecord =>
                                record !== null,
                        )
                        .map((record) => (
                            <AgreementRecord
                                key={record.id}
                                record={record}
                                actions={recordActions(record)}
                                onContextMenu={(event) =>
                                    menu.open(event, record)
                                }
                            />
                        ))}
                </>
            )}
            {menu.ctx ? (
                <EntityContextMenu
                    x={menu.ctx.x}
                    y={menu.ctx.y}
                    icon={FileSignature}
                    title={menu.ctx.record.title ?? 'Service agreement'}
                    items={recordActions(menu.ctx.record)}
                    onClose={menu.close}
                />
            ) : null}
            {reviewing && data && (
                <ProposalAgreementDialog
                    referral={referral}
                    data={data}
                    onClose={(created) => {
                        setReviewing(false);
                        if (created) setRetry((value) => value + 1);
                    }}
                />
            )}
        </div>
    );
}
