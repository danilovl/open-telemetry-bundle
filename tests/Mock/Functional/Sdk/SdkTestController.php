<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Mock\Functional\Sdk;

use Danilovl\OpenTelemetryBundle\Instrumentation\Attribute\{
    Traceable,
    TraceableHandler
};
use RuntimeException;
use Symfony\Component\HttpFoundation\{
    JsonResponse,
    Response
};
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Cache\{
    CacheInterface,
    ItemInterface
};
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class SdkTestController
{
    public function __construct(
        private CacheInterface $cache,
        private HttpClientInterface $httpClient,
        private MessageBusInterface $bus,
    ) {}

    public function home(): Response
    {
        return new Response('home');
    }

    public function user(string $id): Response
    {
        return new Response('user ' . $id);
    }

    public function error(): Response
    {
        throw new RuntimeException('Something broke');
    }

    public function notFound(): Response
    {
        throw new NotFoundHttpException('nope');
    }

    public function serverErrorResponse(): Response
    {
        return new Response('unavailable', 503);
    }

    public function cache(): Response
    {
        $callback = static function (ItemInterface $item): string {
            $item->expiresAfter(60);

            return 'computed';
        };

        // the array adapter is reset between requests: miss and hit in one request
        $this->cache->get('sdk_test_key', $callback);
        $value = $this->cache->get('sdk_test_key', $callback);

        return new Response($value);
    }

    public function outgoing(): Response
    {
        $response = $this->httpClient->request('GET', 'https://downstream.example.com/api?api_key=secret');

        return new JsonResponse(['downstream' => $response->getContent()]);
    }

    public function dispatch(): Response
    {
        $this->bus->dispatch(new SdkTestMessage('from-http'));

        return new Response('dispatched');
    }

    #[Traceable(name: 'traceable controller', handler: TraceableHandler::CONTROLLER)]
    public function traceable(): Response
    {
        return new Response('traceable');
    }
}
