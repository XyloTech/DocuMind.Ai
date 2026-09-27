<?php

namespace App\Support;

use App\Models\Site;

/**
 * Generates the copy-and-paste install snippet for every supported stack.
 *
 * The widget is a single classic script tag with no build step, which is what
 * makes it work unchanged on plain HTML, React, Next.js, Vue, Angular, Svelte
 * and WordPress. The framework variants differ only in *where* the tag
 * goes and how the mount is cleaned up, so each one is a short, copyable
 * fragment rather than a full component.
 *
 * Two rules every snippet obeys:
 *  - the bundle is loaded at most once per page (a second load would mount a
 *    second bubble);
 *  - the public API is read off `window.DocuMindWidget`, never off a dynamic
 *    `import()` — the bundle is an IIFE, so `import()` yields an empty module.
 */
final class EmbedSnippet
{
    public function __construct(private readonly string $origin) {}

    public function origin(): string
    {
        return $this->origin;
    }

    /**
     * Build the snippet for a site against an explicit origin.
     */
    public static function for(Site $site, string $origin): self
    {
        return new self(rtrim($origin, '/'));
    }

    /**
     * The canonical one-liner. Also the fallback when a framework-specific
     * snippet is not needed.
     */
    public function script(Site $site): string
    {
        return '<script src="'.$this->scriptUrl().'" data-site-key="'.$site->site_key.'" defer></script>';
    }

    /**
     * Every framework tab, in display order.
     *
     * @return array<string, array{label: string, hint: string, steps: list<string>, code: string}>
     */
    public function forSite(Site $site): array
    {
        $script = $this->script($site);
        $key = $site->site_key;
        $url = $this->scriptUrl();

        $loader = <<<CODE
            // Loads the bundle exactly once, then resolves with its public API.
            function loadDocuMind() {
              if (window.DocuMindWidget) return Promise.resolve(window.DocuMindWidget);
              if (window.__docuMindLoading) return window.__docuMindLoading;

              window.__docuMindLoading = new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = '{$url}';
                script.async = true;
                script.dataset.siteKey = '{$key}';
                script.onload = () => resolve(window.DocuMindWidget);
                script.onerror = () => reject(new Error('DocuMind widget failed to load'));
                document.head.appendChild(script);
              });

              return window.__docuMindLoading;
            }
            CODE;

        return [
            'html' => [
                'label' => 'HTML / CSS / JavaScript',
                'hint' => 'Paste before </body> on any static site, landing page, blog, or vanilla JavaScript app.',
                'steps' => [
                    'Open the page that should show the assistant.',
                    'Paste the snippet immediately before the closing </body> tag.',
                    'Publish the change, then run Verify installation below.',
                ],
                'code' => $script,
            ],
            'spa' => [
                'label' => 'SPA with any router',
                'hint' => 'Generic single-page app: call once after the shell mounts (React Router, Vue Router, TanStack Router, etc.).',
                'steps' => [
                    'Copy both the loader and the mount call into a module your app always loads.',
                    'Call loadDocuMind() once, after the router shell has mounted.',
                    'The loader promise guards against a second bubble if the route remounts.',
                ],
                'code' => $loader."\n\nloadDocuMind().then((widget) => widget.mount({ siteKey: '{$key}' }));",
            ],
            'react' => [
                'label' => 'React',
                'hint' => 'Mount it once from a top-level effect. The cleanup unmounts, so React StrictMode double-effects stay safe.',
                'steps' => [
                    'Put the effect in your root component so it runs for every route.',
                    'The data-site-key guard makes React StrictMode double-mounts harmless.',
                    'The cleanup unmounts and removes the tag, so navigation never stacks bubbles.',
                ],
                'code' => <<<CODE
                    import { useEffect } from 'react';

                    export default function App() {
                      useEffect(() => {
                        let cancelled = false;

                        async function mountWidget() {
                          if (document.querySelector('script[data-site-key="{$key}"]')) return;

                          const script = document.createElement('script');
                          script.src = '{$url}';
                          script.async = true;
                          script.dataset.siteKey = '{$key}';
                          document.head.appendChild(script);
                          await new Promise((resolve) => { script.onload = resolve; });

                          if (!cancelled) window.DocuMindWidget?.mount({ siteKey: '{$key}' });
                        }

                        mountWidget();

                        return () => {
                          cancelled = true;
                          window.DocuMindWidget?.unmount();
                          document.querySelector('script[data-site-key="{$key}"]')?.remove();
                        };
                      }, []);

                      return <div id="app" />;
                    }
                    CODE,
            ],
            'next' => [
                'label' => 'Next.js',
                'hint' => 'App Router: add <Script> to the root layout. Pages Router: put it in _document.tsx. Both are client-safe.',
                'steps' => [
                    'App Router: add the component to app/layout.tsx. Pages Router: use pages/_document.tsx.',
                    'Keep strategy="afterInteractive" so it loads after the first paint.',
                    'Do not wrap it in a client-only guard — next/script already handles hydration.',
                ],
                'code' => <<<CODE
                    import Script from 'next/script';

                    export default function RootLayout({ children }) {
                      return (
                        <html lang="en">
                          <body>
                            {children}
                            <Script
                              src="{$url}"
                              data-site-key="{$key}"
                              strategy="afterInteractive"
                            />
                          </body>
                        </html>
                      );
                    }
                    CODE,
            ],
            'vue' => [
                'label' => 'Vue',
                'hint' => 'Load it from onMounted in App.vue so it never runs during SSR.',
                'steps' => [
                    'Paste the code into App.vue, or a component that is always mounted.',
                    'onMounted keeps the script out of server-side rendering.',
                    'onBeforeUnmount removes the tag so a full page swap does not stack bubbles.',
                ],
                'code' => <<<CODE
                    <script setup>
                    import { onMounted, onUnmounted } from 'vue';

                    let script;

                    onMounted(() => {
                      script = document.createElement('script');
                      script.src = '{$url}';
                      script.async = true;
                      script.dataset.siteKey = '{$key}';
                      document.head.appendChild(script);
                      script.onload = () => window.DocuMindWidget?.mount({ siteKey: '{$key}' });
                    });

                    onUnmounted(() => {
                      window.DocuMindWidget?.unmount();
                      script?.remove();
                    });
                    </script>
                    CODE,
            ],
            'angular' => [
                'label' => 'Angular',
                'hint' => 'Declare it in index.html so it loads outside the Angular zone and never re-runs on route changes.',
                'steps' => [
                    'Open src/index.html and paste the tag before </body>.',
                    'Loading it there keeps it outside Angular change detection and the router cycle.',
                    'Run ng serve and use Verify installation to confirm the bundle is reachable.',
                ],
                'code' => <<<CODE
                    <!-- index.html, before </body> -->
                    <script src="{$url}" data-site-key="{$key}" defer></script>
                    CODE,
            ],
            'svelte' => [
                'label' => 'Svelte / SvelteKit',
                'hint' => 'Append the script from onMount in your root +layout.svelte or +page.svelte.',
                'steps' => [
                    'Paste into +layout.svelte so it survives route changes, not a single page.',
                    'onMount runs only in the browser, which SvelteKit needs for SSR.',
                    'onDestroy unmounts and removes the tag on teardown.',
                ],
                'code' => <<<CODE
                    <script>
                      import { onMount } from 'svelte';

                      let script;

                      onMount(() => {
                        script = document.createElement('script');
                        script.src = '{$url}';
                        script.async = true;
                        script.dataset.siteKey = '{$key}';
                        document.head.appendChild(script);
                        script.onload = () => window.DocuMindWidget?.mount({ siteKey: '{$key}' });

                        return () => {
                          window.DocuMindWidget?.unmount();
                          script?.remove();
                        };
                      });
                    </script>
                    CODE,
            ],
            'wordpress' => [
                'label' => 'WordPress',
                'hint' => 'Appearance → Theme File Editor → footer.php, or a WPCode "Insert Header and Footer" snippet (site-wide, no plugin conflict).',
                'steps' => [
                    'Preferred: install WPCode and add the snippet under "Insert Header and Footer".',
                    'Alternative: paste into footer.php of your active theme, before </body>.',
                    'Save, clear any page cache, then run Verify installation.',
                ],
                'code' => $script,
            ],
        ];
    }

    private function scriptUrl(): string
    {
        // The path stays stable — no hashed asset name for an owner to chase —
        // while the fingerprinted query busts any copy a browser is still
        // holding from before a rebuild. The endpoint ignores the query and
        // always serves the current bundle, so an already-copied snippet keeps
        // working (and keeps receiving fixes) after the next build.
        return $this->origin.'/widget.js?v='.WidgetBundle::version();
    }
}
