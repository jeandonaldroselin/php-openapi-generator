<?php

declare(strict_types=1);

namespace JeanDonaldRoselin\OpenApiGenerator\Installer;

use RuntimeException;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

/**
 * Makes a generated client autoloadable by adding a PSR-4 mapping directly to the consuming
 * project's own composer.json, instead of installing it as a separate vendor/ package. This is
 * what lets a plain `composer install` work on a completely fresh checkout (empty vendor/, no
 * var/): the generated client's source directory doesn't need to exist ahead of time - autoload
 * mappings are just a namespace-to-directory hint that Composer resolves lazily at runtime.
 *
 * A post-install-cmd/post-update-cmd script (calling this tool) is registered on the project so
 * that every future `composer install`/`update` - in CI, in a Docker build, on a teammate's
 * machine - regenerates the client before the (already-registered) autoload mapping is used.
 */
final class AutoloadRegistrar
{
    private const SCRIPT_COMMAND = '@php vendor/bin/generate-client';

    /**
     * Checks for a namespace collision with an already-required foreign package and, if the
     * user agrees to resolve it, returns the package name(s) to remove in finalize(). Does not
     * run composer.
     *
     * @return string[]
     */
    public function prepare(string $projectRoot, string $generatedDir, SymfonyStyle $io): array
    {
        $conflicts = (new NamespaceConflictChecker())->findConflicts($projectRoot, $generatedDir.'/composer.json');

        $packagesToRemove = [];
        foreach ($conflicts as $conflict) {
            $io->warning(sprintf(
                "Package \"%s\" is already required by this project and declares the PHP namespace \"%s\\\\\", ".
                "the same namespace the client being generated will use.\n".
                'Keeping both would cause autoload/namespace collisions.',
                $conflict->packageName,
                $conflict->namespace
            ));

            $shouldReplace = $io->confirm(
                sprintf('Remove "%s" so the generated client can be autoloaded instead?', $conflict->packageName),
                false
            );

            if (!$shouldReplace) {
                throw new RuntimeException(sprintf(
                    'Aborted: namespace conflict with already-installed package "%s" was not resolved.',
                    $conflict->packageName
                ));
            }

            $packagesToRemove[] = $conflict->packageName;
        }

        return $packagesToRemove;
    }

    /**
     * Adds/updates the PSR-4 mapping(s) for this client on the project's own composer.json.
     */
    public function registerAutoload(string $projectRoot, string $generatedDir, SymfonyStyle $io): void
    {
        $generatedComposerJsonPath = $generatedDir.'/composer.json';
        $generatedComposerJson = $this->readJson($generatedComposerJsonPath);
        if ($generatedComposerJson === null) {
            throw new RuntimeException(sprintf('Unable to read %s', $generatedComposerJsonPath));
        }

        $psr4 = $generatedComposerJson['autoload']['psr-4'] ?? [];
        if (!is_array($psr4) || $psr4 === []) {
            throw new RuntimeException(sprintf(
                '%s does not declare an autoload.psr-4 mapping - cannot register it.',
                $generatedComposerJsonPath
            ));
        }

        $composerJsonPath = $projectRoot.'/composer.json';
        $composer = $this->readJson($composerJsonPath);
        if ($composer === null) {
            throw new RuntimeException(sprintf('Project composer.json not found at %s', $composerJsonPath));
        }

        $composer['autoload'] ??= [];
        $composer['autoload']['psr-4'] ??= [];

        foreach ($psr4 as $namespace => $subPath) {
            $target = $this->relativePath($projectRoot, $generatedDir.'/'.$subPath);
            $composer['autoload']['psr-4'][$namespace] = $target;

            $io->text(sprintf('Registered "%s" -> %s in composer.json autoload.psr-4.', $namespace, $target));
        }

        $this->writeJson($composerJsonPath, $composer);
    }

    /**
     * Ensures composer.json has a post-install-cmd/post-update-cmd script that (re)runs this
     * tool, so every future fresh `composer install`/`update` regenerates the client(s) before
     * the autoload mapping is used - no manual bootstrap step needed.
     */
    public function ensureScriptsRegistered(string $projectRoot, SymfonyStyle $io): void
    {
        $composerJsonPath = $projectRoot.'/composer.json';
        $composer = $this->readJson($composerJsonPath);
        if ($composer === null) {
            throw new RuntimeException(sprintf('Project composer.json not found at %s', $composerJsonPath));
        }

        $composer['scripts'] ??= [];
        $changed = false;

        foreach (['post-install-cmd', 'post-update-cmd'] as $event) {
            $composer['scripts'][$event] ??= [];
            if (is_string($composer['scripts'][$event])) {
                $composer['scripts'][$event] = [$composer['scripts'][$event]];
            }

            if (!in_array(self::SCRIPT_COMMAND, $composer['scripts'][$event], true)) {
                $composer['scripts'][$event][] = self::SCRIPT_COMMAND;
                $changed = true;
            }
        }

        if ($changed) {
            $this->writeJson($composerJsonPath, $composer);
            $io->text(sprintf(
                'Registered "%s" as a post-install-cmd/post-update-cmd script, so future fresh installs regenerate automatically.',
                self::SCRIPT_COMMAND
            ));
        }
    }

    /**
     * Runs composer exactly once for the whole batch: removes any conflicting foreign packages,
     * then dumps the autoloader so the newly registered PSR-4 mappings take effect immediately.
     * Unlike installing a vendor/ package, this needs no network access and no dependency
     * resolution, so it stays fast regardless of how many clients were (re)generated.
     *
     * @param string[] $packagesToRemove
     */
    public function finalize(string $projectRoot, array $packagesToRemove, SymfonyStyle $io, bool $noScripts = false): void
    {
        $packagesToRemove = array_values(array_unique($packagesToRemove));
        if ($packagesToRemove !== []) {
            $args = array_merge(['remove'], $packagesToRemove, $noScripts ? ['--no-scripts'] : [], ['--no-interaction']);
            $this->runComposer($projectRoot, $args, $io);
        }

        $this->runComposer($projectRoot, ['dump-autoload', '--optimize', '--no-interaction'], $io);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function writeJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents($path, $json."\n");
    }

    private function relativePath(string $projectRoot, string $absolutePath): string
    {
        $projectRoot = rtrim($projectRoot, '/');
        if (str_starts_with($absolutePath, $projectRoot.'/')) {
            return substr($absolutePath, strlen($projectRoot) + 1);
        }

        return $absolutePath;
    }

    /**
     * @param array<int,string> $args
     */
    private function runComposer(string $projectRoot, array $args, SymfonyStyle $io): void
    {
        $composerBinary = $this->findComposerBinary();
        $command = array_merge([$composerBinary], $args);

        $io->text('> '.implode(' ', $command));

        $process = new Process($command, $projectRoot, null, null, 600);
        $process->run(function (string $type, string $buffer) use ($io): void {
            $io->write($buffer);
        });

        if (!$process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                'Command "%s" failed (exit code %d).',
                implode(' ', $command),
                $process->getExitCode() ?? -1
            ));
        }
    }

    private function findComposerBinary(): string
    {
        $envBinary = getenv('COMPOSER_BINARY');
        if (is_string($envBinary) && $envBinary !== '') {
            return $envBinary;
        }

        return 'composer';
    }
}
