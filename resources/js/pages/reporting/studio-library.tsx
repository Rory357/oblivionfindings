import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    ArrowRight,
    BarChart3,
    Coins,
    Database,
    FilePlus2,
    Package,
    Route,
    Search,
    ShieldCheck,
    Upload,
    Wrench,
} from 'lucide-react';
import { useState } from 'react';
import type { Source, Template } from './model';
export const fleetViews = [
    {
        key: 'demand',
        source: 'demand',
        label: 'Demand & delivery',
        title: 'Demand & delivery',
        note: 'Request outcomes and unmet needs.',
        icon: Route,
    },
    {
        key: 'readiness',
        source: 'maintenance',
        label: 'Readiness',
        title: 'Disruption & readiness',
        note: 'Waiting work and upcoming obligations.',
        icon: Wrench,
    },
    {
        key: 'use',
        source: 'journeys',
        label: 'Use & custody',
        title: 'Use & custody',
        note: 'Journeys, borrowing and inventory.',
        icon: Package,
    },
    {
        key: 'costs',
        source: 'resource_costs',
        label: 'Costs',
        title: 'Costs & replacement',
        note: 'Scoped Finance amounts and evidence.',
        icon: Coins,
    },
];
export function StudioLibrary({
    domain,
    sources,
    templates,
    onStart,
    onCreate,
    onImport,
}: {
    domain: string;
    sources: Record<string, Source>;
    templates: Template[];
    onStart: (source: string, name?: string) => void;
    onCreate: () => void;
    onImport: () => void;
}) {
    const [query, setQuery] = useState('');
    const views =
        domain === 'fleet' ? fleetViews.filter((v) => sources[v.source]) : [];
    return (
        <>
            <Card className="report-library-intro">
                <div>
                    <p className="text-caption font-semibold tracking-widest uppercase">
                        {domain === 'fleet'
                            ? 'Fleet report studio'
                            : domain === 'medication'
                              ? 'Medication reports'
                              : 'Personal tracker reports'}
                    </p>
                    <h2 className="text-page-title">
                        {domain === 'fleet'
                            ? 'Build a clearer picture of your fleet.'
                            : 'Understand the records behind each report.'}
                    </h2>
                    <p className="text-subtle">
                        Start with a trusted report or shape your own. Choose
                        the data, ask a precise question, and keep the evidence
                        behind every result.
                    </p>
                    <div className="flex flex-wrap gap-2">
                        <Button onClick={onCreate}>
                            <FilePlus2 className="size-4" />
                            Create a report
                        </Button>
                        <Button variant="outline" onClick={onImport}>
                            <Upload className="size-4" />
                            Import design
                        </Button>
                    </div>
                </div>
                <div className="report-library-illustration">
                    <div className="flex items-center justify-between gap-3">
                        <Database className="size-4" />
                        <strong className="text-sm">
                            From records to answers
                        </strong>
                    </div>
                    {[
                        'Choose authorised records',
                        'Shape your measures',
                        'Keep the source evidence',
                    ].map((label, i) => (
                        <div className="report-intro-row" key={label}>
                            <span className="text-caption">0{i + 1}</span>
                            <span className="text-sm">{label}</span>
                        </div>
                    ))}
                    <p className="text-caption flex items-center gap-2 border-t pt-3">
                        <ShieldCheck className="size-4" />
                        Every figure has a source
                    </p>
                </div>
            </Card>
            {views.length > 0 && (
                <>
                    <div>
                        <h2 className="text-section-title">
                            Ready-made reports
                        </h2>
                        <p className="text-subtle">
                            Focused reports available to you
                        </p>
                    </div>
                    <div className="report-ready-grid">
                        {views.map((v) => (
                            <Card key={v.key} className="report-ready-card">
                                <v.icon className="report-category-icon" />
                                <h3 className="text-section-title">
                                    {v.title}
                                </h3>
                                <p className="text-subtle">{v.note}</p>
                                <Button
                                    variant="ghost"
                                    className="justify-between"
                                    onClick={() => onStart(v.source, v.title)}
                                >
                                    Open report
                                    <ArrowRight className="size-4" />
                                </Button>
                            </Card>
                        ))}
                    </div>
                </>
            )}
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-section-title">
                        Start from a template
                    </h2>
                    <p className="text-subtle">
                        {templates.length} templates · Each source retains its
                        permissions and definitions
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    <Search className="size-4" />
                    <Input
                        aria-label="Search report templates"
                        placeholder="Find a template…"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                    />
                </div>
            </div>
            <div className="report-template-grid">
                {templates
                    .filter((t) =>
                        (t.name + ' ' + sources[t.source].label)
                            .toLowerCase()
                            .includes(query.toLowerCase()),
                    )
                    .map((t) => (
                        <Card key={t.id} className="report-template-card">
                            <BarChart3 className="size-5 text-primary" />
                            <div>
                                <h3 className="text-section-title">{t.name}</h3>
                                <p className="text-caption text-muted-foreground">
                                    {sources[t.source].label}
                                </p>
                            </div>
                            <Button
                                variant="ghost"
                                aria-label={'Use template: ' + t.name}
                                onClick={() => onStart(t.source, t.name)}
                            >
                                <ArrowRight className="size-4" />
                            </Button>
                        </Card>
                    ))}
            </div>
        </>
    );
}
