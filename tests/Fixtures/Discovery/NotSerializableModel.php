<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Discovery;

/** Concrete class in the scanned tree — discovered by path/namespace only. */
final class NotSerializableModel
{
    public string $name = '';
}
