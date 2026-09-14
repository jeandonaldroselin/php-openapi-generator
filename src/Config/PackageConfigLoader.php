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

        // Allow either a single client definition at the top level, or a "clients" array
        // alongside root-level defaults (generator_name, openapi_generator_version,
        // additional_properties, generated_path) inherited by every client, unless a client
        // sets its own value for that same key.
        $hasClientsWrapper = isset($decoded['clients']) && is_array($decoded['clients']);
        $clientsData = $hasClientsWrapper ? $decoded['clients'] : [$decoded];
        $rootDefaults = $hasClientsWrapper ? $decoded : [];

        $clients = [];
        foreach ($clientsData as $clientData) {
            if (!is_array($clientData)) {
                continue;
            }
            $clients[] = ClientDefinition::fromArray(self::mergeWithRootDefaults($rootDefaults, $clientData));
        }

        if ($clients === []) {
            throw new RuntimeException(sprintf('No client definitions found in configuration file: %s', $configPath));
        }

        return $clients;
    }

    /**
     * @param array<string,mixed> $rootDefaults
     * @param array<string,mixed> $clientData
     * @return array<string,mixed>
     */
    private static function mergeWithRootDefaults(array $rootDefaults, array $clientData): array
    {
        // Scalar settings: the client's own value wins if set, otherwise fall back to the
        // root default.
        foreach (['generator_name', 'openapi_generator_version'] as $key) {
            if (!array_key_exists($key, $clientData) && array_key_exists($key, $rootDefaults)) {
                $clientData[$key] = $rootDefaults[$key];
            }
        }

        // generated_path is a base directory, not a literal path to reuse as-is: a root-level
        // default gives each client its own "<root generated_path>/<client name>" subdirectory,
        // same as the built-in default (var/openapi-generator/generated/<name>) - unless the
        // client sets its own generated_path, which is then used verbatim.
        if (!array_key_exists('generated_path', $clientData)
            && isset($rootDefaults['generated_path'])
            && is_string($rootDefaults['generated_path'])
            && isset($clientData['name'])
        ) {
            $clientData['generated_path'] = rtrim($rootDefaults['generated_path'], '/').'/'.$clientData['name'];
        }

        // additional_properties merges key by key: a property set on the client overrides the
        // same property from the root default, but other root properties still apply.
        $rootAdditionalProperties = $rootDefaults['additional_properties'] ?? [];
        if (is_array($rootAdditionalProperties) && $rootAdditionalProperties !== []) {
            $clientAdditionalProperties = $clientData['additional_properties'] ?? [];
            $clientData['additional_properties'] = array_merge(
                $rootAdditionalProperties,
                is_array($clientAdditionalProperties) ? $clientAdditionalProperties : []
            );
        }

        return $clientData;
    }
}
