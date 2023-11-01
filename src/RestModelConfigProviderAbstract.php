<?php

namespace Gems\Api;

use Gems\Api\Handlers\ModelRestHandler;
use Gems\Api\Middleware\ApiAuthenticationMiddleware;
use Gems\Api\Middleware\SessionAuthCustomHeaderMiddleware;
use Gems\Middleware\FlashMessageMiddleware;
use Gems\Middleware\LegacyCurrentUserMiddleware;
use Gems\Middleware\LocaleMiddleware;
use Gems\Middleware\SecurityHeadersMiddleware;
use Gems\Util\RouteGroupTrait;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionMiddleware;

abstract class RestModelConfigProviderAbstract
{
    use RouteGroupTrait;

    protected string $defaultHandler = ModelRestHandler::class;

    protected string $defaultIdField = 'id';

    protected string $defaultIdRegex = '\d+';

    public function __construct(protected string $pathPrefix = '/api')
    {}

    public function __invoke()
    {
        return [
            'routes' => $this->routeGroup([
                'path' => $this->pathPrefix,
                'middleware' => $this->getMiddleware(),
            ],
                $this->getRoutes()
            ),
        ];
    }


    /**
     * Get an array of default routing middleware for REST actions with a custom action instead of the default controller
     *
     * @param $customAction string classname of the custom action
     * @return array
     */
    public function getCustomActionMiddleware(string $customAction): array
    {
        $customActionMiddleware = $this->getMiddleware();
        array_pop($customActionMiddleware);
        $customActionMiddleware[] = $customAction;


        return $customActionMiddleware;
    }

    /**
     * Get an array of default routing middleware for Rest actions
     *
     * @return array
     */
    public function getMiddleware(): array
    {
        return [
            SecurityHeadersMiddleware::class,
            SessionMiddleware::class,
            FlashMessageMiddleware::class,
            CsrfMiddleware::class,
            LocaleMiddleware::class,
            ApiAuthenticationMiddleware::class,
            SessionAuthCustomHeaderMiddleware::class,
            LegacyCurrentUserMiddleware::class,
        ];
    }

    protected function createRoute(
        string $name,
        string $path,
        string $handler,
        array $allowedMethods = ['GET'],
        ?array $middleware = null,
        ?array $options = null,
    ): array
    {
        if ($middleware !== null) {
            $middleware[] = $handler;
            $handler = $middleware;
        }

        $route = [
            'name' => $name,
            'path' => $path,
            'middleware' => $handler,
            'allowed_methods' => $allowedMethods,
        ];

        if ($options !== null) {
            $route['options'] = $options;
        }

        return [$route];
    }


    protected function createModelRoute(
        string $endpoint,
        string $model,
        ?bool $constructor = true,
        ?array $applySettings = null,
        ?string $handler = null,
        ?string $idField = null,
        ?string $idRegex = null,
        array $methods = ['GET'],
        ?string $privilege = null,
        ?array $allowedFields = null,
        ?array $allowedSaveFields = null,
        string|array|null $patientIdField = null,
        ?string $respondentIdField = null,
        ?array $multiOranizationField = null,
        ?array $options = null,
    ): array
    {
        if ($idField === null) {
            $idField = $this->defaultIdField;
        }
        if ($idRegex === null) {
            $idRegex = $this->defaultIdRegex;
        }
        if ($handler === null) {
            $handler = $this->defaultHandler;
        }
        if ($privilege === null) {
            $privilege = "pr.api.$endpoint";
        }

        $routeParameters = '/[{' . $idField . ':' . $idRegex . '}]';

        // func_get_args() does not return parameter names
        $settings = array_filter([
            'endpoint' => $endpoint,
            'model' => $model,
            'constructor' => $constructor,
            'applySettings' => $applySettings,
            'handler' => $handler,
            'idField' => $idField,
            'idRegex' => $idRegex,
            'methods' => $methods,
            'privilege' => $privilege,
            'allowedFields' => $allowedFields,
            'allowedSaveFields' => $allowedSaveFields,
            'patientIdField' => $patientIdField,
            'respondentIdField' => $respondentIdField,
            'multiOranizationField' => $multiOranizationField,
        ]);
        if ($options !== null) {
            $settings = array_merge($settings, $options);
        }

        $routes = [];

        if (!empty($methods)) {
            $name = "api.$endpoint.structure";
            $settings['privilege'] = $privilege . '.structure';
            $routes[$name] = [
                'name' => $name,
                'path' => '/' . $endpoint . '/structure',
                'middleware' => $handler,
                'options' => $settings,
                'allowed_methods' => ['GET']
            ];
        }

        foreach($methods as $method) {
            $path = match ($method) {
                'GET' => '/' . $endpoint . '['.$routeParameters.']',
                'POST' => '/' . $endpoint,
                'PATCH', 'PUT', 'DELETE' => '/' . $endpoint . $routeParameters,
                default => null,
            };
            $name = "api.$endpoint.$method";

            $settings['privilege'] = "$privilege.$method";
            $settings['privilegeLabel'] = "API: $endpoint -> $method";

            [$methodRoute] = $this->createRoute(
                name: $name,
                path: $path,
                handler: $handler,
                allowedMethods: [$method],
                options: $settings,
            );
            $routes[$name] = $methodRoute;
        }

        return $routes;
    }

    /**
     * get a list of routes generated from the rest models defined in getRestModels()
     *
     * @return array
     */
    protected function getModelRoutes(): array
    {
        $restModels = $this->getRestModels();

        $routes = [];

        foreach($restModels as $endpoint=>$settings) {
            $settings['endpoint'] = $endpoint;
            $routes = array_merge($routes, $this->createModelRoute(...$settings));
        }

        return $routes;
    }

    /**
     * Get a list of Gemstracker models that should be exposed to the REST API
     * the named keys of the array will be the endpoints.
     * each value is an array with the following required keys and values:
     *  model: (string)The name of the model for this endpoint as registered in the loader
     *  methods: (array with strings) supported methods (e.g. GET, POST, PATCH, DELETE)
     *
     * And the following optional keys and values:
     *  applySettings: (string) the name of the method that applies additional settings to the model (e.g. applyEditSettings)
     *  idField: (string|array) the name of the ID field as used in the url. Needed if it differs from the
     *      primary key of the model. Can also be multiple values if more than one id is needed
     *  idFieldRegex: (string|array) If the ID is not a number the default regex ('\d+') can be changed here.
     *      if multiple id values exist, and one has another regex, supply both regexes in the same order as the idFields
     *  multiOranizationField: (array) If the current model uses one column to store multiple organizations, it can be added here.
     *      supply the field key with the columnname, and the separator key with which separator char has been sewed together
     * organizationIdField: (string) Field name where the organization name is stored, so checks can be done if this organization is allowed
     * respondentIdField: (string) Field name where the respondent id is stored so it can be saved in the access log
     *
     *  Field filters: Keep in mind validation on save will occur after the filter.
     *  allow_fields: (array) List of fields that are allowed to be requested and saved
     *  disallow_fields: (array) List of fields that are not allowed to be requested and saved
     *  readonly_fields: (array) List of fields that are allowed to be requested, but not allowed to be saved
     *
     * @return array
     */
    public function getRestModels(): array
    {
        return [];
    }

    /**
     * Get all Routed including model routes. Add your projects routes in this function of the configProvider of your project.
     *
     * @return array
     */
    public function getRoutes(): array
    {
        return $this->getModelRoutes();
    }
}
