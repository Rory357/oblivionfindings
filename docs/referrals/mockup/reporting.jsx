import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
import { toDateInput } from '@/lib/datetime';
import {
    ArrowUpRight,
    ChartNoAxesCombined,
    Download,
    HeartHandshake,
    ListFilter,
    LockKeyhole,
    Users,
    Wallet,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { usePrototype } from './context';
import { TODAY, coverage, date, money } from './data';
import { followupsFor, fundingIssues, knownNumber } from './model';
import {
    CurrentWorkload,
    ReferralReportBreakdowns,
    ReferralReportOverview,
} from './report-dashboard';
import { Button, Empty, Field, Modal, Notice, Section } from './ui';
const initialFilters = () => {
    const defaults = {
        query: '',
        site: 'all',
        period: 'This month',
        from: TODAY.slice(0, 7) + '-01',
        to: TODAY,
    };
    try {
        return {
            ...defaults,
            ...JSON.parse(
                sessionStorage.getItem('referrals-report-filters') || '{}',
            ),
        };
    } catch {
        return defaults;
    }
};
export function OperationalReports({ records }) {
    const { can, navigate, request, phoneLayout, eligibleSites } =
        usePrototype();
    const [filters, setFilters] = useState(initialFilters),
        [detail, setDetail] = useState(null);
    const [reportView, setReportView] = useState(() => {
        try {
            const saved = sessionStorage.getItem('referrals-report-view');
            return ['overview', 'details', 'workload'].includes(saved)
                ? saved
                : 'overview';
        } catch {
            return 'overview';
        }
    });
    const changeView = (value) => {
        setReportView(value);
        try {
            sessionStorage.setItem('referrals-report-view', value);
        } catch {
            /* In-memory navigation remains available. */
        }
    };
    const change = (key, value) => setFilters((s) => ({ ...s, [key]: value }));
    const reset = () =>
        setFilters({
            query: '',
            site: 'all',
            period: 'This month',
            from: TODAY.slice(0, 7) + '-01',
            to: TODAY,
        });
    useEffect(() => {
        try {
            sessionStorage.setItem(
                'referrals-report-filters',
                JSON.stringify(filters),
            );
        } catch {
            /* Filters remain usable in this tab. */
        }
    }, [filters]);
    if (!can('reports'))
        return (
            <>
                <PageHeader
                    title="Referral reports"
                    icon={LockKeyhole}
                    subline="Reporting permission required"
                />
                <div className="body-stack">
                    <Empty title="Reporting permission required">
                        Your role must include reporting access. Referral and
                        funding permissions still apply to each result.
                    </Empty>
                </div>
            </>
        );
    const thirtyDays = new Date(TODAY + 'T12:00:00Z');
    thirtyDays.setUTCDate(thirtyDays.getUTCDate() - 29);
    const from =
        filters.period === 'This month'
            ? TODAY.slice(0, 7) + '-01'
            : filters.period === 'Last 30 days'
              ? thirtyDays.toISOString().slice(0, 10)
              : filters.period === 'All time'
                ? records.map((r) => toDateInput(r.createdAt)).sort()[0] ||
                  TODAY
                : filters.from;
    const to = filters.period === 'Custom dates' ? filters.to : TODAY;
    const isWorkload = reportView === 'workload';
    const valid = isWorkload || (!!from && !!to && from <= to);
    const cohort = valid
        ? records.filter(
              (r) =>
                  (isWorkload
                      ? !['Closed', 'Converted'].includes(r.stage)
                      : toDateInput(r.createdAt) >= from &&
                        toDateInput(r.createdAt) <= to) &&
                  (filters.site === 'all' || r.site === filters.site) &&
                  (r.name + ' ' + r.id + ' ' + r.owner)
                      .toLowerCase()
                      .includes(filters.query.toLowerCase()),
          )
        : [];
    const active = cohort.filter(
        (r) => !['Closed', 'Converted'].includes(r.stage),
    );
    const ageDays = (at) =>
        Math.max(
            0,
            Math.floor((Date.now() - new Date(at).getTime()) / 86400000),
        );
    const contacted = cohort.filter((r) =>
        r.notes.some(
            (n) =>
                n.successful &&
                Number.isFinite(new Date(n.occurredAt || n.at).getTime()),
        ),
    );
    const firstContactHours = (r) =>
        Math.max(
            0,
            (Math.min(
                ...r.notes
                    .filter(
                        (n) =>
                            n.successful &&
                            Number.isFinite(
                                new Date(n.occurredAt || n.at).getTime(),
                            ),
                    )
                    .map((n) => new Date(n.occurredAt || n.at).getTime()),
            ) -
                new Date(r.createdAt).getTime()) /
                3600000,
        );
    const groups = [
        [
            'Unassigned for more than one day',
            active.filter(
                (r) =>
                    r.owner === 'Intake team' &&
                    Date.now() - new Date(r.createdAt).getTime() > 86400000,
            ),
            'Open referrals still owned by central intake more than 24 hours after receipt.',
        ],
        [
            'Successful human contact recorded',
            contacted,
            'At least one logged contact whose outcome is Spoke with person. Attempts and automated receipts are excluded.',
        ],
        [
            'Capacity waitlist',
            active.filter(
                (r) => r.stage === 'On hold' && r.holdReason === 'Capacity',
            ),
            'On hold with the explicit Capacity reason. Other holds are excluded.',
        ],
        [
            'Other holds',
            active.filter(
                (r) => r.stage === 'On hold' && r.holdReason !== 'Capacity',
            ),
            'On hold for information, personal preference or another recorded reason.',
        ],
        [
            'Service commenced',
            cohort.filter((r) => r.serviceStarted),
            'Service commencement approved separately after referral conversion.',
        ],
        [
            'Closed with another provider',
            cohort.filter(
                (r) =>
                    r.stage === 'Closed' &&
                    r.closureHistory?.at(-1)?.category === 'Another provider',
            ),
            'Explicit alternative-provider outcome; not counted as a failed conversion.',
        ],
        [
            'Other closed referrals',
            cohort.filter(
                (r) =>
                    r.stage === 'Closed' &&
                    r.closureHistory?.at(-1)?.category !== 'Another provider',
            ),
            'Closed with another or unrecorded closure reason.',
        ],
        [
            'Reopened',
            cohort.filter((r) =>
                r.closureHistory?.some(
                    (h) => h.from === 'Closed' && h.to !== 'Closed',
                ),
            ),
            'At least one reasoned reopening in the lifecycle history.',
        ],
        ...(can('fundingRead')
            ? [
                  [
                      'Pending funding for 14+ days',
                      cohort.filter((r) =>
                          r.funding.some(
                              (f) =>
                                  !f.verified &&
                                  f.status !== 'Withdrawn' &&
                                  ageDays(f.createdAt || r.createdAt) >= 14,
                          ),
                      ),
                      'Unverified case open for at least 14 days. Provider tracking age, not an agency deadline.',
                  ],
                  [
                      'Uncosted support',
                      cohort.filter((r) => !knownNumber(r.proposal.weekly)),
                      'No agreed weekly estimate yet; distinct from a zero cost.',
                  ],
                  [
                      'Known funding gap',
                      cohort.filter(
                          (r) =>
                              knownNumber(r.proposal.weekly) &&
                              coverage(r) < Number(r.proposal.weekly),
                      ),
                      'Known proposal total exceeds valid verified provider contributions.',
                  ],
                  [
                      'Declined decisions',
                      cohort.filter((r) =>
                          r.funding.some((f) => f.decision === 'Declined'),
                      ),
                      'A recorded decline. Verification of a decline never counts as funded success.',
                  ],
              ]
            : []),

        [
            'Received in period',
            cohort,
            'Referrals created between the chosen dates, including closed and converted records.',
        ],
        [
            'Open referrals',
            active,
            'Received in period and currently neither closed nor converted.',
        ],
        [
            'Overdue work',
            active.filter((r) =>
                followupsFor(r).some(
                    (w) =>
                        (!w.financial || can('fundingRead')) && w.due < TODAY,
                ),
            ),
            'Distinct open referrals with an incomplete permitted follow-up due before today.',
        ],
        [
            'Converted',
            cohort.filter((r) => r.personId),
            'Received in period and now linked to a supported person.',
        ],
        [
            'No stage progress for 14 days',
            active.filter(
                (r) =>
                    (new Date(TODAY + 'T12:00:00') - new Date(r.stageAt)) /
                        86400000 >=
                    14,
            ),
            'Open referrals whose latest stage change was at least 14 days ago.',
        ],
        ...(can('fundingRead')
            ? [
                  [
                      'Funding needs review',
                      cohort.filter((r) =>
                          r.funding.some(
                              (f) => !f.income && fundingIssues(f, r).length,
                          ),
                      ),
                      'Referrals with a provider funding case that does not meet all current start-date, verification and allocation checks.',
                  ],
              ]
            : []),
    ];

    const openGroup = ([label, rows, definition]) =>
        setDetail({ label, rows, definition });
    const findGroup = (label) => groups.find((g) => g[0] === label);
    const costed = cohort.filter((r) => knownNumber(r.proposal.weekly));
    const meanContact = contacted.length
        ? (
              contacted.reduce((sum, r) => sum + firstContactHours(r), 0) /
              contacted.length
          ).toFixed(1) + ' hrs'
        : '—';
    const reportMeters = [
        ...[
            'Received in period',
            'Open referrals',
            'Overdue work',
            'Converted',
        ].map((label) => ({
            label,
            group: findGroup(label),
            value: findGroup(label)[1].length,
            caption: 'View matching referrals',
            tone: label === 'Overdue work' ? 'warning' : 'brand',
        })),
        {
            label: 'Human contact recorded',
            value: `${contacted.length} / ${cohort.length}`,
            caption: 'Successful conversations',
            group: findGroup('Successful human contact recorded'),
        },
        {
            label: 'First contact time',
            value: meanContact,
            caption: contacted.length
                ? `Mean across ${contacted.length} contacted referrals`
                : 'No successful contact recorded',
            group: [
                'Time to first human contact',
                contacted,
                'Mean elapsed time from receipt to first successful human contact; attempts and automatic acknowledgements are excluded.',
            ],
        },
        {
            label: 'Capacity waitlist',
            value: findGroup('Capacity waitlist')[1].length,
            caption: 'Waiting for available support',
            group: findGroup('Capacity waitlist'),
        },
        ...(can('fundingRead')
            ? [
                  {
                      label: 'Known weekly funding gap',
                      value: money(
                          costed.reduce(
                              (sum, r) =>
                                  sum +
                                  Math.max(
                                      0,
                                      Number(r.proposal.weekly) - coverage(r),
                                  ),
                              0,
                          ),
                      ),
                      caption: `${costed.length} costed · ${cohort.length - costed.length} uncosted referrals`,
                      tone: 'warning',
                      group: findGroup('Known funding gap'),
                  },
              ]
            : [
                  {
                      label: 'Service commenced',
                      value: findGroup('Service commenced')[1].length,
                      caption: 'Approved service starts',
                      group: findGroup('Service commenced'),
                  },
              ]),
    ];
    const sections = [
        {
            title: 'Intake and follow-up',
            icon: Users,
            caption: 'Contact, ownership and delays in the received cohort.',
            labels: [
                'Unassigned for more than one day',
                'Successful human contact recorded',
                'Capacity waitlist',
                'Other holds',
                'No stage progress for 14 days',
            ],
        },
        ...(can('fundingRead')
            ? [
                  {
                      title: 'Funding and access',
                      icon: Wallet,
                      caption:
                          'Provider funding and affordability. Personal income assistance does not count as service funding.',
                      labels: [
                          'Pending funding for 14+ days',
                          'Uncosted support',
                          'Known funding gap',
                          'Declined decisions',
                          'Funding needs review',
                      ],
                  },
              ]
            : []),
        {
            title: 'Referral outcomes',
            icon: HeartHandshake,
            caption:
                'Conversion, service commencement and closure are separate outcomes.',
            labels: [
                'Service commenced',
                'Closed with another provider',
                'Other closed referrals',
                'Reopened',
            ],
        },
    ];
    const workloadGroups = [
        'Open referrals',
        'Overdue work',
        'Unassigned for more than one day',
        'Capacity waitlist',
        'No stage progress for 14 days',
        ...(can('fundingRead') ? ['Funding needs review'] : []),
    ].map((label) => {
        const group = findGroup(label);
        return [
            label,
            group[1],
            `All current permitted open referrals, regardless of received date. ${label === 'Open referrals' ? '' : group[2]}`,
        ];
    });
    const visibleMeters = isWorkload
        ? workloadGroups.map((g) => ({
              label: g[0],
              value: g[1].length,
              caption: 'All current open work',
              group: g,
          }))
        : reportMeters;
    const exportReport = {
        title: isWorkload
            ? 'Current referral workload'
            : 'Referral reports — ' +
              (reportView === 'details' ? 'Detailed reports' : 'Overview'),
        period: isWorkload
            ? 'All current open work — received-date filter does not apply'
            : `${from} to ${to} (received dates, Pacific/Auckland)`,
        site: filters.site === 'all' ? 'All permitted sites' : filters.site,
        search: filters.query || 'None',
        measures: [
            ...new Map(
                [
                    ...(!isWorkload
                        ? groups.map(([label, rows]) => ({
                              label,
                              value: rows.length,
                          }))
                        : []),
                    ...visibleMeters.map(({ label, value }) => ({
                        label,
                        value,
                    })),
                ].map((m) => [m.label, m]),
            ).values(),
        ],
    };
    const metric = ([label, rows, definition]) => (
        <button
            key={label}
            className="report-measure-button frontline-focus"
            onClick={() => openGroup([label, rows, definition])}
        >
            <span>{label}</span>
            <strong>{rows.length}</strong>
            <ArrowUpRight size={14} aria-hidden="true" />
        </button>
    );
    return (
        <>
            <PageHeader
                className="referral-report-header"
                title="Referral reports"
                icon={ChartNoAxesCombined}
                frontline={phoneLayout}
                mobileSummary={
                    isWorkload
                        ? `${cohort.length} current open referrals · all received dates`
                        : valid
                          ? cohort.length +
                            ' referrals · ' +
                            date(from) +
                            ' – ' +
                            date(to)
                          : 'Check the report dates'
                }
                titleChip={
                    <PageHeaderStatusChip variant="neutral">
                        {isWorkload
                            ? 'All current open work'
                            : 'Received cohort'}
                    </PageHeaderStatusChip>
                }
                subline={
                    isWorkload
                        ? `${cohort.length} current open referrals · includes older enquiries · NZ dates`
                        : valid
                          ? `Received ${date(from)} – ${date(to)} · ${cohort.length} matching referrals · NZ dates`
                          : 'Choose a valid received-date range'
                }
                rail={
                    <PageHeaderRail
                        ariaLabel="Report views"
                        items={[
                            {
                                key: 'overview',
                                label: 'Overview',
                                icon: ChartNoAxesCombined,
                            },
                            {
                                key: 'details',
                                label: 'Detailed reports',
                                icon: ListFilter,
                            },
                            {
                                key: 'workload',
                                label: 'Current workload',
                                icon: Users,
                            },
                        ]}
                        value={reportView}
                        onSelect={changeView}
                        showFind={false}
                    />
                }
                actions={
                    <>
                        <PageHeaderSearch
                            value={filters.query}
                            onChange={(v) => change('query', v)}
                            placeholder="Search report referrals"
                        />
                        {can('export') && (
                            <PageHeaderPrimaryButton
                                disabled={!valid || !cohort.length}
                                onClick={() =>
                                    request('export', null, {
                                        records: cohort,
                                        report: exportReport,
                                    })
                                }
                            >
                                <Download />
                                Preview export
                            </PageHeaderPrimaryButton>
                        )}
                    </>
                }
                meters={visibleMeters.map((m) => (
                    <PageHeaderMeterBlock
                        key={m.label}
                        label={m.label}
                        tone={m.tone || 'brand'}
                        onClick={() => valid && openGroup(m.group)}
                    >
                        <PageHeaderMeterBig>
                            {valid ? m.value : '—'}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {m.caption}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                ))}
                filters={
                    <>
                        {!isWorkload && (
                            <PageHeaderFilterSelect
                                label="Report period"
                                value={filters.period}
                                allValue="This month"
                                options={[
                                    'This month',
                                    'Last 30 days',
                                    'All time',
                                    'Custom dates',
                                ].map((value) => ({ value, label: value }))}
                                onChange={(v) => change('period', v)}
                            />
                        )}
                        <PageHeaderFilterSelect
                            label="Report site"
                            value={filters.site}
                            options={[
                                { value: 'all', label: 'All permitted sites' },
                                ...eligibleSites.map((value) => ({
                                    value,
                                    label: value,
                                })),
                            ]}
                            onChange={(v) => change('site', v)}
                        />
                        <PageHeaderGlassButton onClick={reset}>
                            Reset report filters
                        </PageHeaderGlassButton>
                        {!isWorkload && filters.period === 'Custom dates' && (
                            <div className="report-custom-dates">
                                <Field
                                    label="Received from"
                                    type="date"
                                    value={filters.from}
                                    onChange={(v) => change('from', v)}
                                />
                                <Field
                                    label="Received through"
                                    type="date"
                                    value={filters.to}
                                    onChange={(v) => change('to', v)}
                                />
                            </div>
                        )}
                    </>
                }
            />
            <div className="body-stack report-page">
                {!valid ? (
                    <Notice tone="critical">
                        Start date must be on or before the end date. Both dates
                        are required.
                    </Notice>
                ) : !cohort.length ? (
                    <Empty title="No referrals match this report">
                        <p>
                            Change the report period, site or search to see
                            permitted referrals.
                        </p>
                        <Button variant="outline" onClick={reset}>
                            Reset report filters
                        </Button>
                    </Empty>
                ) : (
                    <>
                        {isWorkload ? (
                            <CurrentWorkload
                                records={active}
                                groups={workloadGroups}
                                openGroup={openGroup}
                            />
                        ) : reportView === 'overview' ? (
                            <ReferralReportOverview
                                cohort={cohort}
                                active={active}
                                from={from}
                                to={to}
                                canFunding={can('fundingRead')}
                                groups={groups}
                                openGroup={openGroup}
                                showDetails={() => changeView('details')}
                            />
                        ) : (
                            <div className="report-dashboard">
                                <ReferralReportBreakdowns
                                    cohort={cohort}
                                    openGroup={openGroup}
                                />
                                <div className="report-measure-sections">
                                    {sections.map((section) => (
                                        <Section
                                            key={section.title}
                                            title={section.title}
                                            icon={section.icon}
                                            caption={section.caption}
                                        >
                                            <div className="report-outcome-links">
                                                {section.title ===
                                                    'Intake and follow-up' && (
                                                    <button
                                                        className="report-measure-button frontline-focus"
                                                        onClick={() =>
                                                            openGroup([
                                                                'Time to first human contact',
                                                                contacted,
                                                                'Mean elapsed time from referral receipt to first successful human contact. Attempts and automatic acknowledgements are excluded.',
                                                            ])
                                                        }
                                                    >
                                                        <span>
                                                            Time to first human
                                                            contact
                                                        </span>
                                                        <strong>
                                                            {contacted.length
                                                                ? (
                                                                      contacted.reduce(
                                                                          (
                                                                              sum,
                                                                              r,
                                                                          ) =>
                                                                              sum +
                                                                              firstContactHours(
                                                                                  r,
                                                                              ),
                                                                          0,
                                                                      ) /
                                                                      contacted.length
                                                                  ).toFixed(1) +
                                                                  ' hrs'
                                                                : '—'}
                                                        </strong>
                                                        <ArrowUpRight
                                                            size={14}
                                                        />
                                                    </button>
                                                )}
                                                {section.labels
                                                    .map(findGroup)
                                                    .filter(Boolean)
                                                    .map(metric)}
                                            </div>
                                        </Section>
                                    ))}
                                </div>
                            </div>
                        )}
                        {!isWorkload && (
                            <Notice>
                                Conversion proportion:{' '}
                                {Math.round(
                                    (cohort.filter((r) => r.personId).length /
                                        cohort.length) *
                                        100,
                                )}
                                % of this received cohort. This is not the
                                number of conversions performed during the
                                period. Funding age measures provider tracking
                                time, not an agency response deadline.
                            </Notice>
                        )}
                    </>
                )}
            </div>
            {detail && (
                <Modal
                    title={detail.label}
                    description={detail.definition}
                    onClose={() => setDetail(null)}
                >
                    {detail.rows.length ? (
                        detail.rows.map((r) => (
                            <div className="work-row" key={r.id}>
                                <div className="grow">
                                    <strong>{r.name}</strong>
                                    <p className="text-subtle">
                                        {r.id} · {r.stage} · {r.site} ·{' '}
                                        {Number.isFinite(ageDays(r.stageAt))
                                            ? ageDays(r.stageAt) +
                                              ' days in this stage'
                                            : 'Stage date not recorded'}
                                    </p>
                                </div>
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        setDetail(null);
                                        navigate('Referrals', r.id);
                                    }}
                                >
                                    Open referral
                                </Button>
                            </div>
                        ))
                    ) : (
                        <Empty title="No matching referrals" />
                    )}
                </Modal>
            )}
        </>
    );
}
