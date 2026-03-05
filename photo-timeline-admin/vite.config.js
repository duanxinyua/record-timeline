import { defineConfig } from 'vite'
import uni from '@dcloudio/vite-plugin-uni'
// https://vitejs.dev/config/
export default defineConfig({
  base: './',
  plugins: [
    uni(),
  ],
  server: {
    host: true,
    port: 3000,
    hmr: {
      host: 'localhost',
      protocol: 'ws',
    },
    proxy: {
      '/api': {
        target: 'https://api.hetao.us',
        changeOrigin: true,
        secure: true,
        rewrite: (path) => path.replace(/^\/api/, ''),
      }
    }
  },
})
