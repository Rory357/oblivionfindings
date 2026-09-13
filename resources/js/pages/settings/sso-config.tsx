import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderRail,
    PageHeaderStatusChip,
} from '@/components/page';
import {
    SsoProviderForm,
    SsoProvisioningForm,
    type ProviderConfiguration,
    type ProvisioningConfiguration,
} from '@/components/settings/sso-configuration-form';
import {
    SsoGroupMappings,
    type SsoMapping,
    type SsoRole,
} from '@/components/settings/sso-group-mappings';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useSsoLeaveConfirmation } from '@/hooks/use-sso-leave-confirmation';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { Copy, Shield } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

type Props = {
    mappings: SsoMapping[];
    roles: SsoRole[];
    stats: { total: number; microsoft: number; google: number };
    providers: {
        microsoft: ProviderConfiguration;
        google: ProviderConfiguration;
    };
    provisioning: ProvisioningConfiguration;
};
type View = 'microsoft' | 'google' | 'provisioning' | 'groups' | 'urls';
const views: { key: View; label: string }[] = [
    { key: 'microsoft', label: 'Microsoft' },
    { key: 'google', label: 'Google' },
    { key: 'provisioning', label: 'Provisioning' },
    { key: 'groups', label: 'Group mapping' },
    { key: 'urls', label: 'Callbacks & setup' },
];
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Settings', href: '/settings/profile' },
    { title: 'SSO', href: '/settings/sso' },
];

function CopyField({ label, value }: { label: string; value: string }) {
    const [message, setMessage] = useState('');
    async function copy() {
        try {
            await navigator.clipboard.writeText(value);
            setMessage('Copied.');
        } catch {
            setMessage('Copy failed. Select and copy the address manually.');
        }
    }
    return (
        <div className="space-y-2">
            <Label>{label}</Label>
            <div className="flex gap-2">
                <Input readOnly value={value} aria-label={label} />
                <Button
                    variant="outline"
                    size="icon"
                    aria-label={`Copy ${label}`}
                    onClick={() => void copy()}
                >
                    <Copy className="size-4" />
                </Button>
            </div>
            {message && (
                <p role="status" className="text-sm text-muted-foreground">
                    {message}
                </p>
            )}
        </div>
    );
}
export default function SsoConfig({
    mappings,
    roles,
    providers: initialProviders,
    provisioning: initialProvisioning,
}: Props) {
    const [providers, setProviders] = useState(initialProviders);
    const [provisioning, setProvisioning] = useState(initialProvisioning);
    const pageUrl = usePage().url;
    const [view, setView] = useState<View>(
        pageUrl.includes('view=groups') ? 'groups' : 'microsoft',
    );
    const [currentMappings, setCurrentMappings] = useState(mappings);
    const [dirty, setDirty] = useState(false);
    const [filter, setFilter] = useState('all');
    const [accessLost, setAccessLost] = useState<'session' | 'access' | null>(
        null,
    );
    const accessSummary = useRef<HTMLDivElement>(null);
    useEffect(() => {
        if (accessLost) accessSummary.current?.focus();
    }, [accessLost]);
    const onAccessLost = useCallback((reason: 'session' | 'access') => {
        setAccessLost(reason);
        setDirty(false);
    }, []);
    const onDirtyChange = useCallback((value: boolean) => setDirty(value), []);
    const leave = useSsoLeaveConfirmation(dirty && accessLost === null);
    function select(next: View) {
        if (next === view) return;
        leave.request(() => {
            setView(next);
            setFilter('all');
            setDirty(false);
        });
    }
    const configured = Object.values(providers).filter(
        (value) => Object.keys(value.checks).length === 0,
    ).length;
    if (accessLost)
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="SSO settings unavailable" />
                <SettingsLayout>
                    <Alert
                        variant="destructive"
                        role="alert"
                        ref={accessSummary}
                        tabIndex={-1}
                    >
                        <AlertDescription>
                            {accessLost === 'session'
                                ? 'Your session expired. SSO settings and mappings are concealed until you sign in and reload.'
                                : 'Your current access does not allow SSO administration. All settings and mappings are concealed.'}
                            {accessLost === 'session' && (
                                <Button asChild variant="outline">
                                    <a
                                        href="/login"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        Sign in again
                                    </a>
                                </Button>
                            )}
                            <Button asChild variant="outline">
                                <a href="/settings/sso">
                                    Reload with current access
                                </a>
                            </Button>
                        </AlertDescription>
                    </Alert>
                </SettingsLayout>
            </AppLayout>
        );
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            {leave.confirmation}
            <Head title="SSO settings" />
            <SettingsLayout>
                <div className="space-y-5">
                    <PageHeader
                        icon={Shield}
                        title="SSO settings"
                        titleChip={
                            <PageHeaderStatusChip variant="warning">
                                Provider verification pending
                            </PageHeaderStatusChip>
                        }
                        subline="One organisation · Sign-in, provisioning and group mappings"
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Configuration checks"
                                    value={`${configured}/2`}
                                    onClick={() => select('microsoft')}
                                >
                                    <PageHeaderMeterDonut
                                        percent={(configured / 2) * 100}
                                    />
                                    <PageHeaderMeterCaption>
                                        Local completeness; consent unverified
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Group mappings"
                                    onClick={() => select('groups')}
                                >
                                    <PageHeaderMeterBig>
                                        {currentMappings.length}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Existing role mappings
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                        filters={
                            <PageHeaderFilterSelect
                                label={
                                    view === 'groups' || view === 'urls'
                                        ? 'Provider'
                                        : 'Audience'
                                }
                                value={filter}
                                onChange={setFilter}
                                options={
                                    view === 'groups' || view === 'urls'
                                        ? [
                                              {
                                                  value: 'all',
                                                  label: 'All providers',
                                              },
                                              {
                                                  value: 'microsoft',
                                                  label: 'Microsoft',
                                              },
                                              {
                                                  value: 'google',
                                                  label: 'Google',
                                              },
                                          ]
                                        : [
                                              {
                                                  value: 'all',
                                                  label: 'All audiences',
                                              },
                                              {
                                                  value: 'staff',
                                                  label: 'Staff',
                                              },
                                              {
                                                  value: 'portal',
                                                  label: 'Portal',
                                              },
                                          ]
                                }
                            />
                        }
                        rail={
                            <PageHeaderRail
                                items={views}
                                value={view}
                                onSelect={select}
                                ariaLabel="SSO sections"
                            />
                        }
                    />
                    {view === 'microsoft' && (
                        <SsoProviderForm
                            onAccessLost={onAccessLost}
                            key="microsoft"
                            provider="microsoft"
                            initial={providers.microsoft}
                            onSaved={(value) =>
                                setProviders((current) => ({
                                    ...current,
                                    microsoft: value,
                                }))
                            }
                            onDirtyChange={onDirtyChange}
                            audience={filter}
                        />
                    )}
                    {view === 'google' && (
                        <SsoProviderForm
                            onAccessLost={onAccessLost}
                            key="google"
                            provider="google"
                            initial={providers.google}
                            onSaved={(value) =>
                                setProviders((current) => ({
                                    ...current,
                                    google: value,
                                }))
                            }
                            onDirtyChange={onDirtyChange}
                            audience={filter}
                        />
                    )}
                    {view === 'provisioning' && (
                        <SsoProvisioningForm
                            onAccessLost={onAccessLost}
                            initial={provisioning}
                            onSaved={setProvisioning}
                            onDirtyChange={onDirtyChange}
                            audience={filter}
                        />
                    )}
                    {view === 'groups' && (
                        <SsoGroupMappings
                            onDirtyChange={onDirtyChange}
                            onAccessLost={onAccessLost}
                            mappings={currentMappings}
                            roles={roles}
                            provider={filter}
                            onChange={setCurrentMappings}
                        />
                    )}
                    {view === 'urls' && (
                        <div className="space-y-5">
                            <Card>
                                <CardHeader>
                                    <CardTitle>Callback addresses</CardTitle>
                                    <CardDescription>
                                        Deployment application URL determines
                                        these addresses. They cannot be replaced
                                        by arbitrary form input.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-5">
                                    {(['microsoft', 'google'] as const)
                                        .filter(
                                            (provider) =>
                                                filter === 'all' ||
                                                provider === filter,
                                        )
                                        .map((provider) => (
                                            <div
                                                key={provider}
                                                className="space-y-3"
                                            >
                                                <CopyField
                                                    label={`${provider === 'microsoft' ? 'Microsoft' : 'Google'} staff callback`}
                                                    value={
                                                        providers[provider]
                                                            .callback_urls.staff
                                                    }
                                                />
                                                <CopyField
                                                    label={`${provider === 'microsoft' ? 'Microsoft' : 'Google'} portal callback`}
                                                    value={
                                                        providers[provider]
                                                            .callback_urls
                                                            .portal
                                                    }
                                                />
                                            </div>
                                        ))}
                                </CardContent>
                            </Card>
                            <Alert>
                                <AlertDescription>
                                    Saving and local checks do not verify
                                    provider consent or a successful sign-in.
                                    Provider registration and approved test
                                    sign-in require the organisation's
                                    authorized provider administrator. Sign-in
                                    scopes are Microsoft openid, profile and
                                    User.Read, or Google openid, profile and
                                    email. Mailbox access, sending email and
                                    calendars are configured separately.
                                </AlertDescription>
                            </Alert>
                            <div className="flex gap-2">
                                <Button asChild variant="outline">
                                    <a href="/settings/it-mailbox">
                                        Support mailbox settings
                                    </a>
                                </Button>
                                <Button asChild variant="outline">
                                    <a href="/settings/email">
                                        Outbound email settings
                                    </a>
                                </Button>
                            </div>
                        </div>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
