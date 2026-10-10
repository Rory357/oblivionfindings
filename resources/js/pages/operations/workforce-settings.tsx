import { TierTwoTabs } from '@/components/page/grouped-profile-nav';
import {
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    PageHeaderSearch,
} from '@/components/page/page-header';
import {
    WorkforceEligibilityRules,
    type EligibilityRules,
} from '@/components/rostering/workforce-eligibility-rules';
import {
    isRevision,
    object,
    useSettingsCommand,
} from '@/components/rostering/workforce-settings-outcome';
import {
    WorkforceStaffingRules,
    type StaffingRules,
} from '@/components/rostering/workforce-staffing-rules';
import { SettingsModal } from '@/components/settings/settings-modal';
import { SettingsNotice } from '@/components/settings/settings-notice';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { WorkforcePageHeader } from '@/components/workforce/workforce-page-header';
import AppLayout from '@/layouts/app-layout';
import type { SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Bell,
    CalendarDays,
    Check,
    ExternalLink,
    Loader2,
    Settings,
    ShieldCheck,
    Users,
} from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';

type Preferences = {
    default_tab: 'shifts' | 'calendar';
    roster_view: 'grid' | 'list';
    revision: string;
};
type Props = {
    preferences: Preferences;
    staffingRules?: StaffingRules;
    eligibilityRules?: EligibilityRules;
    workforceSettings: {
        worker_timezone: string;
        week_starts_on: string;
        fatigue: {
            max_hours_per_day: number;
            max_hours_per_week: number;
            warning_threshold_weekly: number;
            min_rest_between_shifts_hours: number;
            max_consecutive_days: number;
        };
        features: { publish: boolean; auto_schedule: boolean };
        policies?: { key: string; label: string; description: string }[];
    };
    ownerLinks?: { label: string; href: string; description: string }[];
};
const views = [
    { key: 'roster', label: 'Roster', icon: CalendarDays },
    { key: 'safety', label: 'Safety & eligibility', icon: ShieldCheck },
    { key: 'notifications', label: 'Notifications', icon: Bell },
    { key: 'connections', label: 'Connected modules', icon: Users },
];
const sections: Record<string, { key: string; label: string }[]> = {
    roster: [
        { key: 'preferences', label: 'My preferences' },
        { key: 'publication', label: 'Publication & scheduling' },
    ],
    safety: [
        { key: 'fatigue', label: 'Hours & rest' },
        { key: 'checks', label: 'Eligibility checks' },
    ],
    notifications: [{ key: 'delivery', label: 'Delivery preferences' }],
    connections: [{ key: 'owners', label: 'Where rules are managed' }],
};
function locationView() {
    const [candidate, subsection] = (
        typeof window === 'undefined' ? '' : window.location.hash.slice(1)
    ).split('/');
    const view = views.some((item) => item.key === candidate)
        ? candidate
        : 'roster';
    return {
        view,
        section: sections[view].some((item) => item.key === subsection)
            ? subsection
            : sections[view][0].key,
    };
}
function Group({
    title,
    caption,
    children,
}: {
    title: string;
    caption: string;
    children: ReactNode;
}) {
    return (
        <Card className="gap-0 overflow-hidden p-0">
            <div className="border-b p-4">
                <h2 className="text-sm font-semibold">{title}</h2>
                <p className="text-caption mt-1">{caption}</p>
            </div>
            <div className="divide-y divide-border">{children}</div>
        </Card>
    );
}
function Row({
    id,
    label,
    hint,
    control,
    changed,
    query,
}: {
    id?: string;
    label: string;
    hint: string;
    control: ReactNode;
    changed?: boolean;
    query: string;
}) {
    if (
        query &&
        !`${label} ${hint}`.toLowerCase().includes(query.toLowerCase())
    )
        return null;
    return (
        <div
            className="flex flex-col gap-4 px-4 py-4 sm:flex-row sm:items-start sm:justify-between"
            data-setting={id ?? label}
        >
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    <Label htmlFor={id}>{label}</Label>
                    {changed && (
                        <StatusBadge variant="info" size="sm">
                            Changed — not saved
                        </StatusBadge>
                    )}
                </div>
                <p className="text-caption mt-1">{hint}</p>
            </div>
            <div className="w-full min-w-0 sm:w-auto sm:shrink-0 [&_button]:max-w-full">
                {control}
            </div>
        </div>
    );
}
const choiceLabel = (key: keyof Preferences, value: string) =>
    key === 'default_tab'
        ? value === 'calendar'
            ? 'Calendar'
            : 'Shifts'
        : value === 'list'
          ? 'List'
          : 'Week grid';

export default function WorkforceSettings(props: Props) {
    const actorId = usePage<SharedData>().props.auth.user.id;
    return <WorkforceSettingsBody key={actorId} {...props} actorId={actorId} />;
}

function WorkforceSettingsBody({
    actorId,
    preferences,
    staffingRules,
    eligibilityRules,
    workforceSettings: settings,
    ownerLinks = [],
}: Props & { actorId: number }) {
    const [eligibilityDirty, setEligibilityDirty] = useState(false);
    const [eligibilityUncertain, setEligibilityUncertain] = useState(false);
    const [staffingDirty, setStaffingDirty] = useState(false);
    const [staffingUncertain, setStaffingUncertain] = useState(false);
    const [saved, setSaved] = useState(preferences);
    const [draft, setDraft] = useState(preferences);
    const [location, setLocation] = useState(locationView);
    const [query, setQuery] = useState('');
    const [reviewing, setReviewing] = useState(false);
    const command = useSettingsCommand(actorId);
    const { processing, uncertain, locked, error, setError } = command;
    const [message, setMessage] = useState<string | null>(null);
    const [recovery, setRecovery] = useState<string | null>(null);
    const [leaving, setLeaving] = useState<string | null>(null);
    const allowLeave = useRef(false);
    const changed = (['default_tab', 'roster_view'] as const).filter(
        (key) => draft[key] !== saved[key],
    );
    const dirty = changed.length > 0;
    const anyDirty =
        dirty || staffingDirty || eligibilityDirty || uncertain || processing;
    const select = (view: string, section = sections[view][0].key) => {
        setLocation({ view, section });
        setQuery('');
        window.history.replaceState(
            window.history.state,
            '',
            `#${view}/${section}`,
        );
    };
    useEffect(() => {
        const update = () => setLocation(locationView());
        window.addEventListener('hashchange', update);
        return () => window.removeEventListener('hashchange', update);
    }, []);
    useEffect(() => {
        const unload = (event: BeforeUnloadEvent) => {
            if (anyDirty && !allowLeave.current) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        window.addEventListener('beforeunload', unload);
        const remove = router.on('before', (event) => {
            if (
                anyDirty &&
                !allowLeave.current &&
                event.detail.visit.method === 'get' &&
                !event.detail.visit.only.length
            ) {
                event.preventDefault();
                setLeaving(event.detail.visit.url.toString());
            }
        });
        return () => {
            window.removeEventListener('beforeunload', unload);
            remove();
        };
    }, [anyDirty]);
    const checkCurrent = () =>
        command.check(
            ['preferences'],
            (props) => {
                const latest = object(props.preferences);
                return latest &&
                    isRevision(latest.revision) &&
                    ['shifts', 'calendar'].includes(
                        String(latest.default_tab),
                    ) &&
                    ['grid', 'list'].includes(String(latest.roster_view))
                    ? (latest as Preferences)
                    : null;
            },
            (latest) => {
                setSaved(latest);
                setReviewing(false);
                setMessage(null);
                setRecovery(
                    'Current preferences loaded. Your choices are retained; compare them before saving. This read does not confirm the earlier save.',
                );
            },
        );
    const save = () => {
        if (!dirty || locked) return;
        const values = {
            default_tab: draft.default_tab,
            roster_view: draft.roster_view,
        };
        command.run(
            {
                action: 'preferences',
                actor_id: actorId,
                expected_revision: saved.revision,
                values,
            },
            (options) =>
                router.patch(
                    '/operations/workforce-settings',
                    { ...values, expected_revision: saved.revision },
                    options,
                ),
            (receipt) => {
                const confirmed = { ...values, revision: receipt.revision };
                setSaved(confirmed);
                setDraft(confirmed);
                setReviewing(false);
                setRecovery(null);
                setMessage(
                    'Your roster preferences are saved. They apply when you next open Rostering.',
                );
            },
        );
    };
    const active = (enabled: boolean) => (
        <StatusBadge variant={enabled ? 'success' : 'neutral'}>
            {enabled ? 'On' : 'Off'}
        </StatusBadge>
    );
    const value = (number: number, unit: string) => (
        <span className="text-sm font-semibold tabular-nums">
            {number} {unit}
        </span>
    );
    const { view, section } = location;
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Workforce', href: '/operations/rostering' },
                { title: 'Settings', href: '/operations/workforce-settings' },
            ]}
        >
            <Head title="Workforce settings" />
            <div className="space-y-5">
                <WorkforcePageHeader
                    icon={Settings}
                    title="Workforce settings"
                    subline="Roster preferences, active safety rules and connected settings"
                    actions={
                        <>
                            <PageHeaderSearch
                                value={query}
                                onChange={setQuery}
                                placeholder="Find a setting in this section"
                            />
                            <PageHeaderGlassButton
                                icon={CalendarDays}
                                onClick={() =>
                                    router.visit('/operations/rostering')
                                }
                            >
                                Open roster
                            </PageHeaderGlassButton>
                        </>
                    }
                    meters={
                        <>
                            <PageHeaderMeterBlock
                                label="Time zone"
                                onClick={() => select('roster', 'publication')}
                            >
                                <PageHeaderMeterBig>
                                    {settings.worker_timezone
                                        .split('/')
                                        .pop()
                                        ?.replaceAll('_', ' ')}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    used for roster dates and times
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Roster week"
                                onClick={() => select('roster', 'publication')}
                            >
                                <PageHeaderMeterBig>
                                    {settings.week_starts_on}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    first day of the roster week
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Rest between shifts"
                                onClick={() => select('safety', 'fatigue')}
                            >
                                <PageHeaderMeterBig>
                                    {
                                        settings.fatigue
                                            .min_rest_between_shifts_hours
                                    }{' '}
                                    hr
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    configured scheduling threshold
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Your changes"
                                onClick={() => select('roster')}
                            >
                                <PageHeaderMeterBig>
                                    {changed.length}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    {dirty
                                        ? 'ready to review before saving'
                                        : 'no unsaved preferences'}
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        </>
                    }
                    rail={
                        <PageHeaderRail
                            items={views}
                            value={view}
                            onSelect={(key) => select(key)}
                            ariaLabel="Workforce settings views"
                        />
                    }
                />
                <TierTwoTabs
                    tabs={sections[view].map((tab) => ({
                        ...tab,
                        icon: views.find((item) => item.key === view)!.icon,
                    }))}
                    activeTab={section}
                    onTab={(key) => select(view, key)}
                    testIdPrefix="workforce-settings"
                    ariaLabel="Workforce settings sections"
                    renderLink={(tab, className, inner, accessibility) => (
                        <Button
                            key={tab.key}
                            variant="ghost"
                            className={className}
                            {...accessibility}
                            onClick={() => select(view, tab.key)}
                        >
                            {inner}
                        </Button>
                    )}
                />
                {message && (
                    <div
                        role="status"
                        className="flex items-center gap-2 rounded-xl border border-status-success/30 bg-status-success-bg p-3 text-sm text-status-success"
                    >
                        <Check className="size-4" />
                        {message}
                    </div>
                )}
                {recovery && (
                    <div role="status">
                        <SettingsNotice role="note">{recovery}</SettingsNotice>
                    </div>
                )}
                {error && (
                    <SettingsNotice role="alert">
                        {error}{' '}
                        <Button
                            variant="link"
                            onClick={checkCurrent}
                            disabled={processing}
                        >
                            Check current preferences
                        </Button>
                    </SettingsNotice>
                )}
                <div className="space-y-5">
                    {section === 'preferences' && (
                        <Group
                            title="How your roster opens"
                            caption="Saved for your account. Shared links and an explicitly selected tab take priority."
                        >
                            <Row
                                query={query}
                                id="workforce-default-tab"
                                label="Starting view"
                                hint="Choose where Rostering opens when you follow the left navigation."
                                changed={
                                    draft.default_tab !== saved.default_tab
                                }
                                control={
                                    <Select
                                        disabled={locked}
                                        value={draft.default_tab}
                                        onValueChange={(
                                            default_tab: Preferences['default_tab'],
                                        ) => {
                                            setDraft((current) => ({
                                                ...current,
                                                default_tab,
                                            }));
                                            setMessage(null);
                                        }}
                                    >
                                        <SelectTrigger
                                            id="workforce-default-tab"
                                            className="w-48"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="shifts">
                                                Shifts
                                            </SelectItem>
                                            <SelectItem value="calendar">
                                                Calendar
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                }
                            />
                            <Row
                                query={query}
                                id="workforce-roster-view"
                                label="Roster layout"
                                hint="Choose the initial layout inside the Shifts view in Rostering. You can still switch between week, day and list while working."
                                changed={
                                    draft.roster_view !== saved.roster_view
                                }
                                control={
                                    <Select
                                        disabled={locked}
                                        value={draft.roster_view}
                                        onValueChange={(
                                            roster_view: Preferences['roster_view'],
                                        ) => {
                                            setDraft((current) => ({
                                                ...current,
                                                roster_view,
                                            }));
                                            setMessage(null);
                                        }}
                                    >
                                        <SelectTrigger
                                            id="workforce-roster-view"
                                            className="w-48"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="grid">
                                                Week grid
                                            </SelectItem>
                                            <SelectItem value="list">
                                                List
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                }
                            />
                        </Group>
                    )}
                    {section === 'publication' && (
                        <>
                            <SettingsNotice role="note">
                                These are the active application settings. Your
                                personal preferences do not change publication
                                permissions or staffing rules.
                            </SettingsNotice>
                            <Group
                                title="Publication & scheduling"
                                caption="Current configuration used by Workforce."
                            >
                                <Row
                                    query={query}
                                    label="Roster publication workflow"
                                    hint="Controls availability of roster-period review and publication."
                                    control={active(settings.features.publish)}
                                />
                                <Row
                                    query={query}
                                    label="Scheduling suggestions"
                                    hint="Suggestions remain subject to staff eligibility and your permissions."
                                    control={active(
                                        settings.features.auto_schedule,
                                    )}
                                />
                                <Row
                                    query={query}
                                    label="Worker time zone"
                                    hint="Dates and local shift times are interpreted in this zone."
                                    control={
                                        <span className="text-sm font-semibold">
                                            {settings.worker_timezone}
                                        </span>
                                    }
                                />
                                <Row
                                    query={query}
                                    label="First day of the week"
                                    hint="The roster week runs from this day."
                                    control={
                                        <span className="text-sm font-semibold">
                                            {settings.week_starts_on}
                                        </span>
                                    }
                                />
                            </Group>
                        </>
                    )}
                    {staffingRules ? (
                        <WorkforceStaffingRules
                            actorId={actorId}
                            rules={staffingRules}
                            visible={section === 'fatigue'}
                            query={query}
                            onDirtyChange={setStaffingDirty}
                            onUncertainChange={setStaffingUncertain}
                            onShow={() => select('safety', 'fatigue')}
                        />
                    ) : null}
                    {!staffingRules && section === 'fatigue' && (
                        <>
                            <SettingsNotice role="note">
                                These are the configured scheduling thresholds.
                                They are shown here for visibility; changing a
                                personal view does not alter them.
                            </SettingsNotice>
                            <Group
                                title="Hours & rest"
                                caption="Active thresholds shared with HR fatigue checks."
                            >
                                <Row
                                    query={query}
                                    label="Daily hours threshold"
                                    hint="Hours scheduled for one member of staff in a day."
                                    control={value(
                                        settings.fatigue.max_hours_per_day,
                                        'hours',
                                    )}
                                />
                                <Row
                                    query={query}
                                    label="Weekly warning threshold"
                                    hint="Highlights staff approaching the configured weekly maximum."
                                    control={value(
                                        settings.fatigue
                                            .warning_threshold_weekly,
                                        'hours',
                                    )}
                                />
                                <Row
                                    query={query}
                                    label="Weekly hours threshold"
                                    hint="Maximum hours threshold used in scheduling checks."
                                    control={value(
                                        settings.fatigue.max_hours_per_week,
                                        'hours',
                                    )}
                                />
                                <Row
                                    query={query}
                                    label="Minimum rest between shifts"
                                    hint="Checks the gap between the end of one shift and the start of the next."
                                    control={value(
                                        settings.fatigue
                                            .min_rest_between_shifts_hours,
                                        'hours',
                                    )}
                                />
                                <Row
                                    query={query}
                                    label="Consecutive working days"
                                    hint="Threshold for consecutive days with work."
                                    control={value(
                                        settings.fatigue.max_consecutive_days,
                                        'days',
                                    )}
                                />
                            </Group>
                        </>
                    )}
                    {eligibilityRules ? (
                        <WorkforceEligibilityRules
                            actorId={actorId}
                            rules={eligibilityRules}
                            visible={section === 'checks'}
                            query={query}
                            timezone={settings.worker_timezone}
                            onDirtyChange={setEligibilityDirty}
                            onUncertainChange={setEligibilityUncertain}
                            onShow={() => select('safety', 'checks')}
                        />
                    ) : null}
                    {section === 'checks' && (
                        <Group
                            title="Eligibility follows the duty"
                            caption="Requirements stay with the module responsible for their records."
                        >
                            {(settings.policies ?? []).map((policy) => (
                                <Row
                                    key={policy.key}
                                    query={query}
                                    label={policy.label}
                                    hint={policy.description}
                                    control={
                                        <StatusBadge variant="neutral">
                                            Shared rule
                                        </StatusBadge>
                                    }
                                />
                            ))}
                        </Group>
                    )}
                    {section === 'delivery' && (
                        <Group
                            title="Workforce notifications"
                            caption="Use your existing notification preferences so there is one saved choice per event."
                        >
                            <Row
                                query={query}
                                label="Notification preferences"
                                hint="Choose how optional shift, cover and roster notifications reach you. Required alerts keep their existing rules."
                                control={
                                    <Button asChild variant="outline">
                                        <Link href="/settings/notifications">
                                            Open preferences
                                            <ExternalLink className="ml-2 size-4" />
                                        </Link>
                                    </Button>
                                }
                            />
                            <Row
                                query={query}
                                label="Available-shift alerts"
                                hint="Your job board controls alerts for matching open positions."
                                control={
                                    <Button asChild variant="outline">
                                        <Link href="/operations/job-board">
                                            Open job board
                                            <ExternalLink className="ml-2 size-4" />
                                        </Link>
                                    </Button>
                                }
                            />
                        </Group>
                    )}
                    {section === 'owners' && (
                        <>
                            <SettingsNotice role="note">
                                Rostering uses records from across supported
                                living. Control Room keeps its own live duty
                                sessions, handovers, alerts and operational
                                settings.
                            </SettingsNotice>
                            <Group
                                title="Manage rules at their source"
                                caption="Only destinations available to your account are shown."
                            >
                                {ownerLinks.map((owner) => (
                                    <Row
                                        key={owner.href}
                                        query={query}
                                        label={owner.label}
                                        hint={owner.description}
                                        control={
                                            <Button asChild variant="outline">
                                                <Link href={owner.href}>
                                                    Open
                                                    <ExternalLink className="ml-2 size-4" />
                                                </Link>
                                            </Button>
                                        }
                                    />
                                ))}
                            </Group>
                        </>
                    )}
                    {query && (
                        <p className="text-caption">
                            Showing settings that match “{query}” in this
                            section.{' '}
                            <Button
                                variant="link"
                                size="sm"
                                onClick={() => setQuery('')}
                            >
                                Clear search
                            </Button>
                        </p>
                    )}
                </div>
                {(section === 'preferences' ||
                    (dirty && section !== 'fatigue')) && (
                    <Card className="sticky bottom-3 z-20 flex-row flex-wrap items-center justify-between gap-4 p-4 shadow-md">
                        <div>
                            <p className="text-sm font-semibold">
                                {dirty
                                    ? `${changed.length} ${changed.length === 1 ? 'change' : 'changes'} to review`
                                    : 'No unsaved changes'}
                            </p>
                            <p className="text-caption mt-1">
                                Your choices apply only after you review and
                                save.
                            </p>
                        </div>
                        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
                            <Button
                                variant="outline"
                                disabled={!dirty || locked}
                                onClick={() => {
                                    setDraft(saved);
                                    setError(null);
                                }}
                            >
                                Discard changes
                            </Button>
                            <Button
                                disabled={!dirty || locked}
                                onClick={() => setReviewing(true)}
                            >
                                Review changes
                            </Button>
                        </div>
                    </Card>
                )}
            </div>
            {reviewing && (
                <SettingsModal
                    title="Review your roster preferences"
                    description="These changes affect how your own roster opens. They do not change staff assignments or safety rules."
                    onClose={() => {
                        if (!command.pending.current) setReviewing(false);
                    }}
                    footer={
                        <>
                            <Button
                                variant="outline"
                                disabled={processing}
                                onClick={() => setReviewing(false)}
                            >
                                Keep editing
                            </Button>
                            <Button disabled={locked || !dirty} onClick={save}>
                                {processing && (
                                    <Loader2 className="mr-2 size-4 animate-spin" />
                                )}
                                Save preferences
                            </Button>
                        </>
                    }
                >
                    {changed.map((key) => (
                        <div key={key} className="rounded-lg border p-3">
                            <p className="text-sm font-semibold">
                                {key === 'default_tab'
                                    ? 'Starting view'
                                    : 'Roster layout'}
                            </p>
                            <p className="text-caption mt-1">
                                {choiceLabel(key, saved[key])} →{' '}
                                {choiceLabel(key, draft[key])}
                            </p>
                        </div>
                    ))}
                    {error && (
                        <SettingsNotice role="alert">
                            {error}{' '}
                            <Button
                                variant="link"
                                disabled={processing}
                                onClick={checkCurrent}
                            >
                                Check current preferences
                            </Button>
                        </SettingsNotice>
                    )}
                </SettingsModal>
            )}
            {leaving && (
                <SettingsModal
                    title={
                        uncertain || staffingUncertain || eligibilityUncertain
                            ? 'Leave with an unconfirmed save?'
                            : 'Leave without saving?'
                    }
                    description={
                        uncertain ||
                        staffingUncertain ||
                        eligibilityUncertain ||
                        processing
                            ? 'A save is unconfirmed. Leaving loses these entries; check current settings before making the same change again.'
                            : 'Your Workforce settings have unsaved changes.'
                    }
                    onClose={() => setLeaving(null)}
                    footer={
                        <>
                            <Button
                                variant="outline"
                                onClick={() => setLeaving(null)}
                            >
                                Keep editing
                            </Button>
                            <Button
                                onClick={() => {
                                    allowLeave.current = true;
                                    router.visit(leaving);
                                }}
                            >
                                {uncertain ||
                                staffingUncertain ||
                                eligibilityUncertain ||
                                processing
                                    ? 'Leave anyway'
                                    : 'Discard and leave'}
                            </Button>
                        </>
                    }
                >
                    <p className="text-body">
                        {uncertain ||
                        staffingUncertain ||
                        eligibilityUncertain ||
                        processing
                            ? 'Keep editing to check the current settings and retain your entries.'
                            : 'Review and save first if you want to keep these changes.'}
                    </p>
                </SettingsModal>
            )}
        </AppLayout>
    );
}
