import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
  plugins: [
    vue(),
    VitePWA({
      registerType: 'autoUpdate',
      manifest: {
        name: 'Forty-Fives',
        short_name: '45s',
        start_url: '/45s/',
        display: 'standalone',
        background_color: '#0f1a2a',
        theme_color: '#123b5d',
        icons: []
      }
    })
  ],
  build: {
    outDir: '../public/app',
    emptyOutDir: true
  }
});
