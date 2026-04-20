<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Integration;

use RemcoSmitsDev\BuildableSerializerBundle\Denormalizer\GeneratedDenormalizerInterface;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\AbstractTestCase;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\SimpleBlog;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * End-to-end integration tests for the generated denormalizer pipeline.
 *
 * Generates a denormalizer for {@see SimpleBlog}, writes it to disk, requires
 * the file, instantiates the class, and runs `denormalize()` through it.
 *
 * This mirrors {@see GeneratedNormalizerTest} on the denormalizer side and
 * asserts the contract expected by Symfony's serializer chain:
 *   - implements DenormalizerInterface, DenormalizerAwareInterface, and the
 *     bundle's GeneratedDenormalizerInterface marker;
 *   - returns an instance of the target class;
 *   - honours `OBJECT_TO_POPULATE`;
 *   - advertises the correct supported types.
 *
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Generator\DenormalizerGenerator
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Generator\DenormalizerPathResolver
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Generator\DenormalizerWriter
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Trait\GeneratedDenormalizerTrait
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Trait\TypeExtractorTrait
 */
final class GeneratedDenormalizerTest extends AbstractTestCase
{
    private string $tempDir;

    /** The instantiated generated denormalizer for SimpleBlog. */
    private object $denormalizer;

    protected function setUp(): void
    {
        $this->tempDir = $this->createTempDir();
        $this->denormalizer = $this->loadDenormalizerFor(SimpleBlog::class, $this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeTempDir($this->tempDir);
    }

    public function testDenormalizerImplementsDenormalizerInterface(): void
    {
        $this->assertInstanceOf(DenormalizerInterface::class, $this->denormalizer);
    }

    public function testDenormalizerImplementsDenormalizerAwareInterface(): void
    {
        $this->assertInstanceOf(DenormalizerAwareInterface::class, $this->denormalizer);
    }

    public function testDenormalizerImplementsGeneratedDenormalizerInterfaceMarker(): void
    {
        $this->assertInstanceOf(GeneratedDenormalizerInterface::class, $this->denormalizer);
    }

    public function testDenormalizerClassIsFinal(): void
    {
        $reflection = new \ReflectionObject($this->denormalizer);

        $this->assertTrue($reflection->isFinal(), 'Generated denormalizer classes must be declared `final`.');
    }

    public function testDenormalizerClassResidesInConfiguredNamespace(): void
    {
        $reflection = new \ReflectionObject($this->denormalizer);

        $this->assertSame(self::GENERATED_NAMESPACE, $reflection->getNamespaceName());
    }

    public function testDenormalizerClassShortNameFollowsHashPrefixConvention(): void
    {
        $reflection = new \ReflectionObject($this->denormalizer);

        $this->assertMatchesRegularExpression('/^N[a-f0-9]{8}_SimpleBlogDenormalizer$/', $reflection->getShortName());
    }

    public function testSupportsDenormalizationReturnsTrueForTargetClass(): void
    {
        $this->assertTrue($this->denormalizer->supportsDenormalization([], SimpleBlog::class));
    }

    public function testSupportsDenormalizationReturnsFalseForUnrelatedClass(): void
    {
        $this->assertFalse($this->denormalizer->supportsDenormalization([], \stdClass::class));
    }

    public function testSupportsDenormalizationReturnsFalseForEmptyType(): void
    {
        $this->assertFalse($this->denormalizer->supportsDenormalization([], ''));
    }

    public function testSupportsDenormalizationIsUnaffectedByFormat(): void
    {
        $this->assertTrue($this->denormalizer->supportsDenormalization([], SimpleBlog::class, 'json'));
        $this->assertTrue($this->denormalizer->supportsDenormalization([], SimpleBlog::class, 'xml'));
        $this->assertTrue($this->denormalizer->supportsDenormalization([], SimpleBlog::class, null));
    }

    public function testSupportsDenormalizationIsUnaffectedByContext(): void
    {
        $this->assertTrue($this->denormalizer->supportsDenormalization([], SimpleBlog::class, null, ['groups' => [
            'read',
        ]]));
    }

    public function testGetSupportedTypesReturnsTargetClassMappedToTrue(): void
    {
        $this->assertSame([SimpleBlog::class => true], $this->denormalizer->getSupportedTypes(null));
    }

    public function testGetSupportedTypesIsStableAcrossFormats(): void
    {
        $this->assertSame($this->denormalizer->getSupportedTypes(null), $this->denormalizer->getSupportedTypes('json'));
    }

    public function testDenormalizeReturnsInstanceOfTargetClass(): void
    {
        $result = $this->denormalizer->denormalize(['id' => 1, 'title' => 'T', 'content' => 'C'], SimpleBlog::class);

        $this->assertInstanceOf(SimpleBlog::class, $result);
    }

    public function testDenormalizePopulatesAllRequiredFields(): void
    {
        /** @var SimpleBlog $result */
        $result = $this->denormalizer->denormalize([
            'id' => 42,
            'title' => 'Hello',
            'content' => 'World',
        ], SimpleBlog::class);

        $this->assertSame(42, $result->getId());
        $this->assertSame('Hello', $result->getTitle());
        $this->assertSame('World', $result->getContent());
    }

    public function testDenormalizeAppliesNullDefaultForOptionalNullableField(): void
    {
        /** @var SimpleBlog $result */
        $result = $this->denormalizer->denormalize(['id' => 1, 'title' => 'T', 'content' => 'C'], SimpleBlog::class);

        $this->assertNull($result->getExcerpt());
    }

    public function testDenormalizeAcceptsExplicitNullForNullableField(): void
    {
        /** @var SimpleBlog $result */
        $result = $this->denormalizer->denormalize([
            'id' => 1,
            'title' => 'T',
            'content' => 'C',
            'excerpt' => null,
        ], SimpleBlog::class);

        $this->assertNull($result->getExcerpt());
    }

    public function testDenormalizeAcceptsExplicitValueForNullableField(): void
    {
        /** @var SimpleBlog $result */
        $result = $this->denormalizer->denormalize([
            'id' => 1,
            'title' => 'T',
            'content' => 'C',
            'excerpt' => 'Preview',
        ], SimpleBlog::class);

        $this->assertSame('Preview', $result->getExcerpt());
    }

    public function testDenormalizeIgnoresUnknownFields(): void
    {
        // Extra keys that don't correspond to any constructor param or
        // populatable property must be silently ignored — the denormalizer
        // should never choke on a payload with more data than it understands.
        /** @var SimpleBlog $result */
        $result = $this->denormalizer->denormalize([
            'id' => 1,
            'title' => 'T',
            'content' => 'C',
            'unknown' => 'whatever',
            'nested' => ['foo' => 'bar'],
        ], SimpleBlog::class);

        $this->assertInstanceOf(SimpleBlog::class, $result);
        $this->assertSame(1, $result->getId());
    }

    public function testDenormalizeIsFormatAgnostic(): void
    {
        $data = ['id' => 1, 'title' => 'T', 'content' => 'C'];

        $json = $this->denormalizer->denormalize($data, SimpleBlog::class, 'json');
        $xml = $this->denormalizer->denormalize($data, SimpleBlog::class, 'xml');
        $null = $this->denormalizer->denormalize($data, SimpleBlog::class, null);

        foreach ([$json, $xml, $null] as $result) {
            $this->assertInstanceOf(SimpleBlog::class, $result);
            $this->assertSame(1, $result->getId());
        }
    }

    public function testDenormalizeProducesIndependentInstances(): void
    {
        $first = $this->denormalizer->denormalize(['id' => 1, 'title' => 'A', 'content' => 'AA'], SimpleBlog::class);

        $second = $this->denormalizer->denormalize(['id' => 2, 'title' => 'B', 'content' => 'BB'], SimpleBlog::class);

        $this->assertNotSame($first, $second);
        $this->assertSame(1, $first->getId());
        $this->assertSame(2, $second->getId());
    }

    public function testObjectToPopulateIsReturnedWhenSupplied(): void
    {
        $existing = new SimpleBlog(99, 'Existing', 'Body');

        $result = $this->denormalizer->denormalize([], SimpleBlog::class, null, [
            AbstractNormalizer::OBJECT_TO_POPULATE => $existing,
        ]);

        $this->assertSame($existing, $result);
    }

    public function testObjectToPopulateIsPreservedEvenWhenDataIsNonEmpty(): void
    {
        // SimpleBlog has only constructor-populated fields, so populate()
        // is effectively a no-op; the important invariant here is that the
        // exact same instance is returned.
        $existing = new SimpleBlog(99, 'Existing', 'Body');

        $result = $this->denormalizer->denormalize(
            ['id' => 1, 'title' => 'Ignored', 'content' => 'Ignored'],
            SimpleBlog::class,
            null,
            [AbstractNormalizer::OBJECT_TO_POPULATE => $existing],
        );

        $this->assertSame($existing, $result);
    }

    public function testAbsenceOfObjectToPopulateCreatesNewInstance(): void
    {
        $result = $this->denormalizer->denormalize(['id' => 1, 'title' => 'T', 'content' => 'C'], SimpleBlog::class);

        $this->assertInstanceOf(SimpleBlog::class, $result);
    }

    public function testDenormalizeThrowsOnMissingRequiredField(): void
    {
        $this->expectException(\RemcoSmitsDev\BuildableSerializerBundle\Exception\MissingRequiredFieldException::class);

        $this->denormalizer->denormalize(['title' => 'T', 'content' => 'C'], SimpleBlog::class);
    }

    public function testDenormalizeMissingFieldExceptionCarriesFieldName(): void
    {
        try {
            $this->denormalizer->denormalize(['title' => 'T', 'content' => 'C'], SimpleBlog::class);
            $this->fail('Expected MissingRequiredFieldException.');
        } catch (\RemcoSmitsDev\BuildableSerializerBundle\Exception\MissingRequiredFieldException $e) {
            $this->assertSame('id', $e->getFieldName());
        }
    }

    public function testDenormalizeThrowsTypeMismatchForWrongScalarType(): void
    {
        $this->expectException(\RemcoSmitsDev\BuildableSerializerBundle\Exception\TypeMismatchException::class);

        $this->denormalizer->denormalize(['id' => 'not-a-number', 'title' => 'T', 'content' => 'C'], SimpleBlog::class);
    }

    public function testDenormalizeCoercesWrongScalarTypeInLenientMode(): void
    {
        // With DISABLE_TYPE_ENFORCEMENT enabled, a numeric string must be
        // coerced to int rather than rejected.
        $result = $this->denormalizer->denormalize(
            ['id' => '42', 'title' => 'T', 'content' => 'C'],
            SimpleBlog::class,
            null,
            [\Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer::DISABLE_TYPE_ENFORCEMENT => true],
        );

        $this->assertSame(42, $result->getId());
    }

    public function testDenormalizeUnexpectedNullExceptionForNonNullableField(): void
    {
        // `id` is a required, non-nullable int — passing null must surface
        // an UnexpectedNullException (distinct from a plain type mismatch)
        // so callers can distinguish "missing/null" from "wrong type".
        $this->expectException(\RemcoSmitsDev\BuildableSerializerBundle\Exception\UnexpectedNullException::class);

        $this->denormalizer->denormalize(['id' => null, 'title' => 'T', 'content' => 'C'], SimpleBlog::class);
    }

    public function testDenormalizerFileWasWrittenUnderCacheDir(): void
    {
        // loadDenormalizerFor() short-circuits and skips the disk write when
        // the target denormalizer class is already loaded from a previous
        // test in the same PHPUnit process. To assert disk-level invariants
        // reliably we therefore force a fresh write here through the writer.
        $writer = $this->makeDenormalizerWriter($this->tempDir);
        $pathResolver = $this->makeDenormalizerPathResolver($this->tempDir);
        $metadata = $this->makeDenormalizerGenerator()->getMetadataFactory()->getMetadataFor(SimpleBlog::class);

        $writer->write($metadata);
        $expectedPath = $pathResolver->resolveFilePath($metadata);

        $this->assertFileExists($expectedPath);
        $this->assertStringStartsWith($this->tempDir, $expectedPath);
    }

    public function testDenormalizerFileBeginsWithOpeningPhpTag(): void
    {
        $writer = $this->makeDenormalizerWriter($this->tempDir);
        $pathResolver = $this->makeDenormalizerPathResolver($this->tempDir);
        $metadata = $this->makeDenormalizerGenerator()->getMetadataFactory()->getMetadataFor(SimpleBlog::class);

        $writer->write($metadata);
        $content = (string) file_get_contents($pathResolver->resolveFilePath($metadata));

        $this->assertStringStartsWith('<?php', $content);
    }

    public function testDenormalizerFileDeclaresStrictTypes(): void
    {
        $writer = $this->makeDenormalizerWriter($this->tempDir);
        $pathResolver = $this->makeDenormalizerPathResolver($this->tempDir);
        $metadata = $this->makeDenormalizerGenerator()->getMetadataFactory()->getMetadataFor(SimpleBlog::class);

        $writer->write($metadata);
        $content = (string) file_get_contents($pathResolver->resolveFilePath($metadata));

        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    public function testReloadingTheGeneratedFileProducesIdenticalBehaviour(): void
    {
        // Regenerating the denormalizer into a second temp dir must produce
        // equivalent runtime behaviour — this guards against accidental
        // hidden state in the generator.
        $secondTempDir = $this->createTempDir();

        try {
            $second = $this->loadDenormalizerFor(SimpleBlog::class, $secondTempDir);

            $data = ['id' => 1, 'title' => 'T', 'content' => 'C', 'excerpt' => 'E'];

            /** @var SimpleBlog $a */
            $a = $this->denormalizer->denormalize($data, SimpleBlog::class);
            /** @var SimpleBlog $b */
            $b = $second->denormalize($data, SimpleBlog::class);

            $this->assertSame($a->getId(), $b->getId());
            $this->assertSame($a->getTitle(), $b->getTitle());
            $this->assertSame($a->getContent(), $b->getContent());
            $this->assertSame($a->getExcerpt(), $b->getExcerpt());
        } finally {
            $this->removeTempDir($secondTempDir);
        }
    }
}
