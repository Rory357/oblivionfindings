import { ClipboardList, ShieldCheck, Wallet } from 'lucide-react';
import { usePrototype } from './context';
import { TODAY, date, makeFunding, money } from './data';
import {
    allocationIssues,
    businessDate,
    clone,
    displayStamp,
    fundingIssues,
    knownNumber,
    pinnedConfig,
    stamp,
    uid,
} from './model';
import {
    Button,
    Empty,
    Field,
    Notice,
    ReviewCard,
    ReviewRow,
    Section,
    Wizard,
} from './ui';
export const fundingTermFields = [
    {
        key: 'awarded',
        label: 'Total approved quantity / amount',
        type: 'number',
    },
    {
        key: 'allocated',
        label: 'Quantity / amount allocated to this provider',
        type: 'number',
    },
    {
        key: 'unit',
        label: 'Original authorization units',
        type: 'select',
        options: ['NZD', 'Hours', 'Days', 'Visits'],
    },
    {
        key: 'period',
        label: 'Original authorization period',
        type: 'select',
        options: ['Week', 'Fortnight', 'Month', 'Year', 'Total period'],
    },
    { key: 'validFrom', label: 'Authorization valid from', type: 'date' },
    { key: 'validTo', label: 'Authorization valid until', type: 'date' },
    {
        key: 'service',
        label: 'Approved service',
        type: 'select',
        options: [
            'Supported living',
            'Community participation',
            'Additional supported living',
        ],
    },
    {
        key: 'weeklyBasis',
        label: 'Approved basis for weekly NZD equivalent (if different units / period)',
        type: 'textarea',
    },
    {
        key: 'conditionsMet',
        label: 'Required funding conditions have been resolved',
        type: 'check',
    },
    {
        key: 'providerConfirmed',
        label: 'Provider agreement and purchasing scope confirmed',
        type: 'check',
    },
];
export function FundingValidity({ r, f }) {
    const issues = fundingIssues(f, r);
    return (
        <>
            <div className="two-col">
                <ReviewCard
                    title="Authorization and provider allocation"
                    icon={Wallet}
                >
                    <ReviewRow
                        label="Original award"
                        value={`${knownNumber(f.awarded) ? f.awarded : 'Unknown'} ${f.unit} / ${f.period}`}
                    />
                    <ReviewRow
                        label="This provider"
                        value={`${knownNumber(f.allocated) ? f.allocated : 'Unknown'} ${f.unit} / ${f.period}`}
                    />
                    <ReviewRow
                        label="Valid period"
                        value={`${date(f.validFrom)} → ${date(f.validTo)}`}
                    />
                    <ReviewRow label="Service" value={f.service} />
                    <ReviewRow
                        label="Conditions"
                        value={f.conditions || 'Not recorded'}
                    />
                    <ReviewRow
                        label="Weekly equivalent basis"
                        value={
                            f.weeklyBasis ||
                            'Original authorization in NZD per week'
                        }
                    />
                </ReviewCard>
                <ReviewCard
                    title="Usable for the proposed start?"
                    icon={ShieldCheck}
                >
                    {issues.length ? (
                        issues.map((x) => (
                            <ReviewRow
                                key={x}
                                label={x}
                                value="Review required"
                            />
                        ))
                    ) : (
                        <Notice tone="success">
                            Valid verified contribution for{' '}
                            {date(r.proposal.start || TODAY)}. Line allocation
                            is reviewed with the proposal.
                        </Notice>
                    )}
                </ReviewCard>
            </div>
            {f.archivedChecks?.length > 0 && (
                <details>
                    <summary>
                        Requirements preserved from earlier versions (
                        {f.archivedChecks.length})
                    </summary>
                    {f.archivedChecks.map((c) => (
                        <ReviewRow
                            key={c.id}
                            label={c.label}
                            value={
                                (c.done
                                    ? 'Previously completed'
                                    : 'Not completed') +
                                (c.evidenceId
                                    ? ' · Evidence ' + c.evidenceId
                                    : '')
                            }
                        />
                    ))}
                </details>
            )}
            {f.decisions?.length > 0 && (
                <details>
                    <summary>
                        Preserved decision versions ({f.decisions.length})
                    </summary>
                    {f.decisions.map((d, i) => (
                        <ReviewCard
                            key={i}
                            title={`Decision version ${i + 1}`}
                            icon={Wallet}
                        >
                            <ReviewRow
                                label="Recorded / actor"
                                value={`${displayStamp(d.at)} · ${d.by || 'Recorded staff review'}`}
                            />
                            <ReviewRow
                                label="Outcome / amount"
                                value={`${d.decision} · ${money(d.amount)} per week`}
                            />
                            <ReviewRow
                                label="Validity"
                                value={`${date(d.validFrom)} → ${date(d.validTo)}`}
                            />
                            <ReviewRow
                                label="Conditions and evidence"
                                value={`${d.conditions || 'None recorded'} · ${d.evidence}`}
                            />
                            <ReviewRow
                                label="Verification"
                                value={
                                    d.verified
                                        ? 'Verified at this version'
                                        : 'Not verified'
                                }
                            />
                        </ReviewCard>
                    ))}
                </details>
            )}
        </>
    );
}
export function ReassessmentAction({ m, close }) {
    const { can, data, update, actor } = usePrototype(),
        r = m.r,
        f = m.funding;
    if (!can('funding')) return <Empty title="Funding permission required" />;
    return (
        <Wizard
            title="Open a linked reassessment"
            onClose={close}
            initial={{
                reason: '',
                next: 'Prepare reassessment information',
                due: businessDate(
                    data.config.reviewDays,
                    undefined,
                    data.config,
                ),
            }}
            steps={[
                {
                    label: 'Reason and next action',
                    notice: 'The earlier decision and its valid coverage stay intact. The new attempt starts unverified.',
                    fields: [
                        {
                            key: 'reason',
                            label: 'Why is a review requested?',
                            type: 'textarea',
                            required: true,
                        },
                        { key: 'next', label: 'Next action', required: true },
                        {
                            key: 'due',
                            label: 'Review follow-up date',
                            type: 'date',
                            required: true,
                        },
                    ],
                },
            ]}
            onSave={(v) => {
                const config = pinnedConfig(data, r);
                const fresh = {
                    ...makeFunding(
                        f.path,
                        config.version,
                        config.requirements[f.path],
                    ),
                    parentId: f.id,
                    navigator: f.navigator,
                    reviewReason: v.reason,
                    next: v.next,
                    due: v.due,
                };
                update(
                    r.id,
                    { funding: [...r.funding, fresh] },
                    'Opened a linked reassessment',
                );
                return {
                    message:
                        'New reassessment opened. The original decision, evidence and verification are unchanged.',
                };
            }}
        />
    );
}
export function ProposalAction({ m, close }) {
    const { can, data, update, actor, failure, setFailure } = usePrototype(),
        r = m.r,
        p = r.proposal;
    if (!can('edit') || !can('fundingRead'))
        return <Empty title="Proposal editing permission required" />;
    const newLine = () => ({
        id: uid('line'),
        service: r.service,
        unit: 'Hours',
        quantity: 1,
        rate: '',
        weekly: 0,
    });
    return (
        <Wizard
            title="Versioned support proposal"
            onClose={close}
            initial={{
                lines: p.lines?.length
                    ? p.lines.map((l) => ({
                          ...l,
                          rate: l.rate ?? l.weekly,
                          quantity: l.quantity || 1,
                      }))
                    : [newLine()],
                allocations: clone(p.allocations || []),
                payer: p.payer,
                start: p.start || TODAY,
                end: p.end || businessDate(260),
                note: '',
                acceptance: false,
                acceptanceEvidence: '',
                capacity: r.capacity,
            }}
            failure={failure}
            clearFailure={() => setFailure('Normal')}
            steps={[
                {
                    label: 'Support lines and dates',
                    fields: [
                        {
                            key: 'start',
                            label: 'Proposed service start',
                            type: 'date',
                            required: true,
                        },
                        {
                            key: 'end',
                            label: 'Proposal review / end date',
                            type: 'date',
                            required: true,
                        },
                        {
                            key: 'payer',
                            label: 'Primary payer / host',
                            required: true,
                        },
                        {
                            key: 'capacity',
                            label: 'Capacity confirmed',
                            type: 'check',
                        },
                    ],
                    render: (v, set) => (
                        <div className="stack">
                            {v.lines.map((l, i) => (
                                <ReviewCard
                                    key={l.id}
                                    icon={ClipboardList}
                                    title={`Support line ${i + 1}`}
                                >
                                    <div className="form-grid">
                                        {[
                                            {
                                                key: 'service',
                                                label: 'Service',
                                                type: 'select',
                                                options: data.config.services,
                                            },
                                            {
                                                key: 'unit',
                                                label: 'Unit',
                                                type: 'select',
                                                options: [
                                                    'Hours',
                                                    'Visits',
                                                    'Days',
                                                    'Weekly package',
                                                ],
                                            },
                                            {
                                                key: 'quantity',
                                                label: 'Units per week',
                                                type: 'number',
                                            },
                                            {
                                                key: 'rate',
                                                label: 'Proposed NZD price per unit',
                                                type: 'number',
                                            },
                                        ].map((field) => (
                                            <Field
                                                key={field.key}
                                                {...field}
                                                label={`${field.label} · line ${i + 1}`}
                                                value={l[field.key]}
                                                onChange={(x) =>
                                                    set(
                                                        'lines',
                                                        v.lines.map((a) =>
                                                            a.id === l.id
                                                                ? {
                                                                      ...a,
                                                                      [field.key]:
                                                                          x,
                                                                  }
                                                                : a,
                                                        ),
                                                    )
                                                }
                                            />
                                        ))}
                                    </div>
                                    <ReviewRow
                                        label="Estimated weekly line cost"
                                        value={
                                            knownNumber(l.rate)
                                                ? money(
                                                      Number(l.quantity) *
                                                          Number(l.rate),
                                                  )
                                                : 'Uncosted'
                                        }
                                    />
                                    <Button
                                        variant="ghost"
                                        disabled={v.lines.length === 1}
                                        onClick={() => {
                                            set(
                                                'lines',
                                                v.lines.filter(
                                                    (a) => a.id !== l.id,
                                                ),
                                            );
                                            set(
                                                'allocations',
                                                v.allocations.filter(
                                                    (a) => a.lineId !== l.id,
                                                ),
                                            );
                                        }}
                                    >
                                        Remove line
                                    </Button>
                                </ReviewCard>
                            ))}
                            <Button
                                variant="outline"
                                onClick={() =>
                                    set('lines', [...v.lines, newLine()])
                                }
                            >
                                Add support line
                            </Button>
                        </div>
                    ),
                    review: (v) => (
                        <ReviewRow
                            label="Service lines"
                            value={v.lines
                                .map(
                                    (l) =>
                                        `${l.service}: ${l.quantity} ${l.unit} × ${money(l.rate)} = ${money(Number(l.quantity) * Number(l.rate))}/week`,
                                )
                                .join(' · ')}
                        />
                    ),
                    validate: (v) =>
                        v.end < v.start
                            ? 'End date must follow start.'
                            : v.lines.some(
                                    (l) =>
                                        !knownNumber(l.rate) ||
                                        Number(l.rate) < 0 ||
                                        Number(l.quantity) <= 0,
                                )
                              ? 'Every line needs a positive quantity and a known non-negative price.'
                              : '',
                },
                {
                    label: 'Payer allocation',
                    notice: 'Allocate each funding arrangement to a service line. Unknown or unverified coverage can be planned, but cannot authorize service start.',
                    render: (v, set) => (
                        <div className="stack">
                            {v.allocations.map((a, i) => (
                                <ReviewCard
                                    key={a.id}
                                    icon={Wallet}
                                    title={`Allocation ${i + 1}`}
                                >
                                    <Field
                                        label={`Service line · allocation ${i + 1}`}
                                        type="select"
                                        options={v.lines.map((l, j) => ({
                                            value: l.id,
                                            label: `Line ${j + 1} · ${l.service}`,
                                        }))}
                                        value={a.lineId}
                                        onChange={(x) =>
                                            set(
                                                'allocations',
                                                v.allocations.map((z) =>
                                                    z.id === a.id
                                                        ? { ...z, lineId: x }
                                                        : z,
                                                ),
                                            )
                                        }
                                    />
                                    <Field
                                        label={`Funding arrangement · allocation ${i + 1}`}
                                        type="select"
                                        options={[
                                            {
                                                value: '',
                                                label: 'Choose arrangement',
                                            },
                                            ...r.funding
                                                .filter((f) => !f.income)
                                                .map((f) => ({
                                                    value: f.id,
                                                    label: `${f.path} · ${f.reference || 'unconfirmed'}`,
                                                })),
                                        ]}
                                        value={a.fundingId}
                                        onChange={(x) =>
                                            set(
                                                'allocations',
                                                v.allocations.map((z) =>
                                                    z.id === a.id
                                                        ? { ...z, fundingId: x }
                                                        : z,
                                                ),
                                            )
                                        }
                                    />
                                    <Field
                                        label={`Allocated NZD per week · allocation ${i + 1}`}
                                        type="number"
                                        value={a.weekly}
                                        onChange={(x) =>
                                            set(
                                                'allocations',
                                                v.allocations.map((z) =>
                                                    z.id === a.id
                                                        ? { ...z, weekly: x }
                                                        : z,
                                                ),
                                            )
                                        }
                                    />
                                    <Button
                                        variant="ghost"
                                        onClick={() =>
                                            set(
                                                'allocations',
                                                v.allocations.filter(
                                                    (z) => z.id !== a.id,
                                                ),
                                            )
                                        }
                                    >
                                        Remove allocation
                                    </Button>
                                </ReviewCard>
                            ))}
                            <Button
                                variant="outline"
                                onClick={() =>
                                    set('allocations', [
                                        ...v.allocations,
                                        {
                                            id: uid('allocation'),
                                            lineId: v.lines[0].id,
                                            fundingId: '',
                                            weekly: '',
                                        },
                                    ])
                                }
                            >
                                Add payer allocation
                            </Button>
                        </div>
                    ),
                    review: (v) => (
                        <ReviewRow
                            label="Planned allocations"
                            value={
                                v.allocations
                                    .map(
                                        (a) =>
                                            `${r.funding.find((f) => f.id === a.fundingId)?.path || 'Unconfirmed'} → ${v.lines.find((l) => l.id === a.lineId)?.service}: ${money(a.weekly)}`,
                                    )
                                    .join(' · ') || 'No allocations yet'
                            }
                        />
                    ),
                    validate: (v) =>
                        v.lines.some(
                            (l) =>
                                v.allocations
                                    .filter((a) => a.lineId === l.id)
                                    .reduce(
                                        (n, a) => n + Number(a.weekly || 0),
                                        0,
                                    ) >
                                Number(l.quantity) * Number(l.rate) + 0.005,
                        )
                            ? 'Planned allocations exceed a service line’s price.'
                            : v.allocations.some(
                                    (a) =>
                                        !a.fundingId ||
                                        !knownNumber(a.weekly) ||
                                        Number(a.weekly) < 0,
                                )
                              ? 'Each planned allocation needs a funding arrangement and non-negative amount.'
                              : '',
                },
                {
                    label: 'Agreement and evidence',
                    notice: 'An edited proposal is a new version. Internal approval, the service agreement and safe-start checks must be reviewed for the new version. Earlier acceptance never silently authorizes changed terms.',
                    fields: [
                        {
                            key: 'note',
                            label: 'Support rationale and price source / reference',
                            type: 'textarea',
                            required: true,
                        },
                        {
                            key: 'acceptance',
                            label: 'Person / authorized payer has reviewed and accepts this version',
                            type: 'check',
                        },
                        {
                            key: 'acceptanceEvidence',
                            label: 'Acceptance evidence and signatory',
                            type: 'textarea',
                            required: true,
                            when: (v) => v.acceptance,
                        },
                    ],
                },
            ]}
            onSave={(v) => {
                const lines = v.lines.map((l) => ({
                    ...l,
                    weekly: Number(l.quantity) * Number(l.rate),
                }));
                const proposal = {
                    ...p,
                    authoredBy: actor,
                    authoredAt: stamp(),
                    version: (p.version || 1) + 1,
                    lines,
                    weekly: lines.reduce((n, l) => n + l.weekly, 0),
                    allocations: v.allocations,
                    payer: v.payer,
                    start: v.start,
                    end: v.end,
                    note: v.note,
                    accepted: v.acceptance,
                    acceptanceEvidence: v.acceptanceEvidence,
                    acceptedAt: v.acceptance ? stamp() : null,
                    approved: false,
                    history: [
                        ...(p.history || []),
                        {
                            ...clone(p),
                            history: undefined,
                            at: stamp(),
                            by: actor,
                        },
                    ],
                };
                update(
                    r.id,
                    {
                        proposal,
                        capacity: v.capacity,
                        service: lines[0].service,
                        agreement: false,
                        safety: false,
                    },
                    'Proposal revised; approvals apply to the new version only',
                );
                return {
                    message: `Proposal v${proposal.version} saved. Internal approval must be recorded for this version.`,
                };
            }}
        />
    );
}
export function ProposalDetails({ r }) {
    const { can } = usePrototype();
    if (!can('fundingRead')) return null;
    return (
        <Section
            title="Support lines, allocations & versions"
            icon={ClipboardList}
        >
            {r.proposal.lines?.map((l, i) => (
                <ReviewCard
                    key={l.id}
                    icon={ClipboardList}
                    title={`Line ${i + 1} · ${l.service}`}
                >
                    <ReviewRow
                        label="Quantity / unit"
                        value={`${l.quantity} ${l.unit} per week`}
                    />
                    <ReviewRow
                        label="Weekly estimate"
                        value={money(l.weekly)}
                    />
                    {r.proposal.allocations
                        ?.filter((a) => a.lineId === l.id)
                        .map((a) => (
                            <ReviewRow
                                key={a.id}
                                label={
                                    r.funding.find((f) => f.id === a.fundingId)
                                        ?.path || 'Arrangement unavailable'
                                }
                                value={money(a.weekly)}
                            />
                        ))}
                </ReviewCard>
            ))}
            <ReviewRow
                label="Rationale and price source"
                value={r.proposal.note || 'To record'}
            />
            <ReviewRow
                label="Acceptance for this version"
                value={r.proposal.acceptanceEvidence || 'Not recorded'}
            />
            {allocationIssues(r).map((x) => (
                <Notice key={x}>{x}</Notice>
            ))}
            {r.proposal.history?.length > 0 && (
                <details>
                    <summary>
                        Previous proposals ({r.proposal.history.length})
                    </summary>
                    {r.proposal.history.map((p, i) => (
                        <ReviewCard
                            key={i}
                            icon={ClipboardList}
                            title={`Version ${p.version || i + 1}`}
                        >
                            <ReviewRow
                                label="Weekly total"
                                value={money(p.weekly)}
                            />
                            <ReviewRow
                                label="Rationale"
                                value={p.note || 'Legacy sample proposal'}
                            />
                            <ReviewRow
                                label="Approval / acceptance at that time"
                                value={`${p.approved ? 'Approved' : 'Not approved'} · ${p.accepted ? 'Accepted' : 'Not accepted'}`}
                            />
                        </ReviewCard>
                    ))}
                </details>
            )}
        </Section>
    );
}
