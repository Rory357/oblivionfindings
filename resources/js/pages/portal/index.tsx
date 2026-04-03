import AppLayout from '@/layouts/app-layout';
import { Head, Link, usePage } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { useInitials } from '@/hooks/use-initials';
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

            <div className="space-y-6">
                {/* Hero header */}
                <div className="rounded-xl bg-gradient-to-br from-primary/10 via-primary/5 to-transparent p-6 md:p-8">
                    <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-full bg-primary/10">
                            <Heart className="h-5 w-5 text-primary" />
                        </div>
                        <div>
                            <h1 className="text-2xl font-semibold tracking-tight md:text-3xl">
                                Welcome back, {auth.user.name}
                            </h1>
                            <p className="text-sm text-muted-foreground">{today}</p>
                        </div>
                    </div>
                </div>

                {/* Client grid or empty state */}
                {clients.length === 0 ? (
                    <Card className="mx-auto max-w-md text-center">
                        <CardHeader>
                            <div className="mx-auto mb-2 flex h-12 w-12 items-center justify-center rounded-full bg-muted">
                                <Users className="h-6 w-6 text-muted-foreground" />
                            </div>
                            <CardTitle className="text-base">No linked clients</CardTitle>
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
                                        {c.avatar && <AvatarImage src={c.avatar} alt={c.name} />}
                                        <AvatarFallback className="bg-primary/10 text-primary">
                                            {getInitials(c.name)}
                                        </AvatarFallback>
                                    </Avatar>
                                    <div className="min-w-0 flex-1">
                                        <CardTitle className="truncate text-base">{c.name}</CardTitle>
                                        <div className="mt-1 flex flex-wrap items-center gap-1.5">
                                            {c.relation && (
                                                <Badge variant="outline" className="capitalize">
                                                    {c.relation}
                                                </Badge>
                                            )}
                                            {c.status?.toLowerCase() === 'active' && (
                                                <Badge className="bg-emerald-100 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-900/30 dark:text-emerald-400">
                                                    Active
                                                </Badge>
                                            )}
                                            {c.status && c.status.toLowerCase() !== 'active' && (
                                                <Badge variant="secondary" className="capitalize">
                                                    {c.status}
                                                </Badge>
                                            )}
                                        </div>
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    <Button asChild className="w-full">
                                        <Link href={`/portal/clients/${c.id}/dashboard`}>
                                            View Dashboard
                                        </Link>
                                    </Button>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
