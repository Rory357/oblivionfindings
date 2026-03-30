import { Badge } from '@/components/ui/badge';
import { Link } from '@inertiajs/react';
import { CheckCircle2, ChevronRight } from 'lucide-react';
import type { ComponentType } from 'react';

type ActionItem = {
    icon: ComponentType<{ className?: string }>;
    label: string;
    count: number;
    href: string;
    variant?: 'warning' | 'info' | 'default';
};

type ActionItemsProps = {
    items: ActionItem[];
};

export function ActionItems({ items }: ActionItemsProps) {
    return (
        <div className="divide-y">
            {items.map((item) => {
                const Icon = item.icon;
                const done = item.count === 0;

                return (
                    <Link
                        key={item.label}
                        href={item.href}
                        className="flex items-center gap-3 px-1 py-3 transition-colors hover:bg-muted/50 rounded-lg"
                    >
                        {done ? (
                            <CheckCircle2 className="h-4 w-4 shrink-0 text-emerald-500" />
                        ) : (
                            <Icon className="h-4 w-4 shrink-0 text-muted-foreground" />
                        )}

                        <span
                            className={`flex-1 text-sm ${done ? 'text-muted-foreground line-through' : 'font-medium'}`}
                        >
                            {item.label}
                        </span>

                        {!done && (
                            <Badge
                                variant={item.variant === 'warning' ? 'destructive' : 'secondary'}
                                className="min-w-[24px] justify-center"
                            >
                                {item.count}
                            </Badge>
                        )}

                        <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground/50" />
                    </Link>
                );
            })}
        </div>
    );
}
