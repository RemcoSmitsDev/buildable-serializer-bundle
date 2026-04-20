<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Unit\CacheWarmer;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RemcoSmitsDev\BuildableSerializerBundle\CacheWarmer\NormalizerCacheWarmer;
use RemcoSmitsDev\BuildableSerializerBundle\Discovery\ClassDiscoveryInterface;
use RemcoSmitsDev\BuildableSerializerBundle\Generator\Normalizer\NormalizerWriterInterface;

/**
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\CacheWarmer\NormalizerCacheWarmer
 *
 * The actual NormalizerCacheWarmer delegates bulk generation to
 * NormalizerWriterInterface::writeAll().
 */
final class NormalizerCacheWarmerTest extends TestCase
{
    /** Unique temp directory created per test. */
    private string $tempDir;

    /** @var ClassDiscoveryInterface&MockObject */
    private ClassDiscoveryInterface $discovery;

    /** @var NormalizerWriterInterface&MockObject */
    private NormalizerWriterInterface $writer;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'buildable_warmer_test_' . uniqid('', true);

        $this->discovery = $this->createMock(ClassDiscoveryInterface::class);
        $this->writer = $this->createMock(NormalizerWriterInterface::class);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testIsOptionalReturnsFalse(): void
    {
        $this->assertFalse($this->makeWarmer()->isOptional());
    }

    public function testWarmUpReturnsEmptyArrayWhenNoClassesDiscovered(): void
    {
        $this->discovery->method('discoverClasses')->willReturn([]);

        $result = $this->makeWarmer()->warmUp('/kernel/cache');

        $this->assertSame([], $result);
    }

    public function testWarmUpDoesNotCallWriterWhenNoClassesDiscovered(): void
    {
        $this->discovery->method('discoverClasses')->willReturn([]);
        $this->writer->expects($this->never())->method('writeAll');

        $this->makeWarmer()->warmUp('/kernel/cache');
    }

    public function testWarmUpCallsWriteAllWithDiscoveredClasses(): void
    {
        $classes = ['App\\Entity\\User'];

        $this->discovery->method('discoverClasses')->willReturn($classes);
        $this->writer->expects($this->once())->method('writeAll')->with($classes)->willReturn([]);

        $this->makeWarmer()->warmUp('/kernel/cache');
    }

    public function testWarmUpReturnsFilePathsFromWriteAll(): void
    {
        $expectedPaths = ['/var/cache/prod/normalizers/UserNormalizer.php'];

        $this->discovery->method('discoverClasses')->willReturn(['App\\Entity\\User']);
        $this->writer->method('writeAll')->willReturn($expectedPaths);

        $result = $this->makeWarmer()->warmUp('/kernel/cache');

        $this->assertSame($expectedPaths, $result);
    }

    public function testWarmUpPassesAllDiscoveredClassesToWriteAll(): void
    {
        $classes = [
            'App\\Entity\\User',
            'App\\Entity\\Order',
            'App\\Dto\\UserDto',
        ];

        $this->discovery->method('discoverClasses')->willReturn($classes);
        $this->writer->expects($this->once())->method('writeAll')->with($classes)->willReturn([]);

        $this->makeWarmer()->warmUp('/kernel/cache');
    }

    public function testWarmUpReturnsAllGeneratedFilePaths(): void
    {
        $paths = ['/cache/UserNormalizer.php', '/cache/OrderNormalizer.php'];

        $this->discovery->method('discoverClasses')->willReturn(['App\\Entity\\User', 'App\\Entity\\Order']);
        $this->writer->method('writeAll')->willReturn($paths);

        $result = $this->makeWarmer()->warmUp('/kernel/cache');

        $this->assertCount(2, $result);
        $this->assertContains($paths[0], $result);
        $this->assertContains($paths[1], $result);
    }

    public function testWarmUpCanBeCalledMultipleTimesWithoutError(): void
    {
        $classes = ['App\\Entity\\User'];
        $this->discovery->method('discoverClasses')->willReturn($classes);
        $this->writer->method('writeAll')->willReturn([]);

        $warmer = $this->makeWarmer();
        $warmer->warmUp('/cache');
        $warmer->warmUp('/cache');

        $this->addToAssertionCount(1); // no exception = pass
    }

    public function testWarmUpCallsWriteAllOnEachInvocation(): void
    {
        $classes = ['App\\Entity\\User'];
        $this->discovery->method('discoverClasses')->willReturn($classes);
        $this->writer->expects($this->exactly(2))->method('writeAll')->willReturn([]);

        $warmer = $this->makeWarmer();
        $warmer->warmUp('/cache');
        $warmer->warmUp('/cache');
    }

    public function testWarmUpReturnsEmptyArrayWhenWriteAllReturnsEmptyArray(): void
    {
        $this->discovery->method('discoverClasses')->willReturn(['App\\Entity\\User']);
        $this->writer->method('writeAll')->willReturn([]);

        $result = $this->makeWarmer()->warmUp('/cache');

        $this->assertSame([], $result);
    }

    public function testWarmUpIgnoresKernelCacheDirArgument(): void
    {
        $this->discovery->method('discoverClasses')->willReturn(['App\\Entity\\User']);
        $this->writer->method('writeAll')->willReturn([]);

        // Pass a completely different kernel cache dir; the warmer must still
        // delegate to the writer which uses its own configured cacheDir.
        $result = $this->makeWarmer()->warmUp('/some/completely/different/path');

        // No assertion other than "did not throw" — the warmer delegates to the writer.
        $this->assertIsArray($result);
    }

    /**
     * Build a NormalizerCacheWarmer wired to the current test doubles.
     */
    private function makeWarmer(): NormalizerCacheWarmer
    {
        return new NormalizerCacheWarmer($this->writer, $this->discovery);
    }

    /**
     * Recursively delete a directory and all of its contents.
     */
    private function removeDirectory(string $dir): void
    {
        if (is_dir($dir) === false) {
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
