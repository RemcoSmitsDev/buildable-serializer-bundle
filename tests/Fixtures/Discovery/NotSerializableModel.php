<?php

declare(strict_types=1);

namespace BuildableSerializerBundle\Tests\Fixtures\Discovery;

/** Intentionally not annotated with #[Serializable]. */
final class NotSerializableModel
{
    public string $name = '';
}
