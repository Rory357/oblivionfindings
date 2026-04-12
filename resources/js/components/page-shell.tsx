import { ReactNode } from 'react';

type Props = {
    children: ReactNode;
    title?: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
};

export default function PageShell({ children, title, description, actions }: Props) {
    return (
        <div className="w-full space-y-8">
            {(title || description || actions) && (
                <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                    <div className="min-w-0">
                        {title ? (
                            <h1 className="text-xl font-semibold tracking-tight md:text-2xl">
                                {title}
                            </h1>
                        ) : null}
                        {description ? (
                            <p className="mt-2 max-w-2xl text-sm text-muted-foreground">
                                {description}
                            </p>
                        ) : null}
                    </div>
                    {actions ? <div className="flex shrink-0 items-center gap-2">{actions}</div> : null}
                </div>
            )}
            {children}
        </div>
    );
}
