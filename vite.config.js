import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vuetify from 'vite-plugin-vuetify'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig({
  plugins: [
    vue(),
    vuetify({
      autoImport: true,
    }),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
    },
  },
  server: {
    host: true,
    port: 5173,
    hmr: {
      host: 'localhost'
    },
    watch: {
      usePolling: true,
    }
  },
  build: {
    outDir: 'public/build',
    manifest: true,
    rollupOptions: {
      input: {
        app: '/resources/js/app.js'
      }
    }
  }
})
