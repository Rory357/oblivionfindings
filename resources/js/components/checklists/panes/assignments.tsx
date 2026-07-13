import { Link } from '@inertiajs/react';
import { ArrowUpRight, Building2 } from 'lucide-react';
import { useState } from 'react';

import { AssignChecklistButton } from '../assign-checklist';
import { initials } from '../category';
import { freqLabel, typeLabel, useChecklistConfig, type PaneCtx } from '../context';
import { CategoryIcon, CountBadge, Dropdown, Empty, SectionHead, StatusBadge } from '../primitives';
import type { ChecklistAssignment, SiteRef } from '../types';
import { Card as GuardrailCard } from '@/components/ui/card';

export function AssignmentsPane({ ctx }: { ctx: PaneCtx }) {
    const cfg = useChecklistConfig();
    const [siteFilter, setSiteFilter] = useState('all');
    const q = ctx.query.toLowerCase();

    const match = (a: ChecklistAssignment) => {
        const matchQ = !q || (a.template?.name ?? '').toLowerCase().includes(q);
        const matchCat = ctx.cat === 'all' || a.template?.category === ctx.cat;
        const matchSite = siteFilter === 'all' || String(a.site?.id) === siteFilter;
        return matchQ && matchCat && matchSite;
    };

    const groups = new Map<number, { site: SiteRef; items: ChecklistAssignment[] }>();
    ctx.assignments.filter(match).forEach((a) => {
        if (!a.site) return;
        if (!groups.has(a.site.id)) groups.set(a.site.id, { site: a.site, items: [] });
        groups.get(a.site.id)!.items.push(a);
    });
    const grouped = [...groups.values()].sort((x, y) => x.site.name.localeCompare(y.site.name));

    const siteOptions = [
        { value: 'all', label: 'All sites' },
        ...ctx.sites.map((s) => ({ value: String(s.id), label: s.name })),
    ];

    return (
        <div className="space-y-4">
            <SectionHead title="Assignments" desc="Which templates run at which site, and how often">
                {cfg.scope.mode === 'org' ? (
                    <Dropdown value={siteFilter} onChange={setSiteFilter} options={siteOptions} className="w-44" />
                ) : null}
                <AssignChecklistButton templates={ctx.templates} />
            </SectionHead>

            {grouped.length === 0 ? (
                <GuardrailCard unstyled className="rounded-xl border border-border bg-card p-2 shadow-sm">
                    <Empty title="No assignments match." />
                </GuardrailCard>
            ) : (
                grouped.map((g) => (
                    <GuardrailCard unstyled key={g.site.id} className="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                        <div className="flex items-center justify-between gap-2 border-b border-border bg-muted/40 px-4 py-2.5">
                            <div className="flex flex-wrap items-center gap-2">
                                <Building2 className="h-3.5 w-3.5 text-muted-foreground" />
                                <span className="text-sm font-semibold">{g.site.name}</span>
                                <StatusBadge tone="neutral">{typeLabel(cfg, g.site.type)}</StatusBadge>
                                <CountBadge>{g.items.length} assigned</CountBadge>
                            </div>
                            <OpenSiteLink href={`/sites/${g.site.id}/checklists`} />
                        </div>
                        <div className="divide-y divide-border">
                            {g.items.map((a) => (
                                <div key={a.id} className="flex items-center gap-3 px-4 py-2.5 hover:bg-accent/30">
                                    <CategoryIcon category={a.template?.category ?? null} box={32} size={16} />
                                    <div className="min-w-0 flex-1">
                                        <div className="truncate text-sm font-medium">{a.template?.name}</div>
                                        <div className="text-xs text-muted-foreground">
                                            {a.template?.category
                                                ? cfg.categoryMap[a.template.category]?.label
                                                : '—'}
                                        </div>
                                    </div>
                                    <StatusBadge tone="neutral" className="hidden sm:inline-flex">
                                        {freqLabel(cfg, a.frequency)}
                                    </StatusBadge>
                                    <div className="hidden items-center gap-1.5 text-xs text-muted-foreground md:flex">
                                        <span className="flex h-5 w-5 items-center justify-center rounded-full bg-muted text-[9px] font-semibold">
                                            {initials(a.assignee)}
                                        </span>
                                        {a.assignee}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </GuardrailCard>
                ))
            )}
        </div>
    );
}

/** "Open site" link styled as a ghost button. */
function OpenSiteLink({ href }: { href: string }) {
    return (
        <Link
            href={href}
            className="inline-flex h-7 items-center gap-1 rounded-md px-2.5 text-xs font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
        >
            Open site
            <ArrowUpRight className="h-3.5 w-3.5" />
        </Link>
    );
}
