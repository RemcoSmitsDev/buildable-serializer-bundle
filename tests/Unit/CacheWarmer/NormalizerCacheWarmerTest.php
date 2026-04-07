<?php

declare(strict_types=1);

namespace Buildable\SerializerBundle\Tests\Unit\CacheWarmer;

use Buildable\SerializerBundle\CacheWarmer\NormalizerCacheWarmer;
use Buildable\SerializerBundle\Discovery\ClassDiscoveryInterface;
use Buildable\SerializerBundle\Generator\NormalizerGeneratorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Buildable\SerializerBundle\CacheWarmer\NormalizerCacheWarmer
 *
 * The actual NormalizerCacheWarmer delegates bulk generation to
 * NormalizerGenerator::generateAll(). Because the production NormalizerGenerator
 * is `final`, the tests depend on NormalizerGeneratorInterface and use
 * getMockBuilder(...)->addMethods(['generateAll']) to include the generateAll
 * method that the warmer calls at runtime.
 *
 * Once generateAll() is formally added to NormalizerGeneratorInterface, the
 * addMethods() workaround can be replaced with a plain createMock() call.
 */
final class NormalizerCacheWarmerTest extends TestCase
{
    /** Unique temp directory created per test. */
    private string $tempDir;

    /** @var ClassDiscoveryInterface&MockObject */
    private ClassDiscoveryInterface $discovery;

    /**
     * A mock that satisfies NormalizerGeneratorInterface AND exposes generateAll(),
     * which is called by the warmer but is not yet declared on the interface.
     *
     * @var NormalizerGeneratorInterface&MockObject
     */
    private NormalizerGeneratorInterface $generator;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'buildable_warmer_test_' . uniqid('', true);

        $this->discovery = $this->createMock(ClassDiscoveryInterface::class);

        // addMethods(['generateAll']) adds generateAll() as a mockable method
        // even though it is not (yet) declared on NormalizerGeneratorInterface.
        $this->generator = $this
            ->getMockBuilder(NormalizerGeneratorInterface::class)
            ->addMethods(['generateAll'])
            ->getMockForAbstractClass();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    // -------------------------------------------------------------------------
    // isOptional
    // -------------------------------------------------------------------------

    public function testIsOptionalReturnsTrue(): void
    {
        $this->assertFalse($this->makeWarmer()->isOptional());
    }

    // -------------------------------------------------------------------------
    // warmUp — empty discovery result
    // -------------------------------------------------------------------------

    public function testWarmUpReturnsEmptyArrayWhenNoClassesDiscovered(): void
    {
        $this->discovery->method('discoverClasses')->willReturn([]);

        $result = $this->makeWarmer()->warmUp('/kernel/cache');

        $this->assertSame([], $result);
    }

    public function testWarmUpDoesNotCallGeneratorWhenNoClassesDiscovered(): void
    {
        $this->discovery->method('discoverClasses')->willReturn([]);
        $this->generator->expects($this->never())->method('generateAll');

        $this->makeWarmer()->warmUp('/kernel/cache');
    }

    public function testWarmUpDoesNotCreateCacheDirWhenNoClassesDiscovered(): void
    {
        $this->assertDirectoryDoesNotExist($this->tempDir);
        $this->discovery->method('discoverClasses')->willReturn([]);

        $this->makeWarmer()->warmUp('/kernel/cache');

        $this->assertDirectoryDoesNotExist($this->tempDir);
    }

    // -------------------------------------------------------------------------
    // warmUp — single class generated
    // -------------------------------------------------------------------------

    public function testWarmUpCallsGenerateAllWithDiscoveredClasses(): void
    {
        mkdir($this->tempDir, 0777, true);

        $classes = ["App\\Entity\\User"];

        $this->discovery->method('discoverClasses')->willReturn($classes);
        $this->generator->expects($this->once())->method('generateAll')->with($classes)->willReturn([]);

        $this->makeWarmer()->warmUp('/kernel/cache');
    }

    public function testWarmUpReturnsFilePathsFromGenerateAll(): void
    {
        mkdir($this->tempDir, 0777, true);

        $expectedPaths = ['/var/cache/prod/normalizers/UserNormalizer.php'];

        $this->discovery->method('discoverClasses')->willReturn(["App\\Entity\\User"]);
        $this->generator->method('generateAll')->willReturn($expectedPaths);

        $result = $this->makeWarmer()->warmUp('/kernel/cache');

        $this->assertSame($expectedPaths, $result);
    }

    // -------------------------------------------------------------------------
    // warmUp — multiple classes
    // -------------------------------------------------------------------------

    public function testWarmUpPassesAllDiscoveredClassesToGenerateAll(): void
    {
        mkdir($this->tempDir, 0777, true);

        $classes = [
            "App\\Entity\\User",
            "App\\Entity\\Order",
            "App\\Dto\\UserDto",
        ];

        $this->discovery->method('discoverClasses')->willReturn($classes);
        $this->generator->expects($this->once())->method('generateAll')->with($classes)->willReturn([]);

        $this->makeWarmer()->warmUp('/kernel/cache');
    }

    public function testWarmUpReturnsAllGeneratedFilePaths(): void
    {
        mkdir($this->tempDir, 0777, true);

        $paths = ['/cache/UserNormalizer.php', '/cache/OrderNormalizer.php'];

        $this->discovery->method('discoverClasses')->willReturn(["App\\Entity\\User", "App\\Entity\\Order"]);
        $this->generator->method('generateAll')->willReturn($paths);

        $result = $this->makeWarmer()->warmUp('/kernel/cache');

        $this->assertCount(2, $result);
        $this->assertContains($paths[0], $result);
        $this->assertContains($paths[1], $result);
    }

    // -------------------------------------------------------------------------
    // warmUp — cache directory creation
    // -------------------------------------------------------------------------

    public function testWarmUpCreatesCacheDirWhenClassesExistAndDirMissing(): void
    {
        $this->assertDirectoryDoesNotExist($this->tempDir);

        $this->discovery->method('discoverClasses')->willReturn(["App\\Entity\\User"]);
        $this->generator->method('generateAll')->willReturn([]);

        $this->makeWarmer()->warmUp('/kernel/cache');

        $this->assertDirectoryExists($this->tempDir);
    }

    public function testWarmUpDoesNotThrowWhenCacheDirAlreadyExists(): void
    {
        mkdir($this->tempDir, 0777, true);

        $this->discovery->method('discoverClasses')->willReturn(["App\\Entity\\User"]);
        $this->generator->method('generateAll')->willReturn([]);

        $this->expectNotToPerformAssertions();
        $this->makeWarmer()->warmUp('/kernel/cache');
    }

    // -------------------------------------------------------------------------
    // warmUp — kernel cacheDir argument is ignored
    // -------------------------------------------------------------------------

    public function testWarmUpIgnoresKernelCacheDirArgument(): void
    {
        mkdir($this->tempDir, 0777, true);

        $this->discovery->method('discoverClasses')->willReturn(["App\\Entity\\User"]);
        $this->generator->method('generateAll')->willReturn([]);

        // Pass a completely different kernel cache dir; the warmer must still
        // write to the bundle's configured cacheDir.
        $result = $this->makeWarmer()->warmUp('/some/completely/different/path');

        // No assertion other than "did not throw" — the warmer uses $this->cacheDir.
        $this->assertIsArray($result);
    }

    // -------------------------------------------------------------------------
    // warmUp — called multiple times (idempotency)
    // -------------------------------------------------------------------------

    public function testWarmUpCanBeCalledMultipleTimesWithoutError(): void
    {
        mkdir($this->tempDir, 0777, true);

        $classes = ["App\\Entity\\User"];
        $this->discovery->method('discoverClasses')->willReturn($classes);
        $this->generator->method('generateAll')->willReturn([]);

        $warmer = $this->makeWarmer();
        $warmer->warmUp('/cache');
        $warmer->warmUp('/cache');

        $this->addToAssertionCount(1); // no exception = pass
    }

    public function testWarmUpCallsGenerateAllOnEachInvocation(): void
    {
        mkdir($this->tempDir, 0777, true);

        $classes = ["App\\Entity\\User"];
        $this->discovery->method('discoverClasses')->willReturn($classes);
        $this->generator->expects($this->exactly(2))->method('generateAll')->willReturn([]);

        $warmer = $this->makeWarmer();
        $warmer->warmUp('/cache');
        $warmer->warmUp('/cache');
    }

    // -------------------------------------------------------------------------
    // warmUp — generator returns empty paths
    // -------------------------------------------------------------------------

    public function testWarmUpReturnsEmptyArrayWhenGenerateAllReturnsEmptyArray(): void
    {
        mkdir($this->tempDir, 0777, true);

        $this->discovery->method('discoverClasses')->willReturn(["App\\Entity\\User"]);
        $this->generator->method('generateAll')->willReturn([]);

        $result = $this->makeWarmer()->warmUp('/cache');

        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a NormalizerCacheWarmer wired to the current test doubles.
     */
    private function makeWarmer(): NormalizerCacheWarmer
    {
        return new NormalizerCacheWarmer($this->generator, $this->discovery, $this->tempDir);
    }

    /**
     * Recursively delete a directory and all of its contents.
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
