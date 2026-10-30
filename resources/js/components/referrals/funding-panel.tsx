import {
    EntityCard,
    EntityCardGrid,
    EntityChip,
    EntityContextMenu,
    EntityKebab,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { LoadingState } from '@/components/ui/loading-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { ReviewCard, ReviewRow, WizardShell } from '@/components/wizard/shell';
import { formatDateOnly, formatDateTimeLong } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import axios from 'axios';
import {
    ChevronLeft,
    ChevronRight,
    ClipboardCheck,
    Eye,
    History,
    Pencil,
    Plus,
    Send,
    ShieldCheck,
    UserRound,
    Wallet,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import {
    FundingActionDialog,
    type FundingAction,
} from './funding-action-dialog';
import { FundingAllocation as Allocation } from './funding-allocation';
import { FundingOpenDialog } from './funding-open-dialog';
import type { FundingCase, FundingDecision } from './funding-types';
import {
    label,
    referralHref,
    type Options,
    type Page,
    type Permissions,
    type ReferralDetail,
} from './types';

function CaseDetail({
    item,
    referral,
    options,
    onClose,
    actions,
    onUnavailable,
}: {
    item: FundingCase;
    referral: ReferralDetail;
    options: Options;
    onClose: () => void;
    actions: MenuItem[];
    onUnavailable: () => void;
}) {
    const [section, setSection] = useState(0);
    const [historyPage, setHistoryPage] = useState(1);
    const [history, setHistory] = useState<Page<FundingDecision> | null>(null);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);
    const [retry, setRetry] = useState(0);
    useEffect(() => {
        if (section !== 2) return;
        const controller = new AbortController();
        setLoading(true);
        setError('');
        setHistory(null);
        axios
            .get<{ decisions: Page<FundingDecision> }>(
                `${referralHref(referral.reference)}/funding/${item.id}/decisions`,
                { params: { page: historyPage }, signal: controller.signal },
            )
            .then((response) => {
                if (!controller.signal.aborted)
                    setHistory(response.data.decisions);
            })
            .catch((caught) => {
                if (controller.signal.aborted) return;
                if (
                    axios.isAxiosError(caught) &&
                    [401, 403, 404].includes(caught.response?.status ?? 0)
                ) {
                    setHistory(null);
                    onUnavailable();
                    return;
                }
                if (!axios.isCancel(caught))
                    setError(
                        'Decision history could not be loaded. Check your connection and try again.',
                    );
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });
        return () => controller.abort();
    }, [
        section,
        historyPage,
        item.id,
        referral.reference,
        onUnavailable,
        retry,
    ]);
    const steps = [
        {
            key: 'case',
            label: 'Assistance',
            blurb: 'Navigator, preparation and roles',
            icon: UserRound,
        },
        {
            key: 'decision',
            label: 'Current funding',
            blurb: 'Decision and verification',
            icon: Wallet,
        },
        {
            key: 'history',
            label: 'Decision history',
            blurb: 'Preserved external decisions',
            icon: History,
        },
    ];
    const decision = item.latest_decision;
    // The contact directory entry captured with this case's pathway version (display only).
    const pathwayContact = item.details.guide?.contact;
    return (
        <WizardShell
            open
            onClose={onClose}
            title={item.pathway_label}
            description={`Funding assistance for ${referral.name}`}
            railTitle={item.pathway_label}
            railIcon={Wallet}
            railSub={item.navigator_name ?? 'No navigator assigned'}
            steps={steps}
            stepIndex={section}
            headerLabel={steps[section].label}
            sequential={false}
            onStepClick={setSection}
            footerEnd={
                <Button variant="outline" onClick={onClose}>
                    Close
                </Button>
            }
        >
            <div className="space-y-5">
                <div className="flex items-center justify-between gap-3">
                    <h2 className="text-section-title">
                        {steps[section].label}
                    </h2>
                    <EntityKebab
                        actions={actions}
                        label="Funding case actions"
                    />
                </div>
                {section === 0 && (
                    <>
                        <ReviewCard icon={UserRound} title="Assistance plan">
                            <ReviewRow
                                label="Status"
                                value={label(item.status)}
                            />
                            <ReviewRow
                                label="Navigator"
                                value={item.navigator_name ?? 'Unassigned'}
                            />
                            <ReviewRow
                                label="Starting point"
                                value={item.details.starting_point}
                            />
                            <ReviewRow
                                label="Requested assistance"
                                value={item.details.assistance_requested}
                            />
                            <ReviewRow
                                label="Person’s review plan"
                                value={item.details.person_review_plan}
                            />
                            <ReviewRow
                                label="Next action"
                                value={item.next_action ?? 'Needs planning'}
                            />
                            <ReviewRow
                                label="Due"
                                value={
                                    item.next_due_at
                                        ? formatDateTimeLong(item.next_due_at)
                                        : 'Not recorded'
                                }
                            />
                            <ReviewRow
                                label="Blocker"
                                value={item.details.blocker ?? 'None recorded'}
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={Wallet}
                            title="Agency and payer roles"
                        >
                            {[
                                'funding_body',
                                'assessor',
                                'host',
                                'contracting_party',
                                'invoice_payer',
                            ].map((key) => (
                                <ReviewRow
                                    key={key}
                                    label={label(key)}
                                    value={
                                        item.details.roles?.[key] ??
                                        'To clarify'
                                    }
                                />
                            ))}
                            <ReviewRow
                                label="External contact"
                                value={
                                    [
                                        item.details.external_contact?.name,
                                        item.details.external_contact?.phone,
                                        item.details.external_contact?.email,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ') || 'Not recorded'
                                }
                            />
                            {pathwayContact && (
                                <>
                                    <ReviewRow
                                        label="Pathway contact"
                                        value={
                                            [
                                                pathwayContact.organisation,
                                                pathwayContact.phone,
                                                pathwayContact.email,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ') || undefined
                                        }
                                    />
                                    {pathwayContact.website && (
                                        <ReviewRow
                                            label="Pathway website"
                                            value={
                                                <a
                                                    href={
                                                        pathwayContact.website
                                                    }
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="text-primary underline focus-visible:ring-2 focus-visible:ring-ring"
                                                >
                                                    Open website
                                                </a>
                                            }
                                        />
                                    )}
                                    {pathwayContact.region_notes && (
                                        <ReviewRow
                                            label="Regional notes"
                                            value={pathwayContact.region_notes}
                                        />
                                    )}
                                </>
                            )}
                        </ReviewCard>
                        <ReviewCard
                            icon={ClipboardCheck}
                            title="Pathway preparation"
                        >
                            {item.details.guide?.evidence_checklist.map(
                                (required) => {
                                    const prepared =
                                        item.details.preparation?.find(
                                            (row) => row.item === required,
                                        );
                                    return (
                                        <ReviewRow
                                            key={required}
                                            label={required}
                                            value={
                                                prepared
                                                    ? `${label(prepared.status)} · ${prepared.note}`
                                                    : 'Needed'
                                            }
                                        />
                                    );
                                },
                            )}
                            {!item.details.guide?.evidence_checklist.length && (
                                <ReviewRow
                                    label="Checklist"
                                    value="None configured for this pathway version"
                                />
                            )}
                            <ReviewRow
                                label="External submissions recorded"
                                value={String(
                                    item.details.submission_count ?? 0,
                                )}
                            />
                        </ReviewCard>
                    </>
                )}
                {section === 1 && (
                    <>
                        {item.personal_income ? (
                            <p className="text-subtle">
                                Personal income assistance is separate from
                                provider service funding and cannot be allocated
                                to care claims.
                            </p>
                        ) : decision ? (
                            <Alert
                                variant={
                                    item.availability.usable
                                        ? 'success'
                                        : 'warning'
                                }
                            >
                                <AlertDescription>
                                    {item.availability.usable
                                        ? 'Verified funding terms are currently usable for matching support. Proposal allocation, agreement and service-start checks still apply.'
                                        : item.availability.issues.join(' ')}
                                </AlertDescription>
                            </Alert>
                        ) : null}
                        {decision ? (
                            <>
                                <ReviewCard
                                    icon={Wallet}
                                    title="Latest external decision"
                                >
                                    <ReviewRow
                                        label="Outcome"
                                        value={label(decision.outcome)}
                                    />
                                    <ReviewRow
                                        label="Decision-maker"
                                        value={decision.terms.decision_maker}
                                    />
                                    <ReviewRow
                                        label="Reference"
                                        value={
                                            decision.terms.external_reference
                                        }
                                    />
                                    <ReviewRow
                                        label="Decision date"
                                        value={formatDateOnly(
                                            decision.terms.decision_date,
                                        )}
                                    />
                                    <ReviewRow
                                        label="Validity"
                                        value={`${decision.terms.valid_from ? formatDateOnly(decision.terms.valid_from) : 'Unknown'} – ${decision.terms.valid_until ? formatDateOnly(decision.terms.valid_until) : 'Unknown'}`}
                                    />
                                    <ReviewRow
                                        label="Conditions"
                                        value={decision.terms.conditions}
                                    />
                                </ReviewCard>
                                <div className="space-y-3">
                                    {decision.terms.lines.map((line, index) => (
                                        <Allocation
                                            key={index}
                                            line={line}
                                            options={options}
                                        />
                                    ))}
                                </div>
                                <ReviewCard
                                    icon={ClipboardCheck}
                                    title="Independent verification"
                                >
                                    <ReviewRow
                                        label="Verification"
                                        value={label(
                                            item.details.verification?.status ??
                                                'unverified',
                                        )}
                                    />
                                    <ReviewRow
                                        label="Scope review"
                                        value={
                                            item.details.verification
                                                ?.scope_note ?? 'Not reviewed'
                                        }
                                    />
                                    <ReviewRow
                                        label="Review due"
                                        value={
                                            item.details.verification
                                                ?.review_due_at
                                                ? formatDateTimeLong(
                                                      item.details.verification
                                                          .review_due_at,
                                                  )
                                                : 'Not recorded'
                                        }
                                    />
                                </ReviewCard>
                            </>
                        ) : (
                            <EmptyState
                                icon={Wallet}
                                title="No external decision recorded"
                                description="Preparation and submission do not imply funding approval. Unknown allocations remain unknown."
                            />
                        )}
                    </>
                )}
                {section === 2 && (
                    <>
                        {loading && (
                            <div role="status">
                                <LoadingState message="Loading decision history…" />
                            </div>
                        )}
                        {error && (
                            <ErrorState
                                title="Decision history unavailable"
                                message={error}
                                onRetry={() => setRetry((value) => value + 1)}
                            />
                        )}
                        {!loading && history && !history.total && (
                            <EmptyState
                                icon={History}
                                title="No decision history yet"
                            />
                        )}
                        {history?.data.map((entry) => (
                            <ReviewCard
                                key={entry.id}
                                icon={History}
                                title={`Version ${entry.version} · ${label(entry.outcome)}`}
                            >
                                <ReviewRow
                                    label="Decision-maker"
                                    value={entry.terms.decision_maker}
                                />
                                <ReviewRow
                                    label="Reference"
                                    value={entry.terms.external_reference}
                                />
                                <ReviewRow
                                    label="Decision date"
                                    value={formatDateOnly(
                                        entry.terms.decision_date,
                                    )}
                                />
                                <ReviewRow
                                    label="Conditions"
                                    value={entry.terms.conditions}
                                />
                                <ReviewRow
                                    label="Recorded"
                                    value={formatDateTimeLong(
                                        entry.recorded_at,
                                    )}
                                />
                                <div className="mt-4 space-y-3">
                                    {entry.terms.lines.map((line, index) => (
                                        <Allocation
                                            key={index}
                                            line={line}
                                            options={options}
                                        />
                                    ))}
                                </div>
                            </ReviewCard>
                        ))}
                        {history && history.last_page > 1 && (
                            <nav
                                aria-label="Decision history pages"
                                className="flex items-center justify-between gap-3"
                            >
                                <Button
                                    variant="outline"
                                    disabled={loading || historyPage <= 1}
                                    onClick={() =>
                                        setHistoryPage((page) => page - 1)
                                    }
                                >
                                    <ChevronLeft className="size-4" />
                                    Previous
                                </Button>
                                <span className="text-subtle">
                                    Page {history.current_page} of{' '}
                                    {history.last_page}
                                </span>
                                <Button
                                    variant="outline"
                                    disabled={
                                        loading ||
                                        historyPage >= history.last_page
                                    }
                                    onClick={() =>
                                        setHistoryPage((page) => page + 1)
                                    }
                                >
                                    Next
                                    <ChevronRight className="size-4" />
                                </Button>
                            </nav>
                        )}
                    </>
                )}
            </div>
        </WizardShell>
    );
}

export function FundingPanel({
    referral,
    permissions,
    options,
}: {
    referral: ReferralDetail;
    permissions: Permissions;
    options: Options;
}) {
    const [opening, setOpening] = useState<{ previous?: FundingCase } | null>(
        null,
    );
    const [view, setView] = useState<number | null>(null);
    const [unavailable, setUnavailable] = useState(false);
    const withdraw = useCallback(() => setUnavailable(true), []);
    const [editing, setEditing] = useState<{
        id: number;
        action: FundingAction;
    } | null>(null);
    const ctxMenu = useEntityContextMenu<FundingCase>();
    const funding = referral.funding;
    if (!funding) return null;
    if (unavailable)
        return (
            <EmptyState
                icon={ShieldCheck}
                title="Funding information is no longer available"
                description="Access has changed. Private funding details have been hidden. Check current access before continuing."
                action={
                    <Button variant="outline" asChild>
                        <Link href={referralHref(referral.reference)}>
                            Check current access
                        </Link>
                    </Button>
                }
            />
        );
    const canOpen =
        permissions['funding.manage'] &&
        referral.stage !== 'closed' &&
        !referral.general_enquiry &&
        funding.authority.assistance_permitted;
    const selected = funding.cases.data.find((item) => item.id === view);
    const editingItem = funding.cases.data.find(
        (item) => item.id === editing?.id,
    );
    const actions = (item: FundingCase): MenuItem[] => {
        if (
            referral.stage === 'closed' ||
            ['closed', 'withdrawn'].includes(item.status)
        )
            return [];
        const openAction = (action: FundingAction) => {
            setView(null);
            setEditing({ id: item.id, action });
        };
        return [
            ...(permissions['funding.manage']
                ? [
                      {
                          label: 'Change navigator',
                          icon: UserRound,
                          onClick: () => openAction('reassign'),
                      },
                      ...(funding.authority.assistance_permitted
                          ? [
                                {
                                    label: 'Prepare assistance',
                                    icon: ClipboardCheck,
                                    onClick: () => openAction('prepare'),
                                },
                            ]
                          : []),
                      ...(!item.latest_decision
                          ? [
                                {
                                    label: 'Record external submission',
                                    icon: Send,
                                    onClick: () => openAction('submission'),
                                },
                            ]
                          : []),
                      {
                          label: 'Record external decision',
                          icon: Wallet,
                          onClick: () => openAction('decision'),
                      },
                      {
                          label: 'Update progress or end assistance',
                          icon: Pencil,
                          onClick: () => openAction('progress'),
                      },
                  ]
                : []),
            ...(permissions['funding.verify'] && item.latest_decision
                ? [
                      {
                          label: 'Independently verify decision',
                          icon: ShieldCheck,
                          onClick: () => openAction('verification'),
                      },
                  ]
                : []),
        ];
    };
    /** One MenuItem[] feeds both the card kebab and its right-click menu. */
    const cardActions = (item: FundingCase): MenuItem[] => [
        {
            label: 'View funding case',
            icon: Eye,
            onClick: () => setView(item.id),
        },
        ...actions(item),
        ...(canOpen &&
        (item.latest_decision || ['closed', 'withdrawn'].includes(item.status))
            ? [
                  {
                      label: 'Open linked review attempt',
                      icon: Plus,
                      onClick: () => setOpening({ previous: item }),
                  },
              ]
            : []),
    ];
    return (
        <div className="space-y-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-section-title">Funding assistance</h2>
                    <p className="text-subtle">
                        {funding.cases.total}{' '}
                        {funding.cases.total === 1 ? 'case' : 'cases'} ·
                        separate pathways and external decisions
                    </p>
                </div>
                {permissions['funding.manage'] && (
                    <Button disabled={!canOpen} onClick={() => setOpening({})}>
                        <Plus className="size-4" />
                        Open funding case
                    </Button>
                )}
            </div>
            {!funding.authority.assistance_permitted && (
                <p className="text-subtle">
                    A current funding-assistance authority review is required
                    before opening or preparing a case. Existing history remains
                    available.
                </p>
            )}
            {!funding.cases.data.length ? (
                <EmptyState
                    icon={Wallet}
                    title="No funding assistance cases"
                    description="Choose a pathway with the person and assign a navigator to help with preparation and follow-up."
                />
            ) : (
                <EntityCardGrid>
                    {funding.cases.data.map((item) => (
                        <EntityCard
                            key={item.id}
                            name={item.pathway_label}
                            icon={Wallet}
                            meridian={
                                item.availability.usable ? 'success' : 'neutral'
                            }
                            subline={
                                item.personal_income
                                    ? 'Personal income assistance'
                                    : (options.services.find(
                                          (service) =>
                                              service.id ===
                                              item.details.service_id,
                                      )?.label ?? 'Service funding')
                            }
                            onOpen={() => setView(item.id)}
                            onContextMenu={(event) => ctxMenu.open(event, item)}
                            actions={cardActions(item)}
                            chips={
                                <>
                                    <StatusBadge status={item.status} />
                                    <EntityChip>
                                        {item.personal_income
                                            ? 'Separate income assistance'
                                            : item.availability.usable
                                              ? 'Verified terms available'
                                              : 'Not ready for service funding'}
                                    </EntityChip>
                                </>
                            }
                            footer={{
                                personName: item.navigator_name,
                                primary:
                                    item.navigator_name ??
                                    'Unassigned navigator',
                                secondary:
                                    item.next_action ??
                                    'No next action recorded',
                            }}
                        />
                    ))}
                </EntityCardGrid>
            )}
            <LaravelPagination
                links={funding.cases.links}
                lastPage={funding.cases.last_page}
                preserveScroll
                only={['referral']}
            />
            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={Wallet}
                    title={ctxMenu.ctx.record.pathway_label}
                    items={cardActions(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}
            {opening && (
                <FundingOpenDialog
                    referral={referral}
                    options={options}
                    previous={opening.previous}
                    onClose={() => setOpening(null)}
                />
            )}
            {selected && (
                <CaseDetail
                    item={selected}
                    referral={referral}
                    options={options}
                    onClose={() => setView(null)}
                    onUnavailable={withdraw}
                    actions={actions(selected)}
                />
            )}
            {editing && editingItem && (
                <FundingActionDialog
                    action={editing.action}
                    item={editingItem}
                    referral={referral}
                    options={options}
                    onClose={() => {
                        setEditing(null);
                        setView(editingItem.id);
                    }}
                />
            )}
        </div>
    );
}
