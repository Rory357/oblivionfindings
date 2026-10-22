import { ItModuleShell } from '@/components/it/it-module-shell';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
} from '@/components/page/page-header';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Monitor, Package, Plus, Search, ShieldCheck } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    softwareBase,
    softwareIndexReply,
    softwareStatusLabel,
    softwareStatuses,
    type SoftwareFilter,
    type SoftwareIndex,
    type SoftwarePackage,
    type SoftwareStatus,
} from './_contracts';
import { SoftwarePackageDetails, SoftwareTransitionDialog } from './_dialogs';
import { SoftwareUploadDialog } from './_upload';

type Props = SoftwareIndex & {
    identity_confirmed?: boolean;
    identity_confirm_href?: string;
    can_confirm_identity?: boolean;
};
const variants: Record<SoftwareStatus, StatusVariant> = {
    published: 'success',
    pending_review: 'info',
    uploaded: 'neutral',
    withdrawn: 'warning',
};
export default function ApprovedSoftware(props: Props) {
    return (
        <SoftwareWorkspace
            key={props.actor_id + ':' + props.pagination.page_key}
            {...props}
        />
    );
}
export function SoftwareWorkspace(props: Props) {
    const [search, setSearch] = useState(props.query.search),
        [upload, setUpload] = useState(false);
    const [rows, setRows] = useState(props.packages);
    const [decision, setDecision] = useState<{
        item: SoftwarePackage;
        action: 'submit' | 'review' | 'withdraw';
    } | null>(null);
    useEffect(() => {
        setRows(props.packages);
        setDecision(null);
        setUpload(false);
    }, [props.packages]);
    let valid = true;
    try {
        softwareIndexReply(props, props.actor_id, props.query.device_id);
    } catch {
        valid = false;
    }
    function url(status: SoftwareFilter, term = '') {
        return (
            softwareBase +
            '?' +
            new URLSearchParams({
                expected_actor_id: String(props.actor_id),
                status,
                ...(term.trim() ? { search: term.trim() } : {}),
                ...(props.query.device_id
                    ? { device_id: String(props.query.device_id) }
                    : {}),
            })
        );
    }
    function searchCatalogue() {
        router.visit(url(props.query.status, search), { preserveScroll: true });
    }
    function changed(item: SoftwarePackage) {
        setRows((current) =>
            current.map((row) => (row.uuid === item.uuid ? item : row)),
        );
    }
    function refresh() {
        router.reload();
    }
    const confirmHref =
        softwareBase + '/confirm-identity?expected_actor_id=' + props.actor_id;
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'IT & Support', href: '/it' },
                { title: 'Approved software', href: softwareBase },
            ]}
        >
            <Head title="Approved software" />
            <ItModuleShell>
                <PageHeader
                    title="Approved software"
                    icon={Package}
                    subline="Reviewed Windows installers and their approval history"
                    actions={
                        <>
                            <PageHeaderSearch
                                value={search}
                                onChange={setSearch}
                                onKeyDown={(event) => {
                                    if (event.key === 'Enter')
                                        searchCatalogue();
                                }}
                                ariaLabel="Search approved software"
                                placeholder="Application or publisher…"
                            />
                            <PageHeaderGlassButton
                                icon={Search}
                                onClick={searchCatalogue}
                            >
                                Search
                            </PageHeaderGlassButton>
                            <PageHeaderGlassButton asChild icon={Monitor}>
                                <Link
                                    href={
                                        props.query.device_id
                                            ? '/it/remote-support?device=' +
                                              props.query.device_id
                                            : '/it/remote-support'
                                    }
                                >
                                    {props.query.device_id
                                        ? 'Computer support'
                                        : 'Choose a computer'}
                                </Link>
                            </PageHeaderGlassButton>
                            {valid && props.can_upload && (
                                <PageHeaderPrimaryButton
                                    icon={Plus}
                                    onClick={() => setUpload(true)}
                                >
                                    Add release
                                </PageHeaderPrimaryButton>
                            )}
                        </>
                    }
                    meters={
                        valid && props.summary ? (
                            <>
                                {(
                                    [
                                        'published',
                                        'pending_review',
                                        'uploaded',
                                        'withdrawn',
                                    ] as const
                                ).map((status) => (
                                    <PageHeaderMeterBlock
                                        key={status}
                                        label={softwareStatusLabel[status]}
                                        href={url(status)}
                                    >
                                        <PageHeaderMeterBig>
                                            {props.summary![status]}
                                        </PageHeaderMeterBig>
                                    </PageHeaderMeterBlock>
                                ))}
                            </>
                        ) : undefined
                    }
                    filters={
                        props.query.search ? (
                            <PageHeaderFilterButton
                                active
                                onClick={() => {
                                    setSearch('');
                                    router.visit(url(props.query.status), {
                                        preserveScroll: true,
                                    });
                                }}
                            >
                                Clear search: {props.query.search}
                            </PageHeaderFilterButton>
                        ) : undefined
                    }
                    rail={
                        <PageHeaderRail
                            items={softwareStatuses.map((status) => ({
                                key: status,
                                label: softwareStatusLabel[status],
                            }))}
                            value={props.query.status}
                            onSelect={(status) =>
                                router.visit(url(status), {
                                    preserveScroll: true,
                                })
                            }
                            ariaLabel="Software status"
                        />
                    }
                />
                <div className="space-y-4 pt-5">
                    {!valid ? (
                        <Alert>
                            <AlertDescription>
                                Software information could not be verified.
                                Reload the catalogue before making changes.{' '}
                                <Button variant="outline" onClick={refresh}>
                                    Reload catalogue
                                </Button>
                            </AlertDescription>
                        </Alert>
                    ) : (
                        <>
                            {!props.available && (
                                <Alert>
                                    <AlertDescription>
                                        Software deployment is not available
                                        yet. Releases can be prepared and
                                        reviewed here.
                                    </AlertDescription>
                                </Alert>
                            )}
                            {props.can_confirm_identity &&
                                !props.identity_confirmed &&
                                props.identity_confirm_href === confirmHref && (
                                    <Card className="flex-row flex-wrap items-center justify-between gap-3 p-4">
                                        <div className="min-w-0">
                                            <p className="font-medium">
                                                Confirm your identity before
                                                reviewing releases
                                            </p>
                                            <p className="text-subtle">
                                                Approving or withdrawing an
                                                installer needs a recent
                                                identity check.
                                            </p>
                                        </div>
                                        <Button variant="outline" asChild>
                                            <a href={confirmHref}>
                                                <ShieldCheck aria-hidden="true" />
                                                Confirm identity to review
                                            </a>
                                        </Button>
                                    </Card>
                                )}
                            {props.pagination.position_reset && (
                                <p className="text-subtle" role="status">
                                    The catalogue changed. Your view has
                                    returned to the first page.
                                </p>
                            )}
                            {rows.length ? (
                                <ul className="divide-y rounded-xl border bg-card">
                                    {rows.map((item) => (
                                        <li
                                            key={item.uuid}
                                            className="min-w-0 p-4"
                                        >
                                            <div className="flex min-w-0 flex-wrap items-start gap-4">
                                                <div className="min-w-0 flex-1 [overflow-wrap:anywhere]">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <h2 className="text-section-title">
                                                            {item.name}
                                                        </h2>
                                                        <StatusBadge
                                                            variant={
                                                                variants[
                                                                    item.status
                                                                ]
                                                            }
                                                        >
                                                            {
                                                                softwareStatusLabel[
                                                                    item.status
                                                                ]
                                                            }
                                                        </StatusBadge>
                                                    </div>
                                                    <p className="text-subtle mt-1">
                                                        {item.product_version} ·{' '}
                                                        {item.publisher} ·{' '}
                                                        {item.architecture ===
                                                        'amd64'
                                                            ? 'Windows x64'
                                                            : 'Windows ARM64'}
                                                    </p>
                                                </div>
                                                <div className="flex flex-wrap gap-2">
                                                    {item.can_submit && (
                                                        <Button
                                                            variant="outline"
                                                            onClick={() =>
                                                                setDecision({
                                                                    item,
                                                                    action: 'submit',
                                                                })
                                                            }
                                                        >
                                                            Submit for review
                                                        </Button>
                                                    )}
                                                    {item.can_review && (
                                                        <Button
                                                            onClick={() =>
                                                                setDecision({
                                                                    item,
                                                                    action: 'review',
                                                                })
                                                            }
                                                        >
                                                            Review release
                                                        </Button>
                                                    )}
                                                    {item.can_withdraw && (
                                                        <Button
                                                            variant="outline"
                                                            onClick={() =>
                                                                setDecision({
                                                                    item,
                                                                    action: 'withdraw',
                                                                })
                                                            }
                                                        >
                                                            Withdraw
                                                        </Button>
                                                    )}
                                                </div>
                                            </div>
                                            <details className="mt-3">
                                                <summary className="frontline-focus text-caption cursor-pointer text-muted-foreground">
                                                    Release details and review
                                                    history
                                                </summary>
                                                <div className="mt-4">
                                                    <SoftwarePackageDetails
                                                        item={item}
                                                    />
                                                </div>
                                            </details>
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <Card className="gap-0 p-6">
                                    <h2 className="text-section-title">
                                        {props.query.search
                                            ? 'No matching software'
                                            : 'No releases in this view'}
                                    </h2>
                                    <p className="text-subtle mt-2">
                                        {props.query.search
                                            ? 'Try another application or publisher.'
                                            : 'New installers appear as drafts and need independent review before they can be deployed.'}
                                    </p>
                                </Card>
                            )}
                            <nav
                                aria-label="Software pages"
                                className="flex flex-wrap items-center justify-between gap-3"
                            >
                                <p className="text-caption text-muted-foreground">
                                    {props.pagination.shown} releases on this
                                    page
                                    {props.summary
                                        ? ' · counts above cover your permitted catalogue'
                                        : ''}
                                </p>
                                <div className="flex gap-2">
                                    <Button
                                        variant="outline"
                                        disabled={
                                            !props.pagination.previous_href
                                        }
                                        onClick={() =>
                                            props.pagination.previous_href &&
                                            router.visit(
                                                props.pagination.previous_href,
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Previous
                                    </Button>
                                    <Button
                                        variant="outline"
                                        disabled={!props.pagination.next_href}
                                        onClick={() =>
                                            props.pagination.next_href &&
                                            router.visit(
                                                props.pagination.next_href,
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Next
                                    </Button>
                                </div>
                            </nav>
                        </>
                    )}
                </div>
                {valid && upload && (
                    <SoftwareUploadDialog
                        actorId={props.actor_id}
                        maxBytes={props.max_upload_bytes}
                        onUploaded={() => undefined}
                        onClose={() => {
                            setUpload(false);
                            refresh();
                        }}
                    />
                )}
                {valid && decision && (
                    <SoftwareTransitionDialog
                        actorId={props.actor_id}
                        item={decision.item}
                        action={decision.action}
                        onChanged={changed}
                        onClose={() => {
                            setDecision(null);
                            refresh();
                        }}
                    />
                )}
            </ItModuleShell>
        </AppLayout>
    );
}
