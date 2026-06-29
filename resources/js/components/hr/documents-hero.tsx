/* eslint-disable no-restricted-syntax -- The Documents hero is a bespoke
 * command band: HeroStats are link-buttons, quick-actions and "needs you" chips
 * sit on the brand gradient, and the right rail renders a signature-completion
 * ring or a recently-filed list as inline surfaces. These are custom on-gradient
 * layouts (raw <button>/<svg>), not shadcn <Button>/<Card> cases. Colours stay
 * token-based so tenant white-label theming still propagates. Mirrors the People
 * hero (resources/js/components/hr/people-hero.tsx). */
import {
    Clock,
    Download,
    FileText,
    FolderOpen,
    PenLine,
    Plus,
    Sparkles,
    Upload,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useState, type CSSProperties } from 'react';

import { cn } from '@/lib/utils';

export type DocsStats = {
    on_file: number;
    awaiting: number;
    expiring: number;
    templates: number;
    declined: number;
};

export type DocsRecent = {
    id: number;
    title: string;
    folder: string;
    employee: { name: string } | null;
    created_at: string | null;
};

export type DocsHeroNeed = {
    key: string;
    label: string;
    icon: LucideIcon;
    onClick: () => void;
};

export type DocsHeroHandlers = {
    onUpload?: () => void;
    onGenerate?: () => void;
    onSend?: () => void;
    onTemplate?: () => void;
    onStatOnFile?: () => void;
    onStatAwaiting?: () => void;
    onStatExpiring?: () => void;
    onStatTemplates?: () => void;
    onViewDoc?: (id: number) => void;
};

const HERO_STYLE: CSSProperties = {
    ['--hr-amber' as string]: 'oklch(0.86 0.13 90)',
    background:
        'linear-gradient(120deg, color-mix(in oklch, var(--primary) 72%, black 22%), var(--primary) 58%, color-mix(in oklch, var(--primary) 90%, white 8%))',
    boxShadow: '0 28px 64px -30px color-mix(in oklch, var(--primary) 86%, black)',
};

type HeroRight = 'ring' | 'recent';

/**
 * The Documents hub hero — a brand-gradient command band above the tab strip on
 * `/hr/documents`. Left: title, four glanceable stats (On file / Awaiting
 * signature / Expiring ≤60d / Templates), quick actions and a "needs you" chip
 * row. Right: a toggle between a signature-completion ring and a recently-filed
 * list (persisted to localStorage). No clock — the manager lens.
 */
export function DocumentsHero({
    stats,
    signatureCompletion,
    recent,
    canManage,
    needs = [],
    handlers,
}: {
    stats: DocsStats;
    signatureCompletion: { signed: number; total: number; requests: number };
    recent: DocsRecent[];
    canManage: boolean;
    needs?: DocsHeroNeed[];
    handlers?: DocsHeroHandlers;
}) {
    const [right, setRight] = useState<HeroRight>('ring');

    useEffect(() => {
        const stored = window.localStorage.getItem('hrDocs.heroRight');
        if (stored === 'ring' || stored === 'recent') setRight(stored);
    }, []);

    const setHero = (mode: HeroRight) => {
        setRight(mode);
        window.localStorage.setItem('hrDocs.heroRight', mode);
    };

    return (
        <div
            style={HERO_STYLE}
            className="relative overflow-hidden rounded-[24px] text-primary-foreground"
        >
            <div className="pointer-events-none absolute inset-0 overflow-hidden rounded-[24px]">
                <div className="absolute -top-20 right-[26%] h-60 w-60 rounded-full bg-primary-foreground/[0.05]" />
                <div className="absolute -bottom-24 -right-10 h-64 w-64 rounded-full bg-primary-foreground/[0.04]" />
            </div>

            <div className="relative flex flex-wrap items-stretch">
                {/* ── left column ── */}
                <div className="min-w-0 flex-1 basis-[560px] p-[32px_36px]">
                    <div className="flex items-center gap-4">
                        <span className="grid h-[54px] w-[54px] flex-none place-items-center rounded-2xl border border-primary-foreground/20 bg-primary-foreground/15">
                            <FolderOpen className="h-[26px] w-[26px]" />
                        </span>
                        <div className="min-w-0">
                            <h1 className="text-[27px] font-bold leading-[1.05] tracking-tight">
                                Documents
                            </h1>
                            <p className="mt-1.5 text-[13px] font-semibold text-primary-foreground/75">
                                Generate, sign and file every document in one
                                place
                            </p>
                        </div>
                    </div>

                    {/* stats */}
                    <div className="-ml-3 mt-5 flex flex-wrap gap-0.5">
                        <HeroStat
                            label="On file"
                            value={stats.on_file}
                            onClick={handlers?.onStatOnFile}
                        />
                        <HeroStat
                            label="Awaiting signature"
                            value={stats.awaiting}
                            amber={stats.awaiting > 0}
                            onClick={handlers?.onStatAwaiting}
                        />
                        <HeroStat
                            label="Expiring ≤60d"
                            value={stats.expiring}
                            onClick={handlers?.onStatExpiring}
                        />
                        <HeroStat
                            label="Templates"
                            value={stats.templates}
                            onClick={handlers?.onStatTemplates}
                        />
                    </div>

                    {/* quick actions */}
                    {canManage ? (
                        <div className="mt-[18px] flex flex-wrap gap-2">
                            <QuickAction
                                icon={Upload}
                                label="Upload"
                                onClick={handlers?.onUpload}
                            />
                            <QuickAction
                                icon={Sparkles}
                                label="Generate from template"
                                onClick={handlers?.onGenerate}
                            />
                            <QuickAction
                                icon={PenLine}
                                label="Send for signature"
                                onClick={handlers?.onSend}
                            />
                            <QuickAction
                                icon={Plus}
                                label="New template"
                                onClick={handlers?.onTemplate}
                            />
                        </div>
                    ) : null}

                    {/* needs you */}
                    {needs.length > 0 ? (
                        <div className="mt-[18px] flex flex-wrap items-center gap-2">
                            <span className="text-[10px] font-bold uppercase tracking-[0.1em] text-primary-foreground/50">
                                Needs you
                            </span>
                            {needs.map((chip) => {
                                const Icon = chip.icon;
                                return (
                                    <button
                                        key={chip.key}
                                        type="button"
                                        onClick={chip.onClick}
                                        className="inline-flex items-center gap-2 rounded-[9px] border border-primary-foreground/25 bg-primary-foreground/[0.13] py-1.5 pl-2.5 pr-3 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/25"
                                    >
                                        <span className="h-1.5 w-1.5 flex-none rounded-full bg-[color:var(--hr-amber)] shadow-[0_0_0_3px_color-mix(in_oklch,var(--hr-amber)_32%,transparent)]" />
                                        <Icon className="h-[13px] w-[13px]" />
                                        {chip.label}
                                    </button>
                                );
                            })}
                        </div>
                    ) : null}
                </div>

                {/* ── right rail ── */}
                <div className="flex w-full flex-none flex-col border-t border-primary-foreground/15 bg-black/[0.08] p-[22px_24px] sm:w-[320px] sm:border-l sm:border-t-0">
                    <div className="mb-3 flex items-center justify-between">
                        <span className="text-[10px] font-bold uppercase tracking-[0.1em] text-primary-foreground/55">
                            {right === 'ring'
                                ? 'Signature completion'
                                : 'Recently filed'}
                        </span>
                        <div className="inline-flex gap-0.5 rounded-lg bg-black/[0.16] p-0.5">
                            <RailTab
                                label="Signing"
                                active={right === 'ring'}
                                onClick={() => setHero('ring')}
                            />
                            <RailTab
                                label="Recent"
                                active={right === 'recent'}
                                onClick={() => setHero('recent')}
                            />
                        </div>
                    </div>

                    {right === 'ring' ? (
                        <CompletionRing completion={signatureCompletion} />
                    ) : (
                        <RecentList
                            recent={recent}
                            onView={handlers?.onViewDoc}
                        />
                    )}
                </div>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Right-rail treatments                                             */
/* ------------------------------------------------------------------ */

function CompletionRing({
    completion,
}: {
    completion: { signed: number; total: number; requests: number };
}) {
    const total = completion.total || 0;
    const signed = completion.signed || 0;
    const pct = total > 0 ? Math.round((signed / total) * 100) : 0;
    const r = 46;
    const c = 2 * Math.PI * r;
    const dash = `${((pct / 100) * c).toFixed(2)} ${c.toFixed(2)}`;
    const outstanding = Math.max(0, total - signed);

    return (
        <div className="mt-1 flex items-center gap-[18px]">
            <div className="relative h-[116px] w-[116px] flex-none">
                <svg
                    width="116"
                    height="116"
                    style={{ transform: 'rotate(-90deg)' }}
                >
                    <circle
                        cx="58"
                        cy="58"
                        r={r}
                        fill="none"
                        stroke="color-mix(in oklch, var(--primary-foreground) 18%, transparent)"
                        strokeWidth="10"
                    />
                    <circle
                        cx="58"
                        cy="58"
                        r={r}
                        fill="none"
                        stroke="var(--hr-amber)"
                        strokeWidth="10"
                        strokeLinecap="round"
                        strokeDasharray={dash}
                    />
                </svg>
                <div className="absolute inset-0 flex flex-col items-center justify-center">
                    <span className="text-[26px] font-bold leading-none tabular-nums">
                        {pct}%
                    </span>
                    <span className="mt-0.5 text-[10px] text-primary-foreground/65">
                        complete
                    </span>
                </div>
            </div>
            <div className="text-[12.5px] leading-[1.7]">
                <div>
                    <b>{signed}</b> signed
                </div>
                <div className="text-[color:var(--hr-amber)]">
                    <b>{outstanding}</b> outstanding
                </div>
                <div className="text-primary-foreground/70">
                    across {completion.requests}{' '}
                    {completion.requests === 1 ? 'request' : 'requests'}
                </div>
            </div>
        </div>
    );
}

function RecentList({
    recent,
    onView,
}: {
    recent: DocsRecent[];
    onView?: (id: number) => void;
}) {
    if (recent.length === 0) {
        return (
            <p className="mt-4 text-center text-xs text-primary-foreground/60">
                No documents filed yet.
            </p>
        );
    }
    return (
        <div className="flex flex-col gap-2">
            {recent.map((d) => (
                <button
                    key={d.id}
                    type="button"
                    onClick={() => onView?.(d.id)}
                    className="flex items-center gap-2.5 rounded-[10px] bg-primary-foreground/10 px-2.5 py-[7px] text-left transition-colors hover:bg-primary-foreground/20"
                >
                    <span className="grid h-[30px] w-[30px] flex-none place-items-center rounded-lg bg-primary-foreground/[0.16]">
                        <FileText className="h-[15px] w-[15px]" />
                    </span>
                    <span className="min-w-0">
                        <span className="block truncate text-xs font-semibold">
                            {d.title}
                        </span>
                        <span className="block text-[10.5px] text-primary-foreground/65">
                            {(d.employee?.name ?? 'All staff') +
                                (d.created_at ? ` · ${d.created_at}` : '')}
                        </span>
                    </span>
                </button>
            ))}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Pieces                                                            */
/* ------------------------------------------------------------------ */

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
    const inner = (
        <>
            <span className="whitespace-nowrap text-[10px] font-bold uppercase tracking-[0.09em] text-primary-foreground/60">
                {label}
            </span>
            <span
                className={cn(
                    'text-[23px] font-bold tabular-nums',
                    amber && 'text-[color:var(--hr-amber)]',
                )}
            >
                {value}
            </span>
        </>
    );
    if (!onClick) {
        return (
            <span className="flex flex-col items-start gap-0.5 rounded-[11px] px-3 py-2 text-left">
                {inner}
            </span>
        );
    }
    return (
        <button
            type="button"
            onClick={onClick}
            className="flex flex-col items-start gap-0.5 rounded-[11px] px-3 py-2 text-left transition-colors hover:bg-primary-foreground/10"
        >
            {inner}
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
    onClick?: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="inline-flex items-center gap-2 text-[12.5px] font-semibold text-primary-foreground/90 transition-colors hover:text-primary-foreground"
        >
            <Icon className="h-[15px] w-[15px]" />
            {label}
        </button>
    );
}

function RailTab({
    label,
    active,
    onClick,
}: {
    label: string;
    active: boolean;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={active}
            className={cn(
                'h-6 rounded-md px-2.5 text-[11px] font-bold transition-colors',
                active
                    ? 'bg-primary-foreground text-primary'
                    : 'text-primary-foreground/70 hover:text-primary-foreground',
            )}
        >
            {label}
        </button>
    );
}

export default DocumentsHero;
