<?php

namespace Gems\Api\Middleware;

use Gems\Repository\OrganizationRepository;
use Mezzio\Router\RouteResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ApiOrganizationGateMiddleware implements MiddlewareInterface
{
    public function __construct(
        protected readonly OrganizationRepository $organizationRepository
    )
    {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /**
         * @var RouteResult $routeResult
         */
        $routeResult = $request->getAttribute(RouteResult::class);
        $route = $routeResult->getMatchedRoute();

        $routeOptions = $route->getOptions();
        if (!isset($routeOptions['organizationIdField']) || empty($routeOptions['organizationIdField'])) {
            $response = $handler->handle($request);
            return $response;
        }

        $method = $request->getMethod();
        if ($method == 'GET') {
            $currentOrganizationId = $request->getAttribute(ApiAuthenticationMiddleware::CURRENT_USER_ORGANIZATION);
            $allowedOrganizationIds = array_keys($this->organizationRepository->getAllowedOrganizationsFor($currentOrganizationId));

            $filters = $request->getQueryParams();
            $filters = $this->getRouteFilters($filters, $routeOptions, $allowedOrganizationIds);
            $request = $request->withQueryParams($filters);
        }

        $response = $handler->handle($request);
        return $response;
    }

    protected function getRouteFilters(array $filters, array $routeOptions, array $allowedOrganizationIds): array
    {
        // Add allowed organizations to filter by default
        if (!isset($filters[$routeOptions['organizationIdField']])) {
            $filters[$routeOptions['organizationIdField']] = $allowedOrganizationIds;
        }

        if (isset($filters[$routeOptions['organizationIdField']])) {
            // Verify queried organizations are allowed
            $selectedOrganizationIds = $filters[$routeOptions['organizationIdField']];
            if (is_string($selectedOrganizationIds)) {
                $selectedOrganizationIds = explode(',', str_replace(['[', ']'], '', $selectedOrganizationIds));
            }
            $filteredOrganizationIds = array_intersect($selectedOrganizationIds, $allowedOrganizationIds);
            $filters[$routeOptions['organizationIdField']] = $filteredOrganizationIds;
        }

        return $filters;
    }
}