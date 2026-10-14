<?php

declare(strict_types=1);

it('assigns every declared production PHP symbol to an approved Foundation owner boundary', function (): void {
    // Native mechanisms remain owned by their specialist packages. These are
    // Foundation application-policy boundaries, not copies of those engines.
    $owners = [
        'Application' => 'framework lifecycle and application context',
        'Auth' => 'auth policy, account, consent, and native adapter selection',
        'Bootstrap' => 'deterministic provider composition',
        'Cache' => 'named native CacheLayer selection and cluster policy',
        'Command' => 'application CLI commands and access policy',
        'Communication' => 'native TalkingBytes transport selection',
        'Config' => 'application configuration and ArrayKit trust policy',
        'Container' => 'InterMix graph inputs and development bindings',
        'Database' => 'native DBLayer configuration and execution leases',
        'Diagnostics' => 'application readiness diagnostics',
        'Exception' => 'domain error contracts',
        'Filesystem' => 'Pathwise storage policy and response adapters',
        'Foundation.php' => 'public application facade',
        'Generator' => 'scaffolding and authored templates',
        'Http' => 'Webrick middleware and HTTP presentation policy',
        'Logging' => 'PSR exception logging policy',
        'Messaging' => 'Omnibus job and provider policy',
        'Module' => 'optional-module ownership and dependency planning',
        'Notifications' => 'recipient and native TalkingBytes email selection',
        'Operations' => 'application deployment/maintenance controls',
        'Process' => 'CLI subprocess lifetime, distinct from Runwire supervision',
        'Release' => 'outer immutable-generation acceptance and activation',
        'Routing' => 'route declaration and native Webrick compilation policy',
        'Runtime' => 'InterMix execution seed and DB cleanup coordination',
        'Scheduling' => 'application scheduling policy, not Omnibus transport',
        'Security' => 'native Epicrypt key selection and security policy',
        'Session' => 'browser cookie/CSRF/flash and request-owned session lifetime',
        'Support' => 'cross-domain application value normalization',
        'Testing' => 'application test support and fixtures',
        'Validation' => 'ReqShield validation selection and presentation',
        'Worker' => 'native Omnibus/Runwire worker configuration',
    ];
    // Ordered most-specific-first: each retained symbol has an explicit
    // application-policy or specialist-integration responsibility.
    $detailedOwners = [
        'Auth/Adapter/DBLayer/' => 'DBLayer persistence mappings for account/credential policy',
        'Auth/Adapter/Epicrypt/' => 'Epicrypt crypto and token policy adapters',
        'Auth/Adapter/Otp/' => 'OTP factor lifecycle adaptation',
        'Auth/Adapter/TalkingBytes/' => 'TalkingBytes notification boundary',
        'Auth/Adapter/WebAuthn/' => 'WebAuthn origin and relying-party configuration',
        'Auth/Adapter/' => 'selected native auth adapter',
        'Auth/Account/' => 'account identity and lifecycle',
        'Auth/Audit/' => 'application authentication audit events',
        'Auth/Authentication/' => 'account authentication workflow',
        'Auth/Authorization/' => 'application grants, permissions and policy',
        'Auth/Contract/' => 'framework authentication contracts',
        'Auth/Device/' => 'account device policy',
        'Auth/Driver/' => 'explicit authentication feature selection',
        'Auth/Exception/' => 'application authentication errors',
        'Auth/Http/' => 'authentication HTTP presentation',
        'Auth/Internal/' => 'native auth recipes, config and production guards',
        'Auth/Mfa/' => 'MFA factor and recovery application policy',
        'Auth/Notification/' => 'authentication notification policy',
        'Auth/OAuth/' => 'OAuth consent, endpoint and persistence policy above Epicrypt',
        'Auth/Otp/' => 'OTP application orchestration',
        'Auth/Passkey/' => 'passkey account binding and persistence policy',
        'Auth/Principal/' => 'execution-local principal identity',
        'Auth/Support/' => 'explicitly selected development/test auth drivers',
        'Auth/' => 'authentication service and public facade',
        'Http/JsonDispatch/' => 'application JSON dispatch presentation',
        'Http/Middleware/' => 'Webrick middleware application policy',
        'Http/Resolver/' => 'request principal selection above Webrick',
        'Http/Resource/' => 'application response resources',
        'Http/Response/' => 'Webrick response and exception mapping',
        'Http/' => 'framework HTTP lifecycle and boot',
        'Config/Internal/' => 'native ArrayKit configuration policy glue',
        'Config/' => 'application configuration and release trust policy',
    ];
    $root = dirname(__DIR__, 2) . '/src';
    $files = 0;
    $typeCount = 0;
    $functionCount = 0;
    $unexpected = [];
    $declaredTypes = [];
    $assignedSymbols = [];
    $seenAreas = [];
    $seenDetailedOwners = [];
    $scan = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($scan as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        ++$files;

        $relative = substr($file->getPathname(), strlen($root) + 1);
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
        $area = explode('/', $relative, 2)[0];
        if (!array_key_exists($area, $owners)) {
            $unexpected[] = 'Unclassified source owner: ' . $relative;

            continue;
        }

        $seenAreas[$area] = true;
        $owner = $owner;
        foreach ($detailedOwners as $prefix => $responsibility) {
            if (str_starts_with($relative, $prefix)) {
                $owner = $responsibility;
                $seenDetailedOwners[$prefix] = true;

                break;
            }
        }

        $source = file_get_contents($file->getPathname());
        if (!is_string($source)) {
            throw new RuntimeException('Unable to inspect source: ' . $relative);
        }
        $tokens = token_get_all($source, TOKEN_PARSE);
        $namespace = '';
        $hasNamedSymbol = false;

        foreach ($tokens as $index => $token) {
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = '';
                for ($i = $index + 1; isset($tokens[$i]); ++$i) {
                    $part = $tokens[$i];
                    if ($part === ';' || $part === '{') {
                        break;
                    }
                    if (is_array($part) && in_array($part[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                        $namespace .= $part[1];
                    }
                }

                continue;
            }

            if (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $next = $index + 1;
                while (isset($tokens[$next]) && is_array($tokens[$next])
                    && $tokens[$next][0] === T_WHITESPACE) {
                    ++$next;
                }
                $name = $tokens[$next] ?? null;
                if (!is_array($name) || $name[0] !== T_STRING) {
                    continue; // Anonymous class or ::class constant.
                }
                ++$typeCount;
                $hasNamedSymbol = true;
                $id = ltrim($namespace . '\\' . $name[1], '\\');
                if (isset($declaredTypes[$id])) {
                    $unexpected[] = sprintf('Duplicate type %s: %s and %s', $id, $declaredTypes[$id], $relative);
                }
                $declaredTypes[$id] = $relative;
                $assignedSymbols['type:' . $id] = $owner;

                continue;
            }

            if ($token[0] !== T_FUNCTION) {
                continue;
            }
            $next = $index + 1;
            while (isset($tokens[$next])) {
                $part = $tokens[$next];
                if ($part === '(') {
                    break; // Anonymous closure, no retained named symbol.
                }
                if (is_array($part) && $part[0] === T_STRING) {
                    ++$functionCount;
                    $hasNamedSymbol = true;
                    $assignedSymbols['function:' . $relative . ':' . $part[2] . ':' . $part[1]] = $owner;

                    break;
                }
                ++$next;
            }
        }

        if (!$hasNamedSymbol) {
            $unexpected[] = 'Production source has no named class, trait, interface, enum or function: ' . $relative;
        }
    }

    expect($unexpected)->toBe([])
        ->and(array_diff_key($owners, $seenAreas))->toBe([])
        ->and(array_diff_key($detailedOwners, $seenDetailedOwners))->toBe([])
        ->and($files)->toBeGreaterThan(0)
        ->and($typeCount)->toBeGreaterThan(0)
        ->and($functionCount)->toBeGreaterThan(0)
        ->and(count($assignedSymbols))->toBe($typeCount + $functionCount);
});
