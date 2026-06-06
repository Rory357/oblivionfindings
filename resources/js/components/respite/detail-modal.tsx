/**
 * Read-only detail pop-up for any pipeline record. Opens in place from a pane's
 * "View" action — no navigation. (Edit/transition pop-ups land in a later pass;
 * forward actions live inline on the pane cards.)
 */
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Link } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import { Avatar, fmtDate, fmtRange, StatusBadge, UrgencyBadge } from './shared';
import type {
    RespiteBookingRow,
    RespiteReferralRow,
    RespiteRequestRow,
    RespiteStayRow,
} from './types';

export type RespiteDetail =
    | { kind: 'referral'; row: RespiteReferralRow }
    | { kind: 'request'; row: RespiteRequestRow }
    | { kind: 'booking'; row: RespiteBookingRow }
    | { kind: 'stay'; row: RespiteStayRow };

const KIND_LABEL: Record<RespiteDetail['kind'], string> = {
    referral: 'Referral',
    request: 'Booking request',
    booking: 'Booking',
    stay: 'Stay',
};

type Row = [string, React.ReactNode];

function buildRows(detail: RespiteDetail): Row[] {
    if (detail.kind === 'referral') {
        const r = detail.row;
        return [
            ['Urgency', <UrgencyBadge key="u" urgency={r.urgency} />],
            ['Age', r.age ?? '—'],
            [
                'Referrer',
                r.referrer
                    ? `${r.referrer}${r.referrerType ? ` · ${r.referrerType}` : ''}`
                    : '—',
            ],
            ['Contact', r.contact ?? '—'],
            ['Reason', r.reason ?? '—'],
            ['Risk level', r.riskLevel ?? '—'],
            ['Funding', r.funding ?? '—'],
            [
                'Cultural snapshot',
                r.isMaori || r.iwi
                    ? [r.isMaori ? 'Maori' : null, r.iwi]
                          .filter(Boolean)
                          .join(' · ')
                    : '—',
            ],
            [
                'Interpreter',
                r.interpreterRequired
                    ? `${r.interpreterLanguage ?? 'Required'} · ${r.interpreterArranged ? 'arranged' : 'not arranged'}`
                    : 'Not required',
            ],
            [
                'Carer strain',
                r.carerStrainLevel
                    ? `${r.carerStrainLevel.replace(/_/g, ' ')}${r.carerBreakdown ? ' · breakdown flag' : ''}`
                    : '—',
            ],
            ['Preferred home', r.site ?? 'No preference'],
            ['Received', fmtDate(r.received)],
            ['Triage notes', r.triageNotes ?? '—'],
        ];
    }
    if (detail.kind === 'request') {
        const r = detail.row;
        return [
            ['From referral', r.referralRef ?? '—'],
            [
                'Dates',
                `${fmtRange(r.start, r.end)}${r.nights != null ? ` · ${r.nights} nights` : ''}`,
            ],
            ['Funding', r.funding ?? '—'],
            ['Funding status', fundingStatusLabel(r.fundingStatus)],
            ['Agreement', agreementLabel(r.serviceAgreement)],
            ['Priority', r.priority ?? '—'],
            ['Waitlist', r.waitlistPosition ? `#${r.waitlistPosition}` : '—'],
            [
                'Emergency fast-track',
                r.isEmergency
                    ? r.fastTracked
                        ? 'Fast-tracked'
                        : 'Emergency'
                    : 'No',
            ],
            ['Service context', r.serviceContext ?? '—'],
            ['Home', r.site ?? '—'],
            ['Reviewer', r.reviewer ?? 'Unassigned'],
            ['Submitted', fmtDate(r.submitted)],
            ['Note', r.note ?? '—'],
        ];
    }
    if (detail.kind === 'booking') {
        const b = detail.row;
        return [
            [
                'Dates',
                `${fmtRange(b.start, b.end)}${b.nights != null ? ` · ${b.nights} nights` : ''}`,
            ],
            ['Home', b.site ?? '—'],
            ['Coordinator', b.coordinator ?? '—'],
            ['Funding', b.funding ?? '—'],
            ['Funding status', fundingStatusLabel(b.fundingStatus)],
            ['Agreement', agreementLabel(b.serviceAgreement)],
            ['Agreement status', b.agreementStatus?.replace(/_/g, ' ') ?? '—'],
            ['Who consents', b.consentAuthority?.replace(/_/g, ' ') ?? '—'],
            [
                'Interpreter',
                b.culturalSnapshot?.interpreter_required
                    ? b.interpreterArranged
                        ? 'Arranged'
                        : 'Required'
                    : 'Not required',
            ],
            [
                'Co-payment',
                b.copaymentAmount != null
                    ? `$${b.copaymentAmount.toFixed(2)} · ${b.copaymentStatus ?? 'not recorded'}`
                    : '—',
            ],
            ['Recurring block', b.recurrenceRule ?? '—'],
            ['Critical alerts', criticalAlertLabel(b.criticalAlerts)],
            ['Pre-stay readiness', `${b.readiness}%`],
            [
                'Next readiness item',
                b.readinessSegments.find((segment) => !segment.complete)
                    ?.label ?? '—',
            ],
            ['Stay', b.hasStay ? 'Open' : 'Not started'],
        ];
    }
    const s = detail.row;
    return [
        ['Home', s.site ?? '—'],
        ['Critical alerts', criticalAlertLabel(s.criticalAlerts)],
        [
            'Admission med-rec',
            s.requiresAdmissionMedRec
                ? (s.admissionMedRecStatus ?? 'Pending')
                : 'Not required',
        ],
        [
            'Compliance blockers',
            s.unreviewedRestraints || s.openIncidents
                ? `${s.unreviewedRestraints} restraint review · ${s.openIncidents} incident`
                : '—',
        ],
        ['Discharge reason', s.dischargeReason?.replace(/_/g, ' ') ?? '—'],
        ['Checked in', fmtDate(s.actualStart)],
        ['Planned end', fmtDate(s.plannedEnd)],
        ['Checked out', fmtDate(s.actualEnd)],
    ];
}

function fundingStatusLabel(status: string): string {
    return status
        .split('_')
        .map((part) => part[0]?.toUpperCase() + part.slice(1))
        .join(' ');
}

function agreementLabel(
    agreement: {
        title: string | null;
        referenceNumber: string | null;
        hoursRemaining: number;
        signedDate?: string | null;
    } | null,
): string {
    if (!agreement) return '—';

    const title =
        agreement.referenceNumber ?? agreement.title ?? 'Linked agreement';

    return `${title} · ${agreement.hoursRemaining}h left${agreement.signedDate ? ' · signed' : ''}`;
}

function criticalAlertLabel(alerts: Array<{ label: string }>): string {
    if (alerts.length === 0) return '—';

    return alerts.map((alert) => alert.label).join(', ');
}

export function RespiteDetailModal({
    detail,
    onClose,
}: {
    detail: RespiteDetail | null;
    onClose: () => void;
}) {
    const row = detail?.row;
    const rows = detail ? buildRows(detail) : [];

    return (
        <Dialog
            open={detail != null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent className="max-w-lg">
                {detail && row ? (
                    <>
                        <DialogHeader>
                            <div className="flex items-center gap-3">
                                <Avatar
                                    name={row.client}
                                    className="h-11 w-11 text-sm"
                                />
                                <div className="min-w-0">
                                    <DialogTitle className="truncate text-left text-lg">
                                        {row.client}
                                    </DialogTitle>
                                    <DialogDescription className="text-left">
                                        {KIND_LABEL[detail.kind]} · {row.ref}
                                    </DialogDescription>
                                </div>
                                <div className="ml-auto">
                                    <StatusBadge status={row.status} />
                                </div>
                            </div>
                        </DialogHeader>
                        <dl className="divide-y divide-border">
                            {rows.map(([k, v], i) => (
                                <div
                                    key={i}
                                    className="flex justify-between gap-6 py-2 text-sm"
                                >
                                    <dt className="shrink-0 text-muted-foreground">
                                        {k}
                                    </dt>
                                    <dd className="text-right font-medium">
                                        {v}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                        {row.clientId ? (
                            <div className="flex justify-end border-t border-border pt-3">
                                <Link
                                    href={`/operations/clients/${row.clientId}`}
                                    className="inline-flex items-center gap-1.5 text-sm font-semibold text-primary hover:underline"
                                >
                                    Open client profile{' '}
                                    <ExternalLink className="h-3.5 w-3.5" />
                                </Link>
                            </div>
                        ) : null}
                    </>
                ) : null}
            </DialogContent>
        </Dialog>
    );
}
