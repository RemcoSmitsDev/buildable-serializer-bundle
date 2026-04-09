<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model;

/**
 * Extended child entity that defines its own constructor and calls the parent.
 *
 * Unlike {@see ConcreteChildEntity}, this class declares its own constructor
 * (with non-promoted parameters) and delegates to the parent constructor via
 * parent::__construct(). This means:
 *
 *  - The promoted properties ($id, $name) are still physically owned by
 *    {@see AbstractBaseEntity}, not by this class.
 *  - The child's own constructor parameters ($status) are NOT promoted, so
 *    they must be exposed through a getter method.
 *  - MetadataFactory must discover $id and $name through the inherited public
 *    getter methods (getId(), getName()), not through promoted-param inspection.
 *
 * Expected serialised properties:
 *
 *  - id      → inherited getter getId()      (METHOD accessor)
 *  - name    → inherited getter getName()    (METHOD accessor)
 *  - status  → own getter getStatus()        (METHOD accessor)
 */
class ExtendedChildEntity extends AbstractBaseEntity
{
    private string $status;

    public function __construct(int $id, string $name, string $status = 'active')
    {
        parent::__construct($id, $name);
        $this->status = $status;
    }

    public function getStatus(): string
    {
        return $this->status;
    }
}
