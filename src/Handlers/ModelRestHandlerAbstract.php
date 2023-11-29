<?php


namespace Gems\Api\Handlers;

use Gems\Api\Util\ContentTypeChecker;
use Gems\Api\Event\SavedModel;
use Gems\Api\Event\SaveFailedModel;
use Gems\Api\Event\SaveModel;
use Gems\Api\Exception\ModelException;
use Gems\Api\Exception\ModelValidationException;
use Gems\Api\Model\ModelApiHelper;
use Gems\Api\Model\RouteOptionsModelFilter;
use Gems\Api\Model\Transformer\CreatedChangedByTransformer;
use Gems\Api\Model\Transformer\DateTransformer;
use Gems\Api\Model\Transformer\ValidateFieldsTransformer;
use Gems\Audit\AuditLog;
use Gems\Model;
use Laminas\Db\Adapter\Adapter;
use Mezzio\Router\Exception\InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Zalt\Loader\ProjectOverloader;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Exception;
use Mezzio\Helper\UrlHelper;
use Mezzio\Router\RouteResult;
use DateTimeInterface;
use Zalt\Model\Data\DataReaderInterface;
use Zalt\Model\Data\DataWriterInterface;
use Zalt\Model\Data\FullDataInterface;

abstract class ModelRestHandlerAbstract extends RestHandlerAbstract
{
    /**
     * @var array List of allowed content types as input for write methods
     */
    protected array $allowedContentTypes = ['application/json'];

    protected ContentTypeChecker $contentTypeChecker;

    /**
     * @var string|null Fieldname of model that identifies a row with a unique ID
     */
    protected ?string $idField = null;

    /**
     * @var int number of items per page for pagination
     */
    protected int $itemsPerPage = 25;

    /**
     * @var DataReaderInterface|null Gemstracker Model
     */
    protected ?DataReaderInterface $model = null;

    protected DateTimeInterface|float $requestStart;

    /**
     * @var array|null list of column structure
     */
    protected ?array $structure = null;

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
     *
     * RestControllerAbstract constructor.
     * @param EventDispatcherInterface $eventDispatcher
     * @param AuditLog $auditLog
     * @param ProjectOverloader $loader
     * @param UrlHelper $urlHelper
     * @param Adapter $db
     */
    public function __construct(
        protected readonly EventDispatcherInterface $eventDispatcher,
        protected readonly AuditLog $auditLog,
        protected readonly ProjectOverloader $loader,
        protected readonly UrlHelper $urlHelper,
        protected readonly ModelApiHelper $modelApiHelper,
        protected Adapter $db,
    )
    {
        $this->contentTypeChecker = new ContentTypeChecker($this->allowedContentTypes);
    }

    protected function addCurrentUserToModel(): void
    {
        Model::setCurrentUserId($this->userId);
    }

    /**
     * Do actions or translate the row after a save
     *
     * @param array $newRow
     * @return array
     */
    protected function afterSaveRow(array $newRow): array
    {
        $oldData = [];
        if (method_exists($this->model, 'getOldValues')) {
            $oldData = $this->model->getOldValues() ?? [];
        }

        $event = new SavedModel($this->model, $newRow, $oldData, $this->requestStart);
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
     * Create a Gemstracker model
     *
     * @return DataReaderInterface
     */
    abstract protected function createModel(): DataReaderInterface;

    /**
     * Delete a row from the model
     *
     * @param ServerRequestInterface $request
     * @return EmptyResponse
     */
    public function delete(ServerRequestInterface $request): EmptyResponse
    {
        if (!$this->model instanceof FullDataInterface) {
            return new EmptyResponse(400);
        }
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
        $metaModel = $this->model->getMetaModel();
        $filterOptions = $this->routeOptions;
        $modelAllowFields = $metaModel->getColNames('allow_api_load');
        $modelAllowSaveFields = $metaModel->getColNames('allow_api_save');
        if ($modelAllowFields) {
            if (!isset($filterOptions['allowedFields'])) {
                $filterOptions['allowedFields'] = [];
            }
            $filterOptions['allowedFields'] = array_merge($modelAllowFields, $filterOptions['allowedFields']);
        }
        if ($modelAllowSaveFields) {
            if (!isset($filterOptions['allowedSaveFields'])) {
                $filterOptions['allowedSaveFields'] = [];
            }
            $filterOptions['allowedSaveFields'] = array_merge($modelAllowSaveFields, $filterOptions['allowedSaveFields']);
        }

        return RouteOptionsModelFilter::filterColumns($row, $filterOptions, $save, $useKeys);
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
    protected function getAllowedFilterFields(): array|null
    {
        return $this->model->getMetaModel()->getItemNames();
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
            $keys = $this->model->getMetaModel()->getKeys();
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

        $apiNames = $this->modelApiHelper->getApiNames($this->model->getMetaModel(), true);

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

        $queryParams = $request->getQueryParams();
        $page = (int)($queryParams['page'] ?? 1);
        $this->itemsPerPage = (int)($queryParams['per_page'] ?? $this->itemsPerPage);

        $itemCount = 0;

        $rows = $this->model->loadPageWithCount($itemCount, $page, $this->itemsPerPage, $filters, $order);

        if ($itemCount === 0) {
            return new EmptyResponse(204);
        }

        $headers = $this->getPaginationHeaders($request, $itemCount);

        $translatedRows = [];
        foreach($rows as $key=>$row) {
            $translatedRows[$key] = $this->filterColumns($this->modelApiHelper->translateRow($this->model->getMetaModel(), $row));
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

        $translations = $this->modelApiHelper->getApiNames($this->model->getMetaModel(), true);

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
                    if (is_int($organizationId)) {

                        $organizationFilter[] = "$field LIKE %" . $separator . $organizationId . $separator . "%";
                    }
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
                                    $secondValue = $this->db->getPlatform()->quoteValue($secondValue);
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
            $translations = $this->modelApiHelper->getApiNames($this->model->getMetaModel(), true);

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
            if (!empty($row)) {
                $translatedRow = $this->modelApiHelper->translateRow($this->model->getMetaModel(), $row);
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
     * @param int $itemCount number of total rows without pagination
     * @return array
     */
    public function getPaginationHeaders(ServerRequestInterface $request, int $itemCount): array
    {
        $headers = [
            'X-total-count' => $itemCount
        ];

        if ($this->itemsPerPage) {
            $params = $request->getQueryParams();

            $page = 1;
            if (isset($params['page'])) {
                $page = $params['page'];
            }

            $lastPage = ceil($itemCount / $this->itemsPerPage);

            if ($page > $lastPage) {
                return [];
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

    protected function logRequest(ServerRequestInterface $request, ?array $data = null, bool $changed = false): ?int
    {
        $respondentId = null;
        if ($data && isset($this->routeOptions['respondentIdField']) && isset($data[$this->routeOptions['respondentIdField']])) {
            $respondentId = $data[$this->routeOptions['respondentIdField']];
        }

        if ($changed) {
            return $this->auditLog->logChange($request, respondentId: $respondentId);
        }

        return $this->auditLog->logChange($request, respondentId:  $respondentId);
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
        if ($this->contentTypeChecker->checkContentType($request) === false) {
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

        $row = $this->modelApiHelper->translateRow($this->model->getMetaModel(), $parsedBody, true);

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

        if ($this->contentTypeChecker->checkContentType($request) === false) {
            return new EmptyResponse(415);
        }

        $parsedBody = json_decode($request->getBody()->getContents(), true);

        $event = new SaveModel($this->model);
        $event->setImportData($parsedBody);
        $eventName = $this->model->getName() . '.patch';
        $this->eventDispatcher->dispatch($event, $eventName);

        $newRowData = $this->modelApiHelper->translateRow($this->model->getMetaModel(), $parsedBody, true);

        $filter = $this->getIdFilter($id, $idField);

        $row = $this->model->loadFirst($filter);

        $row = $newRowData + $row;

        return $this->saveRow($request, $row, true);
    }

    /**
     *
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     * @throws Exception
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->setRequestStart();
        $this->initUserAtributesFromRequest($request);
        $this->addCurrentUserToModel();

        $this->model = $this->createModel();
        if (method_exists($this->model, 'applyApiSettings')) {
            $this->model->applyApiSettings();
        }

        return parent::handle($request);
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

        if (!$this->model instanceof DataWriterInterface) {
            return new EmptyResponse(400);
        }

        $userId = (int)$request->getAttribute('user_id');

        $metaModel = $this->model->getMetaModel();
        $metaModel->addTransformer(new CreatedChangedByTransformer($userId));
        $metaModel->addTransformer(new ValidateFieldsTransformer($this->model, $this->loader, $userId));
        $metaModel->addTransformer(new DateTransformer());

        $row = $this->filterColumns($row, true);

        $row = $this->beforeSaveRow($row);

        try {
            $newRow = $this->model->save($row);
        } catch(Exception $e) {
            // Row could not be saved.

            $event = new SaveFailedModel($this->model, $e, $row);
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
        $this->modelApiHelper->applyAllowedColumnsToModel($this->model->getMetaModel(), $this->routeOptions);

        $structure = $this->modelApiHelper->getStructure($this->model->getMetaModel());
        return new JsonResponse($structure);
    }
}
