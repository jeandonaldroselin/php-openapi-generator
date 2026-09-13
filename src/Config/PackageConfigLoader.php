<?php

declare(strict_types=1);

namespace JeanDonaldRoselin\OpenApiGenerator\Config;

use RuntimeException;

/**
 * Loads the project-level JSON configuration file (default: openapi-generator.json)
 * that describes which client(s) to generate.
 */
final class PackageConfigLoader
{
    /**
     * @return ClientDefinition[]
     */
    public static function load(string $configPath): array
    {
        if (!is_file($configPath)) {
            throw new RuntimeException(sprintf(
                "Configuration file not found: %s\n\n".
                "Create it (see vendor/jeandonaldroselin/php-openapi-generator/config/openapi-generator.dist.json for an example) ".
                "or pass --config=path/to/file.json.",
                $configPath
            ));
        }

        $raw = file_get_contents($configPath);
        if ($raw === false) {
            throw new RuntimeException(sprintf('Unable to read configuration file: %s', $configPath));
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Configuration file is not valid JSON: %s', $configPath));
        }

        // Allow either a single client definition at the top level, or a "clients" array.
        $clientsData = isset($decoded['clients']) && is_array($decoded['clients'])
            ? $decoded['clients']
            : [$decoded];

        $clients = [];
        foreach ($clientsData as $clientData) {
            if (!is_array($clientData)) {
                continue;
            }
            $clients[] = ClientDefinition::fromArray($clientData);
        }

        if ($clients === []) {
            throw new RuntimeException(sprintf('No client definitions found in configuration file: %s', $configPath));
        }

        return $clients;
    }
}
