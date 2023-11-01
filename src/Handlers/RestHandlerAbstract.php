<?php

namespace Gems\Api\Handlers;

use Gems\Api\Exception\IncorrectDataException;
use Gems\Api\Middleware\ApiAuthenticationMiddleware;
use Laminas\Diactoros\Response\JsonResponse;
use Mezzio\Router\RouteResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Server\RequestHandlerInterface;

abstract class RestHandlerAbstract implements RequestHandlerInterface
{
    /**
     * @var string Current Method
     */
    protected string $method = 'GET';

    /**
     * @var array Current route options
     */
    protected array $routeOptions = [];

    /**
     * @var int|null Current User ID
     */
    protected ?int $userId;

    /**
     * @var string|null Current User login name
     */
    protected ?string $userName;

    /**
     * @var int|null Current user base organization
     */
    protected ?int $userOrganization;

    protected function initUserAtributesFromRequest(ServerRequestInterface $request): void
    {
        $this->userId = $request->getAttribute(ApiAuthenticationMiddleware::CURRENT_USER_ID);
        $this->userName = $request->getAttribute(ApiAuthenticationMiddleware::CURRENT_USER_NAME);
        $this->userOrganization = $request->getAttribute(ApiAuthenticationMiddleware::CURRENT_USER_ORGANIZATION);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $method = strtolower($request->getMethod());
        $path = $request->getUri()->getPath();

        $routeResult = $request->getAttribute(RouteResult::class);
        $route = $routeResult->getMatchedRoute();
        $this->routeOptions = $route->getOptions();

        if ($method != 'options'
            && isset($this->routeOptions['methods'])
            &&!in_array($request->getMethod(), $this->routeOptions['methods'])
        ) {
                return new EmptyResponse(405);
        }

        $this->initUserAtributesFromRequest($request);

        if (($method == 'get') && (str_ends_with($path, '/structure'))) {
            if (method_exists($this, 'structure')) {
                return $this->structure();
            }
        } elseif (method_exists($this, $method)) {
            $this->method = $method;
            try {
                return $this->$method($request);
            } catch(IncorrectDataException $e) {
                return new JsonResponse([
                    'message' => $e->getMessage(),
                ], 400);
            }
        }

        return new EmptyResponse(501);
    }
}
