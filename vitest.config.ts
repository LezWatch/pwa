import { defineConfig } from 'vitest/config';

// Separate from vite.config.js so the Laravel plugin stays out of tests.
export default defineConfig({
    test: {
        environment: 'node',
        include: ['resources/js/**/*.test.ts'],
        setupFiles: ['resources/js/store/test-setup.ts'],
    },
});
