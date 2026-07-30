<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Benchmarks;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Auth\Account\Account;
use Infocyph\Foundation\Auth\Authentication\Login\LoginRequest;
use Infocyph\Foundation\Auth\Authentication\TokenAuth\AccessTokenClaims;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Auth\Mfa\MfaFactor;
use Infocyph\Foundation\Auth\Mfa\MfaFactorType;
use Infocyph\Foundation\Auth\Passkey\PasskeyAuthenticationResult;
use Infocyph\Foundation\Auth\Passkey\PasskeyChallenge;
use Infocyph\Foundation\Auth\Passkey\PasskeyRegistrationResult;
use Infocyph\Foundation\Auth\Principal\Principal;
use Infocyph\Foundation\Foundation;
use Infocyph\Webrick\Request\Request;
use PhpBench\Attributes as Bench;

#[Bench\Revs(10)]
#[Bench\Iterations(3)]
#[Bench\Warmup(1)]
final class AuthBench
{
    private Account $account;

    private Application $app;

    private MfaFactor $factor;

    private Request $request;

    private AuthServices $services;

    #[Bench\BeforeMethods('setUpAuthorization')]
    public function benchAuthorization(): void
    {
        $decision = $this->services->authorizer()->can(
            new Principal($this->account->id(), accountId: $this->account->id()),
            'benchmark.read',
        );
        if (!$decision->allowed) {
            throw new \LogicException('Authorization benchmark produced a denied decision.');
        }
    }

    #[Bench\BeforeMethods('setUpAuth')]
    public function benchLogin(): void
    {
        $result = $this->services->authenticator()->login(new LoginRequest(
            $this->account->identifier(),
            'benchmark-secret',
        ));
        if (!$result->authenticated()) {
            throw new \LogicException('Login benchmark did not authenticate.');
        }
    }

    #[Bench\BeforeMethods('setUpMfa')]
    public function benchMfaChallenge(): void
    {
        $challenge = $this->services->mfa()->issueChallenge(
            $this->account->id(),
            factorId: $this->factor->id,
        );

        $verified = $this->services->mfa()->verifyChallenge(
            $challenge->challenge?->id ?? '',
            '000000',
        );
        if (!$verified->successful()) {
            throw new \LogicException('MFA benchmark did not verify its challenge.');
        }
    }

    #[Bench\BeforeMethods('setUpPasskey')]
    public function benchPasskeyAuthentication(): void
    {
        $started = $this->services->passkeys()->startAuthentication($this->account->id());
        $challenge = $started->challenge;
        if (!$challenge instanceof PasskeyChallenge) {
            throw new \LogicException('Passkey benchmark challenge was not created.');
        }

        $verified = $this->services->passkeys()->finishAuthentication(new PasskeyAuthenticationResult(
            challengeId: $challenge->id,
            credentialId: 'benchmark-credential',
            clientData: 'client-data',
            authenticatorData: 'authenticator-data',
            signature: 'signature',
        ));
        if (!$verified->successful()) {
            throw new \LogicException('Passkey benchmark did not verify its credential.');
        }
    }

    #[Bench\BeforeMethods('setUpToken')]
    public function benchTokenVerification(): void
    {
        $token = $this->services->tokens()->issueAccessToken(new AccessTokenClaims(
            subjectId: $this->account->id(),
            actorId: null,
            issuedAt: time(),
            expiresAt: time() + 300,
        ))->token;

        if (!$this->services->tokens()->verifyAccessToken($token ?? '')->successful()) {
            throw new \LogicException('Token benchmark did not verify its token.');
        }
    }

    #[Bench\BeforeMethods('setUpAuthenticatedRequest')]
    public function benchWarmAuthenticatedRequest(): void
    {
        if ($this->app->handle($this->request)->getStatusCode() !== 200) {
            throw new \LogicException('Authenticated request benchmark did not return HTTP 200.');
        }
    }

    public function setUpAuth(): void
    {
        $this->app = Foundation::web([
            'app' => [
                'base_path' => sys_get_temp_dir(),
            ],
            'router' => [
                'files' => [],
            ],
        ]);
        $this->services = $this->app->auth();
        $created = $this->services->accounts()->create(
            'benchmark@example.test',
            $this->services->passwordHasher()->hash('benchmark-secret'),
        );
        $account = $created->account;
        if (!$account instanceof Account) {
            throw new \LogicException('Auth benchmark account was not created.');
        }

        $this->account = $account;
    }

    public function setUpAuthenticatedRequest(): void
    {
        $basePath = sys_get_temp_dir() . '/foundation-auth-benchmark';
        $routesPath = $basePath . '/routes';
        if (!is_dir($routesPath)) {
            mkdir($routesPath, 0775, true);
        }

        file_put_contents($routesPath . '/web.php', <<<'PHP'
<?php

declare(strict_types=1);

use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Facade\Router;

Router::get('/authenticated-benchmark', static fn(): Response => Response::json(['ok' => true]), [
    'middleware' => ['resolve-auth', 'auth'],
]);
PHP);

        $this->app = Foundation::web([
            'app' => [
                'base_path' => $basePath,
            ],
            'auth' => [
                'http' => [
                    'principal_resolvers' => ['bearer'],
                ],
            ],
            'router' => [
                'cache' => false,
                'files' => ['web.php'],
            ],
        ])->boot();
        $this->services = $this->app->auth();
        $created = $this->services->accounts()->create(
            'request-benchmark@example.test',
            $this->services->passwordHasher()->hash('benchmark-secret'),
        );
        $account = $created->account;
        if (!$account instanceof Account) {
            throw new \LogicException('Authenticated request benchmark account was not created.');
        }

        $this->account = $account;
        $now = time();
        $token = $this->services->tokens()->issueAccessToken(new AccessTokenClaims(
            subjectId: $account->id(),
            actorId: null,
            issuedAt: $now,
            expiresAt: $now + 300,
        ))->token;
        $this->request = Request::fake(
            headers: [
                'Authorization' => 'Bearer ' . $token,
                'Host' => 'example.test',
            ],
            uri: 'https://example.test/authenticated-benchmark',
        );
        if ($this->app->handle($this->request)->getStatusCode() !== 200) {
            throw new \LogicException('Authenticated request benchmark setup did not return HTTP 200.');
        }
    }

    public function setUpAuthorization(): void
    {
        $this->setUpAuth();
        $permission = $this->services->permissions()->create('benchmark.read');
        $this->services->permissions()->assignToAccount($this->account->id(), $permission->id);
    }

    public function setUpMfa(): void
    {
        $this->setUpAuth();
        $factor = $this->services->mfa()->enrollFactor(
            $this->account->id(),
            MfaFactorType::TOTP,
            'Benchmark',
            enabled: true,
        )->factor;
        if (!$factor instanceof MfaFactor) {
            throw new \LogicException('MFA benchmark factor was not created.');
        }

        $this->factor = $factor;
    }

    public function setUpPasskey(): void
    {
        $this->setUpAuth();
        $started = $this->services->passkeys()->startRegistration($this->account->id());
        $challenge = $started->challenge;
        if (!$challenge instanceof PasskeyChallenge) {
            throw new \LogicException('Passkey benchmark registration challenge was not created.');
        }

        $this->services->passkeys()->finishRegistration(new PasskeyRegistrationResult(
            challengeId: $challenge->id,
            accountId: $this->account->id(),
            credentialId: 'benchmark-credential',
            publicKey: 'public-key',
        ));
    }

    public function setUpToken(): void
    {
        $this->setUpAuth();
    }
}
