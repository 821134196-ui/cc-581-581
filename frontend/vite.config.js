import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// 构建产物直接输出到 Laravel 的 public/spa，由 php artisan serve 一并托管，
// 实现「一条命令」同时提供后端 API、模拟短信/支付服务与前端页面。
export default defineConfig({
  base: '/spa/',
  plugins: [react()],
  build: {
    outDir: '../backend/public/spa',
    emptyOutDir: true,
  },
  server: {
    host: '127.0.0.1',
    port: 5173,
    proxy: {
      '/api': 'http://127.0.0.1:8000',
    },
  },
})
