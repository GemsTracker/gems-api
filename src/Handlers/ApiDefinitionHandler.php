<?php

namespace Gems\Api\Handlers;

use Gems\Api\Repository\ApiDefinitionRepository;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ApiDefinitionHandler implements RequestHandlerInterface
{
    public function __construct(
        protected readonly ApiDefinitionRepository $apiDefinitionRepository,
    )
    {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $definition = $this->apiDefinitionRepository->getDefinition($request);
        return new JsonResponse($definition, 200);
    }
}