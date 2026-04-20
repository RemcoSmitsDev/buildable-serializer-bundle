<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Unit\Generator;

use PHPUnit\Framework\TestCase;
use RemcoSmitsDev\BuildableSerializerBundle\Generator\DenormalizerPathResolver;
use RemcoSmitsDev\BuildableSerializerBundle\Metadata\ClassMetadata;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\NamespaceA\User as UserA;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\NamespaceB\User as UserB;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\PersonFixture;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\SimpleBlog;

/**
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Generator\DenormalizerPathResolver
 */
final class DenormalizerPathResolverTest extends TestCase
{
    private const CACHE_DIR = '/tmp/buildable-serializer-test';
    private const NAMESPACE = 'BuildableTest\\Generated';

    private DenormalizerPathResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new DenormalizerPathResolver(cacheDir: self::CACHE_DIR, generatedNamespace: self::NAMESPACE);
    }

    public function testResolveDenormalizerFqcnStartsWithGeneratedNamespace(): void
    {
        $metadata = $this->makeMetadataFor(SimpleBlog::class);

        $fqcn = $this->resolver->resolveDenormalizerFqcn($metadata);

        $this->assertStringStartsWith(self::NAMESPACE . '\\', $fqcn);
    }

    public function testResolveDenormalizerFqcnEndsWithDenormalizerSuffix(): void
    {
        $metadata = $this->makeMetadataFor(SimpleBlog::class);

        $fqcn = $this->resolver->resolveDenormalizerFqcn($metadata);

        $this->assertStringEndsWith('SimpleBlogDenormalizer', $fqcn);
    }

    public function testResolveDenormalizerFqcnIncludesNamespaceHashPrefix(): void
    {
        $metadata = $this->makeMetadataFor(SimpleBlog::class);

        $fqcn = $this->resolver->resolveDenormalizerFqcn($metadata);

        // The class-name portion of the FQCN must carry an 'N' + 8-hex-char
        // prefix so that short names are globally unique across namespaces.
        $this->assertMatchesRegularExpression('/\\\\N[a-f0-9]{8}_SimpleBlogDenormalizer$/', $fqcn);
    }

    public function testResolveDenormalizerFqcnIsStableAcrossCalls(): void
    {
        $metadata = $this->makeMetadataFor(SimpleBlog::class);

        $first = $this->resolver->resolveDenormalizerFqcn($metadata);
        $second = $this->resolver->resolveDenormalizerFqcn($metadata);

        $this->assertSame($first, $second);
    }

    public function testResolveDenormalizerFqcnDiffersAcrossNamespacesWithSameShortName(): void
    {
        $a = $this->makeMetadataFor(UserA::class);
        $b = $this->makeMetadataFor(UserB::class);

        $fqcnA = $this->resolver->resolveDenormalizerFqcn($a);
        $fqcnB = $this->resolver->resolveDenormalizerFqcn($b);

        // Both classes have short name "User" but live in different namespaces,
        // so the hash prefix MUST distinguish them to avoid classmap collisions.
        $this->assertNotSame($fqcnA, $fqcnB);

        $this->assertStringEndsWith('_UserDenormalizer', $fqcnA);
        $this->assertStringEndsWith('_UserDenormalizer', $fqcnB);
    }

    public function testResolveDenormalizerFqcnShareNamespacePrefixAcrossClassesInSameNamespace(): void
    {
        $blog = $this->makeMetadataFor(SimpleBlog::class);
        $person = $this->makeMetadataFor(PersonFixture::class);

        $blogFqcn = $this->resolver->resolveDenormalizerFqcn($blog);
        $personFqcn = $this->resolver->resolveDenormalizerFqcn($person);

        // SimpleBlog and PersonFixture share the same source namespace
        // (Tests\Fixtures\Model), so the hash prefix portion of the
        // generated FQCN must be identical for both.
        $blogPrefix = $this->extractHashPrefix($blogFqcn, 'SimpleBlogDenormalizer');
        $personPrefix = $this->extractHashPrefix($personFqcn, 'PersonFixtureDenormalizer');

        $this->assertSame($blogPrefix, $personPrefix);
    }

    public function testResolveFilePathStartsWithCacheDir(): void
    {
        $metadata = $this->makeMetadataFor(SimpleBlog::class);

        $path = $this->resolver->resolveFilePath($metadata);

        $this->assertStringStartsWith(self::CACHE_DIR . \DIRECTORY_SEPARATOR, $path);
    }

    public function testResolveFilePathEndsWithPhpExtension(): void
    {
        $metadata = $this->makeMetadataFor(SimpleBlog::class);

        $path = $this->resolver->resolveFilePath($metadata);

        $this->assertStringEndsWith('.php', $path);
    }

    public function testResolveFilePathUsesFlatDirectoryStructure(): void
    {
        $metadata = $this->makeMetadataFor(SimpleBlog::class);

        $path = $this->resolver->resolveFilePath($metadata);
        $relative = substr($path, \strlen(self::CACHE_DIR) + 1);

        // The file must live directly under the cache directory without
        // nested namespace-matching folders; the generated namespace is
        // deliberately flat so the autoloader can rely on a single classmap
        // entry per file.
        $this->assertStringNotContainsString(\DIRECTORY_SEPARATOR, $relative);
    }

    public function testResolveFilePathFilenameMatchesFqcnShortName(): void
    {
        $metadata = $this->makeMetadataFor(SimpleBlog::class);

        $path = $this->resolver->resolveFilePath($metadata);
        $fqcn = $this->resolver->resolveDenormalizerFqcn($metadata);

        $expectedFilename = $this->shortName($fqcn) . '.php';

        $this->assertSame(self::CACHE_DIR . \DIRECTORY_SEPARATOR . $expectedFilename, $path);
    }

    public function testResolveFilePathDiffersAcrossNamespacesWithSameShortName(): void
    {
        $a = $this->makeMetadataFor(UserA::class);
        $b = $this->makeMetadataFor(UserB::class);

        $pathA = $this->resolver->resolveFilePath($a);
        $pathB = $this->resolver->resolveFilePath($b);

        $this->assertNotSame($pathA, $pathB);
    }

    public function testResolveFilePathIsStableAcrossCalls(): void
    {
        $metadata = $this->makeMetadataFor(SimpleBlog::class);

        $first = $this->resolver->resolveFilePath($metadata);
        $second = $this->resolver->resolveFilePath($metadata);

        $this->assertSame($first, $second);
    }

    public function testResolveFilePathStripsTrailingCacheDirSeparator(): void
    {
        // The resolver should tolerate a cache directory that the caller
        // configured with a trailing separator and emit a single separator
        // between the directory and the filename.
        $resolver = new DenormalizerPathResolver(
            cacheDir: self::CACHE_DIR . \DIRECTORY_SEPARATOR,
            generatedNamespace: self::NAMESPACE,
        );

        $metadata = $this->makeMetadataFor(SimpleBlog::class);

        $path = $resolver->resolveFilePath($metadata);

        $this->assertStringStartsWith(self::CACHE_DIR . \DIRECTORY_SEPARATOR, $path);
        $this->assertStringNotContainsString(\DIRECTORY_SEPARATOR . \DIRECTORY_SEPARATOR, $path);
    }

    public function testResolveDenormalizerFqcnStripsTrailingNamespaceSeparator(): void
    {
        $resolver = new DenormalizerPathResolver(cacheDir: self::CACHE_DIR, generatedNamespace: self::NAMESPACE . '\\');

        $metadata = $this->makeMetadataFor(SimpleBlog::class);

        $fqcn = $resolver->resolveDenormalizerFqcn($metadata);

        $this->assertStringStartsWith(self::NAMESPACE . '\\', $fqcn);
        $this->assertStringNotContainsString('\\\\', $fqcn);
    }

    public function testResolveDenormalizerFqcnHandlesGloballyScopedClass(): void
    {
        // A class declared in the global namespace should produce a
        // denormalizer FQCN with no hash prefix at all, since there is
        // nothing to hash.
        $reflectionClass = new \ReflectionClass(\stdClass::class);
        $metadata = new ClassMetadata($reflectionClass, \stdClass::class);

        $fqcn = $this->resolver->resolveDenormalizerFqcn($metadata);

        $this->assertSame(self::NAMESPACE . '\\stdClassDenormalizer', $fqcn);
    }

    public function testResolveFilePathHandlesGloballyScopedClass(): void
    {
        $reflectionClass = new \ReflectionClass(\stdClass::class);
        $metadata = new ClassMetadata($reflectionClass, \stdClass::class);

        $path = $this->resolver->resolveFilePath($metadata);

        $this->assertSame(self::CACHE_DIR . \DIRECTORY_SEPARATOR . 'stdClassDenormalizer.php', $path);
    }

    public function testDifferentCacheDirsProduceDifferentFilePaths(): void
    {
        $resolverA = new DenormalizerPathResolver(cacheDir: '/tmp/a', generatedNamespace: self::NAMESPACE);

        $resolverB = new DenormalizerPathResolver(cacheDir: '/tmp/b', generatedNamespace: self::NAMESPACE);

        $metadata = $this->makeMetadataFor(SimpleBlog::class);

        $this->assertNotSame($resolverA->resolveFilePath($metadata), $resolverB->resolveFilePath($metadata));
    }

    public function testDifferentCacheDirsProduceSameFqcn(): void
    {
        $resolverA = new DenormalizerPathResolver(cacheDir: '/tmp/a', generatedNamespace: self::NAMESPACE);

        $resolverB = new DenormalizerPathResolver(cacheDir: '/tmp/b', generatedNamespace: self::NAMESPACE);

        $metadata = $this->makeMetadataFor(SimpleBlog::class);

        // The FQCN depends only on the generated namespace + source class,
        // so it must be identical regardless of where the file lives on disk.
        $this->assertSame(
            $resolverA->resolveDenormalizerFqcn($metadata),
            $resolverB->resolveDenormalizerFqcn($metadata),
        );
    }

    public function testDifferentNamespacesProduceDifferentFqcns(): void
    {
        $resolverA = new DenormalizerPathResolver(cacheDir: self::CACHE_DIR, generatedNamespace: 'BuildableTest\\A');

        $resolverB = new DenormalizerPathResolver(cacheDir: self::CACHE_DIR, generatedNamespace: 'BuildableTest\\B');

        $metadata = $this->makeMetadataFor(SimpleBlog::class);

        $this->assertNotSame(
            $resolverA->resolveDenormalizerFqcn($metadata),
            $resolverB->resolveDenormalizerFqcn($metadata),
        );
    }

    public function testFqcnAndFilePathAgreeOnShortName(): void
    {
        $metadata = $this->makeMetadataFor(SimpleBlog::class);

        $fqcn = $this->resolver->resolveDenormalizerFqcn($metadata);
        $path = $this->resolver->resolveFilePath($metadata);

        $shortName = $this->shortName($fqcn);
        $filename = basename($path, '.php');

        $this->assertSame($shortName, $filename);
    }

    public function testFqcnContainsDenormalizerNotNormalizer(): void
    {
        // Regression guard: the path resolver must NOT accidentally reuse
        // the "Normalizer" suffix, because the compiler pass relies on the
        // suffix to distinguish the two generated-class families on disk.
        $metadata = $this->makeMetadataFor(SimpleBlog::class);

        $fqcn = $this->resolver->resolveDenormalizerFqcn($metadata);

        $this->assertStringEndsWith('Denormalizer', $fqcn);
        $this->assertStringNotContainsString('Denormalizer.php', $fqcn);
    }

    /**
     * Build a ClassMetadata instance for the given FQCN using only
     * reflection — no properties / attributes are needed because the path
     * resolver depends solely on the class's short name and namespace.
     *
     * @param class-string $fqcn
     */
    private function makeMetadataFor(string $fqcn): ClassMetadata
    {
        $reflectionClass = new \ReflectionClass($fqcn);

        return new ClassMetadata($reflectionClass, $fqcn);
    }

    /**
     * Extract the 'N<hash>_' prefix from a denormalizer FQCN. Used by tests
     * that need to compare only the namespace-derived hash component.
     */
    private function extractHashPrefix(string $fqcn, string $trailingShortName): string
    {
        $shortName = $this->shortName($fqcn);

        return substr($shortName, 0, \strlen($shortName) - \strlen($trailingShortName));
    }

    /**
     * Return the short (unqualified) class name from a fully-qualified name.
     */
    private function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos !== false ? substr($fqcn, $pos + 1) : $fqcn;
    }
}
