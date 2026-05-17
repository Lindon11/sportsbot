import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueJsx from '@vitejs/plugin-vue-jsx'
import compression from 'vite-plugin-compression'
import { visualizer } from 'rollup-plugin-visualizer'

// https://vite.dev/config/
export default defineConfig({
  base: '/',
  plugins: [
    vue(),
    vueJsx(),
    // Gzip compression
    compression({
      algorithm: 'gzip',
      ext: '.gz',
      threshold: 1024, // Only compress files > 1KB
      deleteOriginFile: false,
    }),
    // Brotli compression (better compression ratio)
    compression({
      algorithm: 'brotliCompress',
      ext: '.br',
      threshold: 1024,
      deleteOriginFile: false,
    }),
    // Bundle analysis (only in build)
    visualizer({
      filename: 'dist/stats.html',
      open: false,
      gzipSize: true,
      brotliSize: true,
    }),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url))
    },
  },
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    // Generate source maps for production debugging
    sourcemap: false,
    // Chunk splitting strategy for optimal caching
    rollupOptions: {
      output: {
        // Route-based chunk splitting for better caching
        manualChunks: (id) => {
          // Vue core ecosystem
          if (id.includes('node_modules/vue/') ||
              id.includes('node_modules/@vue/') ||
              id.includes('node_modules/vue-router/') ||
              id.includes('node_modules/pinia/')) {
            return 'vue-vendor'
          }
          // Axios for API calls
          if (id.includes('node_modules/axios/')) {
            return 'api-vendor'
          }
          // @vueuse utilities
          if (id.includes('node_modules/@vueuse/')) {
            return 'vueuse-vendor'
          }
          // Route-based chunks for views
          if (id.includes('/src/views/')) {
            // Extract the view category (admin, modules, plugins)
            const match = id.match(/\/src\/views\/([^/]+)\//)
            if (match) {
              const category = match[1]
              // Group related views together
              if (category === 'admin') return 'views-admin'
              if (category === 'modules') return 'views-modules'
              if (category === 'plugins') return 'views-plugins'
            }
            // Root level views
            return 'views-core'
          }
          // Stores
          if (id.includes('/src/stores/')) {
            return 'stores'
          }
          // Components
          if (id.includes('/src/components/')) {
            return 'components'
          }
          // Services and composables
          if (id.includes('/src/services/') || id.includes('/src/composables/')) {
            return 'services'
          }
          // Types are tree-shaken at build time, no chunk needed
        },
        // Consistent file naming
        chunkFileNames: (chunkInfo) => {
          const facadeModuleId = chunkInfo.facadeModuleId
            ? chunkInfo.facadeModuleId.split('/').pop()
            : 'chunk'
          return `assets/${chunkInfo.name || facadeModuleId}-[hash].js`
        },
        // Asset file naming
        assetFileNames: (assetInfo: { name?: string }) => {
          if (/\.(png|jpe?g|gif|svg|webp|avif|ico)$/i.test(assetInfo.name || '')) {
            return 'assets/images/[name]-[hash][extname]'
          }
          if (/\.(woff2?|eot|ttf|otf)$/i.test(assetInfo.name || '')) {
            return 'assets/fonts/[name]-[hash][extname]'
          }
          return `assets/[name]-[hash][extname]`
        },
      },
    },
    // Increase chunk size warning limit
    chunkSizeWarningLimit: 500,
    // Minification options
    minify: 'esbuild',
    // CSS code splitting
    cssCodeSplit: true,
    // Asset directory
    assetsDir: 'assets',
  },
  server: {
    port: 5175,
    host: true,
    strictPort: true,
    allowedHosts: ['frontend.openpbbg.orb.local', 'frontend.orb.local', 'localhost', '.orb.local'],
    hmr: {
      clientPort: 5175,
      protocol: 'ws',
    },
    proxy: {
      '/api': {
        target: 'http://host.docker.internal:8001',
        changeOrigin: true,
        secure: false,
        configure: (proxy) => {
          proxy.on('error', (err) => {
            console.log('proxy error', err)
          })
          proxy.on('proxyReq', (proxyReq, req) => {
            console.log('Sending Request to the Target:', req.method, req.url)
          })
        },
      },
    },
  },
  // Optimize dependencies
  optimizeDeps: {
    include: ['vue', 'vue-router', 'pinia', 'axios'],
    // Force pre-bundling even if dependencies change
    force: false,
  },
  // Enable esbuild for faster builds
  esbuild: {
    // Remove console.log in production builds
    drop: ['console', 'debugger'],
  },
})
