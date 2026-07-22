import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import {
    BedDouble,
    CheckCircle2,
    CircleAlert,
    Mail,
    MapPin,
    Pencil,
    Phone,
    Plus,
    ShieldAlert,
    StickyNote,
    Trash2,
    Users,
    type LucideIcon,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { ConfirmAction } from '../_confirm-action';
import {
    AddSiteNoteDialog,
    EditLocationDialog,
    EditSafetyDialog,
    EditSiteLineDialog,
} from '../_overview-dialogs';
import SiteOverviewMapCard from '../_overview-map-card';
import SiteGeofenceDialog from '../_site-geofence-dialog';
import type {
    SiteProfileAttentionData,
    SiteProfileHeroData,
    SiteProfileOverviewData,
    SiteProfileSite,
} from '../show';
import { SiteAttentionPanel } from './attention-panel';

export function SiteProfileOverview({
    site,
    hero,
    overview,
    attention,
    onNavigate,
}: {
    site: SiteProfileSite;
    hero: SiteProfileHeroData;
    overview: SiteProfileOverviewData;
    attention: SiteProfileAttentionData;
    onNavigate: (tab: string) => void;
}) {
    const [siteLineOpen, setSiteLineOpen] = useState(false);
    const [locationOpen, setLocationOpen] = useState(false);
    const [safetyOpen, setSafetyOpen] = useState(false);
    const [noteOpen, setNoteOpen] = useState(false);
    const [geofenceOpen, setGeofenceOpen] = useState(false);
    const canManage = overview.can_manage;

    return (
        <div className="space-y-6">
            {hero.readiness.missing_critical > 0 ? (
                <button
                    type="button"
                    onClick={() => onNavigate('readiness')}
                    className="flex min-h-11 w-full items-center gap-3 rounded-xl border border-status-warning/30 bg-status-warning-bg px-4 py-3 text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <CircleAlert className="h-5 w-5 shrink-0 text-status-warning" />
                    <span className="min-w-0 flex-1">
                        <span className="block font-medium">
                            Complete {hero.readiness.missing_critical} critical
                            setup{' '}
                            {hero.readiness.missing_critical === 1
                                ? 'item'
                                : 'items'}
                        </span>
                        <span className="block text-sm text-muted-foreground">
                            This active Site has an incomplete operational
                            profile.
                        </span>
                    </span>
                    <span className="text-sm font-medium">Review</span>
                </button>
            ) : null}

            <div className="grid gap-4 lg:grid-cols-4">
                <OverviewStat
                    label={hero.occupancy.label}
                    value={`${hero.occupancy.occupied}/${hero.occupancy.total}`}
                    detail="occupied"
                    icon={BedDouble}
                />
                <OverviewStat
                    label="Readiness"
                    value={`${hero.readiness.score}%`}
                    detail={
                        hero.readiness.missing_critical
                            ? 'critical setup remains'
                            : 'core setup complete'
                    }
                    icon={CheckCircle2}
                />
                <OverviewStat
                    label="Attention"
                    value={String(hero.attention.total)}
                    detail={
                        hero.attention.critical
                            ? `${hero.attention.critical} critical`
                            : 'no critical items'
                    }
                    icon={ShieldAlert}
                />
                <OverviewStat
                    label="People"
                    value={String(hero.avatars.length)}
                    detail="shown in Site hero"
                    icon={Users}
                />
            </div>

            <div className="grid gap-4 xl:grid-cols-2">
                <OverviewCard
                    title="Site line & key contacts"
                    icon={Phone}
                    action={
                        canManage ? (
                            <Button
                                variant="outline"
                                size="sm"
                                className="min-h-11"
                                onClick={() => setSiteLineOpen(true)}
                            >
                                <Pencil className="mr-2 h-4 w-4" /> Edit Site
                                line
                            </Button>
                        ) : null
                    }
                >
                    <div className="divide-y">
                        <ContactLine
                            icon={Phone}
                            label="Phone"
                            value={site.phone}
                            href={site.phone ? `tel:${site.phone}` : undefined}
                        />
                        <ContactLine
                            icon={Mail}
                            label="Email"
                            value={site.email}
                            href={
                                site.email ? `mailto:${site.email}` : undefined
                            }
                        />
                    </div>
                    <div className="mt-4 space-y-3 border-t pt-4">
                        {overview.contacts.slice(0, 5).map((contact) => (
                            <div
                                key={contact.id}
                                className="flex min-h-11 items-center justify-between gap-3"
                            >
                                <div className="min-w-0">
                                    <p className="truncate text-sm font-semibold">
                                        {contact.name}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {contact.role ||
                                            contact.type.replaceAll('_', ' ')}
                                    </p>
                                </div>
                                <div className="flex gap-2">
                                    {contact.phone ? (
                                        <a
                                            className="rounded-md p-2 hover:bg-muted"
                                            aria-label={`Call ${contact.name}`}
                                            href={`tel:${contact.phone}`}
                                        >
                                            <Phone className="h-4 w-4" />
                                        </a>
                                    ) : null}
                                    {contact.email ? (
                                        <a
                                            className="rounded-md p-2 hover:bg-muted"
                                            aria-label={`Email ${contact.name}`}
                                            href={`mailto:${contact.email}`}
                                        >
                                            <Mail className="h-4 w-4" />
                                        </a>
                                    ) : null}
                                </div>
                            </div>
                        ))}
                        <Button
                            type="button"
                            variant="ghost"
                            className="min-h-11 w-full"
                            onClick={() => onNavigate('contacts')}
                        >
                            Open full contact register
                        </Button>
                    </div>
                </OverviewCard>

                <OverviewCard
                    title="Location, access & geofence"
                    icon={MapPin}
                    action={
                        canManage ? (
                            <Button
                                variant="outline"
                                size="sm"
                                className="min-h-11"
                                onClick={() => setLocationOpen(true)}
                            >
                                <Pencil className="mr-2 h-4 w-4" /> Edit
                            </Button>
                        ) : null
                    }
                >
                    <p className="text-sm font-medium">
                        {overview.location.address || 'Address not recorded'}
                    </p>
                    {overview.location.region ? (
                        <p className="mt-1 text-sm text-muted-foreground">
                            {overview.location.region}
                        </p>
                    ) : null}
                    <div className="mt-3 rounded-lg border bg-muted/20 p-3">
                        <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            Access instructions
                        </p>
                        <p className="mt-1 text-sm whitespace-pre-wrap">
                            {overview.location.access_instructions ||
                                'No access instructions recorded.'}
                        </p>
                    </div>
                    <div className="mt-4">
                        <SiteOverviewMapCard
                            siteId={site.id}
                            siteName={site.name}
                            latitude={overview.location.latitude}
                            longitude={overview.location.longitude}
                            geofences={overview.geofences}
                            canManage={overview.can_manage_geofences}
                            onEditGeofence={() => setGeofenceOpen(true)}
                        />
                    </div>
                </OverviewCard>

                <OverviewCard
                    title="Safety & medication"
                    icon={ShieldAlert}
                    action={
                        canManage ? (
                            <Button
                                variant="outline"
                                size="sm"
                                className="min-h-11"
                                onClick={() => setSafetyOpen(true)}
                            >
                                <Pencil className="mr-2 h-4 w-4" /> Edit
                            </Button>
                        ) : null
                    }
                >
                    <div className="flex flex-wrap gap-2">
                        <Badge
                            variant="outline"
                            className={cn(
                                overview.safety.is_high_risk &&
                                    'border-status-critical/30 bg-status-critical-bg text-status-critical',
                            )}
                        >
                            {overview.safety.is_high_risk
                                ? 'High-risk Site'
                                : 'Standard risk'}
                        </Badge>
                        {overview.safety.is_high_needs ? (
                            <Badge variant="outline">High needs</Badge>
                        ) : null}
                    </div>
                    {overview.safety.risk_notes ? (
                        <p className="mt-3 text-sm whitespace-pre-wrap">
                            {overview.safety.risk_notes}
                        </p>
                    ) : null}
                    <dl className="mt-4 space-y-3 text-sm">
                        <div>
                            <dt className="font-medium">
                                Emergency plan location
                            </dt>
                            <dd className="text-muted-foreground">
                                {overview.safety.emergency_plan_location ||
                                    'Not recorded'}
                            </dd>
                        </div>
                        <div>
                            <dt className="font-medium">Medication storage</dt>
                            <dd className="text-muted-foreground">
                                {overview.safety.medication_storage_location ||
                                    'Not recorded'}
                            </dd>
                        </div>
                        <div>
                            <dt className="font-medium">Risk review</dt>
                            <dd className="text-muted-foreground">
                                {overview.safety.risk_review_date ||
                                    'No review date recorded'}
                            </dd>
                        </div>
                    </dl>
                    <Button
                        type="button"
                        variant="ghost"
                        className="mt-3 min-h-11 w-full"
                        onClick={() => onNavigate('emergency_plan')}
                    >
                        Open full Emergency Plan
                    </Button>
                </OverviewCard>

                <OverviewCard title="Services" icon={CheckCircle2}>
                    {overview.services.length ? (
                        <div className="space-y-2">
                            {overview.services.map((service) => (
                                <div
                                    key={service.id}
                                    className="rounded-lg border p-3"
                                >
                                    <p className="text-sm font-semibold">
                                        {service.name}
                                    </p>
                                    {service.description ? (
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {service.description}
                                        </p>
                                    ) : null}
                                </div>
                            ))}
                        </div>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            No active services linked.
                        </p>
                    )}
                    <Button
                        type="button"
                        variant="ghost"
                        className="mt-3 min-h-11 w-full"
                        onClick={() => onNavigate('services')}
                    >
                        Open full services register
                    </Button>
                </OverviewCard>
            </div>

            <div className="grid gap-4 xl:grid-cols-[1.3fr_1fr]">
                <SiteAttentionPanel attention={attention} />
                <OverviewCard
                    title="Site notes"
                    icon={StickyNote}
                    action={
                        canManage ? (
                            <Button
                                size="sm"
                                className="min-h-11"
                                onClick={() => setNoteOpen(true)}
                            >
                                <Plus className="mr-2 h-4 w-4" /> New note
                            </Button>
                        ) : null
                    }
                >
                    {overview.notes.length ? (
                        <ul className="space-y-2">
                            {overview.notes.map((note) => (
                                <li
                                    key={note.id}
                                    className="rounded-lg border bg-muted/20 p-3"
                                >
                                    <p className="text-sm whitespace-pre-wrap">
                                        {note.body}
                                    </p>
                                    <div className="mt-2 flex items-center justify-between gap-3 text-xs text-muted-foreground">
                                        <span>
                                            {[
                                                note.created_by,
                                                note.created_at
                                                    ? new Date(
                                                          note.created_at,
                                                      ).toLocaleString()
                                                    : null,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </span>
                                        {canManage ? (
                                            <ConfirmAction
                                                title="Delete Site note?"
                                                description="This note will be permanently removed."
                                                confirmLabel="Delete"
                                                onConfirm={() =>
                                                    router.delete(
                                                        `/sites/${site.id}/notes/${note.id}`,
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    className="min-h-11 text-status-critical"
                                                >
                                                    <Trash2 className="mr-2 h-4 w-4" />{' '}
                                                    Delete
                                                </Button>
                                            </ConfirmAction>
                                        ) : null}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="py-6 text-center text-sm text-muted-foreground">
                            No Site notes recorded.
                        </p>
                    )}
                </OverviewCard>
            </div>

            <EditSiteLineDialog
                siteId={site.id}
                isOpen={siteLineOpen}
                onClose={() => setSiteLineOpen(false)}
                initial={{ phone: site.phone ?? '', email: site.email ?? '' }}
            />
            <EditLocationDialog
                siteId={site.id}
                siteName={site.name}
                isOpen={locationOpen}
                onClose={() => setLocationOpen(false)}
                initial={{
                    address_line_1: site.address_line_1 ?? '',
                    address_line_2: site.address_line_2 ?? '',
                    suburb: site.suburb ?? '',
                    city: site.city ?? '',
                    postcode: site.postcode ?? '',
                    country: site.country ?? '',
                    region: site.region ?? '',
                    latitude:
                        site.latitude == null ? '' : String(site.latitude),
                    longitude:
                        site.longitude == null ? '' : String(site.longitude),
                    access_instructions: site.access_instructions ?? '',
                }}
                geofences={overview.geofences}
                onOpenGeofence={
                    overview.can_manage_geofences
                        ? () => setGeofenceOpen(true)
                        : undefined
                }
            />
            <EditSafetyDialog
                siteId={site.id}
                isOpen={safetyOpen}
                onClose={() => setSafetyOpen(false)}
                initial={{
                    emergency_plan_location:
                        overview.safety.emergency_plan_location ?? '',
                    medication_storage_location:
                        overview.safety.medication_storage_location ?? '',
                }}
            />
            <AddSiteNoteDialog
                siteId={site.id}
                isOpen={noteOpen}
                onClose={() => setNoteOpen(false)}
            />
            <SiteGeofenceDialog
                isOpen={geofenceOpen}
                onClose={() => setGeofenceOpen(false)}
                onOpenLocation={() => {
                    setGeofenceOpen(false);
                    setLocationOpen(true);
                }}
                siteId={site.id}
                siteName={site.name}
                siteLat={site.latitude}
                siteLng={site.longitude}
                existing={overview.geofences[0] ?? null}
                assets={overview.geofence_assets}
            />
        </div>
    );
}

function ContactLine({
    icon: Icon,
    label,
    value,
    href,
}: {
    icon: LucideIcon;
    label: string;
    value?: string | null;
    href?: string;
}) {
    return (
        <div className="flex min-h-11 items-center gap-3 py-2">
            <Icon className="h-4 w-4 text-muted-foreground" />
            <span className="w-20 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </span>
            {href ? (
                <a
                    className="min-w-0 truncate text-sm hover:underline"
                    href={href}
                >
                    {value}
                </a>
            ) : (
                <span className="text-sm text-muted-foreground">
                    {value || 'Not recorded'}
                </span>
            )}
        </div>
    );
}

function OverviewStat({
    label,
    value,
    detail,
    icon: Icon,
}: {
    label: string;
    value: string;
    detail: string;
    icon: LucideIcon;
}) {
    return (
        <Card>
            <CardContent className="flex items-center gap-3 p-4">
                <span className="rounded-lg bg-primary/10 p-2 text-primary">
                    <Icon className="h-5 w-5" />
                </span>
                <span>
                    <span className="block text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                        {label}
                    </span>
                    <span className="text-2xl font-bold tabular-nums">
                        {value}
                    </span>
                    <span className="ml-2 text-xs text-muted-foreground">
                        {detail}
                    </span>
                </span>
            </CardContent>
        </Card>
    );
}

function OverviewCard({
    title,
    icon: Icon,
    action,
    children,
}: {
    title: string;
    icon: LucideIcon;
    action?: ReactNode;
    children: ReactNode;
}) {
    return (
        <Card className="overflow-hidden">
            <CardHeader className="flex flex-row items-center justify-between gap-3 border-b bg-gradient-to-br from-primary/5 to-transparent">
                <CardTitle className="flex items-center gap-2 text-base">
                    <span className="rounded-lg bg-primary/10 p-2 text-primary">
                        <Icon className="h-4 w-4" />
                    </span>
                    {title}
                </CardTitle>
                {action}
            </CardHeader>
            <CardContent className="p-4">{children}</CardContent>
        </Card>
    );
}
