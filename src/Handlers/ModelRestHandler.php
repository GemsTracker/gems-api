<?php

namespace Gems\Api\Handlers;

use Gems\Api\Exception\ModelException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zalt\Model\Data\DataReaderInterface;

class ModelRestHandler extends ModelRestHandlerAbstract
{
    protected ?array $applySettings = null;

    protected bool $constructor = false;

    protected int $itemsPerPage = 5;

    protected ?string $modelName = null;

    protected function createModel(): DataReaderInterface
    {
        if ($this->model instanceof DataReaderInterface) {
            return $this->model;
        }

        if (!$this->modelName) {
            throw new ModelException('No model or model name set');
        }

        $model = $this->loader->create($this->modelName);

        if ($this->applySettings) {
            foreach($this->applySettings as $methodName) {
                if (method_exists($model, $methodName)) {
                    $model->$methodName();
                }
            }
        }
        if ($model instanceof \Gems\Model\MaskedModel) {
            $model->applyMask();
        }

        return $model;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
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
     * @param string|DataReaderInterface $modelName namespaced classname, project loader classname or actual class of a model
     */
    public function setModelName(DataReaderInterface|string $modelName): void
    {
        if (is_string($modelName)) {
            $this->modelName = $modelName;
        } elseif ($modelName instanceof DataReaderInterface) {
            $this->model = $modelName;
        }
    }

    public function setItemsPerPage(int $itemsPerPage): void
    {
        $this->itemsPerPage = $itemsPerPage;
    }
}
