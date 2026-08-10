import { PageHero, PageLayout } from '@/components/page';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useInitials } from '@/hooks/use-initials';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, usePage } from '@inertiajs/react';
import { Heart, Users } from 'lucide-react';

type PortalClient = {
    id: number;
    name: string;
    relation?: string | null;
    avatar?: string | null;
    status?: string | null;
};

type Props = {
    clients: PortalClient[];
};

export default function PortalIndex({ clients }: Props) {
    const { auth } = usePage<{ auth: { user: { name: string } } }>().props;
    const getInitials = useInitials();

    const today = new Date().toLocaleDateString('en-NZ', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });

    return (
        <AppLayout breadcrumbs={[{ title: 'Portal', href: '/portal' }]}>
            <Head title="Portal" />

            <PageLayout
                hero={
                    <PageHero
                        icon={Heart}
                        title={`Welcome back, ${auth.user.name}`}
                        description={today}
                        stats={[
                            { label: 'Linked clients', value: clients.length },
                        ]}
                    />
                }
            >
                {/* Client grid or empty state */}
                {clients.length === 0 ? (
                    <Card className="mx-auto max-w-md text-center">
                        <CardHeader>
                            <div className="mx-auto mb-2 flex h-12 w-12 items-center justify-center rounded-full bg-muted">
                                <Users className="h-6 w-6 text-muted-foreground" />
                            </div>
                            <CardTitle className="text-base">
                                No linked clients
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm text-muted-foreground">
                                Ask your provider to link your account.
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                        {clients.map((c) => (
                            <Card
                                key={c.id}
                                className="transition-all hover:border-primary/50 hover:shadow-md"
                            >
                                <CardHeader className="flex flex-row items-center gap-4 space-y-0">
                                    <Avatar className="h-12 w-12">
                                        {c.avatar && (
                                            <AvatarImage
                                                src={c.avatar}
                                                alt={c.name}
                                            />
                                        )}
                                        <AvatarFallback className="bg-primary/10 text-primary">
                                            {getInitials(c.name)}
                                        </AvatarFallback>
                                    </Avatar>
                                    <div className="min-w-0 flex-1">
                                        <CardTitle className="truncate text-base">
                                            {c.name}
                                        </CardTitle>
                                        <div className="mt-1 flex flex-wrap items-center gap-1.5">
                                            {c.relation && (
                                                <Badge
                                                    variant="outline"
                                                    className="capitalize"
                                                >
                                                    {c.relation}
                                                </Badge>
                                            )}
                                            {c.status?.toLowerCase() ===
                                                'active' && (
                                                <Badge className="bg-status-success-bg text-status-success hover:bg-status-success-bg dark:bg-status-success-bg dark:text-status-success">
                                                    Active
                                                </Badge>
                                            )}
                                            {c.status &&
                                                c.status.toLowerCase() !==
                                                    'active' && (
                                                    <Badge
                                                        variant="secondary"
                                                        className="capitalize"
                                                    >
                                                        {c.status}
                                                    </Badge>
                                                )}
                                        </div>
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    <Button asChild className="w-full">
                                        <Link
                                            href={`/portal/clients/${c.id}/dashboard`}
                                        >
                                            View Dashboard
                                        </Link>
                                    </Button>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}
            </PageLayout>
        </AppLayout>
    );
}
