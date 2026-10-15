<?php

declare(strict_types=1);

use Infocyph\Foundation\Command\CommandCatalog;
use Infocyph\Foundation\Module\ModuleFeatureCommandCatalog;

it('keeps all documented Foundation 4 module commands in the executable command catalog', function (): void {
    $document = file_get_contents(dirname(__DIR__, 2) . '/docs/modules.md');
    expect($document)->toBeString();

    preg_match_all('/^php infbyte (module:[a-z:]+)(?:\s|$)/m', $document, $matches);
    $examples = array_values(array_unique($matches[1]));
    sort($examples);
    expect($examples)->not->toBeEmpty();

    $catalog = new CommandCatalog();
    foreach ($examples as $name) {
        expect($catalog->find($name))->not->toBeNull();
    }
    expect($examples)->toContain(
        'module:list',
        'module:show',
        'module:doctor',
        'module:plan',
        'module:install',
        'module:remove',
        'module:enable',
        'module:disable',
        'module:repair',
        'module:config:publish',
        'module:schema:status',
        'module:schema:install',
        'module:schema:sync',
    );
});

it('keeps documented feature inventory and v2 CLI catalog in sync', function (): void {
    $document = file_get_contents(dirname(__DIR__, 2) . '/docs/modules.md');
    expect($document)->toBeString()
        ->and($document)->toContain('schema_version: 2');

    $inventory = new ModuleFeatureCommandCatalog()->inventory();
    expect(array_column($inventory['modules'], 'name'))->toBe(['data', 'security', 'execution']);

    $features = array_merge(...array_column($inventory['modules'], 'features'));
    expect($features)->toHaveCount(9);
    foreach ($features as $feature) {
        expect($document)->toContain(chr(96) . $feature . chr(96));
    }

    expect($document)->toContain('never force-overwrites')
        ->and($document)->toContain('no unsafe compensation')
        ->and($document)->toContain('never deletes application data');
});


it('keeps the Foundation 4 migration commands compatible with the final v2 catalog', function (): void {
    $guide = file_get_contents(dirname(__DIR__, 2) . '/docs/foundation-4-migration.md');
    expect($guide)->toBeString()
        ->and($guide)->toContain('schema_version: 2')
        ->and($guide)->toContain('data.database')
        ->and($guide)->toContain('security.mfa')
        ->and($guide)->toContain('execution.messaging');

    preg_match_all('/^php infbyte (module:[a-z:]+)(?:\s|$)/m', $guide, $matches);
    $catalog = new CommandCatalog();
    foreach (array_unique($matches[1]) as $name) {
        expect($catalog->find($name))->not->toBeNull();
    }

    // Old names may appear in historical prose, but never as runnable v2 examples.
    expect($guide)->not->toMatch('/^php infbyte module:(?:show|doctor|plan|install) (?:database|messaging|auth)(?:\s|$)/m');
});

it('keeps draft release notes explicit about the v2 features and unfinished host certification', function (): void {
    $notes = file_get_contents(dirname(__DIR__, 2) . '/docs/foundation-4.0-release-notes-draft.md');
    $inventory = new ModuleFeatureCommandCatalog()->inventory();

    expect($notes)->toContain('schema_version: 2')
        ->and($notes)->toContain('unpublished draft')
        ->and($notes)->toContain('2% successful-RPM regression');

    foreach (array_merge(...array_column($inventory['modules'], 'features')) as $feature) {
        expect($notes)->toContain($feature);
    }
});
