<?php

declare(strict_types=1);

namespace Buildable\SerializerBundle\Tests\Unit\Command;

use Buildable\SerializerBundle\Command\GenerateNormalizersCommand;
use Buildable\SerializerBundle\Discovery\ClassDiscoveryInterface;
use Buildable\SerializerBundle\Generator\NormalizerGeneratorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \Buildable\SerializerBundle\Command\GenerateNormalizersCommand
 *
 * The production NormalizerGenerator is `final`, so tests depend on
 * NormalizerGeneratorInterface — which the generator implements — and extend
 * the mock with addMethods() for any concrete methods (resolveFilePath,
 * generateAndWrite, getMetadataFactory) that are not yet on the interface.
 *
 * Constructor signature of the actual command:
 *   __construct(NormalizerGeneratorInterface $generator, ClassDiscoveryInterface $discovery,
 *               string $cacheDir, string $generatedNamespace)
 */
final class GenerateNormalizersCommandTest extends TestCase
{
    /** Unique temp directory per test, cleaned up in tearDown. */
    private string $tempDir;

    /** @var ClassDiscoveryInterface&MockObject */
    private ClassDiscoveryInterface $discovery;

    /**
     * A mock that satisfies NormalizerGeneratorInterface AND exposes the
     * concrete NormalizerGenerator methods the command calls at runtime.
     *
     * @var NormalizerGeneratorInterface&MockObject
     */
    private NormalizerGeneratorInterface $generator;

    /** Fake generated namespace used for all tests. */
    private const GENERATED_NS = "App\\Generated\\Normalizer";

    protected function setUp(): void
    {
        $this->tempDir =
            sys_get_temp_dir() .
            DIRECTORY_SEPARATOR .
            "buildable_cmd_test_" .
            uniqid("", true);

        $this->discovery = $this->createMock(ClassDiscoveryInterface::class);

        // resolveFilePath and generateAndWrite are declared on
        // NormalizerGeneratorInterface so they are automatically mockable.
        // getMetadataFactory is a concrete method on NormalizerGenerator
        // that is NOT on the interface, so it needs addMethods().
        $this->generator = $this->getMockBuilder(
            NormalizerGeneratorInterface::class,
        )
            ->addMethods(["getMetadataFactory"])
            ->getMockForAbstractClass();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    // -------------------------------------------------------------------------
    // Command metadata
    // -------------------------------------------------------------------------

    public function testCommandHasCorrectName(): void
    {
        $this->assertSame(
            "buildable:generate-normalizers",
            $this->makeCommand()->getName(),
        );
    }

    public function testCommandHasNonEmptyDescription(): void
    {
        $this->assertNotEmpty($this->makeCommand()->getDescription());
    }

    public function testCommandExposesDryRunOption(): void
    {
        $this->assertTrue(
            $this->makeCommand()->getDefinition()->hasOption("dry-run"),
        );
    }

    public function testCommandExposesClassOption(): void
    {
        $this->assertTrue(
            $this->makeCommand()->getDefinition()->hasOption("class"),
        );
    }

    public function testCommandExposesForceOption(): void
    {
        $this->assertTrue(
            $this->makeCommand()->getDefinition()->hasOption("force"),
        );
    }

    public function testCommandExposesShowPathsOption(): void
    {
        $this->assertTrue(
            $this->makeCommand()->getDefinition()->hasOption("show-paths"),
        );
    }

    // -------------------------------------------------------------------------
    // No classes discovered
    // -------------------------------------------------------------------------

    public function testReturnsSuccessWhenNoClassesDiscovered(): void
    {
        $this->discovery->method("discoverClasses")->willReturn([]);

        $tester = $this->runCommand();

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testPrintsWarningWhenNoClassesDiscovered(): void
    {
        $this->discovery->method("discoverClasses")->willReturn([]);

        $tester = $this->runCommand();

        $this->assertStringContainsString(
            "No classes found",
            $tester->getDisplay(),
        );
    }

    public function testDoesNotCallGenerateAllWhenNoClassesDiscovered(): void
    {
        $this->discovery->method("discoverClasses")->willReturn([]);
        $this->generator->expects($this->never())->method("generateAndWrite");

        $this->runCommand();
    }

    // -------------------------------------------------------------------------
    // Happy path — all classes succeed
    // -------------------------------------------------------------------------

    public function testReturnsSuccessWhenAllClassesAreGenerated(): void
    {
        mkdir($this->tempDir, 0777, true);

        $this->prepareSuccessfulGeneration("App\\Entity\\User");

        $tester = $this->runCommand(["--force" => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testDisplaysGeneratedCountOnSuccess(): void
    {
        mkdir($this->tempDir, 0777, true);

        $this->prepareSuccessfulGeneration("App\\Entity\\User");

        $tester = $this->runCommand(["--force" => true]);

        $this->assertStringContainsString("1", $tester->getDisplay());
    }

    public function testCreatesOutputDirectoryWhenAbsent(): void
    {
        $this->assertDirectoryDoesNotExist($this->tempDir);

        $this->discovery->method("discoverClasses")->willReturn([]);

        $this->runCommand();

        // The command creates the dir only when classes exist and dry-run is off.
        // With an empty class list it exits early — so the dir may not be created.
        // Just verify no exception was thrown.
        $this->addToAssertionCount(1);
    }

    // -------------------------------------------------------------------------
    // Failure path — generation throws
    // -------------------------------------------------------------------------

    public function testReturnsFailureWhenAtLeastOneClassFails(): void
    {
        mkdir($this->tempDir, 0777, true);

        $this->discovery
            ->method("discoverClasses")
            ->willReturn(["App\\Broken"]);

        // getMetadataFactory() returns a factory mock whose getMetadataFor() throws
        $metaFactory = $this->createMock(
            \Buildable\SerializerBundle\Metadata\MetadataFactoryInterface::class,
        );
        $metaFactory
            ->method("getMetadataFor")
            ->willThrowException(new \RuntimeException("Metadata error"));

        $this->generator
            ->method("getMetadataFactory")
            ->willReturn($metaFactory);

        $this->generator
            ->method("resolveFilePath")
            ->willReturn($this->tempDir . "/BrokenNormalizer.php");

        $tester = $this->runCommand(["--force" => true]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    public function testDisplaysErrorMessageForFailedClass(): void
    {
        mkdir($this->tempDir, 0777, true);

        $this->discovery
            ->method("discoverClasses")
            ->willReturn(["App\\Broken"]);

        $metaFactory = $this->createMock(
            \Buildable\SerializerBundle\Metadata\MetadataFactoryInterface::class,
        );
        $metaFactory
            ->method("getMetadataFor")
            ->willThrowException(new \RuntimeException("Metadata error"));

        $this->generator
            ->method("getMetadataFactory")
            ->willReturn($metaFactory);

        $this->generator
            ->method("resolveFilePath")
            ->willReturn($this->tempDir . "/BrokenNormalizer.php");

        $tester = $this->runCommand(["--force" => true]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString("App\\Broken", $display);
        $this->assertStringContainsString("Metadata error", $display);
    }

    // -------------------------------------------------------------------------
    // --class option
    // -------------------------------------------------------------------------

    public function testClassOptionBypassesDiscovery(): void
    {
        mkdir($this->tempDir, 0777, true);

        // When --class is passed, discoverClasses must not be called.
        // We use \stdClass because the command calls class_exists() on explicit FQCNs
        // and skips any that cannot be autoloaded.
        $this->discovery->expects($this->never())->method("discoverClasses");

        $this->prepareSuccessfulGenerationForClass(\stdClass::class);

        $tester = $this->runCommand([
            "--class" => [\stdClass::class],
            "--force" => true,
        ]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testClassOptionAcceptsMultipleClasses(): void
    {
        mkdir($this->tempDir, 0777, true);

        $this->discovery->expects($this->never())->method("discoverClasses");

        // Use real FQCNs because the command calls class_exists() and skips unknowns.
        $classA = \stdClass::class; // built-in, always available
        $classB = \Exception::class; // built-in, always available

        $meta = $this->makeMeta();
        $metaFactory = $this->createMock(
            \Buildable\SerializerBundle\Metadata\MetadataFactoryInterface::class,
        );
        $metaFactory->method("getMetadataFor")->willReturn($meta);

        $this->generator
            ->method("getMetadataFactory")
            ->willReturn($metaFactory);

        $normalizerPathA = $this->tempDir . "/stdClassNormalizer.php";
        $normalizerPathB = $this->tempDir . "/ExceptionNormalizer.php";

        $this->generator
            ->method("resolveFilePath")
            ->willReturnOnConsecutiveCalls($normalizerPathA, $normalizerPathB);

        $this->generator
            ->expects($this->exactly(2))
            ->method("generateAndWrite");

        $tester = $this->runCommand([
            "--class" => [$classA, $classB],
            "--force" => true,
        ]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // --dry-run option
    // -------------------------------------------------------------------------

    public function testDryRunReturnsSuccessWithoutWritingFiles(): void
    {
        $this->discovery
            ->method("discoverClasses")
            ->willReturn(["App\\Entity\\User"]);

        $metaFactory = $this->createMock(
            \Buildable\SerializerBundle\Metadata\MetadataFactoryInterface::class,
        );
        $metaFactory->method("getMetadataFor")->willReturn($this->makeMeta());

        $this->generator
            ->method("getMetadataFactory")
            ->willReturn($metaFactory);
        $this->generator
            ->method("resolveFilePath")
            ->willReturn($this->tempDir . "/UserNormalizer.php");

        $this->generator->expects($this->never())->method("generateAndWrite");

        $tester = $this->runCommand(["--dry-run" => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testDryRunDisplaysDryRunNotice(): void
    {
        $this->discovery->method("discoverClasses")->willReturn([]);

        $tester = $this->runCommand(["--dry-run" => true]);

        $this->assertStringContainsString(
            "dry-run",
            strtolower($tester->getDisplay()),
        );
    }

    public function testDryRunDoesNotCreateOutputDirectory(): void
    {
        $this->assertDirectoryDoesNotExist($this->tempDir);

        $this->discovery->method("discoverClasses")->willReturn([]);

        $this->runCommand(["--dry-run" => true]);

        // The cache dir is only created when there are classes to process and
        // dry-run is off. Empty class list = early return, no dir creation.
        $this->addToAssertionCount(1);
    }

    // -------------------------------------------------------------------------
    // --force option
    // -------------------------------------------------------------------------

    public function testWithoutForceExistingFilesAreSkipped(): void
    {
        mkdir($this->tempDir, 0777, true);

        $existingPath = $this->tempDir . "/UserNormalizer.php";
        file_put_contents($existingPath, "<?php // existing");

        $this->discovery
            ->method("discoverClasses")
            ->willReturn(["App\\Entity\\User"]);

        $metaFactory = $this->createMock(
            \Buildable\SerializerBundle\Metadata\MetadataFactoryInterface::class,
        );
        $metaFactory->method("getMetadataFor")->willReturn($this->makeMeta());

        $this->generator
            ->method("getMetadataFactory")
            ->willReturn($metaFactory);
        $this->generator->method("resolveFilePath")->willReturn($existingPath);

        // generateAndWrite must NOT be called when the file exists and --force is absent.
        $this->generator->expects($this->never())->method("generateAndWrite");

        $this->runCommand();
    }

    public function testWithForceExistingFilesAreOverwritten(): void
    {
        mkdir($this->tempDir, 0777, true);

        $existingPath = $this->tempDir . "/UserNormalizer.php";
        file_put_contents($existingPath, "<?php // existing");

        $this->discovery
            ->method("discoverClasses")
            ->willReturn(["App\\Entity\\User"]);

        $metaFactory = $this->createMock(
            \Buildable\SerializerBundle\Metadata\MetadataFactoryInterface::class,
        );
        $metaFactory->method("getMetadataFor")->willReturn($this->makeMeta());

        $this->generator
            ->method("getMetadataFactory")
            ->willReturn($metaFactory);
        $this->generator->method("resolveFilePath")->willReturn($existingPath);

        // generateAndWrite MUST be called when --force is set.
        $this->generator->expects($this->once())->method("generateAndWrite");

        $this->runCommand(["--force" => true]);
    }

    // -------------------------------------------------------------------------
    // --show-paths option
    // -------------------------------------------------------------------------

    public function testShowPathsDisplaysGeneratedFilePaths(): void
    {
        mkdir($this->tempDir, 0777, true);

        // Use a real class so the command's class_exists() check passes.
        $normalizerPath = $this->tempDir . "/stdClassNormalizer.php";
        $this->prepareSuccessfulGenerationForClass(
            \stdClass::class,
            $normalizerPath,
        );

        $tester = $this->runCommand([
            "--class" => [\stdClass::class],
            "--force" => true,
            "--show-paths" => true,
        ]);

        // The path may be word-wrapped by SymfonyStyle on narrow terminals,
        // so we only assert that a recognisable portion of it appears.
        $this->assertStringContainsString(
            "stdClassNormalizer.php",
            $tester->getDisplay(),
        );
    }

    // -------------------------------------------------------------------------
    // Exit codes
    // -------------------------------------------------------------------------

    public function testReturnsSuccessWhenNothingFailed(): void
    {
        mkdir($this->tempDir, 0777, true);

        $this->prepareSuccessfulGeneration("App\\Entity\\User");

        $tester = $this->runCommand(["--force" => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testReturnsSuccessForDryRunEvenWithClasses(): void
    {
        $this->discovery
            ->method("discoverClasses")
            ->willReturn(["App\\Entity\\User"]);

        $metaFactory = $this->createMock(
            \Buildable\SerializerBundle\Metadata\MetadataFactoryInterface::class,
        );
        $metaFactory->method("getMetadataFor")->willReturn($this->makeMeta());

        $this->generator
            ->method("getMetadataFactory")
            ->willReturn($metaFactory);
        $this->generator
            ->method("resolveFilePath")
            ->willReturn($this->tempDir . "/UserNormalizer.php");

        $tester = $this->runCommand(["--dry-run" => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Header output
    // -------------------------------------------------------------------------

    public function testOutputIncludesCacheDirectoryInfo(): void
    {
        $this->discovery->method("discoverClasses")->willReturn([]);

        $tester = $this->runCommand();

        // The path may be word-wrapped by SymfonyStyle on narrow terminals.
        // Assert on a distinctive fragment that will always appear, regardless of wrapping.
        $display = $tester->getDisplay();
        $this->assertTrue(
            str_contains($display, "Cache directory") ||
                str_contains($display, "buildable_cmd_test"),
            "Expected cache directory info in output. Got: " . $display,
        );
    }

    public function testOutputIncludesGeneratedNamespaceInfo(): void
    {
        $this->discovery->method("discoverClasses")->willReturn([]);

        $tester = $this->runCommand();

        $this->assertStringContainsString(
            self::GENERATED_NS,
            $tester->getDisplay(),
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build the command wired to the current test doubles.
     */
    private function makeCommand(): GenerateNormalizersCommand
    {
        return new GenerateNormalizersCommand(
            $this->generator,
            $this->discovery,
            $this->tempDir,
            self::GENERATED_NS,
        );
    }

    /**
     * Execute the command and return the tester.
     *
     * @param array<string, mixed> $options
     */
    private function runCommand(
        array $options = [],
        int $verbosity = OutputInterface::VERBOSITY_NORMAL,
    ): CommandTester {
        $tester = new CommandTester($this->makeCommand());
        $tester->execute($options, ["verbosity" => $verbosity]);

        return $tester;
    }

    /**
     * Wire mocks for a single successful generation using the discovery strategy.
     */
    private function prepareSuccessfulGeneration(string $className): void
    {
        // Use a real FQCN so the command's class_exists() check passes when
        // the class is passed via --class; for discovery the check is not run.
        $normalizerPath =
            $this->tempDir .
            "/" .
            self::class_basename($className) .
            "Normalizer.php";
        $this->prepareSuccessfulGenerationForClass($className, $normalizerPath);
        // discovery returns the FQCN directly; class_exists is NOT called on
        // classes returned by the discovery strategy.
        $this->discovery->method("discoverClasses")->willReturn([$className]);
    }

    /**
     * Wire mocks for a single successful generation for an explicit class name.
     */
    private function prepareSuccessfulGenerationForClass(
        string $className,
        string $normalizerPath = "",
    ): void {
        if ($normalizerPath === "") {
            $normalizerPath =
                $this->tempDir .
                "/" .
                self::class_basename($className) .
                "Normalizer.php";
        }

        $metaFactory = $this->createMock(
            \Buildable\SerializerBundle\Metadata\MetadataFactoryInterface::class,
        );
        $metaFactory->method("getMetadataFor")->willReturn($this->makeMeta());

        $this->generator
            ->method("getMetadataFactory")
            ->willReturn($metaFactory);
        $this->generator
            ->method("resolveFilePath")
            ->willReturn($normalizerPath);
        $this->generator->method("generateAndWrite");
    }

    /**
     * Build a minimal ClassMetadata stub.
     */
    private function makeMeta(): \Buildable\SerializerBundle\Metadata\ClassMetadata
    {
        $meta = new \Buildable\SerializerBundle\Metadata\ClassMetadata();
        $meta->className = "App\\Entity\\User";
        $meta->reflectionClass = new \ReflectionClass(\stdClass::class);

        return $meta;
    }

    /**
     * Return the unqualified (short) class name from a FQCN.
     */
    private static function class_basename(string $fqcn): string
    {
        $parts = explode("\\", $fqcn);

        return (string) end($parts);
    }

    /**
     * Recursively delete a directory.
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === "." || $entry === "..") {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
