// Ref: https://vite.dev/config/
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath, URL } from 'node:url';
export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./src', import.meta.url)),
        },
    },
    server: {
        host: '0.0.0.0',
        port: 5173,
        // HMR works through the gateway via WebSocket upgrade
        hmr: {
            clientPort: 5173,
        },
    },
});
