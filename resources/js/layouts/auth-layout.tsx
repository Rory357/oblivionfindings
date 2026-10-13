import AuthJourneyLayout from '@/layouts/auth/auth-journey-layout';

/**
 * Generic auth page wrapper (register and any page without its own design):
 * the approved Journey half-and-half layout with a plain title block.
 */
export default function AuthLayout({
    children,
    title,
    description,
}: {
    children: React.ReactNode;
    title: string;
    description: string;
}) {
    return (
        <AuthJourneyLayout title={title}>
            <div className="flex flex-col gap-1.5">
                <h1 className="journey-title">{title}</h1>
                <p className="journey-subtitle">{description}</p>
            </div>
            {children}
        </AuthJourneyLayout>
    );
}
