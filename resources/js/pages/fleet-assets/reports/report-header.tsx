import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
} from '@/components/page/page-header';
import { router } from '@inertiajs/react';
import { ArrowLeft, Download, FileBarChart } from 'lucide-react';
import type { ReactNode } from 'react';

export function FleetReportHeader({
    title,
    description,
    filters,
    meters = [],
    onExport,
}: {
    title: string;
    description: string;
    filters?: ReactNode;
    meters?: { label: string; value: ReactNode; caption: string }[];
    onExport?: () => void;
}) {
    return (
        <>
            <PageHeader
                icon={FileBarChart}
                title={title}
                wrapTitle
                subline={description}
                actions={
                    <>
                        <PageHeaderGlassButton
                            icon={ArrowLeft}
                            onClick={() =>
                                router.visit('/fleet-assets/reports')
                            }
                        >
                            Report library
                        </PageHeaderGlassButton>
                        {onExport && (
                            <PageHeaderGlassButton
                                icon={Download}
                                onClick={onExport}
                            >
                                Export CSV
                            </PageHeaderGlassButton>
                        )}
                    </>
                }
                meters={
                    meters.length > 0
                        ? meters.map((meter) => (
                              <PageHeaderMeterBlock
                                  key={meter.label}
                                  label={meter.label}
                                  onClick={() =>
                                      document
                                          .getElementById('report-results')
                                          ?.scrollIntoView({
                                              behavior: 'smooth',
                                          })
                                  }
                              >
                                  <PageHeaderMeterBig>
                                      {meter.value}
                                  </PageHeaderMeterBig>
                                  <PageHeaderMeterCaption>
                                      {meter.caption}
                                  </PageHeaderMeterCaption>
                              </PageHeaderMeterBlock>
                          ))
                        : undefined
                }
                filters={filters}
            />
            <div id="report-results" className="scroll-mt-4" />
        </>
    );
}
