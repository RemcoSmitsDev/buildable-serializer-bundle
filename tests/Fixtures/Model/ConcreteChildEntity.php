<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model;

/**
 * Concrete child entity that intentionally omits its own constructor.
 *
 * Because no constructor is declared here, PHP resolves the constructor to the
 * one defined on {@see AbstractBaseEntity}, which uses private promoted
 * parameters ($id, $name). Those promoted properties are physically owned by
 * the *parent* class, so calling ReflectionClass::getProperty() on the child's
 * ReflectionClass for those parameter names would throw:
 *
 *   "Property ConcreteChildEntity::$id does not exist"
 *
 * This fixture is used to verify that MetadataFactory correctly handles this
 * case and discovers all serialisable properties/methods through the
 * inheritance chain:
 *
 *  - $type        → own public property  (PROPERTY accessor)
 *  - getId()      → inherited getter     (METHOD accessor)
 *  - getName()    → inherited getter     (METHOD accessor)
 *  - getType()    → own getter           (METHOD accessor)
 */
class ConcreteChildEntity extends AbstractBaseEntity
{
    public string $type = 'default';

    public function getType(): string
    {
        return $this->type;
    }
}
