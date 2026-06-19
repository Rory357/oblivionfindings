/* eslint-disable no-restricted-syntax -- Hero quick-action pills are bespoke
 * translucent (white/15) chips on the brand gradient, per the design handoff;
 * the shadcn <Button> variants don't cover this on-hero treatment. */
import { router } from '@inertiajs/react';
import {
    Briefcase,
    CalendarDays,
    Clock,
    MapPin,
    MessagesSquare,
    Send,
} from 'lucide-react';
import type { ComponentType } from 'react';

import { MiniSparkline } from '@/components/dashboard/mini-sparkline';
import { PageHero, type PageHeroBadge } from '@/components/page';
import type { PageHeroMetaItem } from '@/components/page/page-hero-meta';
import { cn } from '@/lib/utils';

import { MyHrClockCard } from './my-hr-clock-card';
import type { MyHrShellData } from './my-hr-types';

export type MyHrHeroHandlers = {
    onRequestLeave?: () => void;
    onSendKudos?: () => void;
    onPrep1on1?: () => void;
};

function greetingFor(hour: number): { greeting: string; wave: string } {
    if (hour < 12) return { greeting: 'Mōrena', wave: '🌅' };
    if (hour < 17) return { greeting: 'Kia ora', wave: '☀️' };
    return { greeting: 'Pō mārie', wave: '🌙' };
}

function formatNextShift(iso: string | null): string {
    if (!iso) return '—';
    const d = new Date(iso);
    const weekday = d.toLocaleDateString('en-NZ', { weekday: 'short' });
    const h = d.getHours();
    const m = d.getMinutes();
    const ampm = h < 12 ? 'a' : 'p';
    const h12 = h % 12 === 0 ? 12 : h % 12;
    return `${weekday} ${h12}:${String(m).padStart(2, '0')}${ampm}`;
}

function HeroStat({
    label,
    value,
    tone,
    children,
}: {
    label: string;
    value: string | number;
    tone?: 'amber';
    children?: React.ReactNode;
}) {
    return (
        <div className="min-w-0">
            <p className="text-[10px] font-bold uppercase tracking-[0.09em] text-primary-foreground/70">
                {label}
            </p>
            <div className="flex items-end gap-2">
                <p
                    className={cn(
                        'text-xl font-bold tabular-nums',
                        tone === 'amber' && 'text-status-warning',
                    )}
                >
                    {value}
                </p>
                {children}
            </div>
        </div>
    );
}

function QuickPill({
    icon: Icon,
    label,
    onClick,
}: {
    icon: ComponentType<{ className?: string }>;
    label: string;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="inline-flex items-center gap-2 rounded-[10px] border border-primary-foreground/25 bg-primary-foreground/15 px-3.5 py-2 text-[12.5px] font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/25"
        >
            <Icon className="h-3.5 w-3.5" />
            {label}
        </button>
    );
}

/**
 * The shared My HR hero — brand-purple gradient (NOT the coral category-hr
 * accent), time-aware te-reo greeting, live-shift / docs-to-sign / attestation
 * badges, this-week + next-shift + open-actions + kudos stats, three quick
 * actions, and the promoted clock card in the right column. Rendered above the
 * tab strip on every `/hr/my/*` page via {@link MyHrShell}.
 */
export function MyHrHero({
    myHr,
    handlers,
}: {
    myHr: MyHrShellData;
    handlers?: MyHrHeroHandlers;
}) {
    const { profile, counts, weekly, nextShift } = myHr;
    const hour = new Date().getHours();
    const { greeting, wave } = greetingFor(hour);
    const dateLabel = new Date().toLocaleDateString('en-NZ', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
    const monthShort = new Date().toLocaleDateString('en-NZ', { month: 'short' });

    const sparkData = Object.values(weekly.daily_hours ?? {}).map(Number);
    const openActions = counts.docsToSign + counts.policiesDue + counts.onesToAck;

    const meta: PageHeroMetaItem[] = [];
    if (profile.position_title)
        meta.push({ icon: Briefcase, label: profile.position_title });
    if (profile.site_name) meta.push({ icon: MapPin, label: profile.site_name });

    const badges: PageHeroBadge[] = [];
    if (myHr.activeClock) {
        const since = new Date(myHr.activeClock.clock_in).toLocaleTimeString(
            'en-NZ',
            { hour: '2-digit', minute: '2-digit', hour12: false },
        );
        badges.push({ label: `Live shift · since ${since}`, dot: true });
    }
    if (counts.docsToSign > 0) {
        badges.push({
            label: `${counts.docsToSign} document${counts.docsToSign === 1 ? '' : 's'} to sign`,
            tone: 'critical',
            href: '/hr/my/documents',
            'aria-label': 'Documents awaiting your signature',
        });
    }
    if (counts.policiesDue > 0) {
        badges.push({
            label: 'Attestation due',
            tone: 'warning',
            href: '/hr/my/policies',
        });
    }

    const requestLeave =
        handlers?.onRequestLeave ?? (() => router.visit('/hr/my/leave'));
    const sendKudos = handlers?.onSendKudos ?? (() => router.visit('/hr/my'));
    const prep1on1 = handlers?.onPrep1on1 ?? (() => router.visit('/hr/my/one'));

    return (
        <PageHero
            brandColour="var(--primary)"
            avatar={{ src: profile.avatar ?? undefined, fallback: profile.initials }}
            title={
                <>
                    {greeting}, {profile.first_name}{' '}
                    <span className="text-2xl">{wave}</span>
                </>
            }
            description={dateLabel}
            meta={meta}
            badges={badges}
            actions={
                <MyHrClockCard
                    activeClock={myHr.activeClock}
                    todayTotal={myHr.todayTotal}
                    siteName={profile.site_name}
                />
            }
        >
            <div className="mt-4 flex flex-wrap gap-x-7 gap-y-3">
                <HeroStat
                    label="This week"
                    value={`${weekly.total_hours.toFixed(1)}h`}
                >
                    {sparkData.length > 1 ? (
                        <span className="mb-1 inline-flex">
                            <MiniSparkline
                                data={sparkData}
                                width={64}
                                height={20}
                                color="rgba(255,255,255,0.85)"
                                fillOpacity={0}
                            />
                        </span>
                    ) : null}
                </HeroStat>
                <HeroStat
                    label="Next shift"
                    value={formatNextShift(nextShift?.starts_at ?? null)}
                />
                <HeroStat label="Open actions" value={openActions} tone="amber" />
                <HeroStat
                    label={`Kudos · ${monthShort}`}
                    value={counts.kudosThisMonth}
                />
            </div>

            <div className="mt-4 flex flex-wrap gap-2">
                <QuickPill
                    icon={CalendarDays}
                    label="Request leave"
                    onClick={requestLeave}
                />
                <QuickPill icon={Send} label="Send kudos" onClick={sendKudos} />
                <QuickPill
                    icon={MessagesSquare}
                    label="Prep 1:1"
                    onClick={prep1on1}
                />
            </div>
        </PageHero>
    );
}

export default MyHrHero;
