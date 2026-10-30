import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
} from '@/components/page/page-header';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Bookmark, ChevronLeft, ChevronRight, Plus } from 'lucide-react';
import { useState } from 'react';
import {
    recordingBase,
    recordingPage,
    type RecordingDetail,
    type RecordingPage,
} from './_recording-contract';
import { RecordingReferenceDialog } from './_recording-dialog';
import {
    RecordingSourceNote,
    recordingTime,
    ReferenceLabel,
    ReferenceUnavailable,
    RequestedWindow,
} from './_recording-display';

export default function RecordingReferencesPage(props: RecordingPage) {
    const {
        actor_id,
        server_now,
        camera,
        source,
        current_source,
        window_limits,
        permissions,
        create_href,
        request_recovery_base_href,
        references,
    } = props;
    let page: RecordingPage;
    try {
        page = recordingPage(
            {
                actor_id,
                server_now,
                camera,
                source,
                current_source,
                window_limits,
                permissions,
                create_href,
                request_recovery_base_href,
                references,
            },
            actor_id,
            camera.id,
        );
    } catch {
        return <ReferenceUnavailable />;
    }
    return (
        <RecordingReferencesWorkspace
            key={actor_id + ':' + camera.id + ':' + source?.revision}
            page={page}
        />
    );
}
export function RecordingReferencesWorkspace({
    page,
}: {
    page: RecordingPage;
}) {
    const [open, setOpen] = useState(false),
        [created, setCreated] = useState<RecordingDetail | null>(null);
    const camera = page.camera,
        base = '/security-devices/devices/' + camera.id;
    const canCreate =
        page.permissions.can_create &&
        page.current_source.state === 'ready' &&
        page.source?.revision === page.current_source.revision &&
        page.create_href === recordingBase(camera.id);
    function close() {
        setOpen(false);
        if (created) router.visit(recordingBase(camera.id));
    }
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Security & Devices', href: '/security-devices' },
                { title: 'All devices', href: '/security-devices/devices' },
                { title: camera.name, href: base },
                { title: 'Recording references' },
            ]}
        >
            <Head title={camera.name + ' · Recording references'} />
            <div className="flex flex-col gap-5">
                <PageHeader
                    variant="profile"
                    title={camera.name}
                    icon={Bookmark}
                    wrapTitle
                    backHref={base}
                    subline={camera.site.name + ' · Recording references'}
                    actions={
                        <>
                            <PageHeaderGlassButton asChild>
                                <Link href={base}>Camera details</Link>
                            </PageHeaderGlassButton>
                            {canCreate && (
                                <PageHeaderPrimaryButton
                                    icon={Plus}
                                    onClick={() => setOpen(true)}
                                >
                                    Add recording reference
                                </PageHeaderPrimaryButton>
                            )}
                        </>
                    }
                />
                <RecordingSourceNote source={page.current_source} />
                <Card>
                    <CardHeader>
                        <CardTitle className="text-section-title">
                            Saved camera and time windows
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-5">
                        <p className="text-sm text-muted-foreground">
                            Use these references to identify footage to check
                            with the camera provider. Saving a reference does
                            not verify, copy or preserve a recording.
                        </p>
                        {!page.permissions.can_create && (
                            <p className="text-caption text-muted-foreground">
                                Creating references requires separate permission
                                and a current camera connection.
                            </p>
                        )}
                        {created && (
                            <Alert>
                                <AlertDescription>
                                    Reference saved.{' '}
                                    <Link
                                        className="frontline-focus font-medium text-primary underline"
                                        href={created.reference.reader_href}
                                    >
                                        Open the saved reference
                                    </Link>
                                </AlertDescription>
                            </Alert>
                        )}
                        {page.references.items.length === 0 ? (
                            <EmptyState
                                variant="compact"
                                icon={Bookmark}
                                title="No recording references"
                                description="Save a reviewed camera and time window when you need to check an incident."
                            />
                        ) : (
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>
                                                Requested window
                                            </TableHead>
                                            <TableHead>Recorder</TableHead>
                                            <TableHead>Saved</TableHead>
                                            <TableHead>
                                                <span className="sr-only">
                                                    Open reference
                                                </span>
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {page.references.items.map((item) => (
                                            <TableRow key={item.reference_uuid}>
                                                <TableCell>
                                                    <RequestedWindow
                                                        start={
                                                            item.requested_start_at
                                                        }
                                                        end={
                                                            item.requested_end_at
                                                        }
                                                        serverNow={
                                                            page.server_now
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    {item.recorder.name}
                                                </TableCell>
                                                <TableCell>
                                                    <div className="space-y-2">
                                                        <p>
                                                            {recordingTime(
                                                                item.created_at,
                                                            )}
                                                        </p>
                                                        <ReferenceLabel />
                                                    </div>
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={
                                                                item.reader_href
                                                            }
                                                        >
                                                            Open reference
                                                        </Link>
                                                    </Button>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                        {(page.references.previous_href ||
                            page.references.next_href) && (
                            <nav
                                aria-label="Recording reference pages"
                                className="flex items-center justify-between gap-3"
                            >
                                <div>
                                    {page.references.previous_href && (
                                        <Button variant="outline" asChild>
                                            <Link
                                                href={
                                                    page.references
                                                        .previous_href
                                                }
                                            >
                                                <ChevronLeft aria-hidden="true" />
                                                Previous
                                            </Link>
                                        </Button>
                                    )}
                                </div>
                                <p className="text-caption text-muted-foreground">
                                    Up to 20 references per page
                                </p>
                                <div>
                                    {page.references.next_href && (
                                        <Button variant="outline" asChild>
                                            <Link
                                                href={page.references.next_href}
                                            >
                                                Next
                                                <ChevronRight aria-hidden="true" />
                                            </Link>
                                        </Button>
                                    )}
                                </div>
                            </nav>
                        )}
                    </CardContent>
                </Card>
            </div>
            {canCreate && (
                <RecordingReferenceDialog
                    page={page}
                    open={open}
                    onClose={close}
                    onSaved={setCreated}
                />
            )}
        </AppLayout>
    );
}
