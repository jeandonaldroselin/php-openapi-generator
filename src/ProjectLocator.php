<?php

declare(strict_types=1);

namespace JeanDonaldRoselin\OpenApiGenerator;

use RuntimeException;

final class ProjectLocator
{
    /**
     * Walks up from the current working directory until it finds a composer.json
     * that is not this package's own composer.json (i.e. the consuming project's root).
     */
    public static function findProjectRoot(): string
    {
        $dir = getcwd();
        if ($dir === false) {
            throw new RuntimeException('Unable to determine the current working directory.');
        }

        $ownComposerFile = dirname(__DIR__).'/composer.json';
        $ownComposerReal = realpath($ownComposerFile) ?: $ownComposerFile;

        $probe = $dir;
        for ($i = 0; $i < 10; $i++) {
            $composerFile = $probe.'/composer.json';
            if (is_file($composerFile) && realpath($composerFile) !== $ownComposerReal) {
                return $probe;
            }

            $parent = dirname($probe);
            if ($parent === $probe) {
                break;
            }
            $probe = $parent;
        }

        throw new RuntimeException(
            'Could not locate a project composer.json. Run generate-client from your project root '.
            '(the directory containing your composer.json).'
        );
    }
}
