<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Unit\Discovery;

use PHPUnit\Framework\TestCase;
use RemcoSmitsDev\BuildableSerializerBundle\Discovery\FinderClassDiscovery;
use RemcoSmitsDev\BuildableSerializerBundle\Metadata\ClassMetadata;
use RemcoSmitsDev\BuildableSerializerBundle\Metadata\MetadataFactory;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Discovery\AnotherSerializableModel;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Discovery\Commands\CommandHandler;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Discovery\Commands\CommandHelperClass;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Discovery\Commands\CreateUserCommand;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Discovery\Commands\DeleteUserCommand;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Discovery\Commands\Sub\OrderService;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Discovery\Commands\Sub\UpdateOrderCommand;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Discovery\NotSerializableModel;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Discovery\SerializableModel;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Discovery\Sub\NestedSerializableModel;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;

/**
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Discovery\FinderClassDiscovery
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
            'RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Discovery' => $this->fixturesDir,
        ]);

        $classes = $this->classNames($discovery->discoverClasses());

        $this->assertContains(SerializableModel::class, $classes);
    }

    public function testDiscoversAllSerializableClassesInDirectory(): void
    {
        $discovery = new FinderClassDiscovery($this->metadataFactory, [
            'RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Discovery' => $this->fixturesDir,
        ]);

        $classes = $this->classNames($discovery->discoverClasses());

        $this->assertContains(SerializableModel::class, $classes);
        $this->assertContains(AnotherSerializableModel::class, $classes);
    }

    public function testDiscoversClassesInSubdirectories(): void
    {
        $discovery = new FinderClassDiscovery($this->metadataFactory, [
            'RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Discovery' => $this->fixturesDir,
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
            'RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Discovery' => $this->fixturesDir,
        ]);

        $classes = $this->classNames($discovery->discoverClasses());

        $sorted = $classes;
        sort($sorted);
        $this->assertSame($sorted, $classes);
    }

    public function testResultIsDeduplicatedWhenSameDirectoryGivenTwice(): void
    {
        $discovery = new FinderClassDiscovery($this->metadataFactory, [
            'RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Discovery' => $this->fixturesDir,
            // Simulate duplicate by using a slightly different path that resolves to the same dir
            'RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Discovery' => $this->fixturesDir . '/',
        ]);

        $classes = $this->classNames($discovery->discoverClasses());
        $unique = array_unique($classes);

        $this->assertCount(\count($unique), $classes, 'Result must not contain duplicates.');
    }

    public function testIncludesConcreteClassesMatchedOnlyByPsr4Path(): void
    {
        $discovery = new FinderClassDiscovery($this->metadataFactory, [
            'RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Discovery' => $this->fixturesDir,
        ]);

        $classes = $this->classNames($discovery->discoverClasses());

        $this->assertContains(NotSerializableModel::class, $classes);
    }

    public function testExcludesAbstractClasses(): void
    {
        $discovery = new FinderClassDiscovery($this->metadataFactory, [
            'RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Discovery' => $this->fixturesDir,
        ]);

        $classes = $this->classNames($discovery->discoverClasses());

        // AbstractModel is abstract — must be skipped.
        $this->assertNotContains(
            'RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Discovery\AbstractModel',
            $classes,
        );
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
            'RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Discovery' => $this->fixturesDir,
            'RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Discovery\Sub' => $subDir,
        ]);

        $classes = $this->classNames($discovery->discoverClasses());

        $this->assertContains(SerializableModel::class, $classes);
        $this->assertContains(NestedSerializableModel::class, $classes);
    }

    public function testDiscoveredItemsAreClassMetadataObjects(): void
    {
        $discovery = new FinderClassDiscovery($this->metadataFactory, [
            'RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Discovery' => $this->fixturesDir,
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
            'RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Discovery' => $this->fixturesDir,
        ]);

        foreach ($discovery->discoverClasses() as $metadata) {
            $this->assertTrue(
                class_exists($metadata->getClassName(), false),
                sprintf('Class "%s" should be loadable after discovery.', $metadata->getClassName()),
            );
        }
    }

    public function testDirectoryModeFindsAllPhpFiles(): void
    {
        $commandsDir = $this->fixturesDir . \DIRECTORY_SEPARATOR . 'Commands';

        $discovery = new FinderClassDiscovery($this->metadataFactory, [
            'RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Discovery\Commands' => $commandsDir,
        ]);

        $classes = $this->classNames($discovery->discoverClasses());

        $this->assertContains(CreateUserCommand::class, $classes);
        $this->assertContains(DeleteUserCommand::class, $classes);
        $this->assertContains(CommandHandler::class, $classes);
        $this->assertContains(UpdateOrderCommand::class, $classes);
        $this->assertContains(OrderService::class, $classes);
    }

    public function testExcludePatternExcludesMatchingFiles(): void
    {
        $commandsDir = $this->fixturesDir . \DIRECTORY_SEPARATOR . 'Commands';

        $discovery = new FinderClassDiscovery($this->metadataFactory, [
            'RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Discovery\Commands' => [
                'path' => $commandsDir,
                'exclude' => '*Helper*.php',
            ],
        ]);

        $classes = $this->classNames($discovery->discoverClasses());

        $this->assertContains(CreateUserCommand::class, $classes);
        $this->assertContains(DeleteUserCommand::class, $classes);
        $this->assertContains(CommandHandler::class, $classes);
        $this->assertNotContains(CommandHelperClass::class, $classes, 'Helper class should be excluded');
    }

    public function testExcludePatternWorksInSubdirectories(): void
    {
        $commandsDir = $this->fixturesDir . \DIRECTORY_SEPARATOR . 'Commands';

        $discovery = new FinderClassDiscovery($this->metadataFactory, [
            'RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Discovery\Commands' => [
                'path' => $commandsDir,
                'exclude' => '*Service.php',
            ],
        ]);

        $classes = $this->classNames($discovery->discoverClasses());

        $this->assertContains(CreateUserCommand::class, $classes);
        $this->assertContains(UpdateOrderCommand::class, $classes);
        $this->assertNotContains(OrderService::class, $classes, 'Service class in subdirectory should be excluded');
    }

    public function testExcludePatternCanBeNull(): void
    {
        $commandsDir = $this->fixturesDir . \DIRECTORY_SEPARATOR . 'Commands';

        $discovery = new FinderClassDiscovery($this->metadataFactory, [
            'RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Discovery\Commands' => [
                'path' => $commandsDir,
                'exclude' => null,
            ],
        ]);

        $classes = $this->classNames($discovery->discoverClasses());

        $this->assertContains(CreateUserCommand::class, $classes);
        $this->assertContains(CommandHelperClass::class, $classes);
    }

    public function testExcludePatternArrayExcludesMultiplePatterns(): void
    {
        $commandsDir = $this->fixturesDir . \DIRECTORY_SEPARATOR . 'Commands';

        $discovery = new FinderClassDiscovery($this->metadataFactory, [
            'RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Discovery\Commands' => [
                'path' => $commandsDir,
                'exclude' => ['*Helper*.php', '*Handler.php'],
            ],
        ]);

        $classes = $this->classNames($discovery->discoverClasses());

        $this->assertContains(CreateUserCommand::class, $classes);
        $this->assertContains(DeleteUserCommand::class, $classes);
        $this->assertNotContains(CommandHelperClass::class, $classes, 'Helper class should be excluded');
        $this->assertNotContains(CommandHandler::class, $classes, 'Handler class should be excluded');
    }

    public function testExcludePatternArrayWorksInSubdirectories(): void
    {
        $commandsDir = $this->fixturesDir . \DIRECTORY_SEPARATOR . 'Commands';

        $discovery = new FinderClassDiscovery($this->metadataFactory, [
            'RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Discovery\Commands' => [
                'path' => $commandsDir,
                'exclude' => ['*Service.php', '*Helper*.php'],
            ],
        ]);

        $classes = $this->classNames($discovery->discoverClasses());

        $this->assertContains(CreateUserCommand::class, $classes);
        $this->assertContains(UpdateOrderCommand::class, $classes);
        $this->assertNotContains(OrderService::class, $classes, 'Service in subdirectory should be excluded');
        $this->assertNotContains(CommandHelperClass::class, $classes, 'Helper class should be excluded');
    }

    public function testExcludePatternEmptyArrayExcludesNothing(): void
    {
        $commandsDir = $this->fixturesDir . \DIRECTORY_SEPARATOR . 'Commands';

        $discovery = new FinderClassDiscovery($this->metadataFactory, [
            'RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Discovery\Commands' => [
                'path' => $commandsDir,
                'exclude' => [],
            ],
        ]);

        $classes = $this->classNames($discovery->discoverClasses());

        $this->assertContains(CreateUserCommand::class, $classes);
        $this->assertContains(CommandHelperClass::class, $classes);
        $this->assertContains(CommandHandler::class, $classes);
        $this->assertContains(OrderService::class, $classes);
    }
}
