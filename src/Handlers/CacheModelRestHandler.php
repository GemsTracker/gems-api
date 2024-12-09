<?php

declare(strict_types=1);

namespace Gems\Api\Handlers;

use Gems\Api\Model\ModelApiHelper;
use Gems\Audit\AuditLog;
use Gems\Cache\HelperAdapter;
use Laminas\Db\Adapter\Adapter;
use Mezzio\Helper\UrlHelper;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Zalt\Loader\ProjectOverloader;

class CacheModelRestHandler extends ModelRestHandler
{
    protected array $cacheTags = [];

    public function __construct(
        EventDispatcherInterface $eventDispatcher,
        AuditLog $auditLog,
        ProjectOverloader $loader,
        UrlHelper $urlHelper,
        ModelApiHelper $modelApiHelper,
        Adapter $db,
        protected readonly HelperAdapter $cache,
    ) {
        parent::__construct($eventDispatcher, $auditLog, $loader, $urlHelper, $modelApiHelper, $db);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $routeResult = $request->getAttribute('Mezzio\Router\RouteResult');
        $route = $routeResult->getMatchedRoute();
        if ($route) {
            $options = $route->getOptions();
            if (isset($options['cacheTags'])) {
                $this->cacheTags = (array) $options['cacheTags'];

            }
        }

        return parent::handle($request);
    }

    protected function afterSaveRow(array $newRow): array
    {
        if ($this->cacheTags) {
            $this->cache->invalidateTags($this->cacheTags);
        }
        return parent::afterSaveRow($newRow);


    }
}