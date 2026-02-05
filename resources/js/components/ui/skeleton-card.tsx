import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

interface SkeletonCardProps {
    className?: string;
    header?: boolean;
    rows?: number;
}

export function SkeletonCard({
    className,
    header = true,
    rows = 3,
}: SkeletonCardProps) {
    return (
        <Card className={cn(className)}>
            {header && (
                <CardHeader className="space-y-2">
                    <Skeleton className="h-5 w-1/3" />
                    <Skeleton className="h-4 w-1/2" />
                </CardHeader>
            )}
            <CardContent className="space-y-3">
                {Array.from({ length: rows }).map((_, i) => (
                    <Skeleton key={i} className="h-4 w-full" />
                ))}
            </CardContent>
        </Card>
    );
}

interface SkeletonTableProps {
    className?: string;
    rows?: number;
    columns?: number;
}

export function SkeletonTable({
    className,
    rows = 5,
    columns = 4,
}: SkeletonTableProps) {
    return (
        <div className={cn('rounded-md border', className)}>
            <div className="bg-muted/40 p-3">
                <div className="flex gap-4">
                    {Array.from({ length: columns }).map((_, i) => (
                        <Skeleton
                            key={i}
                            className="h-4 flex-1"
                            style={{ width: `${Math.random() * 20 + 15}%` }}
                        />
                    ))}
                </div>
            </div>
            <div className="divide-y">
                {Array.from({ length: rows }).map((_, rowIndex) => (
                    <div key={rowIndex} className="flex gap-4 p-3">
                        {Array.from({ length: columns }).map((_, colIndex) => (
                            <Skeleton
                                key={colIndex}
                                className="h-4 flex-1"
                                style={{ width: `${Math.random() * 30 + 10}%` }}
                            />
                        ))}
                    </div>
                ))}
            </div>
        </div>
    );
}

interface SkeletonStatsProps {
    className?: string;
    count?: number;
}

export function SkeletonStats({ className, count = 3 }: SkeletonStatsProps) {
    return (
        <div className={cn('grid gap-4 md:grid-cols-3', className)}>
            {Array.from({ length: count }).map((_, i) => (
                <Card key={i} className="p-4">
                    <Skeleton className="h-4 w-24" />
                    <Skeleton className="mt-2 h-8 w-16" />
                    <Skeleton className="mt-1 h-3 w-32" />
                </Card>
            ))}
        </div>
    );
}

export { Skeleton };
