<?php

namespace Gems\Api\Middleware;

use Gems\AuthNew\Adapter\GemsTrackerIdentity;
use Gems\AuthNew\AuthenticationMiddleware;
use Gems\AuthNew\AuthenticationServiceBuilder;
use Gems\AuthTfa\OtpMethodBuilder;
use Gems\AuthTfa\TfaService;
use Gems\Legacy\CurrentUserRepository;
use Gems\OAuth2\Entity\User;
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

    public const CURRENT_USER_ROLE = 'user_role';


    public const AUTH_TYPE = 'auth-type';

    public function __construct(
        private AuthenticationServiceBuilder $authenticationServiceBuilder,
        private OtpMethodBuilder $otpMethodBuilder,
        private ResourceServer $resourceServer,
        private CurrentUserRepository $currentUserRepository,
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

    protected function validateOauth2Authentication(ServerRequestInterface $request): ServerRequestInterface
    {
        $request = $this->resourceServer->validateAuthenticatedRequest($request);
        if ($oauthUserId = $request->getAttribute('oauth_user_id')) {

            list($loginName, $loginOrganization) = explode(User::ID_SEPARATOR, $oauthUserId);
            $request = $request
                ->withAttribute(static::CURRENT_USER_NAME, $loginName)
                ->withAttribute(static::CURRENT_USER_ORGANIZATION, $loginOrganization)
                ->withAttribute(static::AUTH_TYPE, 'oauth2');


            $this->currentUserRepository->setCurrentUserCredentials($loginName, intval($loginOrganization));

            $userId = $request->getAttribute(static::CURRENT_USER_ID);

            if ($userId !== null) {
                $this->currentUserRepository->setCurrentUserId($userId);
            }

            $role = $request->getAttribute(static::CURRENT_USER_ROLE);

            if ($role !== null) {
                $this->currentUserRepository->setCurrentUserRole($role);
            }

            return $request;
        }
        throw OAuthServerException::accessDenied('user not authenticated');
    }

    protected function validateSessionAuthentication(ServerRequestInterface $request): ServerRequestInterface
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
                    ->withAttribute(static::CURRENT_USER_ORGANIZATION, $user->getCurrentOrganizationId())
                    ->withAttribute(static::AUTH_TYPE, 'session');
            }
        }

        throw OAuthServerException::accessDenied('user not authenticated');
    }
}