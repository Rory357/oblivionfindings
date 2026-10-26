import {
    PageHeader,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
import {
    ArrowUpRight,
    FileText,
    Globe,
    History,
    LockKeyhole,
    Mail,
    Plus,
    Settings2,
    ShieldCheck,
    Users,
    Wallet,
    Workflow,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { usePrototype } from './context';
import { pathways, permissions } from './data';
import {
    actorFor,
    clone,
    displayStamp,
    policyWarnings,
    staffDirectory,
    stamp,
    uid,
    validateConfig,
} from './model';
import {
    AdvancedSettings,
    NotificationPreview,
    VersionInspector,
} from './settings-advanced';
import {
    PermissionGroups,
    ScopeEditor,
    SettingsSection as Section,
    SettingsOverview,
    StageList,
} from './settings-design';
import {
    Badge,
    Button,
    Empty,
    Field,
    Modal,
    Notice,
    ReviewCard,
    ReviewRow,
    Switch,
    Tabs,
    Wizard,
} from './ui';

const areas = [
    { key: 'Overview', label: 'Overview', icon: Settings2 },
    { key: 'Intake', label: 'Intake', icon: Globe },
    { key: 'Workflow', label: 'Workflow', icon: Workflow },
    { key: 'Funding', label: 'Funding', icon: Wallet },
    { key: 'Access', label: 'Access', icon: ShieldCheck },
    { key: 'History', label: 'History', icon: History },
];
export function SettingsView() {
    const {
        data,
        setData,
        can,
        role,
        scoped,
        notify,
        setSettingsDirty,
        audit,
        failure,
        setFailure,
    } = usePrototype();
    const [area, setArea] = useState('Overview'),
        [sub, setSub] = useState('Overview'),
        [draft, setDraft] = useState(() => structuredClone(data.config)),
        [draftRoles, setDraftRoles] = useState(() =>
            structuredClone(data.roles),
        ),
        [roleEdit, setRoleEdit] = useState('Coordinator'),
        [path, setPath] = useState(pathways[0]),
        [extra, setExtra] = useState(''),
        [modal, setModal] = useState(null),
        [inspectVersion, setInspectVersion] = useState(null),
        [error, setError] = useState(''),
        [migration, setMigration] = useState([]);
    const [sectionLinks, setSectionLinks] = useState([]);
    useEffect(() => {
        const headings = [
            ...document.querySelectorAll(
                '.settings-content h2, .settings-content h3, .settings-content .wizard-review-card > div:first-child > div:first-child',
            ),
        ];
        setSectionLinks(
            headings.map((heading, index) => {
                const id = `settings-section-${index}`;
                heading.id = id;
                heading.tabIndex = -1;
                return { value: id, label: heading.textContent };
            }),
        );
    }, [area, sub]);
    const dirty =
        JSON.stringify(draft) !== JSON.stringify(data.config) ||
        JSON.stringify(draftRoles) !== JSON.stringify(data.roles);
    const errorRef = useRef(null);
    useEffect(() => {
        if (error) {
            errorRef.current?.focus();
            errorRef.current?.scrollIntoView({ block: 'center' });
        }
    }, [error]);
    useEffect(() => {
        setSettingsDirty(dirty);
        return () => setSettingsDirty(false);
    }, [dirty, setSettingsDirty]);
    const edit = (key, value) => setDraft((d) => ({ ...d, [key]: value }));
    const labels = {
        customField: 'Public question',
        firstContact: 'First contact target (business days)',
        reviewDays: 'Waiting-case review (business days)',
        routing: 'Default receiving team',
        allowPending: 'Onboarding with funding pending',
        requireInterpreter: 'Interpreter question',
        acknowledgement: 'Acknowledgement receipt',
        retention: 'Retention review (years)',
        stages: 'Workflow stages',
        messageTemplate: 'Information-request message',
    };
    const display = (v) =>
        Array.isArray(v)
            ? v
                  .map((x) =>
                      typeof x === 'object'
                          ? Object.entries(x)
                                .map(([k, v]) => `${k}: ${String(v)}`)
                                .join(', ')
                          : x,
                  )
                  .join(' · ')
            : typeof v === 'boolean'
              ? v
                  ? 'On'
                  : 'Off'
              : typeof v === 'object'
                ? JSON.stringify(v)
                : String(v);
    const changes = Object.keys(draft)
        .filter(
            (k) =>
                !['requirements', 'notifications', 'scopeRules'].includes(k) &&
                JSON.stringify(draft[k]) !== JSON.stringify(data.config[k]),
        )
        .map((k) => ({
            key: labels[k] || k,
            before: display(data.config[k]),
            after: display(draft[k]),
        }));
    for (const path of pathways)
        if (
            JSON.stringify(draft.requirements[path]) !==
            JSON.stringify(data.config.requirements[path])
        )
            changes.push({
                key: path + ' preparation',
                before: display(data.config.requirements[path]),
                after: display(draft.requirements[path]),
            });
    for (const name of Object.keys(draft.scopeRules))
        if (
            JSON.stringify(draft.scopeRules[name]) !==
            JSON.stringify(data.config.scopeRules[name])
        )
            changes.push({
                key: name + ' record scope',
                before:
                    data.config.scopeRules[name].sites.join(', ') +
                    ' · ' +
                    data.config.scopeRules[name].ownership,
                after:
                    draft.scopeRules[name].sites.join(', ') +
                    ' · ' +
                    draft.scopeRules[name].ownership,
            });
    for (const key of Object.keys(draft.notifications))
        if (draft.notifications[key] !== data.config.notifications[key])
            changes.push({
                key: key + ' notifications',
                before: display(data.config.notifications[key]),
                after: display(draft.notifications[key]),
            });
    for (const name of Object.keys(draftRoles))
        for (const key of Object.keys(permissions))
            if (draftRoles[name][key] !== data.roles[name][key])
                changes.push({
                    key: name + ' · ' + permissions[key],
                    before: data.roles[name][key] ? 'Allowed' : 'Not granted',
                    after: draftRoles[name][key] ? 'Allowed' : 'Not granted',
                });
    const wholeNumberWithin = (value, min, max) =>
        Number.isInteger(Number(value)) &&
        Number(value) >= min &&
        Number(value) <= max;
    const validation = () =>
        validateConfig(draft) ||
        (!draft.customField.trim()
            ? 'The public question cannot be empty.'
            : !wholeNumberWithin(draft.firstContact, 1, 30)
              ? 'Initial contact target must be a whole number between 1 and 30 business days.'
              : !wholeNumberWithin(draft.reviewDays, 1, 90)
                ? 'Review target must be a whole number between 1 and 90 business days.'
                : !wholeNumberWithin(draft.retention, 1, 30)
                  ? 'Retention review must be a whole number between 1 and 30 years.'
                  : !draft.messageTemplate.trim()
                    ? 'Add a message template before publishing.'
                    : Object.values(draftRoles).every((r) => !r.settings)
                      ? 'At least one role must retain settings publication permission.'
                      : !draftRoles[role].settings
                        ? 'Your current role must retain permission to publish this change. Use a delegated review for removing your own access.'
                        : pathways.some((p) => !draft.requirements[p]?.length)
                          ? 'Every pathway needs at least one preparation requirement.'
                          : '');
    const warnings = policyWarnings(data.config, draft, data.roles, draftRoles);
    const loosensPolicy = warnings.length > 0;
    const jump = (a, s = 'Overview') => {
        setArea(a);
        setSub(s);
        setError('');
    };
    const review = (title, icon, rows, a) => (
        <ReviewCard key={title} icon={icon} title={title}>
            {rows.map(([label, value]) => (
                <ReviewRow key={label} label={label} value={value} />
            ))}
            <Button variant="link" onClick={() => jump(a, 'Edit')}>
                Review {title.toLowerCase()} <ArrowUpRight />
            </Button>
        </ReviewCard>
    );
    const toggle = (label, description, value, onChange) => (
        <div className="setting-row">
            <div>
                <strong>{label}</strong>
                <p className="text-subtle">{description}</p>
            </div>
            <div className="actions">
                <span className="text-caption">{value ? 'On' : 'Off'}</span>
                <Switch
                    aria-label={label}
                    checked={value}
                    onCheckedChange={onChange}
                />
            </div>
        </div>
    );
    if (!can('settings'))
        return (
            <>
                <PageHeader
                    title="Referral settings"
                    mobileSummary="Configuration overview and changes"
                    icon={LockKeyhole}
                    subline="Restricted configuration"
                />
                <div className="body-stack settings-content">
                    <Empty title="Settings permission required">
                        Only authorized settings publishers can change forms,
                        pathways or permissions.
                    </Empty>
                </div>
            </>
        );
    return (
        <>
            <PageHeader
                title="Referral settings"
                icon={Settings2}
                titleChip={
                    <PageHeaderStatusChip
                        variant={dirty ? 'warning' : 'success'}
                    >
                        {dirty
                            ? 'Unpublished changes'
                            : 'Published v' + data.config.version}
                    </PageHeaderStatusChip>
                }
                subline="One organisation · controlled configuration across approved sites"
                actions={
                    <PageHeaderPrimaryButton
                        disabled={!dirty}
                        onClick={() => {
                            const e = validation();
                            setError(e);
                            if (!e) setModal('publish');
                        }}
                    >
                        Review changes
                    </PageHeaderPrimaryButton>
                }
                rail={
                    <PageHeaderRail
                        items={areas}
                        value={area}
                        onSelect={(a) => jump(a)}
                        showFind={false}
                    />
                }
            />
            <div className="body-stack settings-content">
                {error && (
                    <div ref={errorRef} tabIndex={-1}>
                        <Notice tone="critical">{error}</Notice>
                    </div>
                )}
                {['Intake', 'Workflow', 'Funding', 'Access'].includes(area) && (
                    <Tabs
                        items={[
                            {
                                key: 'Overview',
                                label: 'Overview',
                                icon: Settings2,
                            },
                            {
                                key: 'Edit',
                                label:
                                    area === 'Access'
                                        ? 'Role permissions'
                                        : area === 'Workflow'
                                          ? 'Routing & stages'
                                          : 'Configuration',
                                icon: Settings2,
                            },
                            ...(area === 'Workflow'
                                ? [
                                      {
                                          key: 'Notifications',
                                          label: 'Notifications',
                                          icon: Mail,
                                      },
                                      {
                                          key: 'Delivery',
                                          label: 'Delivery & channels',
                                          icon: Mail,
                                      },
                                  ]
                                : []),
                            ...(area === 'Access'
                                ? [
                                      {
                                          key: 'Scope',
                                          label: 'Record scope',
                                          icon: LockKeyhole,
                                      },
                                  ]
                                : []),
                        ]}
                        value={sub}
                        onChange={setSub}
                    />
                )}
                {(area === 'Overview' ||
                    (sub === 'Overview' && area !== 'History')) && (
                    <SettingsOverview
                        area={area}
                        draft={draft}
                        data={data}
                        jump={jump}
                    />
                )}
                {sub !== 'Overview' && sectionLinks.length > 1 && (
                    <Field
                        label="Jump to settings section"
                        type="select"
                        value=""
                        options={[
                            { value: '', label: 'Choose a section…' },
                            ...sectionLinks,
                        ]}
                        onChange={(id) => {
                            const heading = document.getElementById(id);
                            heading?.scrollIntoView({ block: 'start' });
                            heading?.focus({ preventScroll: true });
                        }}
                    />
                )}
                {area === 'Intake' && sub !== 'Overview' && (
                    <>
                        <Section
                            title="Public entry forms"
                            icon={Globe}
                            caption="Keep initial contact short. Funding and full assessment details can follow."
                        >
                            <Field
                                label="Additional public question"
                                value={draft.customField}
                                onChange={(v) => edit('customField', v)}
                                hint="This label appears on the public form after publication."
                            />
                            {toggle(
                                'Ask about interpreter needs',
                                'Show an optional language / interpreter question.',
                                draft.requireInterpreter,
                                (v) => edit('requireInterpreter', v),
                            )}
                            {toggle(
                                'Funding-help guidance on receipt',
                                'Show additional funding-help guidance. A submission reference is always provided.',
                                draft.acknowledgement,
                                (v) => edit('acknowledgement', v),
                            )}
                            <div className="setting-row">
                                <div>
                                    <strong>
                                        Core privacy & safe contact fields
                                    </strong>
                                    <p className="text-subtle">
                                        Minimum contact, authority basis,
                                        privacy acknowledgement and safe-contact
                                        preference.
                                    </p>
                                </div>
                                <Badge>Always required</Badge>
                            </div>
                        </Section>
                        <Section
                            title="Document collection & retention"
                            icon={FileText}
                        >
                            <Notice>
                                Public initial enquiries do not require
                                sensitive document uploads. Retention periods
                                and lawful bases need the organisation’s privacy
                                approval before implementation.
                            </Notice>
                            <Field
                                label="Sample retention review period (years)"
                                type="number"
                                min="1"
                                value={draft.retention}
                                onChange={(v) => edit('retention', Number(v))}
                            />
                            <p className="text-caption">
                                This schedules a review; it does not
                                automatically delete records or override legal
                                holds.
                            </p>
                        </Section>
                    </>
                )}
                {area === 'Workflow' && sub === 'Edit' && (
                    <>
                        <Section
                            title="Ownership & routing"
                            icon={Users}
                            caption="Central intake owns unallocated work until triage."
                        >
                            <Field
                                label="Default receiving team"
                                type="select"
                                options={[
                                    'Central intake',
                                    'Supported living intake',
                                    'Funding navigators',
                                ]}
                                value={draft.routing}
                                onChange={(v) => edit('routing', v)}
                            />
                            <div className="form-grid">
                                <Field
                                    label="First human contact target (business days)"
                                    type="number"
                                    min="1"
                                    max="30"
                                    value={draft.firstContact}
                                    onChange={(v) =>
                                        edit('firstContact', Number(v))
                                    }
                                />
                                <Field
                                    label="Waiting-case review target (business days)"
                                    type="number"
                                    min="1"
                                    max="90"
                                    value={draft.reviewDays}
                                    onChange={(v) =>
                                        edit('reviewDays', Number(v))
                                    }
                                />
                            </div>
                            <Notice>
                                Targets are company settings. The implementation
                                must use a New Zealand business calendar and
                                configured operating hours; they are not
                                government decision deadlines.
                            </Notice>
                        </Section>
                        <Section
                            title="Workflow stages"
                            icon={Workflow}
                            caption="Core lifecycle and readiness gates remain protected."
                        >
                            <StageList
                                values={draft.stages}
                                onChange={(v) => edit('stages', v)}
                            />
                            <div className="actions">
                                <Field
                                    label="Add a custom stage"
                                    value={extra}
                                    onChange={setExtra}
                                />
                                <Button
                                    variant="outline"
                                    disabled={
                                        !extra.trim() ||
                                        draft.stages.includes(extra.trim())
                                    }
                                    onClick={() => {
                                        edit('stages', [
                                            ...draft.stages.slice(0, -2),
                                            extra.trim(),
                                            ...draft.stages.slice(-2),
                                        ]);
                                        setExtra('');
                                    }}
                                >
                                    <Plus />
                                    Add stage
                                </Button>
                            </div>
                        </Section>
                    </>
                )}
                {area === 'Workflow' &&
                    ['Notifications', 'Delivery'].includes(sub) && (
                        <>
                            {sub === 'Notifications' && (
                                <Section
                                    title="Follow-up notifications"
                                    icon={Mail}
                                    caption="Review event recipients separately from delivery channels."
                                >
                                    <div className="notification-head">
                                        <span>Event</span>
                                        <span>In-app</span>
                                        <span>Email</span>
                                    </div>
                                    {[
                                        ['assignment', 'Owner assignment'],
                                        ['overdue', 'Overdue escalation'],
                                    ].map(([key, label]) => (
                                        <div
                                            className="notification-row"
                                            key={key}
                                        >
                                            <div>
                                                <strong>{label}</strong>
                                                <p className="text-subtle">
                                                    Owner + intake manager
                                                    fallback
                                                </p>
                                            </div>
                                            <div className="actions">
                                                <span className="text-caption">
                                                    {draft.notifications[key]
                                                        ? 'On'
                                                        : 'Off'}
                                                </span>
                                                <Switch
                                                    aria-label={
                                                        label + ' in-app'
                                                    }
                                                    checked={
                                                        draft.notifications[key]
                                                    }
                                                    onCheckedChange={(v) =>
                                                        edit('notifications', {
                                                            ...draft.notifications,
                                                            [key]: v,
                                                        })
                                                    }
                                                />
                                            </div>
                                            <div className="actions">
                                                <span className="text-caption">
                                                    {draft.notifications[
                                                        key + 'Email'
                                                    ]
                                                        ? 'On'
                                                        : 'Off'}
                                                </span>
                                                <Switch
                                                    aria-label={
                                                        label + ' email'
                                                    }
                                                    checked={
                                                        !!draft.notifications[
                                                            key + 'Email'
                                                        ]
                                                    }
                                                    onCheckedChange={(value) =>
                                                        edit('notifications', {
                                                            ...draft.notifications,
                                                            [key + 'Email']:
                                                                value,
                                                        })
                                                    }
                                                />
                                            </div>
                                        </div>
                                    ))}
                                </Section>
                            )}
                            {sub === 'Notifications' && (
                                <NotificationPreview config={draft} />
                            )}
                            {sub === 'Delivery' && (
                                <>
                                    <Section
                                        title="Information-request template"
                                        icon={Mail}
                                        caption="Staff review and personalise each request before sending."
                                    >
                                        <Field
                                            label="Message template"
                                            type="textarea"
                                            value={draft.messageTemplate}
                                            onChange={(v) =>
                                                edit('messageTemplate', v)
                                            }
                                            hint="Use {name} for the person’s preferred name. This appears in the referral message preview."
                                        />
                                    </Section>
                                    <Section
                                        title="Delivery channels"
                                        icon={Mail}
                                    >
                                        {toggle(
                                            'Email notifications',
                                            'Prototype previews only; production transport must be configured separately.',
                                            draft.notifications.email,
                                            (v) =>
                                                edit('notifications', {
                                                    ...draft.notifications,
                                                    email: v,
                                                }),
                                        )}
                                        <Notice>
                                            Quiet hours, failed delivery and
                                            escalation must not remove an
                                            outstanding follow-up obligation.
                                        </Notice>
                                    </Section>
                                </>
                            )}
                        </>
                    )}
                {area === 'Funding' && sub !== 'Overview' && (
                    <>
                        <Section
                            title="Pathway templates"
                            icon={Wallet}
                            caption="Each new case receives a versioned copy of these requirements."
                        >
                            <Field
                                label="Pathway"
                                type="search"
                                options={pathways}
                                value={path}
                                onChange={setPath}
                            />
                            {draft.requirements[path].map((label, i) => (
                                <div className="setting-row" key={i}>
                                    <span>{label}</span>
                                    <Button
                                        variant="ghost"
                                        onClick={() =>
                                            edit('requirements', {
                                                ...draft.requirements,
                                                [path]: draft.requirements[
                                                    path
                                                ].filter((_, j) => j !== i),
                                            })
                                        }
                                    >
                                        Remove requirement
                                    </Button>
                                </div>
                            ))}
                            <div className="actions">
                                <Field
                                    label="New preparation requirement"
                                    value={extra}
                                    onChange={setExtra}
                                />
                                <Button
                                    variant="outline"
                                    disabled={
                                        !extra.trim() ||
                                        draft.requirements[path].some(
                                            (label) =>
                                                label.toLowerCase() ===
                                                extra.trim().toLowerCase(),
                                        )
                                    }
                                    onClick={() => {
                                        edit('requirements', {
                                            ...draft.requirements,
                                            [path]: [
                                                ...draft.requirements[path],
                                                extra.trim(),
                                            ],
                                        });
                                        setExtra('');
                                    }}
                                >
                                    <Plus />
                                    Add requirement
                                </Button>
                            </div>
                        </Section>
                        <Section
                            title="Conversion & funding safeguards"
                            icon={ShieldCheck}
                        >
                            {toggle(
                                'Allow onboarding with funding pending',
                                'Service commencement still requires verified funding, approval, capacity and a safe start.',
                                draft.allowPending,
                                (v) => edit('allowPending', v),
                            )}
                            <div className="setting-row">
                                <div>
                                    <strong>
                                        External decision verification
                                    </strong>
                                    <p className="text-subtle">
                                        A named authorized reviewer checks
                                        evidence, scope and conditions.
                                    </p>
                                </div>
                                <Badge>Always required</Badge>
                            </div>
                            <div className="setting-row">
                                <div>
                                    <strong>Personal income separation</strong>
                                    <p className="text-subtle">
                                        Benefits and income assistance do not
                                        count as provider service budgets.
                                    </p>
                                </div>
                                <Badge>Always required</Badge>
                            </div>
                        </Section>
                    </>
                )}
                {area === 'Access' &&
                    (sub === 'Permissions' || sub === 'Edit') && (
                        <>
                            <Section
                                title="Role permissions"
                                icon={ShieldCheck}
                                caption="A grant never bypasses approved sites, canonical record ownership or privacy restrictions."
                            >
                                <Field
                                    label="Role to configure"
                                    type="search"
                                    options={Object.keys(data.roles)}
                                    value={roleEdit}
                                    onChange={setRoleEdit}
                                />
                                <PermissionGroups
                                    role={roleEdit}
                                    values={draftRoles[roleEdit]}
                                    onChange={(key, value) =>
                                        setDraftRoles((s) => ({
                                            ...s,
                                            [roleEdit]: {
                                                ...s[roleEdit],
                                                [key]: value,
                                            },
                                        }))
                                    }
                                />{' '}
                            </Section>
                        </>
                    )}
                {area === 'Access' && sub === 'Scope' && (
                    <Section
                        title="Authorization boundaries"
                        icon={LockKeyhole}
                        caption="Single organisation, multiple approved sites."
                    >
                        <ScopeEditor
                            role={roleEdit}
                            roles={draftRoles}
                            value={draft.scopeRules[roleEdit]}
                            sites={draft.regions}
                            onRole={setRoleEdit}
                            onChange={(v) =>
                                edit('scopeRules', {
                                    ...draft.scopeRules,
                                    [roleEdit]: v,
                                })
                            }
                        />{' '}
                        <h2 className="text-section-title">
                            Staff assignment sites
                        </h2>
                        <p className="text-subtle">
                            Choose where each coordinator can receive work. Role
                            permissions and record scope still apply. Publish
                            these choices before assigning new referrals.
                        </p>
                        {(draft.staffDirectory || staffDirectory)
                            .filter((person) => person.name !== 'Intake team')
                            .map((person) => (
                                <ReviewCard
                                    key={person.id}
                                    title={person.name}
                                    icon={Users}
                                >
                                    {draft.regions.map((site) => (
                                        <div className="setting-row" key={site}>
                                            <span>{site}</span>
                                            <Switch
                                                aria-label={`${person.name} can receive work at ${site}`}
                                                checked={person.sites.includes(
                                                    site,
                                                )}
                                                onCheckedChange={(enabled) =>
                                                    edit(
                                                        'staffDirectory',
                                                        (
                                                            draft.staffDirectory ||
                                                            staffDirectory
                                                        ).map((p) =>
                                                            p.id === person.id
                                                                ? {
                                                                      ...p,
                                                                      sites: enabled
                                                                          ? [
                                                                                ...new Set(
                                                                                    [
                                                                                        ...p.sites,
                                                                                        site,
                                                                                    ],
                                                                                ),
                                                                            ]
                                                                          : p.sites.filter(
                                                                                (
                                                                                    s,
                                                                                ) =>
                                                                                    s !==
                                                                                    site,
                                                                            ),
                                                                  }
                                                                : p,
                                                        ),
                                                    )
                                                }
                                            />
                                        </div>
                                    ))}
                                </ReviewCard>
                            ))}
                    </Section>
                )}
                {sub === 'Edit' &&
                    ['Intake', 'Workflow', 'Funding'].includes(area) && (
                        <AdvancedSettings
                            area={area}
                            draft={draft}
                            edit={edit}
                        />
                    )}
                {area === 'History' && (
                    <>
                        <Section
                            title="Published configuration versions"
                            icon={History}
                            actions={
                                <Button
                                    variant="outline"
                                    onClick={() => setModal('migrate')}
                                >
                                    Review open-case migration
                                </Button>
                            }
                        >
                            {data.versions.map((v) => (
                                <div key={v.version} className="work-row">
                                    <Badge>v{v.version}</Badge>
                                    <div className="grow">
                                        <strong>{v.reason}</strong>
                                        <p className="text-subtle">
                                            {displayStamp(v.at)} ·{' '}
                                            {v.by || 'Sample configuration'}
                                        </p>
                                    </div>
                                    <span>
                                        {v.version === data.config.version
                                            ? 'Current'
                                            : 'Preserved'}
                                    </span>
                                    <Button
                                        variant="outline"
                                        onClick={() => setInspectVersion(v)}
                                    >
                                        Inspect / restore
                                    </Button>
                                </div>
                            ))}
                        </Section>
                        <Section title="Configuration audit" icon={ShieldCheck}>
                            {data.audit
                                .filter((a) => a.kind === 'configuration')
                                .map((a, i) => (
                                    <div className="timeline-item" key={i}>
                                        <div>
                                            <strong>{a.text}</strong>
                                            <p className="text-caption">
                                                {a.at} · {a.by}
                                            </p>
                                        </div>
                                    </div>
                                ))}
                        </Section>
                    </>
                )}
                {(dirty || (sub !== 'Overview' && area !== 'History')) && (
                    <div className="settings-savebar">
                        <div>
                            <strong>
                                {dirty
                                    ? 'Unpublished changes'
                                    : 'All changes published'}
                            </strong>
                            <p className="text-caption">
                                {changes.length} setting group(s) changed ·
                                Existing cases stay on their version
                            </p>
                        </div>
                        <div className="actions">
                            <Button
                                variant="outline"
                                disabled={!dirty}
                                onClick={() => setModal('discard')}
                            >
                                Discard
                            </Button>
                            <Button
                                disabled={!dirty}
                                onClick={() => {
                                    const e = validation();
                                    setError(e);
                                    if (!e) setModal('publish');
                                }}
                            >
                                Review changes
                            </Button>
                            <Button
                                variant="link"
                                onClick={() => {
                                    window.scrollTo({ top: 0 });
                                    const heading =
                                        document.querySelector('main h1');
                                    if (heading) {
                                        heading.tabIndex = -1;
                                        heading.focus({ preventScroll: true });
                                    }
                                }}
                            >
                                Back to top
                            </Button>
                        </div>
                    </div>
                )}
            </div>
            {inspectVersion && (
                <VersionInspector
                    version={inspectVersion}
                    onClose={() => setInspectVersion(null)}
                    onRestore={(config, roles) => {
                        setDraft({ ...config, version: data.config.version });
                        if (roles) setDraftRoles(roles);
                        jump('Overview');
                    }}
                />
            )}
            {modal === 'publish' && (
                <Wizard
                    title="Publish referral settings"
                    onClose={() => setModal(null)}
                    initial={{ reason: '' }}
                    steps={[
                        {
                            label: 'Impact & changes',
                            render: () => (
                                <div className="stack">
                                    <Notice>
                                        New referrals and new funding cases will
                                        use v{data.config.version + 1}.{' '}
                                        {
                                            data.records.filter(
                                                (r) =>
                                                    ![
                                                        'Closed',
                                                        'Converted',
                                                    ].includes(r.stage),
                                            ).length
                                        }{' '}
                                        open referrals keep their existing
                                        version. Existing grant changes take
                                        effect immediately in the role preview.
                                    </Notice>
                                    {changes.map((c) => (
                                        <ReviewCard
                                            key={c.key}
                                            icon={Settings2}
                                            title={c.key}
                                        >
                                            <ReviewRow
                                                label="Published"
                                                value={
                                                    typeof c.before === 'object'
                                                        ? JSON.stringify(
                                                              c.before,
                                                          )
                                                        : String(c.before)
                                                }
                                            />
                                            <ReviewRow
                                                label="Draft"
                                                value={
                                                    typeof c.after === 'object'
                                                        ? JSON.stringify(
                                                              c.after,
                                                          )
                                                        : String(c.after)
                                                }
                                            />
                                        </ReviewCard>
                                    ))}
                                    {warnings.length > 0 && (
                                        <Notice tone="critical">
                                            {warnings.join('; ')}. A governance
                                            approver must publish this change.
                                        </Notice>
                                    )}
                                </div>
                            ),
                            review: () => (
                                <ReviewRow
                                    label="Changes"
                                    value={
                                        changes.length +
                                        ' groups; existing case versions preserved'
                                    }
                                />
                            ),
                        },
                        {
                            label: 'Publication note',
                            fields: [
                                {
                                    key: 'reason',
                                    label: 'Reason and approval reference',
                                    type: 'textarea',
                                    required: true,
                                },
                            ],
                        },
                    ]}
                    failure={failure}
                    clearFailure={() => setFailure('Normal')}
                    submitLabel="Publish version"
                    submitVariant={loosensPolicy ? 'destructive' : 'default'}
                    onSave={(v) => {
                        const error = validation();
                        if (error) return { error };
                        if (loosensPolicy && !can('governance'))
                            return {
                                error: 'These changes loosen safeguards. Switch to a referral manager and prepare the governed change there; your draft is preserved until discarded.',
                            };
                        const config = {
                            ...draft,
                            version: data.config.version + 1,
                        };
                        setData((s) =>
                            audit(
                                {
                                    ...s,
                                    config,
                                    roles: draftRoles,
                                    versions: [
                                        {
                                            version: config.version,
                                            at: stamp(),
                                            effectiveAt: stamp(),
                                            by: actorFor(data, role),
                                            reason: v.reason,
                                            config: clone(config),
                                            roles: clone(draftRoles),
                                            changes: clone(changes),
                                        },
                                        ...s.versions,
                                    ],
                                },
                                'Published settings v' + config.version,
                            ),
                        );
                        setDraft(config);
                        notify('Settings published in prototype');
                        return {
                            message:
                                'Version ' +
                                config.version +
                                ' is published. New cases use the new template; existing cases are unchanged.',
                        };
                    }}
                />
            )}
            {modal === 'discard' && (
                <Modal
                    title="Discard unpublished changes?"
                    description="The published version will remain in use."
                    onClose={() => setModal(null)}
                    actions={
                        <>
                            <Button
                                variant="outline"
                                onClick={() => setModal(null)}
                            >
                                Keep editing
                            </Button>
                            <Button
                                variant="destructive"
                                onClick={() => {
                                    setDraft(structuredClone(data.config));
                                    setDraftRoles(structuredClone(data.roles));
                                    setModal(null);
                                }}
                            >
                                Discard changes
                            </Button>
                        </>
                    }
                />
            )}
            {modal === 'migrate' && (
                <Wizard
                    title="Migrate open cases"
                    onClose={() => setModal(null)}
                    steps={[
                        {
                            label: 'Choose permitted cases',
                            render: () => (
                                <div className="stack">
                                    <Notice>
                                        New checklist requirements start
                                        incomplete. Matching completed
                                        requirements are preserved. Funding
                                        decisions and evidence are not changed.
                                    </Notice>
                                    {scoped
                                        .filter(
                                            (r) =>
                                                ![
                                                    'Closed',
                                                    'Converted',
                                                ].includes(r.stage),
                                        )
                                        .map((r) => (
                                            <Field
                                                key={r.id}
                                                label={`${r.name} · v${r.version} → v${data.config.version}`}
                                                type="check"
                                                value={migration.includes(r.id)}
                                                onChange={(v) =>
                                                    setMigration((ids) =>
                                                        v
                                                            ? [...ids, r.id]
                                                            : ids.filter(
                                                                  (id) =>
                                                                      id !==
                                                                      r.id,
                                                              ),
                                                    )
                                                }
                                            />
                                        ))}
                                </div>
                            ),
                            validate: () =>
                                migration.length
                                    ? ''
                                    : 'Choose at least one permitted open case.',
                            review: () => (
                                <ReviewRow
                                    label="Selected cases"
                                    value={migration
                                        .map((id) => {
                                            const r = scoped.find(
                                                (x) => x.id === id,
                                            );
                                            return (
                                                `${id}: v${r?.version} → v${data.config.version}; ` +
                                                r?.funding
                                                    .map((f) => {
                                                        const required =
                                                            data.config
                                                                .requirements[
                                                                f.path
                                                            ];
                                                        return `${f.path}: add ${required.filter((l) => !f.checks.some((c) => c.label === l)).join(', ') || 'none'}; archive ${
                                                            f.checks
                                                                .filter(
                                                                    (c) =>
                                                                        !required.includes(
                                                                            c.label,
                                                                        ),
                                                                )
                                                                .map(
                                                                    (c) =>
                                                                        c.label,
                                                                )
                                                                .join(', ') ||
                                                            'none'
                                                        }`;
                                                    })
                                                    .join(' · ')
                                            );
                                        })
                                        .join(' | ')}
                                />
                            ),
                        },
                        {
                            label: 'Controlled migration',
                            fields: [
                                {
                                    key: 'reason',
                                    label: 'Reason and approval reference',
                                    type: 'textarea',
                                    required: true,
                                },
                            ],
                        },
                    ]}
                    initial={{}}
                    submitLabel="Apply migration"
                    onSave={(v) => {
                        setData((s) =>
                            audit(
                                {
                                    ...s,
                                    records: s.records.map((r) =>
                                        migration.includes(r.id)
                                            ? {
                                                  ...r,
                                                  migrations: [
                                                      ...(r.migrations || []),
                                                      {
                                                          at: stamp(),
                                                          by: actorFor(
                                                              data,
                                                              role,
                                                          ),
                                                          from: r.version,
                                                          to: s.config.version,
                                                          reason: v.reason,
                                                          previousFunding:
                                                              clone(r.funding),
                                                      },
                                                  ],
                                                  version: s.config.version,
                                                  funding: r.funding.map(
                                                      (f) => ({
                                                          ...f,
                                                          archivedChecks: [
                                                              ...(f.archivedChecks ||
                                                                  []),
                                                              ...f.checks.filter(
                                                                  (c) =>
                                                                      !s.config.requirements[
                                                                          f.path
                                                                      ].includes(
                                                                          c.label,
                                                                      ),
                                                              ),
                                                          ],
                                                          version:
                                                              s.config.version,
                                                          checks: s.config.requirements[
                                                              f.path
                                                          ].map(
                                                              (label) =>
                                                                  f.checks.find(
                                                                      (c) =>
                                                                          c.label ===
                                                                          label,
                                                                  ) || {
                                                                      id: uid(
                                                                          'requirement',
                                                                      ),
                                                                      label,
                                                                      done: false,
                                                                  },
                                                          ),
                                                      }),
                                                  ),
                                                  activity: [
                                                      {
                                                          at: stamp(),
                                                          by: actorFor(
                                                              data,
                                                              role,
                                                          ),
                                                          text:
                                                              'Migrated checklist to v' +
                                                              s.config.version +
                                                              ': ' +
                                                              v.reason,
                                                      },
                                                      ...r.activity,
                                                  ],
                                              }
                                            : r,
                                    ),
                                },
                                'Migrated ' +
                                    migration.length +
                                    ' open cases to settings v' +
                                    s.config.version,
                            ),
                        );
                        return {
                            message:
                                'Selected cases now use the published checklist. Existing decisions were preserved; new requirements need review.',
                        };
                    }}
                />
            )}
        </>
    );
}
