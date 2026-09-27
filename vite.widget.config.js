import { defineConfig } from 'vite';

/**
 * The embeddable widget is built on its own, to a stable, unhashed URL
 * (public/build/widget.js) that customers can paste into their own sites and
 * cache at their CDN forever.
 */
export default defineConfig({
    // Only the bundle is written here: without this, Vite treats `public/` as
    // the static dir and copies index.php, .htaccess and friends into
    // public/build next to widget.js.
    publicDir: false,
    build: {
        outDir: 'public/build',
        emptyOutDir: false,
        lib: {
            entry: 'resources/js/widget.js',
            formats: ['iife'],
            name: 'DocuMindWidget',
            fileName: () => 'widget.js',
        },
    },
});
