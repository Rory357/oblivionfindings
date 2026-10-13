import { PageHeader } from '@/components/page/page-header';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/datetime';
import { Head, Link } from '@inertiajs/react';
import { Camera, Settings2 } from 'lucide-react';
import { useState } from 'react';
import type { HikSetup } from './_hikvision-contract';
import HikvisionSourcePage from './_hikvision-page';
import type { RecorderSetup } from './_hikvision-recorder-contract';
import RecorderPage from './_hikvision-recorder-page';
import { sourceSetupReply, type CctvSourceSetup } from './_source-contract';
import { CctvSourceDialog } from './_source-dialogs';

export default function CctvSourcePage(
    props: CctvSourceSetup | HikSetup | RecorderSetup,
) {
    if ('provider' in props && props.provider === 'hikvision_recorder')
        return <RecorderPage {...props} />;
    if ('provider' in props && props.provider === 'hikvision')
        return <HikvisionSourcePage {...props} />;
    const {
        actor_id,
        camera_id,
        camera,
        revision,
        source,
        choices,
        choice,
        q,
        collector_id,
    } = props;
    try {
        const setup = sourceSetupReply(
            {
                actor_id,
                camera_id,
                camera,
                revision,
                source,
                choices,
                choice,
                q,
                collector_id,
            },
            actor_id,
            camera_id,
        );
        return (
            <CameraSourceWorkspace
                key={
                    actor_id +
                    ':' +
                    camera_id +
                    ':' +
                    revision +
                    ':' +
                    source?.enabled
                }
                setup={setup}
            />
        );
    } catch {
        return (
            <AppLayout
                breadcrumbs={[
                    { title: 'Home', href: '/dashboard' },
                    {
                        title: 'Security & Devices',
                        href: '/security-devices/security?tab=cctv',
                    },
                ]}
            >
                <Alert>
                    <AlertDescription role="alert">
                        Camera setup could not be verified. Return to Security
                        &amp; Devices and reopen this camera.
                    </AlertDescription>
                </Alert>
            </AppLayout>
        );
    }
}
export function CameraSourceWorkspace({ setup }: { setup: CctvSourceSetup }) {
    const [current, setCurrent] = useState(setup),
        [mode, setMode] = useState<
            'configure' | 'enable-stream' | 'disable' | null
        >(null);
    const camera = current.camera,
        source = current.source,
        base = '/security-devices/devices/' + camera.id;
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                {
                    title: 'Security & Devices',
                    href: '/security-devices/security?tab=cctv',
                },
                { title: camera.name, href: base },
                { title: 'Camera setup', href: base + '/live/source' },
            ]}
        >
            <Head title={camera.name + ' · Camera setup'} />
            <div className="space-y-5">
                <PageHeader
                    variant="profile"
                    title={camera.name}
                    icon={Camera}
                    wrapTitle
                    backHref={base}
                    subline={camera.site.name + ' · Camera setup'}
                    actions={
                        <>
                            <Button variant="secondary" asChild>
                                <Link href={base}>Camera details</Link>
                            </Button>
                            <Button onClick={() => setMode('configure')}>
                                <Settings2 aria-hidden="true" />
                                {source
                                    ? 'Edit connection'
                                    : 'Set up connection'}
                            </Button>
                        </>
                    }
                />
                {!source && (
                    <Alert>
                        <AlertDescription>
                            {current.revision > 0
                                ? 'The previous connection can no longer be verified. Review the current recorder, Site collector and network before saving a replacement.'
                                : 'Set up the recorder and Site collector for this camera. Live viewing will remain unavailable until the source and collector are ready.'}
                        </AlertDescription>
                    </Alert>
                )}
                <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
                    <Card className="min-w-0">
                        <CardHeader className="flex-row flex-wrap items-center justify-between gap-3">
                            <CardTitle className="text-section-title">
                                Camera connection
                            </CardTitle>
                            <StatusBadge
                                variant={
                                    source?.enabled ? 'success' : 'neutral'
                                }
                            >
                                {source
                                    ? source.enabled
                                        ? 'Saved'
                                        : 'Viewing off'
                                    : 'Not configured'}
                            </StatusBadge>
                        </CardHeader>
                        <CardContent className="space-y-5">
                            <dl className="grid gap-4 sm:grid-cols-2">
                                {[
                                    [
                                        'Recorder',
                                        source?.recorder_name ?? 'Not selected',
                                    ],
                                    [
                                        'Site collector',
                                        source?.collector_name ??
                                            'Not selected',
                                    ],
                                    [
                                        'Approved network',
                                        source?.discovery_scope_name ??
                                            'Not selected',
                                    ],
                                    [
                                        'Source verified',
                                        source
                                            ? formatDateTime(source.verified_at)
                                            : 'Not yet verified',
                                    ],
                                ].map(([label, value]) => (
                                    <div key={label} className="min-w-0">
                                        <dt className="text-caption text-muted-foreground">
                                            {label}
                                        </dt>
                                        <dd className="text-subtle [overflow-wrap:anywhere]">
                                            {value}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                            {source && (
                                <details className="rounded-lg border p-4">
                                    <summary className="frontline-focus cursor-pointer font-medium">
                                        Connection details
                                    </summary>
                                    <dl className="mt-4 grid gap-4 sm:grid-cols-2">
                                        {[
                                            [
                                                'UniFi console ID',
                                                source.console_id,
                                            ],
                                            [
                                                'Protect camera ID',
                                                source.provider_camera_id,
                                            ],
                                            [
                                                'Recorder IP address',
                                                source.stream_address,
                                            ],
                                            [
                                                'RTSPS port',
                                                String(source.stream_port),
                                            ],
                                            [
                                                'Certificate server name',
                                                source.tls_server_name,
                                            ],
                                            [
                                                'Protect version',
                                                source.application_version,
                                            ],
                                            [
                                                'Certificate SHA-256',
                                                source.tls_pin,
                                            ],
                                            [
                                                'Configuration revision',
                                                String(source.revision),
                                            ],
                                        ].map(([label, value]) => (
                                            <div
                                                key={label}
                                                className="min-w-0"
                                            >
                                                <dt className="text-caption text-muted-foreground">
                                                    {label}
                                                </dt>
                                                <dd className="text-subtle [overflow-wrap:anywhere]">
                                                    {value}
                                                </dd>
                                            </div>
                                        ))}
                                    </dl>
                                </details>
                            )}
                            <p className="text-subtle">
                                Saving the connection checks the equipment
                                identity and approved network. Open live view to
                                check the current picture.
                            </p>
                            {source?.enabled && (
                                <Button
                                    variant="outline"
                                    onClick={() => setMode('disable')}
                                >
                                    Turn off viewing in Oblivion
                                </Button>
                            )}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="flex-row flex-wrap items-center justify-between gap-3">
                            <CardTitle className="text-section-title">
                                Vendor stream
                            </CardTitle>
                            <StatusBadge
                                variant={
                                    source?.stream_state === 'verified'
                                        ? 'success'
                                        : source?.stream_state === 'unavailable'
                                          ? 'warning'
                                          : 'neutral'
                                }
                            >
                                {source?.stream_state === 'verified'
                                    ? 'Verified'
                                    : source?.stream_state === 'unavailable'
                                      ? 'Unavailable'
                                      : 'Not checked'}
                            </StatusBadge>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <p className="text-subtle">
                                UniFi Protect supplies the camera stream. The
                                Site collector carries video to authorised
                                viewers in Oblivion.
                            </p>
                            {source?.stream_verified_at && (
                                <p className="text-caption text-muted-foreground">
                                    Last verified{' '}
                                    {formatDateTime(source.stream_verified_at)}
                                </p>
                            )}
                            {source?.enabled &&
                                source.stream_state !== 'verified' && (
                                    <Button
                                        onClick={() => setMode('enable-stream')}
                                    >
                                        Enable camera stream
                                    </Button>
                                )}
                            <p className="text-caption text-muted-foreground">
                                Initial support is H264 video. Recordings remain
                                with the vendor. A saved source or verified
                                stream does not confirm that fresh video is
                                playing.
                            </p>
                        </CardContent>
                    </Card>
                </div>
            </div>
            {mode && (
                <CctvSourceDialog
                    setup={current}
                    mode={mode}
                    onClose={() => setMode(null)}
                    onSaved={(next) =>
                        setCurrent((old) => ({
                            ...old,
                            revision: next.revision,
                            source: next,
                        }))
                    }
                />
            )}
        </AppLayout>
    );
}
