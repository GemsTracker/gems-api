<?php

namespace Gems\Api\Action;

use Gems\Api\Exception\ModelException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ModelRestController extends ModelRestControllerAbstract
{
    protected ?array $applySettings = null;

    protected int $itemsPerPage = 5;

    protected ?string $modelName = null;

    protected function createModel(): \MUtil_Model_ModelAbstract
    {
        if ($this->model instanceof \MUtil_Model_ModelAbstract) {
            return $this->model;
        }

        if (!$this->modelName) {
            throw new ModelException('No model or model name set');
        }

        /**
         * @var \MUtil_Model_ModelAbstract $model
         */
        $model = $this->loader->create($this->modelName);

        if ($this->applySettings) {
            foreach($this->applySettings as $methodName) {
                if (method_exists($model, $methodName)) {
                    $model->$methodName();
                }
            }
        }

        return $model;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $routeResult = $request->getAttribute('Mezzio\Router\RouteResult');
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
            }
            if (isset($options['itemsPerPage'])) {
                $this->setItemsPerPage($options['itemsPerPage']);
            }
            if (isset($options['idField'])) {
                $this->idField = $options['idField'];
            }
        }

        return parent::process($request, $handler);
    }

    /**
     * Set the name of the model you want to load
     * @param string|\MUtil_Model_ModelAbstract namespaced classname, project loader classname or actual class of a model
     */
    public function setModelName(\MUtil_Model_ModelAbstract|string $modelName): void
    {
        if (is_string($modelName)) {
            $this->modelName = $modelName;
        } elseif ($modelName instanceof \MUtil_Model_ModelAbstract) {
            $this->model = $modelName;
        }
    }

    public function setItemsPerPage(int $itemsPerPage): void
    {
        $this->itemsPerPage = $itemsPerPage;
    }
}
