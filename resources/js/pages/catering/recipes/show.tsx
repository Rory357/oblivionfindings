import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Head, Link } from '@inertiajs/react';
import { ChefHat, Clock, Pencil, ShieldAlert, Utensils } from 'lucide-react';
import { CateringHero } from '../_hero';
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
        <AppLayout breadcrumbs={[{ title: 'Catering', href: '/catering' }, { title: 'Recipes', href: '/catering/recipes' }, { title: recipe.name, href: `/catering/recipes/${recipe.id}` }]}>
            <Head title={recipe.name} />
            <div className="space-y-6 p-6">
                <CateringHero />
                <CateringTabs active="recipes" />

                <div className="flex items-start justify-between gap-4">
                    <div className="flex items-start gap-3">
                        <div className="rounded-full bg-primary/10 p-3 text-primary"><ChefHat className="h-6 w-6" /></div>
                        <div>
                            <h1 className="text-2xl font-semibold">{recipe.name}</h1>
                            {recipe.description && <p className="mt-1 text-sm text-muted-foreground">{recipe.description}</p>}
                            <div className="mt-3 flex flex-wrap items-center gap-3 text-sm text-muted-foreground">
                                <span className="flex items-center gap-1"><Utensils className="h-4 w-4" /> Serves {recipe.serves_default}</span>
                                {recipe.prep_minutes !== null && <span className="flex items-center gap-1"><Clock className="h-4 w-4" /> Prep {recipe.prep_minutes}m</span>}
                                {recipe.cook_minutes !== null && <span className="flex items-center gap-1"><Clock className="h-4 w-4" /> Cook {recipe.cook_minutes}m</span>}
                                {recipe.is_active ? <Badge variant="outline" className="border-green-200 bg-green-50 text-green-800">Active</Badge> : <Badge variant="outline">Draft</Badge>}
                            </div>
                            <div className="mt-2 flex flex-wrap gap-1">
                                {(recipe.tags ?? []).map((t) => (
                                    <Badge key={t.id} variant="outline" style={tagBadgeStyle(t)}>{t.label}</Badge>
                                ))}
                            </div>
                        </div>
                    </div>
                    {canManage && (
                        <Button asChild>
                            <Link href={`/catering/recipes/${recipe.id}/edit`}><Pencil className="mr-2 h-4 w-4" /> Edit recipe</Link>
                        </Button>
                    )}
                </div>

                {impact && (impact.report.has_hard_blocks || impact.report.has_soft_warnings) && (
                    <div className={`rounded-md border-2 p-4 ${impact.report.has_hard_blocks ? 'border-red-400 bg-red-50' : 'border-amber-300 bg-amber-50'}`}>
                        <div className={`mb-2 flex items-center gap-2 font-semibold ${impact.report.has_hard_blocks ? 'text-red-900' : 'text-amber-900'}`}>
                            <ShieldAlert className="h-4 w-4" />
                            Resident impact at {impact.site.name}
                        </div>
                        {impact.report.has_hard_blocks && (
                            <div className="mb-2">
                                <div className="text-xs font-semibold uppercase tracking-wider text-red-900">Allergy alerts (hard block)</div>
                                <ul className="mt-1 space-y-1 text-sm text-red-900">
                                    {impact.report.hard_blocks.map((p) => (
                                        <li key={`h-${p.client_id}`}><strong>{p.client_name}</strong>: {p.matches.map((m) => m.label).join(', ')}</li>
                                    ))}
                                </ul>
                            </div>
                        )}
                        {impact.report.has_soft_warnings && (
                            <div>
                                <div className="text-xs font-semibold uppercase tracking-wider text-amber-900">Soft warnings</div>
                                <ul className="mt-1 space-y-1 text-sm text-amber-900">
                                    {impact.report.soft_warnings.map((p) => (
                                        <li key={`s-${p.client_id}`}><strong>{p.client_name}</strong>: {p.matches.map((m) => `${m.label}${m.kind === 'dislike' ? ' (dislike)' : ''}`).join(', ')}</li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </div>
                )}
                {impact && !impact.report.has_hard_blocks && !impact.report.has_soft_warnings && (
                    <div className="rounded-md border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900">
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
            </div>
        </AppLayout>
    );
}
