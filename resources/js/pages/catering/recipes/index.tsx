import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Head, Link, router } from '@inertiajs/react';
import { ChefHat, Eye, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import { CateringTabs, LibraryDeprecationNotice } from '../_tabs';
import { type DietaryTag, type Recipe, tagBadgeStyle } from '../_helpers';

type PaginationLink = { url: string | null; label: string; active: boolean };
type Paginated<T> = { data: T[]; links: PaginationLink[]; last_page?: number };

type Props = {
    recipes: Paginated<Recipe>;
    tags: DietaryTag[];
    filters: { q: string; inactive: boolean };
    canManage: boolean;
};

export default function CateringRecipesIndex({ recipes, filters, canManage }: Props) {
    const [search, setSearch] = useState(filters.q ?? '');
    const [showDrafts, setShowDrafts] = useState(filters.inactive ?? false);

    function applyFilters(nextDrafts = showDrafts) {
        router.get('/catering/recipes', { q: search || undefined, inactive: nextDrafts ? 1 : undefined }, { preserveState: true, replace: true });
    }

    const activeCount = recipes.data.filter((r) => r.is_active).length;
    const draftCount = recipes.data.length - activeCount;

    return (
        <AppLayout breadcrumbs={[{ title: 'Sites & Locations', href: '/sites' }, { title: 'Catering', href: '/catering' }, { title: 'Recipes', href: '/catering/recipes' }]}>
            <Head title="Recipes" />
            <PageLayout
                hero={
                    <PageHero
                        icon={ChefHat}
                        title="Recipes"
                        description="Reusable recipes for meal planning at any site."
                        stats={[
                            { label: 'Total', value: recipes.data.length },
                            { label: 'Active', value: activeCount },
                            { label: 'Draft', value: draftCount },
                        ]}
                        actions={
                            canManage && (
                                <Button asChild>
                                    <Link href="/catering/recipes/create">
                                        <Plus className="mr-2 h-4 w-4" /> New recipe
                                    </Link>
                                </Button>
                            )
                        }
                    />
                }
            >
                <CateringTabs active="recipes" />
                <LibraryDeprecationNotice thing="Recipes" />

                <div className="flex flex-wrap items-end gap-3">
                    <div className="flex-1 min-w-[240px]">
                        <Label>Search</Label>
                        <Input value={search} onChange={(e) => setSearch(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && applyFilters()} placeholder="Search recipe name" />
                    </div>
                    <label className="flex h-9 items-center gap-2 rounded-md border border-border bg-card px-3 text-sm">
                        <input type="checkbox" checked={showDrafts} onChange={(e) => { setShowDrafts(e.target.checked); applyFilters(e.target.checked); }} />
                        Show drafts
                    </label>
                    <Button variant="outline" onClick={() => applyFilters()}>Apply</Button>
                </div>

                <div className="rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Serves</TableHead>
                                <TableHead>Tags</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="w-32">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {recipes.data.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={5} className="py-8 text-center text-muted-foreground">
                                        {filters.q ? (
                                            <div className="flex flex-col items-center gap-2">
                                                <span>No recipes match your search.</span>
                                                <Button variant="ghost" size="sm" onClick={() => { setSearch(''); router.get('/catering/recipes', {}, { preserveState: true, replace: true }); }}>Clear search</Button>
                                            </div>
                                        ) : (
                                            <div className="flex flex-col items-center gap-2">
                                                <ChefHat className="h-8 w-8 text-muted-foreground/50" />
                                                <span className="font-medium text-foreground">No recipes yet</span>
                                                <span className="text-xs">Build your first recipe, or activate a draft from the shared library.</span>
                                                {canManage && <Button asChild size="sm"><Link href="/catering/recipes/create"><Plus className="mr-1.5 h-4 w-4" /> New recipe</Link></Button>}
                                            </div>
                                        )}
                                    </TableCell>
                                </TableRow>
                            )}
                            {recipes.data.map((r) => (
                                <TableRow key={r.id}>
                                    <TableCell className="font-medium">{r.name}</TableCell>
                                    <TableCell>{r.serves_default}</TableCell>
                                    <TableCell>
                                        <div className="flex flex-wrap gap-1">
                                            {(r.tags ?? []).map((t) => (
                                                <Badge key={t.id} variant="outline" style={tagBadgeStyle(t)} className="text-xs">{t.label}</Badge>
                                            ))}
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        {r.is_active
                                            ? <Badge variant="outline" className="border-green-200 bg-green-50 text-green-800">Active</Badge>
                                            : <Badge variant="outline">Draft</Badge>}
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex gap-1">
                                            <Button asChild size="icon" variant="ghost"><Link href={`/catering/recipes/${r.id}`}><Eye className="h-4 w-4" /></Link></Button>
                                            {canManage && <Button asChild size="icon" variant="ghost"><Link href={`/catering/recipes/${r.id}/edit`}><Pencil className="h-4 w-4" /></Link></Button>}
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
                <LaravelPagination links={recipes.links} lastPage={recipes.last_page} />
            </PageLayout>
        </AppLayout>
    );
}
