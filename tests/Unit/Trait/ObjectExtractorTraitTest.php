<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Unit\Trait;

use PHPUnit\Framework\TestCase;
use RemcoSmitsDev\BuildableSerializerBundle\Exception\TypeMismatchException;
use RemcoSmitsDev\BuildableSerializerBundle\Exception\UnexpectedNullException;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\AddressFixture;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\TagFixture;
use RemcoSmitsDev\BuildableSerializerBundle\Trait\ObjectExtractorTrait;
use Symfony\Component\Serializer\Exception\MissingConstructorArgumentsException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Test host that exposes {@see ObjectExtractorTrait} private helpers as public
 * methods so they can be exercised directly from the unit-test layer.
 *
 * The host also carries the `$denormalizer` property that
 * {@see \Symfony\Component\Serializer\Normalizer\DenormalizerAwareTrait} would
 * normally inject at runtime. In the generated denormalizers the trait and
 * the property live together; here we declare it explicitly so tests can
 * plug in a mock denormalizer without pulling in Symfony's real chain.
 */
final class ObjectExtractorTraitTestHost
{
    use ObjectExtractorTrait {
        extractObject as public;
        extractRequiredObject as public;
        extractArrayOfObjects as public;
        extractNullableArrayOfObjects as public;
        extractMapOfObjects as public;
    }

    public ?DenormalizerInterface $denormalizer = null;
}

/**
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Trait\ObjectExtractorTrait
 */
final class ObjectExtractorTraitTest extends TestCase
{
    private ObjectExtractorTraitTestHost $host;

    /**
     * @var DenormalizerInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private DenormalizerInterface $denormalizer;

    protected function setUp(): void
    {
        $this->host = new ObjectExtractorTraitTestHost();
        $this->denormalizer = $this->createMock(DenormalizerInterface::class);
        $this->host->denormalizer = $this->denormalizer;
    }

    public function testExtractObjectDelegatesToDenormalizer(): void
    {
        $expected = new AddressFixture('Main St', 'Berlin', 'DE');

        $this->denormalizer
            ->expects($this->once())
            ->method('denormalize')
            ->with(['street' => 'Main St', 'city' => 'Berlin', 'country_code' => 'DE'], AddressFixture::class, 'json', [
                'ctx' => 1,
            ])
            ->willReturn($expected);

        $result = $this->host->extractObject(
            data: ['address' => ['street' => 'Main St', 'city' => 'Berlin', 'country_code' => 'DE']],
            key: 'address',
            className: AddressFixture::class,
            required: false,
            default: null,
            format: 'json',
            context: ['ctx' => 1],
        );

        $this->assertSame($expected, $result);
    }

    public function testExtractObjectReturnsDefaultWhenMissingAndNotRequired(): void
    {
        $default = new AddressFixture('Fallback', 'NA', 'NA');

        $this->denormalizer->expects($this->never())->method('denormalize');

        $result = $this->host->extractObject(
            data: [],
            key: 'address',
            className: AddressFixture::class,
            required: false,
            default: $default,
            format: null,
            context: [],
        );

        $this->assertSame($default, $result);
    }

    public function testExtractObjectReturnsNullWhenMissingAndDefaultNull(): void
    {
        $this->denormalizer->expects($this->never())->method('denormalize');

        $result = $this->host->extractObject(
            data: [],
            key: 'address',
            className: AddressFixture::class,
            required: false,
            default: null,
            format: null,
            context: [],
        );

        $this->assertNull($result);
    }

    public function testExtractObjectThrowsOnMissingRequiredField(): void
    {
        $this->expectException(MissingConstructorArgumentsException::class);

        $this->host->extractObject(
            data: [],
            key: 'address',
            className: AddressFixture::class,
            required: true,
            default: null,
            format: null,
            context: [],
        );
    }

    public function testExtractObjectReturnsNullWhenValueIsNull(): void
    {
        // A null value in the payload is always accepted — the caller is
        // responsible for deciding whether that is legal for the target type.
        $this->denormalizer->expects($this->never())->method('denormalize');

        $result = $this->host->extractObject(
            data: ['address' => null],
            key: 'address',
            className: AddressFixture::class,
            required: false,
            default: null,
            format: null,
            context: [],
        );

        $this->assertNull($result);
    }

    public function testExtractObjectShortCircuitsWhenValueIsAlreadyInstance(): void
    {
        $already = new AddressFixture('Already', 'City', 'XX');

        // When the payload already contains an instance of the expected type
        // the denormalizer chain must NOT be invoked — the value is used as-is.
        $this->denormalizer->expects($this->never())->method('denormalize');

        $result = $this->host->extractObject(
            data: ['address' => $already],
            key: 'address',
            className: AddressFixture::class,
            required: false,
            default: null,
            format: null,
            context: [],
        );

        $this->assertSame($already, $result);
    }

    public function testExtractObjectShortCircuitsForSubclassInstance(): void
    {
        // AddressFixture is declared `final`, so we use the non-final
        // TagFixture here to exercise the `is_a($value, $className)`
        // short-circuit branch with an actual subclass instance.
        $already = new class(1, 'existing') extends TagFixture {};

        $this->denormalizer->expects($this->never())->method('denormalize');

        $result = $this->host->extractObject(
            data: ['tag' => $already],
            key: 'tag',
            className: TagFixture::class,
            required: false,
            default: null,
            format: null,
            context: [],
        );

        $this->assertSame($already, $result);
    }

    public function testExtractObjectPassesFormatAndContextToDenormalizer(): void
    {
        $expected = new AddressFixture('S', 'C', 'X');

        $this->denormalizer
            ->expects($this->once())
            ->method('denormalize')
            ->with($this->anything(), AddressFixture::class, 'xml', ['group' => 'read'])
            ->willReturn($expected);

        $this->host->extractObject(
            data: ['address' => ['street' => 'S', 'city' => 'C', 'country_code' => 'X']],
            key: 'address',
            className: AddressFixture::class,
            required: true,
            default: null,
            format: 'xml',
            context: ['group' => 'read'],
        );
    }

    public function testExtractRequiredObjectReturnsDenormalizedValue(): void
    {
        $expected = new AddressFixture('A', 'B', 'C');

        $this->denormalizer->expects($this->once())->method('denormalize')->willReturn($expected);

        $result = $this->host->extractRequiredObject(
            data: ['address' => ['foo' => 'bar']],
            key: 'address',
            className: AddressFixture::class,
            format: null,
            context: [],
        );

        $this->assertSame($expected, $result);
    }

    public function testExtractRequiredObjectThrowsOnMissingField(): void
    {
        $this->expectException(MissingConstructorArgumentsException::class);

        $this->host->extractRequiredObject(
            data: [],
            key: 'address',
            className: AddressFixture::class,
            format: null,
            context: [],
        );
    }

    public function testExtractRequiredObjectThrowsOnNullValue(): void
    {
        $this->expectException(UnexpectedNullException::class);

        $this->host->extractRequiredObject(
            data: ['address' => null],
            key: 'address',
            className: AddressFixture::class,
            format: null,
            context: [],
        );
    }

    public function testExtractRequiredObjectShortCircuitsForInstance(): void
    {
        $already = new AddressFixture('A', 'B', 'C');

        $this->denormalizer->expects($this->never())->method('denormalize');

        $result = $this->host->extractRequiredObject(
            data: ['address' => $already],
            key: 'address',
            className: AddressFixture::class,
            format: null,
            context: [],
        );

        $this->assertSame($already, $result);
    }

    public function testExtractRequiredObjectUnexpectedNullExceptionCarriesClassName(): void
    {
        try {
            $this->host->extractRequiredObject(
                data: ['address' => null],
                key: 'address',
                className: AddressFixture::class,
                format: null,
                context: [],
            );
            $this->fail('Expected UnexpectedNullException.');
        } catch (UnexpectedNullException $e) {
            $this->assertSame('address', $e->getFieldName());
            $this->assertSame(AddressFixture::class, $e->getExpectedType());
        }
    }

    public function testExtractArrayOfObjectsDelegatesEachItem(): void
    {
        $tagA = new TagFixture(1, 'php');
        $tagB = new TagFixture(2, 'symfony');

        $this->denormalizer
            ->expects($this->exactly(2))
            ->method('denormalize')
            ->willReturnOnConsecutiveCalls($tagA, $tagB);

        $result = $this->host->extractArrayOfObjects(
            data: ['tags' => [['id' => 1, 'name' => 'php'], ['id' => 2, 'name' => 'symfony']]],
            key: 'tags',
            className: TagFixture::class,
            required: false,
            default: null,
            format: null,
            context: [],
        );

        $this->assertSame([$tagA, $tagB], $result);
    }

    public function testExtractArrayOfObjectsReturnsEmptyArrayWhenMissingAndNotRequired(): void
    {
        $this->denormalizer->expects($this->never())->method('denormalize');

        $result = $this->host->extractArrayOfObjects(
            data: [],
            key: 'tags',
            className: TagFixture::class,
            required: false,
            default: null,
            format: null,
            context: [],
        );

        $this->assertSame([], $result);
    }

    public function testExtractArrayOfObjectsThrowsOnMissingRequiredField(): void
    {
        $this->expectException(MissingConstructorArgumentsException::class);

        $this->host->extractArrayOfObjects(
            data: [],
            key: 'tags',
            className: TagFixture::class,
            required: true,
            default: null,
            format: null,
            context: [],
        );
    }

    public function testExtractArrayOfObjectsReturnsEmptyArrayWhenValueIsNull(): void
    {
        $this->denormalizer->expects($this->never())->method('denormalize');

        $result = $this->host->extractArrayOfObjects(
            data: ['tags' => null],
            key: 'tags',
            className: TagFixture::class,
            required: false,
            default: null,
            format: null,
            context: [],
        );

        $this->assertSame([], $result);
    }

    public function testExtractArrayOfObjectsThrowsTypeMismatchForScalar(): void
    {
        $this->expectException(TypeMismatchException::class);

        $this->host->extractArrayOfObjects(
            data: ['tags' => 'not-an-array'],
            key: 'tags',
            className: TagFixture::class,
            required: false,
            default: null,
            format: null,
            context: [],
        );
    }

    public function testExtractArrayOfObjectsThrowsTypeMismatchExceptionCarriesExpectedType(): void
    {
        try {
            $this->host->extractArrayOfObjects(
                data: ['tags' => 'foo'],
                key: 'tags',
                className: TagFixture::class,
                required: false,
                default: null,
                format: null,
                context: [],
            );
            $this->fail('Expected TypeMismatchException.');
        } catch (TypeMismatchException $e) {
            $this->assertSame('tags', $e->getFieldName());
            $this->assertStringContainsString('array', $e->getExpectedType());
            $this->assertStringContainsString(TagFixture::class, $e->getExpectedType());
        }
    }

    public function testExtractArrayOfObjectsShortCircuitsForExistingInstances(): void
    {
        $tagA = new TagFixture(1, 'a');
        $tagB = new TagFixture(2, 'b');

        $this->denormalizer->expects($this->never())->method('denormalize');

        $result = $this->host->extractArrayOfObjects(
            data: ['tags' => [$tagA, $tagB]],
            key: 'tags',
            className: TagFixture::class,
            required: false,
            default: null,
            format: null,
            context: [],
        );

        $this->assertSame([$tagA, $tagB], $result);
    }

    public function testExtractArrayOfObjectsMixesInstancesAndArrays(): void
    {
        $existing = new TagFixture(1, 'existing');
        $created = new TagFixture(2, 'created');

        $this->denormalizer
            ->expects($this->once())
            ->method('denormalize')
            ->with(['id' => 2, 'name' => 'created'], TagFixture::class)
            ->willReturn($created);

        $result = $this->host->extractArrayOfObjects(
            data: ['tags' => [$existing, ['id' => 2, 'name' => 'created']]],
            key: 'tags',
            className: TagFixture::class,
            required: false,
            default: null,
            format: null,
            context: [],
        );

        $this->assertSame([$existing, $created], $result);
    }

    public function testExtractArrayOfObjectsPassesContextWithCollectionIndex(): void
    {
        $tag = new TagFixture(1, 'php');

        $this->denormalizer
            ->expects($this->once())
            ->method('denormalize')
            ->with(
                $this->anything(),
                TagFixture::class,
                $this->anything(),
                $this->callback(static function (array $context): bool {
                    // The trait augments the forwarded context with a hint
                    // about which collection index is currently being
                    // denormalized — useful for downstream error messages.
                    return (
                        array_key_exists('_buildable_denormalizer_collection_index', $context)
                        && $context['_buildable_denormalizer_collection_index'] === 0
                    );
                }),
            )
            ->willReturn($tag);

        $this->host->extractArrayOfObjects(
            data: ['tags' => [['id' => 1, 'name' => 'php']]],
            key: 'tags',
            className: TagFixture::class,
            required: false,
            default: null,
            format: null,
            context: [],
        );
    }

    public function testExtractArrayOfObjectsPreservesOriginalContextKeys(): void
    {
        $tag = new TagFixture(1, 'php');

        $this->denormalizer
            ->expects($this->once())
            ->method('denormalize')
            ->with(
                $this->anything(),
                TagFixture::class,
                $this->anything(),
                $this->callback(static fn(array $ctx): bool => ($ctx['groups'] ?? null) === ['read']),
            )
            ->willReturn($tag);

        $this->host->extractArrayOfObjects(
            data: ['tags' => [['id' => 1, 'name' => 'php']]],
            key: 'tags',
            className: TagFixture::class,
            required: false,
            default: null,
            format: null,
            context: ['groups' => ['read']],
        );
    }

    public function testExtractNullableArrayOfObjectsReturnsNullWhenValueIsNull(): void
    {
        $this->denormalizer->expects($this->never())->method('denormalize');

        $result = $this->host->extractNullableArrayOfObjects(
            data: ['tags' => null],
            key: 'tags',
            className: TagFixture::class,
            required: false,
            default: null,
            format: null,
            context: [],
        );

        $this->assertNull($result);
    }

    public function testExtractNullableArrayOfObjectsReturnsNullWhenMissingAndNotRequired(): void
    {
        $this->denormalizer->expects($this->never())->method('denormalize');

        $result = $this->host->extractNullableArrayOfObjects(
            data: [],
            key: 'tags',
            className: TagFixture::class,
            required: false,
            default: null,
            format: null,
            context: [],
        );

        $this->assertNull($result);
    }

    public function testExtractNullableArrayOfObjectsThrowsOnMissingRequiredField(): void
    {
        $this->expectException(MissingConstructorArgumentsException::class);

        $this->host->extractNullableArrayOfObjects(
            data: [],
            key: 'tags',
            className: TagFixture::class,
            required: true,
            default: null,
            format: null,
            context: [],
        );
    }

    public function testExtractNullableArrayOfObjectsReturnsDenormalizedArray(): void
    {
        $tagA = new TagFixture(1, 'a');
        $tagB = new TagFixture(2, 'b');

        $this->denormalizer
            ->expects($this->exactly(2))
            ->method('denormalize')
            ->willReturnOnConsecutiveCalls($tagA, $tagB);

        $result = $this->host->extractNullableArrayOfObjects(
            data: ['tags' => [['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']]],
            key: 'tags',
            className: TagFixture::class,
            required: false,
            default: null,
            format: null,
            context: [],
        );

        $this->assertSame([$tagA, $tagB], $result);
    }

    public function testExtractNullableArrayOfObjectsThrowsTypeMismatchForScalar(): void
    {
        $this->expectException(TypeMismatchException::class);

        $this->host->extractNullableArrayOfObjects(
            data: ['tags' => 'nope'],
            key: 'tags',
            className: TagFixture::class,
            required: false,
            default: null,
            format: null,
            context: [],
        );
    }

    public function testExtractNullableArrayOfObjectsShortCircuitsForExistingInstances(): void
    {
        $tag = new TagFixture(1, 'a');

        $this->denormalizer->expects($this->never())->method('denormalize');

        $result = $this->host->extractNullableArrayOfObjects(
            data: ['tags' => [$tag]],
            key: 'tags',
            className: TagFixture::class,
            required: false,
            default: null,
            format: null,
            context: [],
        );

        $this->assertSame([$tag], $result);
    }

    public function testExtractMapOfObjectsPreservesStringKeys(): void
    {
        $tagA = new TagFixture(1, 'a');
        $tagB = new TagFixture(2, 'b');

        $this->denormalizer
            ->expects($this->exactly(2))
            ->method('denormalize')
            ->willReturnOnConsecutiveCalls($tagA, $tagB);

        $result = $this->host->extractMapOfObjects(
            data: ['tags' => ['first' => ['id' => 1, 'name' => 'a'], 'second' => ['id' => 2, 'name' => 'b']]],
            key: 'tags',
            className: TagFixture::class,
            required: false,
            default: null,
            format: null,
            context: [],
        );

        $this->assertSame(['first' => $tagA, 'second' => $tagB], $result);
    }

    public function testExtractMapOfObjectsCastsIntegerKeysToStrings(): void
    {
        $tag = new TagFixture(1, 'a');

        $this->denormalizer->expects($this->once())->method('denormalize')->willReturn($tag);

        $result = $this->host->extractMapOfObjects(
            data: ['tags' => [5 => ['id' => 1, 'name' => 'a']]],
            key: 'tags',
            className: TagFixture::class,
            required: false,
            default: null,
            format: null,
            context: [],
        );

        $this->assertArrayHasKey('5', $result);
        $this->assertSame($tag, $result['5']);
    }

    public function testExtractMapOfObjectsReturnsEmptyArrayWhenMissingAndNotRequired(): void
    {
        $this->denormalizer->expects($this->never())->method('denormalize');

        $result = $this->host->extractMapOfObjects(
            data: [],
            key: 'tags',
            className: TagFixture::class,
            required: false,
            default: null,
            format: null,
            context: [],
        );

        $this->assertSame([], $result);
    }

    public function testExtractMapOfObjectsThrowsOnMissingRequiredField(): void
    {
        $this->expectException(MissingConstructorArgumentsException::class);

        $this->host->extractMapOfObjects(
            data: [],
            key: 'tags',
            className: TagFixture::class,
            required: true,
            default: null,
            format: null,
            context: [],
        );
    }

    public function testExtractMapOfObjectsReturnsEmptyArrayWhenValueIsNull(): void
    {
        $this->denormalizer->expects($this->never())->method('denormalize');

        $result = $this->host->extractMapOfObjects(
            data: ['tags' => null],
            key: 'tags',
            className: TagFixture::class,
            required: false,
            default: null,
            format: null,
            context: [],
        );

        $this->assertSame([], $result);
    }

    public function testExtractMapOfObjectsThrowsTypeMismatchForScalar(): void
    {
        $this->expectException(TypeMismatchException::class);

        $this->host->extractMapOfObjects(
            data: ['tags' => 'nope'],
            key: 'tags',
            className: TagFixture::class,
            required: false,
            default: null,
            format: null,
            context: [],
        );
    }

    public function testExtractMapOfObjectsShortCircuitsForExistingInstances(): void
    {
        $tag = new TagFixture(1, 'a');

        $this->denormalizer->expects($this->never())->method('denormalize');

        $result = $this->host->extractMapOfObjects(
            data: ['tags' => ['only' => $tag]],
            key: 'tags',
            className: TagFixture::class,
            required: false,
            default: null,
            format: null,
            context: [],
        );

        $this->assertSame(['only' => $tag], $result);
    }

    public function testExtractMapOfObjectsTypeMismatchExceptionCarriesMapTypeDescriptor(): void
    {
        try {
            $this->host->extractMapOfObjects(
                data: ['tags' => 42],
                key: 'tags',
                className: TagFixture::class,
                required: false,
                default: null,
                format: null,
                context: [],
            );
            $this->fail('Expected TypeMismatchException.');
        } catch (TypeMismatchException $e) {
            $this->assertSame('tags', $e->getFieldName());
            $this->assertStringContainsString('array<string,', $e->getExpectedType());
            $this->assertStringContainsString(TagFixture::class, $e->getExpectedType());
        }
    }

    public function testExtractMapOfObjectsPassesContextWithMapKeyHint(): void
    {
        $tag = new TagFixture(1, 'a');

        $this->denormalizer
            ->expects($this->once())
            ->method('denormalize')
            ->with(
                $this->anything(),
                TagFixture::class,
                $this->anything(),
                $this->callback(static function (array $context): bool {
                    return ($context['_buildable_denormalizer_map_key'] ?? null) === 'first';
                }),
            )
            ->willReturn($tag);

        $this->host->extractMapOfObjects(
            data: ['tags' => ['first' => ['id' => 1, 'name' => 'a']]],
            key: 'tags',
            className: TagFixture::class,
            required: false,
            default: null,
            format: null,
            context: [],
        );
    }
}
