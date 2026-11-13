import { defineConfig } from 'vite';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import tailwindcss from '@tailwindcss/vite';
import { formatBuildStamp } from './src/lib/buildStamp.ts';

const buildStamp = formatBuildStamp(new Date());

export default defineConfig(({ command }) => ({
  plugins: [tailwindcss(), svelte()],
  define: {
    __BUILD_STAMP__: JSON.stringify(buildStamp),
  },
  base: command === 'build' ? '/build/' : '/',
  server: {
    port: 5173,
    strictPort: true,
    fs: {
      allow: ['..'],
    },
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8080',
        changeOrigin: true,
      },
    },
  },
  build: {
    outDir: '../public/build',
    emptyOutDir: true,
  },
}));
