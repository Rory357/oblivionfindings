globalThis.__dirname=process.cwd();
export default (await import('./vite.config.ts')).default;
