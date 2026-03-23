import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  base: '/library/',
  build: {
    outDir: '../htdocs/library',
    emptyOutDir: true,
  },
  server: {
    proxy: {
      '/users': 'http://localhost:8080',
      '/groups': 'http://localhost:8080',
      '/keys': 'http://localhost:8080',
      '/library-auth.php': 'http://localhost:8080',
    }
  }
})
