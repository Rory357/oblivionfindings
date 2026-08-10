/**
 * Manage credential types — a full-window dialog over the application's credential
 * type registry (the taxonomy that powers the Add-credential tile picker).
 * Reads/writes `/credential-types`. Opened from the hero ⋯ menu.
 */
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    Loader2,
    Plus,
    Settings,
    Trash2,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import {
    type FilterOption,
    FilterSelect,
    resolveCredentialIcon,
} from '../_dialog-shared';

type TypeRow = {
    key: string;
    label: string;
    icon: string;
    description: string | null;
    active: boolean;
    sort_order: number;
    system: boolean;
    count: number;
};

function humanizeIcon(key: string): string {
    return key.replace(/([A-Z])/g, ' $1').replace(/^./, (c) => c.toUpperCase());
}

function xsrf(): string {
    return decodeURIComponent(
        document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
    );
}

export function ManageCredentialTypesDialog({
    isOpen,
    onClose,
}: {
    isOpen: boolean;
    onClose: () => void;
}) {
    const [rows, setRows] = useState<TypeRow[]>([]);
    const [icons, setIcons] = useState<string[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const [adding, setAdding] = useState<{
        label: string;
        icon: string;
        description: string;
    }>({
        label: '',
        icon: 'lock',
        description: '',
    });

    useEffect(() => {
        if (!isOpen) return;
        setLoading(true);
        setError(null);
        setAdding({ label: '', icon: 'lock', description: '' });
        fetch('/credential-types', {
            credentials: 'include',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .then(async (res) => {
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                const data = (await res.json()) as {
                    types: TypeRow[];
                    icons: string[];
                };
                setRows(data.types ?? []);
                setIcons(data.icons ?? []);
            })
            .catch((e) =>
                setError(
                    e instanceof Error
                        ? e.message
                        : 'Could not load credential types.',
                ),
            )
            .finally(() => setLoading(false));
    }, [isOpen]);

    const update = (key: string, patch: Partial<TypeRow>) =>
        setRows((list) =>
            list.map((r) => (r.key === key ? { ...r, ...patch } : r)),
        );

    const remove = (key: string) =>
        setRows((list) => list.filter((r) => r.key !== key));

    const move = (index: number, delta: number) =>
        setRows((list) => {
            const next = [...list];
            const target = index + delta;
            if (target < 0 || target >= next.length) return list;
            [next[index], next[target]] = [next[target], next[index]];
            return next;
        });

    const addType = () => {
        const label = adding.label.trim();
        if (!label) return;
        const key = label
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '');
        if (!key) return;
        if (rows.some((r) => r.key === key)) {
            toast.error('A type with that name already exists.');
            return;
        }
        setRows((list) => [
            ...list,
            {
                key,
                label,
                icon: adding.icon,
                description: adding.description.trim() || null,
                active: true,
                sort_order: list.length,
                system: false,
                count: 0,
            },
        ]);
        setAdding({ label: '', icon: 'lock', description: '' });
    };

    const save = () => {
        setSaving(true);
        fetch('/credential-types', {
            method: 'PUT',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrf(),
            },
            body: JSON.stringify({
                types: rows.map((r, index) => ({
                    key: r.key,
                    label: r.label,
                    icon: r.icon,
                    description: r.description,
                    active: r.active,
                    sort_order: index,
                })),
            }),
        })
            .then(async (res) => {
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                toast.success('Credential types saved');
                // Refresh the page's tile-picker options.
                router.reload({ only: ['credentialTypeOptions'] });
                onClose();
            })
            .catch((e) =>
                toast.error(
                    e instanceof Error
                        ? e.message
                        : 'Could not save credential types.',
                ),
            )
            .finally(() => setSaving(false));
    };

    const iconOptions: FilterOption[] = icons.map((i) => ({
        value: i,
        label: humanizeIcon(i),
        icon: resolveCredentialIcon(i),
    }));

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto"
                style={{
                    maxWidth: 'min(92vw, 720px)',
                    width: 'min(92vw, 720px)',
                }}
            >
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <Settings className="h-4 w-4 text-primary" />
                        Manage credential types
                    </DialogTitle>
                    <DialogDescription>
                        Define the categories shown in the type picker when
                        adding a credential. Types in use can be hidden but not
                        deleted.
                    </DialogDescription>
                </DialogHeader>

                {loading ? (
                    <div className="flex items-center justify-center gap-2 py-12 text-muted-foreground">
                        <Loader2 className="h-5 w-5 animate-spin" />
                        Loading types…
                    </div>
                ) : error ? (
                    <div className="py-12 text-center text-status-critical">
                        {error}
                    </div>
                ) : (
                    <>
                        <div className="mt-2 space-y-2">
                            {rows.map((row, index) => {
                                const Icon = resolveCredentialIcon(row.icon);
                                const locked = row.system || row.count > 0;
                                return (
                                    <div
                                        key={row.key}
                                        className={`flex items-center gap-2 rounded-xl border p-2.5 ${
                                            row.active
                                                ? 'border-border bg-card/40'
                                                : 'border-border bg-muted/40 opacity-70'
                                        }`}
                                    >
                                        <div className="flex flex-col">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                className="h-5 w-6"
                                                aria-label="Move up"
                                                disabled={index === 0}
                                                onClick={() => move(index, -1)}
                                            >
                                                <ArrowUp className="h-3.5 w-3.5" />
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                className="h-5 w-6"
                                                aria-label="Move down"
                                                disabled={
                                                    index === rows.length - 1
                                                }
                                                onClick={() => move(index, 1)}
                                            >
                                                <ArrowDown className="h-3.5 w-3.5" />
                                            </Button>
                                        </div>
                                        <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                            <Icon className="h-4 w-4" />
                                        </span>
                                        <div className="grid min-w-0 flex-1 gap-1 sm:grid-cols-2">
                                            <Input
                                                value={row.label}
                                                onChange={(e) =>
                                                    update(row.key, {
                                                        label: e.target.value,
                                                    })
                                                }
                                                aria-label={`${row.key} name`}
                                                className="h-8"
                                            />
                                            <Input
                                                value={row.description ?? ''}
                                                onChange={(e) =>
                                                    update(row.key, {
                                                        description:
                                                            e.target.value,
                                                    })
                                                }
                                                placeholder="Short description"
                                                aria-label={`${row.key} description`}
                                                className="h-8"
                                            />
                                        </div>
                                        <div className="flex shrink-0 items-center gap-1.5">
                                            {row.count > 0 ? (
                                                <Badge
                                                    variant="outline"
                                                    className="border-border bg-muted text-muted-foreground"
                                                >
                                                    {row.count} in use
                                                </Badge>
                                            ) : (
                                                <Badge
                                                    variant="outline"
                                                    className="border-border text-muted-foreground"
                                                >
                                                    Unused
                                                </Badge>
                                            )}
                                            {row.system && (
                                                <Badge
                                                    variant="outline"
                                                    className="border-status-info/30 bg-status-info-bg text-status-info"
                                                >
                                                    System
                                                </Badge>
                                            )}
                                            <Switch
                                                checked={row.active}
                                                onCheckedChange={(v) =>
                                                    update(row.key, {
                                                        active: !!v,
                                                    })
                                                }
                                                disabled={row.system}
                                                aria-label={
                                                    row.active
                                                        ? 'Visible'
                                                        : 'Hidden'
                                                }
                                            />
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                className="h-8 w-8 text-status-critical hover:text-status-critical"
                                                aria-label="Delete type"
                                                disabled={locked}
                                                onClick={() => remove(row.key)}
                                            >
                                                <Trash2 className="h-4 w-4" />
                                            </Button>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>

                        <div className="mt-4 rounded-xl border border-dashed border-border p-3">
                            <div className="mb-2 flex items-center gap-1.5 text-sm font-medium">
                                <Plus className="h-3.5 w-3.5" />
                                Add a credential type
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                <FilterSelect
                                    value={adding.icon}
                                    onChange={(v) =>
                                        setAdding((a) => ({ ...a, icon: v }))
                                    }
                                    options={iconOptions}
                                    widthClass="w-28"
                                    aria-label="Icon"
                                />
                                <Input
                                    value={adding.label}
                                    onChange={(e) =>
                                        setAdding((a) => ({
                                            ...a,
                                            label: e.target.value,
                                        }))
                                    }
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') {
                                            e.preventDefault();
                                            addType();
                                        }
                                    }}
                                    placeholder="Type name"
                                    aria-label="New type name"
                                    className="w-40"
                                />
                                <Input
                                    value={adding.description}
                                    onChange={(e) =>
                                        setAdding((a) => ({
                                            ...a,
                                            description: e.target.value,
                                        }))
                                    }
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') {
                                            e.preventDefault();
                                            addType();
                                        }
                                    }}
                                    placeholder="Short description"
                                    aria-label="New type description"
                                    className="min-w-[140px] flex-1"
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={addType}
                                    disabled={!adding.label.trim()}
                                >
                                    <Plus className="mr-1 h-4 w-4" />
                                    Add
                                </Button>
                            </div>
                        </div>
                    </>
                )}

                <DialogFooter className="mt-4">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        onClick={save}
                        disabled={saving || loading || !!error}
                    >
                        {saving && (
                            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                        )}
                        Save changes
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
