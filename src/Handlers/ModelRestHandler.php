<?php

namespace Gems\Api\Handlers;

use Gems\Api\Exception\ModelException;
use Mezzio\Router\RouteResult;
use MUtil\Model\ModelAbstract;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zalt\Loader\DependencyResolver\ConstructorDependencyResolver;

class ModelRestHandler extends ModelRestHandlerAbstract
{
    protected ?array $applySettings = null;

    protected bool $constructor = false;

    protected int $itemsPerPage = 5;

    protected ?string $modelName = null;

    protected function createModel(): ModelAbstract
    {
        if ($this->model instanceof ModelAbstract) {
            return $this->model;
        }

        if (!$this->modelName) {
            throw new ModelException('No model or model name set');
        }

        if ($this->constructor) {
            $loader = clone $this->loader;
            $loader->setDependencyResolver(new ConstructorDependencyResolver());
            /**
             * @var ModelAbstract $model
             */
            $model = $loader->create($this->modelName);
        } else {
            /**
             * @var ModelAbstract $model
             */
            $model = $this->loader->create($this->modelName);
        }



        if ($this->applySettings) {
            foreach($this->applySettings as $methodName) {
                if (method_exists($model, $methodName)) {
                    $model->$methodName();
                }
            }
        }

        return $model;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $routeResult = $request->getAttribute(RouteResult::class);
        $route = $routeResult->getMatchedRoute();
        if ($route) {
            $options = $route->getOptions();
            if (isset($options['model'])) {
                $this->setModelName($options['model']);

                if (isset($options['applySettings'])) {
                    if (is_string($options['applySettings'])) {
                        $options['applySettings'] = [$options['applySettings']];
                    }
                    $this->applySettings = $options['applySettings'];
                }

                if (isset($options['constructor']) && $options['constructor'] === true) {
                    $this->constructor = true;
                }
            }
            if (isset($options['itemsPerPage'])) {
                $this->setItemsPerPage($options['itemsPerPage']);
            }
            if (isset($options['idField'])) {
                $this->idField = $options['idField'];
            }
        }

        return parent::handle($request);
    }

    /**
     * Set the name of the model you want to load
     * @param string|ModelAbstract namespaced classname, project loader classname or actual class of a model
     */
    public function setModelName(ModelAbstract|string $modelName): void
    {
        if (is_string($modelName)) {
            $this->modelName = $modelName;
        } elseif ($modelName instanceof ModelAbstract) {
            $this->model = $modelName;
        }
    }

    public function setItemsPerPage(int $itemsPerPage): void
    {
        $this->itemsPerPage = $itemsPerPage;
    }
}
