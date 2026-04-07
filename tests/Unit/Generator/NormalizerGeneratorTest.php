<?php

declare(strict_types=1);

namespace Buildable\SerializerBundle\Tests\Unit\Generator;

use Buildable\SerializerBundle\Generator\NormalizerGenerator;
use Buildable\SerializerBundle\Metadata\MetadataFactory;
use Buildable\SerializerBundle\Tests\AbstractTestCase;
use Buildable\SerializerBundle\Tests\Fixtures\Model\Author;
use Buildable\SerializerBundle\Tests\Fixtures\Model\BlogWithGroups;
use Buildable\SerializerBundle\Tests\Fixtures\Model\CircularReference;
use Buildable\SerializerBundle\Tests\Fixtures\Model\SimpleBlog;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;

/**
 * @covers \Buildable\SerializerBundle\Generator\NormalizerGenerator
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

    // -------------------------------------------------------------------------
    // generateAndWrite()
    // -------------------------------------------------------------------------

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

    public function testGeneratedFileHasNormalizerPriorityConstant(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $path = $this->generator->generateAndWrite($metadata);

        $this->assertStringContainsString('NORMALIZER_PRIORITY', file_get_contents($path));
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

    // -------------------------------------------------------------------------
    // resolveNormalizerFqcn()
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // resolveFilePath()
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // generateAll()
    // -------------------------------------------------------------------------

    public function testGenerateAllCreatesFilesForAllClasses(): void
    {
        $paths = $this->generator->generateAll([SimpleBlog::class, Author::class]);

        $this->assertCount(2, $paths);

        foreach ($paths as $path) {
            $this->assertFileExists($path);
        }
    }

    public function testGenerateAllReturnsPathsInInputOrder(): void
    {
        $paths = $this->generator->generateAll([SimpleBlog::class, Author::class]);

        $this->assertStringContainsString('SimpleBlog', $paths[0]);
        $this->assertStringContainsString('Author', $paths[1]);
    }

    public function testGenerateAllWithSingleClassReturnsSinglePath(): void
    {
        $paths = $this->generator->generateAll([SimpleBlog::class]);

        $this->assertCount(1, $paths);
        $this->assertFileExists($paths[0]);
    }

    public function testGenerateAllWithEmptyArrayReturnsEmptyArray(): void
    {
        $paths = $this->generator->generateAll([]);

        $this->assertSame([], $paths);
    }

    // -------------------------------------------------------------------------
    // getMetadataFactory()
    // -------------------------------------------------------------------------

    public function testGetMetadataFactoryReturnsFactory(): void
    {
        $factory = $this->generator->getMetadataFactory();

        $this->assertInstanceOf(MetadataFactory::class, $factory);
    }

    // -------------------------------------------------------------------------
    // Generated content — accessor
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Generated content — groups
    // -------------------------------------------------------------------------

    public function testGeneratedFileContainsGroupsMapWhenGroupsPresent(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(BlogWithGroups::class);
        $path = $this->generator->generateAndWrite($metadata);
        $content = file_get_contents($path);

        // Generator emits inline array_intersect() group checks (no GROUPS_MAP constant)
        $this->assertStringContainsString('array_intersect', $content);
        $this->assertStringContainsString('blog:read', $content);
    }

    public function testGeneratedFileContainsGroupsVariableForBlogWithGroups(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(BlogWithGroups::class);
        $path = $this->generator->generateAndWrite($metadata);
        $content = file_get_contents($path);

        $this->assertStringContainsString('$groups', $content);
    }

    // -------------------------------------------------------------------------
    // Generated content — circular reference
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Generated content — skip_null_values
    // -------------------------------------------------------------------------

    public function testGeneratedFileContainsSkipNullValuesLogic(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $path = $this->generator->generateAndWrite($metadata);
        $content = file_get_contents($path);

        $this->assertStringContainsString('skipNullValues', $content);
    }

    // -------------------------------------------------------------------------
    // Overwrite on regeneration
    // -------------------------------------------------------------------------

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
