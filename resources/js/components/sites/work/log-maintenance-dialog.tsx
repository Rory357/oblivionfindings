/**
 * Log maintenance — records servicing or repair work on one asset. Opened
 * from an overdue asset, the asset is locked; opened from a Site, it asks
 * which asset; opened from a list header, it asks which Site first. Saves to
 * the existing POST /assets/{asset}/maintenance (assets.maintenance.record +
 * AssetPolicy), which returns to this page. A next service date moves the
 * asset's due date on; leaving it blank clears the date that was due.
 */
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import {
    VehicleSearchSelect,
    type VehicleSelectOption,
} from '@/components/fleet-assets/vehicle-workspace/search-select';
import {
    PopupCallout,
    PopupField,
    PopupForm,
    PopupSection,
} from '@/components/popup';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { toDateInput } from '@/lib/datetime';
import { useForm } from '@inertiajs/react';
import { Wrench } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import {
    dueLine,
    siteSelectOptions,
    useSiteWorkRead,
    type SiteWorkAssetRef,
    type SiteWorkSite,
} from './shared';

type AssetOptions = { total: number; items: SiteWorkAssetRef[] };

function assetLabel(asset: SiteWorkAssetRef): string {
    return asset.asset_tag ? `${asset.name} · ${asset.asset_tag}` : asset.name;
}

export function LogMaintenanceDialog({
    open,
    onClose,
    site,
    sites = [],
    asset,
    onSaved,
}: {
    open: boolean;
    onClose: () => void;
    /** Locks the Site. */
    site?: SiteWorkSite;
    /** Sites to choose from when no Site is locked. */
    sites?: SiteWorkSite[];
    /** Locks the asset (needs `site`). */
    asset?: SiteWorkAssetRef;
    onSaved?: () => void;
}) {
    const [siteId, setSiteId] = useState(site ? String(site.id) : '');
    const [assetId, setAssetId] = useState(asset ? String(asset.id) : '');
    const [picked, setPicked] = useState<SiteWorkAssetRef | null>(
        asset ?? null,
    );
    const [pickError, setPickError] = useState<{
        site?: string;
        asset?: string;
    }>({});
    const [search, setSearch] = useState('');
    const [query, setQuery] = useState('');
    const [reload, setReload] = useState(0);

    // Debounce the server search; the picker filters what it has meanwhile.
    useEffect(() => {
        const handle = setTimeout(() => setQuery(search.trim()), 250);
        return () => clearTimeout(handle);
    }, [search]);

    const optionsUrl =
        !asset && siteId
            ? `/sites/${siteId}/work-options?${new URLSearchParams({
                  kind: 'assets',
                  ...(query ? { q: query } : {}),
              })}`
            : null;
    const options = useSiteWorkRead<AssetOptions>(optionsUrl, reload, siteId);
    const loadedItems = useMemo(
        () =>
            options.status === 'ready' || options.status === 'loading'
                ? (options.data?.items ?? [])
                : [],
        [options],
    );

    const assetOptions: VehicleSelectOption[] = useMemo(() => {
        const items = loadedItems;
        // Keep the chosen asset listed while a new search runs.
        const withPicked =
            picked && !items.some((item) => item.id === picked.id)
                ? [picked, ...items]
                : items;
        return withPicked.map((item) => ({
            value: String(item.id),
            label: assetLabel(item),
            description:
                item.days_overdue !== null && item.days_overdue !== undefined
                    ? dueLine(item.due_date, item.days_overdue)
                    : item.due_date
                      ? dueLine(item.due_date, null)
                      : undefined,
        }));
    }, [loadedItems, picked]);

    const form = useForm({
        performed_at: toDateInput(new Date()),
        type: '',
        vendor: '',
        cost: '',
        notes: '',
        next_due_at: '',
    });

    const submit = () => {
        const errors = {
            site: siteId ? undefined : 'Choose the site.',
            asset: assetId ? undefined : 'Choose the asset.',
        };
        setPickError(errors);
        if (errors.site || errors.asset) return;
        form.post(`/assets/${assetId}/maintenance`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                onSaved?.();
                onClose();
            },
        });
    };

    const lockedSite = site ?? sites.find((s) => String(s.id) === siteId);
    const assetsReady = options.status === 'ready';

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="md"
            icon={Wrench}
            title="Log maintenance"
            description="Record servicing or repair work on an asset"
            context={
                asset && site
                    ? {
                          title: assetLabel(asset),
                          tag: site.name,
                          icon: Wrench,
                          note:
                              dueLine(asset.due_date, asset.days_overdue) ||
                              undefined,
                      }
                    : site
                      ? {
                            title: site.name,
                            icon: Wrench,
                            note: "The site you opened this from — can't change here.",
                        }
                      : undefined
            }
            submitLabel="Log maintenance"
            processingLabel="Saving…"
            processing={form.processing}
            onSubmit={submit}
            dirty={
                form.isDirty ||
                (!site && siteId !== '') ||
                (!asset && assetId !== '')
            }
            draftName={
                lockedSite
                    ? `maintenance for ${lockedSite.name}`
                    : 'a maintenance log'
            }
        >
            {!site ? (
                <PopupField
                    label="Site"
                    htmlFor="log-maintenance-site"
                    required
                    error={pickError.site}
                >
                    <VehicleSearchSelect
                        id="log-maintenance-site"
                        label="Site"
                        value={siteId}
                        options={siteSelectOptions(sites)}
                        invalid={!!pickError.site}
                        onChange={(value) => {
                            setSiteId(value);
                            setAssetId('');
                            setPicked(null);
                            setSearch('');
                            setPickError({});
                        }}
                    />
                </PopupField>
            ) : null}

            {!asset ? (
                <PopupField
                    label="Asset"
                    htmlFor="log-maintenance-asset"
                    required
                    error={pickError.asset}
                    hint={
                        !siteId
                            ? 'Choose the site first.'
                            : assetsReady &&
                                options.data.items.length === 0 &&
                                !query
                              ? 'No assets at this site that you can log maintenance for.'
                              : assetsReady &&
                                  options.data.total > options.data.items.length
                                ? `Showing ${options.data.items.length} of ${options.data.total}, due first. Search by name or tag for the rest.`
                                : 'Assets due for servicing are listed first.'
                    }
                >
                    {options.status === 'failed' ? (
                        <PopupCallout tone="critical">
                            This site&apos;s assets couldn&apos;t load.{' '}
                            <Button
                                type="button"
                                variant="link"
                                className="h-auto p-0"
                                onClick={() => setReload((n) => n + 1)}
                            >
                                Try again
                            </Button>
                        </PopupCallout>
                    ) : (
                        <VehicleSearchSelect
                            id="log-maintenance-asset"
                            label="Asset"
                            value={assetId}
                            options={assetOptions}
                            disabled={!siteId}
                            invalid={!!pickError.asset}
                            onSearchChange={setSearch}
                            onChange={(value) => {
                                setAssetId(value);
                                setPicked(
                                    loadedItems.find(
                                        (item) => String(item.id) === value,
                                    ) ?? picked,
                                );
                                setPickError((e) => ({
                                    ...e,
                                    asset: undefined,
                                }));
                            }}
                        />
                    )}
                </PopupField>
            ) : null}

            <PopupSection columns={2}>
                <PopupField
                    label="Date done"
                    htmlFor="log-maintenance-performed"
                    required
                    error={form.errors.performed_at}
                >
                    <DatePicker
                        id="log-maintenance-performed"
                        label="Date done"
                        value={form.data.performed_at}
                        invalid={!!form.errors.performed_at}
                        onChange={(value) =>
                            form.setData('performed_at', value)
                        }
                    />
                </PopupField>
                <PopupField
                    label="Kind of work"
                    htmlFor="log-maintenance-type"
                    error={form.errors.type}
                >
                    <Input
                        id="log-maintenance-type"
                        value={form.data.type}
                        maxLength={120}
                        placeholder="e.g. Annual service"
                        onChange={(e) => form.setData('type', e.target.value)}
                    />
                </PopupField>
                <PopupField
                    label="Done by"
                    htmlFor="log-maintenance-vendor"
                    error={form.errors.vendor}
                >
                    <Input
                        id="log-maintenance-vendor"
                        value={form.data.vendor}
                        maxLength={255}
                        placeholder="e.g. Kōwhai Electrical"
                        onChange={(e) => form.setData('vendor', e.target.value)}
                    />
                </PopupField>
                <PopupField
                    label="Cost"
                    htmlFor="log-maintenance-cost"
                    hint="In dollars, e.g. 180.00. A cost goes to Finance as a draft bill to approve."
                    error={form.errors.cost}
                >
                    <Input
                        id="log-maintenance-cost"
                        type="number"
                        inputMode="decimal"
                        min="0"
                        step="0.01"
                        value={form.data.cost}
                        aria-invalid={!!form.errors.cost}
                        onChange={(e) => form.setData('cost', e.target.value)}
                    />
                </PopupField>
                <PopupField
                    label="Notes"
                    htmlFor="log-maintenance-notes"
                    error={form.errors.notes}
                    wide
                >
                    <Textarea
                        id="log-maintenance-notes"
                        rows={3}
                        value={form.data.notes}
                        placeholder="What was done, parts replaced, anything to watch"
                        onChange={(e) => form.setData('notes', e.target.value)}
                    />
                </PopupField>
                <PopupField
                    label="Next service due"
                    htmlFor="log-maintenance-next-due"
                    hint="Leave blank if no further service is planned. The date that was due is then cleared."
                    error={form.errors.next_due_at}
                    wide
                >
                    <DatePicker
                        id="log-maintenance-next-due"
                        label="Next service due"
                        allowClear
                        value={form.data.next_due_at}
                        invalid={!!form.errors.next_due_at}
                        onChange={(value) => form.setData('next_due_at', value)}
                    />
                </PopupField>
            </PopupSection>
        </PopupForm>
    );
}
