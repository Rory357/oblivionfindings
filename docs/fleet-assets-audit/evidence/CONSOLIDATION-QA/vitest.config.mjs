globalThis.__dirname=process.cwd();
const base=(await import('./vitest.config.ts')).default;
export default {...base,cacheDir:"C:/Users/steph/.codex/visualizations/2026/09/27/01a0e1a8-7e5a-7b81-921c-feaf73216636/consolidation-qa/vite-cache",resolve:{...base.resolve,alias:{...base.resolve.alias,'pdfjs-dist':"C:/Users/steph/.codex/worktrees/fleet-personal-reports/oblivionfindings/.consolidation-tools/pdfjs-dist"}},test:{...base.test,maxWorkers:2,cache:false}};
