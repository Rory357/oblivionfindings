import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { ReactNode } from 'react';

type Props = {
    title: ReactNode;
    description?: string;
    backHref?: string;
    backLabel?: string;
    actions?: ReactNode;
    children?: ReactNode;
};

export default function PageHeader({
    title,
    description,
    backHref,
    backLabel = 'Back',
    actions,
    children,
}: Props) {
    return (
        <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
            <div className="min-w-0">
                {backHref ? (
                    <Link
                        href={backHref}
                        className="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="h-4 w-4" />
                        {backLabel}
                    </Link>
                ) : null}

                <h1 className="mt-1 text-xl md:text-2xl font-semibold tracking-tight">
                    {title}
                </h1>
                {description ? (
                    <p className="mt-2 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                        {description}
                    </p>
                ) : null}
            </div>

            {(actions || children) ? (
                <div className="flex shrink-0 flex-wrap items-center gap-2">
                    {actions}
                    {children}
                </div>
            ) : null}
        </div>
    );
}
