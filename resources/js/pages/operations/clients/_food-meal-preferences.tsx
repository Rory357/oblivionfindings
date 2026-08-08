import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ConfirmAction } from '@/pages/sites/_confirm-action';
import { router } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertTriangle,
    Plus,
    ShieldAlert,
    Trash2,
    Utensils,
} from 'lucide-react';
import { useEffect, useState } from 'react';

type Tag = {
    id: number;
    key: string;
    label: string;
    kind: 'dietary' | 'allergen';
    severity: 'info' | 'warn' | 'critical';
    color: string | null;
};

type Dislike = {
    id: number;
    product_id: number | null;
    product_name: string | null;
    free_text_name: string | null;
    notes: string | null;
};

type ProductOpt = { id: number; name: string; default_unit: string };

type Bundle = {
    client_id: number;
    allergens: Tag[];
    preferences: Tag[];
    dislikes: Dislike[];
    tag_catalogue: { allergens: Tag[]; preferences: Tag[] };
    products: ProductOpt[];
};

function tagBadgeStyle(tag: Tag): React.CSSProperties {
    if (!tag.color) return {};
    return {
        backgroundColor: `${tag.color}22`,
        color: tag.color,
        borderColor: `${tag.color}55`,
    };
}

export function FoodMealPreferences({
    clientId,
    canEdit,
}: {
    clientId: number;
    canEdit: boolean;
}) {
    const [bundle, setBundle] = useState<Bundle | null>(null);
    const [showAdd, setShowAdd] = useState(false);
    const [busy, setBusy] = useState(false);

    const reload = () => {
        axios
            .get(`/clients/${clientId}/meal-preferences`)
            .then((res) => setBundle(res.data));
    };

    useEffect(() => {
        reload();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [clientId]);

    if (!bundle) {
        return (
            <Card className="border-primary/30">
                <CardContent className="p-4 text-sm text-muted-foreground">
                    Loading food &amp; meal preferences…
                </CardContent>
            </Card>
        );
    }

    function toggleTag(kind: 'allergens' | 'preferences', tagId: number) {
        if (!canEdit) return;
        const current = new Set([
            ...bundle!.allergens.map((t) => t.id),
            ...bundle!.preferences.map((t) => t.id),
        ]);
        if (current.has(tagId)) current.delete(tagId);
        else current.add(tagId);
        setBusy(true);
        router.put(
            `/clients/${clientId}/meal-preferences/tags`,
            { tag_ids: Array.from(current) },
            {
                preserveScroll: true,
                onSuccess: () => reload(),
                onFinish: () => setBusy(false),
            },
        );
    }

    return (
        <Card className="border-primary/30">
            <CardContent className="space-y-4 p-4">
                <div className="flex items-center justify-between">
                    <p className="flex items-center gap-2 text-[10px] font-bold tracking-wider text-primary uppercase">
                        <Utensils className="h-3.5 w-3.5" /> Food &amp; Meal
                        Preferences
                    </p>
                    {canEdit && (
                        <Badge variant="outline" className="text-[10px]">
                            Used for meal-planner warnings
                        </Badge>
                    )}
                </div>

                {/* Allergies (hard block in planner) */}
                <Section
                    title="Food allergies"
                    icon={ShieldAlert}
                    tone="critical"
                    hint="Hard-block warning at meal time — override requires a logged reason."
                >
                    <TagPicker
                        catalogue={bundle.tag_catalogue.allergens}
                        selected={bundle.allergens}
                        canEdit={canEdit}
                        onToggle={(id) => toggleTag('allergens', id)}
                        emptyLabel="No food allergies recorded."
                    />
                </Section>

                {/* Preferences (soft warning) */}
                <Section
                    title="Dietary preferences"
                    icon={Utensils}
                    tone="info"
                    hint="Soft warning at meal time — staff can plan anyway after reviewing."
                >
                    <TagPicker
                        catalogue={bundle.tag_catalogue.preferences}
                        selected={bundle.preferences}
                        canEdit={canEdit}
                        onToggle={(id) => toggleTag('preferences', id)}
                        emptyLabel="No dietary preferences recorded."
                    />
                </Section>

                {/* Dislikes (soft warning) */}
                <Section
                    title="Dislikes"
                    icon={AlertTriangle}
                    tone="warn"
                    hint="Specific foods this resident doesn't enjoy. Soft warning at meal time."
                >
                    {bundle.dislikes.length === 0 && (
                        <div className="text-xs text-muted-foreground">
                            No dislikes recorded.
                        </div>
                    )}
                    <div className="space-y-1">
                        {bundle.dislikes.map((d) => (
                            <DislikeRow
                                key={d.id}
                                clientId={clientId}
                                dislike={d}
                                canEdit={canEdit}
                                onChanged={reload}
                            />
                        ))}
                    </div>
                    {canEdit &&
                        (showAdd ? (
                            <AddDislikeRow
                                clientId={clientId}
                                products={bundle.products}
                                onCancel={() => setShowAdd(false)}
                                onDone={() => {
                                    setShowAdd(false);
                                    reload();
                                }}
                            />
                        ) : (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => setShowAdd(true)}
                            >
                                <Plus className="mr-1 h-3 w-3" /> Add dislike
                            </Button>
                        ))}
                </Section>
            </CardContent>
        </Card>
    );
}

function Section({
    title,
    icon: Icon,
    tone,
    hint,
    children,
}: {
    title: string;
    icon: React.ComponentType<{ className?: string }>;
    tone: 'critical' | 'warn' | 'info';
    hint: string;
    children: React.ReactNode;
}) {
    const toneClass = {
        critical: 'text-red-800',
        warn: 'text-amber-800',
        info: 'text-sky-800',
    }[tone];
    return (
        <div>
            <div
                className={`flex items-center gap-1.5 text-xs font-semibold ${toneClass}`}
            >
                <Icon className="h-3.5 w-3.5" /> {title}
            </div>
            <div className="text-[10px] text-muted-foreground">{hint}</div>
            <div className="mt-2">{children}</div>
        </div>
    );
}

function TagPicker({
    catalogue,
    selected,
    canEdit,
    onToggle,
    emptyLabel,
}: {
    catalogue: Tag[];
    selected: Tag[];
    canEdit: boolean;
    onToggle: (id: number) => void;
    emptyLabel: string;
}) {
    const selectedIds = new Set(selected.map((t) => t.id));
    if (!canEdit) {
        if (selected.length === 0)
            return (
                <div className="text-xs text-muted-foreground">
                    {emptyLabel}
                </div>
            );
        return (
            <div className="flex flex-wrap gap-1">
                {selected.map((t) => (
                    <Badge
                        key={t.id}
                        variant="outline"
                        style={tagBadgeStyle(t)}
                        className="text-xs"
                    >
                        {t.label}
                    </Badge>
                ))}
            </div>
        );
    }
    return (
        <div className="flex flex-wrap gap-1">
            {catalogue.map((t) => {
                const isSelected = selectedIds.has(t.id);
                return (
                    <Button
                        unstyled
                        key={t.id}
                        type="button"
                        onClick={() => onToggle(t.id)}
                        className={`rounded-md border px-2 py-0.5 text-xs transition ${isSelected ? 'border-primary bg-primary/10 ring-1 ring-primary' : 'border-transparent hover:bg-accent'}`}
                        style={tagBadgeStyle(t)}
                    >
                        {t.label}
                    </Button>
                );
            })}
            {catalogue.length === 0 && (
                <span className="text-xs text-muted-foreground">
                    No catalogue tags yet.
                </span>
            )}
        </div>
    );
}

function DislikeRow({
    clientId,
    dislike,
    canEdit,
    onChanged,
}: {
    clientId: number;
    dislike: Dislike;
    canEdit: boolean;
    onChanged: () => void;
}) {
    const name = dislike.product_name ?? dislike.free_text_name ?? 'Unnamed';
    function destroy() {
        router.delete(
            `/clients/${clientId}/meal-preferences/dislikes/${dislike.id}`,
            {
                preserveScroll: true,
                onSuccess: () => onChanged(),
            },
        );
    }
    return (
        <div className="flex items-center justify-between gap-2 rounded-md border bg-muted/20 px-2 py-1 text-xs">
            <div className="min-w-0 flex-1">
                <span className="font-medium">{name}</span>
                {dislike.notes && (
                    <span className="ml-2 text-muted-foreground">
                        — {dislike.notes}
                    </span>
                )}
                {dislike.product_id && (
                    <Badge variant="outline" className="ml-1 text-[10px]">
                        linked
                    </Badge>
                )}
            </div>
            {canEdit && (
                <ConfirmAction
                    title="Remove this dislike?"
                    description={`Remove "${name}" from this resident's dislikes.`}
                    confirmLabel="Remove"
                    onConfirm={destroy}
                >
                    <Button size="icon" variant="ghost">
                        <Trash2 className="h-3.5 w-3.5 text-destructive" />
                    </Button>
                </ConfirmAction>
            )}
        </div>
    );
}

function AddDislikeRow({
    clientId,
    products,
    onCancel,
    onDone,
}: {
    clientId: number;
    products: ProductOpt[];
    onCancel: () => void;
    onDone: () => void;
}) {
    const [productId, setProductId] = useState<string>('free');
    const [name, setName] = useState('');
    const [notes, setNotes] = useState('');
    const [busy, setBusy] = useState(false);

    function submit(e: React.FormEvent) {
        e.preventDefault();
        setBusy(true);
        router.post(
            `/clients/${clientId}/meal-preferences/dislikes`,
            {
                product_id: productId === 'free' ? null : Number(productId),
                free_text_name: productId === 'free' ? name : null,
                notes: notes || null,
            },
            {
                preserveScroll: true,
                onSuccess: () => onDone(),
                onFinish: () => setBusy(false),
            },
        );
    }

    return (
        <form
            onSubmit={submit}
            className="grid grid-cols-12 items-end gap-2 rounded-md border bg-muted/30 p-2"
        >
            <div className="col-span-5">
                <Label className="text-[10px]">Product</Label>
                <Select value={productId} onValueChange={setProductId}>
                    <SelectTrigger>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="free">— Free text —</SelectItem>
                        {products.map((p) => (
                            <SelectItem key={p.id} value={String(p.id)}>
                                {p.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                {productId === 'free' && (
                    <Input
                        className="mt-1"
                        placeholder="e.g. mushrooms"
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        required
                    />
                )}
            </div>
            <div className="col-span-5">
                <Label className="text-[10px]">Note (optional)</Label>
                <Input
                    value={notes}
                    onChange={(e) => setNotes(e.target.value)}
                    placeholder="e.g. ok in soup but not whole"
                />
            </div>
            <div className="col-span-2 flex gap-1">
                <Button type="submit" size="sm" disabled={busy}>
                    Add
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={onCancel}
                >
                    Cancel
                </Button>
            </div>
        </form>
    );
}
