<?php

declare(strict_types=1);

namespace BuildableSerializerBundle\Tests\Unit\Discovery;

use BuildableSerializerBundle\Discovery\FinderClassDiscovery;
use BuildableSerializerBundle\Metadata\ClassMetadata;
use BuildableSerializerBundle\Metadata\MetadataFactory;
use BuildableSerializerBundle\Tests\Fixtures\Discovery\AnotherSerializableModel;
use BuildableSerializerBundle\Tests\Fixtures\Discovery\NotSerializableModel;
use BuildableSerializerBundle\Tests\Fixtures\Discovery\SerializableModel;
use BuildableSerializerBundle\Tests\Fixtures\Discovery\Sub\NestedSerializableModel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;

/**
 * @covers \BuildableSerializerBundle\Discovery\FinderClassDiscovery
 */
final class FinderClassDiscoveryTest extends TestCase
{
    private string $fixturesDir;
    private MetadataFactory $metadataFactory;

    protected function setUp(): void
    {
        // Absolute path to tests/Fixtures/Discovery/
        $this->fixturesDir = realpath(__DIR__ . '/../../Fixtures/Discovery');

        $reflection = new ReflectionExtractor();
        $this->metadataFactory = new MetadataFactory(new PropertyInfoExtractor(
            listExtractors: [$reflection],
            typeExtractors: [$reflection],
            accessExtractors: [$reflection],
        ));
    }

    /**
     * Extract fully-qualified class names from a list of ClassMetadata objects.
     *
     * @param list<ClassMetadata<object>> $classes
     * @return list<string>
     */
    private function classNames(array $classes): array
    {
        return array_map(static fn(ClassMetadata $m): string => $m->getClassName(), $classes);
    }

    public function testDiscoversSingleSerializableClass(): void
    {
        $discovery = new FinderClassDiscovery($this->metadataFactory, [
            'BuildableSerializerBundle\Tests\Fixtures\Discovery' => $this->fixturesDir,
        ]);

        $classes = $this->classNames($discovery->discoverClasses());

        $this->assertContains(SerializableModel::class, $classes);
    }

    public function testDiscoversAllSerializableClassesInDirectory(): void
    {
        $discovery = new FinderClassDiscovery($this->metadataFactory, [
            'BuildableSerializerBundle\Tests\Fixtures\Discovery' => $this->fixturesDir,
        ]);

        $classes = $this->classNames($discovery->discoverClasses());

        $this->assertContains(SerializableModel::class, $classes);
        $this->assertContains(AnotherSerializableModel::class, $classes);
    }

    public function testDiscoversClassesInSubdirectories(): void
    {
        $discovery = new FinderClassDiscovery($this->metadataFactory, [
            'BuildableSerializerBundle\Tests\Fixtures\Discovery' => $this->fixturesDir,
        ]);

        $classes = $this->classNames($discovery->discoverClasses());

        $this->assertContains(NestedSerializableModel::class, $classes);
    }

    public function testEmptyPathsReturnsEmptyArray(): void
    {
        $discovery = new FinderClassDiscovery($this->metadataFactory, []);

        $this->assertSame([], $discovery->discoverClasses());
    }

    public function testResultIsSorted(): void
    {
        $discovery = new FinderClassDiscovery($this->metadataFactory, [
            'BuildableSerializerBundle\Tests\Fixtures\Discovery' => $this->fixturesDir,
        ]);

        $classes = $this->classNames($discovery->discoverClasses());

        $sorted = $classes;
        sort($sorted);
        $this->assertSame($sorted, $classes);
    }

    public function testResultIsDeduplicatedWhenSameDirectoryGivenTwice(): void
    {
        $discovery = new FinderClassDiscovery($this->metadataFactory, [
            'BuildableSerializerBundle\Tests\Fixtures\Discovery' => $this->fixturesDir,
            // Simulate duplicate by using a slightly different path that resolves to the same dir
            'BuildableSerializerBundle\Tests\Fixtures\Discovery' => $this->fixturesDir . '/',
        ]);

        $classes = $this->classNames($discovery->discoverClasses());
        $unique = array_unique($classes);

        $this->assertCount(\count($unique), $classes, 'Result must not contain duplicates.');
    }

    public function testIncludesConcreteClassesMatchedOnlyByPsr4Path(): void
    {
        $discovery = new FinderClassDiscovery($this->metadataFactory, [
            'BuildableSerializerBundle\Tests\Fixtures\Discovery' => $this->fixturesDir,
        ]);

        $classes = $this->classNames($discovery->discoverClasses());

        $this->assertContains(NotSerializableModel::class, $classes);
    }

    public function testExcludesAbstractClasses(): void
    {
        $discovery = new FinderClassDiscovery($this->metadataFactory, [
            'BuildableSerializerBundle\Tests\Fixtures\Discovery' => $this->fixturesDir,
        ]);

        $classes = $this->classNames($discovery->discoverClasses());

        // AbstractModel is abstract — must be skipped.
        $this->assertNotContains('BuildableSerializerBundle\Tests\Fixtures\Discovery\AbstractModel', $classes);
    }

    public function testThrowsInvalidArgumentExceptionForNonExistentDirectory(): void
    {
        $discovery = new FinderClassDiscovery($this->metadataFactory, [
            'App\Model' => '/this/path/does/absolutely/not/exist',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not exist|not a directory/i');

        $discovery->discoverClasses();
    }

    public function testMergesClassesFromMultiplePaths(): void
    {
        $subDir = $this->fixturesDir . \DIRECTORY_SEPARATOR . 'Sub';

        $discovery = new FinderClassDiscovery($this->metadataFactory, [
            'BuildableSerializerBundle\Tests\Fixtures\Discovery' => $this->fixturesDir,
            'BuildableSerializerBundle\Tests\Fixtures\Discovery\Sub' => $subDir,
        ]);

        $classes = $this->classNames($discovery->discoverClasses());

        $this->assertContains(SerializableModel::class, $classes);
        $this->assertContains(NestedSerializableModel::class, $classes);
    }

    public function testDiscoveredItemsAreClassMetadataObjects(): void
    {
        $discovery = new FinderClassDiscovery($this->metadataFactory, [
            'BuildableSerializerBundle\Tests\Fixtures\Discovery' => $this->fixturesDir,
        ]);

        foreach ($discovery->discoverClasses() as $item) {
            $this->assertInstanceOf(ClassMetadata::class, $item);
        }
    }

    public function testFqcnIsDerivedFromPathWithoutReadingFile(): void
    {
        // This verifies the FQCN is correct by checking the class is findable
        // via the standard autoloader after discovery (no manual require needed).
        $discovery = new FinderClassDiscovery($this->metadataFactory, [
            'BuildableSerializerBundle\Tests\Fixtures\Discovery' => $this->fixturesDir,
        ]);

        foreach ($discovery->discoverClasses() as $metadata) {
            $this->assertTrue(
                class_exists($metadata->getClassName(), false),
                sprintf('Class "%s" should be loadable after discovery.', $metadata->getClassName()),
            );
        }
    }
}
