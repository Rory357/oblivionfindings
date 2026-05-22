import {
    AlertTriangle,
    ArrowUpRight,
    Clock,
    Coffee,
    Compass,
    FileText,
    Heart,
    MapPin,
    Mic,
    Phone,
    Pill,
    Play,
    ShieldCheck,
    Square,
    Stethoscope,
    StickyNote,
    Users,
} from 'lucide-react';
import { type ReactNode } from 'react';

import { PageHero } from '@/components/page/page-hero';
import { type PageHeroBadge } from '@/components/page/page-hero-badges';
import { PageTabs } from '@/components/page/page-tabs';
import { Button } from '@/components/ui/button';

import type {
    MyDayActiveSite,
    MyDayMedDue,
    MyDayResident,
    MyDayTaskFollowup,
} from '../lib/types';

import { ResidentDot } from './resident-dot';

type ResidentTab = 'all' | number;

interface MyDayHeroProps {
    /** Greeting in the description (Kia ora Tane.) */
    workerFirstName: string;
    site: MyDayActiveSite | null;
    /** Used when the shift is a single-resident 1:1 (no site or only 1 resident). */
    singleResident?: MyDayResident | null;
    shiftStartLabel: string;
    shiftEndLabel: string;
    shiftDurationHours: number;
    clockedLabel: string;
    clockedSubLabel?: string;
    tasksDone: number;
    totalTasks: number;
    medsGiven: number;
    totalMeds: number;
    medsOverdue: number;
    openItemsCount: number;
    overdueMeds: MyDayMedDue[];
    openItems: MyDayTaskFollowup[];
    clockedIn: boolean;
    onClockToggle: () => void;
    onBreakToggle?: () => void;
    onOpenTimesheet?: () => void;
    activeResidentId: ResidentTab;
    onResidentChange: (next: ResidentTab) => void;
    residentTaskCounts: Map<number, { tasks: number; meds: number; medsOverdue: number }>;
    /** Per-resident note preview for the hover popover. */
    residentNotes?: Map<number, string | null | undefined>;
    /** Live-shift since label, e.g. "Live shift · since 09:04". */
    liveSinceLabel: string;
    /** Description override; defaults to a generated message based on the residents. */
    description?: ReactNode;
}

export function MyDayHero({
    workerFirstName,
    site,
    singleResident,
    shiftStartLabel,
    shiftEndLabel,
    shiftDurationHours,
    clockedLabel,
    clockedSubLabel,
    tasksDone,
    totalTasks,
    medsGiven,
    totalMeds,
    medsOverdue,
    openItemsCount,
    overdueMeds,
    openItems,
    clockedIn,
    onClockToggle,
    onBreakToggle,
    onOpenTimesheet,
    activeResidentId,
    onResidentChange,
    residentTaskCounts,
    residentNotes,
    liveSinceLabel,
    description,
}: MyDayHeroProps) {
    const residents = site?.residents ?? (singleResident ? [singleResident] : []);
    const multiResident = residents.length > 1;

    const totalAcrossSite = totalTasks + totalMeds;

    const badges: PageHeroBadge[] = [
        { label: liveSinceLabel, dot: true, tone: 'default' as const },
    ];

    if (medsOverdue > 0) {
        badges.push({
            label: `${medsOverdue} overdue dose${medsOverdue > 1 ? 's' : ''}`,
            tone: 'critical',
            popover: {
                title: `${medsOverdue} overdue dose${medsOverdue > 1 ? 's' : ''}`,
                subtitle: 'Catch up before lunchtime round',
                items: overdueMeds.map((med) => ({
                    icon: Pill,
                    tone: 'critical' as const,
                    label: `${med.medication_name} · ${med.dose}`,
                    sub: `${med.client_name}${med.route ? ` · ${med.route}` : ''}`,
                    meta: med.scheduled_for
                        ? new Date(med.scheduled_for).toLocaleTimeString([], {
                              hour: '2-digit',
                              minute: '2-digit',
                              hour12: false,
                          })
                        : undefined,
                    href: med.emar_url,
                })),
                action: { label: 'Open eMAR', href: '/meds/today' },
            },
        });
    }

    if (openItemsCount > 0) {
        badges.push({
            label: `Action needed · ${openItemsCount}`,
            tone: 'warning',
            popover: {
                title: `${openItemsCount} item${openItemsCount > 1 ? 's' : ''} need you`,
                items: openItems.slice(0, 6).map((item) => ({
                    label: item.title,
                    sub: item.meta?.client_name ?? undefined,
                    meta: timeSince(item.created_at),
                    href: item.source_url,
                    tone:
                        item.priority === 'critical'
                            ? ('critical' as const)
                            : item.type === 'incident'
                              ? ('warning' as const)
                              : ('info' as const),
                })),
                // No footer action — the same items live in the Digest panel's
                // "Needs you" tab right next to the hero, so a deep-link would
                // duplicate the navigation.
            },
        });
    }

    const heroTitle = site ? (
        <>
            <span className="font-normal opacity-80">On shift at</span>{' '}
            <a
                href={site.href}
                className={
                    'rounded-sm border-b-2 border-primary-foreground/40 px-0.5 transition-colors hover:border-primary-foreground hover:bg-primary-foreground/10'
                }
            >
                {site.name}
                <ArrowUpRight className="ml-1 inline h-5 w-5 align-[-3px] opacity-70" />
            </a>
        </>
    ) : singleResident ? (
        <>
            <span className="font-normal opacity-80">On shift with</span> {singleResident.name}
        </>
    ) : (
        'Today'
    );

    const meta = site
        ? [
              { icon: Clock, label: `${shiftStartLabel} – ${shiftEndLabel} · ${shiftDurationHours}h` },
              { icon: MapPin, label: site.address },
              {
                  icon: Users,
                  label: `${residents.length} resident${residents.length === 1 ? '' : 's'} · ${site.type}`,
              },
          ]
        : [
              { icon: Clock, label: `${shiftStartLabel} – ${shiftEndLabel} · ${shiftDurationHours}h` },
              { icon: Heart, label: 'Personal care + community' },
          ];

    const stats = [
        { label: 'Clocked', value: clockedLabel, sub: clockedSubLabel ?? '', hideOnMobile: false },
        { label: 'Tasks', value: `${tasksDone}/${totalTasks}`, sub: 'complete', hideOnMobile: false },
        {
            label: 'Meds',
            value: `${medsGiven}/${totalMeds}`,
            sub: medsOverdue > 0 ? `${medsOverdue} overdue` : 'on track',
            hideOnMobile: false,
        },
        { label: 'Open', value: openItemsCount, sub: 'items', hideOnMobile: false },
    ];

    const quickActions = [
        { icon: Pill, label: 'Give medication', badge: totalMeds - medsGiven > 0 ? totalMeds - medsGiven : undefined, href: '/meds/today' },
        { icon: StickyNote, label: 'Care note', href: '/clients' },
        { icon: Stethoscope, label: 'Vitals & obs' },
        { icon: Mic, label: 'Dictate' },
        { icon: AlertTriangle, label: 'Report incident', href: '/incidents/new' },
        { icon: ShieldCheck, label: 'Care plan', href: '/care-plans' },
        { icon: FileText, label: 'Submit timesheet', href: '/timesheets/mine' },
        { icon: Compass, label: 'Directions' },
        { icon: Phone, label: 'Call manager' },
    ];

    const avatarStack = multiResident
        ? residents.map((r) => ({
              id: r.id,
              initials: r.initials,
              hue: r.hue,
              name: r.name,
              popover: {
                  title: r.name,
                  subtitle: `Resident · ${site?.name ?? ''}`,
                  note: residentNotes?.get(r.id) ?? r.care_note_preview ?? undefined,
                  primaryAction: { label: 'Open profile', href: `/clients/${r.id}` },
                  actions: [
                      { icon: Pill, label: 'Give meds', href: `/meds/today?client=${r.id}` },
                      { icon: StickyNote, label: 'Care note', href: `/clients/${r.id}/notes/new` },
                      { icon: ShieldCheck, label: 'Care plan', href: `/clients/${r.id}/care` },
                      { icon: Stethoscope, label: 'Vitals', href: `/clients/${r.id}/observations` },
                      { icon: AlertTriangle, label: 'Incident', href: `/incidents/new?client=${r.id}` },
                      { icon: Phone, label: 'Contacts', href: `/clients/${r.id}/contacts` },
                  ],
              },
          }))
        : undefined;

    const singleAvatar = !multiResident && singleResident
        ? { src: singleResident.photo_url ?? null, fallback: singleResident.initials }
        : undefined;

    const footer = multiResident ? (
        <PageTabs
            onDark
            dense
            value={String(activeResidentId)}
            onValueChange={(next) => onResidentChange(next === 'all' ? 'all' : Number(next))}
            items={[
                {
                    value: 'all',
                    label: 'All residents',
                    icon: Users,
                    badge: totalAcrossSite,
                },
                ...residents.map((r) => {
                    const counts = residentTaskCounts.get(r.id);
                    const total = (counts?.tasks ?? 0) + (counts?.meds ?? 0);
                    return {
                        value: String(r.id),
                        label: r.first_name,
                        icon: (props: { className?: string }) => (
                            <span className={props.className}>
                                <ResidentDot hue={r.hue} initials={r.initials} />
                            </span>
                        ),
                        badge: total,
                    };
                }),
            ]}
        />
    ) : undefined;

    return (
        <PageHero
            avatar={singleAvatar}
            avatarStack={avatarStack}
            title={heroTitle}
            description={
                description ??
                (site
                    ? `Kia ora ${workerFirstName}. You're supporting ${residents.length} resident${residents.length === 1 ? '' : 's'} at ${site.name} today.`
                    : `Kia ora ${workerFirstName}. Here's your day at a glance.`)
            }
            meta={meta}
            badges={badges}
            stats={stats}
            quickActions={quickActions}
            quickActionsHeading="Quick actions"
            actions={
                <>
                    <Button
                        type="button"
                        variant="default"
                        size="sm"
                        onClick={onClockToggle}
                        className="bg-primary-foreground text-primary hover:bg-primary-foreground/90"
                    >
                        {clockedIn ? <Square className="h-3.5 w-3.5" /> : <Play className="h-3.5 w-3.5" />}
                        {clockedIn ? 'Clock out' : 'Clock in'}
                    </Button>
                    <Button type="button" variant="outline" size="sm" onClick={onBreakToggle}>
                        <Coffee className="h-3.5 w-3.5" /> Break
                    </Button>
                    <Button type="button" variant="outline" size="sm" onClick={onOpenTimesheet}>
                        <FileText className="h-3.5 w-3.5" /> Today&rsquo;s timesheet
                    </Button>
                </>
            }
            footer={footer}
        />
    );
}

function timeSince(iso: string): string {
    const ms = Date.now() - new Date(iso).getTime();
    const m = Math.floor(ms / 60_000);
    if (m < 1) return 'just now';
    if (m < 60) return `${m}m`;
    const h = Math.floor(m / 60);
    if (h < 24) return `${h}h`;
    return `${Math.floor(h / 24)}d`;
}

export default MyDayHero;
