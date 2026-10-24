<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Tests\Support;

use GPWebshops\ShopHub\Dhl\Config\TrackingConfig;
use GPWebshops\ShopHub\Dhl\DhlTrackingClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;

/**
 * A client wired to Guzzle's MockHandler and a FakeClock: no network, no real sleeping.
 */
final class MockApi
{
    public const API_KEY = 'test-key-123';

    public readonly DhlTrackingClient $client;

    public readonly FakeClock $clock;

    /** @var \ArrayObject<int, array<string, mixed>> filled by Guzzle's history middleware */
    public \ArrayObject $history;

    /**
     * @param list<Response|\Throwable> $responses
     * @param array<string, mixed>      $config    TrackingConfig overrides
     */
    public function __construct(array $responses, array $config = [], ?LoggerInterface $logger = null)
    {
        $this->clock = new FakeClock();
        $this->history = new \ArrayObject();
        // Guzzle takes the container by reference; hand it a copy of the handle so the typed property stays intact.
        $container = $this->history;
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($container));
        $this->client = DhlTrackingClient::create(TrackingConfig::sandbox(self::API_KEY, $config), $logger, $stack, $this->clock);
    }

    /**
     * @param array<array-key, mixed>  $body
     * @param array<string, string>    $headers
     */
    public static function json(array $body, int $status = 200, array $headers = []): Response
    {
        $type = $status >= 400 ? 'application/problem+json' : 'application/json';

        return new Response($status, ['Content-Type' => $type, 'correlation-id' => 'corr-' . $status] + $headers, json_encode($body, JSON_THROW_ON_ERROR));
    }

    public static function fixture(string $name): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], Fixtures::read($name));
    }

    /**
     * @param array<string, string> $headers
     */
    public static function problem(int $status, string $title, string $detail, array $headers = []): Response
    {
        return self::json(['status' => $status, 'title' => $title, 'detail' => $detail], $status, $headers);
    }

    public function request(int $index = 0): RequestInterface
    {
        $request = $this->history[$index]['request'] ?? null;
        if (!$request instanceof RequestInterface) {
            throw new \OutOfBoundsException('No request #' . $index . ' was sent');
        }

        return $request;
    }

    /**
     * @return array<string, string>
     */
    public function query(int $index = 0): array
    {
        parse_str($this->request($index)->getUri()->getQuery(), $query);

        /** @var array<string, string> $query */
        return $query;
    }
}
