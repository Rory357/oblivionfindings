import { readFileSync, readdirSync } from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';
import type { Plugin } from 'vite';

/** Serve PDF fonts, character maps and image decoders locally, with no external viewer/CDN. */
export function pdfjsAssets(
    packageRoot = path.dirname(
        createRequire(import.meta.url).resolve('pdfjs-dist/package.json'),
    ),
): Plugin {
    const files = new Map<string, string>();
    for (const directory of ['cmaps', 'standard_fonts', 'wasm']) {
        for (const entry of readdirSync(path.join(packageRoot, directory), {
            withFileTypes: true,
        })) {
            if (entry.isFile())
                files.set(
                    `pdfjs/${directory}/${entry.name}`,
                    path.join(packageRoot, directory, entry.name),
                );
        }
    }
    let base = '/';
    return {
        name: 'local-pdf-preview-assets',
        configResolved(config) {
            base = config.base;
        },
        configureServer(server) {
            server.middlewares.use((request, response, next) => {
                const url = request.url?.split('?')[0] ?? '';
                const file = url.startsWith(base)
                    ? files.get(url.slice(base.length))
                    : undefined;
                if (!file) return next();
                response.setHeader(
                    'Content-Type',
                    file.endsWith('.wasm')
                        ? 'application/wasm'
                        : 'application/octet-stream',
                );
                response.end(readFileSync(file));
            });
        },
        generateBundle() {
            for (const [fileName, file] of files)
                this.emitFile({
                    type: 'asset',
                    fileName,
                    source: readFileSync(file),
                });
        },
    };
}
