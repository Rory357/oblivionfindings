import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
} from '@/components/page/page-header';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/datetime';
import { Head, Link } from '@inertiajs/react';
import { Camera, Settings2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { hikBase, hikSetupReply, type HikSetup } from './_hikvision-contract';
import { HikvisionSetupDialog } from './_hikvision-dialog';
import { hikvisionJson } from './live-transport';

const reasons = {
    not_mapped:
        'Check the camera and review a channel before saving a connection.',
    connection_changed:
        'The saved connection has changed. A manager needs to check it again.',
    connection_unavailable:
        'Viewing is unavailable. A manager needs to check the approved equipment support, credential access and Site collector.',
    active_connection_exists:
        'Another connection is still enabled. Turn it off before enabling the new connection.',
};
export default function HikvisionSourcePage(props: HikSetup) {
    const {
        provider,
        actor_id,
        camera_id,
        camera,
        revision,
        source,
        active_source,
        readiness,
        choices,
        choice,
        q,
        collector_id,
    } = props;
    let setup: HikSetup | null = null;
    try {
        setup = hikSetupReply(
            {
                provider,
                actor_id,
                camera_id,
                camera,
                revision,
                source,
                active_source,
                readiness,
                choices,
                choice,
                q,
                collector_id,
            },
            actor_id,
            camera_id,
        );
    } catch {
        setup = null;
    }
    return setup ? (
        <Workspace
            key={actor_id + ':' + camera_id + ':' + revision}
            setup={setup}
        />
    ) : (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Security & Devices', href: '/security-devices' },
                { title: 'All devices', href: '/security-devices/devices' },
            ]}
        >
            <Alert>
                <AlertDescription role="alert">
                    Camera setup could not be verified. Return to Security &amp;
                    Devices and reopen this camera.
                </AlertDescription>
            </Alert>
        </AppLayout>
    );
}
function Workspace({ setup }: { setup: HikSetup }) {
    const [current, setCurrent] = useState(setup),
        [mode, setMode] = useState<'configure' | 'enable' | 'disable' | null>(
            null,
        ),
        [notice, setNotice] = useState(''),
        [busy, setBusy] = useState(false);
    const call = useRef<AbortController | null>(null);
    useEffect(() => () => call.current?.abort(), []);
    const base = '/security-devices/devices/' + current.camera_id;
    async function refresh() {
        call.current?.abort();
        const controller = new AbortController();
        call.current = controller;
        setBusy(true);
        try {
            const next = hikSetupReply(
                await hikvisionJson(
                    current.camera_id,
                    current.actor_id,
                    hikBase(current.camera_id) + '/setup',
                    controller.signal,
                ),
                current.actor_id,
                current.camera_id,
            );
            if (!controller.signal.aborted) {
                setCurrent(next);
                setNotice('');
            }
        } catch {
            if (!controller.signal.aborted)
                setNotice(
                    'Current setup could not be checked. Refresh before making another change.',
                );
        } finally {
            if (!controller.signal.aborted) setBusy(false);
        }
    }
    const source = current.source,
        active = current.active_source;
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Security & Devices', href: '/security-devices' },
                { title: 'All devices', href: '/security-devices/devices' },
                { title: current.camera.name, href: base },
                { title: 'Camera setup' },
            ]}
        >
            <Head title={current.camera.name + ' · Camera setup'} />
            <div className="flex flex-col gap-5">
                <PageHeader
                    variant="profile"
                    title={current.camera.name}
                    icon={Camera}
                    wrapTitle
                    backHref={base}
                    subline={
                        current.camera.site.name + ' · Hikvision camera setup'
                    }
                    actions={
                        <>
                            <PageHeaderGlassButton asChild>
                                <Link href={base}>Camera details</Link>
                            </PageHeaderGlassButton>
                            <PageHeaderPrimaryButton
                                icon={Settings2}
                                disabled={
                                    busy ||
                                    !!notice ||
                                    !current.readiness.can_discover
                                }
                                onClick={() => setMode('configure')}
                            >
                                {source
                                    ? 'Check connection'
                                    : 'Set up connection'}
                            </PageHeaderPrimaryButton>
                        </>
                    }
                />
                {notice && (
                    <Alert>
                        <AlertDescription role="alert">
                            {notice}
                        </AlertDescription>
                    </Alert>
                )}
                {!current.readiness.can_discover && (
                    <Alert>
                        <AlertDescription>
                            Camera checks have not been enabled for this
                            installation. An administrator needs to approve the
                            Site collector and protected settings.
                        </AlertDescription>
                    </Alert>
                )}
                {current.readiness.reason_code && (
                    <Alert>
                        <AlertDescription>
                            {reasons[current.readiness.reason_code]}
                        </AlertDescription>
                    </Alert>
                )}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-section-title">
                            Camera connection
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <dl className="grid gap-3 sm:grid-cols-2">
                            <div>
                                <dt className="text-caption text-muted-foreground">
                                    Site
                                </dt>
                                <dd>{current.camera.site.name}</dd>
                            </div>
                            <div>
                                <dt className="text-caption text-muted-foreground">
                                    Camera model
                                </dt>
                                <dd>
                                    {current.camera.model ?? 'Not recorded'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-caption text-muted-foreground">
                                    Selected channel
                                </dt>
                                <dd>
                                    {source?.channel_name ??
                                        'No verified selection'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-caption text-muted-foreground">
                                    Camera last checked
                                </dt>
                                <dd>
                                    {source?.observed_at
                                        ? formatDateTime(source.observed_at)
                                        : 'Not checked'}
                                </dd>
                            </div>
                        </dl>
                        <StatusBadge variant={active ? 'info' : 'neutral'}>
                            {active ? 'Viewing enabled' : 'Viewing off'}
                        </StatusBadge>
                        <p className="text-subtle">
                            {active?.stream_verified_at
                                ? 'A viewing attempt last succeeded at ' +
                                  formatDateTime(active.stream_verified_at) +
                                  '. Each new attempt checks access and the camera again.'
                                : 'Video has not yet been verified. Saving or enabling a connection does not confirm that video can be viewed.'}
                        </p>
                        {active && source && active.id !== source.id && (
                            <p className="text-subtle">
                                The previous connection remains enabled. Turning
                                it off ends its current viewing sessions. The
                                new connection needs separate approval.
                            </p>
                        )}
                        <div className="flex flex-wrap gap-3">
                            <Button
                                variant="outline"
                                disabled={busy}
                                onClick={() => void refresh()}
                            >
                                {busy ? 'Checking…' : 'Refresh setup'}
                            </Button>
                            {current.readiness.can_enable && (
                                <Button
                                    disabled={busy || !!notice}
                                    onClick={() => setMode('enable')}
                                >
                                    Turn on viewing
                                </Button>
                            )}
                            {current.readiness.can_disable && (
                                <Button
                                    variant="destructive"
                                    disabled={busy || !!notice}
                                    onClick={() => setMode('disable')}
                                >
                                    Turn off viewing
                                </Button>
                            )}
                            {current.readiness.can_view && !notice && (
                                <Button variant="outline" asChild>
                                    <Link href={base + '/live'}>
                                        View camera
                                    </Link>
                                </Button>
                            )}
                        </div>
                        <p className="text-caption text-muted-foreground">
                            Audio is off. These controls do not change the
                            camera’s recording settings.
                        </p>
                    </CardContent>
                </Card>
                {mode && (
                    <HikvisionSetupDialog
                        key={mode + ':' + current.revision}
                        setup={current}
                        mode={mode}
                        onClose={() => setMode(null)}
                        onCommitted={() => {
                            setMode(null);
                            setNotice(
                                'Refresh setup to check the current connection.',
                            );
                            void refresh();
                        }}
                    />
                )}
            </div>
        </AppLayout>
    );
}
