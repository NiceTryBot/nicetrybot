<?php

namespace App\Support;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Gzip large app pages and Livewire updates for browsers that take it. Lambda caps a response at 6 MB,
 * which a long Activity list passes as plain HTML; compressed, it's a fraction of that. Vapor sends a
 * gzipped body on as base64 only when told to, so the response says so.
 *
 * It listens for the handled request rather than sitting in the middleware: Livewire adds its scripts
 * to the page at that point, after every middleware has run, and can't find the page once it's
 * compressed. Registered after Livewire's listener, it compresses the finished page. Only web routes:
 * the edge and the gym's visitors are served as they are.
 */
class CompressResponse
{
    /**
     * Bodies smaller than this go as they are: compressing them saves little.
     */
    private const int MIN_BYTES = 8 * 1024;

    public function __invoke(RequestHandled $event): void
    {
        $response = $event->response;

        if (! $this->shouldCompress($event->request, $response)) {
            return;
        }

        $compressed = gzencode((string) $response->getContent(), 5);

        if ($compressed === false) {
            return;
        }

        $response->setContent($compressed);
        $response->headers->set('Content-Encoding', 'gzip');
        $response->headers->set('Content-Length', (string) strlen($compressed));
        $response->headers->set('X-Vapor-Base64-Encode', 'True');
        $response->setVary('Accept-Encoding', replace: false);
    }

    private function shouldCompress(Request $request, Response $response): bool
    {
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse || $response->headers->has('Content-Encoding')) {
            return false;
        }

        if (! in_array('web', $request->route()?->gatherMiddleware() ?? [], true)) {
            return false;
        }

        if (! str_contains(strtolower((string) $request->headers->get('Accept-Encoding')), 'gzip')) {
            return false;
        }

        $contentType = strtolower((string) $response->headers->get('Content-Type', 'text/html'));

        if (! Str::startsWith($contentType, 'text/') && ! Str::contains($contentType, ['json', 'xml'])) {
            return false;
        }

        $content = $response->getContent();

        return is_string($content) && strlen($content) >= self::MIN_BYTES;
    }
}
