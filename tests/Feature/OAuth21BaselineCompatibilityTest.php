<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\Authentication\TokenAuth\AccessTokenClaims;
use Infocyph\Foundation\Auth\Principal\Principal;
use Infocyph\Foundation\Auth\Principal\PrincipalInterface;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Http\Resolver\PrincipalResolverInterface;
use Infocyph\Foundation\Http\Resolver\RequestPrincipalResolver;
use Infocyph\Webrick\Request\Request;

it('preserves the released application access token claim contract', function (): void {
    $claims = new AccessTokenClaims(
        subjectId: 'account-1',
        actorId: 'actor-1',
        issuedAt: 100,
        expiresAt: 200,
        scopes: ['profile.read'],
        metadata: ['tenant' => 'alpha'],
    );

    expect(array_keys(get_object_vars($claims)))->toBe([
        'subjectId',
        'actorId',
        'issuedAt',
        'expiresAt',
        'scopes',
        'metadata',
    ]);
});

it('accepts an additive oauth principal resolver without changing configured precedence', function (): void {
    $resolver = static function (string $name, ?PrincipalInterface $principal): PrincipalResolverInterface {
        return new class($name, $principal) implements PrincipalResolverInterface {
            public int $calls = 0;

            public function __construct(
                private readonly string $resolverName,
                private readonly ?PrincipalInterface $principal,
            ) {}

            public function name(): string
            {
                return $this->resolverName;
            }

            public function resolve(Request $request): ?PrincipalInterface
            {
                ++$this->calls;

                return $this->principal;
            }
        };
    };

    $session = $resolver('session', null);
    $oauth = $resolver('oauth', new Principal('oauth-account', accountId: 'oauth-account'));
    $bearer = $resolver('bearer', new Principal('legacy-bearer', accountId: 'legacy-bearer'));
    $remember = $resolver('remember', new Principal('remembered', accountId: 'remembered'));

    $principals = new RequestPrincipalResolver(
        new ConfigRepository([
            'auth' => [
                'http' => [
                    'principal_resolvers' => ['session', 'oauth', 'bearer', 'remember'],
                ],
            ],
        ]),
        [
            'session' => $session,
            'oauth' => $oauth,
            'bearer' => $bearer,
            'remember' => $remember,
        ],
    );

    expect($principals->resolve(Request::fake())?->id())->toBe('oauth-account')
        ->and($session->calls)->toBe(1)
        ->and($oauth->calls)->toBe(1)
        ->and($bearer->calls)->toBe(0)
        ->and($remember->calls)->toBe(0);
});
