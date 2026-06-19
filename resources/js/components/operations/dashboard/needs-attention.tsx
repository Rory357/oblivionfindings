import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    BellRing,
    ChevronRight,
    ClipboardCheck,
    GitBranch,
    ShieldAlert,
    type LucideIcon,
} from 'lucide-react';
import { useRef } from 'react';

import { cn } from '@/lib/utils';

import { HoverPopover, useHoverPopover } from './hover-popover';
import type { AttentionItem, AttentionPayload, AttentionTone } from './types';

type AttentionCardProps = {
    item: AttentionItem;
    title: string;
    href: string;
    icon: LucideIcon;
};

const TONE_STYLES: Record<AttentionTone, { bg: string; fg: string; bar: string }> = {
    critical: {
        bg: 'var(--status-critical-bg)',
        fg: 'var(--status-critical)',
        bar: 'var(--status-critical)',
    },
    warning: {
        bg: 'var(--status-warning-bg)',
        fg: 'var(--status-warning)',
        bar: 'var(--status-warning)',
    },
    success: {
        bg: 'var(--status-success-bg)',
        fg: 'var(--status-success)',
        bar: 'var(--status-success)',
    },
    info: {
        bg: 'var(--accent)',
        fg: 'var(--primary)',
        bar: 'var(--primary)',
    },
};

function AttentionCard({ item, title, href, icon: Icon }: AttentionCardProps) {
    const anchor = useRef<HTMLAnchorElement>(null);
    const { open, onEnter, onLeave, popEnter, popLeave } = useHoverPopover();
    const tone = TONE_STYLES[item.tone];
    const tagTone = TONE_STYLES[item.tag_tone];

    return (
        <>
            <Link
                ref={anchor}
                href={href}
                className="group relative flex items-center gap-3 px-3.5 py-2.5 transition-colors hover:bg-muted/40"
                onMouseEnter={onEnter}
                onMouseLeave={onLeave}
                onFocus={onEnter}
                onBlur={onLeave}
            >
                <span
                    className="absolute left-0 top-0 h-full w-0.5"
                    style={{ background: tone.bar }}
                />
                <div
                    className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg"
                    style={{ background: tone.bg, color: tone.fg }}
                >
                    <Icon className="h-4 w-4" />
                </div>
                <div className="min-w-0 flex-1">
                    <div className="flex items-baseline gap-1.5">
                        <span className="text-[16px] font-bold tabular-nums">{item.count}</span>
                        <span className="text-[11.5px] font-semibold">{title}</span>
                        <span
                            className="ml-auto rounded px-1 py-0.5 text-[9px] font-bold uppercase tracking-wide"
                            style={{ background: tagTone.bg, color: tagTone.fg }}
                        >
                            {item.tag}
                        </span>
                    </div>
                    <div className="truncate text-[10.5px] text-muted-foreground">{item.context}</div>
                </div>
                <ChevronRight className="h-3.5 w-3.5 shrink-0 text-muted-foreground group-hover:text-primary" />
            </Link>
            <HoverPopover
                open={open}
                anchorRef={anchor}
                content={item.popover}
                onMouseEnter={popEnter}
                onMouseLeave={popLeave}
                placement="below"
            />
        </>
    );
}

type Props = {
    attention: AttentionPayload;
};

export function NeedsAttentionStrip({ attention }: Props) {
    const totalItems = 4;
    const urgent = (attention.unassigned.urgent ?? 0) > 0 ? 1 : 0;

    return (
        <section>
            <div className="mb-2 flex items-center justify-between">
                <div className="flex items-center gap-2">
                    <BellRing className="h-3.5 w-3.5 text-[color:var(--status-warning)]" />
                    <h2 className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                        Needs attention
                    </h2>
                    <span className="text-[11px] text-muted-foreground">
                        {totalItems} items{urgent > 0 ? ' · 1 urgent' : ''}
                    </span>
                </div>
                <Link
                    href="/operations/activity"
                    className="inline-flex items-center gap-1 text-[11px] font-medium text-primary hover:underline"
                >
                    View all alerts <ArrowRight className="h-3 w-3" />
                </Link>
            </div>
            <div
                className={cn(
                    'grid grid-cols-2 divide-x overflow-hidden rounded-xl border bg-card md:grid-cols-4',
                )}
                style={{ borderColor: 'var(--border)' }}
            >
                <AttentionCard
                    item={attention.unassigned}
                    title="Unassigned shifts"
                    href="/operations/rostering?filter=open"
                    icon={AlertTriangle}
                />
                <AttentionCard
                    item={attention.timesheets}
                    title="Timesheets pending"
                    href="/operations/timesheets/approvals"
                    icon={ClipboardCheck}
                />
                <AttentionCard
                    item={attention.conflicts}
                    title="Roster conflicts"
                    href="/operations/rostering/conflicts"
                    icon={GitBranch}
                />
                <AttentionCard
                    item={attention.incidents}
                    title="Open incidents"
                    href="/incidents?tab=open"
                    icon={ShieldAlert}
                />
            </div>
        </section>
    );
}
