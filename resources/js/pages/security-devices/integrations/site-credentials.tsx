import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

export type SiteCredentialRow = {
    id: number;
    site_id: number;
    site_name: string;
    capability: string;
    enabled: boolean;
    state: 'connected' | 'untested' | 'disabled' | 'error';
    failure_category: string | null;
    last_tested_at: string | null;
};

export function SiteCredentialsCard({ rows }: { rows: SiteCredentialRow[] }) {
    if (rows.length === 0) return null;

    return (
        <Card>
            <CardHeader>
                <CardTitle>Site credentials</CardTitle>
                <CardDescription>
                    Safe per-site credential status. Secret values and provider
                    error text are never displayed.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-3">
                {rows.map((row) => (
                    <div
                        key={row.id}
                        className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3 text-sm"
                    >
                        <div>
                            <p className="font-medium">{row.site_name}</p>
                            <p className="text-muted-foreground">
                                {row.capability}
                            </p>
                        </div>
                        <div className="text-right">
                            <Badge
                                variant={
                                    row.state === 'error'
                                        ? 'destructive'
                                        : 'outline'
                                }
                            >
                                {row.state === 'error'
                                    ? 'Needs attention'
                                    : row.state === 'connected'
                                      ? 'Connected'
                                      : row.state === 'untested'
                                        ? 'Not tested'
                                        : 'Disabled'}
                            </Badge>
                            {row.failure_category && (
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {row.failure_category.replaceAll('_', ' ')}
                                </p>
                            )}
                        </div>
                    </div>
                ))}
            </CardContent>
        </Card>
    );
}
