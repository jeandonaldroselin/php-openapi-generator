<?php

declare(strict_types=1);

namespace JeanDonaldRoselin\OpenApiGenerator\Command;

use JeanDonaldRoselin\OpenApiGenerator\Config\PackageConfigLoader;
use JeanDonaldRoselin\OpenApiGenerator\Config\SymfonyPackageConfigLoader;
use JeanDonaldRoselin\OpenApiGenerator\Generator\ClientGenerator;
use JeanDonaldRoselin\OpenApiGenerator\Generator\OpenApiGeneratorDownloader;
use JeanDonaldRoselin\OpenApiGenerator\Installer\AutoloadRegistrar;
use JeanDonaldRoselin\OpenApiGenerator\ProjectLocator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

#[AsCommand(
    name: 'generate-client',
    description: 'Download openapi-generator, generate PHP API client(s) and autoload them directly from var/.'
)]
final class GenerateClientCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to the JSON config file describing the client(s) to generate.')
            ->addOption('client', null, InputOption::VALUE_REQUIRED, 'Only generate the client with this "name" from the config file.')
            ->addOption('generator-version', null, InputOption::VALUE_REQUIRED, 'Override the openapi-generator-cli version to use.')
            ->addOption('skip-install', null, InputOption::VALUE_NONE, 'Only generate the client, do not touch composer.json / run composer.')
            ->addOption('force-download', null, InputOption::VALUE_NONE, 'Re-download openapi-generator-cli.jar even if already cached.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Regenerate even if the OpenAPI spec has not functionally changed since last run.')
            ->addOption('no-scripts', null, InputOption::VALUE_NONE, 'Pass --no-scripts to the composer remove call this tool makes when resolving a namespace conflict.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $startedAt = hrtime(true);

        try {
            $projectRoot = ProjectLocator::findProjectRoot();
            $io->title('generate-client');
            $io->text(sprintf('Project root: %s', $projectRoot));

            $symfonyConfig = SymfonyPackageConfigLoader::load($projectRoot);

            $configPath = $input->getOption('config')
                ?? $symfonyConfig->configPath
                ?? $projectRoot.'/openapi-generator.json';

            $clients = PackageConfigLoader::load($configPath);

            $clientNameFilter = $input->getOption('client');
            if ($clientNameFilter !== null) {
                $clients = array_values(array_filter(
                    $clients,
                    static fn ($client) => $client->name === $clientNameFilter
                ));

                if ($clients === []) {
                    $io->error(sprintf('No client named "%s" found in %s', $clientNameFilter, $configPath));

                    return Command::FAILURE;
                }
            }

            $filesystem = new Filesystem();
            $filesystem->mkdir($symfonyConfig->cacheDir);
            $filesystem->mkdir($symfonyConfig->generatedDir);

            $javaBinary = $symfonyConfig->javaBinary;
            OpenApiGeneratorDownloader::assertJavaIsAvailable($javaBinary, $io);

            $downloader = new OpenApiGeneratorDownloader();
            $generator = new ClientGenerator();
            $registrar = new AutoloadRegistrar();
            $skipInstall = (bool) $input->getOption('skip-install');

            $packagesToRemove = [];
            $packagesToInstall = [];
            $anyClientProcessed = false;

            foreach ($clients as $client) {
                $io->section(sprintf('Client: %s', $client->name));

                $version = $input->getOption('generator-version')
                    ?? $client->generatorVersion
                    ?? $symfonyConfig->defaultGeneratorVersion;

                $jarPath = $downloader->ensureJar(
                    $version,
                    $symfonyConfig->cacheDir,
                    $io,
                    (bool) $input->getOption('force-download')
                );

                $outputDir = $symfonyConfig->generatedDir.'/'.$client->name;

                $generator->generate(
                    $client,
                    $jarPath,
                    $outputDir,
                    $javaBinary,
                    $io,
                    (bool) $input->getOption('force')
                );

                if ($skipInstall) {
                    $io->text(sprintf('--skip-install set: generated client left at %s', $outputDir));
                } else {
                    $packagesToRemove = array_merge(
                        $packagesToRemove,
                        $registrar->prepare($projectRoot, $outputDir, $io)
                    );
                    $packagesToInstall = array_merge(
                        $packagesToInstall,
                        $registrar->registerAutoload($projectRoot, $outputDir, $io)
                    );
                    $anyClientProcessed = true;
                }

                $io->text(sprintf('Elapsed since start: %s', $this->formatElapsed($startedAt)));
            }

            if (!$skipInstall && $anyClientProcessed) {
                $io->section('Updating autoload');
                $registrar->ensureScriptsRegistered($projectRoot, $io);
                $registrar->finalize(
                    $projectRoot,
                    $packagesToRemove,
                    $packagesToInstall,
                    $io,
                    (bool) $input->getOption('no-scripts')
                );
            }

            $io->success(sprintf('Done in %s.', $this->formatElapsed($startedAt)));

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    private function formatElapsed(int $startedAt): string
    {
        $seconds = (hrtime(true) - $startedAt) / 1_000_000_000;

        if ($seconds < 60) {
            return sprintf('%.1fs', $seconds);
        }

        $minutes = (int) floor($seconds / 60);
        $remainingSeconds = $seconds - $minutes * 60;

        return sprintf('%dm %.1fs', $minutes, $remainingSeconds);
    }
}
