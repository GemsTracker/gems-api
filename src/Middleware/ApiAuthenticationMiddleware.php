<?php

namespace Gems\Api\Middleware;

use Gems\AuthNew\Adapter\GemsTrackerIdentity;
use Gems\AuthNew\AuthenticationMiddleware;
use Gems\AuthNew\AuthenticationServiceBuilder;
use Gems\AuthTfa\OtpMethodBuilder;
use Gems\AuthTfa\TfaService;
use Gems\User\UserLoader;
use Laminas\Diactoros\Response;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Mezzio\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ApiAuthenticationMiddleware implements MiddlewareInterface
{
    public const CURRENT_USER_ID = 'currentUserId';
    public const CURRENT_USER_NAME = 'currentUserName';
    public const CURRENT_USER_ORGANIZATION = 'currentUserOrganization';

    public function __construct(private ResourceServer $resourceServer,
        private AuthenticationServiceBuilder $authenticationServiceBuilder,
        private OtpMethodBuilder $otpMethodBuilder,
        //private readonly UserLoader $userLoader,
    )
    {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $result = $this->validateAuthentication($request);
        } catch (OAuthServerException $exception) {
            $response = new Response();
            if ($request->getMethod() === 'OPTIONS') {
                return $response;
            }
            return $exception->generateHttpResponse($response);
        } catch (\Exception $exception) {
            $response = new Response();
            return (new OAuthServerException($exception->getMessage(), 0, 'unknown_error', 500))
                ->generateHttpResponse($response);
        }

        return $handler->handle($result);
    }

    protected function validateAuthentication(ServerRequestInterface $request): ServerRequestInterface
    {
        if ($request->hasHeader('authorization') !== false) {
            return $this->validateOauth2Authentication($request);
        }

        return $this->validateSessionAuthentication($request);
    }

    protected function validateSessionAuthentication(ServerRequestInterface $request)
    {
        $session = $request->getAttribute(SessionInterface::class);
        $authenticationService = $this->authenticationServiceBuilder->buildAuthenticationService($session);

        if ($authenticationService->isLoggedIn() && $authenticationService->checkValid()) {
            $user = $authenticationService->getLoggedInUser();
            $tfaService = new TfaService($session, $authenticationService, $this->otpMethodBuilder);


            if (!$tfaService->requiresAuthentication($user, $request)) {
                return $request->withAttribute(AuthenticationMiddleware::CURRENT_USER_ATTRIBUTE, $user)
                    ->withAttribute(AuthenticationMiddleware::CURRENT_IDENTITY_ATTRIBUTE, $authenticationService->getIdentity())
                    ->withAttribute(static::CURRENT_USER_ID, $user->getUserId())
                    ->withAttribute(static::CURRENT_USER_NAME, $user->getLoginName())
                    ->withAttribute(static::CURRENT_USER_ORGANIZATION, $user->getCurrentOrganizationId());
            }
        }

        throw OAuthServerException::accessDenied('user not authenticated');
    }

    protected function validateOauth2Authentication(ServerRequestInterface $request)
    {
        $request = $this->resourceServer->validateAuthenticatedRequest($request);
        if ($oauthUserId = $request->getAttribute('oauth_user_id')) {

            list($userId, $loginName, $loginOrganization) = explode('@', $oauthUserId);

            $identity = new GemsTrackerIdentity($loginName, $loginOrganization);
            //$user = $this->userLoader->getUser($loginName, $loginOrganization);
            return $request
                //->withAttribute(AuthenticationMiddleware::CURRENT_USER_ATTRIBUTE, $user)
                ->withAttribute(AuthenticationMiddleware::CURRENT_IDENTITY_ATTRIBUTE, $identity)
                ->withAttribute(static::CURRENT_USER_ID, $userId)
                ->withAttribute(static::CURRENT_USER_NAME, $loginName)
                ->withAttribute(static::CURRENT_USER_ORGANIZATION, $loginOrganization);
        }

        throw OAuthServerException::accessDenied('No valid access token');
    }
}