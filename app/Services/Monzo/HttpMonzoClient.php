<?php

namespace App\Services\Monzo;

use App\Contracts\MonzoClient;
use App\Exceptions\Monzo\MonzoException;
use App\Exceptions\Monzo\ScaRequiredException;
use App\Exceptions\Monzo\TokenExpiredException;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class HttpMonzoClient implements MonzoClient
{
    /**
     * Monzo caps a transactions page at 100.
     */
    private const PAGE_SIZE = 100;

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
        private readonly string $authUrl,
        private readonly string $apiUrl,
    ) {}

    public function authorizationUrl(string $state): string
    {
        return $this->authUrl.'/?'.http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code): MonzoTokens
    {
        return MonzoTokens::fromResponse($this->postForm('/oauth2/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'code' => $code,
        ]));
    }

    public function refreshTokens(string $refreshToken): MonzoTokens
    {
        return MonzoTokens::fromResponse($this->postForm('/oauth2/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $refreshToken,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function whoami(string $accessToken): array
    {
        return $this->get($accessToken, '/ping/whoami');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function accounts(string $accessToken): array
    {
        $payload = $this->get($accessToken, '/accounts');

        /** @var array<int, array<string, mixed>> $accounts */
        $accounts = $payload['accounts'] ?? [];

        return $accounts;
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function transactions(string $accessToken, string $accountId, ?string $since = null): Generator
    {
        $cursor = $since;

        while (true) {
            $query = array_filter([
                'account_id' => $accountId,
                'limit' => self::PAGE_SIZE,
                'since' => $cursor,
            ], fn (mixed $value): bool => $value !== null);

            /**
             * expand[] is repeated rather than nested so Monzo sees the exact
             * parameter name it documents.
             */
            $payload = $this->get(
                $accessToken,
                '/transactions?'.http_build_query($query).'&expand[]=merchant',
            );

            /** @var array<int, array<string, mixed>> $page */
            $page = $payload['transactions'] ?? [];

            if ($page === []) {
                return;
            }

            foreach ($page as $transaction) {
                yield $transaction;
            }

            if (count($page) < self::PAGE_SIZE) {
                return;
            }

            /**
             * Paging by the last transaction id rather than its timestamp
             * avoids skipping rows that share a created time.
             */
            $last = $page[count($page) - 1];
            $lastId = $last['id'] ?? null;

            if (! is_string($lastId) || $lastId === '') {
                return;
            }

            $cursor = $lastId;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function balance(string $accessToken, string $accountId): array
    {
        return $this->get($accessToken, '/balance?'.http_build_query(['account_id' => $accountId]));
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $accessToken, string $path): array
    {
        return $this->send(fn (): Response => $this->request()->withToken($accessToken)->get($path));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function postForm(string $path, array $payload): array
    {
        return $this->send(fn (): Response => $this->request()->asForm()->post($path, $payload));
    }

    /**
     * Turn transport and status failures into meaningful application
     * exceptions, keeping the pending-approval case distinct from a genuine
     * authorisation failure.
     *
     * @param  callable(): Response  $send
     * @return array<string, mixed>
     */
    private function send(callable $send): array
    {
        try {
            $response = $send();
        } catch (ConnectionException $exception) {
            throw new MonzoException('Could not reach Monzo: '.$exception->getMessage(), previous: $exception);
        }

        if ($response->status() === 403) {
            throw ScaRequiredException::make();
        }

        if ($response->status() === 401) {
            throw TokenExpiredException::make();
        }

        try {
            $response->throw();
        } catch (RequestException $exception) {
            throw new MonzoException(
                'Monzo returned '.$response->status().': '.$response->body(),
                previous: $exception,
            );
        }

        /** @var array<string, mixed> $decoded */
        $decoded = $response->json() ?? [];

        return $decoded;
    }

    /**
     * Retries cover transport faults and Monzo's own 5xx responses only. A 4xx
     * is a decision, not a blip, and retrying one would burn the five minute
     * full-history window for nothing.
     */
    private function request(): PendingRequest
    {
        return Http::baseUrl($this->apiUrl)
            ->timeout(15)
            ->connectTimeout(5)
            ->retry(3, 200, function (Throwable $exception): bool {
                return $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError());
            }, throw: false)
            ->acceptJson();
    }
}
