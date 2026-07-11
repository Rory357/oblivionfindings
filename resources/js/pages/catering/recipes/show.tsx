import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Head, Link } from '@inertiajs/react';
import { ChefHat, Clock, Pencil, ShieldAlert, Utensils } from 'lucide-react';
import { CateringTabs } from '../_tabs';
import { type Recipe, formatQuantity, tagBadgeStyle } from '../_helpers';

type ImpactMatch = {
    label: string;
    severity: string;
    kind: string;
};

type ImpactPanel = {
    client_id: number;
    client_name: string;
    matches: ImpactMatch[];
};

type Impact = {
    site: { id: number; name: string; type: string };
    report: {
        has_hard_blocks: boolean;
        has_soft_warnings: boolean;
        hard_blocks: ImpactPanel[];
        soft_warnings: ImpactPanel[];
    };
};

type Props = { recipe: Recipe; canManage: boolean; impact: Impact | null };

export default function CateringRecipeShow({ recipe, canManage, impact }: Props) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Sites & Locations', href: '/sites' }, { title: 'Catering', href: '/catering' }, { title: 'Recipes', href: '/catering/recipes' }, { title: recipe.name, href: `/catering/recipes/${recipe.id}` }]}>
            <Head title={recipe.name} />
            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref="/catering/recipes"
                        backLabel="Back to recipes"
                        icon={ChefHat}
                        title={recipe.name}
                        description={recipe.description ?? undefined}
                        actions={
                            canManage && (
                                <Button asChild>
                                    <Link href={`/catering/recipes/${recipe.id}/edit`}>
                                        <Pencil className="mr-2 h-4 w-4" /> Edit recipe
                                    </Link>
                                </Button>
                            )
                        }
                    >
                        <div className="flex flex-wrap items-center gap-3 text-sm text-muted-foreground">
                            <span className="flex items-center gap-1"><Utensils className="h-4 w-4" /> Serves {recipe.serves_default}</span>
                            {recipe.prep_minutes !== null && <span className="flex items-center gap-1"><Clock className="h-4 w-4" /> Prep {recipe.prep_minutes}m</span>}
                            {recipe.cook_minutes !== null && <span className="flex items-center gap-1"><Clock className="h-4 w-4" /> Cook {recipe.cook_minutes}m</span>}
                            {recipe.is_active ? <Badge variant="outline" className="border-status-success/30 bg-status-success-bg text-status-success">Active</Badge> : <Badge variant="outline">Draft</Badge>}
                        </div>
                        {(recipe.tags ?? []).length > 0 && (
                            <div className="mt-2 flex flex-wrap gap-1">
                                {(recipe.tags ?? []).map((t) => (
                                    <Badge key={t.id} variant="outline" style={tagBadgeStyle(t)}>{t.label}</Badge>
                                ))}
                            </div>
                        )}
                    </PageHero>
                }
            >
                <CateringTabs active="recipes" />

                {impact && (impact.report.has_hard_blocks || impact.report.has_soft_warnings) && (
                                <div className={`rounded-md border-2 p-4 ${impact.report.has_hard_blocks ? 'border-status-critical/40 bg-status-critical-bg' : 'border-status-warning/30 bg-status-warning-bg'}`}>
                                    <div className={`mb-2 flex items-center gap-2 font-semibold ${impact.report.has_hard_blocks ? 'text-status-critical' : 'text-status-warning'}`}>
                            <ShieldAlert className="h-4 w-4" />
                            Resident impact at {impact.site.name}
                        </div>
                        {impact.report.has_hard_blocks && (
                            <div className="mb-2">
                                            <div className="text-xs font-semibold uppercase tracking-wider text-status-critical">Allergy alerts (hard block)</div>
                                            <ul className="mt-1 space-y-1 text-sm text-status-critical">
                                    {impact.report.hard_blocks.map((p) => (
                                        <li key={`h-${p.client_id}`}><strong>{p.client_name}</strong>: {p.matches.map((m) => m.label).join(', ')}</li>
                                    ))}
                                </ul>
                            </div>
                        )}
                        {impact.report.has_soft_warnings && (
                            <div>
                                            <div className="text-xs font-semibold uppercase tracking-wider text-status-warning">Soft warnings</div>
                                            <ul className="mt-1 space-y-1 text-sm text-status-warning">
                                    {impact.report.soft_warnings.map((p) => (
                                        <li key={`s-${p.client_id}`}><strong>{p.client_name}</strong>: {p.matches.map((m) => `${m.label}${m.kind === 'dislike' ? ' (dislike)' : ''}`).join(', ')}</li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </div>
                )}
                {impact && !impact.report.has_hard_blocks && !impact.report.has_soft_warnings && (
                            <div className="rounded-md border border-status-success/30 bg-status-success-bg p-3 text-sm text-status-success">
                        ✓ No dietary conflicts for current residents at {impact.site.name}.
                    </div>
                )}

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <div className="lg:col-span-1">
                        <h2 className="mb-2 text-lg font-medium">Ingredients</h2>
                        <ul className="space-y-2">
                            {(recipe.ingredients ?? []).length === 0 && <li className="text-sm text-muted-foreground">No ingredients yet.</li>}
                            {(recipe.ingredients ?? []).map((ing) => (
                                <li key={ing.id} className="rounded-md border p-2 text-sm">
                                    <span className="font-medium">{(ing as { product?: { name?: string } }).product?.name ?? ing.free_text_name}</span>
                                    <span className="text-muted-foreground"> — {formatQuantity(ing.quantity, ing.unit)}</span>
                                    {ing.notes && <div className="mt-1 text-xs text-muted-foreground">{ing.notes}</div>}
                                </li>
                            ))}
                        </ul>
                    </div>

                    <div className="lg:col-span-2">
                        <h2 className="mb-2 text-lg font-medium">Instructions</h2>
                        {recipe.instructions
                            ? <div className="whitespace-pre-wrap rounded-md border bg-muted/30 p-4 text-sm">{recipe.instructions}</div>
                            : <p className="text-sm text-muted-foreground">No instructions added.</p>}
                    </div>
                </div>
            </PageLayout>
        </AppLayout>
    );
}
