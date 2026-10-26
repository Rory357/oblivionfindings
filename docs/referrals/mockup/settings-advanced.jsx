import { FileText, History, Settings2, Workflow } from 'lucide-react';
import { useState } from 'react';
import { usePrototype } from './context';
import { pathways } from './data';
import {
    businessDate,
    clone,
    displayStamp,
    routeIntake,
    staffDirectory,
    uid,
} from './model';
import {
    Button,
    Field,
    Modal,
    Notice,
    ReviewCard,
    ReviewRow,
    Wizard,
} from './ui';

export function AdvancedSettings({ area, draft, edit }) {
    const { data } = usePrototype();
    const [dialog, setDialog] = useState(null),
        [simulation, setSimulation] = useState({
            region: draft.regions[0],
            service: draft.services[0],
            path: 'Not sure',
        });
    const collections = {
        forms: {
            title: 'Public form fields',
            description:
                'Stable field IDs preserve old answers when labels change. Conditions and required fields apply to website intake.',
            fields: [
                { key: 'label', label: 'Question label', required: true },
                {
                    key: 'type',
                    label: 'Field type',
                    type: 'select',
                    options: ['text', 'textarea', 'select'],
                },
                {
                    key: 'options',
                    label: 'Choice options (separate with |)',
                    when: (v) => v.type === 'select',
                    required: true,
                },
                { key: 'required', label: 'Answer required', type: 'check' },
                {
                    key: 'condition',
                    label: 'Show when',
                    type: 'select',
                    options: [
                        'Always',
                        'Professional referrals',
                        'Funding help requested',
                    ],
                },
                {
                    key: 'sensitivity',
                    label: 'Visibility',
                    type: 'select',
                    options: [
                        'General intake',
                        'Restricted assessment',
                        'Funding information',
                    ],
                },
                {
                    key: 'destination',
                    label: 'Destination review group',
                    type: 'select',
                    options: ['Goals', 'Contacts', 'Funding', 'Assessment'],
                },
            ],
        },
        routingRules: {
            title: 'Ordered routing rules',
            description:
                'First matching rule wins. Unavailable staff fall back to central intake. Move rules up to change priority.',
            fields: [
                {
                    key: 'region',
                    label: 'Requested region',
                    type: 'select',
                    options: ['Any region', ...draft.regions],
                },
                {
                    key: 'service',
                    label: 'Requested service',
                    type: 'select',
                    options: ['Any service', ...draft.services],
                },
                {
                    key: 'pathway',
                    label: 'Funding pathway',
                    type: 'select',
                    options: ['Any pathway', ...pathways],
                },
                {
                    key: 'site',
                    label: 'Receiving site',
                    type: 'select',
                    options: draft.regions,
                },
                {
                    key: 'owner',
                    label: 'Accountable owner',
                    type: 'search',
                    options: (draft.staffDirectory || staffDirectory).map(
                        (x) => x.name,
                    ),
                },
                { key: 'team', label: 'Receiving team', required: true },
            ],
        },
        stageRules: {
            title: 'Stage entry requirements',
            description:
                'Applies to the case’s published workflow version. Closed and converted safeguards always apply.',
            fields: [
                {
                    key: 'stage',
                    label: 'Destination stage',
                    type: 'select',
                    options: draft.stages.filter(
                        (s) => !['Closed', 'Converted'].includes(s),
                    ),
                },
                {
                    key: 'requires',
                    label: 'Required before entry',
                    type: 'select',
                    options: [
                        'Identity and consent',
                        'Verified funding',
                        'Named owner',
                    ],
                },
                {
                    key: 'role',
                    label: 'Reviewer role',
                    type: 'select',
                    options: Object.keys(data.roles).filter(
                        (r) => data.roles[r].view,
                    ),
                },
            ],
        },
    };
    const keys =
        area === 'Intake'
            ? ['forms', 'routingRules']
            : area === 'Workflow'
              ? ['stageRules']
              : [];
    const group = (key) => {
        const spec = collections[key];
        return (
            <ReviewCard key={key} icon={Workflow} title={spec.title}>
                <p className="text-subtle">{spec.description}</p>
                {draft[key].map((row, i) => (
                    <div key={row.id} className="work-row">
                        <div className="grow">
                            <strong>
                                {row.label ||
                                    row.stage ||
                                    `${i + 1}. ${row.region} → ${row.owner}`}
                            </strong>
                            <p className="text-caption">
                                {row.id} ·{' '}
                                {row.sensitivity || row.requires || row.service}
                            </p>
                        </div>
                        <Button
                            variant="outline"
                            onClick={() => setDialog({ key, row })}
                        >
                            Configure
                        </Button>
                        {key === 'routingRules' && i > 0 && (
                            <Button
                                variant="ghost"
                                onClick={() => {
                                    const list = [...draft[key]];
                                    [list[i - 1], list[i]] = [
                                        list[i],
                                        list[i - 1],
                                    ];
                                    edit(key, list);
                                }}
                            >
                                Move up
                            </Button>
                        )}
                    </div>
                ))}
                <Button
                    variant="link"
                    onClick={() => setDialog({ key, row: null })}
                >
                    Add {key === 'forms' ? 'question' : 'rule'}
                </Button>
            </ReviewCard>
        );
    };
    return (
        <div className="stack">
            {keys.map(group)}
            {area === 'Intake' && (
                <ReviewCard
                    icon={Settings2}
                    title="Website coverage & routing preview"
                >
                    <ReviewRow
                        label="Services / regions"
                        value={`${draft.services.join(', ')} · ${draft.regions.join(', ')}`}
                    />
                    <ReviewRow label="Contact hours" value={draft.hours} />
                    <Button
                        variant="link"
                        onClick={() => setDialog({ key: 'coverage' })}
                    >
                        Configure services and intake availability
                    </Button>
                    <details className="note">
                        <summary>Test routing before publication</summary>
                        <div className="form-grid">
                            <Field
                                label="Example region"
                                type="select"
                                options={draft.regions}
                                value={simulation.region}
                                onChange={(v) =>
                                    setSimulation({ ...simulation, region: v })
                                }
                            />
                            <Field
                                label="Example service"
                                type="select"
                                options={draft.services}
                                value={simulation.service}
                                onChange={(v) =>
                                    setSimulation({ ...simulation, service: v })
                                }
                            />
                            <Field
                                label="Example pathway"
                                type="select"
                                options={['Not sure', ...pathways]}
                                value={simulation.path}
                                onChange={(v) =>
                                    setSimulation({ ...simulation, path: v })
                                }
                            />
                        </div>
                        <Notice>
                            {routeIntake(simulation, draft).explanation} First
                            contact:{' '}
                            {businessDate(draft.firstContact, undefined, draft)}
                            . Waiting-case review:{' '}
                            {businessDate(draft.reviewDays, undefined, draft)}.
                            Next action: confirm safe contact, authority and
                            requested funding help.
                        </Notice>
                    </details>
                </ReviewCard>
            )}
            {area === 'Workflow' && (
                <ReviewCard
                    icon={Workflow}
                    title="Review targets, reasons & approval delegation"
                >
                    <ReviewRow
                        label="Approval delegation"
                        value={`Up to NZD ${draft.approvalLimit} weekly; separate reviewer ${draft.separateReviewer ? 'On' : 'Off'}`}
                    />
                    <ReviewRow
                        label="Closure / hold reasons"
                        value={`${draft.closureReasons.length} closure choices · ${draft.holdReasons.length} hold choices`}
                    />
                    <Button
                        variant="link"
                        onClick={() => setDialog({ key: 'operations' })}
                    >
                        Configure operational safeguards
                    </Button>
                </ReviewCard>
            )}
            {area === 'Funding' && (
                <ReviewCard
                    icon={FileText}
                    title="Pathway guidance & review ownership"
                >
                    <p className="text-subtle">
                        Local guidance supports staff; it does not decide
                        eligibility or guarantee funding.
                    </p>
                    {pathways.map((path) => (
                        <div className="work-row" key={path}>
                            <div className="grow">
                                <strong>{path}</strong>
                                <p className="text-caption">
                                    {draft.pathwayDetails[path]?.owner ||
                                        'Guidance owner not set'}
                                </p>
                            </div>
                            <Button
                                variant="outline"
                                onClick={() =>
                                    setDialog({ key: 'pathway', path })
                                }
                            >
                                Configure guidance
                            </Button>
                        </div>
                    ))}
                </ReviewCard>
            )}
            {dialog &&
                (() => {
                    const key = dialog.key,
                        spec = collections[key];
                    let initial, fields;
                    if (spec) {
                        initial =
                            dialog.row ||
                            Object.fromEntries(
                                spec.fields.map((f) => [
                                    f.key,
                                    f.type === 'check'
                                        ? false
                                        : f.options?.[0] || '',
                                ]),
                            );
                        fields = [
                            ...spec.fields,
                            ...(dialog.row
                                ? [
                                      {
                                          key: 'retire',
                                          label: 'Retire this question or rule in the next version',
                                          type: 'check',
                                      },
                                  ]
                                : []),
                        ];
                    } else if (key === 'coverage') {
                        initial = {
                            services: draft.services.join('\n'),
                            regions: draft.regions.join('\n'),
                            hours: draft.hours,
                            contactAlternative: draft.contactAlternative,
                            noticeVersion: draft.noticeVersion,
                            absentStaff: draft.absentStaff.join('\n'),
                        };
                        fields = [
                            {
                                key: 'services',
                                label: 'Available services (one per line)',
                                type: 'textarea',
                                required: true,
                            },
                            {
                                key: 'regions',
                                label: 'Service regions (one per line)',
                                type: 'textarea',
                                required: true,
                            },
                            {
                                key: 'hours',
                                label: 'Staffed intake hours',
                                required: true,
                            },
                            {
                                key: 'contactAlternative',
                                label: 'Contact fallback',
                                required: true,
                            },
                            {
                                key: 'noticeVersion',
                                label: 'Privacy notice version',
                                required: true,
                            },
                            {
                                key: 'absentStaff',
                                label: 'Unavailable staff names (one per line)',
                                type: 'textarea',
                            },
                        ];
                    } else if (key === 'operations') {
                        initial = {
                            ...draft,
                            holidays: draft.holidays.join('\n'),
                            closureReasons: draft.closureReasons.join('\n'),
                            holdReasons: draft.holdReasons.join('\n'),
                        };
                        fields = [
                            {
                                key: 'requestDays',
                                label: 'Information response target (business days)',
                                type: 'number',
                                required: true,
                            },
                            {
                                key: 'approvalLimit',
                                label: 'Reviewer weekly approval limit (NZD)',
                                type: 'number',
                                required: true,
                            },
                            {
                                key: 'separateReviewer',
                                label: 'Require a different proposal reviewer',
                                type: 'check',
                            },
                            {
                                key: 'onboardingTemplate',
                                label: 'Onboarding checklist name',
                                required: true,
                            },
                            {
                                key: 'holidays',
                                label: 'Non-working dates (YYYY-MM-DD, one per line)',
                                type: 'textarea',
                            },
                            {
                                key: 'closureReasons',
                                label: 'Closure reasons (one per line)',
                                type: 'textarea',
                                required: true,
                            },
                            {
                                key: 'holdReasons',
                                label: 'Hold reasons (one per line)',
                                type: 'textarea',
                                required: true,
                            },
                        ];
                    } else {
                        initial = draft.pathwayDetails[dialog.path] || {
                            owner: '',
                            reviewDate: '',
                            referenceUrl: '',
                            eligibility: '',
                            escalation: '',
                        };
                        fields = [
                            {
                                key: 'owner',
                                label: 'Guidance review owner',
                                required: true,
                            },
                            {
                                key: 'reviewDate',
                                label: 'Next guidance review',
                                type: 'date',
                                required: true,
                            },
                            {
                                key: 'referenceUrl',
                                label: 'Official guidance URL',
                                required: true,
                            },
                            {
                                key: 'eligibility',
                                label: 'Eligibility / preparation notes',
                                type: 'textarea',
                                required: true,
                            },
                            {
                                key: 'escalation',
                                label: 'Escalation and reassessment guidance',
                                type: 'textarea',
                                required: true,
                            },
                        ];
                    }
                    return (
                        <Wizard
                            title={
                                spec?.title ||
                                (key === 'pathway'
                                    ? dialog.path + ' guidance'
                                    : 'Configure ' + key)
                            }
                            initial={initial}
                            onClose={() => setDialog(null)}
                            steps={[
                                {
                                    label: 'Configuration',
                                    fields,
                                    validate: (v) =>
                                        key === 'pathway' &&
                                        !/^https:\/\//.test(v.referenceUrl)
                                            ? 'Use an HTTPS official guidance link.'
                                            : key === 'operations' &&
                                                Number(v.requestDays) < 1
                                              ? 'Response target must be at least one business day.'
                                              : '',
                                },
                            ]}
                            onSave={(v) => {
                                if (spec) {
                                    const row = {
                                        ...v,
                                        id: dialog.row?.id || uid(key),
                                    };
                                    edit(
                                        key,
                                        v.retire
                                            ? draft[key].filter(
                                                  (x) => x.id !== dialog.row.id,
                                              )
                                            : dialog.row
                                              ? draft[key].map((x) =>
                                                    x.id === row.id ? row : x,
                                                )
                                              : [...draft[key], row],
                                    );
                                } else if (key === 'pathway')
                                    edit('pathwayDetails', {
                                        ...draft.pathwayDetails,
                                        [dialog.path]: v,
                                    });
                                else
                                    for (const field of fields) {
                                        const k = field.key;
                                        edit(
                                            k,
                                            [
                                                'services',
                                                'regions',
                                                'absentStaff',
                                                'holidays',
                                                'closureReasons',
                                                'holdReasons',
                                            ].includes(k)
                                                ? v[k]
                                                      .split('\n')
                                                      .map((x) => x.trim())
                                                      .filter(Boolean)
                                                : v[k],
                                        );
                                    }
                                return {
                                    message:
                                        'Draft updated. Review changes and publish from the settings header to apply them.',
                                };
                            }}
                        />
                    );
                })()}
        </div>
    );
}

export function VersionInspector({ version, onClose, onRestore }) {
    const c = version.config;
    return (
        <Modal title={`Published version ${version.version}`} onClose={onClose}>
            <Notice>
                {displayStamp(version.at)} ·{' '}
                {version.by || 'Original sample configuration'} ·{' '}
                {version.reason}
            </Notice>
            {c ? (
                <>
                    <ReviewCard
                        icon={History}
                        title="Immutable configuration snapshot"
                    >
                        <ReviewRow
                            label="Effective from"
                            value={displayStamp(
                                version.effectiveAt || version.at,
                            )}
                        />
                        <ReviewRow
                            label="Website"
                            value={`${c.forms.length} questions · ${c.services.join(', ')} · ${c.hours}`}
                        />
                        <ReviewRow
                            label="Routing / transitions"
                            value={`${c.routingRules.length} routing rules · ${c.stageRules.length} stage requirements`}
                        />
                        <ReviewRow
                            label="Safeguards"
                            value={`Retention review ${c.retention} years · approval limit ${c.approvalLimit} · pending onboarding ${c.allowPending ? 'On' : 'Off'}`}
                        />
                        <ReviewRow
                            label="Funding requirements"
                            value={Object.entries(c.requirements)
                                .map(([p, list]) => `${p}: ${list.join(', ')}`)
                                .join(' · ')}
                        />
                    </ReviewCard>
                    <details className="note">
                        <summary>Inspect every stored setting</summary>
                        {Object.entries(c)
                            .filter(
                                ([key]) =>
                                    !['version', 'requirements'].includes(key),
                            )
                            .map(([key, v]) => (
                                <ReviewRow
                                    key={key}
                                    label={key}
                                    value={
                                        typeof v === 'object'
                                            ? Array.isArray(v)
                                                ? v
                                                      .map((x) =>
                                                          typeof x === 'object'
                                                              ? Object.values(x)
                                                                    .map(String)
                                                                    .join(' · ')
                                                              : String(x),
                                                      )
                                                      .join('; ')
                                                : Object.entries(v)
                                                      .map(
                                                          ([k, value]) =>
                                                              `${k}: ${
                                                                  typeof value ===
                                                                  'object'
                                                                      ? Object.entries(
                                                                            value,
                                                                        )
                                                                            .map(
                                                                                ([
                                                                                    name,
                                                                                    item,
                                                                                ]) =>
                                                                                    `${name} ${Array.isArray(item) ? item.join(', ') : String(item)}`,
                                                                            )
                                                                            .join(
                                                                                ' · ',
                                                                            )
                                                                      : String(
                                                                            value,
                                                                        )
                                                              }`,
                                                      )
                                                      .join('; ')
                                            : String(v)
                                    }
                                />
                            ))}
                    </details>
                    <Button
                        variant="outline"
                        onClick={() => {
                            onRestore(clone(c), clone(version.roles));
                            onClose();
                        }}
                    >
                        Restore into draft for review
                    </Button>
                </>
            ) : (
                <Notice>
                    This older sample has no recoverable snapshot. It cannot be
                    restored.
                </Notice>
            )}
        </Modal>
    );
}

export function NotificationPreview({ config }) {
    const [event, setEvent] = useState('assignment'),
        [shown, setShown] = useState(false);
    const channels = [
        config.notifications[event] ? 'In-app' : null,
        config.notifications[event + 'Email'] && config.notifications.email
            ? 'Email'
            : null,
    ].filter(Boolean);
    return (
        <ReviewCard title="Try a notification rule" icon={Workflow}>
            <Field
                label="Example event"
                type="select"
                value={event}
                options={[
                    { value: 'assignment', label: 'Owner assignment' },
                    { value: 'overdue', label: 'Overdue escalation' },
                ]}
                onChange={(v) => {
                    setEvent(v);
                    setShown(false);
                }}
            />
            <Button variant="outline" onClick={() => setShown(true)}>
                Preview generated notice
            </Button>
            {shown && (
                <>
                    <ReviewRow
                        label="Recipients"
                        value="Assigned owner; intake manager fallback"
                    />
                    <ReviewRow
                        label="Enabled delivery"
                        value={
                            channels.join(', ') ||
                            'No delivery enabled for this event'
                        }
                    />
                    <ReviewRow
                        label="Safe message"
                        value={
                            event === 'assignment'
                                ? 'A referral has been assigned to you. Open your permitted queue to review.'
                                : 'A referral follow-up is overdue. Review ownership and the next contact.'
                        }
                    />
                    <ReviewRow
                        label="Review target"
                        value={businessDate(
                            config.reviewDays,
                            undefined,
                            config,
                        )}
                    />
                    <Notice>
                        This is a local preview. It contains no assessment
                        narrative or funding amount.
                    </Notice>
                </>
            )}
        </ReviewCard>
    );
}
