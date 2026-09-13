<?php

declare(strict_types=1);

namespace JeanDonaldRoselin\OpenApiGenerator\Generator;

use RuntimeException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads an OpenAPI spec (local file or URL, YAML or JSON) into a plain PHP array.
 * Parsing (rather than comparing raw bytes) is what lets callers detect "nothing
 * functionally changed" even if comments, formatting or key order changed.
 */
final class SpecReader
{
    /**
     * @return array<string,mixed>
     */
    public function readAsArray(string $inputSpec): array
    {
        $content = $this->fetch($inputSpec);

        $path = preg_match('#^https?://#i', $inputSpec) === 1
            ? (parse_url($inputSpec, PHP_URL_PATH) ?: $inputSpec)
            : $inputSpec;
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $data = $extension === 'json' ? json_decode($content, true) : Yaml::parse($content);

        if (!is_array($data)) {
            throw new RuntimeException(sprintf('Unable to parse OpenAPI spec "%s" as YAML or JSON.', $inputSpec));
        }

        return $data;
    }

    private function fetch(string $inputSpec): string
    {
        if (preg_match('#^https?://#i', $inputSpec) === 1) {
            $response = HttpClient::create()->request('GET', $inputSpec);

            return $response->getContent();
        }

        if (!is_file($inputSpec)) {
            throw new RuntimeException(sprintf('OpenAPI spec file not found: %s', $inputSpec));
        }

        $content = file_get_contents($inputSpec);
        if ($content === false) {
            throw new RuntimeException(sprintf('Unable to read OpenAPI spec: %s', $inputSpec));
        }

        return $content;
    }
}
