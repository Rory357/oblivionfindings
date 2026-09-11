import type { Plugin, PluginOption } from 'vite';
import { defineConfig } from 'vitest/config';

import productionConfig from './vite.config';
import testConfig from './vitest.config';

async function flattenPlugins(option: PluginOption): Promise<Plugin[]> {
    const resolved = await option;
    if (Array.isArray(resolved)) {
        return (await Promise.all(resolved.map(flattenPlugins))).flat();
    }
    return resolved ? [resolved] : [];
}

export default defineConfig(async () => {
    // Use the actual application React plugins, including its compiler options.
    // Exclude Laravel/Wayfinder/CSS hooks: this test must not generate assets,
    // discover PHP routes, or start the application's development server.
    const reactPlugins = (
        await flattenPlugins(productionConfig.plugins ?? [])
    ).filter((plugin) => plugin.name.startsWith('vite:react-'));
    if (!reactPlugins.some((plugin) => plugin.name === 'vite:react-babel')) {
        throw new Error('The production React Babel plugin was not found.');
    }

    const assertCompiledTaskRegister: Plugin = {
        name: 'it-test-confirm-production-react-compiler',
        enforce: 'post',
        transform(code, id) {
            if (
                id
                    .replaceAll('\\', '/')
                    .split('?')[0]
                    .endsWith(
                        '/resources/js/components/it/ticket-work-tasks.tsx',
                    )
            ) {
                if (!code.includes('react/compiler-runtime')) {
                    throw new Error(
                        'The task register did not receive the production React Compiler transform.',
                    );
                }
                console.info(
                    '[IT compiler regression] Production React Compiler transform confirmed for the task register.',
                );
            }
            if (
                process.env.IT_TASK_COMPILER_TRACE === '1' &&
                id
                    .replaceAll('\\', '/')
                    .split('?')[0]
                    .endsWith(
                        '/resources/js/hooks/use-it-ticket-draft-memory.ts',
                    )
            ) {
                const start = code.indexOf(
                    'function useItWorkTaskMemoryNotices(',
                );
                const end = code.indexOf(
                    'function purgeItWorkTaskMemory(',
                    start,
                );
                console.info(code.slice(start, end));
            }
        },
    };

    return {
        ...testConfig,
        plugins: [...reactPlugins, assertCompiledTaskRegister],
        test: {
            ...testConfig.test,
            include: ['tests/Frontend/It/task-recovery.compiler.test.tsx'],
        },
    };
});
