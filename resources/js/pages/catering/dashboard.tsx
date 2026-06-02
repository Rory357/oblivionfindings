import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { PageHero } from '@/components/page';
import { CateringTabs } from './_tabs';
import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Building2,
    CalendarDays,
    ChefHat,
    Home,
    LayoutGrid,
    Package,
    ShoppingCart,
    Sparkles,
    Utensils,
} from 'lucide-react';

type SiteCard = {
    id: number;
    name: string;
    type: string;
    meals_planned_this_week: number;
    meals_served_this_week: number;
    inventory_items: number;
    low_stock_count: number;
    out_of_stock_count: number;
    top_low_stock: { product_name: string | null; current_qty: number; unit: string }[];
    inventory_value_cents: number;
    week_cost_cents: number;
    draft_list_id: number | null;
    ordered_list_count: number;
};

type Totals = {
    sites: number;
    meals_this_week: number;
    meals_served: number;
    low_stock: number;
    out_of_stock: number;
    inventory_value_cents: number;
    week_cost_cents: number;
    draft_lists: number;
};

type Library = {
    recipe_count: number;
    recipe_total: number;
    product_count: number;
    tag_count: number;
    allergen_count: number;
    recent_recipes: { id: number; name: string; slug: string; serves_default: number }[];
};

type Props = {
    week_start: string;
    week_end: string;
    sites: SiteCard[];
    totals: Totals;
    library: Library;
};

function formatMoney(cents: number): string {
    try {
        return new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(cents / 100);
    } catch {
        return `$${(cents / 100).toFixed(2)}`;
    }
}

function siteIcon(type: string) {
    if (type === 'house') return Home;
    if (type === 'facility') return LayoutGrid;
    if (type === 'head_office') return Building2;
    return Building2;
}

export default function CateringDashboard({ week_start, week_end, sites, totals, library }: Props) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Catering', href: '/catering' }, { title: 'Overview', href: '/catering/overview' }]}>
            <Head title="Meal Planner — Overview" />
            <div className="space-y-6 p-6">
                <PageHero
                    icon={Utensils}
                    title="Meal Planner"
                    description="Cross-site overview of meal plans, kitchen inventory and the catering library."
                    badges={[
                        { icon: CalendarDays, label: `Week of ${week_start} → ${week_end}` },
                        { icon: Home, label: `${totals.sites} site${totals.sites === 1 ? '' : 's'}` },
                        ...(totals.low_stock > 0 ? [{ icon: AlertTriangle, label: `${totals.low_stock} low stock`, tone: 'warning' as const }] : []),
                        ...(totals.draft_lists > 0 ? [{ icon: ShoppingCart, label: `${totals.draft_lists} draft list${totals.draft_lists === 1 ? '' : 's'}` }] : []),
                    ]}
                    stats={[
                        { value: formatMoney(totals.week_cost_cents), label: 'Week cost' },
                        { value: totals.meals_this_week, label: 'Meals planned' },
                        { value: library.recipe_count, label: 'Recipes' },
                        { value: library.product_count, label: 'Products' },
                    ]}
                />

                <CateringTabs active="overview" counts={{ recipes: library.recipe_count, products: library.product_count, tags: library.tag_count }} />

                {/* Per-site cards */}
                <section>
                    <div className="mb-3 flex items-center justify-between">
                        <h2 className="text-lg font-semibold">By site</h2>
                        <span className="text-xs text-muted-foreground">{sites.length} active site{sites.length === 1 ? '' : 's'}</span>
                    </div>
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {sites.length === 0 && (
                            <div className="col-span-full rounded-md border border-dashed p-8 text-center text-sm text-muted-foreground">
                                No active sites yet. Add one in <Link href="/sites" className="text-primary underline">Sites</Link>.
                            </div>
                        )}
                        {sites.map((site) => {
                            const Icon = siteIcon(site.type);
                            const hasLow = site.low_stock_count > 0;
                            return (
                                <Card key={site.id} className="flex flex-col">
                                    <CardHeader className="pb-3">
                                        <div className="flex items-start justify-between gap-2">
                                            <div className="flex items-center gap-2">
                                                <div className="rounded-md bg-primary/10 p-2 text-primary"><Icon className="h-4 w-4" /></div>
                                                <div>
                                                    <CardTitle className="text-base">
                                                        <Link href={`/sites/${site.id}?tab=meal-planner`} className="hover:underline">
                                                            {site.name}
                                                        </Link>
                                                    </CardTitle>
                                                    <Badge variant="outline" className="mt-1 capitalize text-[10px]">{site.type.replace('_', ' ')}</Badge>
                                                </div>
                                            </div>
                                            {hasLow && <Badge variant="outline" className="border-red-300 bg-red-50 text-red-800">{site.low_stock_count} low</Badge>}
                                        </div>
                                    </CardHeader>
                                    <CardContent className="flex-1 space-y-3">
                                        <div className="grid grid-cols-3 gap-2 text-center">
                                            <div className="rounded-md bg-muted/40 p-2">
                                                <div className="text-lg font-semibold">{site.meals_planned_this_week}</div>
                                                <div className="text-[10px] uppercase tracking-wide text-muted-foreground">Meals planned</div>
                                            </div>
                                            <div className="rounded-md bg-muted/40 p-2">
                                                <div className="text-lg font-semibold">{site.inventory_items}</div>
                                                <div className="text-[10px] uppercase tracking-wide text-muted-foreground">Inventory items</div>
                                            </div>
                                            <div className="rounded-md bg-muted/40 p-2">
                                                <div className="text-lg font-semibold">{formatMoney(site.week_cost_cents)}</div>
                                                <div className="text-[10px] uppercase tracking-wide text-muted-foreground">Week cost</div>
                                            </div>
                                        </div>

                                        {site.top_low_stock.length > 0 && (
                                            <div className="rounded-md border border-red-200 bg-red-50/50 p-2">
                                                <div className="mb-1 flex items-center gap-1 text-xs font-medium text-red-900">
                                                    <AlertTriangle className="h-3 w-3" /> Needs restocking
                                                </div>
                                                <ul className="space-y-0.5 text-xs text-red-900/90">
                                                    {site.top_low_stock.map((row, i) => (
                                                        <li key={i}>
                                                            <strong>{row.product_name ?? 'Item'}</strong>: {row.current_qty} {row.unit}
                                                        </li>
                                                    ))}
                                                </ul>
                                            </div>
                                        )}

                                        {site.draft_list_id && (
                                            <div className="flex items-center justify-between rounded-md border bg-amber-50/50 px-2 py-1 text-xs text-amber-900">
                                                <span className="flex items-center gap-1"><ShoppingCart className="h-3 w-3" /> Draft shopping list ready</span>
                                                <Link href={`/sites/${site.id}?tab=meal-planner`} className="font-medium underline">Open</Link>
                                            </div>
                                        )}
                                    </CardContent>
                                    <div className="border-t bg-muted/20 px-4 py-2">
                                        <Button asChild variant="ghost" size="sm" className="w-full justify-between">
                                            <Link href={`/sites/${site.id}?tab=meal-planner`}>
                                                Open meal planner
                                                <Sparkles className="h-3 w-3 text-primary" />
                                            </Link>
                                        </Button>
                                    </div>
                                </Card>
                            );
                        })}
                    </div>
                </section>

                {/* Catering library */}
                <section>
                    <h2 className="mb-3 text-lg font-semibold">Catering library</h2>
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <LibraryCard
                            href="/catering/recipes"
                            icon={ChefHat}
                            title="Recipes"
                            primary={`${library.recipe_count} active`}
                            secondary={`${library.recipe_total - library.recipe_count} drafts`}
                            description="Reusable recipes shared across every site."
                        />
                        <LibraryCard
                            href="/catering/products"
                            icon={Package}
                            title="Products"
                            primary={`${library.product_count}`}
                            secondary="active products"
                            description="Master catalogue of ingredients and consumables."
                        />
                        <LibraryCard
                            href="/catering/tags"
                            icon={AlertTriangle}
                            title="Dietary & Allergens"
                            primary={`${library.allergen_count}`}
                            secondary={`allergens · ${library.tag_count - library.allergen_count} dietary`}
                            description="Drives resident allergen warnings on plan entries."
                        />
                    </div>

                    {library.recent_recipes.length > 0 && (
                        <div className="mt-4 rounded-md border bg-card p-4">
                            <div className="mb-2 text-sm font-medium">Recently updated recipes</div>
                            <div className="grid grid-cols-2 gap-2 md:grid-cols-3">
                                {library.recent_recipes.map((r) => (
                                    <Link
                                        key={r.id}
                                        href={`/catering/recipes/${r.id}`}
                                        className="flex items-center justify-between rounded-md border px-3 py-2 text-sm hover:bg-accent"
                                    >
                                        <span className="font-medium">{r.name}</span>
                                        <span className="text-xs text-muted-foreground">Serves {r.serves_default}</span>
                                    </Link>
                                ))}
                            </div>
                        </div>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}

function LibraryCard({
    href,
    icon: Icon,
    title,
    primary,
    secondary,
    description,
}: {
    href: string;
    icon: React.ComponentType<{ className?: string }>;
    title: string;
    primary: string;
    secondary: string;
    description: string;
}) {
    return (
        <Link href={href} className="group block">
            <Card className="h-full transition group-hover:border-primary group-hover:shadow-sm">
                <CardContent className="flex h-full flex-col gap-3 p-4">
                    <div className="flex items-center justify-between">
                        <div className="rounded-md bg-primary/10 p-2 text-primary"><Icon className="h-5 w-5" /></div>
                        <div className="text-right">
                            <div className="text-2xl font-semibold">{primary}</div>
                            <div className="text-xs text-muted-foreground">{secondary}</div>
                        </div>
                    </div>
                    <div>
                        <div className="font-medium">{title}</div>
                        <div className="text-xs text-muted-foreground">{description}</div>
                    </div>
                </CardContent>
            </Card>
        </Link>
    );
}
