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
        $queryParams = $request->getQueryParams();

        $currentRole = 'super';
        if (isset($queryParams['role'])) {
            $currentRole = $queryParams['role'];
        }

        $definition = $this->apiDefinitionRepository->getDefinition($request, $currentRole);
        return new JsonResponse($definition, 200);
    }
}