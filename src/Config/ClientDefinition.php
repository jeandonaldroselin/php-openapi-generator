<?php

declare(strict_types=1);

namespace JeanDonaldRoselin\OpenApiGenerator\Config;

final class ClientDefinition
{
    /**
     * @param array<string,string> $additionalProperties Passed as --additional-properties to openapi-generator
     * @param array<string,string> $globalProperties Passed as --global-property to openapi-generator
     */
    public function __construct(
        public readonly string $name,
        public readonly string $inputSpec,
        public readonly string $packageName,
        public readonly string $generatorName = 'php',
        public readonly ?string $generatorVersion = null,
        public readonly array $additionalProperties = [],
        public readonly array $globalProperties = [],
        public readonly ?string $packageVersion = null,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['name', 'input_spec', 'package_name'] as $required) {
            if (empty($data[$required])) {
                throw new \InvalidArgumentException(
                    sprintf('Client configuration is missing required key "%s".', $required)
                );
            }
        }

        return new self(
            name: (string) $data['name'],
            inputSpec: (string) $data['input_spec'],
            packageName: (string) $data['package_name'],
            generatorName: (string) ($data['generator_name'] ?? 'php'),
            generatorVersion: isset($data['openapi_generator_version']) ? (string) $data['openapi_generator_version'] : null,
            additionalProperties: (array) ($data['additional_properties'] ?? []),
            globalProperties: (array) ($data['global_properties'] ?? []),
            packageVersion: isset($data['package_version']) ? (string) $data['package_version'] : null,
        );
    }
}
