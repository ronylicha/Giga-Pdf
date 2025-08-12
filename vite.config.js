import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import { copyFileSync } from 'fs';
import { resolve } from 'path';

export default defineConfig({
    plugins: [
        laravel({
            input: 'resources/js/app.js',
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        {
            name: 'copy-pdf-worker',
            writeBundle() {
                try {
                    copyFileSync(
                        resolve(__dirname, 'node_modules/pdfjs-dist/build/pdf.worker.min.mjs'),
                        resolve(__dirname, 'public/build/assets/pdf.worker.min.js')
                    );
                    console.log('PDF worker copied successfully');
                } catch (e) {
                    console.log('PDF worker copy skipped:', e.message);
                }
            }
        }
    ],
    resolve: {
        alias: {
            '@': '/resources/js',
        },
    },
});
