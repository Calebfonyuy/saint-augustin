// Ref: https://vite.dev/config/
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

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
    // Dev proxy — forwards /api/* to the Nginx gateway when running Vite
    // outside Docker (e.g. `npm run dev` on a remote VM without the gateway
    // in front of Vite). Mirrors the gateway routing so the same relative
    // URLs work in both dev and Docker-compose mode.
    //
    // Set VITE_GATEWAY_URL in frontend/.env.local if your gateway is not on
    // the default port 80 (e.g. VITE_GATEWAY_URL=http://localhost:8080).
    proxy: {
      '/api': {
        target: process.env.VITE_GATEWAY_URL || 'http://localhost:80',
        changeOrigin: true,
      },
      '/socket.io': {
        target: process.env.VITE_GATEWAY_URL || 'http://localhost:80',
        changeOrigin: true,
        ws: true,
      },
    },
  },
})
