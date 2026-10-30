import { ClipboardCheck, Link, UserRound } from 'lucide-react';
import { usePrototype } from './context';
import { conversionBlocks, coverage, date } from './data';
import {
    actorFor,
    businessDate,
    handoverFields,
    knownNumber,
    pinnedConfig,
    stageIssue,
    stamp,
    uid,
} from './model';
import {
    Button,
    Modal,
    Notice,
    ReviewCard,
    ReviewRow,
    Section,
    Wizard,
} from './ui';

export function LifecycleAction({ m, close }) {
    const { data, can, role, update, failure, setFailure } = usePrototype(),
        r = m.r,
        c = pinnedConfig(data, r),
        closed = r.stage === 'Closed';
    if (r.stage === 'Converted')
        return (
            <Modal title="Handover completed" onClose={close}>
                <Notice>
                    This referral stays linked to {r.personId}. Continue
                    funding, appointments and onboarding from its existing
                    record.
                </Notice>
            </Modal>
        );
    if (!can(closed ? 'reopen' : 'transition'))
        return <Modal title="Lifecycle permission required" onClose={close} />;
    return (
        <Wizard
            title={closed ? 'Reopen referral' : 'Update referral stage'}
            onClose={close}
            failure={failure}
            clearFailure={() => setFailure('Normal')}
            initial={{
                stage: closed ? 'Assessment' : r.stage,
                reason: '',
                next: r.next,
                due: businessDate(c.reviewDays, undefined, c),
                category: c.closureReasons[0],
                holdCategory: r.holdReason || c.holdReasons[0],
            }}
            steps={[
                {
                    label: 'Stage and reason',
                    fields: [
                        {
                            key: 'stage',
                            label: 'Stage',
                            type: 'select',
                            options: closed
                                ? c.stages.filter(
                                      (x) =>
                                          !['Converted', 'Closed'].includes(x),
                                  )
                                : c.stages.filter((x) => x !== 'Converted'),
                        },
                        {
                            key: 'category',
                            label: 'Closure reason',
                            type: 'select',
                            options: c.closureReasons,
                            when: (v) => v.stage === 'Closed',
                        },
                        {
                            key: 'holdCategory',
                            label: 'Hold reason',
                            required: true,
                            type: 'select',
                            options: c.holdReasons,
                            when: (v) => v.stage === 'On hold',
                        },
                        {
                            key: 'reason',
                            label: 'Reason for this change',
                            type: 'textarea',
                            required: true,
                        },
                    ],
                    validate: (v) =>
                        v.stage === 'Closed' && !can('close')
                            ? 'Close referrals permission is required.'
                            : stageIssue(r, v.stage, c, role, {
                                  reopening: closed,
                              }),
                },
                {
                    label: 'Accountable next step',
                    fields: [
                        {
                            key: 'next',
                            label: 'Next action',
                            required: true,
                            when: (v) => v.stage !== 'Closed',
                        },
                        {
                            key: 'due',
                            label: 'Next action or hold review date',
                            type: 'date',
                            required: true,
                            when: (v) => v.stage !== 'Closed',
                        },
                    ],
                },
            ]}
            onSave={(v) => {
                update(
                    r.id,
                    {
                        stage: v.stage,
                        stageAt: stamp(),
                        next: v.stage === 'Closed' ? r.next : v.next,
                        due: v.stage === 'Closed' ? r.due : v.due,
                        nextDone: v.stage === 'Closed',
                        holdReason:
                            v.stage === 'On hold' ? v.holdCategory : null,
                        closureHistory: [
                            ...(r.closureHistory || []),
                            {
                                at: stamp(),
                                by: actorFor(data, role),
                                from: r.stage,
                                to: v.stage,
                                reason: v.reason,
                                category:
                                    v.stage === 'On hold'
                                        ? v.holdCategory
                                        : v.stage === 'Closed'
                                          ? v.category
                                          : null,
                            },
                        ],
                        ...(closed ? { reopenedAt: stamp() } : {}),
                    },
                    `${closed ? 'Reopened' : 'Stage updated'}: ${v.stage} · ${v.reason}`,
                );
                return {
                    message:
                        'Stage and dated accountability saved. Previous closure history is retained.',
                };
            }}
        />
    );
}

export function HandoverAction({ m, close }) {
    const {
            data,
            setData,
            can,
            role,
            visiblePeople,
            audit,
            request,
            failure,
            setFailure,
        } = usePrototype(),
        r = m.r,
        c = pinnedConfig(data, r),
        actor = actorFor(data, role);
    if (!can('convert'))
        return <Modal title="Handover permission required" onClose={close} />;
    if (r.personId)
        return (
            <Modal title="Handover already completed" onClose={close}>
                <Notice>
                    Linked to {r.personId}. No second person will be created.
                </Notice>
            </Modal>
        );
    const blocks = conversionBlocks(r),
        pending =
            !knownNumber(r.proposal.weekly) ||
            coverage(r) < Number(r.proposal.weekly);
    const restricted =
        !!r.existingPerson &&
        !visiblePeople.some((p) => p.id === r.existingPerson);
    const transfer = (v) => (
        <ReviewCard icon={ClipboardCheck} title="Information transfer review">
            {[
                [
                    'Verified identity',
                    [r.name, r.preferred, r.dob].filter(Boolean).join(' · '),
                    'Person profile',
                ],
                [
                    'Person phone / email',
                    [r.phone, r.email].filter(Boolean).join(' · ') ||
                        'Not supplied',
                    'Person contacts',
                ],
                [
                    'Safe recipient',
                    `${r.safeContactPerson || r.safeRecipientName || r.name} · ${r.safeContactPhone || r.safeContactEmail || 'Contact not supplied'}`,
                    'Safe contact instructions',
                ],
                ['Contact restrictions', r.safe, 'Safe contact instructions'],
                [
                    'Authority / privacy',
                    `${r.authorityState} · ${r.noticeVersion}`,
                    'Sharing record',
                ],
                [
                    'Requested support',
                    r.service,
                    'Proposed support — awaiting start approval',
                ],
                [
                    'General goals',
                    handoverFields(r).goals || 'Not supplied',
                    'Intake goals',
                ],
                [
                    'Communication preferences',
                    [
                        r.preferredContact || r.channel,
                        r.leaveMessage,
                        r.interpreter,
                    ]
                        .filter(Boolean)
                        .join(' · ') || 'To confirm',
                    'Communication and safe contact',
                ],
                [
                    'Assessment information',
                    can('sensitive')
                        ? r.needs
                        : 'Restricted; retained under source access rules',
                    'Restricted assessment reference',
                ],
                [
                    'Evidence',
                    `${r.documents.length} source references; access checks retained`,
                    'Referral evidence references',
                ],
                [
                    'Funding and follow-ups',
                    'Continue on the linked referral',
                    'Onboarding work list',
                ],
                [
                    'Existing person handling',
                    v.identityMode || 'Preserve existing verified fields',
                    'Canonical record',
                ],
            ].map(([label, value, destination]) => (
                <ReviewRow
                    key={label}
                    label={`${label} → ${destination}`}
                    value={value || 'Not recorded'}
                />
            ))}
        </ReviewCard>
    );
    if (restricted)
        return (
            <Modal
                title="Possible match needs an authorised review"
                onClose={close}
            >
                <Notice>
                    Details of this possible match are restricted. Request an
                    identity review; the receiving reviewer can resolve it
                    without disclosing the other record.
                </Notice>
                <Button onClick={() => request('matchReview', r)}>
                    Request identity review
                </Button>
            </Modal>
        );
    return (
        <Wizard
            title="Convert to supported person"
            onClose={close}
            failure={failure}
            clearFailure={() => setFailure('Normal')}
            initial={{
                target: r.existingPerson || 'new',
                identityMode: 'Preserve existing verified fields',
                sameNameReason: '',
                matchChecked: false,
                ack: false,
                pending: false,
            }}
            steps={[
                {
                    label: 'Identity and destination',
                    fields: [
                        {
                            key: 'target',
                            label: 'Create or link',
                            type: 'select',
                            options: [
                                {
                                    value: 'new',
                                    label: 'Create new onboarding record',
                                },
                                ...visiblePeople.map((p) => ({
                                    value: p.id,
                                    label: `Link ${p.name} (${p.id})`,
                                })),
                            ],
                        },
                        {
                            key: 'matchChecked',
                            label: 'I checked identity and possible duplicates',
                            type: 'check',
                            required: true,
                        },
                        {
                            key: 'sameNameReason',
                            label: 'Identity review explanation',
                            type: 'textarea',
                            required: true,
                        },
                        {
                            key: 'identityMode',
                            label: 'Existing identity and contact fields',
                            type: 'select',
                            options: [
                                'Preserve existing verified fields',
                                'Update with reviewed referral fields',
                            ],
                            when: (v) => v.target !== 'new',
                        },
                    ],
                    validate: (v) =>
                        blocks.length
                            ? 'Required: ' + blocks.join('; ')
                            : v.target !== 'new' &&
                                !visiblePeople.some((p) => p.id === v.target)
                              ? 'Choose an accessible person.'
                              : v.target === 'new' && r.existingPerson
                                ? 'Resolve the known match before creating a different person.'
                                : '',
                },
                {
                    label: 'Transfer and ongoing work',
                    render: transfer,
                    review: transfer,
                    fields: [
                        {
                            key: 'ack',
                            label: 'I reviewed the source values and their destinations',
                            type: 'check',
                            required: true,
                        },
                        {
                            key: 'pending',
                            label: 'Acknowledge pending funding — onboarding only',
                            type: 'check',
                            required: true,
                            when: () => pending,
                        },
                    ],
                    validate: () =>
                        pending && !c.allowPending
                            ? 'This case’s published policy requires verified funding before handover.'
                            : '',
                },
            ]}
            submitLabel="Confirm handover"
            onSave={(v) => {
                const id = v.target === 'new' ? uid('SP') : v.target,
                    at = stamp();
                const fields = handoverFields(r);
                const proposed = {
                    referralId: r.id,
                    service: r.service,
                    proposalVersion: r.proposal.version,
                    status: 'Proposed — start approval required',
                };
                const receipt = {
                    id: uid('handover'),
                    at,
                    by: actor,
                    personId: id,
                    referralId: r.id,
                    identityMode: v.identityMode,
                    identityReview: v.sameNameReason,
                    fields,
                    template: c.onboardingTemplate,
                    fundingCaseIds: r.funding.map((f) => f.id),
                };
                setData((s) =>
                    audit(
                        {
                            ...s,
                            people:
                                v.target === 'new'
                                    ? [
                                          ...s.people,
                                          {
                                              id,
                                              ...fields,
                                              status: 'Onboarding',
                                              site: r.site,
                                              services: [],
                                              proposedServices: [proposed],
                                              handoverReceipts: [receipt],
                                          },
                                      ]
                                    : s.people.map((p) =>
                                          p.id === id
                                              ? {
                                                    ...p,
                                                    ...(v.identityMode ===
                                                    'Update with reviewed referral fields'
                                                        ? fields
                                                        : {}),
                                                    proposedServices: [
                                                        ...(p.proposedServices ||
                                                            []),
                                                        proposed,
                                                    ],
                                                    handoverReceipts: [
                                                        ...(p.handoverReceipts ||
                                                            []),
                                                        receipt,
                                                    ],
                                                }
                                              : p,
                                      ),
                            records: s.records.map((x) =>
                                x.id === r.id
                                    ? {
                                          ...x,
                                          stage: 'Converted',
                                          stageAt: at,
                                          personId: id,
                                          handoverReceipt: receipt,
                                          activity: [
                                              {
                                                  at,
                                                  by: actor,
                                                  text: `Handover completed to ${id}; proposed support awaits service-start approval`,
                                              },
                                              ...x.activity,
                                          ],
                                      }
                                    : x,
                            ),
                        },
                        `Handover completed for ${r.id}`,
                    ),
                );
                return {
                    message: `Linked to ${id}. Inspect the destination in Supported people → Open handover. Funding work continues on this referral; support remains proposed until approved.`,
                };
            }}
        />
    );
}

export function MatchReviewAction({ m, close }) {
    const { data, setData, can, actor, audit } = usePrototype(),
        r = m.r;
    const existing = data.reviewRequests.find(
        (q) => q.referralId === r.id && q.status === 'Pending',
    );
    return (
        <Wizard
            title="Identity review request"
            onClose={close}
            initial={{ reason: '', resolution: 'Confirm existing match' }}
            steps={[
                {
                    label: 'Authorised identity review',
                    notice: existing
                        ? `Request ${existing.id} is ${existing.status}.`
                        : 'Only the reference and reason are sent to the identity-review queue.',
                    fields: [
                        {
                            key: 'reason',
                            label: 'Review reason / evidence checked',
                            type: 'textarea',
                            required: true,
                        },
                        {
                            key: 'resolution',
                            label: 'Review outcome',
                            type: 'select',
                            options: [
                                'Confirm existing match',
                                'Different person — allow new record',
                            ],
                            when: () => can('restricted') && !!existing,
                        },
                    ],
                },
            ]}
            onSave={(v) => {
                const resolving =
                    can('duplicates') && can('restricted') && existing;
                setData((s) =>
                    audit(
                        {
                            ...s,
                            reviewRequests: resolving
                                ? s.reviewRequests.map((q) =>
                                      q.id === existing.id
                                          ? {
                                                ...q,
                                                status: 'Resolved',
                                                resolution: v.resolution,
                                                reviewedBy: actor,
                                                reviewedAt: stamp(),
                                                note: v.reason,
                                            }
                                          : q,
                                  )
                                : [
                                      ...s.reviewRequests,
                                      {
                                          id: uid('match'),
                                          referralId: r.id,
                                          status: 'Pending',
                                          at: stamp(),
                                          by: actor,
                                          reason: v.reason,
                                      },
                                  ],
                            records: resolving
                                ? s.records.map((x) =>
                                      x.id === r.id
                                          ? {
                                                ...x,
                                                ...(v.resolution.startsWith(
                                                    'Different',
                                                )
                                                    ? {
                                                          existingPerson: null,
                                                          duplicate: false,
                                                      }
                                                    : {}),
                                                identityReview: {
                                                    at: stamp(),
                                                    by: actor,
                                                    note: v.reason,
                                                    outcome: v.resolution,
                                                },
                                            }
                                          : x,
                                  )
                                : s.records,
                        },
                        resolving
                            ? 'Identity review resolved'
                            : 'Identity review requested',
                    ),
                );
                return {
                    message: resolving
                        ? 'Identity review saved. Reopen handover to continue.'
                        : 'Request recorded. A referral manager can open this referral and resolve it from Review identity request.',
                };
            }}
        />
    );
}

export function HandoverReceipt({ r }) {
    const { can, request } = usePrototype();
    return (
        <Section title="Transfer receipt & identity review" icon={Link}>
            {r.handoverReceipt ? (
                <ReviewCard icon={UserRound} title={r.handoverReceipt.personId}>
                    <ReviewRow
                        label="Onboarding template"
                        value={r.handoverReceipt.template}
                    />
                    <details>
                        <summary>View transferred information</summary>
                        {[
                            ['name', 'Full name'],
                            ['preferred', 'Preferred name'],
                            ['dob', 'Date of birth'],
                            ['phone', 'Person phone'],
                            ['email', 'Person email'],
                            ['safeRecipientName', 'Safe contact person'],
                            ['safeContactPhone', 'Safe contact phone'],
                            ['safeContactEmail', 'Safe contact email'],
                            ['safe', 'Safe contact instructions'],
                            ['preferredContact', 'Preferred channel'],
                            ['leaveMessage', 'Leaving a message'],
                            ['interpreter', 'Communication support'],
                            ['goals', 'Person’s goals'],
                            ['assessmentRef', 'Assessment source'],
                        ]
                            .filter(([key]) => r.handoverReceipt.fields[key])
                            .map(([key, label]) => (
                                <ReviewRow
                                    key={key}
                                    label={label}
                                    value={
                                        key === 'dob'
                                            ? date(
                                                  r.handoverReceipt.fields[key],
                                              )
                                            : r.handoverReceipt.fields[key]
                                    }
                                />
                            ))}
                    </details>
                    <ReviewRow
                        label="Ongoing work"
                        value={`${r.funding.length} funding cases remain linked to ${r.id}`}
                    />
                </ReviewCard>
            ) : (
                <p className="text-subtle">
                    Review identity and transferred fields before handover.
                </p>
            )}
            {can('duplicates') && (
                <Button
                    variant="outline"
                    onClick={() => request('matchReview', r)}
                >
                    Review identity request
                </Button>
            )}
        </Section>
    );
}
