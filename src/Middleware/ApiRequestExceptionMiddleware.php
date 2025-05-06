<?php

declare(strict_types=1);

namespace Gems\Api\Middleware;

use Gems\Exception\SymfonyValidatorException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;

class ApiRequestExceptionMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $response = $handler->handle($request);
            return $response;
        } catch (SymfonyValidatorException $e) {
            return new JsonResponse([
                'error' => 'validation_error',
                'message' => $e->getFormattedViolations(),
            ], 422);
        } catch (SerializerExceptionInterface $e) {
            return new JsonResponse([
                'error' => 'validation_error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}