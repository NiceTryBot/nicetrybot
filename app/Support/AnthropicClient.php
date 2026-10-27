<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Calls the Claude API with a user's own key. Errors come back as AnthropicException, worded for the
 * person whose key it is.
 */
class AnthropicClient
{
    public const string API_VERSION = '2023-06-01';

    /**
     * The beta that lets a declined request run again on the model Anthropic recommends for it.
     */
    public const string FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    /**
     * How long one call may take, well inside the web request's own limit, so a slow answer becomes a
     * message instead of a timed-out page.
     */
    public const int TIMEOUT_SECONDS = 15;

    /**
     * Determine if Anthropic accepts a key, by listing one model (which costs nothing). A key for a whole
     * organization, rather than one workspace, also needs the workspace to use.
     *
     * @throws AnthropicWorkspaceRequired When the key needs a workspace and none was given.
     * @throws AnthropicException When Anthropic can't be reached or answers with something other than yes or no.
     */
    public function accepts(string $key, ?string $workspaceId = null): bool
    {
        $response = $this->send(fn () => $this->request($key, $workspaceId)->get('v1/models', ['limit' => 1]));

        if (in_array($response->status(), [401, 403], true) && ! $this->needsWorkspace($response)) {
            return false;
        }

        $this->ensureSuccessful($response);

        return true;
    }

    /**
     * Create a message and get the response body. A refusal is an error, since there's nothing to read.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws AnthropicException
     */
    public function message(string $key, array $body, ?string $workspaceId = null): array
    {
        $response = $this->send(fn () => $this->request($key, $workspaceId)
            ->withHeaders(['anthropic-beta' => self::FALLBACK_BETA])
            ->post('v1/messages', ['fallbacks' => 'default', ...$body]));

        $this->ensureSuccessful($response);

        $message = $response->json();

        if (($message['stop_reason'] ?? null) === 'refusal') {
            throw new AnthropicException(__('Claude declined to answer that one. Try wording it differently.'));
        }

        return $message;
    }

    /**
     * Get the text of a message's text blocks.
     *
     * @param  array<string, mixed>  $message
     */
    public static function text(array $message): string
    {
        return collect($message['content'] ?? [])
            ->where('type', 'text')
            ->pluck('text')
            ->implode('');
    }

    private function request(string $key, ?string $workspaceId): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.anthropic.url'), '/').'/')
            ->withHeaders(array_filter([
                'x-api-key' => $key,
                'anthropic-version' => self::API_VERSION,
                'anthropic-workspace-id' => $workspaceId,
            ]))
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(self::TIMEOUT_SECONDS)
            // Overloaded, rate limited or a dropped connection are worth one more try; a slow answer isn't.
            ->retry(2, 750, fn (Throwable $exception) => ($exception instanceof ConnectionException && ! self::timedOut($exception))
                || ($exception instanceof RequestException && in_array($exception->response->status(), [429, 500, 502, 503, 529], true)), throw: false);
    }

    /**
     * @param  callable(): Response  $call
     *
     * @throws AnthropicException
     */
    private function send(callable $call): Response
    {
        try {
            return $call();
        } catch (ConnectionException $exception) {
            throw new AnthropicException(self::timedOut($exception)
                ? __('Claude took too long to answer. Try again, or ask something simpler.')
                : __('Couldn’t reach Anthropic. Check your connection and try again.'));
        }
    }

    /**
     * Determine if a connection failed because the answer took too long (cURL error 28).
     */
    private static function timedOut(ConnectionException $exception): bool
    {
        return str_contains($exception->getMessage(), 'cURL error 28') || str_contains(strtolower($exception->getMessage()), 'timed out');
    }

    /**
     * @throws AnthropicException
     */
    private function ensureSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $detail = (string) $response->json('error.message', '');

        if ($this->needsWorkspace($response)) {
            throw new AnthropicWorkspaceRequired(__('Your Anthropic key belongs to your whole organization, so it needs a workspace ID too. Add one in Settings → Artificial Intelligence.'));
        }

        throw new AnthropicException(match (true) {
            $response->status() === 401 => __('Anthropic didn’t accept your API key. Check it in Settings → Artificial Intelligence.'),
            $response->status() === 403 => __('Your Anthropic API key isn’t allowed to do that: :detail', ['detail' => $detail]),
            $response->status() === 429 => __('Anthropic is rate limiting your key. Try again in a moment.'),
            in_array($response->status(), [500, 502, 503, 529], true) => __('Anthropic is busy right now. Try again in a moment.'),
            $detail !== '' => __('Anthropic said: :detail', ['detail' => $detail]),
            default => __('Anthropic answered with an error (:status).', ['status' => $response->status()]),
        });
    }

    /**
     * Determine if Anthropic turned the request away for want of a workspace, which an organization-wide
     * key must name on every request.
     */
    private function needsWorkspace(Response $response): bool
    {
        return $response->failed() && str_contains((string) $response->json('error.message', ''), 'anthropic-workspace-id');
    }
}
