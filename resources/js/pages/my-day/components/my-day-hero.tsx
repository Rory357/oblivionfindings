import {
    AlertTriangle,
    ArrowUpRight,
    ClipboardCheck,
    Clock,
    Coffee,
    FileText,
    Heart,
    MapPin,
    Pause,
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
import { useMyDayLabels } from '@/hooks/use-my-day-labels';

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
    /** Active shift id — used to scope the "Report incident" quick action to this shift. */
    activeShiftId?: number | null;
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
    /** Worker is currently on a break (within an open attendance session). */
    isOnBreak?: boolean;
    /** Outgoing handover for the active shift has already been submitted. */
    handoverSubmitted?: boolean;
    onClockToggle: () => void;
    onBreakToggle?: () => void;
    onOpenTimesheet?: () => void;
    /** Open the outgoing-handover sheet (HandoverWriteSheet). */
    onWriteHandover?: () => void;
    /**
     * Open the Vitals & obs flow (VitalsRecordFlow). When provided we render
     * the Vitals quick action as a button that triggers the picker — the
     * worker chooses which resident the observation is for. When omitted we
     * fall back to the static single-resident href (1:1 shifts).
     */
    onOpenVitals?: () => void;
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
    activeShiftId,
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
    isOnBreak,
    handoverSubmitted,
    onClockToggle,
    onBreakToggle,
    onOpenTimesheet,
    onWriteHandover,
    onOpenVitals,
    activeResidentId,
    onResidentChange,
    residentTaskCounts,
    residentNotes,
    liveSinceLabel,
    description,
}: MyDayHeroProps) {
    const t = useMyDayLabels();
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
                action: { label: t('open_emar'), href: '/meds/today' },
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
            <span className="font-normal opacity-80">{t('hero_on_shift_at')}</span>{' '}
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
            <span className="font-normal opacity-80">{t('hero_on_shift_with')}</span>{' '}
            {singleResident.name}
        </>
    ) : (
        t('today')
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
        { label: t('hero_clocked'), value: clockedLabel, sub: clockedSubLabel ?? '', hideOnMobile: false },
        { label: t('tasks'), value: `${tasksDone}/${totalTasks}`, sub: t('hero_complete'), hideOnMobile: false },
        {
            label: t('hero_meds'),
            value: `${medsGiven}/${totalMeds}`,
            sub: medsOverdue > 0 ? `${medsOverdue} ${t('overdue_badge').toLowerCase()}` : t('hero_on_track'),
            hideOnMobile: false,
        },
        { label: t('hero_open'), value: openItemsCount, sub: t('hero_items'), hideOnMobile: false },
    ];

    // Single-resident shifts get resident-scoped care/notes links so the worker
    // lands directly on the right record (clients.viewAssigned grants those
    // endpoints). Multi-resident shifts drop the resident-specific shortcuts
    // entirely because the org-wide care_plans / clients-list destinations are
    // gated behind manager permissions a support worker doesn't have.
    // Both Care Note and Care Plan deep-link into the client profile's
    // existing tabs (the `clients/{id}/daily-notes` endpoint is JSON-only,
    // not an Inertia page). Using `?tab=` lands the worker on the profile
    // with the right tab already open.
    //
    // Vitals & obs is its own flow: VitalsRecordFlow renders a resident
    // picker (multi-resident shifts) or skips straight to the record sheet
    // (1:1 shifts). When `onOpenVitals` is wired the quick action becomes a
    // button; we keep the deep-link as a fallback for callers that don't pass
    // the handler (older embeddings of this hero).
    const careNoteHref = singleResident
        ? `/clients/${singleResident.id}?tab=progress_notes`
        : '/clients';
    const carePlanHref = singleResident ? `/clients/${singleResident.id}/care` : null;
    const vitalsFallbackHref =
        !onOpenVitals && singleResident
            ? `/clients/${singleResident.id}?tab=observations`
            : null;
    const incidentHref = activeShiftId
        ? `/incidents/create?shift_id=${activeShiftId}`
        : '/incidents/create';

    const quickActions = [
        { icon: Pill, label: t('qa_give_medication'), badge: totalMeds - medsGiven > 0 ? totalMeds - medsGiven : undefined, href: '/meds/today' },
        { icon: StickyNote, label: t('qa_care_note'), href: careNoteHref },
        // Vitals & obs: prefer the picker flow (resolves the right client
        // even on a multi-resident shift); fall back to the single-resident
        // deep-link when no flow handler is wired.
        ...(onOpenVitals
            ? [{ icon: Stethoscope, label: t('qa_vitals_obs'), onClick: onOpenVitals }]
            : vitalsFallbackHref
              ? [{ icon: Stethoscope, label: t('qa_vitals_obs'), href: vitalsFallbackHref }]
              : []),
        { icon: AlertTriangle, label: t('qa_report_incident'), href: incidentHref },
        ...(carePlanHref ? [{ icon: ShieldCheck, label: t('qa_care_plan'), href: carePlanHref }] : []),
        // "Submit timesheet" used to deep-link to /operations/timesheets,
        // which duplicates the top-right "Today's timesheet" action and
        // bounces the worker out of /my-day to a list view. When the popup
        // handler is wired we prefer it — workers stay on /my-day, the
        // server find-or-creates today's draft, and the review window opens
        // immediately. The list link remains the fallback for callers that
        // don't wire `onOpenTimesheet`.
        onOpenTimesheet
            ? { icon: FileText, label: t('qa_submit_timesheet'), onClick: onOpenTimesheet }
            : { icon: FileText, label: t('qa_submit_timesheet'), href: '/operations/timesheets' },
        // The "Write handover" button is the worker's outgoing handover — only
        // useful mid-shift (we have an active shift id) and not yet submitted.
        // It opens HandoverWriteSheet via the parent's onWriteHandover.
        ...(clockedIn && onWriteHandover && !handoverSubmitted
            ? [
                  {
                      icon: ClipboardCheck,
                      label: t('qa_write_handover'),
                      onClick: onWriteHandover,
                  },
              ]
            : []),
    ];

    const avatarStack = multiResident
        ? residents.map((r) => ({
              id: r.id,
              initials: r.initials,
              hue: r.hue,
              name: r.name,
              popover: {
                  title: r.name,
                  subtitle: t('resident_at_site', { site: site?.name ?? '' }),
                  note: residentNotes?.get(r.id) ?? r.care_note_preview ?? undefined,
                  primaryAction: { label: t('res_open_profile'), href: `/clients/${r.id}` },
                  actions: [
                      { icon: Pill, label: t('res_give_meds'), href: `/meds/today?client=${r.id}` },
                      { icon: StickyNote, label: t('res_care_note'), href: `/clients/${r.id}?tab=progress_notes` },
                      { icon: ShieldCheck, label: t('res_care_plan'), href: `/clients/${r.id}/care` },
                      { icon: Stethoscope, label: t('res_vitals'), href: `/clients/${r.id}?tab=observations` },
                      { icon: AlertTriangle, label: t('res_incident'), href: `/incidents/create?client_id=${r.id}` },
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
                    label: t('all_residents'),
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
                    ? t('hero_greeting_site', {
                          name: workerFirstName,
                          count: residents.length,
                          site: site.name,
                      })
                    : t('hero_greeting_no_site', { name: workerFirstName }))
            }
            meta={meta}
            badges={badges}
            stats={stats}
            quickActions={quickActions}
            quickActionsHeading={t('hero_quick_actions')}
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
                        {clockedIn ? t('btn_end_shift') : t('btn_clock_in')}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={onBreakToggle}
                        disabled={!clockedIn || !onBreakToggle}
                    >
                        {isOnBreak ? <Pause className="h-3.5 w-3.5" /> : <Coffee className="h-3.5 w-3.5" />}
                        {isOnBreak ? t('btn_end_break') : t('btn_start_break')}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={onOpenTimesheet}
                        disabled={!onOpenTimesheet}
                    >
                        <FileText className="h-3.5 w-3.5" /> {t('btn_todays_timesheet')}
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
