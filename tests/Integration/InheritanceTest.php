<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Integration;

use RemcoSmitsDev\BuildableSerializerBundle\Tests\AbstractTestCase;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\ConcreteChildEntity;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\ExtendedChildEntity;

/**
 * Integration tests for class-inheritance scenarios.
 *
 * Verifies that the code generator correctly handles both of the two primary
 * inheritance shapes:
 *
 *  1. {@see ConcreteChildEntity} – child class with NO own constructor.
 *     PHP resolves the constructor to the parent's promoted-parameter
 *     constructor. The promoted properties ($id, $name) physically belong to
 *     the parent's ReflectionClass, so naively calling
 *     ReflectionClass::getProperty() on the child would throw
 *     "Property ConcreteChildEntity::$id does not exist".
 *     MetadataFactory must skip inherited constructors during promoted-param
 *     discovery and instead pick up those properties via the inherited
 *     public getter methods (getId(), getName()).
 *
 *  2. {@see ExtendedChildEntity} – child class that declares its own
 *     constructor (non-promoted parameters) and calls parent::__construct().
 *     Here the child's constructor parameters are NOT promoted, so the
 *     parent's properties are again only reachable through the inherited
 *     public getter methods.
 *
 * Both shapes must produce a fully working generated normalizer that serialises
 * all public data from the entire inheritance chain without errors.
 */
final class InheritanceTest extends AbstractTestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = $this->createTempDir();
    }

    protected function tearDown(): void
    {
        $this->removeTempDir($this->tempDir);
    }

    public function testConcreteChildMetadataContainsInheritedGetterProperties(): void
    {
        $generator = $this->makeGenerator($this->tempDir);
        $metadata = $generator->getMetadataFactory()->getMetadataFor(ConcreteChildEntity::class);

        $propertyNames = array_keys($metadata->getProperties());

        $this->assertContains('id', $propertyNames, 'id (from parent getter) must be discovered');
        $this->assertContains('name', $propertyNames, 'name (from parent getter) must be discovered');
        $this->assertContains('type', $propertyNames, 'type (own public property) must be discovered');
    }

    public function testConcreteChildNormalizerCanBeGenerated(): void
    {
        $generator = $this->makeGenerator($this->tempDir);
        $metadata = $generator->getMetadataFactory()->getMetadataFor(ConcreteChildEntity::class);

        $filePath = $generator->generateAndWrite($metadata);

        $this->assertFileExists($filePath);
    }

    public function testConcreteChildNormalizerNormalizesOwnProperty(): void
    {
        $normalizer = $this->buildNormalizer(ConcreteChildEntity::class);

        $obj = new ConcreteChildEntity(1, 'Alice');
        $obj->type = 'premium';
        $result = $normalizer->normalize($obj, 'json', []);

        $this->assertIsArray($result);
        $this->assertSame('premium', $result['type']);
    }

    public function testConcreteChildNormalizerNormalizesInheritedIdViaGetter(): void
    {
        $normalizer = $this->buildNormalizer(ConcreteChildEntity::class);

        $obj = new ConcreteChildEntity(42, 'Bob');
        $result = $normalizer->normalize($obj, 'json', []);

        $this->assertArrayHasKey('id', $result);
        $this->assertSame(42, $result['id']);
    }

    public function testConcreteChildNormalizerNormalizesInheritedNameViaGetter(): void
    {
        $normalizer = $this->buildNormalizer(ConcreteChildEntity::class);

        $obj = new ConcreteChildEntity(1, 'Charlie');
        $result = $normalizer->normalize($obj, 'json', []);

        $this->assertArrayHasKey('name', $result);
        $this->assertSame('Charlie', $result['name']);
    }

    public function testConcreteChildNormalizerNormalizesOwnGetter(): void
    {
        $normalizer = $this->buildNormalizer(ConcreteChildEntity::class);

        $obj = new ConcreteChildEntity(1, 'Dave');
        $obj->type = 'basic';
        $result = $normalizer->normalize($obj, 'json', []);

        // getType() is an own getter that mirrors the public property; both
        // 'type' (PROPERTY) and 'type' (METHOD) map to the same name so only
        // one entry should appear – whichever was registered first.
        $this->assertArrayHasKey('type', $result);
        $this->assertSame('basic', $result['type']);
    }

    public function testConcreteChildNormalizerProducesCompleteOutput(): void
    {
        $normalizer = $this->buildNormalizer(ConcreteChildEntity::class);

        $obj = new ConcreteChildEntity(7, 'Eve');
        $obj->type = 'vip';
        $result = $normalizer->normalize($obj, 'json', []);

        $this->assertSame(7, $result['id']);
        $this->assertSame('Eve', $result['name']);
        $this->assertSame('vip', $result['type']);
    }

    public function testConcreteChildSupportsNormalizationForOwnClass(): void
    {
        $normalizer = $this->buildNormalizer(ConcreteChildEntity::class);

        $obj = new ConcreteChildEntity(1, 'Test');

        $this->assertTrue($normalizer->supportsNormalization($obj));
    }

    public function testConcreteChildSupportsNormalizationReturnsFalseForOtherObjects(): void
    {
        $normalizer = $this->buildNormalizer(ConcreteChildEntity::class);

        $this->assertFalse($normalizer->supportsNormalization(new \stdClass()));
    }

    public function testConcreteChildGetSupportedTypesIncludesClass(): void
    {
        $normalizer = $this->buildNormalizer(ConcreteChildEntity::class);
        $types = $normalizer->getSupportedTypes('json');

        $this->assertArrayHasKey(ConcreteChildEntity::class, $types);
        $this->assertTrue($types[ConcreteChildEntity::class]);
    }

    public function testConcreteChildNormalizerImplementsGeneratedInterface(): void
    {
        $normalizer = $this->buildNormalizer(ConcreteChildEntity::class);

        $this->assertInstanceOf(
            \RemcoSmitsDev\BuildableSerializerBundle\Normalizer\GeneratedNormalizerInterface::class,
            $normalizer,
        );
    }

    public function testExtendedChildMetadataContainsInheritedGetterProperties(): void
    {
        $generator = $this->makeGenerator($this->tempDir);
        $metadata = $generator->getMetadataFactory()->getMetadataFor(ExtendedChildEntity::class);

        $propertyNames = array_keys($metadata->getProperties());

        $this->assertContains('id', $propertyNames, 'id (from parent getter) must be discovered');
        $this->assertContains('name', $propertyNames, 'name (from parent getter) must be discovered');
        $this->assertContains('status', $propertyNames, 'status (own getter) must be discovered');
    }

    public function testExtendedChildNormalizerCanBeGenerated(): void
    {
        $generator = $this->makeGenerator($this->tempDir);
        $metadata = $generator->getMetadataFactory()->getMetadataFor(ExtendedChildEntity::class);

        $filePath = $generator->generateAndWrite($metadata);

        $this->assertFileExists($filePath);
    }

    public function testExtendedChildNormalizerNormalizesInheritedIdViaGetter(): void
    {
        $normalizer = $this->buildNormalizer(ExtendedChildEntity::class);

        $obj = new ExtendedChildEntity(10, 'Frank', 'active');
        $result = $normalizer->normalize($obj, 'json', []);

        $this->assertArrayHasKey('id', $result);
        $this->assertSame(10, $result['id']);
    }

    public function testExtendedChildNormalizerNormalizesInheritedNameViaGetter(): void
    {
        $normalizer = $this->buildNormalizer(ExtendedChildEntity::class);

        $obj = new ExtendedChildEntity(1, 'Grace', 'inactive');
        $result = $normalizer->normalize($obj, 'json', []);

        $this->assertArrayHasKey('name', $result);
        $this->assertSame('Grace', $result['name']);
    }

    public function testExtendedChildNormalizerNormalizesOwnStatusGetter(): void
    {
        $normalizer = $this->buildNormalizer(ExtendedChildEntity::class);

        $obj = new ExtendedChildEntity(1, 'Hank', 'suspended');
        $result = $normalizer->normalize($obj, 'json', []);

        $this->assertArrayHasKey('status', $result);
        $this->assertSame('suspended', $result['status']);
    }

    public function testExtendedChildNormalizerProducesCompleteOutput(): void
    {
        $normalizer = $this->buildNormalizer(ExtendedChildEntity::class);

        $obj = new ExtendedChildEntity(99, 'Ivy', 'active');
        $result = $normalizer->normalize($obj, 'json', []);

        $this->assertSame(99, $result['id']);
        $this->assertSame('Ivy', $result['name']);
        $this->assertSame('active', $result['status']);
    }

    public function testExtendedChildNormalizerDefaultStatusIsActive(): void
    {
        $normalizer = $this->buildNormalizer(ExtendedChildEntity::class);

        $obj = new ExtendedChildEntity(1, 'Jack');
        $result = $normalizer->normalize($obj, 'json', []);

        $this->assertSame('active', $result['status']);
    }

    public function testExtendedChildSupportsNormalizationForOwnClass(): void
    {
        $normalizer = $this->buildNormalizer(ExtendedChildEntity::class);

        $obj = new ExtendedChildEntity(1, 'Test');

        $this->assertTrue($normalizer->supportsNormalization($obj));
    }

    public function testExtendedChildSupportsNormalizationReturnsFalseForOtherObjects(): void
    {
        $normalizer = $this->buildNormalizer(ExtendedChildEntity::class);

        $this->assertFalse($normalizer->supportsNormalization(new \stdClass()));
    }

    public function testExtendedChildGetSupportedTypesIncludesClass(): void
    {
        $normalizer = $this->buildNormalizer(ExtendedChildEntity::class);
        $types = $normalizer->getSupportedTypes('json');

        $this->assertArrayHasKey(ExtendedChildEntity::class, $types);
        $this->assertTrue($types[ExtendedChildEntity::class]);
    }

    public function testExtendedChildNormalizerImplementsGeneratedInterface(): void
    {
        $normalizer = $this->buildNormalizer(ExtendedChildEntity::class);

        $this->assertInstanceOf(
            \RemcoSmitsDev\BuildableSerializerBundle\Normalizer\GeneratedNormalizerInterface::class,
            $normalizer,
        );
    }

    /**
     * Generate, require, and instantiate the normalizer for the given class.
     *
     * Uses a unique class-name suffix per target class so that multiple
     * normalizers can coexist in the same PHP process without conflicts.
     *
     * @param class-string $targetClass
     */
    private function buildNormalizer(string $targetClass): object
    {
        $generator = $this->makeGenerator($this->tempDir);
        $metadata = $generator->getMetadataFactory()->getMetadataFor($targetClass);
        $fqcn = $generator->resolveNormalizerFqcn($metadata);

        if (!class_exists($fqcn, false)) {
            $filePath = $generator->generateAndWrite($metadata);
            require_once $filePath;
        }

        return new $fqcn();
    }
}
