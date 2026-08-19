<?php

use App\Contracts\MonzoClient;
use App\Exceptions\Monzo\MonzoException;
use App\Exceptions\Monzo\ScaRequiredException;
use App\Exceptions\Monzo\TokenExpiredException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.monzo.client_id', 'oauth2client_test');
    config()->set('services.monzo.client_secret', 'secret_test');
    config()->set('services.monzo.redirect', 'http://localhost/settings/connections/monzo/callback');

    Http::preventStrayRequests();

    $this->client = app(MonzoClient::class);
});

test('the authorization url carries the client id, redirect and state', function () {
    $url = $this->client->authorizationUrl('state-token');

    expect($url)->toStartWith('https://auth.monzo.com/?');
    expect($url)->toContain('client_id=oauth2client_test');
    expect($url)->toContain('response_type=code');
    expect($url)->toContain('state=state-token');
    expect($url)->toContain(urlencode('http://localhost/settings/connections/monzo/callback'));
});

test('an authorization code is exchanged for tokens', function () {
    Http::fake([
        'api.monzo.com/oauth2/token' => Http::response([
            'access_token' => 'access-123',
            'refresh_token' => 'refresh-456',
            'expires_in' => 21600,
            'user_id' => 'user_abc',
            'token_type' => 'Bearer',
        ]),
    ]);

    $tokens = $this->client->exchangeCode('auth-code');

    expect($tokens->accessToken)->toBe('access-123');
    expect($tokens->refreshToken)->toBe('refresh-456');
    expect($tokens->userId)->toBe('user_abc');
    expect($tokens->isRefreshable())->toBeTrue();
    expect($tokens->expiresAt?->diffInSeconds(now()->addSeconds(21600), absolute: true))->toBeLessThan(5);

    Http::assertSent(fn (Request $request) => $request['grant_type'] === 'authorization_code'
        && $request['code'] === 'auth-code'
        && $request['client_secret'] === 'secret_test');
});

test('a non confidential client is flagged as unrefreshable', function () {
    Http::fake([
        'api.monzo.com/oauth2/token' => Http::response([
            'access_token' => 'access-123',
            'expires_in' => 21600,
        ]),
    ]);

    expect($this->client->exchangeCode('auth-code')->isRefreshable())->toBeFalse();
});

test('accounts are unwrapped from the envelope', function () {
    Http::fake([
        'api.monzo.com/accounts*' => Http::response([
            'accounts' => [
                ['id' => 'acc_1', 'description' => 'Current account', 'type' => 'uk_retail'],
            ],
        ]),
    ]);

    $accounts = $this->client->accounts('access-123');

    expect($accounts)->toHaveCount(1);
    expect($accounts[0]['id'])->toBe('acc_1');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer access-123'));
});

test('a 403 means the user has not yet approved access in the monzo app', function () {
    Http::fake(['api.monzo.com/accounts*' => Http::response(['code' => 'forbidden'], 403)]);

    expect(fn () => $this->client->accounts('access-123'))->toThrow(ScaRequiredException::class);
});

test('a 401 means the token is dead', function () {
    Http::fake(['api.monzo.com/accounts*' => Http::response(['code' => 'unauthorized'], 401)]);

    expect(fn () => $this->client->accounts('access-123'))->toThrow(TokenExpiredException::class);
});

test('a server error surfaces as a monzo exception after retries', function () {
    Http::fake(['api.monzo.com/accounts*' => Http::response(['code' => 'internal'], 500)]);

    expect(fn () => $this->client->accounts('access-123'))->toThrow(MonzoException::class);
});

test('transactions page through until a short page is returned', function () {
    $firstPage = array_map(
        fn (int $i): array => ['id' => 'tx_'.$i, 'amount' => -100, 'created' => '2026-01-01T00:00:00Z'],
        range(1, 100),
    );

    Http::fakeSequence('api.monzo.com/transactions*')
        ->push(['transactions' => $firstPage])
        ->push(['transactions' => [['id' => 'tx_101', 'amount' => -250, 'created' => '2026-01-02T00:00:00Z']]]);

    $transactions = iterator_to_array($this->client->transactions('access-123', 'acc_1'));

    expect($transactions)->toHaveCount(101);
    expect($transactions[100]['id'])->toBe('tx_101');

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'since=tx_100'));
});

test('transactions stop immediately on an empty first page', function () {
    Http::fake(['api.monzo.com/transactions*' => Http::response(['transactions' => []])]);

    expect(iterator_to_array($this->client->transactions('access-123', 'acc_1')))->toBeEmpty();

    Http::assertSentCount(1);
});

test('transactions request the expanded merchant', function () {
    Http::fake(['api.monzo.com/transactions*' => Http::response(['transactions' => []])]);

    iterator_to_array($this->client->transactions('access-123', 'acc_1'));

    Http::assertSent(fn (Request $request) => str_contains(urldecode($request->url()), 'expand[]=merchant'));
});

test('a server error is retried before giving up', function () {
    Http::fake(['api.monzo.com/accounts*' => Http::response(['code' => 'internal'], 500)]);

    expect(fn () => $this->client->accounts('access-123'))->toThrow(MonzoException::class);

    Http::assertSentCount(3);
});

test('a client error is not retried because a decision is not a blip', function () {
    Http::fake(['api.monzo.com/accounts*' => Http::response(['code' => 'bad_request'], 400)]);

    expect(fn () => $this->client->accounts('access-123'))->toThrow(MonzoException::class);

    Http::assertSentCount(1);
});
