<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Unit\Generator;

use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\PhpVersion;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;
use PHPUnit\Framework\TestCase;
use RemcoSmitsDev\BuildableSerializerBundle\Generator\DefaultValueBuilder;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\StatusFixture;

/**
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Generator\DefaultValueBuilder
 */
final class DefaultValueBuilderTest extends TestCase
{
    private DefaultValueBuilder $builder;
    private PrettyPrinter $printer;

    protected function setUp(): void
    {
        $this->builder = new DefaultValueBuilder();
        $this->printer = new PrettyPrinter([
            'shortArraySyntax' => true,
            'phpVersion' => PhpVersion::fromComponents(8, 1),
        ]);
    }

    public function testBuildNullReturnsConstFetch(): void
    {
        $node = $this->builder->build(null);

        $this->assertInstanceOf(ConstFetch::class, $node);
        $this->assertSame('null', $node->name->toString());
        $this->assertSame('null', $this->printExpr($node));
    }

    public function testBuildTrueReturnsConstFetch(): void
    {
        $node = $this->builder->build(true);

        $this->assertInstanceOf(ConstFetch::class, $node);
        $this->assertSame('true', $node->name->toString());
        $this->assertSame('true', $this->printExpr($node));
    }

    public function testBuildFalseReturnsConstFetch(): void
    {
        $node = $this->builder->build(false);

        $this->assertInstanceOf(ConstFetch::class, $node);
        $this->assertSame('false', $node->name->toString());
        $this->assertSame('false', $this->printExpr($node));
    }

    public function testBuildPositiveInt(): void
    {
        $node = $this->builder->build(42);

        $this->assertInstanceOf(Int_::class, $node);
        $this->assertSame(42, $node->value);
        $this->assertSame('42', $this->printExpr($node));
    }

    public function testBuildNegativeInt(): void
    {
        $node = $this->builder->build(-7);

        $this->assertInstanceOf(Int_::class, $node);
        $this->assertSame(-7, $node->value);
    }

    public function testBuildZeroInt(): void
    {
        $node = $this->builder->build(0);

        $this->assertInstanceOf(Int_::class, $node);
        $this->assertSame(0, $node->value);
        $this->assertSame('0', $this->printExpr($node));
    }

    public function testBuildFloat(): void
    {
        $node = $this->builder->build(1.5);

        $this->assertInstanceOf(Float_::class, $node);
        $this->assertSame(1.5, $node->value);
    }

    public function testBuildFloatPreservesValue(): void
    {
        $node = $this->builder->build(3.14159);

        $this->assertInstanceOf(Float_::class, $node);
        $this->assertSame(3.14159, $node->value);
    }

    public function testBuildEmptyString(): void
    {
        $node = $this->builder->build('');

        $this->assertInstanceOf(String_::class, $node);
        $this->assertSame('', $node->value);
        $this->assertSame("''", $this->printExpr($node));
    }

    public function testBuildSimpleString(): void
    {
        $node = $this->builder->build('hello');

        $this->assertInstanceOf(String_::class, $node);
        $this->assertSame('hello', $node->value);
        $this->assertSame("'hello'", $this->printExpr($node));
    }

    public function testBuildStringPreservesSpecialCharacters(): void
    {
        $value = "line1\nline2";
        $node = $this->builder->build($value);

        $this->assertInstanceOf(String_::class, $node);
        $this->assertSame($value, $node->value);

        // The pretty-printed form must round-trip back to the original value
        // via PHP's own parser — we don't care about the exact quoting style
        // chosen by the printer (single- vs double-quoted, escaped vs literal).
        $printed = $this->printExpr($node);
        /** @var string $roundTripped */
        $roundTripped = eval('return ' . $printed . ';');
        $this->assertSame($value, $roundTripped);
    }

    public function testBuildStringWithQuotesRoundTrips(): void
    {
        $value = "it's a \"test\" string";
        $node = $this->builder->build($value);

        $this->assertSame($value, $node->value);

        $printed = $this->printExpr($node);
        /** @var string $roundTripped */
        $roundTripped = eval('return ' . $printed . ';');
        $this->assertSame($value, $roundTripped);
    }

    public function testBuildEmptyArrayUsesShortSyntax(): void
    {
        $node = $this->builder->build([]);

        $this->assertInstanceOf(Array_::class, $node);
        $this->assertSame([], $node->items);
        $this->assertSame('[]', $this->printExpr($node));
    }

    public function testBuildListArrayOmitsIntegerKeys(): void
    {
        $node = $this->builder->build(['a', 'b', 'c']);

        $this->assertInstanceOf(Array_::class, $node);
        $this->assertCount(3, $node->items);

        foreach ($node->items as $item) {
            $this->assertNull($item->key, 'List items should not carry explicit integer keys.');
        }

        $this->assertSame("['a', 'b', 'c']", $this->printExpr($node));
    }

    public function testBuildAssociativeArrayPreservesStringKeys(): void
    {
        $node = $this->builder->build(['name' => 'Alice', 'age' => 30]);

        $this->assertInstanceOf(Array_::class, $node);
        $this->assertCount(2, $node->items);

        $this->assertInstanceOf(String_::class, $node->items[0]->key);
        $this->assertSame('name', $node->items[0]->key->value);
        $this->assertInstanceOf(String_::class, $node->items[1]->key);
        $this->assertSame('age', $node->items[1]->key->value);

        $this->assertSame("['name' => 'Alice', 'age' => 30]", $this->printExpr($node));
    }

    public function testBuildSparseIntegerKeyedArrayIsNotTreatedAsList(): void
    {
        // Sparse or non-zero-based integer keys are not a list, so the builder
        // must emit explicit integer keys.
        $node = $this->builder->build([5 => 'a', 10 => 'b']);

        $this->assertInstanceOf(Array_::class, $node);
        $this->assertInstanceOf(Int_::class, $node->items[0]->key);
        $this->assertSame(5, $node->items[0]->key->value);
        $this->assertInstanceOf(Int_::class, $node->items[1]->key);
        $this->assertSame(10, $node->items[1]->key->value);
    }

    public function testBuildNestedArray(): void
    {
        $node = $this->builder->build([
            'inner' => ['a', 'b'],
            'scalar' => 1,
        ]);

        $this->assertInstanceOf(Array_::class, $node);
        $this->assertCount(2, $node->items);

        $this->assertInstanceOf(Array_::class, $node->items[0]->value);
        $this->assertInstanceOf(Int_::class, $node->items[1]->value);

        $this->assertSame("['inner' => ['a', 'b'], 'scalar' => 1]", $this->printExpr($node));
    }

    public function testBuildDeeplyNestedArray(): void
    {
        $value = [
            'level1' => [
                'level2' => [
                    'level3' => [1, 2, 3],
                ],
            ],
        ];

        $node = $this->builder->build($value);

        $printed = $this->printExpr($node);

        $this->assertStringContainsString("'level1'", $printed);
        $this->assertStringContainsString("'level2'", $printed);
        $this->assertStringContainsString("'level3'", $printed);
        $this->assertStringContainsString('[1, 2, 3]', $printed);
    }

    public function testBuildArrayWithMixedValueTypes(): void
    {
        $node = $this->builder->build([
            'int' => 1,
            'float' => 2.5,
            'string' => 'foo',
            'bool' => true,
            'null' => null,
        ]);

        $printed = $this->printExpr($node);

        $this->assertStringContainsString("'int' => 1", $printed);
        $this->assertStringContainsString("'float' => 2.5", $printed);
        $this->assertStringContainsString("'string' => 'foo'", $printed);
        $this->assertStringContainsString("'bool' => true", $printed);
        $this->assertStringContainsString("'null' => null", $printed);
    }

    public function testBuildBackedEnumCaseProducesClassConstFetch(): void
    {
        $node = $this->builder->build(StatusFixture::PENDING);

        $this->assertInstanceOf(ClassConstFetch::class, $node);
        $this->assertInstanceOf(FullyQualified::class, $node->class);
        $this->assertSame(StatusFixture::class, $node->class->toString());

        $this->assertSame('PENDING', (string) $node->name);
    }

    public function testBuildEnumCasePrintsAsFullyQualifiedReference(): void
    {
        $node = $this->builder->build(StatusFixture::ACTIVE);

        $printed = $this->printExpr($node);

        // The FullyQualified class name must be emitted with a leading backslash
        // so the generated code is namespace-independent.
        $this->assertStringStartsWith('\\' . StatusFixture::class, $printed);
        $this->assertStringEndsWith('::ACTIVE', $printed);
    }

    public function testBuildDifferentEnumCasesProduceDifferentNames(): void
    {
        $pending = $this->builder->build(StatusFixture::PENDING);
        $active = $this->builder->build(StatusFixture::ACTIVE);
        $archived = $this->builder->build(StatusFixture::ARCHIVED);

        $this->assertInstanceOf(ClassConstFetch::class, $pending);
        $this->assertInstanceOf(ClassConstFetch::class, $active);
        $this->assertInstanceOf(ClassConstFetch::class, $archived);

        $this->assertSame('PENDING', (string) $pending->name);
        $this->assertSame('ACTIVE', (string) $active->name);
        $this->assertSame('ARCHIVED', (string) $archived->name);
    }

    public function testBuildThrowsLogicExceptionForObject(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot build AST node for default value of type');

        $this->builder->build(new \stdClass());
    }

    public function testBuildThrowsLogicExceptionForClosure(): void
    {
        $this->expectException(\LogicException::class);

        $this->builder->build(static fn(): int => 1);
    }

    public function testBuildThrowsLogicExceptionForDateTime(): void
    {
        $this->expectException(\LogicException::class);

        $this->builder->build(new \DateTimeImmutable());
    }

    public function testBuildExceptionMessageMentionsActualType(): void
    {
        try {
            $this->builder->build(new \stdClass());
            $this->fail('Expected LogicException to be thrown.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('stdClass', $e->getMessage());
        }
    }

    public function testBuildExceptionMessageListsSupportedTypes(): void
    {
        try {
            $this->builder->build(new \stdClass());
            $this->fail('Expected LogicException to be thrown.');
        } catch (\LogicException $e) {
            // The exception is the user's primary feedback channel when they
            // hit an unsupported default value; it must enumerate the
            // supported types so they can adapt their model.
            $this->assertStringContainsString('null', $e->getMessage());
            $this->assertStringContainsString('bool', $e->getMessage());
            $this->assertStringContainsString('int', $e->getMessage());
            $this->assertStringContainsString('float', $e->getMessage());
            $this->assertStringContainsString('string', $e->getMessage());
            $this->assertStringContainsString('array', $e->getMessage());
        }
    }

    public function testSupportsReturnsTrueForScalars(): void
    {
        $this->assertTrue($this->builder->supports(null));
        $this->assertTrue($this->builder->supports(true));
        $this->assertTrue($this->builder->supports(false));
        $this->assertTrue($this->builder->supports(0));
        $this->assertTrue($this->builder->supports(42));
        $this->assertTrue($this->builder->supports(-1));
        $this->assertTrue($this->builder->supports(0.0));
        $this->assertTrue($this->builder->supports(1.5));
        $this->assertTrue($this->builder->supports(''));
        $this->assertTrue($this->builder->supports('hello'));
    }

    public function testSupportsReturnsTrueForEnum(): void
    {
        $this->assertTrue($this->builder->supports(StatusFixture::PENDING));
    }

    public function testSupportsReturnsTrueForEmptyArray(): void
    {
        $this->assertTrue($this->builder->supports([]));
    }

    public function testSupportsReturnsTrueForArrayOfScalars(): void
    {
        $this->assertTrue($this->builder->supports([1, 2, 3]));
        $this->assertTrue($this->builder->supports(['a' => 1, 'b' => 'two']));
    }

    public function testSupportsReturnsTrueForNestedArrayOfSupportedValues(): void
    {
        $this->assertTrue($this->builder->supports([
            'list' => [1, 2, 3],
            'map' => ['a' => 'x'],
            'enum' => StatusFixture::ACTIVE,
        ]));
    }

    public function testSupportsReturnsFalseForObject(): void
    {
        $this->assertFalse($this->builder->supports(new \stdClass()));
    }

    public function testSupportsReturnsFalseForClosure(): void
    {
        $this->assertFalse($this->builder->supports(static fn(): int => 1));
    }

    public function testSupportsReturnsFalseForDateTime(): void
    {
        $this->assertFalse($this->builder->supports(new \DateTimeImmutable()));
    }

    public function testSupportsReturnsFalseForArrayContainingUnsupportedValue(): void
    {
        $this->assertFalse($this->builder->supports([
            'ok' => 1,
            'bad' => new \stdClass(),
        ]));
    }

    public function testSupportsReturnsFalseForDeeplyNestedUnsupportedValue(): void
    {
        $this->assertFalse($this->builder->supports([
            'level1' => [
                'level2' => [
                    'bad' => new \stdClass(),
                ],
            ],
        ]));
    }

    /**
     * Helper that pretty-prints a single expression AST node using the same
     * printer configuration the real generator uses.
     */
    private function printExpr(\PhpParser\Node\Expr $node): string
    {
        return $this->printer->prettyPrintExpr($node);
    }
}
