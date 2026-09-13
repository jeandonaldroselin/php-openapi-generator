<?php

declare(strict_types=1);

namespace JeanDonaldRoselin\OpenApiGenerator\Installer;

final class NamespaceConflict
{
    public function __construct(
        public readonly string $packageName,
        public readonly string $namespace,
    ) {
    }
}
