import {
    ChatGovernanceDialogs,
    type ChatGovernanceDialog,
} from '@/components/chat-governance/chat-governance-dialogs';
import { ChatError } from '@/components/chat/api';
import { chatActorRequest } from '@/components/chat/chat-actor-request';
import { ChatResponseSettings } from '@/components/chat/chat-response-settings';
import { ChatServiceHealth } from '@/components/chat/chat-service-health';
import { reviewLatestChatSettings } from '@/components/chat/chat-settings-conflict';
import {
    chatPolicyChanges,
    chatSectionFields,
    chatSettingLabels,
    chatSettingSections,
    describeChatSetting,
    editChatSetting,
    effectiveChatSetting,
    inheritChatSection,
    type ChatConfiguration,
    type ChatSettingField,
    type ChatSettingSection,
} from '@/components/chat/chat-settings-policy';
import { ChatUrgentContactSettings } from '@/components/chat/chat-urgent-contact-settings';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Switch } from '@/components/ui/switch';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { cn } from '@/lib/utils';
import { Head, usePage } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

type Configuration = ChatConfiguration;

function normaliseConfiguration(settings: Configuration): Configuration {
    return {
        ...settings,
        voice_notes_enabled: settings.voice_notes_enabled ?? true,
        response_schedule: settings.response_schedule ?? null,
        urgent_contact: settings.urgent_contact ?? null,
    };
}

type SettingsProps = {
    chatSettings: Configuration;
    chatSites: { id: number; name: string }[];
    callingReady: boolean;
    chatInvestigator?: boolean;
};

export default function ChatSettingsPage(props: SettingsProps) {
    const { auth } = usePage<{ auth: { user: { id: number } } }>().props;
    return (
        <ChatSettingsSession
            key={auth.user.id}
            {...props}
            actorId={auth.user.id}
        />
    );
}

function ChatSettingsSession({
    actorId,
    chatSettings,
    chatSites,
    callingReady,
    chatInvestigator = false,
}: SettingsProps & { actorId: number }) {
    const [governance, setGovernance] = useState<ChatGovernanceDialog | null>(
        null,
    );
    const request = useRef<AbortController | null>(null);
    const [denied, setDenied] = useState(false);
    const deniedHeading = useRef<HTMLHeadingElement>(null);
    useEffect(() => {
        if (denied) deniedHeading.current?.focus();
    }, [denied]);
    useEffect(
        () => () => {
            request.current?.abort();
            request.current = null;
        },
        [],
    );
    const initial = normaliseConfiguration(chatSettings);
    const [saved, setSaved] = useState(initial);
    const [draft, setDraft] = useState(initial);
    const [review, setReview] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [conflict, setConflict] = useState(false);
    const [latestReviewed, setLatestReviewed] = useState(false);
    const dirty = chatPolicyChanges(saved, draft, chatSites).length > 0;
    const leave = useSettingsLeaveConfirmation(
        dirty && !denied,
        'Discard unsaved Chat settings?',
        'Your changes have not been saved. Stay here to keep reviewing them.',
        undefined,
        saving,
    );
    async function save() {
        if (denied || conflict || !dirty || saving || request.current) return;
        const controller = new AbortController();
        request.current = controller;
        setSaving(true);
        setError('');
        try {
            const result = await chatActorRequest<{ settings: Configuration }>(
                actorId,
                '/settings/chat',
                {
                    method: 'PUT',
                    body: JSON.stringify(draft),
                    signal: AbortSignal.any([
                        controller.signal,
                        AbortSignal.timeout(15000),
                    ]),
                },
            );
            if (request.current !== controller || controller.signal.aborted)
                return;
            const current = normaliseConfiguration(result.settings);
            setSaved(current);
            setDraft(current);
            setReview(false);
            setNotice('Chat settings saved.');
            setLatestReviewed(false);
        } catch (failure) {
            if (request.current !== controller || controller.signal.aborted)
                return;
            if (
                failure instanceof ChatError &&
                [401, 403, 419].includes(failure.status)
            )
                setDenied(true);
            setError((failure as Error).message);
            if (failure instanceof ChatError && failure.status === 409) {
                setConflict(true);
                setLatestReviewed(false);
            }
        } finally {
            if (request.current === controller) {
                request.current = null;
                setSaving(false);
            }
        }
    }
    async function loadLatest() {
        if (denied || request.current) return;
        const controller = new AbortController();
        request.current = controller;
        setSaving(true);
        setError('');
        try {
            const result = await chatActorRequest<{ settings: Configuration }>(
                actorId,
                '/settings/chat/configuration',
                {
                    signal: AbortSignal.any([
                        controller.signal,
                        AbortSignal.timeout(10000),
                    ]),
                },
            );
            if (request.current !== controller || controller.signal.aborted)
                return;
            const current = normaliseConfiguration(result.settings);
            setDraft(reviewLatestChatSettings(saved, draft, current));
            setSaved(current);
            setConflict(false);
            setLatestReviewed(true);
        } catch (failure) {
            if (request.current !== controller || controller.signal.aborted)
                return;
            if (
                failure instanceof ChatError &&
                [401, 403, 419].includes(failure.status)
            )
                setDenied(true);
            setError((failure as Error).message);
        } finally {
            if (request.current === controller) {
                request.current = null;
                setSaving(false);
            }
        }
    }

    const [section, setSection] = useState<ChatSettingSection>('general');
    const [scope, setScope] = useState('organisation');
    const [pending, setPending] = useState<{
        section: ChatSettingSection;
        scope: string;
    } | null>(null);
    const heading = useRef<HTMLHeadingElement>(null);
    const reviewButton = useRef<HTMLButtonElement>(null);
    const policyChanges = chatPolicyChanges(saved, draft, chatSites);
    const scopeName =
        scope === 'organisation'
            ? 'Organisation defaults'
            : (chatSites.find((site) => String(site.id) === scope)?.name ??
              'Site ' + scope);
    const overrides =
        scope === 'organisation' ? {} : (draft.site_overrides[scope] ?? {});
    const hasOverride = chatSectionFields[section].some((field) =>
        Object.hasOwn(overrides, field),
    );
    const value = <K extends ChatSettingField>(field: K) =>
        effectiveChatSetting(draft, scope, field);
    function edit<K extends ChatSettingField>(
        field: K,
        next: Configuration[K],
    ) {
        setDraft((current) => editChatSetting(current, scope, field, next));
        setNotice('');
    }
    function navigate(target: { section: ChatSettingSection; scope: string }) {
        if (dirty) {
            setPending(target);
            return;
        }
        setSection(target.section);
        setScope(target.scope);
        setNotice('');
        requestAnimationFrame(() => heading.current?.focus());
    }
    function discard() {
        setDraft(saved);
        setError('');
        setConflict(false);
        setLatestReviewed(false);
        setNotice('');
    }
    function source(field: ChatSettingField) {
        return scope === 'organisation'
            ? 'Organisation policy'
            : (Object.hasOwn(overrides, field)
                  ? 'Overridden: '
                  : 'Inherited: ') + describeChatSetting(value(field));
    }
    const toggle = (
        field:
            | 'enabled'
            | 'family_enabled'
            | 'files_enabled'
            | 'voice_notes_enabled'
            | 'calls_enabled'
            | 'family_calls_enabled',
        description: string,
        disabled = false,
    ) => (
        <div className="flex items-center justify-between gap-4 border-b py-4 last:border-0">
            <div className="min-w-0">
                <label htmlFor={'chat-' + field} className="font-medium">
                    {chatSettingLabels[field]}
                </label>
                <p className="mt-1 text-sm text-muted-foreground">
                    {description}
                </p>
                <p className="mt-1 text-xs text-muted-foreground">
                    {source(field)}
                </p>
            </div>
            <div className="flex min-h-[44px] min-w-[44px] shrink-0 items-center justify-center">
                <Switch
                    id={'chat-' + field}
                    checked={value(field)}
                    disabled={disabled || saving}
                    className="frontline-hit"
                    onCheckedChange={(next) => edit(field, next)}
                />
            </div>
        </div>
    );
    const number = (
        field:
            | 'max_file_mb'
            | 'max_files'
            | 'max_call_participants'
            | 'message_change_window_minutes',
        min: number,
        max: number,
    ) => (
        <div className="grid items-center gap-3 border-b py-4 last:border-0 sm:grid-cols-[minmax(0,1fr)_160px]">
            <div>
                <label
                    htmlFor={'chat-number-' + field}
                    className="text-sm font-medium"
                >
                    {chatSettingLabels[field]}
                </label>
                <p
                    id={'chat-source-' + field}
                    className="mt-1 text-xs text-muted-foreground"
                >
                    {source(field)}
                </p>
            </div>
            <Input
                id={'chat-number-' + field}
                aria-describedby={'chat-source-' + field}
                type="number"
                className="min-h-[44px] min-w-0"
                min={min}
                max={max}
                placeholder="Not configured"
                value={value(field) ?? ''}
                disabled={saving}
                onChange={(event) =>
                    edit(
                        field,
                        field === 'message_change_window_minutes' &&
                            event.target.value === ''
                            ? null
                            : Number(event.target.value),
                    )
                }
            />
        </div>
    );
    if (denied)
        return (
            <AppLayout>
                <Head title="Chat settings" />
                <SettingsLayout>
                    <div role="alert" className="space-y-3 p-4">
                        <h1
                            ref={deniedHeading}
                            tabIndex={-1}
                            className="text-xl font-semibold"
                        >
                            Chat settings unavailable
                        </h1>
                        <p className="text-sm text-muted-foreground">{error}</p>
                        <Button
                            asChild
                            variant="outline"
                            className="frontline-tap"
                        >
                            <a href="/settings/chat">Reload Chat settings</a>
                        </Button>
                    </div>
                </SettingsLayout>
            </AppLayout>
        );
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Settings', href: '/settings' },
                { title: 'Chat', href: '/settings/chat' },
            ]}
        >
            <Head title="Chat settings" />
            {leave.confirmation}
            <SettingsLayout>
                <div className="grid min-w-0 gap-5 lg:grid-cols-[210px_minmax(0,1fr)]">
                    <nav
                        aria-label="Chat settings sections"
                        className="min-w-0 space-y-3"
                    >
                        <p className="text-xs font-semibold text-muted-foreground">
                            Global Settings / Chat
                        </p>
                        <div className="flex gap-1 overflow-x-auto pb-2 lg:flex-col">
                            {(
                                Object.keys(
                                    chatSettingSections,
                                ) as ChatSettingSection[]
                            ).map((key) => (
                                <Button
                                    key={key}
                                    variant="ghost"
                                    disabled={saving}
                                    aria-current={
                                        section === key ? 'page' : undefined
                                    }
                                    className={cn(
                                        'min-h-[44px] shrink-0 justify-start whitespace-nowrap',
                                        section === key &&
                                            'bg-primary-fill/10 text-primary',
                                    )}
                                    onClick={() =>
                                        navigate({ section: key, scope })
                                    }
                                >
                                    {chatSettingSections[key]}
                                </Button>
                            ))}
                            {(
                                [
                                    ['portal-access', 'Portal relationships'],
                                    ['settings-audit', 'Settings audit'],
                                ] as const
                            ).map(([kind, label]) => (
                                <Button
                                    key={kind}
                                    variant="ghost"
                                    disabled={saving}
                                    className="min-h-[44px] shrink-0 justify-start whitespace-nowrap"
                                    onClick={() => setGovernance(kind)}
                                >
                                    {label}
                                </Button>
                            ))}
                        </div>
                    </nav>
                    <section
                        className="min-w-0 rounded-2xl border bg-background"
                        aria-labelledby="chat-settings-heading"
                    >
                        <header className="space-y-3 border-b p-5">
                            <p className="text-xs font-semibold tracking-wide text-primary uppercase">
                                Global Settings / Chat
                            </p>
                            <h1
                                id="chat-settings-heading"
                                ref={heading}
                                tabIndex={-1}
                                className="text-page-title"
                            >
                                {chatSettingSections[section]}
                            </h1>
                            <div className="grid gap-2">
                                <Label htmlFor="chat-policy-scope">
                                    Policy scope
                                </Label>
                                <Select
                                    disabled={saving}
                                    value={String(scope)}
                                    onValueChange={(value) =>
                                        navigate({
                                            section,
                                            scope: value,
                                        })
                                    }
                                >
                                    <SelectTrigger
                                        id="chat-policy-scope"
                                        className="frontline-tap min-w-0"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="organisation">
                                            Organisation defaults
                                        </SelectItem>
                                        {chatSites.map((site) => (
                                            <SelectItem
                                                key={site.id}
                                                value={String(site.id)}
                                            >
                                                {site.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <p className="text-sm text-muted-foreground">
                                {scope === 'organisation'
                                    ? 'Applies to private staff conversations and site conversations without an override.'
                                    : 'Applies to client conversations and managed channels at ' +
                                      scopeName +
                                      '. Private staff conversations without a site use organisation defaults. Access still follows current roles and approved relationships.'}
                            </p>
                            <StatusBadge
                                variant={
                                    scope !== 'organisation' && hasOverride
                                        ? 'info'
                                        : 'neutral'
                                }
                            >
                                {scope === 'organisation'
                                    ? 'Organisation default'
                                    : hasOverride
                                      ? 'Site/service override'
                                      : 'Inherited organisation defaults'}
                            </StatusBadge>
                        </header>
                        <div className="min-w-0 space-y-5 p-5">
                            {notice && (
                                <p
                                    role="status"
                                    className="flex items-center gap-2 rounded-lg bg-status-success-bg p-3 text-sm text-status-success"
                                >
                                    <Check className="size-4" />
                                    {notice}
                                </p>
                            )}
                            {section === 'general' && (
                                <>
                                    {toggle(
                                        'enabled',
                                        'Controls new messages and conversations. Existing history remains available to authorised people.',
                                    )}
                                    <p className="text-sm text-muted-foreground">
                                        Staff can start direct chats and private
                                        groups with eligible colleagues. Managed
                                        site channels require current
                                        channel-management authority. A policy
                                        setting never adds someone to a
                                        conversation.
                                    </p>
                                </>
                            )}
                            {section === 'family' && (
                                <>
                                    {toggle(
                                        'family_enabled',
                                        'Separate family conversations with disclosed authorised care-team cover.',
                                    )}
                                    <ChatResponseSettings
                                        value={draft}
                                        sites={chatSites}
                                        policyScope={scope}
                                        dirty={dirty}
                                        disabled={saving}
                                        onReview={() => setReview(true)}
                                        onChange={(next) => {
                                            setDraft((current) => ({
                                                ...current,
                                                ...next,
                                            }));
                                            setNotice('');
                                        }}
                                    />
                                    <ChatUrgentContactSettings
                                        value={value('urgent_contact') ?? null}
                                        source={source('urgent_contact')}
                                        disabled={saving}
                                        onChange={(next) =>
                                            edit('urgent_contact', next)
                                        }
                                    />
                                    <p className="rounded-lg bg-muted/40 p-3 text-sm">
                                        Unconfigured services show no
                                        response-time promise. Configured clocks
                                        count working hours and exclude
                                        configured holidays.
                                    </p>
                                    <div>
                                        <Button
                                            variant="outline"
                                            className="min-h-[44px]"
                                            onClick={() =>
                                                setGovernance('portal-access')
                                            }
                                        >
                                            Review client contact relationships
                                        </Button>
                                    </div>
                                </>
                            )}
                            {section === 'files' && (
                                <>
                                    {toggle(
                                        'files_enabled',
                                        'Files stay in the conversation until separately reviewed for publication to client records.',
                                    )}
                                    {toggle(
                                        'voice_notes_enabled',
                                        'Allow recorded voice messages up to two minutes. File sharing and the local audio safety checks must also be available. Stopping a recording never sends it.',
                                    )}
                                    <div>
                                        {number('max_file_mb', 1, 20)}
                                        {number('max_files', 1, 5)}
                                    </div>
                                    {number(
                                        'message_change_window_minutes',
                                        0,
                                        10080,
                                    )}
                                    <p className="text-sm text-muted-foreground">
                                        The edit/removal window applies to a
                                        sender’s own messages from the time
                                        sent. Leave blank until policy is
                                        agreed; 0 disables editing and removal.
                                        Earlier text stays in restricted audit
                                        history. Existing client records are
                                        unchanged.
                                    </p>
                                </>
                            )}
                            {section === 'calls' && (
                                <>
                                    <p className="rounded-lg bg-muted/40 p-3 text-sm">
                                        {callingReady
                                            ? 'Self-hosted calling has been configured and marked verified.'
                                            : 'Not ready: configure and verify the self-hosted calling service before enabling calls.'}
                                    </p>
                                    {toggle(
                                        'calls_enabled',
                                        'Audio only. Calls stay inside Oblivion.',
                                        !callingReady &&
                                            !value('calls_enabled'),
                                    )}
                                    {toggle(
                                        'family_calls_enabled',
                                        'Approved family members and authorised care-team participants only.',
                                        !callingReady &&
                                            !value('family_calls_enabled'),
                                    )}
                                    {number('max_call_participants', 2, 8)}
                                    <p className="text-sm text-muted-foreground">
                                        Voice only. No video, screen sharing,
                                        recording, transcription or public
                                        phone-number calls. Changes to
                                        enablement apply to new calls; active
                                        calls can finish.
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        Web calls need an available browser.
                                        Locked-phone calling belongs to the
                                        later native app.
                                    </p>
                                </>
                            )}
                            {section === 'notifications' && (
                                <>
                                    <h2 className="font-semibold">
                                        Notification preferences
                                    </h2>
                                    <p className="text-sm text-muted-foreground">
                                        Chat notices recheck access, mute
                                        preferences and quiet hours. Message
                                        notices also recheck unread state;
                                        family handover notices recheck current
                                        ownership. Notices keep client names and
                                        message content private. In-app notices
                                        are connected; email and push delivery
                                        are not available yet.
                                    </p>
                                    <a
                                        href="/settings/notifications"
                                        className="inline-flex min-h-[44px] items-center text-sm text-primary underline underline-offset-4"
                                    >
                                        Open notification preferences
                                    </a>
                                </>
                            )}
                            {section === 'privacy' && (
                                <>
                                    <h2 className="font-semibold">
                                        Privacy and records
                                    </h2>
                                    <ul className="space-y-3 text-sm text-muted-foreground">
                                        <li>
                                            Settings access does not authorise
                                            inspection of private conversations.
                                        </li>
                                        <li>
                                            No call recording, automatic
                                            transcript or automatic care note.
                                        </li>
                                        <li>
                                            Retention policy is awaiting
                                            approval. Automatic deletion is not
                                            enabled.
                                        </li>
                                        <li>
                                            Investigation, export and
                                            preservation actions require
                                            separately authorised case access.
                                        </li>
                                        <li>
                                            Settings changes are version checked
                                            and audited.
                                        </li>
                                    </ul>
                                    <p className="rounded-lg bg-muted/40 p-3 text-sm">
                                        No automatic deletion or invented
                                        retention period. Approved preservation
                                        and retention policy is required before
                                        rollout.
                                    </p>
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            variant="outline"
                                            className="min-h-[44px]"
                                            onClick={() =>
                                                setGovernance('investigation')
                                            }
                                        >
                                            Investigation access
                                        </Button>
                                        <Button
                                            variant="outline"
                                            className="min-h-[44px]"
                                            onClick={() =>
                                                setGovernance('holds')
                                            }
                                        >
                                            Exports and preservation holds
                                        </Button>
                                        <Button
                                            variant="outline"
                                            className="min-h-[44px]"
                                            onClick={() =>
                                                setGovernance('offboarding')
                                            }
                                        >
                                            Offboarding and expired access
                                        </Button>
                                    </div>
                                </>
                            )}
                            {section === 'access' && (
                                <>
                                    <h2 className="font-semibold">
                                        Channels and access
                                    </h2>
                                    <p className="text-sm text-muted-foreground">
                                        One organisation. The directory follows
                                        current staff roles and approved sites.
                                        Family conversations follow current
                                        client relationships and explicit
                                        invitations. Site overrides cannot widen
                                        record access.
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        New members see history from joining
                                        onward, unless specific earlier messages
                                        were explicitly reviewed and shared.
                                        Expired employment or family
                                        relationships remove future access
                                        without silently deleting existing
                                        records.
                                    </p>
                                    <div>
                                        <Button
                                            variant="outline"
                                            className="min-h-[44px]"
                                            onClick={() =>
                                                setGovernance(
                                                    'effective-access',
                                                )
                                            }
                                        >
                                            Inspect effective access
                                        </Button>
                                    </div>
                                </>
                            )}
                            {section === 'health' && (
                                <ChatServiceHealth actorId={actorId} />
                            )}
                        </div>
                        <footer className="sticky bottom-0 flex flex-wrap items-center justify-between gap-3 border-t bg-background p-4">
                            <p className="text-sm text-muted-foreground">
                                {dirty
                                    ? policyChanges.length + ' unsaved changes'
                                    : 'No unsaved changes'}
                            </p>
                            <div className="flex flex-wrap gap-2">
                                {scope !== 'organisation' && hasOverride && (
                                    <Button
                                        variant="outline"
                                        className="min-h-[44px]"
                                        disabled={saving}
                                        onClick={() => {
                                            setDraft((current) =>
                                                inheritChatSection(
                                                    current,
                                                    scope,
                                                    section,
                                                ),
                                            );
                                            setReview(true);
                                        }}
                                    >
                                        Reset to inherited
                                    </Button>
                                )}
                                <Button
                                    variant="outline"
                                    className="min-h-[44px]"
                                    disabled={!dirty || saving}
                                    onClick={discard}
                                >
                                    Discard
                                </Button>
                                <Button
                                    className="min-h-[44px]"
                                    disabled={!dirty || saving}
                                    ref={reviewButton}
                                    onClick={() => setReview(true)}
                                >
                                    Review changes
                                </Button>
                            </div>
                        </footer>
                    </section>
                </div>
                <a
                    href="/legal/chat-third-party-notices.txt"
                    target="_blank"
                    rel="noopener noreferrer"
                    className="mt-4 inline-flex min-h-[44px] items-center text-sm text-muted-foreground underline underline-offset-4"
                >
                    Calling open-source licences (opens in a new tab)
                </a>
                <ChatGovernanceDialogs
                    open={governance}
                    onClose={() => setGovernance(null)}
                    actorId={actorId}
                    investigator={chatInvestigator}
                    sites={chatSites}
                    onViewPolicy={(target) =>
                        navigate({ section: 'family', scope: target })
                    }
                />
                <Dialog
                    open={!!pending}
                    onOpenChange={(open) => {
                        if (!open) setPending(null);
                    }}
                >
                    <DialogContent
                        style={{
                            maxWidth: 'min(92vw, 480px)',
                            width: 'min(92vw, 480px)',
                        }}
                    >
                        <DialogHeader>
                            <DialogTitle>
                                Keep your settings changes?
                            </DialogTitle>
                            <DialogDescription>
                                Your draft is still available. Review and save
                                it, or discard it before leaving this scope.
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter className="sm:flex-wrap">
                            <Button
                                className="min-h-[44px]"
                                variant="outline"
                                onClick={() => setPending(null)}
                            >
                                Stay here
                            </Button>
                            <Button
                                className="min-h-[44px]"
                                variant="outline"
                                onClick={() => {
                                    if (pending) {
                                        discard();
                                        setSection(pending.section);
                                        setScope(pending.scope);
                                        setPending(null);
                                        requestAnimationFrame(() =>
                                            heading.current?.focus(),
                                        );
                                    }
                                }}
                            >
                                Discard and continue
                            </Button>
                            <Button
                                className="min-h-[44px]"
                                onClick={() => {
                                    setPending(null);
                                    setReview(true);
                                }}
                            >
                                Review changes
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
                <Dialog
                    open={review}
                    onOpenChange={(open) => {
                        if (!saving) setReview(open);
                    }}
                >
                    <DialogContent
                        style={{
                            maxWidth: 'min(92vw, 480px)',
                            width: 'min(92vw, 480px)',
                        }}
                        className="flex max-h-[85dvh] flex-col overflow-hidden"
                        onCloseAutoFocus={(event) => {
                            event.preventDefault();
                            (dirty
                                ? reviewButton.current
                                : heading.current
                            )?.focus();
                        }}
                    >
                        <DialogHeader>
                            <DialogTitle>Review Chat settings</DialogTitle>
                            <DialogDescription>
                                {scopeName} · {chatSettingSections[section]}.
                                Review the effective values and affected
                                conversations below. Site overrides cannot widen
                                access. Active calls can finish; changed call
                                permissions apply to new calls.
                            </DialogDescription>
                        </DialogHeader>
                        <ul className="min-h-0 space-y-3 overflow-y-auto text-sm">
                            {policyChanges.map((change) => (
                                <li
                                    key={change.label}
                                    className="rounded-lg bg-muted/40 p-3"
                                >
                                    <strong>{change.label}</strong>
                                    <p className="mt-1 break-words text-muted-foreground">
                                        Before:{' '}
                                        {change.before ||
                                            'No additional wording'}
                                    </p>
                                    <p className="mt-1 break-words">
                                        After:{' '}
                                        {change.after ||
                                            'No additional wording'}
                                    </p>
                                    <p className="mt-2 text-xs text-muted-foreground">
                                        {change.impact}
                                    </p>
                                </li>
                            ))}
                        </ul>
                        {error && (
                            <p
                                role="alert"
                                className="text-sm text-status-critical"
                            >
                                {error}
                            </p>
                        )}
                        {conflict && (
                            <Button
                                variant="outline"
                                className="h-auto min-h-[44px] whitespace-normal"
                                disabled={saving}
                                onClick={() => void loadLatest()}
                            >
                                Load latest and review my changes
                            </Button>
                        )}
                        {latestReviewed && (
                            <p
                                role="status"
                                className="text-sm text-muted-foreground"
                            >
                                {dirty
                                    ? 'Latest settings loaded. Your proposed values are retained for the fields you changed. Review the current values above before saving.'
                                    : 'Your proposed settings already match the latest saved settings. No further save is needed.'}
                            </p>
                        )}
                        <DialogFooter>
                            <Button
                                variant="outline"
                                className="min-h-[44px]"
                                disabled={saving}
                                onClick={() => setReview(false)}
                            >
                                Keep editing
                            </Button>
                            <Button
                                className="min-h-[44px]"
                                disabled={saving || conflict || !dirty}
                                onClick={() => void save()}
                            >
                                {saving ? 'Saving…' : 'Save reviewed changes'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </SettingsLayout>
        </AppLayout>
    );
}
