<?php

declare(strict_types=1);

namespace Buildable\SerializerBundle\Tests\Integration;

use Buildable\SerializerBundle\Tests\AbstractTestCase;
use Buildable\SerializerBundle\Tests\Fixtures\Model\CircularReference;
use Symfony\Component\Serializer\Exception\CircularReferenceException;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Integration tests for circular reference detection in generated normalizers.
 *
 * Generates a normalizer for CircularReference, wires it up so that recursive
 * calls go back through the generated normalizer itself, and verifies that:
 *   - A circular reference handler is invoked instead of looping infinitely.
 *   - Without a handler, CircularReferenceException is thrown.
 *   - Non-circular graphs still normalise correctly.
 */
final class CircularReferenceTest extends AbstractTestCase
{
    private string $tempDir;

    /** @var string FQCN of the generated CircularReferenceNormalizer */
    private string $normalizerFqcn;

    /** @var object The instantiated generated normalizer */
    private object $normalizer;

    protected function setUp(): void
    {
        $this->tempDir = $this->createTempDir();
        $generator     = $this->makeGenerator($this->tempDir);
        $factory       = $generator->getMetadataFactory();
        $metadata      = $factory->getMetadataFor(CircularReference::class);

        $this->normalizerFqcn = $generator->resolveNormalizerFqcn($metadata);

        if (!class_exists($this->normalizerFqcn, false)) {
            $filePath = $generator->generateAndWrite($metadata);
            require_once $filePath;
        }

        $this->normalizer = new $this->normalizerFqcn();

        // Wire up a delegate that routes CircularReference objects back through
        // the generated normalizer, simulating how Symfony's Serializer would
        // dispatch to the correct normalizer for recursive calls.
        $generatedNormalizer = $this->normalizer;
        $delegate = new class ($generatedNormalizer) implements NormalizerInterface {
            public function __construct(private readonly object $gen) {}

            public function normalize(
                mixed $data,
                ?string $format = null,
                array $context = [],
            ): array|string|int|float|bool|\ArrayObject|null {
                if ($data instanceof CircularReference) {
                    /** @var NormalizerInterface $gen */
                    $gen = $this->gen;
                    return $gen->normalize($data, $format, $context);
                }

                return null;
            }

            public function supportsNormalization(
                mixed $data,
                ?string $format = null,
                array $context = [],
            ): bool {
                return $data instanceof CircularReference;
            }

            public function getSupportedTypes(?string $format): array
            {
                return [CircularReference::class => false];
            }
        };

        $this->normalizer->setNormalizer($delegate);
    }

    protected function tearDown(): void
    {
        $this->removeTempDir($this->tempDir);
    }

    // -------------------------------------------------------------------------
    // Implements NormalizerAwareInterface (required for recursive delegation)
    // -------------------------------------------------------------------------

    public function testNormalizerImplementsNormalizerAwareInterface(): void
    {
        $this->assertInstanceOf(
            \Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface::class,
            $this->normalizer,
        );
    }

    public function testNormalizerImplementsNormalizerInterface(): void
    {
        $this->assertInstanceOf(NormalizerInterface::class, $this->normalizer);
    }

    public function testNormalizerImplementsGeneratedNormalizerInterface(): void
    {
        $this->assertInstanceOf(
            \Buildable\SerializerBundle\Normalizer\GeneratedNormalizerInterface::class,
            $this->normalizer,
        );
    }

    // -------------------------------------------------------------------------
    // Non-circular graph — no guard should fire
    // -------------------------------------------------------------------------

    public function testNormalizeNonCircularObjectReturnsData(): void
    {
        $node   = new CircularReference('standalone');
        $result = $this->normalizer->normalize($node, 'json', []);

        $this->assertIsArray($result);
        $this->assertSame('standalone', $result['name']);
    }

    public function testNormalizeNonCircularObjectWithNullNestedReturnsNull(): void
    {
        $node   = new CircularReference('root');
        $result = $this->normalizer->normalize($node, 'json', []);

        $this->assertArrayHasKey('parent', $result);
        $this->assertNull($result['parent']);

        $this->assertArrayHasKey('child', $result);
        $this->assertNull($result['child']);
    }

    public function testNormalizeLinearChainWithoutCircleDoesNotThrow(): void
    {
        // parent → child (no back-reference)
        $parent = new CircularReference('parent');
        $child  = new CircularReference('child');
        $parent->setChild($child);

        // The mock delegate routes CircularReference back to the generated normalizer.
        // Since child has no further CircularReference fields, recursion terminates.
        $result = $this->normalizer->normalize($parent, 'json', []);

        $this->assertIsArray($result);
        $this->assertSame('parent', $result['name']);
    }

    // -------------------------------------------------------------------------
    // Circular reference with handler — handler is invoked, no exception
    // -------------------------------------------------------------------------

    public function testCircularReferenceHandlerIsInvokedInsteadOfThrowing(): void
    {
        $node = new CircularReference('looping');
        $node->setChild($node); // self-reference

        $handlerCalled = false;
        $context = [
            AbstractNormalizer::CIRCULAR_REFERENCE_HANDLER => function (
                object $obj,
            ) use (&$handlerCalled): array {
                $handlerCalled = true;

                return ['circular' => true, 'name' => ($obj instanceof CircularReference) ? $obj->getName() : '?'];
            },
        ];

        $result = $this->normalizer->normalize($node, 'json', $context);

        $this->assertTrue($handlerCalled, 'The circular reference handler must be called.');
        $this->assertIsArray($result);
    }

    public function testCircularReferenceHandlerReturnValueIsUsedAsNestedValue(): void
    {
        $node = new CircularReference('A');
        $node->setChild($node); // self-reference

        $context = [
            AbstractNormalizer::CIRCULAR_REFERENCE_HANDLER => static fn(object $obj): string => 'CIRCULAR',
        ];

        $result = $this->normalizer->normalize($node, 'json', $context);

        // The child key should contain the handler's return value
        $this->assertSame('CIRCULAR', $result['child']);
    }

    public function testCircularReferenceHandlerReceivesTheCircularObject(): void
    {
        $node = new CircularReference('target-node');
        $node->setChild($node);

        $receivedObject = null;
        $context = [
            AbstractNormalizer::CIRCULAR_REFERENCE_HANDLER => function (object $obj) use (&$receivedObject): string {
                $receivedObject = $obj;
                return 'CIRCULAR';
            },
        ];

        $this->normalizer->normalize($node, 'json', $context);

        $this->assertInstanceOf(CircularReference::class, $receivedObject);
        $this->assertSame('target-node', $receivedObject->getName());
    }

    public function testNormalizeReturnsNameForRootNodeWhenHandlerCatchesCircle(): void
    {
        $node = new CircularReference('root-node');
        $node->setChild($node);

        $context = [
            AbstractNormalizer::CIRCULAR_REFERENCE_HANDLER => static fn(): string => 'CIRCULAR',
        ];

        $result = $this->normalizer->normalize($node, 'json', $context);

        $this->assertSame('root-node', $result['name']);
    }

    // -------------------------------------------------------------------------
    // Circular reference without handler — CircularReferenceException thrown
    // -------------------------------------------------------------------------

    public function testCircularReferenceWithoutHandlerThrowsException(): void
    {
        $node = new CircularReference('looping');
        $node->setChild($node);

        $this->expectException(CircularReferenceException::class);

        $this->normalizer->normalize($node, 'json', []);
    }

    public function testCircularReferenceExceptionMessageContainsClassName(): void
    {
        $node = new CircularReference('looping');
        $node->setChild($node);

        try {
            $this->normalizer->normalize($node, 'json', []);
            $this->fail('Expected CircularReferenceException was not thrown.');
        } catch (CircularReferenceException $e) {
            $this->assertStringContainsString('CircularReference', $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // supportsNormalization
    // -------------------------------------------------------------------------

    public function testSupportsNormalizationReturnsTrueForCircularReference(): void
    {
        $node = new CircularReference('x');

        $this->assertTrue($this->normalizer->supportsNormalization($node));
    }

    public function testSupportsNormalizationReturnsFalseForOtherObject(): void
    {
        $this->assertFalse($this->normalizer->supportsNormalization(new \stdClass()));
    }

    // -------------------------------------------------------------------------
    // getSupportedTypes
    // -------------------------------------------------------------------------

    public function testGetSupportedTypesIncludesCircularReference(): void
    {
        $types = $this->normalizer->getSupportedTypes('json');

        $this->assertArrayHasKey(CircularReference::class, $types);
        $this->assertTrue($types[CircularReference::class]);
    }
}
