<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Unit\Generator;

use RemcoSmitsDev\BuildableSerializerBundle\Generator\NormalizerGenerator;
use RemcoSmitsDev\BuildableSerializerBundle\Generator\NormalizerPathResolver;
use RemcoSmitsDev\BuildableSerializerBundle\Generator\NormalizerWriter;
use RemcoSmitsDev\BuildableSerializerBundle\Metadata\ClassMetadata;
use RemcoSmitsDev\BuildableSerializerBundle\Metadata\MetadataFactory;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\AbstractTestCase;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\Author;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\BlogWithContext;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\BlogWithGroups;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\CircularReference;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\NamespaceA\User as UserA;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\NamespaceB\User as UserB;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\ScalarTypesFixture;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\SimpleBlog;

/**
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Generator\NormalizerGenerator
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Generator\NormalizerWriter
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Generator\NormalizerPathResolver
 */
final class NormalizerGeneratorTest extends AbstractTestCase
{
    private string $tempDir;
    private NormalizerGenerator $generator;
    private NormalizerWriter $writer;
    private NormalizerPathResolver $pathResolver;

    protected function setUp(): void
    {
        $this->tempDir = $this->createTempDir();
        $this->generator = $this->makeGenerator();
        $this->writer = $this->makeWriter($this->tempDir);
        $this->pathResolver = $this->makePathResolver($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeTempDir($this->tempDir);
    }

    public function testGenerateReturnsValidPhpCode(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $code = $this->generator->generate($metadata);

        $this->assertStringStartsWith('<?php', $code);
    }

    public function testGeneratedCodeHasStrictTypes(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $code = $this->generator->generate($metadata);

        $this->assertStringContainsString('declare(strict_types=1)', $code);
    }

    public function testGeneratedCodeHasCorrectNamespace(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $code = $this->generator->generate($metadata);

        $this->assertStringContainsString('namespace BuildableTest\\Generated', $code);
    }

    public function testGeneratedCodeHasNormalizerClass(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $code = $this->generator->generate($metadata);

        // Class name now has a hash prefix (N + 8 hex chars) for flat namespace structure
        $this->assertMatchesRegularExpression('/class N[a-f0-9]{8}_SimpleBlogNormalizer/', $code);
    }

    public function testGeneratedCodeImplementsNormalizerInterface(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $code = $this->generator->generate($metadata);

        $this->assertStringContainsString('NormalizerInterface', $code);
    }

    public function testGeneratedCodeImplementsGeneratedNormalizerInterface(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $code = $this->generator->generate($metadata);

        $this->assertStringContainsString('GeneratedNormalizerInterface', $code);
    }

    public function testGeneratedCodeHasGeneratedTag(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $code = $this->generator->generate($metadata);

        $this->assertStringContainsString('@generated', $code);
    }

    public function testGeneratedCodeHasNormalizeMethod(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $code = $this->generator->generate($metadata);

        $this->assertStringContainsString('public function normalize(', $code);
    }

    public function testGeneratedCodeHasSupportsNormalizationMethod(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $code = $this->generator->generate($metadata);

        $this->assertStringContainsString('public function supportsNormalization(', $code);
    }

    public function testGeneratedCodeHasGetSupportedTypesMethod(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $code = $this->generator->generate($metadata);

        $this->assertStringContainsString('public function getSupportedTypes(', $code);
    }

    public function testGetMetadataFactoryReturnsFactory(): void
    {
        $factory = $this->generator->getMetadataFactory();

        $this->assertInstanceOf(MetadataFactory::class, $factory);
    }

    public function testGeneratedCodeContainsPropertyAccessor(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $code = $this->generator->generate($metadata);

        // SimpleBlog uses getter-based access (private promoted params)
        $this->assertStringContainsString('->getId()', $code);
    }

    public function testGeneratedCodeContainsAllSimpleBlogGetters(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $code = $this->generator->generate($metadata);

        $this->assertStringContainsString('->getTitle()', $code);
        $this->assertStringContainsString('->getContent()', $code);
    }

    public function testGeneratedCodeContainsGroupsMapWhenGroupsPresent(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(BlogWithGroups::class);
        $code = $this->generator->generate($metadata);

        // Generator emits a lookup table (array_fill_keys) and isset() group checks
        $this->assertStringContainsString('array_fill_keys', $code);
        $this->assertStringContainsString('isset($groupsLookup[', $code);
        $this->assertStringContainsString('blog:read', $code);
    }

    public function testGeneratedCodeContainsGroupsVariableForBlogWithGroups(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(BlogWithGroups::class);
        $code = $this->generator->generate($metadata);

        $this->assertStringContainsString('$groups', $code);
    }

    public function testGeneratedCodeContainsCircularReferenceCheckWhenEnabled(): void
    {
        // CircularReference has nested objects, so the guard will be emitted.
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(CircularReference::class);
        $code = $this->generator->generate($metadata);

        $this->assertStringContainsString('spl_object_hash', $code);
    }

    public function testGeneratedCodeContainsCircularReferenceExceptionImport(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(CircularReference::class);
        $code = $this->generator->generate($metadata);

        $this->assertStringContainsString('CircularReferenceException', $code);
    }

    public function testSimpleBlogNormalizerHasNoCircularReferenceGuard(): void
    {
        // SimpleBlog has no nested objects, so no circular reference guard
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $code = $this->generator->generate($metadata);

        $this->assertStringNotContainsString('spl_object_hash', $code);
    }

    public function testGeneratedCodeContainsSkipNullValuesLogic(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $code = $this->generator->generate($metadata);

        $this->assertStringContainsString('skipNullValues', $code);
    }

    public function testMixedPropertyKeepsNullGuardWhenSkipNullValuesActive(): void
    {
        // A `mixed` property can hold null at runtime, so the null-guard
        // ($val !== null || !$skipNullValues) must still be emitted for it.
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(ScalarTypesFixture::class);
        $code = $this->generator->generate($metadata);

        // The guard must be present and reference the mixed accessor
        $this->assertStringContainsString('->getMeta()', $code);
        $this->assertStringContainsString('skipNullValues', $code);

        // Locate the getMeta() block and verify the null-guard wraps it
        $metaPos = strpos($code, '->getMeta()');
        $nullGuardPos = strpos($code, 'skipNullValues');
        $this->assertNotFalse($metaPos);
        $this->assertNotFalse($nullGuardPos);

        // The _val assignment and the if-guard must appear before the data assignment for meta
        $valAssignPos = strpos($code, '_val = $object->getMeta()');
        $this->assertNotFalse($valAssignPos, 'Expected $_val = $object->getMeta() assignment for mixed property');
    }

    public function testNonNullableScalarPropertyOmitsNullGuard(): void
    {
        // A non-nullable scalar (e.g. `string $name`) can never be null, so the
        // null-guard must NOT be emitted — the value should be assigned directly.
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(ScalarTypesFixture::class);
        $code = $this->generator->generate($metadata);

        $this->assertStringContainsString('->getName()', $code);

        // There must be no intermediate $_val assignment for the non-nullable name property;
        // the getter must be used directly in the data assignment.
        $this->assertStringContainsString("\$data['name'] = \$object->getName()", $code);

        // And no $_val = $object->getName() temp variable should appear
        $this->assertStringNotContainsString('_val = $object->getName()', $code);
    }

    public function testGeneratedNormalizersForSameClassNameInDifferentNamespacesAreBothValid(): void
    {
        $metadataA = $this->generator->getMetadataFactory()->getMetadataFor(UserA::class);
        $metadataB = $this->generator->getMetadataFactory()->getMetadataFor(UserB::class);

        $codeA = $this->generator->generate($metadataA);
        $codeB = $this->generator->generate($metadataB);

        $this->assertStringContainsString('UserNormalizer', $codeA);
        $this->assertStringContainsString('UserNormalizer', $codeB);

        // They should reference their respective original classes
        $this->assertStringContainsString('NamespaceA\\User', $codeA);
        $this->assertStringContainsString('NamespaceB\\User', $codeB);
    }

    public function testFlatNamespaceStructure(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $code = $this->generator->generate($metadata);

        // The namespace should be flat (just the base generated namespace)
        $this->assertMatchesRegularExpression('/namespace BuildableTest\\\\Generated;/', $code);
        // Should NOT have nested namespaces like BuildableTest\Generated\RemcoSmitsDev\...
        $this->assertDoesNotMatchRegularExpression('/namespace BuildableTest\\\\Generated\\\\[A-Za-z]/', $code);
    }

    public function testWriteReturnsValidFilePath(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $path = $this->writer->write($metadata);

        $this->assertFileExists($path);
    }

    public function testWrittenFileHasPhpExtension(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $path = $this->writer->write($metadata);

        $this->assertStringEndsWith('.php', $path);
    }

    public function testResolveNormalizerFqcnReturnsExpectedFqcn(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $fqcn = $this->pathResolver->resolveNormalizerFqcn($metadata);

        $this->assertStringEndsWith('SimpleBlogNormalizer', $fqcn);
    }

    public function testResolveNormalizerFqcnContainsGeneratedNamespace(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $fqcn = $this->pathResolver->resolveNormalizerFqcn($metadata);

        $this->assertStringStartsWith('BuildableTest\\Generated', $fqcn);
    }

    public function testResolveNormalizerFqcnForAuthor(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(Author::class);
        $fqcn = $this->pathResolver->resolveNormalizerFqcn($metadata);

        $this->assertStringEndsWith('AuthorNormalizer', $fqcn);
    }

    public function testResolveFilePathEndsWithPhpExtension(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $path = $this->pathResolver->resolveFilePath($metadata);

        $this->assertStringEndsWith('.php', $path);
    }

    public function testResolveFilePathStartsWithCacheDir(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $path = $this->pathResolver->resolveFilePath($metadata);

        $this->assertStringStartsWith($this->tempDir, $path);
    }

    public function testResolveFilePathContainsNormalizerClassName(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $path = $this->pathResolver->resolveFilePath($metadata);

        $this->assertStringContainsString('SimpleBlogNormalizer', $path);
    }

    public function testWriteAllCreatesFilesForAllClasses(): void
    {
        $paths = $this->writer->writeAll([
            new ClassMetadata(new \ReflectionClass(SimpleBlog::class), SimpleBlog::class),
            new ClassMetadata(new \ReflectionClass(Author::class), Author::class),
        ]);

        $this->assertCount(2, $paths);

        foreach ($paths as $path) {
            $this->assertFileExists($path);
        }
    }

    public function testWriteAllReturnsPathsInInputOrder(): void
    {
        $paths = $this->writer->writeAll([
            new ClassMetadata(new \ReflectionClass(SimpleBlog::class), SimpleBlog::class),
            new ClassMetadata(new \ReflectionClass(Author::class), Author::class),
        ]);

        $this->assertStringContainsString('SimpleBlog', $paths[0]);
        $this->assertStringContainsString('Author', $paths[1]);
    }

    public function testWriteAllWithSingleClassReturnsSinglePath(): void
    {
        $paths = $this->writer->writeAll([
            new ClassMetadata(new \ReflectionClass(SimpleBlog::class), SimpleBlog::class),
        ]);

        $this->assertCount(1, $paths);
        $this->assertFileExists($paths[0]);
    }

    public function testWriteAllWithEmptyArrayReturnsEmptyArray(): void
    {
        $paths = $this->writer->writeAll([]);

        $this->assertSame([], $paths);
    }

    public function testWriteOverwritesExistingFile(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);

        $path1 = $this->writer->write($metadata);
        $mtime1 = filemtime($path1);

        // Small sleep to detect file modification
        usleep(50000);

        $path2 = $this->writer->write($metadata);
        clearstatcache();
        $mtime2 = filemtime($path2);

        $this->assertSame($path1, $path2);
        $this->assertGreaterThanOrEqual($mtime1, $mtime2);
    }

    public function testResolveFilePathIsFlatWithNoNestedDirectories(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $path = $this->pathResolver->resolveFilePath($metadata);

        // File should be directly in the cache dir, not in nested directories
        $relativePath = str_replace($this->tempDir . DIRECTORY_SEPARATOR, '', $path);
        $this->assertStringNotContainsString(DIRECTORY_SEPARATOR, $relativePath);
    }

    public function testResolveFilePathHasHashedNamespacePrefix(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $path = $this->pathResolver->resolveFilePath($metadata);
        $filename = basename($path);

        // Filename should match pattern: N<8 hex chars>_<ClassName>Normalizer.php
        $this->assertMatchesRegularExpression('/^N[a-f0-9]{8}_SimpleBlogNormalizer\.php$/', $filename);
    }

    public function testSameClassNameInDifferentNamespacesHaveDifferentFilePaths(): void
    {
        $metadataA = $this->generator->getMetadataFactory()->getMetadataFor(UserA::class);
        $metadataB = $this->generator->getMetadataFactory()->getMetadataFor(UserB::class);

        $pathA = $this->pathResolver->resolveFilePath($metadataA);
        $pathB = $this->pathResolver->resolveFilePath($metadataB);

        // Both should end with UserNormalizer.php but have different prefixes
        $this->assertStringEndsWith('UserNormalizer.php', $pathA);
        $this->assertStringEndsWith('UserNormalizer.php', $pathB);
        $this->assertNotSame($pathA, $pathB, 'Same class name in different namespaces must have different file paths');
    }

    public function testSameClassNameInDifferentNamespacesHaveDifferentFqcns(): void
    {
        $metadataA = $this->generator->getMetadataFactory()->getMetadataFor(UserA::class);
        $metadataB = $this->generator->getMetadataFactory()->getMetadataFor(UserB::class);

        $fqcnA = $this->pathResolver->resolveNormalizerFqcn($metadataA);
        $fqcnB = $this->pathResolver->resolveNormalizerFqcn($metadataB);

        // Both should end with UserNormalizer but have different prefixes
        $this->assertStringEndsWith('UserNormalizer', $fqcnA);
        $this->assertStringEndsWith('UserNormalizer', $fqcnB);
        $this->assertNotSame($fqcnA, $fqcnB, 'Same class name in different namespaces must have different FQCNs');
    }

    public function testWrittenFileContainsGeneratedCode(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(SimpleBlog::class);
        $path = $this->writer->write($metadata);
        $content = file_get_contents($path);

        // Verify the written file contains the same content as generate()
        $generatedCode = $this->generator->generate($metadata);
        $this->assertSame($generatedCode, $content);
    }

    public function testGeneratedCodeContainsArrayMergeForContextAttribute(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(BlogWithContext::class);
        $code = $this->generator->generate($metadata);

        // The createdAt property has an unconditional normalization context
        // It should generate array_merge($context, [...])
        $this->assertStringContainsString('array_merge($context', $code);
    }

    public function testGeneratedCodeContainsContextValuesForContextAttribute(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(BlogWithContext::class);
        $code = $this->generator->generate($metadata);

        // The createdAt property has datetime_format => 'Y-m-d' in its normalization context
        $this->assertStringContainsString("'datetime_format'", $code);
        $this->assertStringContainsString("'Y-m-d'", $code);
    }

    public function testGeneratedCodeContainsGroupCheckForConditionalContext(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(BlogWithContext::class);
        $code = $this->generator->generate($metadata);

        // The scheduledAt property has group-specific contexts
        // It should generate conditional context merging with group checks
        // For example: isset($groupsLookup['blog:read'])
        $this->assertStringContainsString("isset(\$groupsLookup['blog:read'])", $code);
        $this->assertStringContainsString("isset(\$groupsLookup['blog:list'])", $code);
        $this->assertStringContainsString("isset(\$groupsLookup['blog:api'])", $code);
    }

    public function testGeneratedCodeContainsBothConditionalAndUnconditionalContext(): void
    {
        $metadata = $this->generator->getMetadataFactory()->getMetadataFor(BlogWithContext::class);
        $code = $this->generator->generate($metadata);

        // The archivedAt property has both unconditional ('always_applied' => true)
        // and conditional ('only_for_read' => true for 'blog:read' group) contexts
        $this->assertStringContainsString("'always_applied'", $code);
        $this->assertStringContainsString("'only_for_read'", $code);
    }

    public function testGeneratedCodeOmitsContextMergingWhenContextFeatureDisabled(): void
    {
        // Create a generator with context feature disabled
        $generator = new NormalizerGenerator(
            metadataFactory: $this->generator->getMetadataFactory(),
            generatedNamespace: self::GENERATED_NAMESPACE,
            features: [
                'groups' => true,
                'max_depth' => true,
                'circular_reference' => true,
                'skip_null_values' => true,
                'context' => false,
                'strict_types' => true,
            ],
        );

        $metadata = $generator->getMetadataFactory()->getMetadataFor(BlogWithContext::class);
        $code = $generator->generate($metadata);

        // When context feature is disabled, there should be no array_merge for context
        // and no datetime_format context key in the generated code
        $this->assertStringNotContainsString("'datetime_format'", $code);
        $this->assertStringNotContainsString("'always_applied'", $code);
        $this->assertStringNotContainsString("'only_for_read'", $code);
        $this->assertStringNotContainsString('$_context', $code);
    }
}
