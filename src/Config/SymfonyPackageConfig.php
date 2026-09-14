<?php

declare(strict_types=1);

namespace JeanDonaldRoselin\OpenApiGenerator\Config;

final class SymfonyPackageConfig
{
    public function __construct(
        public readonly ?string $configPath = null,
        public readonly string $cacheDir = 'var/openapi-generator/cache',
        public readonly string $generatedDir = '.generated',
        public readonly string $javaBinary = 'java',
        public readonly string $defaultGeneratorVersion = '7.9.0',
    ) {
    }
}
