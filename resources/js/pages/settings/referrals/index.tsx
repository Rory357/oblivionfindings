/* Settings › Referral settings. The Fleet Settings workspace pattern
 * (pages/fleet-assets/settings): one PageHeader with a short rail
 * (Overview / Intake / Workflow / Funding / Access / History), tier-2
 * sections that each open on an Overview of ReviewCards, titled groups of
 * compact rows, a sticky save bar with "Review changes", an unsaved-draft
 * guard, and two-person publication with a required publication note. */
import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { AccessSettings } from '@/components/referrals/settings-access';
import { FundingSettings } from '@/components/referrals/settings-funding';
import {
    DiscardDraftDialog,
    HistorySettings,
    RestoreDialog,
} from '@/components/referrals/settings-history';
import { IntakeSettings } from '@/components/referrals/settings-intake';
import {
    PublishWizard,
    SaveDraftWizard,
    type PreviewState,
} from '@/components/referrals/settings-review';
import {
    configurationChanges,
    settingAreas,
    type ConfigurationVersion,
    type ReferralConfiguration,
    type ReferralSettingsPage,
    type SettingArea,
    type SettingsImpactSummary,
    type SettingTab,
} from '@/components/referrals/settings-types';
import { OverviewGrid, SaveBar } from '@/components/referrals/settings-ui';
import { WorkflowSettings } from '@/components/referrals/settings-workflow-area';
import { SettingsNotice } from '@/components/settings/settings-notice';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { formatDateTimeLong } from '@/lib/datetime';
import { Sections } from '@/pages/fleet-assets/settings/_ui';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import {
    ArrowLeft,
    Bell,
    Globe,
    History,
    LockKeyhole,
    Mail,
    Route,
    Settings2,
    ShieldCheck,
    Wallet,
    Workflow,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

const areaItems: { key: SettingArea; label: string; icon: typeof Globe }[] = [
    { key: 'overview', label: 'Overview', icon: Settings2 },
    { key: 'intake', label: 'Intake', icon: Globe },
    { key: 'workflow', label: 'Workflow', icon: Workflow },
    { key: 'funding', label: 'Funding', icon: Wallet },
    { key: 'access', label: 'Access', icon: ShieldCheck },
    { key: 'history', label: 'History', icon: History },
];
const areaTabs: Partial<
    Record<
        SettingArea,
        { key: SettingTab; label: string; icon: typeof Globe }[]
    >
> = {
    intake: [
        { key: 'overview', label: 'Overview', icon: Settings2 },
        { key: 'configuration', label: 'Configuration', icon: Globe },
    ],
    workflow: [
        { key: 'overview', label: 'Overview', icon: Settings2 },
        { key: 'configuration', label: 'Routing & stages', icon: Route },
        { key: 'notifications', label: 'Notifications', icon: Bell },
        { key: 'delivery', label: 'Delivery & channels', icon: Mail },
    ],
    funding: [
        { key: 'overview', label: 'Overview', icon: Settings2 },
        { key: 'configuration', label: 'Configuration', icon: Wallet },
    ],
    access: [
        { key: 'overview', label: 'Overview', icon: Settings2 },
        { key: 'configuration', label: 'Role permissions', icon: ShieldCheck },
        { key: 'scope', label: 'Record scope', icon: LockKeyhole },
    ],
};

function readHash(): { area: SettingArea; tab: SettingTab } {
    const [area, tab] = (
        typeof window === 'undefined' ? '' : window.location.hash.slice(1)
    ).split('/');
    const found = settingAreas.find((key) => key === area) ?? 'overview';
    const tabs = areaTabs[found]?.map((item) => item.key) ?? [];
    return {
        area: found,
        tab: tabs.find((key) => key === tab) ?? 'overview',
    };
}

/** Values people type freely are tidied once, before anything is sent. */
function normalise(configuration: ReferralConfiguration) {
    const copy = structuredClone(configuration);
    copy.business_calendar.holidays = copy.business_calendar.holidays
        .map((line) => line.trim())
        .filter(Boolean);
    copy.pathways.forEach((pathway) => {
        pathway.evidence_checklist = pathway.evidence_checklist
            .map((line) => line.trim())
            .filter(Boolean);
    });
    copy.routing?.rules.forEach((rule) => {
        rule.regions = rule.regions
            .map((region) => region.trim())
            .filter(Boolean);
    });
    return copy;
}

export default function ReferralsSettings({
    settings,
}: {
    settings: ReferralSettingsPage;
}) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Settings', href: '/settings/profile' },
                { title: 'Referral settings', href: '/settings/referrals' },
            ]}
        >
            <Head title="Referral settings" />
            <SettingsLayout>
                <SettingsWorkspace
                    key={`${settings.selected?.id ?? 'new'}:${settings.latest?.id ?? 'none'}`}
                    settings={settings}
                />
            </SettingsLayout>
        </AppLayout>
    );
}

type Command = 'draft' | 'publish';

function SettingsWorkspace({ settings }: { settings: ReferralSettingsPage }) {
    const initial = settings.selected?.configuration ?? settings.configuration;
    const [{ area, tab }, setView] = useState(readHash);
    const [configuration, setConfiguration] = useState(initial);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [fieldErrors, setFieldErrors] = useState<string[]>([]);
    const [recovering, setRecovering] = useState<Command | null>(null);
    const [conflict, setConflict] = useState(false);
    const [denied, setDenied] = useState(false);
    const [reviewing, setReviewing] = useState(false);
    const [preview, setPreview] = useState<PreviewState>({ state: 'loading' });
    const [publishing, setPublishing] = useState(false);
    const [discardingLocal, setDiscardingLocal] = useState(false);
    const [discardDraft, setDiscardDraft] =
        useState<ConfigurationVersion | null>(null);
    const [restoreFrom, setRestoreFrom] = useState<ConfigurationVersion | null>(
        null,
    );
    const draftRequest = useRef<Record<string, unknown> | null>(null);
    const publication = useRef<Record<string, unknown> | null>(null);
    const pending = useRef(false);
    const errorRef = useRef<HTMLDivElement>(null);

    const selected = settings.selected;
    const viewingDraft = selected?.state === 'draft';
    const viewingHistory =
        !!selected &&
        (selected.state === 'discarded' ||
            (selected.state === 'published' &&
                selected.id !== settings.latest?.id));
    const unsaved = configurationChanges(
        initial,
        configuration,
        settings.roles,
        settings.service_types,
    );
    const toPublish = configurationChanges(
        settings.configuration,
        configuration,
        settings.roles,
        settings.service_types,
    );
    const dirty =
        !denied && (unsaved.length > 0 || recovering !== null || busy);
    const leave = useSettingsLeaveConfirmation(
        dirty,
        'Leave these referral settings?',
        'Unsaved changes will be lost. If a save could not be confirmed, stay here and retry the same request before starting another.',
        undefined,
        busy,
    );
    const readOnly = !settings.can_manage || viewingHistory || busy || conflict;
    const stale =
        viewingDraft &&
        selected.base_version_id !== (settings.latest?.id ?? null);
    const mayPublish =
        viewingDraft &&
        settings.can_publish &&
        selected.created_by !== settings.actor_id &&
        !stale &&
        (!selected.governance_required || settings.can_govern);
    const waiting = (settings.pending_drafts ?? []).filter(
        (draft) =>
            draft.base_version_id === (settings.latest?.id ?? null) &&
            draft.id !== selected?.id,
    );

    useEffect(() => {
        const changed = () => setView(readHash());
        window.addEventListener('hashchange', changed);
        return () => window.removeEventListener('hashchange', changed);
    }, []);
    useEffect(() => {
        if (error || fieldErrors.length) errorRef.current?.focus();
    }, [error, fieldErrors]);

    const jump = (next: SettingArea, nextTab: SettingTab = 'overview') => {
        setView({ area: next, tab: nextTab });
        window.history.replaceState(
            window.history.state,
            '',
            `${window.location.pathname}${window.location.search}#${next}${nextTab === 'overview' ? '' : `/${nextTab}`}`,
        );
    };

    async function loadPreview() {
        setPreview({ state: 'loading' });
        try {
            const { data } = await axios.post<{
                governance_required: boolean;
                impact: SettingsImpactSummary;
            }>('/settings/referrals/preview', {
                configuration: normalise(configuration),
            });
            setPreview({ state: 'ready', ...data });
        } catch (failure) {
            const status = axios.isAxiosError(failure)
                ? failure.response?.status
                : null;
            const data = axios.isAxiosError(failure)
                ? (failure.response?.data as {
                      message?: string;
                      errors?: Record<string, string[]>;
                  })
                : undefined;
            if (status === 422) {
                setReviewing(false);
                setFieldErrors(Object.values(data?.errors ?? {}).flat());
                setError(data?.message ?? 'Review the highlighted settings.');
            } else if (status === 401 || status === 403) {
                setReviewing(false);
                setDenied(true);
            } else
                setPreview({
                    state: 'error',
                    message:
                        'Check your connection and try again. Nothing has been saved.',
                });
        }
    }

    async function command(kind: Command, input: Record<string, string>) {
        if (
            pending.current ||
            denied ||
            conflict ||
            (recovering && recovering !== kind)
        )
            return;
        pending.current = true;
        setBusy(true);
        setError('');
        setFieldErrors([]);
        const payload =
            kind === 'draft'
                ? (draftRequest.current ??= {
                      configuration: normalise(configuration),
                      base_version_id: settings.latest?.id ?? null,
                      reason: input.reason,
                      request_key: crypto.randomUUID(),
                  })
                : (publication.current ??= {
                      effective_at: input.effective || new Date().toISOString(),
                      acknowledge_impact: true,
                      publication_note: input.note,
                  });
        try {
            const response = await axios.post<{ version: { id: number } }>(
                kind === 'draft'
                    ? '/settings/referrals/drafts'
                    : `/settings/referrals/${selected?.id}/publish`,
                payload,
            );
            if (!response.data.version?.id)
                throw new Error('Saved version could not be confirmed');
            setReviewing(false);
            setPublishing(false);
            setRecovering(null);
            leave.preserveAndRun(() =>
                router.get(
                    '/settings/referrals',
                    { version: response.data.version.id },
                    { preserveState: false },
                ),
            );
        } catch (failure) {
            const status = axios.isAxiosError(failure)
                ? failure.response?.status
                : null;
            const data = axios.isAxiosError(failure)
                ? (failure.response?.data as {
                      message?: string;
                      errors?: Record<string, string[]>;
                  })
                : undefined;
            if (status === 403 || status === 401 || status === 404) {
                setDenied(true);
                setReviewing(false);
                setPublishing(false);
            } else if (status === 409) {
                setConflict(true);
                setReviewing(false);
                setPublishing(false);
                setError(
                    data?.message ??
                        'Settings changed. Review the latest publication before continuing.',
                );
            } else if (status === 422) {
                draftRequest.current = null;
                publication.current = null;
                setRecovering(null);
                setFieldErrors(Object.values(data?.errors ?? {}).flat());
                setError(data?.message ?? 'Review the highlighted settings.');
                setReviewing(false);
                setPublishing(false);
            } else {
                setRecovering(kind);
                setError(
                    'The result could not be confirmed. Your request is kept exactly as sent. Retry to recover the saved version.',
                );
            }
        } finally {
            pending.current = false;
            setBusy(false);
        }
    }

    if (denied)
        return (
            <Alert variant="destructive">
                <AlertTitle>Settings are no longer available</AlertTitle>
                <AlertDescription>
                    Your access changed. Reload the page to check current
                    access.
                </AlertDescription>
                <Button asChild variant="outline" className="mt-3">
                    <Link href="/settings/referrals">Check current access</Link>
                </Button>
            </Alert>
        );

    const enabledServices = configuration.services.filter(
        (service) => service.enabled,
    ).length;
    const enabledPathways = configuration.pathways.filter(
        (pathway) => pathway.enabled,
    ).length;
    const impactForLatest =
        settings.impact && settings.impact.version_id === settings.latest?.id
            ? settings.impact
            : null;
    const chip =
        unsaved.length > 0 ? (
            <PageHeaderStatusChip variant="warning">
                Unsaved changes
            </PageHeaderStatusChip>
        ) : viewingDraft ? (
            <PageHeaderStatusChip variant="info">
                Draft version {selected.version}
            </PageHeaderStatusChip>
        ) : viewingHistory ? (
            <PageHeaderStatusChip variant="neutral">
                {selected.state === 'discarded'
                    ? `Discarded draft ${selected.version}`
                    : `Earlier version ${selected.version}`}
            </PageHeaderStatusChip>
        ) : settings.latest && settings.effective?.id !== settings.latest.id ? (
            <PageHeaderStatusChip variant="info">
                Version {settings.latest.version} starts{' '}
                {formatDateTimeLong(settings.latest.effective_at ?? '')}
            </PageHeaderStatusChip>
        ) : settings.latest ? (
            <PageHeaderStatusChip variant="success">
                Published version {settings.latest.version}
            </PageHeaderStatusChip>
        ) : (
            <PageHeaderStatusChip variant="warning">
                Not published yet
            </PageHeaderStatusChip>
        );
    const primary =
        unsaved.length > 0 || recovering === 'draft' ? (
            <PageHeaderPrimaryButton
                disabled={busy || conflict}
                onClick={() => {
                    setReviewing(true);
                    void loadPreview();
                }}
            >
                Review changes
            </PageHeaderPrimaryButton>
        ) : mayPublish ? (
            <PageHeaderPrimaryButton
                disabled={busy || conflict}
                onClick={() => setPublishing(true)}
            >
                Review and publish
            </PageHeaderPrimaryButton>
        ) : waiting.length > 0 ? (
            <PageHeaderPrimaryButton asChild>
                <Link
                    href={`/settings/referrals?version=${waiting[0].id}`}
                    preserveState={false}
                >
                    Review waiting draft
                </Link>
            </PageHeaderPrimaryButton>
        ) : settings.can_manage && !viewingHistory ? (
            <PageHeaderPrimaryButton disabled>
                Review changes
            </PageHeaderPrimaryButton>
        ) : null;
    const tabs = areaTabs[area];
    const areaProps = {
        value: configuration,
        onChange: setConfiguration,
        readOnly,
        settings,
        tab,
        jump,
    };
    const showSaveBar =
        settings.can_manage &&
        !viewingHistory &&
        (unsaved.length > 0 ||
            (area !== 'overview' &&
                area !== 'history' &&
                area !== 'access' &&
                tab !== 'overview'));

    return (
        <PageLayout
            hero={
                <PageHeader
                    icon={Settings2}
                    title="Referral settings"
                    titleChip={chip}
                    subline="One organisation · two people approve every change · open referrals keep their version"
                    mobileSummary={`In use: ${settings.effective ? `version ${settings.effective.version}` : 'nothing published'}`}
                    actions={
                        <>
                            <PageHeaderGlassButton asChild>
                                <Link href="/operations/referrals">
                                    <ArrowLeft className="size-4" />
                                    Referrals
                                </Link>
                            </PageHeaderGlassButton>
                            <PageHeaderGlassButton
                                icon={History}
                                onClick={() => jump('history')}
                            >
                                Changes
                            </PageHeaderGlassButton>
                            {primary}
                        </>
                    }
                    meters={
                        <>
                            <PageHeaderMeterBlock
                                label="In use"
                                onClick={() => jump('history')}
                            >
                                <PageHeaderMeterBig>
                                    {settings.effective
                                        ? `Version ${settings.effective.version}`
                                        : 'None'}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    {settings.effective?.effective_at
                                        ? `Since ${formatDateTimeLong(settings.effective.effective_at)}`
                                        : 'Website enquiries stay closed'}
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Waiting for review"
                                tone={
                                    (settings.pending_drafts ?? []).length
                                        ? 'warning'
                                        : 'brand'
                                }
                                onClick={() => jump('history')}
                            >
                                <PageHeaderMeterBig>
                                    {(settings.pending_drafts ?? []).length}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    Drafts a different person publishes
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Services offered"
                                value={`${enabledServices} of ${configuration.services.length}`}
                                onClick={() => jump('intake', 'configuration')}
                            >
                                <PageHeaderMeterBar
                                    percent={
                                        configuration.services.length
                                            ? (enabledServices /
                                                  configuration.services
                                                      .length) *
                                              100
                                            : 0
                                    }
                                />
                                <PageHeaderMeterCaption>
                                    On the public enquiry form
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Funding pathways"
                                value={`${enabledPathways} of ${configuration.pathways.length}`}
                                onClick={() => jump('funding', 'configuration')}
                            >
                                <PageHeaderMeterBar
                                    percent={
                                        configuration.pathways.length
                                            ? (enabledPathways /
                                                  configuration.pathways
                                                      .length) *
                                              100
                                            : 0
                                    }
                                />
                                <PageHeaderMeterCaption>
                                    Available for new funding cases
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            {impactForLatest && (
                                <PageHeaderMeterBlock
                                    label="On earlier versions"
                                    value={`${impactForLatest.retaining_another_version} of ${impactForLatest.open_referrals}`}
                                    onClick={() => jump('history')}
                                >
                                    <PageHeaderMeterBar
                                        percent={
                                            impactForLatest.open_referrals
                                                ? (impactForLatest.retaining_another_version /
                                                      impactForLatest.open_referrals) *
                                                  100
                                                : 0
                                        }
                                    />
                                    <PageHeaderMeterCaption>
                                        Open referrals you can see
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            )}
                        </>
                    }
                    rail={
                        <PageHeaderRail
                            items={areaItems}
                            value={area}
                            onSelect={(key) => jump(key)}
                            ariaLabel="Settings views"
                        />
                    }
                />
            }
            tabs={
                tabs ? (
                    <Sections
                        tabs={tabs}
                        value={tab}
                        onChange={(key) => jump(area, key as SettingTab)}
                    />
                ) : undefined
            }
        >
            <div className="space-y-5">
                {(error || fieldErrors.length > 0) && (
                    <div ref={errorRef} tabIndex={-1} className="outline-none">
                        <Alert variant="destructive">
                            <AlertTitle>
                                {error || 'Review these settings'}
                            </AlertTitle>
                            <AlertDescription>
                                <ul>
                                    {fieldErrors.map((message, index) => (
                                        <li key={index}>{message}</li>
                                    ))}
                                </ul>
                                {conflict && (
                                    <Button asChild variant="outline">
                                        <Link href="/settings/referrals">
                                            Review latest settings
                                        </Link>
                                    </Button>
                                )}
                                {recovering && (
                                    <Button
                                        variant="outline"
                                        disabled={busy}
                                        onClick={() => {
                                            if (recovering === 'publish') {
                                                setPublishing(true);
                                                return;
                                            }
                                            setReviewing(true);
                                            void loadPreview();
                                        }}
                                    >
                                        Retry the same request
                                    </Button>
                                )}
                            </AlertDescription>
                        </Alert>
                    </div>
                )}
                {viewingDraft && (
                    <SettingsNotice role="note">
                        <span className="space-y-2">
                            <span className="block">
                                <b>Draft version {selected.version}</b> · saved
                                by {selected.created_by_name ?? 'a colleague'}{' '}
                                on {formatDateTimeLong(selected.created_at)}.
                                Reason: {selected.reason}
                            </span>
                            <span className="block">
                                {stale
                                    ? 'A newer version was published after this draft, so it can’t be published. Start a new draft from the current settings.'
                                    : selected.created_by === settings.actor_id
                                      ? 'A different authorised person must publish your draft.'
                                      : selected.governance_required &&
                                          !settings.can_govern
                                        ? 'This draft changes safeguards. A person who can approve weaker safeguards must publish it.'
                                        : settings.can_publish
                                          ? 'Review what it changes, then publish it with a note.'
                                          : 'Someone with permission to publish settings reviews it next.'}
                            </span>
                            <span className="flex flex-wrap gap-2 pt-1">
                                {mayPublish && (
                                    <Button
                                        size="sm"
                                        disabled={busy || unsaved.length > 0}
                                        onClick={() => setPublishing(true)}
                                    >
                                        Review and publish
                                    </Button>
                                )}
                                {(settings.can_manage ||
                                    settings.can_publish) && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={busy}
                                        onClick={() =>
                                            setDiscardDraft(selected)
                                        }
                                    >
                                        Discard draft
                                    </Button>
                                )}
                                <Button size="sm" variant="outline" asChild>
                                    <Link
                                        href="/settings/referrals"
                                        preserveState={false}
                                    >
                                        Back to current settings
                                    </Link>
                                </Button>
                            </span>
                            {mayPublish && unsaved.length > 0 && (
                                <span className="text-caption block">
                                    Save or discard your own changes before
                                    publishing this draft.
                                </span>
                            )}
                        </span>
                    </SettingsNotice>
                )}
                {viewingHistory && (
                    <SettingsNotice role="note">
                        <span className="space-y-2">
                            <span className="block">
                                You’re looking at{' '}
                                {selected.state === 'discarded'
                                    ? 'discarded draft'
                                    : 'version'}{' '}
                                {selected.version} from{' '}
                                {formatDateTimeLong(
                                    selected.published_at ??
                                        selected.created_at,
                                )}
                                . It can’t be changed.
                            </span>
                            <span className="flex flex-wrap gap-2 pt-1">
                                <Button size="sm" variant="outline" asChild>
                                    <Link
                                        href="/settings/referrals"
                                        preserveState={false}
                                    >
                                        Back to current settings
                                    </Link>
                                </Button>
                                {settings.can_manage &&
                                    selected.state === 'published' && (
                                        <Button
                                            size="sm"
                                            onClick={() =>
                                                setRestoreFrom(selected)
                                            }
                                        >
                                            Restore into a new draft
                                        </Button>
                                    )}
                            </span>
                        </span>
                    </SettingsNotice>
                )}
                {!viewingDraft && !viewingHistory && waiting.length > 0 && (
                    <SettingsNotice role="note">
                        <span>
                            Draft version {waiting[0].version} is waiting for
                            review.{' '}
                            <Link
                                href={`/settings/referrals?version=${waiting[0].id}`}
                                preserveState={false}
                                className="font-semibold text-primary underline-offset-2 hover:underline"
                            >
                                Review the draft
                            </Link>
                        </span>
                    </SettingsNotice>
                )}
                {!settings.can_manage && !viewingHistory && (
                    <SettingsNotice role="note">
                        <span>
                            You can review these settings. Changing them needs
                            permission to draft referral settings.
                        </span>
                    </SettingsNotice>
                )}
                {area === 'overview' && (
                    <OverviewGrid
                        title="Overview"
                        caption="What each area controls. Open one to review or change it."
                        cards={[
                            {
                                key: 'intake',
                                icon: Globe,
                                title: 'Intake and forms',
                                rows: [
                                    [
                                        'Online enquiries',
                                        configuration.public.enabled
                                            ? 'On'
                                            : 'Off',
                                    ],
                                    [
                                        'Extra questions',
                                        `${(configuration.forms?.fields ?? []).filter((field) => field.enabled).length} in use`,
                                    ],
                                    [
                                        'Information notice',
                                        `Version ${configuration.public.notice_version}`,
                                    ],
                                ],
                                cta: 'Review intake and forms',
                                onReview: () => jump('intake', 'configuration'),
                            },
                            {
                                key: 'workflow',
                                icon: Route,
                                title: 'Routing and follow-up',
                                rows: [
                                    [
                                        'Receiving team',
                                        configuration.intake.team,
                                    ],
                                    [
                                        'First contact',
                                        `${configuration.intake.first_contact_business_days} working days`,
                                    ],
                                    [
                                        'Automatic assignment',
                                        configuration.routing?.enabled
                                            ? 'On'
                                            : 'Off',
                                    ],
                                ],
                                cta: 'Review routing and follow-up',
                                onReview: () =>
                                    jump('workflow', 'configuration'),
                            },
                            {
                                key: 'funding',
                                icon: Wallet,
                                title: 'Funding pathways',
                                rows: [
                                    [
                                        'With contact details',
                                        `${configuration.pathways.filter((pathway) => pathway.contact?.organisation || pathway.contact?.phone).length}`,
                                    ],
                                    [
                                        'Temporary company funding',
                                        configuration.proposals
                                            .temporary_funding_enabled
                                            ? 'On'
                                            : 'Off',
                                    ],
                                    ['Service-start checks', 'Always required'],
                                ],
                                cta: 'Review funding pathways',
                                onReview: () =>
                                    jump('funding', 'configuration'),
                            },
                            {
                                key: 'access',
                                icon: ShieldCheck,
                                title: 'Permissions and record scope',
                                rows: [
                                    [
                                        'Roles with referral access',
                                        `${(settings.role_grants ?? []).filter((role) => role.keys.length).length}`,
                                    ],
                                    [
                                        'Limits',
                                        'Role, site, assignment and privacy',
                                    ],
                                    [
                                        'Settings access',
                                        'Never opens referral records',
                                    ],
                                ],
                                cta: 'Review permissions and record scope',
                                onReview: () => jump('access', 'configuration'),
                            },
                        ]}
                    />
                )}
                {area === 'intake' && <IntakeSettings {...areaProps} />}
                {area === 'workflow' && <WorkflowSettings {...areaProps} />}
                {area === 'funding' && <FundingSettings {...areaProps} />}
                {area === 'access' && <AccessSettings {...areaProps} />}
                {area === 'history' && (
                    <HistorySettings
                        settings={settings}
                        onDiscard={setDiscardDraft}
                        onRestore={setRestoreFrom}
                    />
                )}
                {showSaveBar && (
                    <SaveBar
                        count={unsaved.length}
                        busy={busy || conflict}
                        onDiscard={() => setDiscardingLocal(true)}
                        onReview={() => {
                            setReviewing(true);
                            void loadPreview();
                        }}
                        note={
                            viewingDraft && unsaved.length
                                ? 'Saving makes a new draft from these values. The draft you opened stays waiting for review.'
                                : undefined
                        }
                    />
                )}
                {leave.confirmation}
                <ConfirmDialog
                    open={discardingLocal}
                    onClose={() => setDiscardingLocal(false)}
                    onConfirm={() => {
                        setConfiguration(initial);
                        setDiscardingLocal(false);
                    }}
                    variant="destructive"
                    title="Discard unsaved changes?"
                    description="The published settings stay in use. Your unsaved changes on every tab are removed."
                    confirmText="Discard changes"
                    cancelText="Keep editing"
                />
                {reviewing && (
                    <SaveDraftWizard
                        preview={preview}
                        onRetryPreview={() => void loadPreview()}
                        changes={toPublish}
                        configuration={configuration}
                        busy={busy}
                        error={error}
                        recovering={recovering === 'draft'}
                        onClose={() => setReviewing(false)}
                        onSave={(reason) => void command('draft', { reason })}
                    />
                )}
                {publishing && viewingDraft && (
                    <PublishWizard
                        version={selected}
                        impact={
                            settings.impact?.version_id === selected.id
                                ? settings.impact
                                : null
                        }
                        governanceRequired={selected.governance_required}
                        changes={configurationChanges(
                            selected.base_configuration,
                            selected.configuration,
                            settings.roles,
                            settings.service_types,
                        )}
                        configuration={selected.configuration}
                        busy={busy}
                        error={error}
                        recovering={recovering === 'publish'}
                        onClose={() => setPublishing(false)}
                        onPublish={(input) => void command('publish', input)}
                    />
                )}
                {discardDraft && (
                    <DiscardDraftDialog
                        version={discardDraft}
                        onClose={() => setDiscardDraft(null)}
                    />
                )}
                {restoreFrom && (
                    <RestoreDialog
                        version={restoreFrom}
                        onClose={() => setRestoreFrom(null)}
                    />
                )}
            </div>
        </PageLayout>
    );
}
