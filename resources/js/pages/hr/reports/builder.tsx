import { ReportsTabs } from '@/components/hr';
import { PageHero, PageLayout } from '@/components/page';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    BarChart3,
    Eye,
    Plus,
    Save,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';

type ReportSource = {
    label: string;
    fields: string[];
};

type Filter = {
    field: string;
    operator: string;
    value: string;
};

type Props = {
    sources: Record<string, ReportSource>;
};

const breadcrumbs = [
    { title: 'HR', href: '/hr' },
    { title: 'Reports', href: '/hr/reports' },
    { title: 'Report Builder', href: '/hr/reports/builder' },
];

const operators = [
    { value: '=', label: 'Equals' },
    { value: '!=', label: 'Not Equals' },
    { value: 'contains', label: 'Contains' },
    { value: '>', label: 'Greater Than' },
    { value: '<', label: 'Less Than' },
    { value: '>=', label: 'Greater or Equal' },
    { value: '<=', label: 'Less or Equal' },
    { value: 'starts_with', label: 'Starts With' },
    { value: 'is_null', label: 'Is Empty' },
    { value: 'is_not_null', label: 'Is Not Empty' },
];

export default function ReportBuilder({ sources }: Props) {
    const [reportType, setReportType] = useState<string>('');
    const [selectedFields, setSelectedFields] = useState<string[]>([]);
    const [filters, setFilters] = useState<Filter[]>([]);
    const [sortBy, setSortBy] = useState('');
    const [sortDirection, setSortDirection] = useState('asc');
    const [previewData, setPreviewData] = useState<
        Record<string, string>[] | null
    >(null);
    const [previewTotal, setPreviewTotal] = useState(0);
    const [loading, setLoading] = useState(false);
    const [previewError, setPreviewError] = useState<string | null>(null);
    const [reportName, setReportName] = useState('');
    const [reportDescription, setReportDescription] = useState('');
    const [showSave, setShowSave] = useState(false);

    const availableFields = reportType
        ? (sources[reportType]?.fields ?? [])
        : [];

    const handleTypeChange = (value: string) => {
        setReportType(value);
        setSelectedFields([]);
        setFilters([]);
        setSortBy('');
        setPreviewData(null);
        setPreviewError(null);
    };

    const toggleField = (field: string) => {
        setSelectedFields((prev) =>
            prev.includes(field)
                ? prev.filter((f) => f !== field)
                : [...prev, field],
        );
    };

    const selectAllFields = () => {
        setSelectedFields(availableFields);
    };

    const addFilter = () => {
        setFilters([
            ...filters,
            { field: availableFields[0] || '', operator: '=', value: '' },
        ]);
    };

    const updateFilter = (index: number, key: keyof Filter, value: string) => {
        const updated = [...filters];
        updated[index] = { ...updated[index], [key]: value };
        setFilters(updated);
    };

    const removeFilter = (index: number) => {
        setFilters(filters.filter((_, i) => i !== index));
    };

    const handlePreview = async () => {
        if (!reportType || selectedFields.length === 0) return;
        setLoading(true);
        setPreviewError(null);

        try {
            const response = await fetch('/hr/reports/builder/preview', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN':
                        document.querySelector<HTMLMetaElement>(
                            'meta[name="csrf-token"]',
                        )?.content || '',
                },
                body: JSON.stringify({
                    report_type: reportType,
                    fields: selectedFields,
                    filters: filters.length > 0 ? filters : null,
                    sort_by: sortBy || null,
                    sort_direction: sortDirection,
                }),
            });

            const result = await response.json();
            if (!response.ok) {
                throw new Error(
                    result.message ||
                        'The report preview could not be generated. Review the selected fields and filters.',
                );
            }
            setPreviewData(result.data || []);
            setPreviewTotal(result.total || 0);
        } catch (error) {
            setPreviewData(null);
            setPreviewError(
                error instanceof Error
                    ? error.message
                    : 'The report preview could not be generated. Please try again.',
            );
        } finally {
            setLoading(false);
        }
    };

    const handleSave = () => {
        if (!reportName.trim()) return;

        router.post('/hr/reports/builder', {
            name: reportName,
            description: reportDescription || null,
            report_type: reportType,
            fields: selectedFields,
            filters: filters.length > 0 ? filters : null,
            sort_by: sortBy || null,
            sort_direction: sortDirection,
        });
    };

    const formatLabel = (field: string) => {
        return field
            .replace(/_/g, ' ')
            .replace(/\b\w/g, (c) => c.toUpperCase());
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Report Builder" />
            <PageLayout
                hero={
                    <PageHero
                        category="hr"
                        icon={BarChart3}
                        title="Report Builder"
                        description="Build reusable HR reports from the Sites and data fields you are currently allowed to access."
                        actions={
                            selectedFields.length > 0 ? (
                                <Button
                                    variant="outline"
                                    onClick={() => setShowSave(!showSave)}
                                    className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                >
                                    <Save className="mr-2 h-4 w-4" />
                                    Save Report
                                </Button>
                            ) : null
                        }
                    />
                }
            >
                <ReportsTabs active="builder" />

                {previewError && (
                    <Alert variant="destructive">
                        <AlertTriangle />
                        <AlertTitle>Preview could not be generated</AlertTitle>
                        <AlertDescription>{previewError}</AlertDescription>
                    </Alert>
                )}

                {/* Step 1: Select Report Type */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Step 1: Select Data Source
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Select
                            value={reportType}
                            onValueChange={handleTypeChange}
                        >
                            <SelectTrigger className="w-full max-w-sm">
                                <SelectValue placeholder="Choose a report type..." />
                            </SelectTrigger>
                            <SelectContent>
                                {Object.entries(sources).map(
                                    ([key, source]) => (
                                        <SelectItem key={key} value={key}>
                                            {source.label}
                                        </SelectItem>
                                    ),
                                )}
                            </SelectContent>
                        </Select>
                    </CardContent>
                </Card>

                {/* Step 2: Select Fields */}
                {reportType && (
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <CardTitle className="text-base">
                                    Step 2: Select Fields (
                                    {selectedFields.length} selected)
                                </CardTitle>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={selectAllFields}
                                >
                                    Select All
                                </Button>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                                {availableFields.map((field) => (
                                    <label
                                        key={field}
                                        className="flex cursor-pointer items-center gap-2 rounded-md border p-2 text-sm hover:bg-muted/50"
                                    >
                                        <Checkbox
                                            checked={selectedFields.includes(
                                                field,
                                            )}
                                            onCheckedChange={() =>
                                                toggleField(field)
                                            }
                                        />
                                        {formatLabel(field)}
                                    </label>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Step 3: Filters */}
                {reportType && selectedFields.length > 0 && (
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <CardTitle className="text-base">
                                    Step 3: Add Filters (Optional)
                                </CardTitle>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={addFilter}
                                    disabled={filters.length >= 25}
                                >
                                    <Plus className="mr-1 h-3 w-3" />
                                    Add Filter
                                </Button>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {filters.map((filter, index) => (
                                <div
                                    key={index}
                                    className="flex items-center gap-2"
                                >
                                    <Select
                                        value={filter.field}
                                        onValueChange={(v) =>
                                            updateFilter(index, 'field', v)
                                        }
                                    >
                                        <SelectTrigger className="w-48">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {availableFields.map((f) => (
                                                <SelectItem key={f} value={f}>
                                                    {formatLabel(f)}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>

                                    <Select
                                        value={filter.operator}
                                        onValueChange={(v) =>
                                            updateFilter(index, 'operator', v)
                                        }
                                    >
                                        <SelectTrigger className="w-40">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {operators.map((op) => (
                                                <SelectItem
                                                    key={op.value}
                                                    value={op.value}
                                                >
                                                    {op.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>

                                    {!['is_null', 'is_not_null'].includes(
                                        filter.operator,
                                    ) && (
                                        <Input
                                            value={filter.value}
                                            onChange={(e) =>
                                                updateFilter(
                                                    index,
                                                    'value',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="Value..."
                                            className="w-48"
                                        />
                                    )}

                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => removeFilter(index)}
                                        className="text-status-critical hover:text-status-critical"
                                    >
                                        <Trash2 className="h-4 w-4" />
                                    </Button>
                                </div>
                            ))}

                            <div className="flex gap-4 pt-2">
                                <div>
                                    <Label className="text-xs text-muted-foreground">
                                        Sort By
                                    </Label>
                                    <Select
                                        value={sortBy}
                                        onValueChange={setSortBy}
                                    >
                                        <SelectTrigger className="w-48">
                                            <SelectValue placeholder="None" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {selectedFields.map((f) => (
                                                <SelectItem key={f} value={f}>
                                                    {formatLabel(f)}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div>
                                    <Label className="text-xs text-muted-foreground">
                                        Direction
                                    </Label>
                                    <Select
                                        value={sortDirection}
                                        onValueChange={setSortDirection}
                                    >
                                        <SelectTrigger className="w-32">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="asc">
                                                Ascending
                                            </SelectItem>
                                            <SelectItem value="desc">
                                                Descending
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            {filters.length === 0 && (
                                <p className="text-sm text-muted-foreground">
                                    No filters applied. All records will be
                                    included.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                )}

                {/* Step 4: Preview & Actions */}
                {reportType && selectedFields.length > 0 && (
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <CardTitle className="text-base">
                                    Step 4: Preview Results
                                </CardTitle>
                                <Button
                                    onClick={handlePreview}
                                    disabled={loading}
                                >
                                    <Eye className="mr-2 h-4 w-4" />
                                    {loading ? 'Loading...' : 'Preview'}
                                </Button>
                            </div>
                        </CardHeader>
                        <CardContent>
                            {previewData !== null ? (
                                <>
                                    <p className="mb-3 text-sm text-muted-foreground">
                                        Showing {previewData.length} of{' '}
                                        {previewTotal} total rows
                                    </p>
                                    <div className="overflow-x-auto">
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
                                                    {selectedFields.map(
                                                        (field) => (
                                                            <TableHead
                                                                key={field}
                                                            >
                                                                {formatLabel(
                                                                    field,
                                                                )}
                                                            </TableHead>
                                                        ),
                                                    )}
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {previewData.map((row, i) => (
                                                    <TableRow key={i}>
                                                        {selectedFields.map(
                                                            (field) => (
                                                                <TableCell
                                                                    key={field}
                                                                >
                                                                    {row[
                                                                        field
                                                                    ] !==
                                                                        null &&
                                                                    row[
                                                                        field
                                                                    ] !==
                                                                        undefined
                                                                        ? typeof row[
                                                                              field
                                                                          ] ===
                                                                          'boolean'
                                                                            ? row[
                                                                                  field
                                                                              ]
                                                                                ? 'Yes'
                                                                                : 'No'
                                                                            : String(
                                                                                  row[
                                                                                      field
                                                                                  ],
                                                                              )
                                                                        : '\u2014'}
                                                                </TableCell>
                                                            ),
                                                        )}
                                                    </TableRow>
                                                ))}
                                                {previewData.length === 0 && (
                                                    <TableRow>
                                                        <TableCell
                                                            colSpan={
                                                                selectedFields.length
                                                            }
                                                            className="py-8 text-center text-muted-foreground"
                                                        >
                                                            No data matches your
                                                            criteria.
                                                        </TableCell>
                                                    </TableRow>
                                                )}
                                            </TableBody>
                                        </Table>
                                    </div>
                                </>
                            ) : (
                                <p className="py-8 text-center text-sm text-muted-foreground">
                                    Click Preview to see results.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                )}

                {/* Save Dialog */}
                {showSave && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Save Report
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <Label htmlFor="report-name">Report Name</Label>
                                <Input
                                    id="report-name"
                                    value={reportName}
                                    onChange={(e) =>
                                        setReportName(e.target.value)
                                    }
                                    placeholder="e.g. Active Employees by Department"
                                />
                            </div>
                            <div>
                                <Label htmlFor="report-desc">
                                    Description (Optional)
                                </Label>
                                <Input
                                    id="report-desc"
                                    value={reportDescription}
                                    onChange={(e) =>
                                        setReportDescription(e.target.value)
                                    }
                                    placeholder="Brief description of this report..."
                                />
                            </div>
                            <div className="flex gap-2">
                                <Button
                                    onClick={handleSave}
                                    disabled={!reportName.trim()}
                                >
                                    <Save className="mr-2 h-4 w-4" />
                                    Save
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={() => setShowSave(false)}
                                >
                                    Cancel
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                )}
            </PageLayout>
        </AppLayout>
    );
}
