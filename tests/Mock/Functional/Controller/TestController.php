<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Tests\Mock\Functional\Controller;

use Danilovl\OpenTelemetryBundle\Instrumentation\Attribute\{
    Traceable,
    TraceableHandler
};
use Symfony\Component\HttpFoundation\{
    JsonResponse,
    Request,
    Response
};

class TestController
{
    public function home(): Response
    {
        return new Response('OK', Response::HTTP_OK);
    }

    #[Traceable(name: 'api.users', attributes: ['resource' => 'users'], handler: TraceableHandler::CONTROLLER)]
    public function apiUsers(Request $request): JsonResponse
    {
        return new JsonResponse(['users' => [['id' => 1, 'name' => 'Alice']]]);
    }

    #[Traceable(
        name: 'api.attributes',
        attributes: [
            'string' => 'value',
            'bool' => true,
            'int' => 42,
            'float' => 3.14,
            'array' => ['a', 'b']
        ],
        handler: TraceableHandler::CONTROLLER
    )]
    public function apiAttributes(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }

    public function wdtToolbar(Request $request, ?string $token = null): Response
    {
        return new Response('toolbar:' . ($token ?? ''), Response::HTTP_OK);
    }
}
