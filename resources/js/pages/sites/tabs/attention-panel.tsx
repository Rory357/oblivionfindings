import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    BellRing,
    CircleAlert,
    ExternalLink,
} from 'lucide-react';
import type { SiteProfileAttentionData } from '../show';

export function SiteAttentionPanel({
    attention,
}: {
    attention: SiteProfileAttentionData;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                    <BellRing className="h-4 w-4" /> Needs attention
                    <Badge variant="outline">{attention.summary.total}</Badge>
                </CardTitle>
            </CardHeader>
            <CardContent>
                {attention.items.length ? (
                    <div className="divide-y">
                        {attention.items.map((item) => (
                            <Link
                                key={item.id}
                                href={item.href}
                                className="flex min-h-11 items-start gap-3 py-3 hover:text-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                {item.severity === 'critical' ? (
                                    <AlertTriangle
                                        aria-label="Critical"
                                        className="mt-0.5 h-4 w-4 shrink-0 text-status-critical"
                                    />
                                ) : (
                                    <CircleAlert
                                        aria-label="Warning"
                                        className="mt-0.5 h-4 w-4 shrink-0 text-status-warning"
                                    />
                                )}
                                <span className="min-w-0 flex-1">
                                    <span className="block text-sm font-medium">
                                        {item.title}
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {item.detail}
                                    </span>
                                </span>
                                <ExternalLink className="mt-0.5 h-4 w-4 shrink-0" />
                            </Link>
                        ))}
                    </div>
                ) : (
                    <div className="py-8 text-center">
                        <BellRing className="mx-auto h-6 w-6 text-status-success" />
                        <p className="mt-2 text-sm font-medium">
                            Nothing needs attention
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            No overdue or critical Site work was found.
                        </p>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
