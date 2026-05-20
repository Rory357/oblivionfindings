import AppLayout from '@/layouts/app-layout';
import ClientSafetyRibbon, {
    type ClientSafety,
} from '@/components/client-safety-ribbon';
import { PageHero, PageLayout } from '@/components/page';
import { Head, usePage } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { TabsContent, TabsList, TabsRoot, TabsTrigger } from '@/components/ui/tabs';
import { Stethoscope } from 'lucide-react';

export default function MedicalSimple() {
    const { client, medications, conditions, emergency_contacts, profile, safety } = usePage<any>().props;

    return (
        <AppLayout breadcrumbs={[{ title: 'Clients', href: '/clients' }, { title: `${client?.first_name} ${client?.last_name}`, href: `/clients/${client?.id}` }, { title: 'Medical', href: `/clients/${client?.id}/medical` }]}>
            <Head title={`Medical - ${client?.first_name} ${client?.last_name}`} />

            <PageLayout
                hero={
                    <PageHero
                        icon={Stethoscope}
                        title="Medical Profile (Debug)"
                        description={`${client?.first_name} ${client?.last_name}`}
                        stats={[
                            { label: 'Medications', value: medications?.length || 0 },
                            { label: 'Conditions', value: conditions?.length || 0 },
                            { label: 'Contacts', value: emergency_contacts?.length || 0 },
                        ]}
                    />
                }
            >
                <ClientSafetyRibbon safety={safety as ClientSafety | null | undefined} />

                <TabsRoot defaultValue="overview" className="space-y-4">
                    <TabsList>
                        <TabsTrigger value="overview">Overview</TabsTrigger>
                        <TabsTrigger value="medications">Medications ({medications?.length || 0})</TabsTrigger>
                        <TabsTrigger value="profile">Profile</TabsTrigger>
                    </TabsList>

                    <TabsContent value="overview">
                        <Card>
                            <CardHeader>
                                <CardTitle>Overview</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p>Medications: {medications?.length || 0}</p>
                                <p>Conditions: {conditions?.length || 0}</p>
                                <p>Contacts: {emergency_contacts?.length || 0}</p>
                                <p>Profile: {profile ? 'Yes' : 'No'}</p>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    <TabsContent value="medications">
                        <Card>
                            <CardHeader>
                                <CardTitle>Medications</CardTitle>
                            </CardHeader>
                            <CardContent>
                                {medications?.map((m: any) => (
                                    <div key={m.id} className="border p-2 mb-2 rounded">
                                        {m.name} - {m.state}
                                    </div>
                                ))}
                            </CardContent>
                        </Card>
                    </TabsContent>

                    <TabsContent value="profile">
                        <Card>
                            <CardHeader>
                                <CardTitle>Profile</CardTitle>
                            </CardHeader>
                            <CardContent>
                                {profile?.allergies && (
                                    <div className="mb-4">
                                        <h4 className="font-medium">Allergies</h4>
                                        <p className="text-sm text-muted-foreground">{profile.allergies}</p>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>
                </TabsRoot>
            </PageLayout>
        </AppLayout>
    );
}
