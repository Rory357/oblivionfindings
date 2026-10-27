import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import axios from 'axios';
import { Loader2 } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import type { HubSiteOption } from './add-hub-wizard';

export type EditHubTarget = {
    id: number;
    name: string;
    site: HubSiteOption | null;
    covered_site_ids?: number[];
    update_url: string | null;
};

function updateErrorMessage(error: unknown): string {
    if (axios.isAxiosError(error)) {
        const validation = error.response?.data?.errors;
        if (validation && typeof validation === 'object') {
            const first = Object.values(validation as Record<string, unknown>)
                .flatMap((value) => (Array.isArray(value) ? value : [value]))
                .find(
                    (value): value is string =>
                        typeof value === 'string' && value.length > 0,
                );
            if (first) return first;
        }
        if (typeof error.response?.data?.message === 'string') {
            return error.response.data.message;
        }
    }

    return 'Could not save hub settings. Check your Site access and try again.';
}

export function EditHubDialog({
    open,
    onOpenChange,
    hub,
    sites,
    onSaved,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    hub: EditHubTarget | null;
    sites: HubSiteOption[];
    onSaved: () => void;
}) {
    const [name, setName] = useState('');
    const [coveredSiteIds, setCoveredSiteIds] = useState<number[]>([]);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!open || !hub) {
            setError(null);
            setSaving(false);
            return;
        }
        setName(hub.name);
        setCoveredSiteIds(hub.covered_site_ids ?? []);
        setError(null);
    }, [open, hub]);

    if (!hub) return null;

    const otherSites = sites.filter((item) => item.id !== hub.site?.id);

    const toggleSite = (siteId: number, checked: boolean) => {
        setCoveredSiteIds((before) =>
            checked
                ? [...new Set([...before, siteId])]
                : before.filter((id) => id !== siteId),
        );
    };

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        if (!hub.update_url || name.trim().length < 2) return;

        setSaving(true);
        setError(null);
        try {
            await axios.patch(
                hub.update_url,
                {
                    name: name.trim(),
                    covered_site_ids: coveredSiteIds,
                },
                { headers: { Accept: 'application/json' } },
            );
            onSaved();
            onOpenChange(false);
        } catch (requestError) {
            setError(updateErrorMessage(requestError));
        } finally {
            setSaving(false);
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next && !saving) onOpenChange(false);
            }}
        >
            <DialogContent
                style={{
                    maxWidth: 'min(92vw, 560px)',
                    width: 'min(92vw, 560px)',
                }}
            >
                <DialogHeader>
                    <DialogTitle>Edit hub · {hub.name}</DialogTitle>
                    <DialogDescription>
                        Update this hub&apos;s display name or choose additional
                        Sites it can scan and monitor over SD-WAN or MPLS.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="space-y-4">
                    {error ? (
                        <Alert variant="destructive">
                            <AlertDescription>{error}</AlertDescription>
                        </Alert>
                    ) : null}

                    <div className="space-y-2">
                        <Label htmlFor="edit-hub-name">Hub name</Label>
                        <Input
                            id="edit-hub-name"
                            value={name}
                            maxLength={120}
                            onChange={(event) => setName(event.target.value)}
                            required
                        />
                        {hub.site ? (
                            <p className="text-xs text-muted-foreground">
                                Home Site: {hub.site.name}
                            </p>
                        ) : null}
                    </div>

                    {otherSites.length > 0 ? (
                        <div className="space-y-2">
                            <Label>
                                Additional Sites covered over SD-WAN or MPLS
                            </Label>
                            <div className="max-h-56 space-y-2 overflow-y-auto pr-1">
                                {otherSites.map((site) => {
                                    const checked = coveredSiteIds.includes(
                                        site.id,
                                    );
                                    return (
                                        <label
                                            key={site.id}
                                            className="flex cursor-pointer items-center gap-3 rounded-lg border p-3 text-sm"
                                        >
                                            <Checkbox
                                                checked={checked}
                                                onCheckedChange={(next) =>
                                                    toggleSite(
                                                        site.id,
                                                        Boolean(next),
                                                    )
                                                }
                                            />
                                            <span>{site.name}</span>
                                        </label>
                                    );
                                })}
                            </div>
                        </div>
                    ) : null}

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={saving}
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={
                                saving ||
                                !hub.update_url ||
                                name.trim().length < 2
                            }
                        >
                            {saving ? (
                                <Loader2 className="mr-1.5 size-4 animate-spin" />
                            ) : null}
                            {saving ? 'Saving…' : 'Save hub settings'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
