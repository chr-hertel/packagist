<?php declare(strict_types=1);

namespace App\Tests\Security;

use Beelab\Recaptcha2Bundle\Recaptcha\RecaptchaVerifier;
use App\Security\BruteForceLoginFormAuthenticator;
use App\Security\RecaptchaHelper;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\HttpUtils;

class BruteForceLoginFormAuthenticatorTest extends TestCase
{
    /** @var BruteForceLoginFormAuthenticator */
    private $authenticator;
    /** @var HttpUtils&\PHPUnit\Framework\MockObject\MockObject */
    private $httpUtils;
    /** @var RecaptchaVerifier&\PHPUnit\Framework\MockObject\MockObject */
    private $recaptchaVerifier;
    /** @var UserProviderInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $userProvider;
    /** @var RecaptchaHelper&\PHPUnit\Framework\MockObject\MockObject */
    private $recaptchaHelper;
    /** @var ManagerRegistry&\PHPUnit\Framework\MockObject\MockObject */
    private $doctrine;

    protected function setUp(): void
    {
        $this->httpUtils = $this->getMockBuilder(HttpUtils::class)->disableOriginalConstructor()->getMock();
        $this->recaptchaVerifier = $this->getMockBuilder(RecaptchaVerifier::class)->disableOriginalConstructor()->getMock();
        $this->userProvider = $this->getMockBuilder(UserProviderInterface::class)->disableOriginalConstructor()->getMock();
        $this->recaptchaHelper = $this->getMockBuilder(RecaptchaHelper::class)->disableOriginalConstructor()->getMock();
        $this->doctrine = $this->getMockBuilder(ManagerRegistry::class)->disableOriginalConstructor()->getMock();

        $this->authenticator = new BruteForceLoginFormAuthenticator($this->httpUtils, $this->recaptchaVerifier, $this->userProvider, $this->recaptchaHelper, $this->doctrine);
    }

    /**
     * @dataProvider supportsProvider
     */
    public function testSupports(string $method, string $route, bool $expected): void
    {
        $request = new Request();
        $request->setMethod($method);
        $request->attributes->set('_route', $route);

        $this->assertSame($expected, $this->authenticator->supports($request));
    }

    public function supportsProvider(): array
    {
        return [
            ['POST', 'login', true],
            ['GET', 'login', false],
            ['POST', 'route', false],
        ];
    }

    public function testOnAuthenticationSuccess(): void
    {
        $request = new Request();
        $request->setSession($this->getMockBuilder(SessionInterface::class)->disableOriginalConstructor()->getMock());
        $token = $this->getMockBuilder(UsernamePasswordToken::class)->disableOriginalConstructor()->getMock();

        $this->httpUtils
            ->expects($this->once())
            ->method('createRedirectResponse')
            ->with($request, $this->equalTo('home'))
            ->willReturn(new RedirectResponse('/'));

        $this->recaptchaHelper
            ->expects($this->once())
            ->method('clearCounter');

        $this->authenticator->onAuthenticationSuccess($request, $token, 'main');
    }

    public function testOnAuthenticationFailureIncreaseCounter(): void
    {
        $request = new Request();
        $request->setSession($this->getMockBuilder(SessionInterface::class)->disableOriginalConstructor()->getMock());
        $exception = new AuthenticationException();

        $this->recaptchaHelper
            ->expects($this->once())
            ->method('increaseCounter');

        $this->httpUtils
            ->expects($this->once())
            ->method('createRedirectResponse')
            ->with($request, $this->equalTo('login'))
            ->willReturn(new RedirectResponse('/'));

        $this->authenticator->onAuthenticationFailure($request, $exception);
    }
}
