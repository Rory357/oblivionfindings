import {
    ArrowUpRight,
    Globe,
    LockKeyhole,
    Route,
    ShieldCheck,
    Users,
    Wallet,
    X,
} from 'lucide-react';
import { pathways, permissions, stages } from './data';
import {
    Button,
    Field,
    Notice,
    ReviewCard,
    ReviewRow,
    Section,
    Switch,
} from './ui';

// Settings follow the Fleet overview/review/edit structure and shared groups.
export function ScopeEditor({ role, roles, value, sites, onRole, onChange }) {
    return (
        <>
            <Field
                label="Role to configure"
                type="search"
                value={role}
                options={Object.keys(roles)}
                onChange={onRole}
            />
            <div className="permission-summary">
                <ShieldCheck />
                <div>
                    <strong>Record scope for {role.toLowerCase()}</strong>
                    <p className="text-subtle">
                        Example staff member: {value.person}. These restrictions
                        apply to every visible record and action.
                    </p>
                </div>
            </div>
            <div className="permission-group">
                <h3 className="text-section-title">Approved sites</h3>
                {sites.map((site) => (
                    <div key={site} className="permission-control">
                        <span>{site}</span>
                        <span className="text-caption">
                            {roles[role].allSites || value.sites.includes(site)
                                ? 'On'
                                : 'Off'}
                        </span>
                        <Switch
                            aria-label={'Allow ' + site}
                            checked={
                                roles[role].allSites ||
                                value.sites.includes(site)
                            }
                            disabled={roles[role].allSites}
                            onCheckedChange={(v) =>
                                onChange({
                                    ...value,
                                    sites: v
                                        ? [...value.sites, site]
                                        : value.sites.filter((s) => s !== site),
                                })
                            }
                        />
                    </div>
                ))}
            </div>
            {roles[role].allSites && (
                <Notice>
                    “View all approved sites” is enabled for this role. Turn it
                    off in Role permissions to limit individual sites.
                </Notice>
            )}
            <Field
                label="Ownership scope"
                type="select"
                options={[
                    'All permitted referrals',
                    'Assigned referrals',
                    'Assigned referrals or funding cases',
                ]}
                value={value.ownership}
                onChange={(v) => onChange({ ...value, ownership: v })}
            />
            <Notice>
                Central intake and privacy-restricted records require separate
                grants. Settings access alone never grants access to referral
                content.
            </Notice>
        </>
    );
}
export const SettingsSection = Section;
export function SettingsOverview({ draft, data, jump, area = 'Overview' }) {
    const sections = [
        {
            key: 'Intake',
            title: 'Intake & forms',
            icon: Globe,
            rows: [
                ['Public question', draft.customField],
                ['Interpreter needs', draft.requireInterpreter ? 'On' : 'Off'],
                ['Retention review', draft.retention + ' years'],
            ],
        },
        {
            key: 'Workflow',
            title: 'Routing & follow-up',
            icon: Route,
            rows: [
                ['Receiving team', draft.routing],
                ['First contact', draft.firstContact + ' business day(s)'],
                ['Review target', draft.reviewDays + ' business days'],
                ['Workflow stages', draft.stages.length],
            ],
        },
        {
            key: 'Funding',
            title: 'Funding pathways',
            icon: Wallet,
            rows: [
                ['Pathways', pathways.length],
                [
                    'Onboarding with funding pending',
                    draft.allowPending ? 'On' : 'Off',
                ],
                ['Service-start safeguards', 'Always required'],
            ],
        },
        {
            key: 'Access',
            title: 'Permissions & record scope',
            icon: ShieldCheck,
            rows: [
                ['Role presets', Object.keys(data.roles).length],
                ['Boundary', 'Role, site, ownership and privacy'],
                ['Settings access', 'Does not grant referral access'],
            ],
        },
    ].filter((section) => area === 'Overview' || section.key === area);
    return (
        <div className="grid gap-5 md:grid-cols-2">
            {sections.map((section) => (
                <ReviewCard
                    key={section.key}
                    title={section.title}
                    icon={section.icon}
                >
                    {section.rows.map(([label, value]) => (
                        <ReviewRow
                            key={label}
                            label={label}
                            value={String(value)}
                        />
                    ))}
                    <Button
                        variant="link"
                        onClick={() => jump(section.key, 'Edit')}
                    >
                        Review {section.title.toLowerCase()} <ArrowUpRight />
                    </Button>
                </ReviewCard>
            ))}
        </div>
    );
}
export function StageList({ values, onChange }) {
    const hints = {
        New: 'Central intake receives and acknowledges the enquiry',
        Triage: 'Confirm safe contact, authority and accountable owner',
        Assessment: 'Understand support needs, preferences and suitability',
        'Funding assistance':
            'Prepare, apply and follow up with the right agency',
        'Ready for handover':
            'Review conversion requirements and receiving site',
        'On hold': 'Keep an owner and a scheduled review',
        Converted: 'Linked to the canonical supported-person record',
        Closed: 'Reason, person’s preference and history preserved',
    };
    return (
        <div className="stage-editor">
            {values.map((s, i) => (
                <div key={s} className="stage-editor-row">
                    <span className="stage-number">
                        {String(i + 1).padStart(2, '0')}
                    </span>
                    <div>
                        <strong>{s}</strong>
                        <p className="text-caption">
                            {hints[s] ||
                                'Additional step configured for your team'}
                        </p>
                    </div>
                    {stages.includes(s) ? (
                        <LockKeyhole aria-label="Protected lifecycle stage" />
                    ) : (
                        <Button
                            variant="ghost"
                            aria-label={'Remove ' + s + ' stage'}
                            onClick={() =>
                                onChange(values.filter((v) => v !== s))
                            }
                        >
                            <X />
                        </Button>
                    )}
                </div>
            ))}
        </div>
    );
}
export function PermissionGroups({ role, values, onChange }) {
    const groups = [
        [
            'Everyday referral work',
            [
                'view',
                'create',
                'edit',
                'assign',
                'contact',
                'tasks',
                'transition',
                'close',
                'reopen',
                'duplicates',
            ],
        ],
        [
            'Funding & handover',
            [
                'sensitive',
                'fundingRead',
                'funding',
                'verify',
                'convert',
                'activate',
                'personRead',
            ],
        ],
        [
            'Information & configuration',
            [
                'disclose',
                'documents',
                'reports',
                'export',
                'audit',
                'settingsDraft',
                'settings',
                'governance',
            ],
        ],
        ['Record scope', ['central', 'allSites', 'restricted']],
    ];
    return (
        <div className="permission-groups">
            <div className="permission-summary">
                <Users />
                <div>
                    <strong>{role}</strong>
                    <p className="text-subtle">
                        {Object.values(values).filter(Boolean).length}{' '}
                        permissions enabled. Record scope still applies to every
                        action.
                    </p>
                </div>
            </div>
            {groups.map(([title, keys]) => (
                <div className="permission-group" key={title}>
                    <h3 className="text-section-title">{title}</h3>
                    {keys.map((key) => (
                        <div className="permission-control" key={key}>
                            <span>{permissions[key]}</span>
                            <span className="permission-state">
                                {values[key] ? 'On' : 'Off'}
                            </span>
                            <Switch
                                aria-label={permissions[key]}
                                checked={values[key]}
                                onCheckedChange={(v) => onChange(key, v)}
                            />
                        </div>
                    ))}
                </div>
            ))}
            <Notice>
                Grants are combined: referral actions also need View referrals.
                Funding work needs financial visibility. Clinical information
                has its own permission. Site, assignment and privacy
                restrictions still apply.
            </Notice>
        </div>
    );
}
