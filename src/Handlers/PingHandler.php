<?php

namespace Gems\Api\Handlers;

use DateTimeImmutable;
use DateTimeInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class PingHandler implements RequestHandlerInterface
{

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $now = new DateTimeImmutable();
        return new JsonResponse(
            [
                'message' => 'hello!',
                'current-time' => $now->format(DateTimeInterface::ATOM),
            ],
            200
        );
    }
}