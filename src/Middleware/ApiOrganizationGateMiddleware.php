<?php

namespace Gems\Api\Middleware;

use Gems\Repository\OrganizationRepository;
use Laminas\Permissions\Acl\Acl;
use Mezzio\Router\RouteResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ApiOrganizationGateMiddleware implements MiddlewareInterface
{
    public function __construct(
        protected readonly OrganizationRepository $organizationRepository,
        protected readonly Acl $acl,
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
            return $handler->handle($request);
        }

        $method = $request->getMethod();
        if ($method == 'GET') {
            $allowedOrganizationIds = $this->getAllowedOrganizations($request);

            $filters = $request->getQueryParams();
            $filters = $this->getRouteFilters($filters, $routeOptions, $allowedOrganizationIds);
            $request = $request->withQueryParams($filters);
        }

        return $handler->handle($request);
    }

    protected function getAllowedOrganizations(ServerRequestInterface $request): array
    {
        $userRole = $request->getAttribute(ApiAuthenticationMiddleware::CURRENT_USER_ROLE);
        if ($userRole && $this->acl->isAllowed($userRole, 'pr.organization-switch')) {
            return array_keys($this->organizationRepository->getOrganizations());
        }

        $currentOrganizationId = $request->getAttribute(ApiAuthenticationMiddleware::CURRENT_USER_ORGANIZATION);
        return array_keys($this->organizationRepository->getAllowedOrganizationsFor($currentOrganizationId));
    }

    protected function getRouteFilters(array $filters, array $routeOptions, array $allowedOrganizationIds): array
    {
        // Add allowed organizations to filter by default
        $filters[$routeOptions['organizationIdField']] = $allowedOrganizationIds;
        if (isset($filters[$routeOptions['organizationIdField']])) {
            // Verify queried organizations are allowed
            $selectedOrganizationIds = $filters[$routeOptions['organizationIdField']];
            if (is_string($selectedOrganizationIds)) {
                $selectedOrganizationIds = explode(',', str_replace(['[', ']'], '', $selectedOrganizationIds));
            }
            $filteredOrganizationIds = array_intersect($selectedOrganizationIds, $allowedOrganizationIds);
            foreach ($selectedOrganizationIds as $organizationId) {
                if (in_array($organizationId, $allowedOrganizationIds)) {
                    $filteredOrganizationIds[] = $organizationId;
                }
            }
            $filters[$routeOptions['organizationIdField']] = $filteredOrganizationIds;
        }

        return $filters;
    }
}