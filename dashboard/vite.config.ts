import { defineConfig, type ProxyOptions } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { resolve } from 'path'

const backendTarget = process.env.VITE_BACKEND_URL ?? 'http://payswitch.test'

const proxyOpts: ProxyOptions = {
  target: backendTarget,
  changeOrigin: true,
  cookieDomainRewrite: { 'payswitch.test': 'localhost' },
}

function methodProxy(methods: string[]): ProxyOptions {
  return {
    ...proxyOpts,
    bypass(req) {
      if (!methods.includes(req.method ?? 'GET')) {
        return req.url
      }
    },
  }
}

export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': resolve(__dirname, './src'),
    },
  },
  build: {
    rollupOptions: {
      output: {
        manualChunks(id) {
          if (id.includes('node_modules/recharts')) return 'vendor-charts'
          if (id.includes('node_modules/@dnd-kit')) return 'vendor-dnd'
          if (
            id.includes('node_modules/react-hook-form') ||
            id.includes('node_modules/zod') ||
            id.includes('node_modules/@hookform')
          )
            return 'vendor-forms'
        },
      },
    },
  },
  test: {
    environment: 'happy-dom',
    setupFiles: ['./src/test-setup.ts'],
  },
  server: {
    port: 3000,
    proxy: {
      '/api/': proxyOpts,
      '/sanctum': proxyOpts,
      '/login': methodProxy(['POST']),
      '/logout': methodProxy(['POST']),
      '/two-factor-challenge': methodProxy(['POST']),
    },
  },
})
