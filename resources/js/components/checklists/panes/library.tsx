import {
    Building2,
    Camera,
    LayoutGrid,
    ListChecks,
    type LucideIcon,
    Pencil,
    PenLine,
    RefreshCw,
    Sparkles,
    Star,
    TriangleAlert,
} from 'lucide-react';
import { type ReactNode, useState } from 'react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

import { AssignChecklistButton } from '../assign-checklist';
import { catBgVar, catColorVar } from '../category';
import { freqLabel, typeLabel, useChecklistConfig, type PaneCtx } from '../context';
import { categoryIcon } from '../icons';
import { CategoryIcon, Dropdown, Empty, StatusBadge, ViewToggle, type ChecklistView } from '../primitives';
import type { ChecklistTemplate } from '../types';
import { Card as GuardrailCard } from '@/components/ui/card';

function FlagChips({ t }: { t: ChecklistTemplate }) {
    return (
        <>
            {t.flags.hazard ? (
                <StatusBadge tone="critical" Icon={TriangleAlert}>
                    Hazard
                </StatusBadge>
            ) : null}
            {t.flags.photo ? (
                <span className="inline-flex items-center gap-1 rounded-md border border-border px-1.5 py-0.5 text-[11px] font-medium text-muted-foreground">
                    <Camera className="h-3 w-3" />
                    Photo
                </span>
            ) : null}
            {t.flags.sign ? (
                <span className="inline-flex items-center gap-1 rounded-md border border-border px-1.5 py-0.5 text-[11px] font-medium text-muted-foreground">
                    <PenLine className="h-3 w-3" />
                    Sign-off
                </span>
            ) : null}
        </>
    );
}

function TemplateActions({ t, templates }: { t: ChecklistTemplate; templates: ChecklistTemplate[] }) {
    const { can, openBuilder } = useChecklistConfig();
    return (
        <div className="flex items-center gap-1">
            {can.manageTemplates ? (
                <Button
                    size="sm"
                    variant="ghost"
                    className="h-7 w-7 p-0"
                    title="Edit template"
                    onClick={() => openBuilder(t.id)}
                >
                    <Pencil className="h-3.5 w-3.5" />
                </Button>
            ) : null}
            <AssignChecklistButton templates={templates} templateId={t.id} label="Assign" variant="outline" />
        </div>
    );
}

function TemplateCard({ t, templates }: { t: ChecklistTemplate; templates: ChecklistTemplate[] }) {
    const cfg = useChecklistConfig();
    const tone = t.category ? cfg.categoryMap[t.category]?.tone : undefined;
    return (
        <div
            className={cn(
                'group relative flex flex-col overflow-hidden rounded-xl border border-border bg-card shadow-sm transition-all hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md',
                t.spotlight && 'ring-1 ring-primary/30',
            )}
        >
            <div className="h-1 w-full" style={{ background: catColorVar(tone) }} />
            <div className="flex flex-1 flex-col p-4">
                <div className="flex items-start gap-3">
                    <CategoryIcon category={t.category} box={40} size={20} />
                    <div className="min-w-0 flex-1">
                        <div className="flex items-start justify-between gap-2">
                            <h4 className="text-sm font-semibold leading-snug">{t.name}</h4>
                            {t.spotlight ? (
                                <span title="Core checklist" className="shrink-0 text-primary">
                                    <Star className="h-3.5 w-3.5" />
                                </span>
                            ) : null}
                        </div>
                        <div className="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground">
                            <RefreshCw className="h-3 w-3" />
                            {freqLabel(cfg, t.frequency)}
                            <span className="text-muted-foreground/40">·</span>
                            {typeLabel(cfg, t.applicable_to_type)}
                        </div>
                    </div>
                </div>
                {t.description ? (
                    <p className="mt-2.5 line-clamp-2 text-xs leading-relaxed text-muted-foreground">{t.description}</p>
                ) : null}
                {t.flags.hazard || t.flags.photo || t.flags.sign ? (
                    <div className="mt-3 flex flex-wrap gap-1.5">
                        <FlagChips t={t} />
                    </div>
                ) : null}
                <div className="mt-auto flex items-center justify-between gap-2 pt-3.5">
                    <div className="flex items-center gap-3 text-xs text-muted-foreground">
                        <span className="flex items-center gap-1">
                            <ListChecks className="h-3.5 w-3.5" />
                            {t.items_count}
                        </span>
                        <span className="flex items-center gap-1">
                            <Building2 className="h-3.5 w-3.5" />
                            {t.assignments_count} sites
                        </span>
                    </div>
                    <TemplateActions t={t} templates={templates} />
                </div>
            </div>
        </div>
    );
}

function TemplateRow({ t, templates }: { t: ChecklistTemplate; templates: ChecklistTemplate[] }) {
    const cfg = useChecklistConfig();
    const tone = t.category ? cfg.categoryMap[t.category]?.tone : undefined;
    return (
        <div className="group flex items-center gap-3 px-3.5 py-2.5 transition-colors hover:bg-accent/40">
            <span className="h-9 w-1 shrink-0 rounded-full" style={{ background: catColorVar(tone) }} />
            <CategoryIcon category={t.category} box={34} size={17} />
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-1.5">
                    <span className="truncate text-sm font-semibold">{t.name}</span>
                    {t.spotlight ? <Star className="h-3 w-3 shrink-0 text-primary" /> : null}
                </div>
                {t.description ? <p className="truncate text-xs text-muted-foreground">{t.description}</p> : null}
            </div>
            <div className="hidden shrink-0 items-center gap-1.5 md:flex">
                <FlagChips t={t} />
            </div>
            <StatusBadge tone="neutral" className="hidden shrink-0 lg:inline-flex">
                {freqLabel(cfg, t.frequency)}
            </StatusBadge>
            <span className="hidden w-14 shrink-0 items-center gap-1 text-xs text-muted-foreground xl:flex">
                <ListChecks className="h-3.5 w-3.5" />
                {t.items_count}
            </span>
            <TemplateActions t={t} templates={templates} />
        </div>
    );
}

export function LibraryPane({ ctx, onNewTemplate }: { ctx: PaneCtx; onNewTemplate: () => void }) {
    const cfg = useChecklistConfig();
    const [freq, setFreq] = useState('all');
    const [view, setView] = useState<ChecklistView>('board');
    const q = ctx.query.toLowerCase();

    // Per-site Library only shows templates that apply to this site type (plus
    // the universal "all" ones); the org dashboard shows the whole catalog.
    const siteType =
        cfg.scope.mode === 'site'
            ? cfg.scope.site.type === 'residential'
                ? 'house'
                : cfg.scope.site.type
            : null;
    const scopedTemplates = siteType
        ? ctx.templates.filter((t) => t.applicable_to_type === 'all' || t.applicable_to_type === siteType)
        : ctx.templates;

    const templates = scopedTemplates.filter((t) => {
        const catLabel = t.category ? cfg.categoryMap[t.category]?.label ?? '' : '';
        const matchQ =
            !q ||
            t.name.toLowerCase().includes(q) ||
            (t.description ?? '').toLowerCase().includes(q) ||
            catLabel.toLowerCase().includes(q);
        const matchC = ctx.cat === 'all' || t.category === ctx.cat;
        const matchF = freq === 'all' || t.frequency === freq;
        return matchQ && matchC && matchF;
    });

    const sections = cfg.categories
        .filter((c) => ctx.cat === 'all' || c.key === ctx.cat)
        .map((c) => ({ ...c, items: templates.filter((t) => t.category === c.key) }))
        .filter((c) => c.items.length > 0);

    const freqOptions = [
        { value: 'all', label: 'Any frequency' },
        ...Object.entries(cfg.freqLabels).map(([value, label]) => ({ value, label })),
    ];

    const chip = (
        key: string,
        label: string,
        active: boolean,
        count: number,
        onClick: () => void,
        Icon: LucideIcon,
        dot?: string,
    ): ReactNode => (
        <Button unstyled
            key={key}
            type="button"
            onClick={onClick}
            className={cn(
                'inline-flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-medium transition',
                active
                    ? 'border-transparent text-primary-foreground shadow-sm'
                    : 'border-border bg-card text-muted-foreground hover:bg-accent',
            )}
            style={active && dot ? { background: dot } : active ? { background: 'var(--primary)' } : undefined}
        >
            <Icon className="h-3.5 w-3.5 shrink-0" style={!active && dot ? { color: dot } : undefined} />
            {label}
            <span className={cn('rounded px-1 text-[10px] tabular-nums', active ? 'bg-white/20' : 'bg-muted')}>{count}</span>
        </Button>
    );

    const knownKeys = new Set(cfg.categories.map((c) => c.key));
    const uncategorised =
        ctx.cat === 'all' ? templates.filter((t) => !t.category || !knownKeys.has(t.category)) : [];
    const nothing = sections.length === 0 && uncategorised.length === 0;

    const grid = (rows: ChecklistTemplate[]) =>
        view === 'board' ? (
            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                {rows.map((t) => (
                    <TemplateCard key={t.id} t={t} templates={ctx.templates} />
                ))}
            </div>
        ) : (
            <GuardrailCard unstyled className="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                {rows.map((t) => (
                    <TemplateRow key={t.id} t={t} templates={ctx.templates} />
                ))}
            </GuardrailCard>
        );

    return (
        <div className="space-y-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-2">
                    <Dropdown value={freq} onChange={setFreq} options={freqOptions} className="w-36" />
                    <span className="text-sm text-muted-foreground">
                        {q
                            ? `${templates.length} results for “${ctx.query}”`
                            : `${templates.length} of ${scopedTemplates.length} templates`}
                    </span>
                </div>
                <div className="flex items-center gap-2">
                    <ViewToggle value={view} onChange={setView} />
                    {cfg.can.manageTemplates ? (
                        <Button size="sm" onClick={onNewTemplate}>
                            <Sparkles className="h-3.5 w-3.5" />
                            New template
                        </Button>
                    ) : null}
                </div>
            </div>

            <div className="flex items-center gap-1.5 overflow-x-auto pb-1">
                {chip('all', 'All', ctx.cat === 'all', scopedTemplates.length, () => ctx.setCat('all'), LayoutGrid)}
                {cfg.categories.map((c) =>
                    chip(
                        c.key,
                        c.label,
                        ctx.cat === c.key,
                        scopedTemplates.filter((t) => t.category === c.key).length,
                        () => ctx.setCat(c.key),
                        categoryIcon(c.icon),
                        catColorVar(c.tone),
                    ),
                )}
            </div>

            {nothing ? (
                <GuardrailCard unstyled className="rounded-xl border border-border bg-card p-2 shadow-sm">
                    <Empty Icon={Sparkles} title="No templates match your filters." />
                </GuardrailCard>
            ) : (
                <>
                    {sections.map((c) => (
                        <div key={c.key} className="space-y-3">
                            <div
                                className="flex items-center gap-3 rounded-xl border border-border p-3"
                                style={{ background: catBgVar(c.tone) }}
                            >
                                <CategoryIcon category={c.key} box={40} size={20} />
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <h3 className="text-sm font-semibold" style={{ color: catColorVar(c.tone) }}>
                                            {c.label}
                                        </h3>
                                        <span className="rounded-full bg-background/70 px-2 py-0.5 text-[11px] font-semibold tabular-nums text-foreground">
                                            {c.items.length} {c.items.length === 1 ? 'checklist' : 'checklists'}
                                        </span>
                                    </div>
                                    <p className="mt-0.5 text-xs" style={{ color: catColorVar(c.tone), opacity: 0.85 }}>
                                        {c.blurb}
                                    </p>
                                </div>
                            </div>
                            {grid(c.items)}
                        </div>
                    ))}

                    {uncategorised.length > 0 ? (
                        <div className="space-y-3">
                            <div className="flex items-center gap-3 rounded-xl border border-dashed border-border bg-muted/40 p-3">
                                <CategoryIcon category={null} box={40} size={20} />
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <h3 className="text-sm font-semibold text-muted-foreground">Uncategorised</h3>
                                        <span className="rounded-full bg-background/70 px-2 py-0.5 text-[11px] font-semibold tabular-nums text-foreground">
                                            {uncategorised.length} {uncategorised.length === 1 ? 'checklist' : 'checklists'}
                                        </span>
                                    </div>
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        Edit a template and pick a category to file it under the right group.
                                    </p>
                                </div>
                            </div>
                            {grid(uncategorised)}
                        </div>
                    ) : null}
                </>
            )}
        </div>
    );
}
