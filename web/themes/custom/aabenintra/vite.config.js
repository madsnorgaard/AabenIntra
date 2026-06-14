import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';

const root = dirname(fileURLToPath(import.meta.url));

// Build contract: stable names (main.css / main.js, no content hashes), fonts
// copied to dist/fonts, deterministic output so CI can diff-verify dist/.
export default defineConfig({
  base: './',
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    assetsInlineLimit: 0,
    cssMinify: true,
    rollupOptions: {
      input: {
        main: resolve(root, 'src/js/main.js'),
        style: resolve(root, 'src/css/main.css'),
      },
      output: {
        entryFileNames: '[name].js',
        assetFileNames: (assetInfo) => {
          const name = assetInfo.names?.[0] ?? assetInfo.name ?? '';
          if (name.endsWith('.css')) {
            return 'main.css';
          }
          if (/\.(woff2?|ttf|otf|eot)$/.test(name)) {
            return 'fonts/[name][extname]';
          }
          return '[name][extname]';
        },
      },
    },
  },
});
