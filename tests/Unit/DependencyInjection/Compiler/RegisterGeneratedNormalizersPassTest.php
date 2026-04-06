<?php

declare(strict_types=1);

namespace Buildable\SerializerBundle\Tests\Unit\DependencyInjection\Compiler;

use Buildable\SerializerBundle\DependencyInjection\Compiler\RegisterGeneratedNormalizersPass;
use Buildable\SerializerBundle\Normalizer\GeneratedNormalizerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * @covers \Buildable\SerializerBundle\DependencyInjection\Compiler\RegisterGeneratedNormalizersPass
 */
final class RegisterGeneratedNormalizersPassTest extends TestCase
{
    /** Unique temp directory created for each test. */
    private string $tempDir;

    private ContainerBuilder $container;
    private RegisterGeneratedNormalizersPass $pass;

    /**
     * Counter used to make class names unique across tests in the same process.
     * The counter is prepended so that the class name always ends in "Normalizer",
     * matching the scanner filter: str_ends_with($filename, "Normalizer.php").
     */
    private static int $classCounter = 0;

    protected function setUp(): void
    {
        $this->tempDir =
            sys_get_temp_dir() .
            DIRECTORY_SEPARATOR .
            "buildable_pass_test_" .
            uniqid("", true);

        mkdir($this->tempDir, 0777, true);

        $this->container = new ContainerBuilder();
        $this->pass = new RegisterGeneratedNormalizersPass();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    // -------------------------------------------------------------------------
    // Early-exit paths
    // -------------------------------------------------------------------------

    public function testProcessDoesNothingWhenCacheDirParameterAbsent(): void
    {
        $this->container->setParameter(
            "buildable_serializer.generated_namespace",
            "BuildableTest\\Generated",
        );

        $this->pass->process($this->container);

        $this->assertNoNormalizerServicesRegistered();
    }

    public function testProcessDoesNothingWhenGeneratedNamespaceParameterAbsent(): void
    {
        $this->container->setParameter(
            "buildable_serializer.cache_dir",
            $this->tempDir,
        );

        $this->pass->process($this->container);

        $this->assertNoNormalizerServicesRegistered();
    }

    public function testProcessDoesNothingWhenBothParametersAbsent(): void
    {
        $this->pass->process($this->container);

        $this->assertNoNormalizerServicesRegistered();
    }

    public function testProcessDoesNothingWhenCacheDirDoesNotExist(): void
    {
        $this->setContainerParams(
            "/nonexistent/path/that/does/not/exist",
            "BuildableTest\\Generated",
        );

        $this->pass->process($this->container);

        $this->assertNoNormalizerServicesRegistered();
    }

    public function testProcessDoesNothingWhenCacheDirIsEmpty(): void
    {
        $this->setContainerParams($this->tempDir, "BuildableTest\\Generated");

        $this->pass->process($this->container);

        $this->assertNoNormalizerServicesRegistered();
    }

    public function testProcessDoesNothingWhenOnlyNonNormalizerPhpFilesPresent(): void
    {
        file_put_contents(
            $this->tempDir . "/SomeOtherClass.php",
            "<?php class SomeOther {}",
        );
        file_put_contents($this->tempDir . "/autoload.php", "<?php return [];");

        $this->setContainerParams($this->tempDir, "BuildableTest\\Generated");

        $this->pass->process($this->container);

        $this->assertNoNormalizerServicesRegistered();
    }

    // -------------------------------------------------------------------------
    // Marker interface check
    // -------------------------------------------------------------------------

    public function testProcessSkipsClassNotImplementingMarkerInterface(): void
    {
        $shortName = $this->uniqueShortName("NoMarkerNormalizer");
        $fqcn = "BuildableTest\\Generated\\" . $shortName;
        $this->writeNormalizerFile($shortName, $fqcn, implementsMarker: false);

        $this->setContainerParams($this->tempDir, "BuildableTest\\Generated");

        $this->pass->process($this->container);

        $this->assertFalse($this->container->hasDefinition($fqcn));
    }

    // -------------------------------------------------------------------------
    // Happy path — service registration
    // -------------------------------------------------------------------------

    public function testProcessRegistersValidNormalizerAsTaggedService(): void
    {
        $shortName = $this->uniqueShortName("ValidNormalizer");
        $fqcn = "BuildableTest\\Generated\\" . $shortName;
        // $shortName ends with "Normalizer" → file ends with "Normalizer.php" → scanner picks it up
        $this->writeNormalizerFile($shortName, $fqcn, implementsMarker: true);

        $this->setContainerParams($this->tempDir, "BuildableTest\\Generated");

        $this->pass->process($this->container);

        $this->assertTrue(
            $this->container->hasDefinition($fqcn),
            "Expected service definition for {$fqcn}.",
        );

        $definition = $this->container->getDefinition($fqcn);
        $this->assertSame($fqcn, $definition->getClass());
        $this->assertFalse(
            $definition->isPublic(),
            "Service must not be public.",
        );
        $this->assertFalse(
            $definition->isAutowired(),
            "Service must not be autowired.",
        );
        $this->assertFalse(
            $definition->isAutoconfigured(),
            "Service must not be autoconfigured.",
        );
    }

    public function testProcessTagsServiceWithSerializerNormalizerTag(): void
    {
        $shortName = $this->uniqueShortName("TaggedNormalizer");
        $fqcn = "BuildableTest\\Generated\\" . $shortName;
        $this->writeNormalizerFile($shortName, $fqcn, implementsMarker: true);

        $this->setContainerParams($this->tempDir, "BuildableTest\\Generated");

        $this->pass->process($this->container);

        $tags = $this->container
            ->getDefinition($fqcn)
            ->getTag("serializer.normalizer");
        $this->assertCount(
            1,
            $tags,
            "Expected exactly one serializer.normalizer tag.",
        );
        $this->assertArrayHasKey("priority", $tags[0]);
    }

    public function testProcessUsesDefaultPriorityOf200(): void
    {
        $shortName = $this->uniqueShortName("DefaultPrioNormalizer");
        $fqcn = "BuildableTest\\Generated\\" . $shortName;
        $this->writeNormalizerFile($shortName, $fqcn, implementsMarker: true);

        $this->setContainerParams($this->tempDir, "BuildableTest\\Generated");

        $this->pass->process($this->container);

        $tags = $this->container
            ->getDefinition($fqcn)
            ->getTag("serializer.normalizer");
        $this->assertSame(200, $tags[0]["priority"]);
    }

    public function testProcessUsesCustomNormalizerPriorityConstant(): void
    {
        $shortName = $this->uniqueShortName("HighPrioNormalizer");
        $fqcn = "BuildableTest\\Generated\\" . $shortName;
        $this->writeNormalizerFileWithPriority($shortName, $fqcn, 500);

        $this->setContainerParams($this->tempDir, "BuildableTest\\Generated");

        $this->pass->process($this->container);

        $this->assertTrue($this->container->hasDefinition($fqcn));
        $tags = $this->container
            ->getDefinition($fqcn)
            ->getTag("serializer.normalizer");
        $this->assertSame(500, $tags[0]["priority"]);
    }

    public function testProcessRegistersMultipleNormalizersFromSameDirectory(): void
    {
        $shortA = $this->uniqueShortName("MultiANormalizer");
        $shortB = $this->uniqueShortName("MultiBNormalizer");
        $fqcnA = "BuildableTest\\Generated\\" . $shortA;
        $fqcnB = "BuildableTest\\Generated\\" . $shortB;

        $this->writeNormalizerFile($shortA, $fqcnA, implementsMarker: true);
        $this->writeNormalizerFile($shortB, $fqcnB, implementsMarker: true);

        $this->setContainerParams($this->tempDir, "BuildableTest\\Generated");

        $this->pass->process($this->container);

        $this->assertTrue($this->container->hasDefinition($fqcnA));
        $this->assertTrue($this->container->hasDefinition($fqcnB));
    }

    public function testProcessRegistersNormalizersInSubdirectories(): void
    {
        $subDir =
            $this->tempDir .
            DIRECTORY_SEPARATOR .
            "App" .
            DIRECTORY_SEPARATOR .
            "Entity";
        mkdir($subDir, 0777, true);

        $shortName = $this->uniqueShortName("DeepNormalizer");
        $fqcn = "BuildableTest\\Generated\\App\\Entity\\" . $shortName;

        $filePath = $subDir . DIRECTORY_SEPARATOR . $shortName . ".php";
        $markerInterface = "\\" . GeneratedNormalizerInterface::class;
        file_put_contents(
            $filePath,
            <<<PHP
            <?php
            namespace BuildableTest\Generated\App\Entity;
            final class {$shortName} implements {$markerInterface} {}
            PHP
            ,
        );

        $this->setContainerParams($this->tempDir, "BuildableTest\\Generated");

        $this->pass->process($this->container);

        $this->assertTrue(
            $this->container->hasDefinition($fqcn),
            "Expected service definition for deeply nested {$fqcn}.",
        );
    }

    // -------------------------------------------------------------------------
    // Mixed valid + invalid entries
    // -------------------------------------------------------------------------

    public function testProcessMixesValidAndSkippableFilesGracefully(): void
    {
        $validShort = $this->uniqueShortName("MixedValidNormalizer");
        $invalidShort = $this->uniqueShortName("MixedInvalidNormalizer");
        $validFqcn = "BuildableTest\\Generated\\" . $validShort;
        $invalidFqcn = "BuildableTest\\Generated\\" . $invalidShort;

        $this->writeNormalizerFile(
            $validShort,
            $validFqcn,
            implementsMarker: true,
        );
        $this->writeNormalizerFile(
            $invalidShort,
            $invalidFqcn,
            implementsMarker: false,
        );

        $this->setContainerParams($this->tempDir, "BuildableTest\\Generated");

        $this->pass->process($this->container);

        $this->assertTrue($this->container->hasDefinition($validFqcn));
        $this->assertFalse($this->container->hasDefinition($invalidFqcn));
    }

    // -------------------------------------------------------------------------
    // Already-registered services
    // -------------------------------------------------------------------------

    public function testProcessSkipsAlreadyRegisteredService(): void
    {
        $shortName = $this->uniqueShortName("PreExistingNormalizer");
        $fqcn = "BuildableTest\\Generated\\" . $shortName;
        $this->writeNormalizerFile($shortName, $fqcn, implementsMarker: true);

        // Pre-register with a sentinel tag to detect whether it is replaced.
        $this->container->register($fqcn, $fqcn)->addTag("custom_sentinel_tag");

        $this->setContainerParams($this->tempDir, "BuildableTest\\Generated");

        $this->pass->process($this->container);

        $definition = $this->container->getDefinition($fqcn);
        $this->assertNotEmpty($definition->getTag("custom_sentinel_tag"));
        $this->assertEmpty(
            $definition->getTag("serializer.normalizer"),
            "The pass must not overwrite a user-defined service definition.",
        );
    }

    // -------------------------------------------------------------------------
    // Already-loaded classes
    // -------------------------------------------------------------------------

    public function testProcessHandlesAlreadyRequiredClass(): void
    {
        $shortName = $this->uniqueShortName("PreLoadedNormalizer");
        $fqcn = "BuildableTest\\Generated\\" . $shortName;
        $filePath = $this->writeNormalizerFile(
            $shortName,
            $fqcn,
            implementsMarker: true,
        );

        // Pre-load the class to simulate it being autoloaded before the pass runs.
        require_once $filePath;

        $this->setContainerParams($this->tempDir, "BuildableTest\\Generated");

        // Must not throw or register a duplicate service.
        $this->pass->process($this->container);

        $this->assertTrue($this->container->hasDefinition($fqcn));
    }

    // -------------------------------------------------------------------------
    // Broken / unreadable files
    // -------------------------------------------------------------------------

    public function testProcessSkipsBrokenPhpFile(): void
    {
        // Write a file with a syntax error so require_once throws a ParseError.
        // "BrokenNormalizer.php" ends in "Normalizer.php" → scanner picks it up, then skips it.
        $brokenPath = $this->tempDir . "/BrokenNormalizer.php";
        file_put_contents($brokenPath, "<?php this is not valid php }{");

        $this->setContainerParams($this->tempDir, "BuildableTest\\Generated");

        // Must not throw; the broken file is silently skipped.
        $this->pass->process($this->container);

        $this->assertFalse(
            $this->container->hasDefinition(
                "BuildableTest\\Generated\\BrokenNormalizer",
            ),
        );
    }

    // -------------------------------------------------------------------------
    // Constructor argument resolution
    // -------------------------------------------------------------------------

    public function testProcessInjectsSerializerReferenceForNormalizerInterfaceParam(): void
    {
        $shortName = $this->uniqueShortName("DelegatingNormalizer");
        $fqcn = "BuildableTest\\Generated\\" . $shortName;
        $this->writeNormalizerFileWithNormalizerParam($shortName, $fqcn);

        $this->container->register("serializer");
        $this->setContainerParams($this->tempDir, "BuildableTest\\Generated");

        $this->pass->process($this->container);

        $this->assertTrue($this->container->hasDefinition($fqcn));

        $arguments = $this->container->getDefinition($fqcn)->getArguments();
        $this->assertCount(1, $arguments);
        $this->assertInstanceOf(Reference::class, $arguments[0]);
        $this->assertSame("serializer", (string) $arguments[0]);
    }

    public function testProcessInjectsNullForBuiltinTypeParam(): void
    {
        $shortName = $this->uniqueShortName("BuiltinParamNormalizer");
        $fqcn = "BuildableTest\\Generated\\" . $shortName;
        $this->writeNormalizerFileWithBuiltinParam($shortName, $fqcn);

        $this->setContainerParams($this->tempDir, "BuildableTest\\Generated");

        $this->pass->process($this->container);

        $this->assertTrue($this->container->hasDefinition($fqcn));

        $arguments = $this->container->getDefinition($fqcn)->getArguments();
        $this->assertCount(1, $arguments);
        $this->assertNull($arguments[0]);
    }

    public function testProcessInjectsContainerServiceReferenceForKnownServiceType(): void
    {
        $depClass = "stdClass";
        $shortName = $this->uniqueShortName("ServiceDepNormalizer");
        $fqcn = "BuildableTest\\Generated\\" . $shortName;
        $this->writeNormalizerFileWithTypedParam($shortName, $fqcn, $depClass);

        $this->container->register($depClass, $depClass);
        $this->setContainerParams($this->tempDir, "BuildableTest\\Generated");

        $this->pass->process($this->container);

        $this->assertTrue($this->container->hasDefinition($fqcn));

        $arguments = $this->container->getDefinition($fqcn)->getArguments();
        $this->assertCount(1, $arguments);
        $this->assertInstanceOf(Reference::class, $arguments[0]);
        $this->assertSame($depClass, (string) $arguments[0]);
    }

    public function testProcessInjectsNullForUnknownServiceType(): void
    {
        $shortName = $this->uniqueShortName("UnknownDepNormalizer");
        $fqcn = "BuildableTest\\Generated\\" . $shortName;
        $this->writeNormalizerFileWithTypedParam(
            $shortName,
            $fqcn,
            "App\\NonExistentService",
        );

        $this->setContainerParams($this->tempDir, "BuildableTest\\Generated");

        $this->pass->process($this->container);

        $this->assertTrue($this->container->hasDefinition($fqcn));

        $arguments = $this->container->getDefinition($fqcn)->getArguments();
        $this->assertCount(1, $arguments);
        $this->assertNull($arguments[0]);
    }

    public function testProcessRegistersNormalizerWithNoConstructorArgs(): void
    {
        $shortName = $this->uniqueShortName("NoArgNormalizer");
        $fqcn = "BuildableTest\\Generated\\" . $shortName;
        $this->writeNormalizerFile($shortName, $fqcn, implementsMarker: true);

        $this->setContainerParams($this->tempDir, "BuildableTest\\Generated");

        $this->pass->process($this->container);

        $this->assertTrue($this->container->hasDefinition($fqcn));
        $this->assertEmpty(
            $this->container->getDefinition($fqcn)->getArguments(),
        );
    }

    // -------------------------------------------------------------------------
    // FQCN derivation from path
    // -------------------------------------------------------------------------

    public function testFqcnIsCorrectlyDerivedFromFlatFile(): void
    {
        $shortName = $this->uniqueShortName("FlatNormalizer");
        $fqcn = "My\\Namespace\\" . $shortName;
        $this->writeNormalizerFile($shortName, $fqcn, implementsMarker: true);

        $this->setContainerParams($this->tempDir, "My\\Namespace");

        $this->pass->process($this->container);

        $this->assertTrue(
            $this->container->hasDefinition($fqcn),
            "FQCN {$fqcn} must be derived correctly from a flat (non-PSR4) path.",
        );
    }

    public function testFqcnIsCorrectlyDerivedFromNestedPath(): void
    {
        $subDir = $this->tempDir . DIRECTORY_SEPARATOR . "Dto";
        mkdir($subDir, 0777, true);

        $shortName = $this->uniqueShortName("NestedFqcnNormalizer");
        $fqcn = "Root\\Dto\\" . $shortName;

        $markerInterface = "\\" . GeneratedNormalizerInterface::class;
        file_put_contents(
            $subDir . DIRECTORY_SEPARATOR . $shortName . ".php",
            <<<PHP
            <?php
            namespace Root\Dto;
            final class {$shortName} implements {$markerInterface} {}
            PHP
            ,
        );

        $this->setContainerParams($this->tempDir, "Root");

        $this->pass->process($this->container);

        $this->assertTrue(
            $this->container->hasDefinition($fqcn),
            "FQCN {$fqcn} must be derived from nested directory path.",
        );
    }

    // -------------------------------------------------------------------------
    // Idempotency
    // -------------------------------------------------------------------------

    public function testProcessIsIdempotentWhenRunTwice(): void
    {
        $shortName = $this->uniqueShortName("IdempotentNormalizer");
        $fqcn = "BuildableTest\\Generated\\" . $shortName;
        $this->writeNormalizerFile($shortName, $fqcn, implementsMarker: true);

        $this->setContainerParams($this->tempDir, "BuildableTest\\Generated");

        $this->pass->process($this->container);

        // The first run registers the service; the second must not throw or
        // double-register because hasDefinition() returns true.
        $this->pass->process($this->container);

        $definitions = array_filter(
            $this->container->getDefinitions(),
            static fn($def) => $def->getClass() === $fqcn,
        );
        $this->assertCount(
            1,
            $definitions,
            "Service must be registered exactly once.",
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Assert that the compiler pass registered no `serializer.normalizer`-tagged
     * services in the container.
     *
     * We deliberately avoid `assertEmpty($container->getDefinitions())` because
     * a fresh `ContainerBuilder` always contains built-in definitions (e.g.
     * `service_container`).
     */
    private function assertNoNormalizerServicesRegistered(): void
    {
        $tagged = $this->container->findTaggedServiceIds(
            "serializer.normalizer",
        );

        $this->assertEmpty(
            $tagged,
            "Expected no serializer.normalizer tagged services, but found: " .
                implode(", ", array_keys($tagged)),
        );
    }

    /**
     * Set the two container parameters required by the pass.
     */
    private function setContainerParams(
        string $cacheDir,
        string $namespace,
    ): void {
        // Resolve symlinks (e.g. /tmp → /private/tmp on macOS) so the
        // container parameter matches the canonical path returned by getRealPath().
        $resolved = realpath($cacheDir);

        $this->container->setParameter(
            "buildable_serializer.cache_dir",
            $resolved !== false ? $resolved : $cacheDir,
        );
        $this->container->setParameter(
            "buildable_serializer.generated_namespace",
            $namespace,
        );
    }

    /**
     * Generate a unique short class name to prevent redeclaration errors.
     *
     * The counter is prepended so that every generated name ends with "Normalizer",
     * which is required by the compiler pass scanner:
     *   str_ends_with($filename, "Normalizer.php")
     *
     * Example: uniqueShortName("Valid") → "T001ValidNormalizer"
     */
    private function uniqueShortName(string $base): string
    {
        return sprintf("T%03d%s", ++self::$classCounter, $base);
    }

    /**
     * Write a minimal PHP normalizer file to the temp directory.
     *
     * @return string The absolute path of the written file.
     */
    private function writeNormalizerFile(
        string $shortName,
        string $fqcn,
        bool $implementsMarker,
    ): string {
        [$namespace, $class] = $this->splitFqcn($fqcn);
        $markerClause = $implementsMarker
            ? "implements \\" . GeneratedNormalizerInterface::class
            : "";

        $content = <<<PHP
        <?php
        namespace {$namespace};
        final class {$class} {$markerClause} {}
        PHP;

        $path = $this->tempDir . DIRECTORY_SEPARATOR . $shortName . ".php";
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * Write a normalizer file that declares a public NORMALIZER_PRIORITY constant.
     */
    private function writeNormalizerFileWithPriority(
        string $shortName,
        string $fqcn,
        int $priority,
    ): string {
        [$namespace, $class] = $this->splitFqcn($fqcn);
        $marker = "\\" . GeneratedNormalizerInterface::class;

        $content = <<<PHP
        <?php
        namespace {$namespace};
        final class {$class} implements {$marker}
        {
            public const NORMALIZER_PRIORITY = {$priority};
        }
        PHP;

        $path = $this->tempDir . DIRECTORY_SEPARATOR . $shortName . ".php";
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * Write a normalizer file whose constructor accepts a NormalizerInterface param.
     * This triggers the `@serializer` injection path in the pass.
     */
    private function writeNormalizerFileWithNormalizerParam(
        string $shortName,
        string $fqcn,
    ): string {
        [$namespace, $class] = $this->splitFqcn($fqcn);
        $marker = "\\" . GeneratedNormalizerInterface::class;
        $normIface =
            "\Symfony\Component\Serializer\Normalizer\NormalizerInterface";

        $content = <<<PHP
        <?php
        namespace {$namespace};
        final class {$class} implements {$marker}
        {
            public function __construct(
                private readonly {$normIface} \$normalizer,
            ) {}
        }
        PHP;

        $path = $this->tempDir . DIRECTORY_SEPARATOR . $shortName . ".php";
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * Write a normalizer file whose constructor accepts a built-in (string) param.
     * This triggers the `null` injection fallback.
     */
    private function writeNormalizerFileWithBuiltinParam(
        string $shortName,
        string $fqcn,
    ): string {
        [$namespace, $class] = $this->splitFqcn($fqcn);
        $marker = "\\" . GeneratedNormalizerInterface::class;

        $content = <<<PHP
        <?php
        namespace {$namespace};
        final class {$class} implements {$marker}
        {
            public function __construct(private readonly string \$value = '') {}
        }
        PHP;

        $path = $this->tempDir . DIRECTORY_SEPARATOR . $shortName . ".php";
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * Write a normalizer file whose constructor accepts an object param of the
     * given FQCN. Used to test both the "known service" and "unknown service" paths.
     */
    private function writeNormalizerFileWithTypedParam(
        string $shortName,
        string $fqcn,
        string $typeFqcn,
    ): string {
        [$namespace, $class] = $this->splitFqcn($fqcn);
        $marker = "\\" . GeneratedNormalizerInterface::class;
        $typeHint = "?\\" . ltrim($typeFqcn, "\\");

        $content = <<<PHP
        <?php
        namespace {$namespace};
        final class {$class} implements {$marker}
        {
            public function __construct(private readonly {$typeHint} \$dep = null) {}
        }
        PHP;

        $path = $this->tempDir . DIRECTORY_SEPARATOR . $shortName . ".php";
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * Split a FQCN into [namespace, shortName].
     *
     * @return array{0: string, 1: string}
     */
    private function splitFqcn(string $fqcn): array
    {
        $parts = explode("\\", $fqcn);
        $shortName = (string) array_pop($parts);
        $namespace = implode("\\", $parts);

        return [$namespace, $shortName];
    }

    /**
     * Recursively delete a directory and all its contents.
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
