import {installSyntheticApi} from './synthetic-api';
import React from 'react';import {createRoot} from 'react-dom/client';import {ReportWorkspace} from '@/pages/reporting/workspace';import {PreviewShell} from './preview-shell';import data from './fixtures.json';
const saved=installSyntheticApi();const domain=new URLSearchParams(location.search).get('domain')||'fleet';const sources=Object.fromEntries(Object.entries(data.sources).filter(([,source])=>source.domain===domain));
createRoot(document.getElementById('root')!).render(<PreviewShell domain={domain}><ReportWorkspace domain={domain} sources={sources as never} templates={data.templates.filter(t=>t.source in sources)} saved={saved.filter(s=>s.source in sources)} exportFormats={['csv','json']} viewerId={999999}/></PreviewShell>);
