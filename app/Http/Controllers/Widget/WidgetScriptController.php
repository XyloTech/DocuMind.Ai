<?php

namespace App\Http\Controllers\Widget;

use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Notifier;
use App\Support\WidgetBundle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Serves the embeddable widget bundle.
 *
 * Kept as its own route so the install snippet is a single stable URL that can
 * be cached hard at the customer's CDN: no hashed asset names to chase, no
 * build step on their site.
 */
class WidgetScriptController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $path = WidgetBundle::resolvePath();

        if ($path === null) {
            $this->notifyMissingBundle();

            abort(404);
        }

        $contents = (string) file_get_contents($path);
        $etag = '"'.md5($contents).'"';

        $headers = [
            'Content-Type' => 'application/javascript; charset=utf-8',
            /*
             | The URL is deliberately un-hashed so a customer's snippet never
             | has to change, which also means a browser that fetched yesterday's
             | build cannot be told apart from one fetching today's by the URL
             | alone. The previous `max-age=86400` therefore held a stale bundle
             | for a full day — long enough for a rebuild (and any fix in it) to
             | be invisible, which is what makes an install look broken.
             | Revalidation keeps the URL stable while letting an unchanged
             | bundle answer with a 304 instead of the full body.
             */
            'Cache-Control' => 'public, max-age=0, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
            'ETag' => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', filemtime($path)).' GMT',
        ];

        if ($request->headers->get('If-None-Match') === $etag) {
            return new Response(null, 304, $headers);
        }

        return response($contents, 200, $headers);
    }

    /**
     * Every installed snippet points at this URL, so a missing build breaks
     * customer sites silently. The cache guard keeps a 404 storm (each page
     * view of every installed site) down to one lookup, and the notice itself
     * repeats at most once an hour until the bundle is rebuilt.
     */
    private function notifyMissingBundle(): void
    {
        try {
            if (! Cache::add('widget.script-missing-notice', 1, now()->addHour())) {
                return;
            }

            Notifier::make()
                ->type(NotificationType::SystemNotice)
                ->to(User::admins()->get())
                ->title('Widget script unavailable')
                ->body('Requests for /widget.js are returning 404, so installed snippets cannot load. Run `npm run build` and try again.')
                ->link(route('widget.index'), 'Open widget sites')
                ->dedupe('system.widget_script.'.now()->format('Y-m-d.H'))
                ->send();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
