<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Unit\Generator;

use RemcoSmitsDev\BuildableSerializerBundle\Generator\NormalizerGenerator;
use RemcoSmitsDev\BuildableSerializerBundle\Metadata\ClassMetadata;
use RemcoSmitsDev\BuildableSerializerBundle\Metadata\MetadataFactory;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\AbstractTestCase;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\Author;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\BlogWithGroups;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\CircularReference;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\ScalarTypesFixture;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\SimpleBlog;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;

/**
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Generator\NormalizerGenerator
 */
final class NormalizerGeneratorTest extends AbstractTestCase
{
    private string $tempDir;
    private NormalizerGenerator $generator;

    protected function setUp(): void
    {
        $this->tempDir = $this->createTempDir();
        $this->generator = $this->makeGenerator($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeTempDir($this->tempDir);
    }

    public function testGenerateAndWriteReturnsValidFilePath(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $path = $this->generator->generateAndWrite($metadata);

        $this->assertFileExists($path);
    }

    public function testGeneratedFileHasPhpExtension(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $path = $this->generator->generateAndWrite($metadata);

        $this->assertStringEndsWith('.php', $path);
    }

    public function testGeneratedFileHasStrictTypes(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $path = $this->generator->generateAndWrite($metadata);

        $this->assertStringContainsString('declare(strict_types=1)', file_get_contents($path));
    }

    public function testGeneratedFileHasCorrectNamespace(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $path = $this->generator->generateAndWrite($metadata);

        $this->assertStringContainsString('namespace BuildableTest\\Generated', file_get_contents($path));
    }

    public function testGeneratedFileHasNormalizerClass(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $path = $this->generator->generateAndWrite($metadata);

        $this->assertStringContainsString('class SimpleBlogNormalizer', file_get_contents($path));
    }

    public function testGeneratedFileImplementsNormalizerInterface(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $path = $this->generator->generateAndWrite($metadata);

        $this->assertStringContainsString('NormalizerInterface', file_get_contents($path));
    }

    public function testGeneratedFileImplementsGeneratedNormalizerInterface(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $path = $this->generator->generateAndWrite($metadata);

        $this->assertStringContainsString('GeneratedNormalizerInterface', file_get_contents($path));
    }

    public function testGeneratedFileHasGeneratedTag(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $path = $this->generator->generateAndWrite($metadata);

        $this->assertStringContainsString('@generated', file_get_contents($path));
    }

    public function testGeneratedFileHasNormalizeMethod(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $path = $this->generator->generateAndWrite($metadata);

        $this->assertStringContainsString('public function normalize(', file_get_contents($path));
    }

    public function testGeneratedFileHasSupportsNormalizationMethod(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $path = $this->generator->generateAndWrite($metadata);

        $this->assertStringContainsString('public function supportsNormalization(', file_get_contents($path));
    }

    public function testGeneratedFileHasGetSupportedTypesMethod(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $path = $this->generator->generateAndWrite($metadata);

        $this->assertStringContainsString('public function getSupportedTypes(', file_get_contents($path));
    }

    public function testResolveNormalizerFqcnReturnsExpectedFqcn(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $fqcn = $this->generator->resolveNormalizerFqcn($metadata);

        $this->assertStringEndsWith('SimpleBlogNormalizer', $fqcn);
    }

    public function testResolveNormalizerFqcnContainsGeneratedNamespace(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $fqcn = $this->generator->resolveNormalizerFqcn($metadata);

        $this->assertStringStartsWith('BuildableTest\\Generated', $fqcn);
    }

    public function testResolveNormalizerFqcnForAuthor(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(Author::class);
        $fqcn = $this->generator->resolveNormalizerFqcn($metadata);

        $this->assertStringEndsWith('AuthorNormalizer', $fqcn);
    }

    public function testResolveFilePathEndsWithPhpExtension(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $path = $this->generator->resolveFilePath($metadata);

        $this->assertStringEndsWith('.php', $path);
    }

    public function testResolveFilePathStartsWithCacheDir(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $path = $this->generator->resolveFilePath($metadata);

        $this->assertStringStartsWith($this->tempDir, $path);
    }

    public function testResolveFilePathContainsNormalizerClassName(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $path = $this->generator->resolveFilePath($metadata);

        $this->assertStringContainsString('SimpleBlogNormalizer', $path);
    }

    public function testGenerateAllCreatesFilesForAllClasses(): void
    {
        $paths = $this->generator->generateAll([
            new ClassMetadata(new \ReflectionClass(SimpleBlog::class), SimpleBlog::class),
            new ClassMetadata(new \ReflectionClass(Author::class), Author::class),
        ]);

        $this->assertCount(2, $paths);

        foreach ($paths as $path) {
            $this->assertFileExists($path);
        }
    }

    public function testGenerateAllReturnsPathsInInputOrder(): void
    {
        $paths = $this->generator->generateAll([
            new ClassMetadata(new \ReflectionClass(SimpleBlog::class), SimpleBlog::class),
            new ClassMetadata(new \ReflectionClass(Author::class), Author::class),
        ]);

        $this->assertStringContainsString('SimpleBlog', $paths[0]);
        $this->assertStringContainsString('Author', $paths[1]);
    }

    public function testGenerateAllWithSingleClassReturnsSinglePath(): void
    {
        $paths = $this->generator->generateAll([
            new ClassMetadata(new \ReflectionClass(SimpleBlog::class), SimpleBlog::class),
        ]);

        $this->assertCount(1, $paths);
        $this->assertFileExists($paths[0]);
    }

    public function testGenerateAllWithEmptyArrayReturnsEmptyArray(): void
    {
        $paths = $this->generator->generateAll([]);

        $this->assertSame([], $paths);
    }

    public function testGetMetadataFactoryReturnsFactory(): void
    {
        $factory = $this->generator->getMetadataFactory();

        $this->assertInstanceOf(MetadataFactory::class, $factory);
    }

    public function testGeneratedFileContainsPropertyAccessor(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $path = $this->generator->generateAndWrite($metadata);
        $content = file_get_contents($path);

        // SimpleBlog uses getter-based access (private promoted params)
        $this->assertStringContainsString('->getId()', $content);
    }

    public function testGeneratedFileContainsAllSimpleBlogGetters(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $path = $this->generator->generateAndWrite($metadata);
        $content = file_get_contents($path);

        $this->assertStringContainsString('->getTitle()', $content);
        $this->assertStringContainsString('->getContent()', $content);
    }

    public function testGeneratedFileContainsGroupsMapWhenGroupsPresent(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(BlogWithGroups::class);
        $path = $this->generator->generateAndWrite($metadata);
        $content = file_get_contents($path);

        // Generator emits a lookup table (array_fill_keys) and isset() group checks
        $this->assertStringContainsString('array_fill_keys', $content);
        $this->assertStringContainsString('isset($groupsLookup[', $content);
        $this->assertStringContainsString('blog:read', $content);
    }

    public function testGeneratedFileContainsGroupsVariableForBlogWithGroups(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(BlogWithGroups::class);
        $path = $this->generator->generateAndWrite($metadata);
        $content = file_get_contents($path);

        $this->assertStringContainsString('$groups', $content);
    }

    public function testGeneratedFileContainsCircularReferenceCheckWhenEnabled(): void
    {
        // CircularReference has nested objects, so the guard will be emitted.
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(CircularReference::class);
        $path = $this->generator->generateAndWrite($metadata);
        $content = file_get_contents($path);

        $this->assertStringContainsString('spl_object_hash', $content);
    }

    public function testGeneratedFileContainsCircularReferenceExceptionImport(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(CircularReference::class);
        $path = $this->generator->generateAndWrite($metadata);
        $content = file_get_contents($path);

        $this->assertStringContainsString('CircularReferenceException', $content);
    }

    public function testSimpleBlogNormalizerHasNoCircularReferenceGuard(): void
    {
        // SimpleBlog has no nested objects, so no circular reference guard
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $path = $this->generator->generateAndWrite($metadata);
        $content = file_get_contents($path);

        $this->assertStringNotContainsString('spl_object_hash', $content);
    }

    public function testGeneratedFileContainsSkipNullValuesLogic(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $path = $this->generator->generateAndWrite($metadata);
        $content = file_get_contents($path);

        $this->assertStringContainsString('skipNullValues', $content);
    }

    public function testMixedPropertyKeepsNullGuardWhenSkipNullValuesActive(): void
    {
        // A `mixed` property can hold null at runtime, so the null-guard
        // ($val !== null || !$skipNullValues) must still be emitted for it.
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(ScalarTypesFixture::class);
        $path = $this->generator->generateAndWrite($metadata);
        $content = file_get_contents($path);

        // The guard must be present and reference the mixed accessor
        $this->assertStringContainsString('->getMeta()', $content);
        $this->assertStringContainsString('skipNullValues', $content);

        // Locate the getMeta() block and verify the null-guard wraps it
        $metaPos = strpos($content, '->getMeta()');
        $nullGuardPos = strpos($content, 'skipNullValues');
        $this->assertNotFalse($metaPos);
        $this->assertNotFalse($nullGuardPos);

        // The _val assignment and the if-guard must appear before the data assignment for meta
        $valAssignPos = strpos($content, '_val = $object->getMeta()');
        $this->assertNotFalse($valAssignPos, 'Expected $_val = $object->getMeta() assignment for mixed property');
    }

    public function testNonNullableScalarPropertyOmitsNullGuard(): void
    {
        // A non-nullable scalar (e.g. `string $name`) can never be null, so the
        // null-guard must NOT be emitted — the value should be assigned directly.
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(ScalarTypesFixture::class);
        $path = $this->generator->generateAndWrite($metadata);
        $content = file_get_contents($path);

        $this->assertStringContainsString('->getName()', $content);

        // There must be no intermediate $_val assignment for the non-nullable name property;
        // the getter must be used directly in the data assignment.
        $this->assertStringContainsString("\$data['name'] = \$object->getName()", $content);

        // And no $_val = $object->getName() temp variable should appear
        $this->assertStringNotContainsString('_val = $object->getName()', $content);
    }

    public function testGenerateAndWriteOverwritesExistingFile(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);

        $path1 = $this->generator->generateAndWrite($metadata);
        $mtime1 = filemtime($path1);

        // Small sleep to detect file modification
        usleep(50000);

        $path2 = $this->generator->generateAndWrite($metadata);
        $mtime2 = filemtime($path2);

        $this->assertSame($path1, $path2);
        $this->assertGreaterThanOrEqual($mtime1, $mtime2);
    }
}
