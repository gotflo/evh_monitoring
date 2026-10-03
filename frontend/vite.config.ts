import { defineConfig, type Plugin } from 'vite'
import react from '@vitejs/plugin-react'

/**
 * Politique de securite du contenu, ajoutee au build de production : la console ne charge
 * que ses propres fichiers et ne se connecte qu'a sa propre API.
 */
const CSP = [
  "default-src 'self'",
  "script-src 'self'",
  "style-src 'self' 'unsafe-inline'",
  "font-src 'self' data:",
  "img-src 'self' data:",
  "connect-src 'self'",
  "object-src 'none'",
  "base-uri 'self'",
  "form-action 'self'",
  "frame-ancestors 'none'",
].join('; ')

function contentSecurityPolicy(): Plugin {
  return {
    name: 'console-csp',
    apply: 'build',
    transformIndexHtml: (html) => html.replace('<head>', `<head>\n    <meta http-equiv="Content-Security-Policy" content="${CSP}" />`),
  }
}

// En developpement, /api est redirige vers le backend de la console (port 8001).
export default defineConfig({
  plugins: [react(), contentSecurityPolicy()],
  server: {
    port: 5174,
    strictPort: true,
    proxy: {
      '/api': 'http://127.0.0.1:8001',
    },
  },
  build: {
    // Les drapeaux restent des fichiers separes (telecharges seulement s'ils s'affichent).
    assetsInlineLimit: (file) => (file.includes('flag-icons') ? false : undefined),
    rollupOptions: {
      output: {
        manualChunks: {
          react: ['react', 'react-dom', 'react-router-dom'],
          phone: ['libphonenumber-js'],
        },
      },
    },
  },
})
