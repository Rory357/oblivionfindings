import { defineConfig } from 'vitest/config';

import testConfig from './vitest.config';

// The identical browser-state regression with the repository's ordinary,
// uncompiled React test transform isolates the production-only failure.
export default defineConfig({
    ...testConfig,
    test: {
        ...testConfig.test,
        include: ['tests/Frontend/It/task-recovery.compiler.test.tsx'],
    },
});
