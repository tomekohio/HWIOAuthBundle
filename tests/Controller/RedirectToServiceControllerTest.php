<?php

declare(strict_types=1);

/*
 * This file is part of the HWIOAuthBundle package.
 *
 * (c) Hardware Info <opensource@hardware.info>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace HWI\Bundle\OAuthBundle\Tests\Controller;

use HWI\Bundle\OAuthBundle\Controller\RedirectToServiceController;
use HWI\Bundle\OAuthBundle\OAuth\ResourceOwnerInterface;
use HWI\Bundle\OAuthBundle\Security\Http\ResourceOwnerMapInterface;
use HWI\Bundle\OAuthBundle\Security\Http\ResourceOwnerMapLocator;
use HWI\Bundle\OAuthBundle\Security\OAuthUtils;
use HWI\Bundle\OAuthBundle\Util\DomainWhitelist;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\HttpUtils;

final class RedirectToServiceControllerTest extends TestCase
{
    /**
     * @var MockObject&SessionInterface
     */
    private $session;

    private Request $request;

    private string $targetPathParameter = 'target_path';

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = $this->createMock(SessionInterface::class);
        $this->request = Request::create('/');
        $this->request->setSession($this->session);
    }

    public function testTargetUrlIsCorrect(): void
    {
        $controller = $this->createController();

        $response = $controller->redirectToServiceAction($this->request, 'facebook');

        $this->assertSame('https://domain.com/oauth/v2/auth', $response->getTargetUrl());
    }

    public function testTargetPathParameter(): void
    {
        $this->request->attributes->set($this->targetPathParameter, 'https://domain.com/target/path');

        $this->session->expects($this->once())
            ->method('set')
            ->with('_security.default.target_path', 'https://domain.com/target/path')
        ;

        $controller = $this->createController();
        $controller->redirectToServiceAction($this->request, 'facebook');
    }

    public function testUseReferer(): void
    {
        $this->request->headers->set('Referer', 'https://google.com');

        $this->session->expects($this->once())
            ->method('set')
            ->with('_security.default.target_path', 'https://google.com')
        ;

        $controller = $this->createController(false, true);
        $controller->redirectToServiceAction($this->request, 'facebook');
    }

    public function testFailedUseReferer(): void
    {
        $this->request->headers->set('Referer', 'https://google.com');

        $this->session->expects($this->once())
            ->method('set')
            ->with('_security.default.failed_target_path', 'https://google.com')
        ;

        $controller = $this->createController(true);
        $controller->redirectToServiceAction($this->request, 'facebook');
    }

    public function testUnknownResourceOwner(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $controller = $this->createController();
        $controller->redirectToServiceAction($this->request, 'unknown');
    }

    public function testThrowAccessDeniedExceptionForNonWhitelistedTargetPath(): void
    {
        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Not allowed to redirect to /malicious/target/path');

        $this->request->attributes->set($this->targetPathParameter, '/malicious/target/path');

        $this->session->expects($this->never())
            ->method('set')
            ->with('_security.default.target_path', '/malicious/target/path')
        ;

        $controller = $this->createController();
        $controller->redirectToServiceAction($this->request, 'facebook');
    }

    protected function createFirewallMapMock(): FirewallMap
    {
        $firewallMap = $this->createMock(FirewallMap::class);

        $firewallMap
            ->expects($this->any())
            ->method('getFirewallConfig')
            ->willReturn(new FirewallConfig('main', '/path/a'))
        ;

        return $firewallMap;
    }

    private function createController(bool $failedUseReferer = false, bool $useReferer = false): RedirectToServiceController
    {
        $resourceOwner = $this->createMock(ResourceOwnerInterface::class);
        $resourceOwner->method('getAuthorizationUrl')
            ->willReturn('https://domain.com/oauth/v2/auth');

        $ownerMap = $this->createMock(ResourceOwnerMapInterface::class);
        $ownerMap->method('getResourceOwnerByName')
            ->with('facebook')
            ->willReturn($resourceOwner);
        $ownerMap->method('getResourceOwnerCheckPath')
            ->withAnyParameters()
            ->willReturn('https://domain.com/oauth/v2/auth');

        $resourceOwnerMapLocator = new ResourceOwnerMapLocator();
        $resourceOwnerMapLocator->set('default', $ownerMap);

        $utils = new OAuthUtils(
            $this->createMock(HttpUtils::class),
            $this->createMock(AuthorizationCheckerInterface::class),
            $this->createFirewallMapMock(),
            true,
            'IS_AUTHENTICATED_REMEMBERED'
        );
        $utils->addResourceOwnerMap('main', $ownerMap);

        return new RedirectToServiceController(
            $utils,
            new DomainWhitelist(['domain.com']),
            $resourceOwnerMapLocator,
            $this->targetPathParameter,
            $failedUseReferer,
            $useReferer
        );
    }
}
