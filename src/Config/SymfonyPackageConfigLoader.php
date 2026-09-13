<?php

declare(strict_types=1);

namespace JeanDonaldRoselin\OpenApiGenerator\Config;

use Symfony\Component\Yaml\Yaml;

/**
 * Reads the optional config/packages/php_openapi_generator.yaml file that a Symfony
 * project can use to point generate-client at its JSON config and override defaults,
 * the same way any other Symfony bundle config would live in config/packages/.
 *
 * This is read directly (no Symfony kernel/container needed), so the command also
 * works in a plain Composer project that merely follows the config/packages/ convention.
 */
final class SymfonyPackageConfigLoader
{
    private const DEFAULT_RELATIVE_PATH = 'config/packages/php_openapi_generator.yaml';
    private const ROOT_KEY = 'php_openapi_generator';

    public static function load(string $projectRoot): SymfonyPackageConfig
    {
        $file = $projectRoot.'/'.self::DEFAULT_RELATIVE_PATH;

        $defaults = new SymfonyPackageConfig();

        if (!is_file($file)) {
            return $defaults;
        }

        /** @var mixed $parsed */
        $parsed = Yaml::parseFile($file);
        $data = is_array($parsed) && isset($parsed[self::ROOT_KEY]) && is_array($parsed[self::ROOT_KEY])
            ? $parsed[self::ROOT_KEY]
            : (is_array($parsed) ? $parsed : []);

        $resolve = static fn (?string $value): ?string => $value === null
            ? null
            : self::resolvePath($projectRoot, self::resolvePlaceholders($value, $projectRoot));

        return new SymfonyPackageConfig(
            configPath: isset($data['config_path']) ? $resolve((string) $data['config_path']) : null,
            cacheDir: isset($data['cache_dir'])
                ? (string) $resolve((string) $data['cache_dir'])
                : $projectRoot.'/'.$defaults->cacheDir,
            generatedDir: isset($data['generated_dir'])
                ? (string) $resolve((string) $data['generated_dir'])
                : $projectRoot.'/'.$defaults->generatedDir,
            javaBinary: (string) ($data['java_binary'] ?? $defaults->javaBinary),
            defaultGeneratorVersion: (string) ($data['default_generator_version'] ?? $defaults->defaultGeneratorVersion),
        );
    }

    private static function resolvePlaceholders(string $value, string $projectRoot): string
    {
        return str_replace('%kernel.project_dir%', $projectRoot, $value);
    }

    private static function resolvePath(string $projectRoot, string $path): string
    {
        if (str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return $path;
        }

        return $projectRoot.'/'.$path;
    }
}
