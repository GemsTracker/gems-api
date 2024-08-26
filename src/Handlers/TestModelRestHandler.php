<?php

declare(strict_types=1);

namespace Gems\Api\Handlers;

use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class TestModelRestHandler extends ModelRestHandler
{
    public function getOne(mixed $id, ServerRequestInterface $request): ResponseInterface
    {
        $idField = $this->getIdField();
        if ($idField) {
            $filter = $this->getIdFilter($id, $idField, $request);

            $row = $this->model->loadFirst($filter);
            $this->logRequest($request, $row);
            if (!empty($row)) {
                $translatedRow = $this->modelApiHelper->translateRow($this->model->getMetaModel(), $row);
                $filteredRow = $this->filterColumns($translatedRow);
                return new JsonResponse($filteredRow);
            }
        }

        /**
         * @var \Mezzio\Router\RouteResult $routeResult
         */
        $routeResult = $request->getAttribute('Mezzio\Router\RouteResult');
        $route = $routeResult->getMatchedRoute();
        $name = $route->getName();
        file_put_contents('data/logs/echo.txt', __FUNCTION__ . '(' . __LINE__ . '): ' . "$name -> " . print_r($filter, true) . "\n", FILE_APPEND);

        return new EmptyResponse(400);
    }
}