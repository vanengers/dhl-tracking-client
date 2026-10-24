<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Tests\Unit\Http;

use GPWebshops\ShopHub\Dhl\Exception\AuthenticationException;
use GPWebshops\ShopHub\Dhl\Exception\BadRequestException;
use GPWebshops\ShopHub\Dhl\Exception\QuotaExceededException;
use GPWebshops\ShopHub\Dhl\Exception\RateLimitException;
use GPWebshops\ShopHub\Dhl\Exception\ResponseFormatException;
use GPWebshops\ShopHub\Dhl\Exception\ServerException;
use GPWebshops\ShopHub\Dhl\Exception\TransportException;
use GPWebshops\ShopHub\Dhl\Query\TrackingQuery;
use GPWebshops\ShopHub\Dhl\Status\DeliveryState;
use GPWebshops\ShopHub\Dhl\Tests\Support\MockApi;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * HTTP behaviour through the public client, against Guzzle's MockHandler.
 */
final class TransportTest extends TestCase
{
    public function testSendsKeyHeaderAndQuery(): void
    {
        $api = new MockApi([MockApi::fixture('parcel-nl-pre-transit')], ['requesterCountryCode' => 'NL']);

        $api->client->shipments(new TrackingQuery(' jvgl 0619 1357 0022 2222 2222 ', '1234 ab', 'parcel-nl'));

        $request = $api->request();
        self::assertSame('GET', $request->getMethod());
        self::assertSame('https://api-sandbox.dhl.com/track/shipments', (string) $request->getUri()->withQuery(''));
        self::assertSame(MockApi::API_KEY, $request->getHeaderLine('DHL-API-Key'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame([
            'trackingNumber' => 'JVGL06191357002222222222',
            'service' => 'parcel-nl',
            'recipientPostalCode' => '1234AB',
            'requesterCountryCode' => 'NL',
            'language' => 'en',
        ], $api->query());
        self::assertStringNotContainsString(MockApi::API_KEY, (string) $request->getUri());
    }

    public function testNotFoundIsAStatusNotAnError(): void
    {
        $api = new MockApi([MockApi::problem(404, 'No result found', 'No shipment with given tracking number found.')]);

        self::assertSame([], $api->client->shipments('UNKNOWN123'));
        $status = (new MockApi([MockApi::problem(404, 'No result found', 'No shipment with given tracking number found.')]))->client->status('UNKNOWN123');
        self::assertSame(DeliveryState::NotFound, $status->state);
    }

    public function testBadKeyIsAuthenticationErrorWithoutRetry(): void
    {
        $api = new MockApi([MockApi::problem(401, 'Unauthorized', 'Access to the resource is not allowed.')]);

        try {
            $api->client->status('JVGL1');
            self::fail('Expected AuthenticationException');
        } catch (AuthenticationException $e) {
            self::assertSame(401, $e->status);
            self::assertSame('Access to the resource is not allowed.', $e->detail);
            self::assertSame('corr-401', $e->getContext()?->correlationId);
            self::assertStringContainsString('Access to the resource is not allowed', $e->getMessage());
            self::assertStringNotContainsString(MockApi::API_KEY, $e->getMessage());
        }
        self::assertCount(1, $api->history);
    }

    public function testBadRequest(): void
    {
        $api = new MockApi([MockApi::problem(400, 'Bad request', 'Invalid service')]);

        $this->expectException(BadRequestException::class);
        $api->client->status('JVGL1');
    }

    public function testDailyQuotaIsNeverRetried(): void
    {
        $api = new MockApi([MockApi::json(['fault' => ['faultstring' => 'Rate limit quota violation. Quota limit exceeded.']], 429)]);

        try {
            $api->client->status('JVGL1');
            self::fail('Expected QuotaExceededException');
        } catch (QuotaExceededException $e) {
            self::assertStringContainsString('24:00 GMT', $e->getMessage());
        }
        self::assertCount(1, $api->history);
        self::assertSame([], $api->clock->sleeps);
    }

    public function testSpikeArrestWaitsAndRetriesOnce(): void
    {
        $api = new MockApi([
            MockApi::json(['fault' => ['faultstring' => 'Spike arrest violation']], 429, ['Retry-After' => '3']),
            MockApi::fixture('parcel-nl-pre-transit'),
        ]);

        $status = $api->client->status('JVGL06191357002222222222');

        self::assertSame(DeliveryState::PreTransit, $status->state);
        self::assertCount(2, $api->history);
        // Retry-After (3s), then the throttle tops it up to the 5s minimum interval.
        self::assertSame([3.0, 2.0], $api->clock->sleeps);
    }

    public function testSpikeArrestGivesUpAfterMaxAttempts(): void
    {
        $api = new MockApi([MockApi::problem(429, 'Too many requests', 'Too many requests'), MockApi::problem(429, 'Too many requests', 'Too many requests')]);

        try {
            $api->client->status('JVGL1');
            self::fail('Expected RateLimitException');
        } catch (RateLimitException $e) {
            self::assertNotInstanceOf(QuotaExceededException::class, $e);
            self::assertSame(2, $e->getContext()?->attempts);
        }
        self::assertCount(2, $api->history);
    }

    public function testLongRetryAfterIsNotWaitedFor(): void
    {
        $api = new MockApi([MockApi::problem(429, 'Too many requests', 'Too many requests', ['Retry-After' => '3600'])]);

        try {
            $api->client->status('JVGL1');
            self::fail('Expected RateLimitException');
        } catch (RateLimitException $e) {
            self::assertSame(3600.0, $e->retryAfter);
        }
        self::assertSame([], $api->clock->sleeps);
    }

    public function testServerErrorIsRetriedWithBackoff(): void
    {
        $api = new MockApi([new Response(503), MockApi::fixture('parcel-nl-pre-transit')], ['minInterval' => 0.0]);

        self::assertSame(DeliveryState::PreTransit, $api->client->status('JVGL06191357002222222222')->state);
        self::assertSame([2.0], $api->clock->sleeps);
    }

    public function testInternalServerErrorIsNotRetried(): void
    {
        $api = new MockApi([MockApi::problem(500, 'Internal error', 'Boom')]);

        $this->expectException(ServerException::class);
        try {
            $api->client->status('JVGL1');
        } finally {
            self::assertCount(1, $api->history);
        }
    }

    public function testConnectionErrorIsRetriedThenThrown(): void
    {
        $request = new Request('GET', 'https://api-sandbox.dhl.com/track/shipments');
        $api = new MockApi([new ConnectException('Could not resolve host', $request), new ConnectException('Connection timed out after 5001 milliseconds', $request)]);

        try {
            $api->client->status('JVGL1');
            self::fail('Expected TransportException');
        } catch (TransportException $e) {
            self::assertTrue($e->timedOut);
            self::assertSame(2, $e->getContext()?->attempts);
        }
    }

    public function testHtmlBodyIsAFormatError(): void
    {
        $api = new MockApi([new Response(200, ['Content-Type' => 'text/html'], '<html>Maintenance</html>')]);

        $this->expectException(ResponseFormatException::class);
        $this->expectExceptionMessage('HTML response');
        $api->client->status('JVGL1');
    }

    public function testMalformedJsonIsAFormatError(): void
    {
        $api = new MockApi([new Response(200, [], '{"shipments": [')]);

        $this->expectException(ResponseFormatException::class);
        $this->expectExceptionMessage('malformed JSON');
        $api->client->status('JVGL1');
    }

    public function testMissingShipmentsListIsAFormatError(): void
    {
        $api = new MockApi([MockApi::json(['url' => '/track/shipments'])]);

        $this->expectException(ResponseFormatException::class);
        $api->client->status('JVGL1');
    }

    public function testCallsAreThrottled(): void
    {
        $api = new MockApi([MockApi::fixture('parcel-nl-pre-transit'), MockApi::fixture('parcel-nl-pre-transit'), MockApi::fixture('parcel-nl-pre-transit')]);

        $api->client->status('JVGL06191357002222222222');
        $api->clock->advance(1.5);
        $api->client->status('JVGL06191357002222222222');
        $api->client->status('JVGL06191357002222222222');

        self::assertSame([3.5, 5.0], $api->clock->sleeps);
    }
}
