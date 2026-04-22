<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Integration;

use RemcoSmitsDev\BuildableSerializerBundle\Tests\AbstractTestCase;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\IgnoredPropertiesFixture;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Integration tests verifying that #[Ignore]-annotated properties and
 * constructor parameters are completely skipped by both the generated
 * normalizer and the generated denormalizer.
 *
 * ### Fixture anatomy ({@see IgnoredPropertiesFixture})
 *
 *   - `$title`         — plain visible promoted constructor parameter (control field).
 *   - `$secret`        — promoted constructor parameter marked #[Ignore] with a
 *                        default of `''`. Must never appear in normalized output
 *                        and must never be read from denormalization input.
 *   - `$internalScore` — public property (populate() phase) marked #[Ignore]
 *                        with a declaration-site default of `0`. Must never
 *                        appear in normalized output and must never be written
 *                        to during denormalization.
 *
 * ### Normalizer coverage
 *
 *   1. The ignored promoted parameter (`$secret`) is absent from the output array.
 *   2. The ignored populate-phase property (`$internalScore`) is absent from the output array.
 *   3. The non-ignored control field (`$title`) is present in the output array.
 *   4. No extra keys beyond the expected visible set appear in the output.
 *
 * ### Denormalizer coverage
 *
 *   5. Supplying `"secret"` in the input payload does NOT populate `$secret`;
 *      the property retains its constructor default (`''`).
 *   6. Supplying `"internalScore"` in the input payload does NOT populate
 *      `$internalScore`; the property retains its declaration-site default (`0`).
 *   7. The control field `$title` is correctly read from the input.
 *   8. The same invariants hold when `OBJECT_TO_POPULATE` is used instead of
 *      constructing a new instance.
 *
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Generator\Normalizer\NormalizerGenerator
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Generator\Denormalizer\DenormalizerGenerator
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Metadata\MetadataFactory
 */
final class IgnoredPropertiesTest extends AbstractTestCase
{
    private string $tempDir;

    /** The instantiated generated normalizer for IgnoredPropertiesFixture. */
    private NormalizerInterface $normalizer;

    /** The instantiated generated denormalizer for IgnoredPropertiesFixture. */
    private DenormalizerInterface $denormalizer;

    protected function setUp(): void
    {
        $this->tempDir = $this->createTempDir();

        // --- Normalizer setup ---
        $writer = $this->makeWriter($this->tempDir);
        $pathResolver = $this->makePathResolver($this->tempDir);
        $generator = $this->makeGenerator();
        $metadata = $generator->getMetadataFactory()->getMetadataFor(IgnoredPropertiesFixture::class);

        $normalizerFqcn = $pathResolver->resolveNormalizerFqcn($metadata);

        if (!class_exists($normalizerFqcn, false)) {
            $filePath = $writer->write($metadata);
            require_once $filePath;
        }

        $this->normalizer = new $normalizerFqcn();

        // --- Denormalizer setup ---
        $this->denormalizer = $this->loadDenormalizerFor(IgnoredPropertiesFixture::class, $this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeTempDir($this->tempDir);
    }

    public function testNormalizerOutputContainsVisibleField(): void
    {
        $object = new IgnoredPropertiesFixture(title: 'Hello');

        $result = $this->normalizer->normalize($object, 'json', []);

        $this->assertArrayHasKey('title', $result);
        $this->assertSame('Hello', $result['title']);
    }

    public function testNormalizerOutputDoesNotContainIgnoredConstructorParameter(): void
    {
        $object = new IgnoredPropertiesFixture(title: 'Hello', secret: 'top-secret');

        $result = $this->normalizer->normalize($object, 'json', []);

        $this->assertArrayNotHasKey('secret', $result);
    }

    public function testNormalizerOutputDoesNotContainIgnoredPopulatePhaseProperty(): void
    {
        $object = new IgnoredPropertiesFixture(title: 'Hello');
        $object->internalScore = 99;

        $result = $this->normalizer->normalize($object, 'json', []);

        $this->assertArrayNotHasKey('internalScore', $result);
    }

    public function testNormalizerOutputContainsOnlyExpectedKeys(): void
    {
        $object = new IgnoredPropertiesFixture(title: 'Hello', secret: 'top-secret');
        $object->internalScore = 42;

        $result = $this->normalizer->normalize($object, 'json', []);

        $this->assertSame(['title'], array_keys($result));
    }

    public function testDenormalizerReadsVisibleConstructorParameter(): void
    {
        /** @var IgnoredPropertiesFixture $result */
        $result = $this->denormalizer->denormalize(['title' => 'Hello'], IgnoredPropertiesFixture::class);

        $this->assertInstanceOf(IgnoredPropertiesFixture::class, $result);
        $this->assertSame('Hello', $result->getTitle());
    }

    public function testDenormalizerDoesNotReadIgnoredConstructorParameterFromInput(): void
    {
        // Even though "secret" is explicitly present in the payload, the
        // generated denormalizer must ignore it and fall back to the
        // constructor default ('').
        /** @var IgnoredPropertiesFixture $result */
        $result = $this->denormalizer->denormalize([
            'title' => 'Hello',
            'secret' => 'should-be-ignored',
        ], IgnoredPropertiesFixture::class);

        $this->assertSame('', $result->getSecret());
    }

    public function testDenormalizerDoesNotWriteIgnoredPopulatePhasePropertyFromInput(): void
    {
        // Even though "internalScore" is explicitly present in the payload,
        // the generated denormalizer must skip it during the populate() phase,
        // leaving the declaration-site default of 0 intact.
        /** @var IgnoredPropertiesFixture $result */
        $result = $this->denormalizer->denormalize([
            'title' => 'Hello',
            'internalScore' => 999,
        ], IgnoredPropertiesFixture::class);

        $this->assertSame(0, $result->getInternalScore());
    }

    public function testDenormalizerIgnoresBothIgnoredFieldsSimultaneously(): void
    {
        /** @var IgnoredPropertiesFixture $result */
        $result = $this->denormalizer->denormalize([
            'title' => 'My Title',
            'secret' => 'should-be-ignored',
            'internalScore' => 777,
        ], IgnoredPropertiesFixture::class);

        $this->assertSame('My Title', $result->getTitle());
        $this->assertSame('', $result->getSecret());
        $this->assertSame(0, $result->getInternalScore());
    }

    public function testDenormalizerDoesNotReadIgnoredConstructorParameterWithObjectToPopulate(): void
    {
        $existing = new IgnoredPropertiesFixture(title: 'Original', secret: 'kept-secret');

        /** @var IgnoredPropertiesFixture $result */
        $result = $this->denormalizer->denormalize(
            ['title' => 'Updated', 'secret' => 'injected-secret'],
            IgnoredPropertiesFixture::class,
            null,
            [\Symfony\Component\Serializer\Normalizer\AbstractNormalizer::OBJECT_TO_POPULATE => $existing],
        );

        // The populate() phase must not overwrite $secret because it is ignored.
        $this->assertSame('kept-secret', $result->getSecret());
    }

    public function testDenormalizerDoesNotWriteIgnoredPopulatePhasePropertyWithObjectToPopulate(): void
    {
        $existing = new IgnoredPropertiesFixture(title: 'Original');
        $existing->internalScore = 55;

        /** @var IgnoredPropertiesFixture $result */
        $result = $this->denormalizer->denormalize(
            ['title' => 'Updated', 'internalScore' => 999],
            IgnoredPropertiesFixture::class,
            null,
            [\Symfony\Component\Serializer\Normalizer\AbstractNormalizer::OBJECT_TO_POPULATE => $existing],
        );

        // The populate() phase must not touch $internalScore because it is ignored.
        $this->assertSame(55, $result->getInternalScore());
    }
}
