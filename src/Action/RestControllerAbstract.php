<?php

namespace Gems\Api\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

abstract class RestControllerAbstract implements MiddlewareInterface
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
     * @var string|null Current User ID
     */
    protected ?int $userId;

    /**
     * @var string|null Current User login name
     */
    protected ?string $userName;

    /**
     * @var string|null Current user base organization
     */
    protected ?int $userOrganization;

    protected function initUserAtributesFromRequest(ServerRequestInterface $request): void
    {
        $this->userId = $request->getAttribute('user_id');
        $this->userName = $request->getAttribute('user_name');
        $this->userOrganization = $request->getAttribute('user_organization');
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = strtolower($request->getMethod());
        $path = $request->getUri()->getPath();

        $routeResult = $request->getAttribute('Mezzio\Router\RouteResult');
        $route = $routeResult->getMatchedRoute();
        $this->routeOptions = $route->getOptions();

        if ($method != 'options'
            && isset($this->routeOptions['methods'])
            &&!in_array($request->getMethod(), $this->routeOptions['methods'])
        ) {
                return new EmptyResponse(405);
        }

        $this->initUserAtributesFromRequest($request);

        if (($method == 'get') && (substr($path, -10) === '/structure')) {
            if (method_exists($this, 'structure')) {
                return $this->structure($request, $handler);
            }
        } elseif (method_exists($this, $method)) {
            $this->method = $method;


            return $this->$method($request, $handler);
        }

        return new EmptyResponse(501);
    }
}
