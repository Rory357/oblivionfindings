import { ClipboardList, ShieldCheck, Users } from 'lucide-react';
import { usePrototype } from './context';
import { date } from './data';
import { clone, displayStamp, stamp, uid } from './model';
import {
    Badge,
    Button,
    Empty,
    ReviewCard,
    ReviewRow,
    Section,
    Wizard,
} from './ui';
export function IntakeReview({ r }) {
    const { can, request } = usePrototype();
    const rows = [
        ['Entry', r.kind || r.source],
        [
            'Referrer',
            r.referrerRecord?.name ||
                r.referrer ||
                'Person enquiring for themselves',
        ],
        ['Organisation', r.referrerRecord?.organisation],
        ['Requested region', r.region || 'To clarify'],
        ['Nominated safe contact', r.safeContactPerson || r.name],
        ['Safe contact phone', r.safeContactPhone || r.phone],
        ['Safe contact email', r.safeContactEmail || r.email],
        ['Preferred channel', r.channel],
        ['Leave a message', r.leaveMessage || 'Ask before leaving a message'],
        ['Communication preferences', r.interpreter],
        [
            'Notice',
            r.notice
                ? `${r.notice.version} · ${displayStamp(r.notice.at)} · ${r.notice.method}`
                : 'Notice completion needs review',
        ],
    ];
    return (
        <Section
            title="Submitted intake information"
            icon={ClipboardList}
            caption="Submitted information is not automatically verified. Keep the referrer separate from the person."
            actions={
                can('edit') && (
                    <Button
                        variant="outline"
                        onClick={() => request('personEdit', r)}
                    >
                        Correct intake details
                    </Button>
                )
            }
        >
            <div className="two-col">
                <ReviewCard icon={Users} title="People & safe contact">
                    {rows
                        .filter(([, v]) => v)
                        .map(([label, value]) => (
                            <ReviewRow
                                key={label}
                                label={label}
                                value={value}
                            />
                        ))}
                </ReviewCard>
                <ReviewCard icon={ClipboardList} title="Support & funding">
                    {can('sensitive') ? (
                        <ReviewRow label="Support requested" value={r.needs} />
                    ) : (
                        <ReviewRow
                            label="General intake summary"
                            value={r.generalSummary}
                        />
                    )}
                    <ReviewRow label="Requested service" value={r.service} />
                    {can('fundingRead') ? (
                        <>
                            <ReviewRow
                                label="Funding situation at intake"
                                value={r.fundingSituation}
                            />
                            <ReviewRow
                                label="Existing agency / host arrangement"
                                value={r.funder || 'Not supplied'}
                            />
                        </>
                    ) : (
                        <ReviewRow
                            label="Funding details"
                            value="Restricted financial information"
                        />
                    )}
                    {(r.intakeAnswers || [])
                        .filter(
                            (a) =>
                                a.sensitivity === 'General intake' ||
                                can(
                                    [
                                        'Financial',
                                        'Funding information',
                                    ].includes(a.sensitivity)
                                        ? 'fundingRead'
                                        : 'sensitive',
                                ),
                        )
                        .map((a) => (
                            <ReviewRow
                                key={a.id}
                                label={a.label}
                                value={String(a.value || 'Not supplied')}
                            />
                        ))}
                </ReviewCard>
            </div>
            {can('sensitive') && r.assessmentReviews?.length > 0 && (
                <details>
                    <summary>
                        Assessment and safe-start reviews (
                        {r.assessmentReviews.length})
                    </summary>
                    {r.assessmentReviews.map((review, i) => (
                        <ReviewCard
                            key={i}
                            icon={ShieldCheck}
                            title={displayStamp(review.at)}
                        >
                            <ReviewRow label="Reviewed by" value={review.by} />
                            <ReviewRow
                                label="Review note / evidence"
                                value={review.note}
                            />
                            <ReviewRow
                                label="Confirmed at this review"
                                value={
                                    Object.entries(review.checks)
                                        .filter(([, done]) => done)
                                        .map(
                                            ([key]) =>
                                                ({
                                                    identity: 'Identity',
                                                    consent:
                                                        'Sharing authority',
                                                    choice: 'Provider choice',
                                                    suitability: 'Suitability',
                                                    capacity: 'Capacity',
                                                    agreement:
                                                        'Service agreement',
                                                    safety: 'Safe start',
                                                })[key],
                                        )
                                        .join(' · ') ||
                                    'Further review required'
                                }
                            />
                        </ReviewCard>
                    ))}
                </details>
            )}
            {r.corrections?.length > 0 && (
                <details>
                    <summary>
                        Correction history ({r.corrections.length})
                    </summary>
                    {r.corrections.map((c) => (
                        <p key={c.at} className="text-subtle">
                            {displayStamp(c.at)} · {c.by} · {c.reason}
                        </p>
                    ))}
                </details>
            )}
        </Section>
    );
}
export function ContactsPanel({ r }) {
    const { can, request } = usePrototype();
    const contacts = [
        ...(r.referrerRecord ? [{ ...r.referrerRecord, id: 'referrer' }] : []),
        ...r.contacts,
    ];
    return (
        <>
            <Section
                title="Contacts & participation"
                icon={Users}
                caption="Each person's details and authority are recorded separately."
                actions={
                    can('edit') && (
                        <Button onClick={() => request('personEdit', r)}>
                            Edit person & safe contact
                        </Button>
                    )
                }
            >
                <div className="two-col">
                    <ReviewCard icon={Users} title={r.name}>
                        <ReviewRow
                            label="Relationship"
                            value={
                                r.generalOnly
                                    ? 'General callback; person not identified'
                                    : 'Person seeking support'
                            }
                        />
                        <ReviewRow
                            label="Person’s phone"
                            value={r.phone || 'Not supplied'}
                        />
                        <ReviewRow
                            label="Person’s email"
                            value={r.email || 'Not supplied'}
                        />
                        <ReviewRow
                            label="Date of birth"
                            value={
                                can('sensitive') ? date(r.dob) : 'Restricted'
                            }
                        />
                    </ReviewCard>
                    <ReviewCard icon={ShieldCheck} title="Safe contact">
                        <ReviewRow
                            label="Contact this person"
                            value={r.safeContactPerson || r.name}
                        />
                        <ReviewRow label="Instructions" value={r.safe} />
                        <ReviewRow
                            label="Phone / email"
                            value={[
                                r.safeContactPhone || r.phone,
                                r.safeContactEmail || r.email,
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                        />
                        <ReviewRow
                            label="Leaving a message"
                            value={r.leaveMessage || 'Confirm first'}
                        />
                    </ReviewCard>
                    {contacts.map((c) => (
                        <ReviewCard key={c.id} icon={Users} title={c.name}>
                            <ReviewRow
                                label="Relationship / organisation"
                                value={[c.relationship, c.organisation]
                                    .filter(Boolean)
                                    .join(' · ')}
                            />
                            <ReviewRow
                                label="Contact"
                                value={
                                    c.contact ||
                                    [c.phone, c.email]
                                        .filter(Boolean)
                                        .join(' · ')
                                }
                            />
                            <ReviewRow
                                label="Authority"
                                value={c.authority || 'To clarify'}
                            />
                            <ReviewRow
                                label="Participation"
                                value={c.safe || 'Ask the person'}
                            />
                            {can('contact') && (
                                <Button
                                    variant="link"
                                    onClick={() =>
                                        request('contactEdit', r, {
                                            contact: c,
                                        })
                                    }
                                >
                                    Review contact <span aria-hidden>↗</span>
                                </Button>
                            )}
                        </ReviewCard>
                    ))}
                </div>
                {can('contact') && (
                    <Button
                        variant="outline"
                        onClick={() => request('contactEdit', r)}
                    >
                        Add contact
                    </Button>
                )}
            </Section>
            <Section
                title="Authority & privacy"
                icon={ShieldCheck}
                actions={
                    can('edit') && (
                        <Button
                            variant="outline"
                            onClick={() => request('authorityEdit', r)}
                        >
                            Review authority
                        </Button>
                    )
                }
            >
                <ReviewRow
                    label="Current state"
                    value={<Badge>{r.authorityState}</Badge>}
                />
                <ReviewRow label="Basis and scope" value={r.authority} />
                <ReviewRow
                    label="Contact permission"
                    value={
                        r.authorityScopes?.contact
                            ? 'Current'
                            : 'Not granted / withdrawn'
                    }
                />
                <ReviewRow
                    label="Disclosure permission"
                    value={
                        r.authorityScopes?.disclose
                            ? 'Current'
                            : 'Not granted / withdrawn'
                    }
                />
                <ReviewRow
                    label="Service permission"
                    value={
                        r.authorityScopes?.service
                            ? 'Current'
                            : 'Not granted / withdrawn'
                    }
                />
                <ReviewRow
                    label="Authority review date"
                    value={date(r.authorityReview)}
                />
                <ReviewRow
                    label="Third-party notice"
                    value={
                        r.thirdPartyNotice ||
                        'Review whether notice is required'
                    }
                />
            </Section>
        </>
    );
}
export function RecordAction({ m, close }) {
    const { can, update, actor, failure, setFailure } = usePrototype(),
        r = m.r;
    const permission = m.type === 'contactEdit' ? 'contact' : 'edit';
    if (!can(permission))
        return (
            <Empty title="Permission required">
                This action requires {permission} access.
            </Empty>
        );
    const shared = {
        onClose: close,
        failure,
        clearFailure: () => setFailure('Normal'),
    };
    if (m.type === 'contactEdit')
        return (
            <Wizard
                {...shared}
                title={m.contact ? 'Correct contact' : 'Add contact'}
                initial={
                    m.contact
                        ? {
                              ...m.contact,
                              phone: m.contact.phone || '',
                              email: m.contact.email || '',
                          }
                        : {
                              name: '',
                              relationship: 'Whānau / family',
                              authority: 'To clarify',
                              safe: '',
                          }
                }
                steps={[
                    {
                        label: 'Person & contact',
                        fields: [
                            {
                                key: 'name',
                                label: 'Contact name',
                                required: true,
                            },
                            {
                                key: 'relationship',
                                label: 'Relationship / organisation',
                                required: true,
                            },
                            {
                                key: 'contact',
                                label: 'Phone or email',
                                required: true,
                                when: () => m.contact?.id !== 'referrer',
                            },
                            {
                                key: 'phone',
                                label: 'Referrer phone',
                                when: () => m.contact?.id === 'referrer',
                            },
                            {
                                key: 'email',
                                label: 'Referrer email',
                                type: 'email',
                                when: () => m.contact?.id === 'referrer',
                            },
                            {
                                key: 'authority',
                                label: 'Authority basis and scope',
                                type: 'textarea',
                                required: true,
                            },
                            {
                                key: 'safe',
                                label: 'Participation and safe contact',
                                required: true,
                            },
                        ],
                    },
                    {
                        label: 'Correction record',
                        fields: [
                            {
                                key: 'reason',
                                label: 'Reason / source for these details',
                                type: 'textarea',
                                required: true,
                            },
                        ],
                    },
                ]}
                onSave={(v) => {
                    if (
                        m.contact?.id === 'referrer' &&
                        !v.phone?.trim() &&
                        !v.email?.trim()
                    )
                        return {
                            error: 'Keep at least one phone number or email for the referrer.',
                        };
                    const patch =
                        m.contact?.id === 'referrer'
                            ? {
                                  referrerRecord: {
                                      ...r.referrerRecord,
                                      ...v,
                                      phone: v.phone,
                                      email: v.email,
                                      contact: '',
                                  },
                              }
                            : {
                                  contacts: m.contact
                                      ? r.contacts.map((c) =>
                                            c.id === m.contact.id
                                                ? { ...c, ...v }
                                                : c,
                                        )
                                      : [
                                            ...r.contacts,
                                            { ...v, id: uid('contact') },
                                        ],
                              };
                    update(
                        r.id,
                        {
                            ...patch,
                            corrections: [
                                ...(r.corrections || []),
                                { at: stamp(), by: actor, reason: v.reason },
                            ],
                        },
                        'Contact details corrected',
                    );
                    return { message: 'Contact and correction history saved.' };
                }}
            />
        );
    if (m.type === 'authorityEdit')
        return (
            <Wizard
                {...shared}
                title="Review authority and preferences"
                initial={{
                    ...r,
                    contact: !!r.authorityScopes?.contact,
                    disclose: !!r.authorityScopes?.disclose,
                    service: !!r.authorityScopes?.service,
                    reason: '',
                }}
                steps={[
                    {
                        label: 'Authority and notice',
                        notice: 'A family relationship alone is not authority. Withdrawal blocks future disclosure and flags further review; it does not erase historic records.',
                        fields: [
                            {
                                key: 'authorityState',
                                label: 'Authority state',
                                type: 'select',
                                options: [
                                    'To clarify',
                                    'Current',
                                    'Withdrawn',
                                    'Disputed',
                                ],
                            },
                            {
                                key: 'authority',
                                label: 'Basis, representative and scope',
                                type: 'textarea',
                                required: true,
                            },
                            {
                                key: 'authorityReview',
                                label: 'Next authority review',
                                type: 'date',
                                required: true,
                            },
                            {
                                key: 'thirdPartyNotice',
                                label: 'Third-party collection notice',
                                type: 'select',
                                options: [
                                    'Notice given',
                                    'Not applicable — direct collection',
                                    'Exception reviewed by privacy lead',
                                    'Awaiting privacy review',
                                ],
                            },
                            {
                                key: 'contact',
                                label: 'Permission for future contact',
                                type: 'check',
                            },
                            {
                                key: 'disclose',
                                label: 'Permission for this disclosure purpose',
                                type: 'check',
                            },
                            {
                                key: 'service',
                                label: 'Consent for proposed service',
                                type: 'check',
                            },
                        ],
                    },
                    {
                        label: 'Reason and evidence',
                        fields: [
                            {
                                key: 'reason',
                                label: 'Evidence and reason for the change',
                                type: 'textarea',
                                required: true,
                            },
                        ],
                    },
                ]}
                onSave={(v) => {
                    const current = v.authorityState === 'Current';
                    update(
                        r.id,
                        {
                            authorityState: v.authorityState,
                            authority: v.authority,
                            authorityReview: v.authorityReview,
                            thirdPartyNotice: v.thirdPartyNotice,
                            authorityScopes: {
                                contact: current && v.contact,
                                disclose: current && v.disclose,
                                service: current && v.service,
                            },
                            consent: current && v.disclose,
                            agreement: current && v.service,
                            corrections: [
                                ...r.corrections,
                                { at: stamp(), by: actor, reason: v.reason },
                            ],
                        },
                        'Authority and communication preferences reviewed',
                    );
                    return {
                        message:
                            'Future contact and disclosure use the updated authority. Historical records are preserved.',
                    };
                }}
            />
        );
    return (
        <Wizard
            {...shared}
            title="Correct person & intake details"
            initial={{
                ...r,
                referrerName: r.referrerRecord?.name || '',
                referrerPhone: r.referrerRecord?.phone || '',
                referrerEmail: r.referrerRecord?.email || '',
                reason: '',
            }}
            steps={[
                {
                    label: 'Person and referrer',
                    notice: 'These person fields do not overwrite the referrer’s contact details.',
                    fields: [
                        {
                            key: 'name',
                            label: 'Person’s full name',
                            required: true,
                        },
                        {
                            key: 'generalOnly',
                            label: 'This remains a general callback without an identified person',
                            type: 'check',
                        },
                        { key: 'preferred', label: 'Preferred name' },
                        { key: 'phone', label: 'Person’s phone' },
                        {
                            key: 'email',
                            label: 'Person’s email',
                            type: 'email',
                        },
                        {
                            key: 'referrerName',
                            label: 'Referrer / caller name',
                        },
                        { key: 'referrerPhone', label: 'Referrer phone' },
                        {
                            key: 'referrerEmail',
                            label: 'Referrer email',
                            type: 'email',
                        },
                    ],
                },
                {
                    label: 'Safe contact and verification',
                    fields: [
                        {
                            key: 'safeContactPerson',
                            label: 'Nominated safe contact person',
                            required: true,
                        },
                        {
                            key: 'safeContactPhone',
                            label: 'Safe contact phone',
                        },
                        {
                            key: 'safeContactEmail',
                            label: 'Safe contact email',
                            type: 'email',
                        },
                        {
                            key: 'safe',
                            label: 'Safe contact instructions',
                            required: true,
                        },
                        {
                            key: 'leaveMessage',
                            label: 'Leaving a message',
                            type: 'select',
                            options: [
                                'Do not leave a message',
                                'A brief message is safe',
                            ],
                        },
                        {
                            key: 'generalSummary',
                            label: 'General intake summary — visible to reception',
                            type: 'textarea',
                            required: true,
                        },
                        {
                            key: 'reason',
                            label: 'Correction reason / verified source',
                            type: 'textarea',
                            required: true,
                        },
                    ],
                },
            ]}
            onSave={(v) => {
                const fields = [
                    'name',
                    'generalOnly',
                    'preferred',
                    'phone',
                    'email',
                    'safeContactPerson',
                    'safeContactPhone',
                    'safeContactEmail',
                    'safe',
                    'leaveMessage',
                    'generalSummary',
                ];
                update(
                    r.id,
                    {
                        ...Object.fromEntries(fields.map((k) => [k, v[k]])),
                        referrerRecord: v.referrerName
                            ? {
                                  ...r.referrerRecord,
                                  name: v.referrerName,
                                  phone: v.referrerPhone,
                                  email: v.referrerEmail,
                              }
                            : r.referrerRecord,
                        corrections: [
                            ...r.corrections,
                            {
                                at: stamp(),
                                by: actor,
                                reason: v.reason,
                                before: fields.map((k) => ({
                                    field: k,
                                    value: clone(r[k] ?? null),
                                })),
                                after: fields.map((k) => ({
                                    field: k,
                                    value: v[k],
                                })),
                            },
                        ],
                    },
                    'Person and intake details corrected',
                );
                return {
                    message:
                        'Corrections saved with their source. Existing supported-person information is not silently overwritten.',
                };
            }}
        />
    );
}
