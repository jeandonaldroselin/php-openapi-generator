<?php

declare(strict_types=1);

namespace JeanDonaldRoselin\OpenApiGenerator\Generator;

use RuntimeException;
use JeanDonaldRoselin\OpenApiGenerator\Config\ClientDefinition;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Runs openapi-generator-cli.jar to generate the PHP client for one client definition.
 */
final class ClientGenerator
{
    private const SPEC_SNAPSHOT_RELATIVE_PATH = '.openapi-generator/source-spec.json';
    private const GENERATION_CONFIG_RELATIVE_PATH = '.openapi-generator/generation-config.json';

    /**
     * @return bool true if the client was (re)generated, false if generation was skipped
     *              because neither the OpenAPI spec nor the generation config (generator_name,
     *              openapi_generator_version, additional_properties, global_properties) have
     *              functionally changed since last time.
     */
    public function generate(
        ClientDefinition $client,
        string $jarPath,
        string $version,
        string $outputDir,
        string $javaBinary,
        SymfonyStyle $io,
        bool $forceRegenerate = false,
    ): bool {
        $snapshotPath = $outputDir.'/'.self::SPEC_SNAPSHOT_RELATIVE_PATH;
        $generationConfigPath = $outputDir.'/'.self::GENERATION_CONFIG_RELATIVE_PATH;
        $currentGenerationConfig = $this->buildGenerationConfig($client, $version);

        $currentSpec = null;
        try {
            $currentSpec = (new SpecReader())->readAsArray($client->inputSpec);
        } catch (\Throwable) {
            // Can't read/parse the spec ahead of time (e.g. transient network issue) - fall
            // through and let openapi-generator-cli itself report the real error below.
        }

        if (!$forceRegenerate
            && $currentSpec !== null
            && $this->matchesStoredJson($currentSpec, $snapshotPath)
            && $this->matchesStoredJson($currentGenerationConfig, $generationConfigPath)
        ) {
            $io->text(sprintf(
                'No functional changes detected in the OpenAPI spec or generation config for client "%s" - '.
                'skipping regeneration (keeping the client already generated at %s).',
                $client->name,
                $outputDir
            ));

            return false;
        }

        (new Filesystem())->remove($outputDir);

        [$vendorName, $projectName] = $this->splitPackageName($client->packageName);

        $additionalProperties = array_merge([
            'composerVendorName' => $vendorName,
            'composerProjectName' => $projectName,
        ], $client->additionalProperties);

        $command = [
            $javaBinary,
            '-jar',
            $jarPath,
            'generate',
            '-i', $client->inputSpec,
            '-g', $client->generatorName,
            '-o', $outputDir,
            '--additional-properties', $this->toPropertiesString($additionalProperties),
        ];

        if ($client->globalProperties !== []) {
            $command[] = '--global-property';
            $command[] = $this->toPropertiesString($client->globalProperties);
        }

        $io->text(sprintf('Generating client "%s" -> %s', $client->name, $outputDir));
        $io->text(implode(' ', array_map(static fn (string $arg) => escapeshellarg($arg), $command)));

        $process = new Process($command, null, null, null, 600);
        $process->run(function (string $type, string $buffer) use ($io): void {
            $io->write($buffer);
        });

        if (!$process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                'openapi-generator-cli failed while generating client "%s" (exit code %d).',
                $client->name,
                $process->getExitCode() ?? -1
            ));
        }

        $composerJsonPath = $outputDir.'/composer.json';
        if (!is_file($composerJsonPath)) {
            throw new RuntimeException(sprintf(
                'Generation for client "%s" completed but no composer.json was produced in %s. '.
                'Check that generator "%s" supports PHP/Composer output.',
                $client->name,
                $outputDir,
                $client->generatorName
            ));
        }

        $this->ensurePackageMetadata($composerJsonPath, $client, $currentSpec);

        if ($currentSpec !== null) {
            $this->storeJson($currentSpec, $snapshotPath);
        }
        $this->storeJson($currentGenerationConfig, $generationConfigPath);

        return true;
    }

    /**
     * The subset of a client's configuration that actually affects the generated code's
     * content (as opposed to e.g. package_name/package_version/generated_path, which only
     * affect metadata or where the output goes).
     *
     * @return array<string,mixed>
     */
    private function buildGenerationConfig(ClientDefinition $client, string $version): array
    {
        return [
            'generator_name' => $client->generatorName,
            'generator_version' => $version,
            'additional_properties' => $client->additionalProperties,
            'global_properties' => $client->globalProperties,
        ];
    }

    /**
     * @param array<string,mixed> $current
     */
    private function matchesStoredJson(array $current, string $storedPath): bool
    {
        if (!is_file($storedPath)) {
            return false;
        }

        $raw = file_get_contents($storedPath);
        if ($raw === false) {
            return false;
        }

        /** @var mixed $stored */
        $stored = json_decode($raw, true);

        // Loose comparison: same keys/values regardless of array order, so purely cosmetic
        // changes (comments, formatting, key reordering) don't trigger a rebuild.
        return is_array($stored) && $stored == $current;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function storeJson(array $data, string $path): void
    {
        (new Filesystem())->mkdir(dirname($path));
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents($path, $json);
    }

    /**
     * Some openapi-generator versions/templates do not put a "name" (and never a "version")
     * key in the generated composer.json even when composerVendorName/composerProjectName are
     * set. Fill them in for documentation purposes (this composer.json is never installed as a
     * dependency - the client is autoloaded directly from the consuming project, see
     * AutoloadRegistrar).
     *
     * @param array<string,mixed>|null $parsedSpec Already-parsed spec, if available, to avoid re-reading it.
     */
    private function ensurePackageMetadata(string $composerJsonPath, ClientDefinition $client, ?array $parsedSpec): void
    {
        $raw = file_get_contents($composerJsonPath);
        if ($raw === false) {
            throw new RuntimeException(sprintf('Unable to read %s', $composerJsonPath));
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('%s does not contain a valid JSON object.', $composerJsonPath));
        }

        $specVersion = $parsedSpec['info']['version'] ?? null;
        $version = $client->packageVersion
            ?? (is_string($specVersion) && $specVersion !== '' ? $specVersion : null)
            ?? '1.0.0';

        unset($decoded['name'], $decoded['version']);
        $decoded = ['name' => $client->packageName, 'version' => $version] + $decoded;

        $json = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents($composerJsonPath, $json."\n");
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitPackageName(string $packageName): array
    {
        $parts = explode('/', $packageName, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new RuntimeException(sprintf(
                'package_name "%s" must be a valid Composer package name in the form "vendor/project".',
                $packageName
            ));
        }

        return [$parts[0], $parts[1]];
    }

    /**
     * @param array<string,string> $properties
     */
    private function toPropertiesString(array $properties): string
    {
        $pairs = [];
        foreach ($properties as $key => $value) {
            $pairs[] = sprintf('%s=%s', $key, $value);
        }

        return implode(',', $pairs);
    }
}
