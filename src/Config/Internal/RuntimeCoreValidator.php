<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config\Internal;

use Infocyph\Foundation\Config\ConfigIssue;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Config\DeploymentTopology;

final readonly class RuntimeCoreValidator
{
    public function __construct(private ConfigRepository $config) {}

    /** @return list<ConfigIssue> */
    public function container(): array
    {
        $issues = $this->allowedString(
            'app.container.compiled_activation',
            $this->config->get('app.container.compiled_activation', 'off'),
            ['off', 'always'],
        );
        $path = $this->config->get('app.container.compiled', 'bootstrap/cache/container.php');
        if (!is_string($path) || trim($path) === '') {
            $issues[] = new ConfigIssue(
                'app.container.compiled must be a non-empty application-owned path.',
                'app.container.compiled',
            );
        }
        if (!is_bool($this->config->get('app.container.lazy_loading', true))) {
            $issues[] = new ConfigIssue(
                'app.container.lazy_loading must be true or false.',
                'app.container.lazy_loading',
            );
        }

        return $issues;
    }

    /** @return list<ConfigIssue> */
    public function notifications(): array
    {
        $issues = [];
        $channels = $this->config->get('notifications.channels', []);
        if (!is_array($channels)) {
            $issues[] = new ConfigIssue(
                'notifications.channels must be a channel service map.',
                'notifications.channels',
            );
        } else {
            foreach ($channels as $name => $definition) {
                if (!is_string($name)
                    || trim($name) === ''
                    || ((!is_string($definition) || trim($definition) === '') && !is_object($definition))
                ) {
                    $issues[] = new ConfigIssue(
                        'notifications.channels must map non-empty names to service class names or channel instances.',
                        'notifications.channels',
                    );

                    break;
                }
            }
        }

        $sender = $this->config->get('notifications.email.default_sender', 'default');
        if (!is_string($sender) || trim($sender) === '') {
            $issues[] = new ConfigIssue(
                'notifications.email.default_sender must be a non-empty sender profile name.',
                'notifications.email.default_sender',
            );
        }

        $from = $this->config->get('notifications.email.default_from');
        if ($from !== null && (!is_string($from) || trim($from) === '')) {
            $issues[] = new ConfigIssue(
                'notifications.email.default_from must be null or a non-empty mailbox string.',
                'notifications.email.default_from',
            );
        }

        return $issues;
    }

    /** @return list<ConfigIssue> */
    public function responses(): array
    {
        $issues = [];
        $vendor = $this->config->get('responses.json_dispatch.vendor', 'infocyph');
        if (!is_string($vendor) || preg_match('/^[a-z0-9][a-z0-9.-]*$/D', $vendor) !== 1) {
            $issues[] = new ConfigIssue(
                'responses.json_dispatch.vendor must be a lowercase media-type token.',
                'responses.json_dispatch.vendor',
            );
        }
        $version = $this->config->get('responses.json_dispatch.application_version', '1.0.0');
        if (!is_string($version) || $version === '') {
            $issues[] = new ConfigIssue(
                'responses.json_dispatch.application_version must be a non-empty string.',
                'responses.json_dispatch.application_version',
            );
        }
        if (!is_bool($this->config->get('responses.json_dispatch.tunnel_errors', false))) {
            $issues[] = new ConfigIssue(
                'responses.json_dispatch.tunnel_errors must be true or false.',
                'responses.json_dispatch.tunnel_errors',
            );
        }

        return $issues;
    }

    /** @return list<ConfigIssue> */
    public function topology(): array
    {
        return $this->allowedString(
            'app.topology',
            $this->config->get('app.topology', DeploymentTopology::SINGLE_NODE->value),
            array_map(static fn(DeploymentTopology $topology): string => $topology->value, DeploymentTopology::cases()),
        );
    }

    /**
     * @param list<string> $allowed
     * @return list<ConfigIssue>
     */
    private function allowedString(string $key, mixed $value, array $allowed): array
    {
        return is_string($value) && in_array($value, $allowed, true)
            ? []
            : [new ConfigIssue(
                sprintf('%s must be one of: %s.', $key, implode(', ', $allowed)),
                $key,
            )];
    }
}
