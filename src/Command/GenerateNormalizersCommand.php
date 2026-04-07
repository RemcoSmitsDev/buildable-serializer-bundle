<?php

declare(strict_types=1);

namespace Buildable\SerializerBundle\Command;

use Buildable\SerializerBundle\Discovery\ClassDiscoveryInterface;
use Buildable\SerializerBundle\Generator\NormalizerGeneratorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console command that generates build-time optimised PHP normalizer classes
 * for every class discovered by the configured discovery strategy.
 *
 * ### Usage
 *
 *     bin/console buildable:generate-normalizers
 *     bin/console buildable:generate-normalizers --dry-run
 *     bin/console buildable:generate-normalizers --class="App\Entity\User"
 *
 * ### Options
 *
 *   --dry-run     Simulate generation without writing any files to disk.
 *   --class=FQCN  Generate a normalizer only for the given fully-qualified class
 *                 name, bypassing the configured discovery strategy.
 *   --force       Overwrite existing generated files without confirmation.
 *   --show-paths  Print the absolute path of every generated file.
 *
 * ### Exit codes
 *
 *   0  All normalizers generated successfully (or dry-run completed).
 *   1  One or more normalizers could not be generated.
 */
#[AsCommand(
    name: 'buildable:generate-normalizers',
    description: 'Generate build-time optimised PHP normalizer classes for configured domain models.',
)]
final class GenerateNormalizersCommand extends Command
{
    public function __construct(
        private readonly NormalizerGeneratorInterface $generator,
        private readonly ClassDiscoveryInterface $discovery,
        private readonly string $cacheDir,
        private readonly string $generatedNamespace,
    ) {
        parent::__construct();
    }

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    protected function configure(): void
    {
        $this
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Simulate generation without writing any files to disk.',
            )
            ->addOption(
                'class',
                'c',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Generate a normalizer only for the given FQCN(s), bypassing discovery.',
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Overwrite existing generated files without confirmation.',
            )
            ->addOption(
                'show-paths',
                null,
                InputOption::VALUE_NONE,
                'Print the absolute path of every generated (or would-be generated) file.',
            )
            ->setHelp(<<<'HELP'
                The <info>%command.name%</info> command generates build-time optimised PHP normalizer
                classes from the metadata of your domain models. The generated normalizers bypass
                Symfony's runtime reflection machinery and therefore serialize objects significantly
                faster in production workloads.

                <comment>Basic usage</comment>

                  <info>php %command.full_name%</info>

                  Discovers all configured classes and generates a normalizer for each one,
                  writing the output files to the configured cache directory.

                <comment>Dry run</comment>

                  <info>php %command.full_name% --dry-run</info>

                  Runs through the entire discovery and generation pipeline but does not write
                  any files to disk. Useful for verifying configuration in CI.

                <comment>Single class</comment>

                  <info>php %command.full_name% --class="App\Entity\User"</info>
                  <info>php %command.full_name% --class="App\Entity\User" --class="App\Dto\OrderDto"</info>

                  Bypass the configured discovery strategy and generate normalizer(s) only for
                  the explicitly specified class(es).

                <comment>Show generated file paths</comment>

                  <info>php %command.full_name% --show-paths</info>

                  Prints the absolute path of every file that was written (or would be written
                  in dry-run mode).
                HELP);
    }

    // -------------------------------------------------------------------------
    // Execution
    // -------------------------------------------------------------------------

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $isDryRun = (bool) $input->getOption('dry-run');
        $isForce = (bool) $input->getOption('force');
        $showPaths = (bool) $input->getOption('show-paths');

        /** @var string[] $explicitClasses */
        $explicitClasses = (array) $input->getOption('class');

        // ---- Header ---------------------------------------------------------
        $io->title('Buildable Serializer — Normalizer Generator');

        if ($isDryRun) {
            $io->note('Dry-run mode active. No files will be written to disk.');
        }

        $io->comment(sprintf('Cache directory : <info>%s</info>', $this->cacheDir));
        $io->comment(sprintf('Generated namespace : <info>%s</info>', $this->generatedNamespace));

        // ---- Class discovery ------------------------------------------------
        $classes = $this->resolveClasses($explicitClasses, $io);

        if ($classes === []) {
            $io->warning('No classes found to generate normalizers for.');
            $io->comment(
                'Make sure you have configured "buildable_serializer.classes" or '
                . '"buildable_serializer.namespaces" in your bundle configuration, '
                . 'or pass a class explicitly via --class.',
            );

            return Command::SUCCESS;
        }

        $io->comment(sprintf('Classes to process : <info>%d</info>', \count($classes)));

        // ---- Pre-generation table (verbose mode) ----------------------------
        if ($output->isVerbose()) {
            $io->section('Discovered classes');
            $io->listing($classes);
        }

        // ---- Ensure output directory exists (skip in dry-run) ---------------
        if (!$isDryRun) {
            try {
                $this->ensureCacheDirectory();
            } catch (\RuntimeException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
        }

        // ---- Generation loop ------------------------------------------------
        $io->section('Generating normalizers');

        $generated = [];
        $skipped = [];
        $failed = [];

        $classmap = [];
        $progressBar = $io->createProgressBar(\count($classes));
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% — %message%');
        $progressBar->start();

        foreach ($classes as $className) {
            $progressBar->setMessage($className);

            try {
                $metadata = $this->generator->getMetadataFactory()->getMetadataFor($className);
                $filePath = $this->generator->resolveFilePath($metadata);

                if (!$isDryRun && !$isForce && is_file($filePath)) {
                    $skipped[] = [
                        'class' => $className,
                        'path' => $filePath,
                        'reason' => 'already exists',
                    ];
                    $progressBar->advance();
                    continue;
                }

                if (!$isDryRun) {
                    $this->generator->generateAndWrite($metadata);
                    $classmap[$this->generator->resolveNormalizerFqcn($metadata)] = $filePath;
                }

                $generated[] = ['class' => $className, 'path' => $filePath];
            } catch (\Throwable $e) {
                $failed[] = [
                    'class' => $className,
                    'error' => $e->getMessage(),
                ];
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $io->newLine(2);

        // ---- Write classmap so RegisterGeneratedNormalizersPass can register ----
        if (!$isDryRun && $classmap !== []) {
            if (!is_dir($this->cacheDir)) {
                mkdir($this->cacheDir, 0755, true);
            }
            $autoloadContent = "<?php\n\n// @generated by buildable/serializer-bundle\n\nreturn [\n";
            foreach ($classmap as $fqcn => $fp) {
                $autoloadContent .= '    ' . var_export($fqcn, true) . ' => ' . var_export($fp, true) . ",\n";
            }
            $autoloadContent .= "];\n";
            file_put_contents($this->cacheDir . '/autoload.php', $autoloadContent);
        }

        // ---- Results summary ------------------------------------------------
        $this->renderSummary($io, $generated, $skipped, $failed, $isDryRun, $showPaths);

        return $failed !== [] ? Command::FAILURE : Command::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Output helpers
    // -------------------------------------------------------------------------

    /**
     * Render the post-generation summary table.
     *
     * @param list<array{class: string, path: string}>                    $generated
     * @param list<array{class: string, path: string, reason: string}>    $skipped
     * @param list<array{class: string, error: string}>                   $failed
     */
    private function renderSummary(
        SymfonyStyle $io,
        array $generated,
        array $skipped,
        array $failed,
        bool $isDryRun,
        bool $showPaths,
    ): void {
        $io->section('Summary');

        $action = $isDryRun ? 'Would generate' : 'Generated';

        $io->definitionList(
            [$action => \count($generated)],
            ['Skipped' => \count($skipped)],
            ['Failed' => \count($failed)],
        );

        // ---- Generated files ------------------------------------------------
        if ($generated !== [] && $showPaths) {
            $io->section($action . ' files');
            $rows = array_map(static fn(array $entry): array => [
                $entry['class'],
                $entry['path'],
            ], $generated);
            $io->table(['Class', 'File'], $rows);
        }

        // ---- Skipped files (only in verbose mode) ---------------------------
        if ($skipped !== []) {
            $io->warning(sprintf(
                '%d file(s) were skipped because they already exist. '
                . 'Re-run with <comment>--force</comment> to overwrite them.',
                \count($skipped),
            ));

            if ($io->isVerbose()) {
                $rows = array_map(static fn(array $entry): array => [
                    $entry['class'],
                    $entry['path'],
                    $entry['reason'],
                ], $skipped);
                $io->table(['Class', 'File', 'Reason'], $rows);
            }
        }

        // ---- Failures -------------------------------------------------------
        if ($failed !== []) {
            $io->error(sprintf('%d normalizer(s) could not be generated.', \count($failed)));

            $rows = array_map(static fn(array $entry): array => [
                $entry['class'],
                $entry['error'],
            ], $failed);
            $io->table(['Class', 'Error'], $rows);

            return;
        }

        // ---- Success message ------------------------------------------------
        if ($isDryRun) {
            $io->success(sprintf('Dry run completed. %d normalizer(s) would be generated.', \count($generated)));

            return;
        }

        if ($generated === [] && $skipped === []) {
            $io->note('Nothing to generate — all classes are already up to date.');

            return;
        }

        $io->success(sprintf(
            'Successfully generated %d normalizer(s) into "%s".',
            \count($generated),
            $this->cacheDir,
        ));

        $io->comment('Run <info>php bin/console cache:clear</info> to rebuild the container '
        . 'and register the new normalizers.');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Return the list of FQCNs to process.
     *
     * When $explicitClasses is non-empty those are used directly (after basic
     * existence validation). Otherwise the configured discovery strategy is used.
     *
     * @param  string[]   $explicitClasses
     * @return string[]
     */
    private function resolveClasses(array $explicitClasses, SymfonyStyle $io): array
    {
        if ($explicitClasses !== []) {
            $valid = [];

            foreach ($explicitClasses as $fqcn) {
                if (!class_exists($fqcn)) {
                    $io->warning(sprintf('Class "%s" could not be autoloaded and will be skipped.', $fqcn));
                    continue;
                }

                $valid[] = $fqcn;
            }

            return $valid;
        }

        try {
            return $this->discovery->discoverClasses();
        } catch (\Throwable $e) {
            $io->error(sprintf('Class discovery failed: %s', $e->getMessage()));

            return [];
        }
    }

    /**
     * Ensure the configured cache directory exists, creating it when needed.
     *
     * @throws \RuntimeException When the directory cannot be created.
     */
    private function ensureCacheDirectory(): void
    {
        if (is_dir($this->cacheDir)) {
            return;
        }

        if (!mkdir($this->cacheDir, 0755, true) && !is_dir($this->cacheDir)) {
            throw new \RuntimeException(sprintf(
                'Could not create the normalizer cache directory "%s". '
                . 'Please check that the parent directory is writable.',
                $this->cacheDir,
            ));
        }
    }
}
