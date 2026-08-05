import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Link } from '@inertiajs/react';
import { ArrowUpRight, Plus } from 'lucide-react';
import { formatRegisterDate, registerLabel } from './safety-register';
import { SiteProfileLockedState } from './site-profile-states';

type SiteAssetRow = {
    id: number;
    name: string;
    asset_tag?: string | null;
    category?: string | null;
    status?: string | null;
    risk_level?: string | null;
    location?: string | null;
    owner: { type: 'client' | 'site'; id: number; label: string };
    inspection_due_at?: string | null;
    maintenance_due_at?: string | null;
    updated_at?: string | null;
    href: string;
};

export type SiteAssetsData =
    | {
          locked: true;
          items: never[];
          can_create: false;
          href: null;
      }
    | {
          locked?: false;
          items: SiteAssetRow[];
          can_create: boolean;
          href: string;
      };

export function SiteProfileAssets({ data }: { data: SiteAssetsData }) {
    if (data.locked) {
        return <SiteProfileLockedState label="Site assets" />;
    }

    return (
        <div className="space-y-5">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 className="text-lg font-semibold">Site assets</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Complete Site-linked inventory with owner, assignment,
                        condition, location and servicing attention.
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    {data.can_create ? (
                        <Button asChild size="sm" className="min-h-11">
                            <Link href={`${data.href}&new=1`}>
                                <Plus className="mr-1.5 h-4 w-4" /> Add asset
                            </Link>
                        </Button>
                    ) : null}
                    <Button
                        asChild
                        size="sm"
                        variant="outline"
                        className="min-h-11"
                    >
                        <Link href={data.href}>
                            Open Assets
                            <ArrowUpRight className="ml-1.5 h-4 w-4" />
                        </Link>
                    </Button>
                </div>
            </div>
            <Card>
                <CardHeader>
                    <CardTitle className="text-base">
                        Inventory ({data.items.length})
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    {data.items.length ? (
                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full min-w-[920px] text-sm">
                                <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                    <tr>
                                        <th className="px-4 py-3 font-medium">
                                            Asset
                                        </th>
                                        <th className="px-4 py-3 font-medium">
                                            Owner
                                        </th>
                                        <th className="px-4 py-3 font-medium">
                                            Status
                                        </th>
                                        <th className="px-4 py-3 font-medium">
                                            Risk
                                        </th>
                                        <th className="px-4 py-3 font-medium">
                                            Inspection
                                        </th>
                                        <th className="px-4 py-3 font-medium">
                                            Maintenance
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {data.items.map((asset) => (
                                        <tr
                                            key={asset.id}
                                            className="hover:bg-muted/40"
                                        >
                                            <td className="px-4 py-3">
                                                <Link
                                                    href={asset.href}
                                                    className="font-medium text-primary hover:underline"
                                                >
                                                    {asset.name}
                                                </Link>
                                                <div className="mt-1 text-xs text-muted-foreground">
                                                    {[
                                                        asset.asset_tag,
                                                        asset.category,
                                                        asset.location,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · ') ||
                                                        'No asset detail recorded'}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <Badge variant="outline">
                                                    {asset.owner.type ===
                                                    'client'
                                                        ? `Client: ${asset.owner.label}`
                                                        : 'Site-owned'}
                                                </Badge>
                                            </td>
                                            <td className="px-4 py-3">
                                                {registerLabel(asset.status)}
                                            </td>
                                            <td className="px-4 py-3">
                                                {registerLabel(
                                                    asset.risk_level,
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {formatRegisterDate(
                                                    asset.inspection_due_at,
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {formatRegisterDate(
                                                    asset.maintenance_due_at,
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <div className="rounded-xl border border-dashed px-4 py-10 text-center text-sm text-muted-foreground">
                            No assets are linked to this Site yet.
                        </div>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}
