<?php

declare(strict_types=1);

namespace JeanDonaldRoselin\OpenApiGenerator\Installer;

/**
 * Detects when a package already required by the project declares the same PHP namespace
 * (PSR-4 root) as the client we are about to autoload, so we don't end up with two conflicting
 * sources for the same classes (the required package's vendor/ copy, and our generated one).
 */
final class NamespaceConflictChecker
{
    /**
     * @return NamespaceConflict[]
     */
    public function findConflicts(string $projectRoot, string $generatedComposerJsonPath): array
    {
        $newNamespaces = $this->readPsr4Namespaces($generatedComposerJsonPath);
        if ($newNamespaces === []) {
            return [];
        }

        $projectComposerJson = $this->readJson($projectRoot.'/composer.json');
        $requiredPackages = array_keys(array_merge(
            $projectComposerJson['require'] ?? [],
            $projectComposerJson['require-dev'] ?? []
        ));

        $conflicts = [];
        foreach ($requiredPackages as $packageName) {
            $installedComposerJsonPath = $projectRoot.'/vendor/'.$packageName.'/composer.json';

            foreach ($this->readPsr4Namespaces($installedComposerJsonPath) as $namespace) {
                if (in_array($namespace, $newNamespaces, true)) {
                    $conflicts[] = new NamespaceConflict($packageName, $namespace);
                    break;
                }
            }
        }

        return $conflicts;
    }

    /**
     * @return array<int,string>
     */
    private function readPsr4Namespaces(string $composerJsonPath): array
    {
        $composerJson = $this->readJson($composerJsonPath);
        if ($composerJson === null) {
            return [];
        }

        $psr4 = $composerJson['autoload']['psr-4'] ?? [];
        if (!is_array($psr4)) {
            return [];
        }

        return array_map(
            static fn (string $namespace): string => rtrim($namespace, '\\'),
            array_keys($psr4)
        );
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
}
