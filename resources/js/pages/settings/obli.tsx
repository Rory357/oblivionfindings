import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderStatusChip,
} from '@/components/page';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { Head, usePage } from '@inertiajs/react';
import axios from 'axios';
import { Bot, Check, CircleDollarSign, KeyRound, Shield } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    defaultRemoteSupport,
    ObliRemoteSupportCard,
    type RemoteSupportConfiguration,
} from './obli-remote-support-card';

type Configuration = {
    version: number;
    enabled: boolean;
    provider: 'openai' | 'anthropic';
    model: string;
    key_saved: boolean;
    helper_access_enabled: boolean;
    max_output_tokens: number;
    daily_budget_usd: string;
    monthly_budget_usd: string;
    input_price_usd: string;
    output_price_usd: string;
    chat_retention_days: number;
    knowledge_enabled: boolean;
    technician_enabled: boolean;
    diagnostic_categories: ('system' | 'software' | 'services')[];
};
type Props = {
    actor_id: number;
    configuration: Configuration;
    remote_support?: RemoteSupportConfiguration;
};
const diagnosticGroups = [
    [
        'system',
        'System health',
        'Windows version, architecture, memory, uptime, CPU use and up to eight fixed drives.',
    ],
    [
        'software',
        'Approved software versions',
        'Recognised Microsoft Edge, Google Chrome and machine-wide Teams entries only.',
    ],
    [
        'services',
        'Windows service states',
        'Print spooler, Windows Update, background transfers, Defender and Firewall. State alone does not prove a fault.',
    ],
] as const;
const steps = [
    {
        key: 'provider',
        label: 'Provider',
        blurb: 'Connection and access',
        icon: KeyRound,
    },
    {
        key: 'limits',
        label: 'Limits',
        blurb: 'Spending, retention and guides',
        icon: CircleDollarSign,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check before saving',
        icon: Check,
    },
];
const providerLabel = (provider: Configuration['provider']) =>
    provider === 'openai' ? 'OpenAI' : 'Anthropic (Claude)';
const moneyFields = [
    ['input_price_usd', 'Input price per million tokens (USD)'],
    ['output_price_usd', 'Output price per million tokens (USD)'],
    ['daily_budget_usd', 'Daily spending limit (USD)'],
    ['monthly_budget_usd', 'Monthly spending limit (USD)'],
] as const;

export default function ObliSettings() {
    const props = usePage<Props>().props;
    return (
        <ObliSettingsEditor
            key={props.actor_id + ':' + props.configuration.version}
            {...props}
        />
    );
}
function ObliSettingsEditor({
    actor_id,
    configuration,
    remote_support,
}: Props) {
    const [saved, setSaved] = useState(configuration);
    const [draft, setDraft] = useState(configuration);
    const [apiKey, setApiKey] = useState('');
    const [clearKey, setClearKey] = useState(false);
    const [open, setOpen] = useState(false);
    const [step, setStep] = useState(0);
    const [busy, setBusy] = useState(false);
    const [success, setSuccess] = useState(false);
    const [notice, setNotice] = useState('');
    const [requiresReload, setRequiresReload] = useState(false);
    const abort = useRef<AbortController | null>(null);
    const dirty =
        open &&
        !success &&
        (JSON.stringify(draft) !== JSON.stringify(saved) ||
            apiKey !== '' ||
            clearKey);
    const leave = useSettingsLeaveConfirmation(
        dirty,
        undefined,
        undefined,
        () => setApiKey(''),
    );
    useEffect(() => () => abort.current?.abort(), []);
    function update<K extends keyof Configuration>(
        key: K,
        value: Configuration[K],
    ) {
        setDraft((current) => ({ ...current, [key]: value }));
    }
    function edit(index = 0) {
        if (requiresReload) return;
        setDraft(saved);
        setApiKey('');
        setClearKey(false);
        setSuccess(false);
        setNotice('');
        setStep(index);
        setOpen(true);
    }
    function close() {
        if (!busy)
            leave.request(() => {
                setApiKey('');
                setOpen(false);
            });
    }
    function validate(): boolean {
        if (
            ((draft.enabled || draft.model !== '') &&
                !/^[A-Za-z0-9][A-Za-z0-9._:-]{0,119}$/.test(draft.model)) ||
            (draft.enabled &&
                !apiKey &&
                (!saved.key_saved ||
                    clearKey ||
                    draft.provider !== saved.provider)) ||
            (apiKey !== '' && !/^[\x21-\x7e]{12,2048}$/.test(apiKey))
        ) {
            setStep(0);
            setNotice(
                'Enter a model ID and a valid provider key. A new provider needs its own key.',
            );
            return false;
        }
        if (
            !/^\d{1,5}(\.\d{1,2})?$/.test(draft.daily_budget_usd) ||
            !/^\d{1,6}(\.\d{1,2})?$/.test(draft.monthly_budget_usd) ||
            (['input_price_usd', 'output_price_usd'] as const).some(
                (field) =>
                    (draft.enabled || draft[field] !== '') &&
                    (!/^\d{1,4}(\.\d{1,6})?$/.test(draft[field]) ||
                        Number(draft[field]) <= 0),
            ) ||
            Number(draft.daily_budget_usd) <= 0 ||
            Number(draft.monthly_budget_usd) <= 0 ||
            !Number.isInteger(draft.chat_retention_days) ||
            draft.chat_retention_days < 1 ||
            draft.chat_retention_days > 90 ||
            !Number.isInteger(draft.max_output_tokens) ||
            draft.max_output_tokens < 256 ||
            draft.max_output_tokens > 8192
        ) {
            setStep(1);
            setNotice(
                'Use positive USD amounts, 1–90 retention days and a reply limit of 256–8192 tokens.',
            );
            return false;
        }
        setNotice('');
        return true;
    }
    async function save() {
        if (busy || requiresReload || !validate()) return;
        setBusy(true);
        const controller = new AbortController();
        abort.current = controller;
        const payload = {
            ...draft,
            expected_actor_id: actor_id,
            expected_version: saved.version,
            api_key: apiKey || null,
            clear_key: clearKey,
        };
        setApiKey('');
        try {
            const response = await axios.put<Props>('/settings/obli', payload, {
                signal: controller.signal,
                timeout: 20000,
                headers: { Accept: 'application/json' },
            });
            if (
                response.data.actor_id !== actor_id ||
                response.data.configuration.version !== saved.version + 1
            )
                throw new Error('Reload required');
            if (controller.signal.aborted) return;
            setSaved(response.data.configuration);
            setDraft(response.data.configuration);
            setClearKey(false);
            setSuccess(true);
        } catch {
            if (!controller.signal.aborted) {
                setRequiresReload(true);
                setNotice(
                    'The save could not be confirmed. Your entered key has been cleared. Reload the saved settings before trying again.',
                );
            }
        } finally {
            if (!controller.signal.aborted) setBusy(false);
        }
    }
    async function reload() {
        if (busy) return;
        setBusy(true);
        setApiKey('');
        const controller = new AbortController();
        abort.current = controller;
        try {
            const response = await axios.get<Props>('/settings/obli', {
                signal: controller.signal,
                timeout: 15000,
                headers: { Accept: 'application/json' },
            });
            if (response.data.actor_id !== actor_id) {
                window.location.reload();
                return;
            }
            if (controller.signal.aborted) return;
            setSaved(response.data.configuration);
            setDraft(response.data.configuration);
            setClearKey(false);
            setRequiresReload(false);
            setNotice('Saved settings reloaded.');
            setOpen(false);
        } catch {
            if (!controller.signal.aborted)
                setNotice(
                    'Settings could not be loaded. Check your sign-in and current access.',
                );
        } finally {
            if (!controller.signal.aborted) setBusy(false);
        }
    }
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Settings', href: '/settings/profile' },
                { title: 'Obli', href: '/settings/obli' },
            ]}
        >
            <Head title="Obli settings" />
            <SettingsLayout>
                <div className="space-y-5">
                    <PageHeader
                        icon={Bot}
                        title="Obli"
                        subline="Private computer support · AI and approved guides"
                        titleChip={
                            <PageHeaderStatusChip
                                variant={
                                    !saved.helper_access_enabled
                                        ? 'warning'
                                        : saved.enabled
                                          ? 'success'
                                          : 'neutral'
                                }
                            >
                                {!saved.helper_access_enabled
                                    ? 'Helper access off'
                                    : saved.enabled
                                      ? 'Chat enabled'
                                      : 'Chat off'}
                            </PageHeaderStatusChip>
                        }
                        actions={
                            <PageHeaderGlassButton
                                onClick={() => edit()}
                                disabled={requiresReload}
                            >
                                Edit settings
                            </PageHeaderGlassButton>
                        }
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Provider"
                                    onClick={() => edit(0)}
                                >
                                    <PageHeaderMeterBig>
                                        {providerLabel(saved.provider)}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {saved.model || 'No model selected'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Daily limit"
                                    onClick={() => edit(1)}
                                >
                                    <PageHeaderMeterBig>
                                        {'$' + saved.daily_budget_usd}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Estimated USD · UTC day
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Monthly limit"
                                    onClick={() => edit(1)}
                                >
                                    <PageHeaderMeterBig>
                                        {'$' + saved.monthly_budget_usd}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Estimated USD · UTC month
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Private replies"
                                    onClick={() => edit(1)}
                                >
                                    <PageHeaderMeterBig>
                                        {saved.chat_retention_days} days
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Encrypted reply recovery
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                    />
                    {!open && notice && (
                        <Alert>
                            <AlertDescription role="status">
                                {notice}
                            </AlertDescription>
                        </Alert>
                    )}
                    {requiresReload && (
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={() => void reload()}
                        >
                            Reload saved settings
                        </Button>
                    )}
                    <ObliRemoteSupportCard
                        key={actor_id + ':' + (remote_support?.version ?? 0)}
                        actorId={actor_id}
                        initial={
                            remote_support ??
                            defaultRemoteSupport(saved.helper_access_enabled)
                        }
                        onHelperAccessChange={(enabled) => {
                            setSaved((current) => ({
                                ...current,
                                helper_access_enabled: enabled,
                            }));
                            setDraft((current) => ({
                                ...current,
                                helper_access_enabled: enabled,
                            }));
                        }}
                    />
                    <Card>
                        <CardHeader>
                            <CardTitle>Who can use Obli</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <p className="text-subtle">
                                {saved.technician_enabled
                                    ? 'Technician assistance is enabled when Obli AI is on. Current IT, computer and diagnostic permissions still apply, and the deployment must allow this feature.'
                                    : 'Technician AI assistance is switched off.'}
                            </p>
                            <p className="text-subtle">
                                {saved.knowledge_enabled
                                    ? 'Organisation guides are enabled for authorised staff. AI sharing requires selecting a guide excerpt.'
                                    : 'Organisation guides are switched off in the helper.'}
                            </p>
                            {!saved.helper_access_enabled && (
                                <p className="text-subtle">
                                    Windows helper access is switched off for
                                    this deployment. Saving AI settings alone
                                    will not make chat available. Your
                                    deployment administrator must enable helper
                                    access.
                                </p>
                            )}
                            <p className="text-subtle">
                                Staff sign in to the Windows helper and choose
                                when to send their conversation to{' '}
                                {providerLabel(saved.provider)}. Obli can
                                explain a repair; this chat has no command or
                                remote-control tools.
                            </p>
                            <p className="text-subtle text-muted-foreground">
                                The provider key stays encrypted on the Oblivion
                                server.{' '}
                                {saved.key_saved
                                    ? 'A key is saved.'
                                    : 'No key is saved.'}{' '}
                                Tickets, files, screenshots and device
                                diagnostics are not automatically included.
                            </p>
                            <p className="text-subtle text-muted-foreground">
                                Replies and selected technician checks are
                                encrypted for recovery. Questions are not stored
                                separately by this chat gateway. Provider
                                retention is managed separately by your provider
                                account and agreement. Changing retention
                                applies to new replies.
                            </p>
                        </CardContent>
                    </Card>
                </div>
            </SettingsLayout>
            <WizardShell
                open={open}
                onClose={close}
                title="Obli settings"
                description="Configure approved guides, the AI provider, limits and private-reply retention."
                railIcon={Bot}
                railTitle="Obli"
                railSub="AI connection"
                steps={steps}
                stepIndex={step}
                onStepClick={(index) => {
                    if (!busy && !requiresReload) setStep(index);
                }}
                pct={Math.round(
                    ([
                        !!draft.model,
                        !!(
                            apiKey ||
                            (saved.key_saved &&
                                !clearKey &&
                                draft.provider === saved.provider)
                        ),
                        Number(draft.input_price_usd) > 0,
                        Number(draft.output_price_usd) > 0,
                    ].filter(Boolean).length /
                        4) *
                        100,
                )}
                footerStart={
                    <Button variant="outline" onClick={close} disabled={busy}>
                        Cancel
                    </Button>
                }
                footerEnd={
                    requiresReload ? (
                        <Button disabled={busy} onClick={() => void reload()}>
                            Reload saved settings
                        </Button>
                    ) : step === 2 ? (
                        <Button disabled={busy} onClick={() => void save()}>
                            {busy ? 'Saving…' : 'Save settings'}
                        </Button>
                    ) : (
                        <Button
                            onClick={() => {
                                if (step === 0) setStep(1);
                                else if (validate()) setStep(2);
                            }}
                        >
                            Continue
                        </Button>
                    )
                }
                success={
                    success ? (
                        <WizardSuccessPane
                            title="Obli settings saved"
                            blurb="Staff will see the current provider before sending a new conversation."
                            actions={
                                <Button onClick={() => setOpen(false)}>
                                    Done
                                </Button>
                            }
                        />
                    ) : undefined
                }
            >
                <div className="space-y-5">
                    {notice && (
                        <Alert>
                            <AlertDescription role="status">
                                {notice}
                            </AlertDescription>
                        </Alert>
                    )}
                    <WizardStepPane key={step}>
                        {step === 0 && (
                            <div className="space-y-5">
                                <div className="flex items-center justify-between gap-4">
                                    <Label htmlFor="obli-enabled">
                                        Enable Obli AI
                                    </Label>
                                    <Switch
                                        id="obli-enabled"
                                        checked={draft.enabled}
                                        onCheckedChange={(value) =>
                                            update('enabled', value)
                                        }
                                        disabled={busy}
                                    />
                                </div>
                                <div className="space-y-2">
                                    <div className="flex items-center justify-between gap-4">
                                        <Label htmlFor="obli-technician">
                                            Enable technician AI assistance
                                        </Label>
                                        <Switch
                                            id="obli-technician"
                                            checked={draft.technician_enabled}
                                            onCheckedChange={(value) =>
                                                update(
                                                    'technician_enabled',
                                                    value,
                                                )
                                            }
                                            disabled={busy}
                                        />
                                    </div>
                                    <p className="text-caption text-muted-foreground">
                                        Allows authorised technicians to ask
                                        Obli about approved computer checks in
                                        IT &amp; Support. Uses the same provider
                                        and spending limits. Suggestions do not
                                        execute repairs.
                                    </p>
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="obli-provider">
                                        Provider
                                    </Label>
                                    <Select
                                        value={draft.provider}
                                        onValueChange={(
                                            value: Configuration['provider'],
                                        ) => {
                                            update('provider', value);
                                            setApiKey('');
                                        }}
                                        disabled={busy}
                                    >
                                        <SelectTrigger id="obli-provider">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="openai">
                                                OpenAI
                                            </SelectItem>
                                            <SelectItem value="anthropic">
                                                Anthropic (Claude)
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="obli-model">Model ID</Label>
                                    <Input
                                        id="obli-model"
                                        value={draft.model}
                                        onChange={(e) =>
                                            update('model', e.target.value)
                                        }
                                        maxLength={120}
                                        autoComplete="off"
                                        disabled={busy}
                                    />
                                    <p className="text-caption text-muted-foreground">
                                        Optional while AI chat is off. Use the
                                        exact model ID available in your
                                        provider account.
                                    </p>
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="obli-key">
                                        {saved.key_saved
                                            ? 'Replacement API key (optional)'
                                            : 'API key'}
                                    </Label>
                                    <Input
                                        id="obli-key"
                                        type="password"
                                        value={apiKey}
                                        onChange={(e) => {
                                            setApiKey(e.target.value);
                                            setClearKey(false);
                                        }}
                                        autoComplete="new-password"
                                        maxLength={2048}
                                        disabled={busy}
                                    />
                                    <p className="text-caption text-muted-foreground">
                                        The saved key is never displayed.
                                        Changing provider requires a matching
                                        key.
                                    </p>
                                </div>
                                {saved.key_saved && (
                                    <div className="flex items-center justify-between gap-4">
                                        <Label htmlFor="obli-clear">
                                            Remove the saved key
                                        </Label>
                                        <Switch
                                            id="obli-clear"
                                            checked={clearKey}
                                            onCheckedChange={(value) => {
                                                setClearKey(value);
                                                if (value) {
                                                    setApiKey('');
                                                    update('enabled', false);
                                                }
                                            }}
                                            disabled={busy}
                                        />
                                    </div>
                                )}
                            </div>
                        )}
                        {step === 1 && (
                            <div className="space-y-5">
                                <p className="text-subtle text-muted-foreground">
                                    Per-token prices can stay blank while AI
                                    chat is off. Enter current prices from your
                                    provider account. Limits use estimates and
                                    reserve the maximum reply cost before
                                    sending. Unconfirmed provider requests keep
                                    that reservation. Invoices may differ; also
                                    set an account-level budget with your
                                    provider.
                                </p>
                                {moneyFields.map(([field, label]) => (
                                    <div key={field} className="space-y-2">
                                        <Label htmlFor={field}>{label}</Label>
                                        <Input
                                            id={field}
                                            inputMode="decimal"
                                            value={draft[field]}
                                            onChange={(e) =>
                                                update(field, e.target.value)
                                            }
                                            disabled={busy}
                                        />
                                    </div>
                                ))}
                                <div className="space-y-2">
                                    <Label htmlFor="reply-limit">
                                        Maximum reply tokens (256–8192)
                                    </Label>
                                    <Input
                                        id="reply-limit"
                                        type="number"
                                        min={256}
                                        max={8192}
                                        value={draft.max_output_tokens}
                                        onChange={(e) =>
                                            update(
                                                'max_output_tokens',
                                                Number(e.target.value),
                                            )
                                        }
                                        disabled={busy}
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="reply-retention">
                                        Retain private replies for (1–90 days)
                                    </Label>
                                    <Input
                                        id="reply-retention"
                                        type="number"
                                        min={1}
                                        max={90}
                                        value={draft.chat_retention_days}
                                        onChange={(e) =>
                                            update(
                                                'chat_retention_days',
                                                Number(e.target.value),
                                            )
                                        }
                                        disabled={busy}
                                    />
                                </div>
                                <div className="space-y-3">
                                    <div className="space-y-2">
                                        <div className="flex items-center justify-between gap-4">
                                            <Label htmlFor="obli-knowledge">
                                                Organisation guides in Obli
                                            </Label>
                                            <Switch
                                                id="obli-knowledge"
                                                checked={
                                                    draft.knowledge_enabled
                                                }
                                                disabled={busy}
                                                onCheckedChange={(value) =>
                                                    update(
                                                        'knowledge_enabled',
                                                        value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <p className="text-subtle text-muted-foreground">
                                            Let signed-in staff find current,
                                            reviewed IT guides they can access.
                                            Staff can read guides while AI chat
                                            is off. Sharing an excerpt with the
                                            AI provider requires selecting it
                                            before sending a question.
                                        </p>
                                    </div>
                                    <h3 className="text-section-title">
                                        Optional computer checks
                                    </h3>
                                    <p className="text-subtle text-muted-foreground">
                                        Allow the groups staff may preview and
                                        attach to a chat. Nothing is added
                                        automatically. Checks exclude computer
                                        and user names, addresses, file paths,
                                        publishers and raw logs.
                                    </p>
                                    {diagnosticGroups.map(
                                        ([group, label, description]) => (
                                            <div
                                                key={group}
                                                className="space-y-1"
                                            >
                                                <div className="flex items-center justify-between gap-4">
                                                    <Label
                                                        htmlFor={
                                                            'diagnostics-' +
                                                            group
                                                        }
                                                    >
                                                        {label}
                                                    </Label>
                                                    <Switch
                                                        id={
                                                            'diagnostics-' +
                                                            group
                                                        }
                                                        checked={draft.diagnostic_categories.includes(
                                                            group,
                                                        )}
                                                        disabled={busy}
                                                        onCheckedChange={(
                                                            enabled,
                                                        ) =>
                                                            update(
                                                                'diagnostic_categories',
                                                                diagnosticGroups
                                                                    .filter(
                                                                        ([
                                                                            candidate,
                                                                        ]) =>
                                                                            candidate ===
                                                                            group
                                                                                ? enabled
                                                                                : draft.diagnostic_categories.includes(
                                                                                      candidate,
                                                                                  ),
                                                                    )
                                                                    .map(
                                                                        ([
                                                                            candidate,
                                                                        ]) =>
                                                                            candidate,
                                                                    ),
                                                            )
                                                        }
                                                    />
                                                </div>
                                                <p className="text-caption text-muted-foreground">
                                                    {description}
                                                </p>
                                            </div>
                                        ),
                                    )}
                                </div>
                            </div>
                        )}
                        {step === 2 && (
                            <div className="space-y-4">
                                <ReviewCard
                                    icon={KeyRound}
                                    title="Connection"
                                    onEdit={() => setStep(0)}
                                >
                                    <ReviewRow
                                        label="Chat"
                                        value={
                                            draft.enabled ? 'Enabled' : 'Off'
                                        }
                                    />
                                    <ReviewRow
                                        label="Technician assistance"
                                        value={
                                            draft.technician_enabled
                                                ? 'Enabled when Obli AI is on'
                                                : 'Off'
                                        }
                                    />
                                    <ReviewRow
                                        label="Provider"
                                        value={providerLabel(draft.provider)}
                                    />
                                    <ReviewRow
                                        label="Model"
                                        value={draft.model}
                                    />
                                    <ReviewRow
                                        label="API key"
                                        value={
                                            clearKey
                                                ? 'Remove'
                                                : apiKey
                                                  ? 'Replace with entered key'
                                                  : saved.key_saved
                                                    ? 'Keep saved key'
                                                    : 'No key saved'
                                        }
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={CircleDollarSign}
                                    title="Spending"
                                    onEdit={() => setStep(1)}
                                >
                                    <ReviewRow
                                        label="Input / output per million"
                                        value={
                                            draft.input_price_usd &&
                                            draft.output_price_usd
                                                ? 'USD ' +
                                                  draft.input_price_usd +
                                                  ' / ' +
                                                  draft.output_price_usd
                                                : 'Not configured while chat is off'
                                        }
                                    />
                                    <ReviewRow
                                        label="Daily / monthly limit"
                                        value={
                                            'USD ' +
                                            draft.daily_budget_usd +
                                            ' / ' +
                                            draft.monthly_budget_usd
                                        }
                                    />
                                    <ReviewRow
                                        label="Maximum reply"
                                        value={
                                            draft.max_output_tokens + ' tokens'
                                        }
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={Shield}
                                    title="Privacy"
                                    onEdit={() => setStep(1)}
                                >
                                    <ReviewRow
                                        label="Private reply retention"
                                        value={
                                            draft.chat_retention_days +
                                            ' days for new replies'
                                        }
                                    />
                                    <ReviewRow
                                        label="Organisation guides"
                                        value={
                                            draft.knowledge_enabled
                                                ? 'Available to authorised staff'
                                                : 'Off'
                                        }
                                    />
                                    <ReviewRow
                                        label="Optional checks"
                                        value={
                                            diagnosticGroups
                                                .filter(([group]) =>
                                                    draft.diagnostic_categories.includes(
                                                        group,
                                                    ),
                                                )
                                                .map(([, label]) => label)
                                                .join(', ') || 'None allowed'
                                        }
                                    />
                                    <ReviewRow
                                        label="Actions"
                                        value="Read-only chat"
                                    />
                                </ReviewCard>
                            </div>
                        )}
                    </WizardStepPane>
                </div>
            </WizardShell>
            {leave.confirmation}
        </AppLayout>
    );
}
