<?php


namespace Gems\Api\Action;

use Gems\AccessLog\AccesslogRepository;
use Gems\Api\Event\SavedModel;
use Gems\Api\Event\SaveFailedModel;
use Gems\Api\Event\SaveModel;
use Gems\Api\Exception\ModelException;
use Gems\Api\Exception\ModelValidationException;
use Gems\Api\Model\RouteOptionsModelFilter;
use Gems\Api\Model\Transformer\CreatedChangedByTransformer;
use Gems\Api\Model\Transformer\DateTransformer;
use Gems\Api\Model\Transformer\ValidateFieldsTransformer;
use Mezzio\Router\Exception\InvalidArgumentException;
use MUtil\Model\ModelAbstract;
use MUtil\Model\Type\JsonData;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zalt\Loader\ProjectOverloader;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Exception;
use Mezzio\Helper\UrlHelper;
use Mezzio\Router\RouteResult;
use DateTimeInterface;

abstract class ModelRestControllerAbstract extends RestControllerAbstract
{
    /**
     * @var AccesslogRepository
     */
    protected AccesslogRepository $accesslogRepository;

    /**
     * @var array List of allowed content types as input for write methods
     */
    protected array $allowedContentTypes = ['application/json'];

    /**
     * @var ?array list of translated colnames for the api
     */
    protected ?array $apiNames = null;

    /**
     * @var \Zend_Db_Adapter_Abstract
     */
    protected \Zend_Db_Adapter_Abstract $db1;

    protected EventDispatcherInterface $eventDispatcher;

    /**
     * @var string Fieldname of model that identifies a row with a unique ID
     */
    protected string $idField;

    /**
     * @var int number of items per page for pagination
     */
    protected int $itemsPerPage = 25;

    /**
     * @var ProjectOverloader
     */
    protected ProjectOverloader $loader;

    /**
     * @var ModelAbstract Gemstracker Model
     */
    protected ?ModelAbstract $model = null;

    protected DateTimeInterface|float $requestStart;

    /**
     * @var array list of apiNames but key=>value reversed
     */
    protected ?array $reverseApiNames = null;

    /**
     * @var array list of column structure
     */
    protected array $structure;

    /**
     * @var array list of methods supported by this current controller
     */
    protected array $supportedMethods = [
        'delete',
        'get',
        'options',
        'patch',
        'post',
        'structure',
    ];

    /**
     * @var UrlHelper
     */
    protected UrlHelper $urlHelper;

    /**
     *
     * RestControllerAbstract constructor.
     * @param EventDispatcherInterface $eventDispatcher
     * @param AccesslogRepository $accesslogRepository
     * @param ProjectOverloader $loader
     * @param UrlHelper $urlHelper
     * @param \Zend_Db_Adapter_Abstract $LegacyDb Init Zend DB so it's loaded at least once, needed to set default Zend_Db_Adapter for Zend_Db_Table
     */
    public function __construct(EventDispatcherInterface $eventDispatcher, AccesslogRepository $accesslogRepository, ProjectOverloader $loader, UrlHelper $urlHelper, \Zend_Db_Adapter_Abstract $LegacyDb)
    {
        $this->accesslogRepository = $accesslogRepository;
        $this->loader = $loader;

        $this->urlHelper = $urlHelper;
        $this->db1 = $LegacyDb;

        $this->eventDispatcher = $eventDispatcher;
    }

    protected function addCurrentUserToModel(): void
    {
        \Gems\Model::setCurrentUserId($this->userId);
    }

    /**
     * Do actions or translate the row after a save
     *
     * @param array $newRow
     * @return array
     */
    protected function afterSaveRow(array $newRow): array
    {
        $event = new SavedModel($this->model);
        $event->setNewData($newRow);
        $oldData = [];
        if (method_exists($this->model, 'getOldValues')) {
            $oldData = $this->model->getOldValues();
        }
        $event->setOldData($oldData);
        $event->setStart($this->requestStart);
        $this->eventDispatcher->dispatch($event, 'model.' . $this->model->getName() . '.saved');
        return $newRow;
    }

    /**
     * Do actions or translate the row before a save and before validators
     *
     * @param array $row
     * @return array
     */
    protected function beforeSaveRow(array $row): array
    {
        return $row;
    }

    /**
     * Check if current content type is allowed for the current method
     *
     * @param ServerRequestInterface $request
     * @return bool
     */
    protected function checkContentType(ServerRequestInterface $request): bool
    {
        $contentTypeHeader = $request->getHeaderLine('content-type');
        foreach ($this->allowedContentTypes as $contentType) {
            if (str_contains($contentTypeHeader, $contentType)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create a Gemstracker model
     *
     * @return ModelAbstract
     */
    abstract protected function createModel(): ModelAbstract;

    /**
     * Delete a row from the model
     *
     * @param ServerRequestInterface $request
     * @return EmptyResponse
     */
    public function delete(ServerRequestInterface $request): EmptyResponse
    {
        $id = $request->getAttribute('id');
        $idField = $this->getIdField();
        if ($id === null || !$idField) {
            return new EmptyResponse(404);
        }

        $filter = [
            $idField => $id,
        ];

        if (isset($this->routeOptions['respondent_id_field'])) {
            try {
                $row = $this->model->loadFirst($filter);
                $this->logRequest($request, $row);
            } catch(Exception) {
                return new EmptyResponse(404);
            }
        }

        try {
            $changedRows = $this->model->delete($filter);

        } catch (Exception) {
            return new EmptyResponse(400);
        }

        if ($changedRows == 0) {
            return new EmptyResponse(400);
        }

        return new EmptyResponse(204);
    }

    /**
     * Filter the columns of a row with routeoptions like allowed_fields, disallowed_fields and readonly_fields
     *
     * @param array $row Row with model values
     * @param bool $save Will the row be saved after filter (enables readonly
     * @param bool $useKeys Use keys or values in the filter of the row
     * @return array Filtered array
     */
    protected function filterColumns(array $row, bool $save=false, bool $useKeys=true): array
    {
        $filterOptions = $this->routeOptions;
        $modelAllowFields = $this->model->getColNames('allow_api_load');
        $modelAllowSaveFields = $this->model->getColNames('allow_api_save');
        if ($modelAllowFields && count($modelAllowFields)) {
            if (!isset($filterOptions['allowed_fields'])) {
                $filterOptions['allowed_fields'] = [];
            }
            $filterOptions['allowed_fields'] = array_merge($modelAllowFields, $filterOptions['allowed_fields']);
        }
        if ($modelAllowSaveFields && count($modelAllowSaveFields)) {
            if (!isset($filterOptions['allowed_save_fields'])) {
                $filterOptions['allowed_save_fields'] = [];
            }
            $filterOptions['allowed_save_fields'] = array_merge($modelAllowSaveFields, $filterOptions['allowed_save_fields']);
        }

        return RouteOptionsModelFilter::filterColumns($row, $filterOptions, $save, $useKeys);
    }

    protected function flipMultiArray(array $array): array
    {
        $flipped = [];
        foreach($array as $key=>$value)
        {
            if (is_array($value)) {
                $flipped[$key] = $this->flipMultiArray($value);
            } else {
                $flipped[$value] = $key;
            }
        }
        return $flipped;
    }

    /**
     * Get one or multiple rows from the model
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function get(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->getId($request);

        if ($id !== null) {
            return $this->getOne($id, $request);
        } else {
            return $this->getList($request);
        }
    }

    /**
     * Get the allowed filter fields, null if all is allowed
     *
     * @return string[]
     */
    protected function getAllowedFilterFields(): array
    {
        return $this->model->getItemNames();
    }

    /**
     * Get the api column names translations if they are set
     *
     * @param bool $reverse return the reversed translations
     * @return array
     */
    protected function getApiNames(bool $reverse=false): array
    {
        if (!$this->apiNames) {
            $this->apiNames = $this->getApiSubModelNames($this->model);
        }

        if ($reverse) {
            if (!$this->reverseApiNames) {
                $this->reverseApiNames = $this->flipMultiArray($this->apiNames);
            }
            return $this->reverseApiNames;
        }

        return $this->apiNames;
    }

    protected function getApiSubModelNames(ModelAbstract $model): array
    {
        $apiNames = $this->model->getCol('apiName');

        $subModels = $model->getCol('model');
        foreach($subModels as $subModelName=>$subModel) {
            $apiNames[$subModelName] = $this->getApiSubModelNames($subModel);
        }
        return $apiNames;
    }

    /**
     * Get the ID from the request. e.g. a route to /items/5 will return 5
     *
     * @param ServerRequestInterface $request
     * @return array|mixed|null
     */
    protected function getId(ServerRequestInterface $request): mixed
    {
        if (isset($this->routeOptions['idField'])) {
            if (is_array($this->routeOptions['idField'])) {
                $id = [];
                foreach($this->routeOptions['idField'] as $idField) {
                    if ($request->getAttribute($idField)) {
                        $id[] = $request->getAttribute($idField);
                    }
                }
                if ($id === []) {
                    $id = null;
                }
            } else {
                $id = $request->getAttribute($this->routeOptions['idField']);
            }

        } else {
            $id = $request->getAttribute('id');
        }

        return $id;
    }

    /**
     * Get the id field of the model if it is set in the model keys
     *
     * @return string Fieldname
     */
    protected function getIdField(): string
    {
        if (!$this->idField) {
            $keys = $this->model->getKeys();
            if (isset($keys['id'])) {
                $this->idField = $keys['id'];
            }
        }

        return $this->idField;
    }

    /**
     * Return a filter that has the current models id field or fields as parameters set.
     *
     * @param string|int|array $id
     * @param string|int|array $idField
     * @return array
     */
    protected function getIdFilter(mixed $id, mixed $idField): array
    {
        if (!is_array($id)) {
            $id = [$id];
        }
        if (!is_array($idField)) {
            $idField = [$idField];
        }

        $apiNames = $this->getApiNames(true);

        $filter = [];
        foreach($idField as $key=>$singleField) {
            if (isset($apiNames[$singleField])) {
                $singleField = $apiNames[$singleField];
            }
            $filter[$singleField] = $id[$key];
        }

        return $filter;
    }

    /**
     * Get a list of items from the model, filtered in the request attributes
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function getList(ServerRequestInterface $request): ResponseInterface
    {
        $filters = $this->getListFilter($request);
        $order = $this->getListOrder($request);
        $paginatedFilters = $this->getListPagination($request, $filters);
        $headers = $this->getPaginationHeaders($request, $filters);
        if ($headers === false) {
            return new EmptyResponse(204);
        }

        $rows = $this->model->load($paginatedFilters, $order);

        $translatedRows = [];
        foreach($rows as $key=>$row) {
            $translatedRows[$key] = $this->filterColumns($this->translateRow($row));
        }

        return new JsonResponse($translatedRows, 200, $headers);
    }

    /**
     * Get all filters set in the request attributes used for listing model items with a GET request
     *
     * most common just the columnName=>value
     * values in [] brackets will be checked on special characters <, > <=, >=, LIKE, NOT LIKE for specific operations
     *
     * @param ServerRequestInterface $request
     * @return array
     */
    public function getListFilter(ServerRequestInterface $request): array
    {
        $params = $request->getQueryParams();

        $keywords = [
            'per_page',
            'page',
            'order',
        ];

        $keywords = array_flip($keywords);

        $allowedFilterFields = $this->getAllowedFilterFields();

        $translations = $this->getApiNames(true);

        $filters = [];

        foreach($params as $key=>$value) {
            if (isset($keywords[$key])) {
                continue;
            }

            if (isset($this->routeOptions['multiOranizationField'], $this->routeOptions['multiOranizationField']['field'])
                && $key == $this->routeOptions['multiOranizationField']['field']) {
                $field = $this->routeOptions['multiOranizationField']['field'];
                $separator = $this->routeOptions['multiOranizationField']['separator'];
                $organizationIds = $value;
                if (!is_array($organizationIds)) {
                    $organizationIds = explode(',', $organizationIds);
                }

                $organizationFilter = [];
                foreach($organizationIds as $organizationId) {
                    $organizationFilter[] = $field . ' LIKE '. $this->db1->quote('%'.$separator . $organizationId . $separator . '%');
                }
                if (!empty($organizationFilter)) {
                    $filters[] = '(' . join(' OR ', $organizationFilter) . ')';
                }

                continue;
            }

            $colName = $key;
            if (isset($translations[$key])) {
                $colName = $translations[$key];
            }

            if ($allowedFilterFields === null || in_array($colName, $allowedFilterFields)) {
                if (is_string($value) || is_numeric($value)) {
                    if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
                        $values = explode(',', str_replace(['[', ']'], '', $value));
                        $firstValue = reset($values);
                        switch ($firstValue) {
                            case '<':
                            case '>':
                            case '<=':
                            case '>=':
                            case '!=':
                            case 'LIKE':
                            case 'NOT LIKE':
                                $secondValue = end($values);
                                if (is_numeric($secondValue)) {
                                    $secondValue = ($secondValue == (int)$secondValue) ? (int)$secondValue : (float)$secondValue;
                                }
                                if ($firstValue == 'LIKE' || $firstValue == 'NOT LIKE') {
                                    $secondValue = $this->db1->quote($secondValue);
                                }
                                $filters[] = $colName . ' ' . $firstValue . ' ' . $secondValue;
                                break;
                            default:
                                $filters[$colName] = $values;
                                break;
                        }
                    } else {
                        switch (strtoupper($value)) {
                            case 'IS NULL':
                            case 'IS NOT NULL':
                                $filters[] = $colName . ' ' . $value;
                                break;
                            default:
                                $filters[$colName] = $value;
                        }
                    }
                } elseif (is_array($value)) {
                    $filters[$colName] = $value;
                }
            }
        }

        return $filters;
    }

    /**
     * Get the order items should be ordered in for listing model items with a GET request
     *
     * @param ServerRequestInterface $request
     * @return bool|array
     */
    public function getListOrder(ServerRequestInterface $request): bool|array
    {
        $params = $request->getQueryParams();
        if (isset($params['order'])) {

            if ($params['order'] == 1) {
                return true;
            }

            $orderParams = explode(',', $params['order']);

            $order = [];
            $translations = $this->getApiNames(true);

            foreach($orderParams as $orderParam) {
                $sort = false;
                $name = $orderParam = trim($orderParam);

                if (str_starts_with($orderParam, '-')) {
                    $name = substr($orderParam, 1);
                    $sort = SORT_DESC;
                }
                if (str_contains(strtolower($orderParam), ' desc')) {
                    $name = substr($orderParam, 0,-5);
                    $sort = SORT_DESC;
                }
                if (str_contains(strtolower($orderParam), ' asc')) {
                    $name = substr($orderParam, 0,-4);
                    $sort = SORT_ASC;
                }

                $name = trim($name);

                if (isset($translations[$name])) {
                    $name = $translations[$name];
                }

                if ($sort) {
                    $order[$name] = $sort;
                } else {
                    $order[] = $name;
                }
            }

            return $order;
        }
        return $this->model->getSort();
    }

    /**
     * Get pagination filters for listing model items with a GET request
     *
     * uses per_page and page to set the sql limit
     *
     * @param ServerRequestInterface $request
     * @param array $filters
     * @return array
     */
    public function getListPagination(ServerRequestInterface $request, array $filters): array
    {
        $params = $request->getQueryParams();

        if (isset($params['per_page'])) {
            $this->itemsPerPage = $params['per_page'];
        }

        if ($this->itemsPerPage) {
            $page = 1;
            if (isset($params['page'])) {
                $page = $params['page'];
            }
            $offset = ($page-1) * $this->itemsPerPage;

            $filters['limit'] = [
                $this->itemsPerPage,
                $offset,
            ];
        }

        return $filters;
    }

    /**
     * Get one item from the model from an ID field
     *
     * @param mixed $id
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function getOne(mixed $id, ServerRequestInterface $request): ResponseInterface
    {
        $idField = $this->getIdField();
        if ($idField) {
            $filter = $this->getIdFilter($id, $idField);

            $row = $this->model->loadFirst($filter);
            $this->logRequest($request, $row);
            if (is_array($row)) {
                $translatedRow = $this->translateRow($row);
                $filteredRow = $this->filterColumns($translatedRow);
                return new JsonResponse($filteredRow);
            }
        }
        return new EmptyResponse(404);
    }

    /**
     * Get response headers used for pagination.
     * Will set
     * - X-total-count: the total number of items
     * - page: the current page
     * - Link: links to the previous, next, first and last page if applicable
     *
     * @param ServerRequestInterface $request
     * @param array $filter
     * @param array $sort
     * @return array|null
     */
    public function getPaginationHeaders(ServerRequestInterface $request, array $filter=[], array $sort=[]): ?array
    {
        $count = $this->model->getItemCount($filter, $sort);

        $headers = [
            'X-total-count' => $count
        ];

        if ($this->itemsPerPage) {
            $params = $request->getQueryParams();

            $page = 1;
            if (isset($params['page'])) {
                $page = $params['page'];
            }

            $lastPage = ceil($count / $this->itemsPerPage);

            if ($page > $lastPage) {
                return null;
            }

            $baseUrl = $request->getUri()
                ->withQuery('')
                ->withFragment('')
                ->__toString();

            $routeResult = $request->getAttribute('Mezzio\Router\RouteResult');
            $routeName   = $routeResult->getMatchedRouteName();

            $links = [];

            if ($page != $lastPage) {
                $nextPageParams = $params;
                $nextPageParams['page'] = $page+1;
                $links['next'] = '<'.$baseUrl.$this->urlHelper->generate($routeName, [], $nextPageParams).'>; rel=next';

                $lastPageParams = $params;
                $lastPageParams['page'] = $lastPage;
                $links['last'] = '<'.$baseUrl.$this->urlHelper->generate($routeName, [], $lastPageParams).'>; rel=last';
            }

            if ($page > 1) {
                $firstPageParams = $params;
                $firstPageParams['page'] = 1;
                $links['first'] = '<'.$baseUrl.$this->urlHelper->generate($routeName, [], $firstPageParams).'>; rel=first';

                $prevPageParams = $params;
                $prevPageParams['page'] = $page-1;
                $links['prev'] = '<'.$baseUrl.$this->urlHelper->generate($routeName, [], $prevPageParams).'>; rel=prev';
            }

            $headers['Link'] = join(',', $links);
        }

        return $headers;
    }

    /**
     * Get the structural information of each model field so it will be easier to see what information is
     * received or needed for a POST/PATCH
     *
     * @return array
     * @throws \Zend_Date_Exception
     */
    public function getStructure(): array
    {
        if (!$this->structure) {
            $columns = $this->model->getItemsOrdered();

            $translations = $this->getApiNames();

            $structureAttributes = [
                'label',
                'description',
                'required',
                'size',
                'maxlength',
                'type',
                'multiOptions',
                'default',
                'elementClass',
                'multiOptionSettings',
                'disable',
                'raw',
            ];

            $translatedColumns = [];

            foreach($columns as $columnName) {
                $columnLabel = $columnName;
                if (isset($translations[$columnName]) && !empty($translations[$columnName])) {
                    $columnLabel = $translations[$columnName];
                }
                $translatedColumns[$columnName] = $columnLabel;
            }
            $columns = $this->filterColumns($translatedColumns, false, false);

            $structure = [];

            foreach ($columns as $columnName => $columnLabel) {
                foreach ($structureAttributes as $attributeName) {
                    if ($this->model->has($columnName, $attributeName)) {

                        $propertyValue = $this->model->get($columnName, $attributeName);

                        $structure[$columnLabel][$attributeName] = $propertyValue;

                        if ($attributeName === 'type') {
                            $structure[$columnLabel][$attributeName] = match ($structure[$columnLabel][$attributeName]) {
                                1 => 'string',
                                2 => 'numeric',
                                3 => 'date',
                                4 => 'datetime',
                                5 => 'time',
                                6 => 'child_model',
                                default => 'no_value',
                            };
                            if ($this->model->has($columnName, ModelAbstract::SAVE_TRANSFORMER)) {
                                $transformer = $this->model->get($columnName, ModelAbstract::SAVE_TRANSFORMER);
                                if (is_array($transformer) && $transformer[0] instanceof JsonData) {
                                    $structure[$columnLabel][$attributeName] = 'json';
                                }
                            }
                        }

                        if ($attributeName == 'default') {
                            switch (true) {
                                case $structure[$columnLabel][$attributeName] instanceof \Zend_Db_Expr:
                                    $structure[$columnLabel][$attributeName] = $structure[$columnLabel][$attributeName]->__toString();
                                    break;
                                case ($structure[$columnLabel][$attributeName] instanceof \Zend_Date
                                    && $structure[$columnLabel][$attributeName] == new \Zend_Date):
                                    $structure[$columnLabel][$attributeName] = 'NOW()';
                                    break;
                                case is_object($structure[$columnLabel][$attributeName]):
                                    $structure[$columnLabel][$attributeName] = null;
                            }
                        }
                    }
                }
                if (isset($structure[$columnLabel])) {
                    $structure[$columnLabel]['name'] = $columnLabel;
                }
            }

            $usedColumns = array_keys($structure);

            $columns = $this->filterColumns($usedColumns, false, false);
            $structure = array_intersect_key($structure, array_flip($columns));

            $this->structure = $structure;
        }

        return $this->structure;
    }

    /**
     * Returns an empty response with the allowed methods for this specific endpoint in the header
     * @return EmptyResponse
     */
    public function options(): EmptyResponse
    {
        $response = new EmptyResponse(200);

        if (isset($this->routeOptions['methods'])) {
            $allow = strtoupper(join(', ', $this->routeOptions['methods']));
        } else {
            $allow = strtoupper(join(', ', $this->supportedMethods));
        }

        return $response->withHeader('Allow', $allow)
            ->withHeader('Access-Control-Allow-Methods', $allow);
    }

    protected function logRequest(ServerRequestInterface $request, ?array $data = null, bool $changed = false): array|null
    {
        $respondentId = null;
        if ($data && isset($this->routeOptions['respondentIdField']) && isset($data[$this->routeOptions['respondentIdField']])) {
            $respondentId = $data[$this->routeOptions['respondentIdField']];
        }

        if ($changed) {
            return $this->accesslogRepository->logChange($request, $respondentId);
        }

        return $this->accesslogRepository->logAction($request, $respondentId);
    }

    /**
     * Save a new row to the model
     *
     * Will return status:
     * - 415 when the content type of the data supplied in the request is not allowed
     * - 400 (empty response) if the row is empty or if the model could not save the row AFTER validation
     * - 400 (json response) if the row did not pass validation. Errors will be returned in the body
     * - 201 (empty response) if the row is succesfully added to the model.
     *      If possible a Link header will be supplied to the new record
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function post(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->checkContentType($request) === false) {
            return new EmptyResponse(415);
        }

        $parsedBody = json_decode($request->getBody()->getContents(), true);

        if (empty($parsedBody)) {
            return new EmptyResponse(400);
        }

        $event = new SaveModel($this->model);
        $event->setImportData($parsedBody);
        $eventName = $this->model->getName() . '.post';
        $this->eventDispatcher->dispatch($event, $eventName);

        $row = $this->translateRow($parsedBody, true);

        $response = $this->saveRow($request, $row);
        if (in_array($response->getStatusCode(), [200,201])) {
            $eventName = $this->model->getName() . '.saved';
            $this->eventDispatcher->dispatch($event, $eventName);
        }
        return $response;
    }

    /**
     * Update a row in the model. Only needs the changed values in the model.
     *
     * Will return status:
     * - 404 when the model ID supplied in the request url is not found
     * - 415 when the content type of the data supplied in the request is not allowed
     * - 400 (empty response) if the row is empty or if the model could not save the row AFTER validation
     * - 400 (json response) if the row did not pass validation. Errors will be returned in the body
     * - 201 (empty response) if the row is succesfully added to the model.
     *      If possible a Link header will be supplied to the new record
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function patch(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->getId($request);

        $idField = $this->getIdField();
        if ($id === null || !$idField) {
            return new EmptyResponse(404);
        }

        if ($this->checkContentType($request) === false) {
            return new EmptyResponse(415);
        }

        $parsedBody = json_decode($request->getBody()->getContents(), true);

        $event = new SaveModel($this->model);
        $event->setImportData($parsedBody);
        $eventName = $this->model->getName() . '.patch';
        $this->eventDispatcher->dispatch($event, $eventName);

        $newRowData = $this->translateRow($parsedBody, true);

        $filter = $this->getIdFilter($id, $idField);

        $row = $this->model->loadFirst($filter);

        $row = $newRowData + $row;

        return $this->saveRow($request, $row, true);
    }

    /**
     *
     *
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     * @throws Exception
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->setRequestStart();
        $this->initUserAtributesFromRequest($request);
        $this->addCurrentUserToModel();

        $this->model = $this->createModel();
        if (method_exists($this->model, 'applyApiSettings')) {
            $this->model->applyApiSettings();
        }

        return parent::process($request, $handler);
    }

    /**
     * Saves the row to the model after validating the row first
     *
     * Hooks beforeSaveRow before validation and afterSaveRow after for extra actions to the row.
     *
     * @param ServerRequestInterface $request
     * @param array $row
     * @param bool $update
     * @return ResponseInterface
     */
    public function saveRow(ServerRequestInterface $request, array $row, bool $update=false): ResponseInterface
    {
        if (empty($row)) {
            return new EmptyResponse(400);
        }

        $userId = (int)$request->getAttribute('user_id');

        $this->model->addTransformer(new CreatedChangedByTransformer($userId));
        $this->model->addTransformer(new ValidateFieldsTransformer($this->loader, $userId));
        $this->model->addTransformer(new DateTransformer());

        $row = $this->filterColumns($row, true);

        $row = $this->beforeSaveRow($row);

        try {
            $newRow = $this->model->save($row);
        } catch(Exception $e) {
            // Row could not be saved.

            $event = new SaveFailedModel($this->model);
            $event->setSaveData($row);
            $event->setException($e);

            $this->eventDispatcher->dispatch($event, 'model.' . $this->model->getName() . '.save.error');

            if ($e instanceof ModelValidationException) {
                //$this->logger->error($e->getMessage(), $e->getErrors());
                return new JsonResponse(['error' => 'validation_error', 'message' => $e->getMessage(), 'errors' => $e->getErrors()], 400);
            }

            if ($e instanceof ModelException) {
                //$this->logger->error($e->getMessage());
                return new JsonResponse(['error' => 'model_error', 'message' => $e->getMessage()], 400);
            }

            // Unknown exception!
            //$this->logger->error($e->getMessage());
            return new JsonResponse(['error' => 'unknown_error', 'message' => $e->getMessage()], 400);
        }

        $newRow = $this->afterSaveRow($newRow);

        $idField = $this->getIdField();

        $routeParams = [];
        if (isset($newRow[$idField])) {
            $routeParams[$idField] = $newRow[$idField];
        }

        if (!empty($routeParams)) {

            $result = $request->getAttribute(RouteResult::class);
            $routeName = $result->getMatchedRouteName();
            $baseRoute = str_replace(['.structure', '.get', '.fixed'], '', $routeName);

            $routeParts = explode('.', $baseRoute);
            //array_pop($routeParts);
            $getRouteName = join('.', $routeParts) . '.get';

            try {
                $location = $this->urlHelper->generate($getRouteName, $routeParams);
            } catch(InvalidArgumentException) {
                // Give it another go for custom routes
                $getRouteName = join('.', $routeParts);
                try {
                    $location = $this->urlHelper->generate($getRouteName, $routeParams);
                } catch(InvalidArgumentException) {
                    $location = null;
                }
            }
            if ($location !== null) {
                return new EmptyResponse(
                    201,
                    [
                        'Location' => $location,
                    ]
                );
            }
        }

        return new EmptyResponse(201);
    }

    protected function setRequestStart()
    {
        $this->requestStart = microtime(true);
    }

    /**
     * Get the structural information of each model field so it will be easier to see what information is
     * received or needed for a POST/PATCH
     *
     * @return JsonResponse
     * @throws \Zend_Date_Exception
     */
    public function structure(): JsonResponse
    {
        $structure = $this->getStructure();
        return new JsonResponse($structure);
    }

    /**
     * Translate a row with the api names and a date transformation to ISO 8601
     *
     * @param array $row
     * @param bool $reversed
     * @return array
     */
    public function translateRow(array $row, bool $reversed=false): array
    {
        $translations = $this->getApiNames($reversed);

        return $this->translateList($row, $translations);
    }

    public function translateList(array $row, array $translations): array
    {
        $translatedRow = [];
        foreach($row as $colName=>$value) {

            if (is_array($value) && isset($translations[$colName]) && is_array($translations[$colName])) {
                foreach($value as $key=>$subrow) {
                    $translatedRow[$colName][$key] = $this->translateList($subrow, $translations[$colName]);
                }
                continue;
            }

            if ($value instanceof DateTimeInterface) {
                $value = $value->format(DateTimeInterface::ATOM);
            }

            if (isset($translations[$colName])) {
                $translatedRow[$translations[$colName]] = $value;
            } else {
                $translatedRow[$colName] = $value;
            }
        }

        return $translatedRow;
    }
}
