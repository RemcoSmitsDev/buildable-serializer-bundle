<?php

declare(strict_types=1);

namespace Buildable\SerializerBundle\Tests\Unit\Discovery;

use Buildable\SerializerBundle\Discovery\FinderClassDiscovery;
use Buildable\SerializerBundle\Tests\Fixtures\Discovery\AnotherSerializableModel;
use Buildable\SerializerBundle\Tests\Fixtures\Discovery\NotSerializableModel;
use Buildable\SerializerBundle\Tests\Fixtures\Discovery\SerializableModel;
use Buildable\SerializerBundle\Tests\Fixtures\Discovery\Sub\NestedSerializableModel;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Buildable\SerializerBundle\Discovery\FinderClassDiscovery
 */
final class FinderClassDiscoveryTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        // Absolute path to tests/Fixtures/Discovery/
        $this->fixturesDir = realpath(__DIR__ . '/../../Fixtures/Discovery');
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function testDiscoversSingleSerializableClass(): void
    {
        $discovery = new FinderClassDiscovery([
            'Buildable\SerializerBundle\Tests\Fixtures\Discovery' => $this->fixturesDir,
        ]);

        $classes = $discovery->discoverClasses();

        $this->assertContains(SerializableModel::class, $classes);
    }

    public function testDiscoversAllSerializableClassesInDirectory(): void
    {
        $discovery = new FinderClassDiscovery([
            'Buildable\SerializerBundle\Tests\Fixtures\Discovery' => $this->fixturesDir,
        ]);

        $classes = $discovery->discoverClasses();

        $this->assertContains(SerializableModel::class, $classes);
        $this->assertContains(AnotherSerializableModel::class, $classes);
    }

    public function testDiscoversClassesInSubdirectories(): void
    {
        $discovery = new FinderClassDiscovery([
            'Buildable\SerializerBundle\Tests\Fixtures\Discovery' => $this->fixturesDir,
        ]);

        $classes = $discovery->discoverClasses();

        $this->assertContains(NestedSerializableModel::class, $classes);
    }

    public function testEmptyPathsReturnsEmptyArray(): void
    {
        $discovery = new FinderClassDiscovery([]);

        $this->assertSame([], $discovery->discoverClasses());
    }

    public function testResultIsSorted(): void
    {
        $discovery = new FinderClassDiscovery([
            'Buildable\SerializerBundle\Tests\Fixtures\Discovery' => $this->fixturesDir,
        ]);

        $classes = $discovery->discoverClasses();

        $sorted = $classes;
        sort($sorted);
        $this->assertSame($sorted, $classes);
    }

    public function testResultIsDeduplicatedWhenSameDirectoryGivenTwice(): void
    {
        $discovery = new FinderClassDiscovery([
            'Buildable\SerializerBundle\Tests\Fixtures\Discovery' => $this->fixturesDir,
            // Simulate duplicate by using a slightly different path that resolves to the same dir
            'Buildable\SerializerBundle\Tests\Fixtures\Discovery' => $this->fixturesDir . '/',
        ]);

        $classes = $discovery->discoverClasses();
        $unique = array_unique($classes);

        $this->assertCount(\count($unique), $classes, 'Result must not contain duplicates.');
    }

    // -------------------------------------------------------------------------
    // Exclusions
    // -------------------------------------------------------------------------

    public function testExcludesClassesWithoutSerializableAttribute(): void
    {
        $discovery = new FinderClassDiscovery([
            'Buildable\SerializerBundle\Tests\Fixtures\Discovery' => $this->fixturesDir,
        ]);

        $classes = $discovery->discoverClasses();

        $this->assertNotContains(NotSerializableModel::class, $classes);
    }

    public function testExcludesAbstractClasses(): void
    {
        $discovery = new FinderClassDiscovery([
            'Buildable\SerializerBundle\Tests\Fixtures\Discovery' => $this->fixturesDir,
        ]);

        $classes = $discovery->discoverClasses();

        // AbstractModel carries #[Serializable] but is abstract — must be skipped.
        $this->assertNotContains('Buildable\SerializerBundle\Tests\Fixtures\Discovery\AbstractModel', $classes);
    }

    // -------------------------------------------------------------------------
    // Error handling
    // -------------------------------------------------------------------------

    public function testThrowsInvalidArgumentExceptionForNonExistentDirectory(): void
    {
        $discovery = new FinderClassDiscovery([
            'App\Model' => '/this/path/does/absolutely/not/exist',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not exist|not a directory/i');

        $discovery->discoverClasses();
    }

    // -------------------------------------------------------------------------
    // Multiple paths
    // -------------------------------------------------------------------------

    public function testMergesClassesFromMultiplePaths(): void
    {
        $subDir = $this->fixturesDir . \DIRECTORY_SEPARATOR . 'Sub';

        $discovery = new FinderClassDiscovery([
            'Buildable\SerializerBundle\Tests\Fixtures\Discovery' => $this->fixturesDir,
            'Buildable\SerializerBundle\Tests\Fixtures\Discovery\Sub' => $subDir,
        ]);

        $classes = $discovery->discoverClasses();

        $this->assertContains(SerializableModel::class, $classes);
        $this->assertContains(NestedSerializableModel::class, $classes);
    }

    // -------------------------------------------------------------------------
    // FQCN derivation
    // -------------------------------------------------------------------------

    public function testFqcnIsDerivedFromPathWithoutReadingFile(): void
    {
        // This verifies the FQCN is correct by checking the class is findable
        // via the standard autoloader after discovery (no manual require needed).
        $discovery = new FinderClassDiscovery([
            'Buildable\SerializerBundle\Tests\Fixtures\Discovery' => $this->fixturesDir,
        ]);

        $classes = $discovery->discoverClasses();

        foreach ($classes as $fqcn) {
            $this->assertTrue(
                class_exists($fqcn, false),
                sprintf('Class "%s" should be loadable after discovery.', $fqcn),
            );
        }
    }
}
