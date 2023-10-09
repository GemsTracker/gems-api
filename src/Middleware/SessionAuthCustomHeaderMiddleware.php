<?php

namespace Gems\Api\Middleware;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class SessionAuthCustomHeaderMiddleware implements MiddlewareInterface
{
    public const VUE_CUSTOM_REQUEST_HEADER = 'X-gems-vue';

    public array $mutationMethods = [
        'POST',
        'PUT',
        'PATCH',
        'DELETE'
    ];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!in_array($request->getMethod(), $this->mutationMethods)) {
            return $handler->handle($request);
        }

        if ($request->getAttribute(ApiAuthenticationMiddleware::AUTH_TYPE) !== 'session') {
            return $handler->handle($request);
        }


        if ($request->hasHeader(static::VUE_CUSTOM_REQUEST_HEADER) && $request->getHeader(static::VUE_CUSTOM_REQUEST_HEADER) == 1) {
            return $handler->handle($request);
        }

        return new JsonResponse([
            'error' => 'Not allowed in session login',
            ], 403
        );
    }
}