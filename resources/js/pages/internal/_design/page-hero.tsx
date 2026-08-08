import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Building2,
    Car,
    ClipboardCheck,
    Cog,
    Download,
    Eye,
    Heart,
    Home,
    LayoutGrid,
    MapPin,
    Pencil,
    Plus,
    ShieldAlert,
    Truck,
    Users,
} from 'lucide-react';
import { useState } from 'react';

import {
    PageHero,
    PageLayout,
    PageTabs,
    type PageHeroCategory,
    type PageTabItem,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { TabsContent } from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';

const CATEGORIES: { value: PageHeroCategory; label: string }[] = [
    { value: 'ops', label: 'Operations' },
    { value: 'hr', label: 'HR' },
    { value: 'compliance', label: 'Compliance' },
    { value: 'incidents', label: 'Incidents' },
    { value: 'governance', label: 'Governance' },
    { value: 'sites', label: 'Sites' },
    { value: 'fleet', label: 'Fleet' },
];

const SHOWCASE_TABS: PageTabItem[] = [
    { value: 'hero', label: 'Hero variant', icon: LayoutGrid },
    { value: 'compact', label: 'Compact variant', icon: Pencil },
    { value: 'inline', label: 'Inline variant', icon: ClipboardCheck },
    { value: 'categories', label: 'Categories', icon: Cog },
    {
        value: 'overflow-a',
        label: 'Tab A',
        icon: Eye,
        overflowable: true,
        badge: (
            <Badge variant="outline" className="ml-1 px-1.5 py-0 text-xs">
                3
            </Badge>
        ),
    },
    { value: 'overflow-b', label: 'Tab B', icon: Heart, overflowable: true },
    { value: 'overflow-c', label: 'Tab C', icon: Truck, overflowable: true },
];

export default function PageHeroShowcase() {
    const [tab, setTab] = useState('hero');

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Internal', href: '/internal/_design/page-hero' },
                {
                    title: 'PageHero showcase',
                    href: '/internal/_design/page-hero',
                },
            ]}
        >
            <Head title="PageHero showcase" />

            <PageLayout
                hero={
                    <PageHero
                        icon={LayoutGrid}
                        title="PageHero showcase"
                        description="Reference implementation of every variant, tone, and category. Use this page to verify theme + dark-mode coverage."
                        meta={[
                            {
                                icon: MapPin,
                                label: 'resources/js/components/page',
                            },
                            { icon: ShieldAlert, label: 'Admin only' },
                        ]}
                        badges={[
                            { label: 'Variant: hero', tone: 'default' },
                            {
                                icon: AlertTriangle,
                                label: 'Demo',
                                tone: 'warning',
                            },
                            { label: 'Active', tone: 'success' },
                            { label: 'Critical example', tone: 'critical' },
                            { label: 'Info chip', tone: 'info' },
                        ]}
                        stats={[
                            { label: 'Variants', value: 3 },
                            { label: 'Tones', value: 5 },
                            { label: 'Categories', value: CATEGORIES.length },
                        ]}
                        actions={
                            <>
                                <Button size="sm" variant="outline">
                                    <Download className="mr-1.5 h-4 w-4" />
                                    Export
                                </Button>
                                <Button size="sm">
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    Primary action
                                </Button>
                            </>
                        }
                    />
                }
                tabs={
                    <PageTabs
                        value={tab}
                        onValueChange={setTab}
                        items={SHOWCASE_TABS}
                    >
                        <TabsContent value="hero" className="space-y-6">
                            <Section title="Hero — Site Detail reference (icon + meta + badges + stats + actions)">
                                <PageHero
                                    icon={Building2}
                                    title="123 Example Street House"
                                    meta={[
                                        {
                                            icon: MapPin,
                                            label: '123 Example St, Wellington 6011',
                                        },
                                    ]}
                                    badges={[
                                        {
                                            icon: Home,
                                            label: 'House',
                                            tone: 'default',
                                        },
                                        {
                                            icon: AlertTriangle,
                                            label: 'High Risk',
                                            tone: 'warning',
                                        },
                                        { label: 'Active', tone: 'success' },
                                        {
                                            label: 'Wellington',
                                            tone: 'default',
                                        },
                                    ]}
                                    stats={[
                                        { label: 'Clients', value: 6 },
                                        { label: 'Assets', value: 12 },
                                        { label: 'Contacts', value: 3 },
                                    ]}
                                    actions={
                                        <Button size="sm" variant="outline">
                                            <Pencil className="mr-1.5 h-4 w-4" />
                                            Edit
                                        </Button>
                                    }
                                />
                            </Section>

                            <Section title="Hero — avatar (person detail)">
                                <PageHero
                                    avatar={{ fallback: 'JD' }}
                                    title="Jane Doe"
                                    description="Senior Support Worker · Started 2024-03-15"
                                    meta={[
                                        {
                                            icon: MapPin,
                                            label: 'Wellington · NZST',
                                        },
                                    ]}
                                    badges={[
                                        { label: 'Active', tone: 'success' },
                                        {
                                            icon: ShieldAlert,
                                            label: 'On-call',
                                            tone: 'warning',
                                        },
                                    ]}
                                    stats={[
                                        { label: 'Shifts (30d)', value: 24 },
                                        { label: 'Hours', value: 156 },
                                    ]}
                                    actions={
                                        <Button size="sm" variant="outline">
                                            <Pencil className="mr-1.5 h-4 w-4" />
                                            Edit profile
                                        </Button>
                                    }
                                />
                            </Section>

                            <Section title="Hero — Index page (stats prominent)">
                                <PageHero
                                    icon={Users}
                                    title="Clients"
                                    description="Manage active and archived clients across all sites"
                                    stats={[
                                        { label: 'Total', value: 124 },
                                        { label: 'Active', value: 96 },
                                        { label: 'Open risks', value: 8 },
                                    ]}
                                    actions={
                                        <Button size="sm">
                                            <Plus className="mr-1.5 h-4 w-4" />
                                            Add client
                                        </Button>
                                    }
                                />
                            </Section>
                        </TabsContent>

                        <TabsContent value="compact" className="space-y-6">
                            <Section title="Compact — form pages">
                                <PageHero
                                    variant="compact"
                                    backHref="/sites"
                                    backLabel="Back to Sites"
                                    title="Create Site"
                                    description="Provide basic information about the site. You can configure rooms, assets, and checklists after creation."
                                    actions={
                                        <>
                                            <Button variant="outline" size="sm">
                                                Cancel
                                            </Button>
                                            <Button size="sm">Save</Button>
                                        </>
                                    }
                                />
                            </Section>
                        </TabsContent>

                        <TabsContent value="inline" className="space-y-6">
                            <Section title="Inline — deeply nested pages">
                                <PageHero
                                    variant="inline"
                                    title="Edit room 3B"
                                    description="Wing 2 · Capacity 1"
                                    actions={
                                        <Button size="sm" variant="outline">
                                            Save
                                        </Button>
                                    }
                                />
                            </Section>
                        </TabsContent>

                        <TabsContent value="categories" className="space-y-6">
                            {CATEGORIES.map((cat) => (
                                <Section
                                    key={cat.value}
                                    title={`Category: ${cat.label} (--category-${cat.value})`}
                                >
                                    <PageHero
                                        category={cat.value}
                                        icon={
                                            cat.value === 'fleet'
                                                ? Car
                                                : LayoutGrid
                                        }
                                        title={`${cat.label} dashboard`}
                                        description={`Hero gradient driven by var(--category-${cat.value}) instead of --primary.`}
                                        stats={[
                                            { label: 'Metric A', value: 42 },
                                            { label: 'Metric B', value: 17 },
                                            { label: 'Metric C', value: 5 },
                                        ]}
                                        actions={
                                            <Button size="sm" variant="outline">
                                                Configure
                                            </Button>
                                        }
                                    />
                                </Section>
                            ))}
                        </TabsContent>

                        <TabsContent value="overflow-a">
                            <DemoCard title="Overflow tab A">
                                This tab is marked{' '}
                                <code>overflowable: true</code> and appears
                                under the More dropdown below the 2xl
                                breakpoint.
                            </DemoCard>
                        </TabsContent>
                        <TabsContent value="overflow-b">
                            <DemoCard title="Overflow tab B" />
                        </TabsContent>
                        <TabsContent value="overflow-c">
                            <DemoCard title="Overflow tab C" />
                        </TabsContent>
                    </PageTabs>
                }
            />
        </AppLayout>
    );
}

function Section({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <div className="space-y-2">
            <p className="text-xs font-medium tracking-wider text-muted-foreground uppercase">
                {title}
            </p>
            {children}
        </div>
    );
}

function DemoCard({
    title,
    children,
}: {
    title: string;
    children?: React.ReactNode;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>{title}</CardTitle>
            </CardHeader>
            <CardContent className="text-sm text-muted-foreground">
                {children ?? 'Tab content goes here.'}
                <div className="mt-3">
                    <Link
                        href="/internal/_design/page-hero"
                        className="text-primary hover:underline"
                    >
                        Reload showcase
                    </Link>
                </div>
            </CardContent>
        </Card>
    );
}
