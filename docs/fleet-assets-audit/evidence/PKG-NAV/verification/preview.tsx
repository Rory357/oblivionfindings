import React from 'react';
import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { Card, CardContent } from '@/components/ui/card';
import { PageHeader } from '@/components/page/page-header';
import { fleetWorkspaceForUrl } from '@/lib/fleet-navigation';
import { usePage } from '@inertiajs/react';
import '../../resources/css/app.css';

function NavigationFixture() {
    const page = usePage();
    const match = fleetWorkspaceForUrl(page.url);
    return <AppSidebarLayout breadcrumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Fleet & Assets', href: '/fleet-assets' }, { title: match?.item.label || 'Fixture route', href: page.url }]}>
        <PageHeader title={match?.item.label || 'Fixture route'} subline="Synthetic navigation fixture · actual application shell and navigation" />
        <Card className="mt-5"><CardContent className="p-5">
            <p>PKG-NAV verification — worktree 475b</p>
            <p>Canonical route: <code>{page.url}</code></p>
            <p>Page content is synthetic. No operational data or backend workflow is loaded.</p>
        </CardContent></Card>
    </AppSidebarLayout>;
}
createInertiaApp({ page: window.__NAV_PAGE__, resolve: () => NavigationFixture, setup({ el, App, props }) { createRoot(el).render(<App {...props} />); } });


