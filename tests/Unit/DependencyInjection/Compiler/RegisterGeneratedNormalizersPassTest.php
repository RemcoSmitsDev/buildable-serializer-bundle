<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Unit\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use RemcoSmitsDev\BuildableSerializerBundle\DependencyInjection\Compiler\RegisterGeneratedNormalizersPass;
use RemcoSmitsDev\BuildableSerializerBundle\Discovery\ClassDiscoveryInterface;
use RemcoSmitsDev\BuildableSerializerBundle\Discovery\FinderClassDiscovery;
use RemcoSmitsDev\BuildableSerializerBundle\Generator\NormalizerGenerator;
use RemcoSmitsDev\BuildableSerializerBundle\Metadata\MetadataFactory;
use RemcoSmitsDev\BuildableSerializerBundle\Normalizer\GeneratedNormalizerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Serializer;

/**
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\DependencyInjection\Compiler\RegisterGeneratedNormalizersPass
 */
final class RegisterGeneratedNormalizersPassTest extends TestCase
{
    private string $tempDir;
    private ContainerBuilder $container;
    private RegisterGeneratedNormalizersPass $pass;

    /** Absolute path to tests/Fixtures/Discovery/ */
    private string $discoveryFixturesDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'buildable_pass_test_' . uniqid('', true);

        mkdir($this->tempDir, 0777, true);

        $this->discoveryFixturesDir = realpath(__DIR__ . '/../../../Fixtures/Discovery');

        $this->container = new ContainerBuilder();
        $this->pass = new RegisterGeneratedNormalizersPass();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testProcessDoesNothingWhenCacheDirParameterAbsent(): void
    {
        $this->container->setParameter('buildable_serializer.generated_namespace', "BuildableTest\\Generated");
        $this->container->setParameter('buildable_serializer.paths', []);

        $this->pass->process($this->container);

        $this->assertNoNormalizerServicesRegistered();
    }

    public function testProcessDoesNothingWhenGeneratedNamespaceParameterAbsent(): void
    {
        $this->container->setParameter('buildable_serializer.cache_dir', $this->tempDir);
        $this->container->setParameter('buildable_serializer.paths', []);

        $this->pass->process($this->container);

        $this->assertNoNormalizerServicesRegistered();
    }

    public function testProcessDoesNothingWhenPathsParameterAbsent(): void
    {
        $this->container->setParameter('buildable_serializer.cache_dir', $this->tempDir);
        $this->container->setParameter('buildable_serializer.generated_namespace', "BuildableTest\\Generated");

        $this->pass->process($this->container);

        $this->assertNoNormalizerServicesRegistered();
    }

    public function testProcessDoesNothingWhenAllParametersAbsent(): void
    {
        $this->pass->process($this->container);

        $this->assertNoNormalizerServicesRegistered();
    }

    public function testProcessDoesNothingWhenPathsIsEmpty(): void
    {
        $this->setupContainerParameters([]);

        $this->pass->process($this->container);

        $this->assertNoNormalizerServicesRegistered();
    }

    public function testProcessGeneratesNormalizerFilesForDiscoveredClasses(): void
    {
        $this->setupContainerParameters([
            "RemcoSmitsDev\\BuildableSerializerBundle\\Tests\\Fixtures\\Discovery" => $this->discoveryFixturesDir,
        ]);

        $this->pass->process($this->container);

        // At least one *Normalizer.php file must exist in the temp output dir.
        $generated = glob(
            $this->tempDir . '/**/*Normalizer.php',
            GLOB_BRACE,
        ) ?: glob($this->tempDir . '/*Normalizer.php') ?: $this->findNormalizerFiles($this->tempDir);

        $this->assertNotEmpty($generated, 'Expected generated Normalizer PHP files in cache_dir.');
    }

    public function testProcessRegistersNormalizerServicesInContainer(): void
    {
        $this->setupContainerParameters([
            "RemcoSmitsDev\\BuildableSerializerBundle\\Tests\\Fixtures\\Discovery" => $this->discoveryFixturesDir,
        ]);

        $this->pass->process($this->container);

        $this->assertAtLeastOneNormalizerServiceRegistered();
    }

    public function testRegisteredServicesAreTaggedWithSerializerNormalizerTag(): void
    {
        $this->setupContainerParameters([
            "RemcoSmitsDev\\BuildableSerializerBundle\\Tests\\Fixtures\\Discovery" => $this->discoveryFixturesDir,
        ]);

        $this->pass->process($this->container);

        foreach ($this->getRegisteredNormalizerFqcns() as $fqcn) {
            $definition = $this->container->getDefinition($fqcn);
            $this->assertTrue(
                $definition->hasTag('serializer.normalizer'),
                "{$fqcn} must be tagged with 'serializer.normalizer'.",
            );
        }
    }

    public function testRegisteredServicesUseDefaultPriority200(): void
    {
        $this->setupContainerParameters([
            "RemcoSmitsDev\\BuildableSerializerBundle\\Tests\\Fixtures\\Discovery" => $this->discoveryFixturesDir,
        ]);

        $this->pass->process($this->container);

        foreach ($this->getRegisteredNormalizerFqcns() as $fqcn) {
            $definition = $this->container->getDefinition($fqcn);
            $tags = $definition->getTag('serializer.normalizer');
            $priority = (int) ($tags[0]['priority'] ?? 0);

            $this->assertSame(200, $priority, "{$fqcn} must be tagged with priority 200.");
        }
    }

    public function testRegisteredServicesArePrivate(): void
    {
        $this->setupContainerParameters([
            "RemcoSmitsDev\\BuildableSerializerBundle\\Tests\\Fixtures\\Discovery" => $this->discoveryFixturesDir,
        ]);

        $this->pass->process($this->container);

        foreach ($this->getRegisteredNormalizerFqcns() as $fqcn) {
            $definition = $this->container->getDefinition($fqcn);
            $this->assertFalse($definition->isPublic(), "{$fqcn} must be a private service.");
        }
    }

    public function testAbstractClassIsNotRegistered(): void
    {
        $this->setupContainerParameters([
            "RemcoSmitsDev\\BuildableSerializerBundle\\Tests\\Fixtures\\Discovery" => $this->discoveryFixturesDir,
        ]);

        $this->pass->process($this->container);

        // AbstractModel is abstract — must be excluded.
        foreach ($this->getRegisteredNormalizerFqcns() as $fqcn) {
            $this->assertStringNotContainsStringIgnoringCase(
                'AbstractModel',
                $fqcn,
                'AbstractModel must not appear as a registered normalizer.',
            );
        }
    }

    public function testConcreteClassInScannedDirectoryIsRegistered(): void
    {
        $this->setupContainerParameters([
            "RemcoSmitsDev\\BuildableSerializerBundle\\Tests\\Fixtures\\Discovery" => $this->discoveryFixturesDir,
        ]);

        $this->pass->process($this->container);

        $found = false;
        foreach ($this->getRegisteredNormalizerFqcns() as $fqcn) {
            if (stripos($fqcn, 'NotSerializableModel') !== false) {
                $found = true;
                break;
            }
        }

        $this->assertTrue(
            $found,
            'A normalizer for NotSerializableModel must be registered when its file lies under configured paths.',
        );
    }

    public function testProcessInjectsNormalizersIntoSerializerDefinition(): void
    {
        $this->registerSerializerDefinition();

        $this->setupContainerParameters([
            "RemcoSmitsDev\\BuildableSerializerBundle\\Tests\\Fixtures\\Discovery" => $this->discoveryFixturesDir,
        ]);

        $this->pass->process($this->container);

        $serializerDef = $this->container->getDefinition('serializer');
        $normalizersArg = $serializerDef->getArgument(0);

        $this->assertIsArray($normalizersArg);

        $referencedIds = array_map(
            static fn(Reference $ref): string => (string) $ref,
            array_filter($normalizersArg, static fn($arg): bool => $arg instanceof Reference),
        );

        $registeredFqcns = $this->getRegisteredNormalizerFqcns();
        $this->assertNotEmpty($registeredFqcns);

        foreach ($registeredFqcns as $fqcn) {
            $this->assertContains(
                $fqcn,
                $referencedIds,
                "Generated normalizer {$fqcn} must appear in the serializer's normalizer argument.",
            );
        }
    }

    public function testGeneratedNormalizersArePrependedBeforeExistingNormalizers(): void
    {
        $this->registerSerializerDefinition();

        $this->setupContainerParameters([
            "RemcoSmitsDev\\BuildableSerializerBundle\\Tests\\Fixtures\\Discovery" => $this->discoveryFixturesDir,
        ]);

        $this->pass->process($this->container);

        $normalizersArg = $this->container->getDefinition('serializer')->getArgument(0);
        $this->assertIsArray($normalizersArg);

        if ($normalizersArg === []) {
            $this->markTestSkipped('No normalizer References found in serializer argument.');
        }

        $firstRef = $normalizersArg[0];
        $this->assertInstanceOf(Reference::class, $firstRef);

        // The first Reference must be one of our generated normalizers.
        $registeredFqcns = $this->getRegisteredNormalizerFqcns();
        $this->assertContains(
            (string) $firstRef,
            $registeredFqcns,
            'The first normalizer in the chain must be a generated normalizer.',
        );
    }

    public function testProcessDoesNotTouchSerializerWhenNoClassesDiscovered(): void
    {
        $this->registerSerializerDefinition();

        // Use an empty temp dir — no PHP files → no discovered classes.
        $emptyDir = $this->tempDir . DIRECTORY_SEPARATOR . 'empty_src';
        mkdir($emptyDir, 0777, true);

        $this->setupContainerParameters([
            "App\\Empty" => $emptyDir,
        ]);

        $this->pass->process($this->container);

        // Serializer argument must still be the original TaggedIteratorArgument (unchanged).
        $serializerDef = $this->container->getDefinition('serializer');
        $arg = $serializerDef->getArgument(0);

        $this->assertIsNotArray(
            $arg,
            'Serializer argument must not be converted to a flat array when no classes are discovered.',
        );
    }

    public function testProcessCreatesCacheDirWhenAbsent(): void
    {
        $newCacheDir = $this->tempDir . DIRECTORY_SEPARATOR . 'auto_created';
        $this->assertDirectoryDoesNotExist($newCacheDir);

        $this->setupContainerParameters([
            "RemcoSmitsDev\\BuildableSerializerBundle\\Tests\\Fixtures\\Discovery" => $this->discoveryFixturesDir,
        ], $newCacheDir);

        $this->pass->process($this->container);

        $this->assertDirectoryExists($newCacheDir);
    }

    public function testProcessIsIdempotentWhenRunTwice(): void
    {
        $this->setupContainerParameters([
            "RemcoSmitsDev\\BuildableSerializerBundle\\Tests\\Fixtures\\Discovery" => $this->discoveryFixturesDir,
        ]);

        $this->pass->process($this->container);
        $countAfterFirst = count($this->getRegisteredNormalizerFqcns());

        // Re-run; services are already registered — must not duplicate.
        $this->pass->process($this->container);
        $countAfterSecond = count($this->getRegisteredNormalizerFqcns());

        $this->assertSame(
            $countAfterFirst,
            $countAfterSecond,
            'Running the pass twice must not register duplicate normalizer services.',
        );
    }

    /**
     * Configure the container with all parameters required by the pass.
     *
     * @param array<string, string|array{path: string, exclude: string|null}> $paths namespace-prefix => directory or config
     */
    private function setupContainerParameters(array $paths, ?string $cacheDir = null): void
    {
        // Normalize paths to new format
        $normalizedPaths = [];
        foreach ($paths as $namespace => $config) {
            if (\is_string($config)) {
                $normalizedPaths[$namespace] = ['path' => $config, 'exclude' => null];
            } else {
                $normalizedPaths[$namespace] = $config;
            }
        }

        $resolvedCacheDir = $cacheDir ?? $this->tempDir;

        $this->container->setParameter('buildable_serializer.cache_dir', $resolvedCacheDir);
        $this->container->setParameter('buildable_serializer.generated_namespace', "BuildableTest\\Generated");
        $this->container->setParameter('buildable_serializer.paths', $normalizedPaths);
        $this->container->setParameter('buildable_serializer.features', [
            'groups' => true,
            'max_depth' => true,
            'circular_reference' => true,
            'name_converter' => false,
            'skip_null_values' => true,
        ]);
        $this->container->setParameter('buildable_serializer.generation', [
            'strict_types' => true,
        ]);

        // Register the required services for the compiler pass
        $this->registerRequiredServices($normalizedPaths, $resolvedCacheDir);

        // Register the serializer definition if not already registered
        if (!$this->container->hasDefinition('serializer')) {
            $this->registerSerializerDefinition();
        }
    }

    /**
     * Register the services required by RegisterGeneratedNormalizersPass.
     *
     * @param array<string, array{path: string, exclude: string|null}> $paths
     */
    private function registerRequiredServices(array $paths, string $cacheDir): void
    {
        // Create PropertyInfoExtractor
        $reflectionExtractor = new ReflectionExtractor();
        $phpDocExtractor = new PhpDocExtractor();
        $propertyInfoExtractor = new PropertyInfoExtractor(
            [$reflectionExtractor],
            [$phpDocExtractor, $reflectionExtractor],
            [$phpDocExtractor],
            [$reflectionExtractor],
            [$reflectionExtractor],
        );

        // Create MetadataFactory
        $metadataFactory = new MetadataFactory($propertyInfoExtractor);

        // Create FinderClassDiscovery
        $discovery = new FinderClassDiscovery($metadataFactory, $paths);

        // Create NormalizerGenerator
        $generator = new NormalizerGenerator(
            $metadataFactory,
            $cacheDir,
            "BuildableTest\\Generated",
            [
                'groups' => true,
                'max_depth' => true,
                'circular_reference' => true,
                'name_converter' => false,
                'skip_null_values' => true,
            ],
            [
                'strict_types' => true,
            ],
        );

        // Register services in container (as synthetic services for the compiler pass)
        $this->container->set(NormalizerGenerator::class, $generator);
        $this->container->set(ClassDiscoveryInterface::class, $discovery);
    }

    /**
     * Add a minimal `serializer` service definition to the container with a
     * TaggedIteratorArgument placeholder as its first argument, mirroring what
     * Symfony's FrameworkBundle registers.
     */
    private function registerSerializerDefinition(): void
    {
        $def = new Definition(Serializer::class);
        $def->setArguments([
            // Use a plain object (non-array) to simulate the TaggedIteratorArgument.
            new \Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument('serializer.normalizer'),
            [],
            [],
        ]);
        $this->container->setDefinition('serializer', $def);
    }

    private function assertNoNormalizerServicesRegistered(): void
    {
        $this->assertSame(
            [],
            $this->getRegisteredNormalizerFqcns(),
            'No normalizer services should have been registered.',
        );
    }

    private function assertAtLeastOneNormalizerServiceRegistered(): void
    {
        $this->assertNotEmpty(
            $this->getRegisteredNormalizerFqcns(),
            'At least one normalizer service should have been registered.',
        );
    }

    /**
     * Return the FQCNs of all container services that implement
     * GeneratedNormalizerInterface and are tagged with serializer.normalizer.
     *
     * @return list<string>
     */
    private function getRegisteredNormalizerFqcns(): array
    {
        $fqcns = [];

        foreach ($this->container->getDefinitions() as $id => $definition) {
            $class = $definition->getClass();
            if ($class === null) {
                continue;
            }

            // Include the generated file if it exists so the class becomes available
            $file = $definition->getFile();
            if ($file !== null && is_file($file) && !class_exists($class, false)) {
                require_once $file;
            }

            if (!class_exists($class, false)) {
                continue;
            }
            if (!is_a($class, GeneratedNormalizerInterface::class, true)) {
                continue;
            }
            $fqcns[] = $id;
        }

        return $fqcns;
    }

    /**
     * Recursively find all *Normalizer.php files under a directory.
     *
     * @return list<string>
     */
    private function findNormalizerFiles(string $dir): array
    {
        $files = [];

        if (!is_dir($dir)) {
            return $files;
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $dir,
            \FilesystemIterator::SKIP_DOTS,
        ));

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (
                $file->isFile()
                && $file->getExtension() === 'php'
                && str_ends_with($file->getFilename(), 'Normalizer.php')
            ) {
                $files[] = $file->getRealPath();
            }
        }

        return $files;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }

        rmdir($dir);
    }
}
