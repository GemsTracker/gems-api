<?php

namespace Gems\Api\Repository;

use FastRoute\RouteParser\Std;
use Gems\Api\Model\ModelApiHelper;
use ReflectionClass;
use Gems\Api\Handlers\ModelRestHandlerAbstract;
use Gems\Api\Handlers\RestHandlerAbstract;
use Gems\Menu\RouteHelper;
use Psr\Http\Message\ServerRequestInterface;
use Zalt\Loader\ProjectOverloader;
use Zalt\Model\Data\DataReaderInterface;

class ApiDefinitionRepository
{
    public const OPEN_API_VERSION = '3.1.0';

    public function __construct(
        protected readonly array $config,
        protected readonly RouteHelper $routeHelper,
        protected readonly ProjectOverloader $projectOverloader,
        protected readonly ModelApiHelper $modelApiHelper,
    )
    {
    }

    public function getDefinition(ServerRequestInterface $request): array
    {
        $definition = [
            ...$this->getHeader($request),
            ...$this->getPaths(),
            ...$this->getSecuritySchemes(),
        ];

        return $definition;
    }

    protected function getAllowedRoutes(): array
    {
        $routes = [
            'api.ping',
            'survey-questions',
            'api.appointment.GET',
        ];
        $allowedRoutes = [];
        foreach($routes as $routeName) {
            $routeConfig = $this->routeHelper->getRoute($routeName);
            if ($routeConfig) {
                $allowedRoutes[] = $routeConfig;
            }
        }

        return $allowedRoutes;
    }

    protected function getHeader(ServerRequestInterface $request): array
    {
        $title = 'API';
        if (isset($this->config['app']['name'])) {
            $title = $this->config['app']['name'] . ' API';
        }

        $uri = $request->getUri();
        $url = $uri->getScheme() . '://' . $uri->getHost() . '/api';

        $header = [
            'openapi' => static::OPEN_API_VERSION,
            'info' => [
                'title' => $title,
                'version' => '1',
            ],
            'servers' => [
                ['url' => $url],
            ],
        ];

        if (isset($this->config['app']['description'])) {
            $header['info']['description'] = $this->config['app']['description'];
        }

        return $header;
    }

    protected function getListUrlParams(): array
    {
        return [
            [
                'name' => 'per_page',
                'in' => 'query',
                'description' => 'Items per page',
                'schema' => [
                    'type' => 'integer',
                    'format' => 'int32',
                ],
            ],
            [
                'name' => 'page',
                'in' => 'query',
                'description' => 'Page number',
                'schema' => [
                    'type' => 'integer',
                    'format' => 'int32',
                ],
            ],
            [
                'name' => 'order',
                'in' => 'query',
                'description' => 'Order items by. Comma separated for multiple values. Either end with " DESC" or prefixed with - for descending order',
                'schema' => [
                    'type' => 'string',
                ],
            ]
        ];
    }

    protected function getModel(array $routeInfo): DataReaderInterface
    {
        $modelClassName = $routeInfo['options']['model'];
        $applySettings = $routeInfo['options']['applySettings'] ?? [];


        $model = $this->projectOverloader->create($modelClassName);
        foreach($applySettings as $applyMethodName) {
            if (method_exists($model, $applyMethodName)) {
                $model->$applyMethodName();
            }
        }
        $this->modelApiHelper->applyAllowedColumnsToModel($model->getMetaModel(), $routeInfo['options']);

        return $model;
    }

    protected function getModelSimpleName(string $fullClassName): string
    {
        return str_replace(['\\', '_'], '', $fullClassName);
    }

    protected function getPaths(): array
    {
        $routes = $this->getAllowedRoutes();

        $paths = [];

        foreach($routes as $route) {
            $handler =  end($route['middleware']);
            $reflector = new ReflectionClass($handler);

            $path = null;
            if ($reflector->isSubclassOf(ModelRestHandlerAbstract::class)) {
                $paths = array_merge($paths, $this->getPathFromModelHandler($route, $reflector));
                continue;
            }
            if ($reflector->isSubclassOf(RestHandlerAbstract::class)) {
                $paths = array_merge($paths, $this->getPathFromBaseHandler($route, $reflector));
                continue;
            }
            if (str_starts_with($route['name'], 'api.')) {
                $paths = array_merge($paths, $this->getPathFromBaseHandler($route, $reflector));
            }
        }

        return ['paths' => $paths];
    }

    protected function getPathFromBaseHandler(array $route, ReflectionClass $reflector): array
    {
        $pathName = $this->getTranslatedPath($route['path']);

        $definitionInfo = null;
        $methodInfo = [];

        if ($reflector->hasProperty('definition')) {
            $definitionProperty = $reflector->getProperty('definition');
            if ($definitionProperty->isStatic()) {
                $definitionInfo = $definitionProperty->getValue();
            }
        }

        if ($definitionInfo === null) {
            foreach($route['allowed_methods'] as $method) {
                $methodInfo[$method] = [
                    'summary' => strtolower($method),
                    'tags' => ['other'],
                ];
            }
        }

        $pathData = [
            $pathName => $methodInfo,
        ];

        return $pathData;
    }

    protected function getPathFromModelHandler(array $routeInfo, ReflectionClass $handlerReflector): array
    {
        $model = $this->getModel($routeInfo);

        $structure = $this->modelApiHelper->getStructure($model->getMetaModel());

        $modelSimpleName = $this->getModelSimpleName($routeInfo['options']['model']);


        $paths = [];

        foreach($routeInfo['allowed_methods'] as $method) {
            if ($method === 'GET') {                    // Get url
                $idField = $routeInfo['options']['idField'] ?? null;
                $baseRoute = $this->getTranslatedPath($routeInfo['path'], $idField);
                $baseUrlParams = $this->getUrlParamSchema($routeInfo['path'], $idField);
                $listUrlParams = $this->getListUrlParams();

                $paths[$baseRoute] = [
                    'get' => [
                        'parameters' => $baseUrlParams + $listUrlParams,
                        'responses' => [
                            '200' => [
                                'description' => 'get items',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            '$ref' => "#/components/schemas/" . $modelSimpleName . 's',
                                        ],
                                    ],
                                ],
                            ],
                            '204' => [
                                'description' => 'no items',
                            ],
                        ],
                    ],
                ];

                $route = $this->getTranslatedPath($routeInfo['path']);
                $urlParams = $this->getUrlParamSchema($routeInfo['path']);

                $paths[$route] = [
                    'get' => [
                        'parameters' => $urlParams,
                        'responses' => [
                            '200' => [
                                'description' => 'Get a specific item',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            '$ref' => "#/components/schemas/" . $modelSimpleName,
                                        ],
                                    ],
                                ],
                            ],
                            '404' => [
                                'description' => 'Item not found',
                            ],
                        ]
                    ],
                ];
            }
        }


        return $paths;
    }

    protected function getSecuritySchemes(): array
    {
        $schemes = [];

        $schemes['OAuth2'] = [
            'type' => 'oauth2',
            'description' => 'oauth 2 auth with [thephpleague/oauth2-server](https://oauth2.thephpleague.com/)',
            'flows' => [
                'password' => [
                    'tokenUrl' => '/access_token',
                    'refreshUrl' => '/access_token',
                    'scopes' => ['all' => 'all available resources'],
                ],
                /*'authorizationCode' => [
                    'authorizationUrl' => '/authorize',
                    'tokenUrl' => '/access_token',
                    'refreshUrl' => '/access_token',
                    'scopes' => ['all' => 'all available resources'],
                ],*/
            ]
        ];

        $security = [['OAuth2' => ['all']]];

        return [
            'security' => $security,
            'components' => [
                'securitySchemes' => $schemes,
            ],
        ];
    }

    protected function getRoutePathParts(string $path): array
    {
        $routeParser = new Std();
        $parsedRoute = $routeParser->parse($path);
        return end($parsedRoute);
    }

    protected function getUrlParamSchema(string $path, string|null $excludeParam = null): array
    {
        $allParts = $this->getRoutePathParts($path);

        $urlParamSchema = [];
        foreach($allParts as $part) {
            if (!is_array($part)) {
                continue;
            }

            list($fieldName, $regex) = $part;

            if ($fieldName === $excludeParam) {
                continue;
            }

            $paramSchema = ['type' => 'integer', 'format' => 'int64'];
            if ($regex != '\d+') {
                $paramSchema = ['type' => 'string'];
            }
            $urlParamSchema[] = [
                'name' => $fieldName,
                'in' => 'path',
                'required' => true,
                'schema' => $paramSchema,
            ];
        }

        return $urlParamSchema;
    }

    protected function getTranslatedPath(string $path, string|null $excludeParam = null): string
    {
        $allParts = $this->getRoutePathParts($path);
        $translatedPath = '';
        foreach($allParts as $part) {
            if (is_array($part)) {
                if ($part[0] === $excludeParam) {
                    continue;
                }
                $translatedPath .= '{'.$part[0].'}';
                continue;
            }
            $translatedPath .= $part;
        }



        return rtrim($translatedPath, '/');
    }

}