/* eslint-disable no-restricted-syntax -- The Recruitment hero is a bespoke
 * command band mirroring the People hero (resources/js/components/hr/people-hero.tsx):
 * HeroStats are link-buttons, quick-actions + "needs you" chips sit on the brand
 * gradient, and the right rail toggles a hiring-funnel bar chart / speed stats as
 * inline SVG-free DOM. Colours stay token-based (primary / status-* / --hr-amber
 * injected as a CSS var) plus the recruitment stage hue scale, so tenant
 * white-label theming still propagates. */
import {
    CalendarPlus,
    Download,
    FilePlus2,
    Send,
    UserPlus,
    Users,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useState, type CSSProperties } from 'react';

import { cn } from '@/lib/utils';
import { stageColors } from './stage';

export type RecruitmentHeroData = {
    subtitle: string;
    open_requisitions: number;
    active_candidates: number;
    interviews_this_week: number;
    offers_out: number;
    time_to_hire_days: number;
    offer_accept_rate: number;
    funnel: { key: string; label: string; count: number }[];
};

export type RecruitmentNeedChip = { key: string; label: string; tab: string };

export type RecruitmentHeroHandlers = {
    onAddCandidate?: () => void;
    onNewRequisition?: () => void;
    onSchedule?: () => void;
    onReviewOffers?: () => void;
    onExport?: () => void;
    onStat?: (tab: string) => void;
    onNeed?: (chip: RecruitmentNeedChip) => void;
};

const HERO_STYLE: CSSProperties = {
    ['--hr-amber' as string]: 'oklch(0.86 0.13 90)',
    background:
        'linear-gradient(120deg, color-mix(in oklch, var(--primary) 72%, black 22%), var(--primary) 58%, color-mix(in oklch, var(--primary) 90%, white 8%))',
    boxShadow: '0 28px 64px -30px color-mix(in oklch, var(--primary) 86%, black)',
};

type HeroRight = 'funnel' | 'speed';

export function RecruitmentHero({
    hero,
    needs = [],
    canManage,
    handlers,
}: {
    hero: RecruitmentHeroData;
    needs?: RecruitmentNeedChip[];
    canManage: boolean;
    handlers?: RecruitmentHeroHandlers;
}) {
    const [right, setRight] = useState<HeroRight>('funnel');

    useEffect(() => {
        const stored = window.localStorage.getItem('hrRecruit.heroRight');
        if (stored === 'funnel' || stored === 'speed') setRight(stored);
    }, []);

    const setHero = (mode: HeroRight) => {
        setRight(mode);
        window.localStorage.setItem('hrRecruit.heroRight', mode);
    };

    return (
        <div
            style={HERO_STYLE}
            className="relative overflow-hidden rounded-[24px] text-primary-foreground"
        >
            <div className="pointer-events-none absolute inset-0 overflow-hidden rounded-[24px]">
                <div className="absolute -top-20 right-[22%] h-60 w-60 rounded-full bg-primary-foreground/[0.05]" />
                <div className="absolute -bottom-[90px] right-[6%] h-50 w-50 rounded-full bg-primary-foreground/[0.04]" />
            </div>

            <div className="relative flex flex-wrap items-stretch">
                {/* ── left column ── */}
                <div className="min-w-0 flex-1 basis-[560px] p-[30px_34px]">
                    <div className="flex items-center gap-4">
                        <span className="grid h-[54px] w-[54px] flex-none place-items-center rounded-2xl border border-primary-foreground/20 bg-primary-foreground/15">
                            <Users className="h-[26px] w-[26px]" />
                        </span>
                        <div className="min-w-0">
                            <h1 className="text-[28px] font-bold leading-[1.05] tracking-tight">
                                Recruitment
                            </h1>
                            <p className="mt-1.5 text-[13px] font-medium text-primary-foreground/75">
                                {hero.subtitle}
                            </p>
                        </div>
                    </div>

                    {/* stats */}
                    <div className="-ml-3 mt-[18px] flex flex-wrap gap-0.5">
                        <HeroStat
                            label="Open requisitions"
                            value={hero.open_requisitions}
                            onClick={() => handlers?.onStat?.('requisitions')}
                        />
                        <HeroStat
                            label="Active candidates"
                            value={hero.active_candidates}
                            onClick={() => handlers?.onStat?.('pipeline')}
                        />
                        <HeroStat
                            label="Interviews · this wk"
                            value={hero.interviews_this_week}
                            onClick={() => handlers?.onStat?.('interviews')}
                        />
                        <HeroStat
                            label="Offers out"
                            value={hero.offers_out}
                            amber={hero.offers_out > 0}
                            onClick={() => handlers?.onStat?.('offers')}
                        />
                        <HeroStat
                            label="Time to hire"
                            value={`${hero.time_to_hire_days}d`}
                            onClick={() => handlers?.onStat?.('analytics')}
                        />
                    </div>

                    {/* quick actions */}
                    <div className="mt-[18px] flex flex-wrap gap-2">
                        {canManage && handlers?.onAddCandidate ? (
                            <button
                                type="button"
                                onClick={handlers.onAddCandidate}
                                className="inline-flex h-[34px] items-center gap-2 rounded-[9px] bg-primary-foreground px-3.5 text-[12.5px] font-bold text-primary shadow-sm transition-transform hover:scale-[1.02]"
                            >
                                <UserPlus className="h-[15px] w-[15px]" />
                                Add candidate
                            </button>
                        ) : null}
                        {canManage && handlers?.onNewRequisition ? (
                            <QuickAction icon={FilePlus2} label="New requisition" onClick={handlers.onNewRequisition} />
                        ) : null}
                        {canManage && handlers?.onSchedule ? (
                            <QuickAction icon={CalendarPlus} label="Schedule interview" onClick={handlers.onSchedule} />
                        ) : null}
                        {handlers?.onReviewOffers ? (
                            <QuickAction icon={Send} label="Review offers" onClick={handlers.onReviewOffers} />
                        ) : null}
                        {handlers?.onExport ? (
                            <QuickAction icon={Download} label="Export" onClick={handlers.onExport} />
                        ) : null}
                    </div>

                    {/* needs you */}
                    {needs.length > 0 ? (
                        <div className="mt-[18px] flex flex-wrap items-center gap-2">
                            <span className="text-[10px] font-bold uppercase tracking-[0.1em] text-primary-foreground/50">
                                Needs you
                            </span>
                            {needs.map((chip) => (
                                <button
                                    key={chip.key}
                                    type="button"
                                    onClick={() => handlers?.onNeed?.(chip)}
                                    className="inline-flex items-center gap-2 rounded-[9px] border border-primary-foreground/25 bg-primary-foreground/[0.13] py-1.5 pl-2.5 pr-3 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/25"
                                >
                                    <span className="h-1.5 w-1.5 flex-none rounded-full bg-[color:var(--hr-amber)] shadow-[0_0_0_3px_color-mix(in_oklch,var(--hr-amber)_32%,transparent)]" />
                                    {chip.label}
                                </button>
                            ))}
                        </div>
                    ) : null}
                </div>

                {/* ── right rail: hiring health ── */}
                <div className="flex w-full flex-none flex-col border-t border-primary-foreground/15 bg-black/[0.08] p-[24px] sm:w-[340px] sm:border-l sm:border-t-0">
                    <div className="mb-2 flex items-center justify-between">
                        <span className="text-[10px] font-bold uppercase tracking-[0.1em] text-primary-foreground/55">
                            Hiring health
                        </span>
                        <div className="inline-flex gap-0.5 rounded-lg bg-primary-foreground/[0.12] p-0.5">
                            <RailTab label="Funnel" active={right === 'funnel'} onClick={() => setHero('funnel')} />
                            <RailTab label="Speed" active={right === 'speed'} onClick={() => setHero('speed')} />
                        </div>
                    </div>

                    {right === 'funnel' ? (
                        <FunnelBars funnel={hero.funnel} />
                    ) : (
                        <SpeedStats hero={hero} />
                    )}
                </div>
            </div>
        </div>
    );
}

function FunnelBars({ funnel }: { funnel: RecruitmentHeroData['funnel'] }) {
    const max = Math.max(1, ...funnel.map((f) => f.count));
    return (
        <div className="mt-1.5 flex flex-col gap-2">
            {funnel.map((f) => (
                <div key={f.key} className="flex items-center gap-2.5">
                    <span className="w-[62px] flex-none whitespace-nowrap text-[11px] font-semibold text-primary-foreground/85">
                        {f.label}
                    </span>
                    <div className="relative h-4 flex-1 overflow-hidden rounded-full bg-primary-foreground/10">
                        <div
                            className="absolute inset-y-0 left-0 rounded-full transition-[width] duration-500"
                            style={{
                                width: `${Math.max(6, (f.count / max) * 100)}%`,
                                background: stageColors(f.key).dot,
                            }}
                        />
                    </div>
                    <span className="w-5 flex-none text-right text-xs font-bold tabular-nums text-primary-foreground">
                        {f.count}
                    </span>
                </div>
            ))}
        </div>
    );
}

function SpeedStats({ hero }: { hero: RecruitmentHeroData }) {
    const stats = [
        { value: `${hero.time_to_hire_days}d`, label: 'Avg time to hire' },
        { value: `${hero.offer_accept_rate}%`, label: 'Offer acceptance' },
        { value: hero.offers_out, label: 'Offers awaiting reply' },
    ];
    return (
        <div className="mt-2 flex flex-col gap-3.5">
            {stats.map((s) => (
                <div key={s.label}>
                    <div className="text-xl font-extrabold leading-none tabular-nums">
                        {s.value}
                    </div>
                    <div className="mt-0.5 text-[11px] text-primary-foreground/65">{s.label}</div>
                </div>
            ))}
        </div>
    );
}

function HeroStat({
    label,
    value,
    amber,
    onClick,
}: {
    label: string;
    value: string | number;
    amber?: boolean;
    onClick?: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="flex flex-col items-start gap-0.5 rounded-[10px] px-3 py-2 text-left transition-colors hover:bg-primary-foreground/10"
        >
            <span className="whitespace-nowrap text-[10px] font-bold uppercase tracking-[0.09em] text-primary-foreground/60">
                {label}
            </span>
            <span
                className={cn(
                    'text-[22px] font-bold tabular-nums',
                    amber && 'text-[color:var(--hr-amber)]',
                )}
            >
                {value}
            </span>
        </button>
    );
}

function QuickAction({
    icon: Icon,
    label,
    onClick,
}: {
    icon: LucideIcon;
    label: string;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="inline-flex h-[34px] items-center gap-2 rounded-[9px] border border-primary-foreground/[0.28] bg-primary-foreground/[0.12] px-3.5 text-[12.5px] font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/20"
        >
            <Icon className="h-[15px] w-[15px]" />
            {label}
        </button>
    );
}

function RailTab({ label, active, onClick }: { label: string; active: boolean; onClick: () => void }) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={active}
            className={cn(
                'h-6 rounded-md px-2.5 text-[11px] font-bold transition-colors',
                active ? 'bg-primary-foreground text-primary' : 'text-primary-foreground/80 hover:text-primary-foreground',
            )}
        >
            {label}
        </button>
    );
}

export default RecruitmentHero;
