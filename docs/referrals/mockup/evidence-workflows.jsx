import { FileText, Mail } from 'lucide-react';
import { usePrototype } from './context';
import { businessDate, displayStamp, stamp, today, uid } from './model';
import {
    Badge,
    Button,
    Empty,
    Modal,
    Notice,
    ReviewCard,
    ReviewRow,
    Section,
    Wizard,
} from './ui';
const mayRead = (can, d) =>
    d.audience === 'General intake' ||
    (d.audience === 'Funding information'
        ? can('fundingRead')
        : can('sensitive'));
export function EvidenceWorkspace({ r }) {
    const { can, request } = usePrototype();
    return (
        <>
            <Section
                title="Information requests"
                icon={Mail}
                caption="Fictional delivery and recipient response; no messages leave this prototype."
                actions={
                    can('disclose') && (
                        <Button onClick={() => request('infoRequest', r)}>
                            Request information
                        </Button>
                    )
                }
            >
                {(can('disclose') || can('documents')
                    ? r.infoRequests || []
                    : []
                ).map((q) => (
                    <ReviewCard key={q.id} icon={Mail} title={q.subject}>
                        <ReviewRow
                            label="Recipient / state"
                            value={`${q.recipient} · ${q.status} · expires ${q.expires}`}
                        />
                        <ReviewRow label="Purpose" value={q.purpose} />
                        <div className="actions">
                            {can('disclose') && (
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        request('infoRequest', r, {
                                            infoRequest: q,
                                        })
                                    }
                                >
                                    Review request
                                </Button>
                            )}
                            {can('documents') && q.status === 'Sent' && (
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        request('recipientResponse', r, {
                                            infoRequest: q,
                                        })
                                    }
                                >
                                    Open fictional recipient response
                                </Button>
                            )}
                        </div>
                    </ReviewCard>
                ))}
                {!can('disclose') &&
                    !can('documents') &&
                    r.infoRequests?.length > 0 && (
                        <p className="text-subtle">
                            Request details require information-sharing
                            permission.
                        </p>
                    )}
                {!r.infoRequests?.length && (
                    <p className="text-subtle">No information requests yet.</p>
                )}
            </Section>
            <Section
                title="Evidence & documents"
                icon={FileText}
                caption="Only synthetic document metadata and sample contents are stored locally."
                actions={
                    can('documents') && (
                        <Button onClick={() => request('evidenceEdit', r)}>
                            Add evidence
                        </Button>
                    )
                }
            >
                {r.documents
                    .filter((d) => mayRead(can, d))
                    .map((d) => (
                        <ReviewCard key={d.id} icon={FileText} title={d.name}>
                            <ReviewRow
                                label="Version / classification"
                                value={`v${d.version || 1} · ${d.classification || 'Evidence'} · ${d.audience || 'Restricted assessment'}`}
                            />
                            <ReviewRow
                                label="State"
                                value={
                                    <Badge>
                                        {d.expires && d.expires < today()
                                            ? 'Expired'
                                            : d.state}
                                    </Badge>
                                }
                            />
                            <ReviewRow
                                label="Source / review"
                                value={`${d.source || 'Staff'} · ${d.reviewedBy || 'Not reviewed'} · ${displayStamp(d.reviewedAt)}`}
                            />
                            <div className="actions">
                                <Button
                                    variant="outline"
                                    disabled={d.state === 'Quarantined'}
                                    onClick={() =>
                                        request('evidenceView', r, {
                                            document: d,
                                        })
                                    }
                                >
                                    View evidence
                                </Button>
                                {can('documents') && (
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            request('evidenceEdit', r, {
                                                document: d,
                                            })
                                        }
                                    >
                                        Review / replace
                                    </Button>
                                )}
                            </div>
                        </ReviewCard>
                    ))}
                {!r.documents.some((d) => mayRead(can, d)) && (
                    <Empty title="No evidence available to this role" />
                )}
            </Section>
        </>
    );
}
export function EvidenceAction({ m, close }) {
    const { data, can, update, actor, failure, setFailure } = usePrototype(),
        r = m.r,
        d = m.document,
        q = m.infoRequest;
    const shared = {
        onClose: close,
        failure,
        clearFailure: () => setFailure('Normal'),
    };
    if (m.type === 'evidenceView')
        return (
            <Modal title="Evidence preview" onClose={close}>
                {d && mayRead(can, d) && d.state !== 'Quarantined' ? (
                    <>
                        <Notice>
                            Fictional sample evidence. No original document is
                            connected.
                        </Notice>
                        <ReviewCard icon={FileText} title={d.name}>
                            <ReviewRow
                                label="Contents"
                                value={
                                    d.contents ||
                                    d.description ||
                                    'Sample decision letter / information supplied for this referral.'
                                }
                            />
                            <ReviewRow
                                label="Previous versions"
                                value={
                                    (d.versions || [])
                                        .map(
                                            (x) =>
                                                `v${x.version}: ${x.name} · ${x.state}`,
                                        )
                                        .join(' · ') || 'None'
                                }
                            />
                        </ReviewCard>
                    </>
                ) : (
                    <Empty title="Evidence unavailable" />
                )}
            </Modal>
        );
    if (m.type === 'infoRequest') {
        if (!can('disclose'))
            return (
                <Modal title="Disclosure permission required" onClose={close} />
            );
        const blocked =
            ['Withdrawn', 'Disputed'].includes(r.authorityState) ||
            !r.consent ||
            r.authorityScopes?.contact === false ||
            r.authorityScopes?.disclose === false;
        return (
            <Wizard
                {...shared}
                title="Information request"
                initial={
                    q
                        ? { ...q, action: 'Review' }
                        : {
                              subject: 'Information to support your referral',
                              recipient:
                                  r.safeContactPerson ||
                                  r.safeRecipientName ||
                                  r.name,
                              address:
                                  r.safeContactEmail ||
                                  r.safeContactPhone ||
                                  r.email ||
                                  r.phone,
                              channel: r.preferredContact || 'Phone',
                              purpose: '',
                              body: data.config.messageTemplate.replaceAll(
                                  '{name}',
                                  r.preferred || r.name,
                              ),
                              expires: businessDate(
                                  data.config.requestDays,
                                  undefined,
                                  data.config,
                              ),
                              action: 'Send',
                              safe: false,
                              scope: false,
                          }
                }
                steps={[
                    {
                        label: 'Recipient and authority',
                        notice: 'Safe contact instructions: ' + r.safe,
                        fields: [
                            {
                                key: 'recipient',
                                label: 'Safe recipient',
                                required: true,
                            },
                            {
                                key: 'address',
                                label: 'Confirmed contact address or number',
                                required: true,
                            },
                            {
                                key: 'channel',
                                label: 'Delivery channel',
                                type: 'select',
                                options: ['Phone', 'Email', 'SMS', 'In person'],
                            },
                            {
                                key: 'purpose',
                                label: 'Purpose and information requested',
                                type: 'textarea',
                                required: true,
                            },
                            {
                                key: 'safe',
                                label: 'Recipient and channel follow the safe contact instructions',
                                type: 'check',
                                required: true,
                            },
                            {
                                key: 'scope',
                                label: 'This request is within the recorded sharing authority',
                                type: 'check',
                                required: true,
                            },
                        ],
                        validate: () =>
                            blocked
                                ? 'Consent is missing, withdrawn or disputed. Review authority before disclosure.'
                                : '',
                    },
                    {
                        label: 'Message and expiry',
                        fields: [
                            {
                                key: 'requirement',
                                label: 'Preparation requirement to support (optional)',
                                type: 'select',
                                options: [
                                    {
                                        value: '',
                                        label: 'General information — no checklist link',
                                    },
                                    ...(can('fundingRead')
                                        ? r.funding.flatMap((f) =>
                                              f.checks.map((c) => ({
                                                  value: f.id + '|' + c.id,
                                                  label:
                                                      f.path + ' · ' + c.label,
                                              })),
                                          )
                                        : []),
                                ],
                            },
                            {
                                key: 'body',
                                label: 'Message preview',
                                type: 'textarea',
                                required: true,
                            },
                            {
                                key: 'expires',
                                label: 'Response link expiry',
                                type: 'date',
                                required: true,
                            },
                            {
                                key: 'action',
                                label: 'Request action',
                                type: 'select',
                                options: q
                                    ? [
                                          'Review',
                                          'Retry delivery',
                                          'Cancel request',
                                      ]
                                    : ['Send'],
                            },
                        ],
                        validate: (v) =>
                            v.action !== 'Cancel request' && v.expires < today()
                                ? 'Choose a current or future expiry.'
                                : '',
                    },
                ]}
                submitLabel="Save request preview"
                onSave={(v) => {
                    const id = q?.id || uid('request'),
                        state =
                            v.action === 'Cancel request'
                                ? 'Cancelled'
                                : v.action === 'Review'
                                  ? q.status
                                  : failure === 'Delivery failure'
                                    ? 'Failed'
                                    : 'Sent';
                    const changed = {
                        ...v,
                        id,
                        status: state,
                        at: stamp(),
                        by: actor,
                        history: [
                            ...(q?.history || []),
                            ...(q ? [{ at: q.at, status: q.status }] : []),
                        ],
                    };
                    update(
                        r.id,
                        {
                            infoRequests: q
                                ? r.infoRequests.map((x) =>
                                      x.id === id ? changed : x,
                                  )
                                : [...(r.infoRequests || []), changed],
                        },
                        'Information request ' + state.toLowerCase(),
                    );
                    if (failure === 'Delivery failure') setFailure('Normal');
                    return {
                        message:
                            state === 'Sent'
                                ? 'Fictional delivery recorded. Open Evidence → Open fictional recipient response to return information.'
                                : `Request ${state.toLowerCase()}. No real message was sent.`,
                    };
                }}
            />
        );
    }
    if (!can('documents'))
        return (
            <Modal
                title="Evidence management permission required"
                onClose={close}
            />
        );
    if (m.type === 'recipientResponse') {
        if (!q || q.status !== 'Sent' || q.expires < today())
            return (
                <Modal title="Response link unavailable" onClose={close}>
                    <Notice>
                        This request is expired, cancelled or already answered.
                        Review the request to issue a current one.
                    </Notice>
                </Modal>
            );
        return (
            <Wizard
                {...shared}
                title="Fictional recipient response"
                initial={{
                    name: 'Sample supporting information',
                    contents: '',
                    confirm: false,
                }}
                steps={[
                    {
                        label: 'Return requested information',
                        notice: q.purpose,
                        fields: [
                            {
                                key: 'name',
                                label: 'Document title',
                                required: true,
                            },
                            {
                                key: 'contents',
                                label: 'Information supplied (fictional)',
                                type: 'textarea',
                                required: true,
                            },
                            {
                                key: 'confirm',
                                label: 'I checked that this information is for the named request',
                                type: 'check',
                                required: true,
                            },
                        ],
                    },
                ]}
                submitLabel="Return information"
                onSave={(v) => {
                    update(
                        r.id,
                        {
                            documents: [
                                ...r.documents,
                                {
                                    id: uid('doc'),
                                    name: v.name,
                                    contents: v.contents,
                                    state: 'Available',
                                    audience: 'Restricted assessment',
                                    classification: 'Recipient response',
                                    requirement: q.requirement || '',
                                    version: 1,
                                    source: `Request ${q.id} · ${q.recipient}`,
                                    at: stamp(),
                                    versions: [],
                                },
                            ],
                            infoRequests: r.infoRequests.map((x) =>
                                x.id === q.id
                                    ? {
                                          ...x,
                                          status: 'Responded',
                                          respondedAt: stamp(),
                                      }
                                    : x,
                            ),
                        },
                        'Requested information returned for staff review',
                    );
                    return {
                        message:
                            'Returned to the referral Evidence queue as unreviewed information. Staff must review its purpose and classification before use.',
                    };
                }}
            />
        );
    }
    if (d && !mayRead(can, d))
        return <Modal title="Evidence unavailable" onClose={close} />;
    return (
        <Wizard
            {...shared}
            title={d ? 'Review or replace evidence' : 'Add evidence'}
            initial={
                d
                    ? { ...d, action: 'Review', reason: '' }
                    : {
                          name: '',
                          contents: '',
                          classification: 'Supporting information',
                          audience: 'Restricted assessment',
                          expires: '',
                          action: 'Add',
                          reason: '',
                      }
            }
            steps={[
                {
                    label: 'Document and visibility',
                    fields: [
                        {
                            key: 'name',
                            label: 'Document title',
                            required: true,
                        },
                        {
                            key: 'contents',
                            label: 'Fictional contents / summary',
                            type: 'textarea',
                            required: true,
                        },
                        {
                            key: 'classification',
                            label: 'Classification',
                            type: 'select',
                            options: [
                                'Supporting information',
                                'Identity',
                                'Consent / authority',
                                'Funding decision',
                                'Assessment',
                                'Recipient response',
                            ],
                        },
                        {
                            key: 'audience',
                            label: 'Who may read this?',
                            type: 'select',
                            options: [
                                'General intake',
                                ...(can('fundingRead')
                                    ? ['Funding information']
                                    : []),
                                ...(can('sensitive')
                                    ? ['Restricted assessment']
                                    : []),
                            ],
                        },
                        {
                            key: 'expires',
                            label: 'Expiry / review-by date (if applicable)',
                            type: 'date',
                        },
                    ],
                },
                {
                    label: 'Review outcome',
                    fields: [
                        {
                            key: 'requirement',
                            label: 'Preparation requirement to support (optional)',
                            type: 'select',
                            options: [
                                {
                                    value: '',
                                    label: 'General information — no checklist link',
                                },
                                ...(can('fundingRead')
                                    ? r.funding.flatMap((f) =>
                                          f.checks.map((c) => ({
                                              value: f.id + '|' + c.id,
                                              label: f.path + ' · ' + c.label,
                                          })),
                                      )
                                    : []),
                            ],
                        },
                        {
                            key: 'action',
                            label: 'Evidence action',
                            type: 'select',
                            options: d
                                ? [
                                      'Review',
                                      'Replace with new version',
                                      'Quarantine',
                                      'Mark superseded',
                                  ]
                                : ['Add'],
                        },
                        {
                            key: 'reason',
                            label: 'Source and review rationale',
                            type: 'textarea',
                            required: true,
                        },
                    ],
                    validate: (v) =>
                        v.action === 'Review' && d?.state === 'Quarantined'
                            ? 'Quarantined evidence cannot be marked reviewed. Replace it with a safe sample.'
                            : v.action === 'Review' &&
                                v.expires &&
                                v.expires < today()
                              ? 'Expired evidence cannot be marked current. Replace with a current version.'
                              : '',
                },
            ]}
            onSave={(v) => {
                const replace = v.action === 'Replace with new version',
                    changed = {
                        ...v,
                        id: d?.id || uid('doc'),
                        version: replace
                            ? (d.version || 1) + 1
                            : d?.version || 1,
                        state:
                            v.action === 'Review'
                                ? 'Reviewed'
                                : v.action === 'Quarantine'
                                  ? 'Quarantined'
                                  : v.action === 'Mark superseded'
                                    ? 'Superseded'
                                    : 'Available',
                        source: d?.source || actor,
                        at: stamp(),
                        reviewedAt: v.action === 'Review' ? stamp() : null,
                        reviewedBy: v.action === 'Review' ? actor : null,
                        versions: [
                            ...(d?.versions || []),
                            ...(replace ? [{ ...d, versions: undefined }] : []),
                        ],
                    };
                update(
                    r.id,
                    {
                        funding: r.funding.map((f) => ({
                            ...f,
                            checks: f.checks.map((c) =>
                                v.requirement === f.id + '|' + c.id
                                    ? {
                                          ...c,
                                          done: v.action === 'Review',
                                          evidenceId: changed.id,
                                          evidenceVersion: changed.version,
                                          reviewedAt: stamp(),
                                          reviewedBy: actor,
                                      }
                                    : c,
                            ),
                        })),
                        documents: d
                            ? r.documents.map((x) =>
                                  x.id === d.id ? changed : x,
                              )
                            : [...r.documents, changed],
                    },
                    'Evidence metadata and review state updated',
                );
                return {
                    message:
                        'Evidence saved with its classification, version and review state.',
                };
            }}
        />
    );
}
