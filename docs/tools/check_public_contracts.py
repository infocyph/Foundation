#!/usr/bin/env python3
"""Fail docs CI when referenced Foundation 4 public API/feature contracts drift.

This is deliberately a narrow source-declaration audit, not a PHP parser or
an integration test. It catches renamed or removed documented methods and
feature IDs; PHP lint and Sphinx check other documentation properties.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DOCS = ROOT / "docs"

# Each group names source code, its public reference page, and audited methods.
CONTRACTS: tuple[tuple[str, str, tuple[str, ...]], ...] = (
    (
        "src/Foundation.php", "reference/application-api.rst",
        ("web", "cli", "worker", "scheduler", "preset"),
    ),
    (
        "src/Application/Application.php", "reference/application-api.rst",
        ("create", "boot", "booted", "make", "has", "config", "providers",
         "execution", "runtimeMode", "handle", "http", "container",
         "runtime", "replaceForTesting"),
    ),
    (
        "src/Application/ServiceProviderInterface.php",
        "reference/application-api.rst", ("contribute", "boot"),
    ),
    (
        "src/Validation/FormRequest.php", "reference/http-security-api.rst",
        ("rules", "validate", "validated", "__invoke", "input", "options"),
    ),
    (
        "src/Http/JsonDispatch/JsonDispatchResponseFactory.php",
        "reference/http-security-api.rst",
        ("success", "fail", "error", "failureFromValidation", "paginated"),
    ),
    (
        "src/Auth/AuthServices.php", "reference/http-security-api.rst",
        ("accounts", "accountProvider", "authenticator", "sessions",
         "tokens", "authorizer", "gate", "roles", "permissions", "principals",
         "mfa", "passkeys", "oauth", "passwordHasher", "passwordVerifier",
         "passwordPolicy", "passwordChanges", "passwordResets",
         "passwordless", "emailVerification", "rememberMe", "delegation",
         "impersonation", "stepUp", "devices", "lockouts"),
    ),
    (
        "src/Command/CommandHandlerInterface.php",
        "reference/console-testing-api.rst", ("define", "run"),
    ),
    (
        "src/Command/CommandDefinition.php",
        "reference/console-testing-api.rst",
        ("name", "description", "group", "alias", "argument", "option",
         "runtime", "scope", "execution", "capability"),
    ),
    (
        "src/Command/CommandContext.php",
        "reference/console-testing-api.rst",
        ("input", "io", "executionId", "definition", "descriptor"),
    ),
    (
        "src/Scheduling/Schedule.php", "reference/console-testing-api.rst",
        ("command", "add", "entries"),
    ),
    (
        "src/Scheduling/ScheduledCommand.php",
        "reference/console-testing-api.rst",
        ("everyMinute", "hourly", "dailyAt", "cron", "timezone", "key",
         "onOneServer", "withoutOverlap", "arguments", "timeout", "memoryLimit"),
    ),
    (
        "src/Testing/TestKit.php", "reference/console-testing-api.rst",
        ("http", "auth", "database", "cache", "fakeCache", "fakeHttp",
         "fakeMessaging", "fakeNotifications", "freezeTime", "files",
         "messaging", "sessions"),
    ),
    (
        "src/Testing/HttpTestClient.php",
        "reference/console-testing-api.rst",
        ("get", "post", "put", "patch", "delete", "request", "withHeaders"),
    ),
    (
        "src/Testing/TestResponse.php", "reference/console-testing-api.rst",
        ("assertStatus", "assertHeader", "assertJson", "assertJsonPath",
         "body", "json", "response", "status"),
    ),
    (
        "src/Config/ConfigRepository.php", "reference/configuration.rst",
        ("get", "all"),
    ),
)

METHOD = re.compile(r"\b(?:public|protected)\s+(?:static\s+)?function\s+(\w+)\s*\(")
FEATURE_IDS = re.compile(r"private const array FEATURE_IDS\s*=\s*\[(.*?)\n\s*\];", re.S)
FEATURE_GROUPS = re.compile(r"'([a-z]+)'\s*=>\s*\[([^\]]*)\]")
FEATURE_NAME = re.compile(r"'([a-z]+)'")

CONFIG_TEMPLATES = (
    "cache.php", "communication.php", "database.php", "filesystem.php",
    "logging.php", "messaging.php", "notifications.php", "operations.php",
    "responses.php", "security.php", "session.php", "validation.php",
)


def load(path: Path, failures: list[str]) -> str:
    if not path.is_file():
        failures.append(f"missing file: {path.relative_to(ROOT)}")
        return ""
    return path.read_text(encoding="utf-8")


def main() -> int:
    failures: list[str] = []
    checked_methods = 0
    for source, reference, methods in CONTRACTS:
        actual_source = load(ROOT / source, failures)
        document = load(DOCS / reference, failures)
        public_methods = set(METHOD.findall(actual_source))
        for method in methods:
            checked_methods += 1
            if method not in public_methods:
                failures.append(f"{source}: declared public/protected method {method}() missing")
            if f"{method}(" not in document:
                failures.append(f"docs/{reference}: reference to {method}() missing")

    catalog = load(ROOT / "src/Module/ModuleArchitectureCatalog.php", failures)
    matrix = load(DOCS / "tutorials/choose-capabilities.rst", failures)
    feature_match = FEATURE_IDS.search(catalog)
    if feature_match is None:
        failures.append("src/Module/ModuleArchitectureCatalog.php: FEATURE_IDS map not found")
    else:
        modules = FEATURE_GROUPS.findall(feature_match.group(1))
        ids = {f"{group}.{name}" for group, items in modules
               for name in FEATURE_NAME.findall(items)}
        if len(modules) != 3 or len(ids) != 8:
            failures.append(f"feature taxonomy changed: groups={len(modules)}, features={len(ids)}")
        for feature in sorted(ids):
            if feature not in matrix:
                failures.append(f"docs/tutorials/choose-capabilities.rst: {feature} missing")

    config = load(DOCS / "reference/configuration.rst", failures)
    for name in CONFIG_TEMPLATES:
        if not (ROOT / "resources/config" / name).is_file():
            failures.append(f"resources/config/{name}: template missing")
        if f"``{name}``" not in config:
            failures.append(f"docs/reference/configuration.rst: {name} missing")

    for failure in failures:
        print(f"FAIL: {failure}", file=sys.stderr)
    print(f"Documentation public contracts: {checked_methods} methods, "
          f"12 configuration templates, 3 feature groups / 8 features; "
          f"failures: {len(failures)}")
    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main())
