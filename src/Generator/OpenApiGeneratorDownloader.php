<?php

declare(strict_types=1);

namespace JeanDonaldRoselin\OpenApiGenerator\Generator;

use RuntimeException;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;

/**
 * Downloads (and caches) the openapi-generator-cli.jar for a given version from Maven Central.
 */
final class OpenApiGeneratorDownloader
{
    private const MAVEN_URL_TEMPLATE =
        'https://repo1.maven.org/maven2/org/openapitools/openapi-generator-cli/%1$s/openapi-generator-cli-%1$s.jar';

    private Filesystem $filesystem;

    public function __construct()
    {
        $this->filesystem = new Filesystem();
    }

    public function ensureJar(string $version, string $cacheDir, SymfonyStyle $io, bool $forceDownload = false): string
    {
        $this->filesystem->mkdir($cacheDir);

        $jarPath = sprintf('%s/openapi-generator-cli-%s.jar', $cacheDir, $version);

        if (!$forceDownload && is_file($jarPath) && filesize($jarPath) > 0) {
            $io->text(sprintf('Using cached openapi-generator-cli %s (%s)', $version, $jarPath));

            return $jarPath;
        }

        $url = sprintf(self::MAVEN_URL_TEMPLATE, $version);
        $io->text(sprintf('Downloading openapi-generator-cli %s from %s ...', $version, $url));

        $client = HttpClient::create();
        $response = $client->request('GET', $url);

        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException(sprintf(
                'Unable to download openapi-generator-cli %s (HTTP %d). Check that the version exists on Maven Central: %s',
                $version,
                $response->getStatusCode(),
                $url
            ));
        }

        $tmpPath = $jarPath.'.download';
        $handle = fopen($tmpPath, 'wb');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open %s for writing.', $tmpPath));
        }

        try {
            foreach ($client->stream($response) as $chunk) {
                fwrite($handle, $chunk->getContent());
            }
        } finally {
            fclose($handle);
        }

        $this->filesystem->rename($tmpPath, $jarPath, true);

        $io->text(sprintf('Downloaded to %s', $jarPath));

        return $jarPath;
    }

    public static function assertJavaIsAvailable(string $javaBinary, SymfonyStyle $io): void
    {
        $process = new Process([$javaBinary, '-version']);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                "Java runtime not found (tried running \"%s -version\").\n".
                'openapi-generator-cli requires a JRE/JDK (11+) to run. Install Java or set '.
                'java_binary in config/packages/php_openapi_generator.yaml to the correct path.',
                $javaBinary
            ));
        }

        $io->text(trim($process->getErrorOutput() ?: $process->getOutput()));
    }
}
