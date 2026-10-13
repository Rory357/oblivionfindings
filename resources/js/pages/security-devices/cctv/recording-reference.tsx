import { PageHeader } from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { Bookmark } from 'lucide-react';
import {
    recordingBase,
    recordingDetail,
    type RecordingDetail,
} from './_recording-contract';
import {
    RecordingSourceNote,
    recordingTime,
    ReferenceLabel,
    ReferenceUnavailable,
    RequestedWindow,
} from './_recording-display';

export default function RecordingReferencePage(props: RecordingDetail) {
    const {
        actor_id,
        server_now,
        camera,
        recorder,
        reference,
        current_source,
        permissions,
    } = props;
    let detail: RecordingDetail;
    try {
        detail = recordingDetail(
            {
                actor_id,
                server_now,
                camera,
                recorder,
                reference,
                current_source,
                permissions,
            },
            actor_id,
            camera.id,
            reference.reference_uuid,
        );
    } catch {
        return <ReferenceUnavailable />;
    }
    return (
        <RecordingReferenceWorkspace
            key={actor_id + ':' + reference.reference_uuid}
            detail={detail}
        />
    );
}
export function RecordingReferenceWorkspace({
    detail,
}: {
    detail: RecordingDetail;
}) {
    const { camera, recorder, reference: r } = detail,
        base = recordingBase(camera.id);
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                {
                    title: 'Security & Devices',
                    href: '/security-devices/security?tab=cctv',
                },
                {
                    title: camera.name,
                    href: '/security-devices/devices/' + camera.id,
                },
                { title: 'Recording references', href: base },
                { title: 'Saved reference', href: r.reader_href },
            ]}
        >
            <Head title={camera.name + ' · Saved recording reference'} />
            <div className="space-y-5">
                <PageHeader
                    variant="profile"
                    title={camera.name}
                    icon={Bookmark}
                    wrapTitle
                    backHref={base}
                    subline={camera.site.name + ' · Saved recording reference'}
                    actions={
                        <Button variant="secondary" asChild>
                            <Link href={base}>All references</Link>
                        </Button>
                    }
                />
                <RecordingSourceNote source={detail.current_source} />
                <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
                    <Card>
                        <CardHeader className="flex-row flex-wrap items-center justify-between gap-3">
                            <CardTitle className="text-section-title">
                                Recording reference
                            </CardTitle>
                            <ReferenceLabel />
                        </CardHeader>
                        <CardContent className="space-y-6">
                            <div className="space-y-2">
                                <h2 className="text-subtle">
                                    Requested time window
                                </h2>
                                <RequestedWindow
                                    start={r.requested_start_at}
                                    end={r.requested_end_at}
                                    serverNow={detail.server_now}
                                />
                            </div>
                            <dl className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <dt className="text-caption text-muted-foreground">
                                        Recorder
                                    </dt>
                                    <dd>{recorder.name}</dd>
                                </div>
                                <div>
                                    <dt className="text-caption text-muted-foreground">
                                        Saved by
                                    </dt>
                                    <dd>{r.created_by.name}</dd>
                                </div>
                                <div>
                                    <dt className="text-caption text-muted-foreground">
                                        Saved
                                    </dt>
                                    <dd>{recordingTime(r.created_at)}</dd>
                                </div>
                                <div>
                                    <dt className="text-caption text-muted-foreground">
                                        Camera source checked
                                    </dt>
                                    <dd>
                                        {recordingTime(r.source_verified_at)}
                                    </dd>
                                </div>
                            </dl>
                            <div className="space-y-2">
                                <h2 className="text-subtle">Reason</h2>
                                <p className="text-sm break-words whitespace-pre-wrap">
                                    {r.purpose}
                                </p>
                            </div>
                            <details className="rounded-lg border border-border p-4">
                                <summary className="frontline-focus cursor-pointer text-sm font-medium">
                                    Source reference details
                                </summary>
                                <dl className="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                                    {[
                                        ['Reference', r.reference_uuid],
                                        ['Provider', 'UniFi Protect'],
                                        ['Protect version', r.protect_version],
                                        [
                                            'Source revision',
                                            String(r.source_revision),
                                        ],
                                        ['Console ID', r.console_id],
                                        ['Camera ID', r.provider_camera_id],
                                        ['Recorder ID', r.provider_recorder_id],
                                    ].map(([label, value]) => (
                                        <div key={label} className="min-w-0">
                                            <dt className="text-caption text-muted-foreground">
                                                {label}
                                            </dt>
                                            <dd className="break-all">
                                                {value}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                                <p className="text-caption mt-4 text-muted-foreground">
                                    These are the reviewed connection details
                                    when the reference was saved. They do not
                                    establish the camera’s location during the
                                    incident window.
                                </p>
                            </details>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-section-title">
                                Footage not verified or preserved
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4 text-sm text-muted-foreground">
                            <p>
                                This reference identifies a camera and requested
                                time. No recording has been checked or copied.
                            </p>
                            <dl className="space-y-3">
                                <div>
                                    <dt className="font-medium text-foreground">
                                        Recorded coverage
                                    </dt>
                                    <dd>Not verified</dd>
                                </div>
                                <div>
                                    <dt className="font-medium text-foreground">
                                        Vendor retention
                                    </dt>
                                    <dd>Unknown</dd>
                                </div>
                                <div>
                                    <dt className="font-medium text-foreground">
                                        Preservation
                                    </dt>
                                    <dd>No preserved footage</dd>
                                </div>
                            </dl>
                            <p>
                                Use a new reference for a different time window.
                                This saved reference remains unchanged.
                            </p>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
