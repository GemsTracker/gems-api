<?php

namespace Gems\Api\Util;

use Psr\Http\Message\ServerRequestInterface;

class ContentTypeChecker
{
    public function __construct(
        protected readonly array $allowedContentTypes
    )
    {}

    public function checkContentType(ServerRequestInterface $request): bool
    {
        $contentTypeHeader = $request->getHeaderLine('content-type');
        foreach ($this->allowedContentTypes as $contentType) {
            if (strpos($contentTypeHeader, $contentType) !== false) {
                return true;
            }
        }

        return false;
    }
}