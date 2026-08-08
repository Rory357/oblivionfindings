import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';

interface WidgetCardProps {
    title: string;
    description?: string;
    children: React.ReactNode;
    className?: string;
    href?: string;
    action?: {
        label: string;
        href: string;
    };
}

export default function WidgetCard({
    title,
    description,
    children,
    className,
    href,
    action,
}: WidgetCardProps) {
    const content = (
        <Card className={cn('h-full', className)}>
            <CardHeader className="pb-3">
                <div className="flex items-start justify-between">
                    <div>
                        <CardTitle className="text-lg">{title}</CardTitle>
                        {description && (
                            <CardDescription>{description}</CardDescription>
                        )}
                    </div>
                </div>
            </CardHeader>
            <CardContent>{children}</CardContent>
        </Card>
    );

    if (href) {
        return (
            <Link href={href} className="block h-full hover:no-underline">
                {content}
            </Link>
        );
    }

    return content;
}
