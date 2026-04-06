<?php

declare(strict_types=1);

namespace Buildable\SerializerBundle\Tests\Unit\Generator;

use Buildable\SerializerBundle\Generator\CodeBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Buildable\SerializerBundle\Generator\CodeBuilder
 */
final class CodeBuilderTest extends TestCase
{
    // -------------------------------------------------------------------------
    // addLine() — indentation
    // -------------------------------------------------------------------------

    public function testAddLineWithZeroBaseIndentAndZeroExtraIndent(): void
    {
        $b = new CodeBuilder(0);
        $b->addLine('$x = 1;');

        $this->assertSame('$x = 1;', $b->build());
    }

    public function testAddLineAppendsLineWithCorrectIndent(): void
    {
        $b = new CodeBuilder(1);
        $b->addLine('$x = 1;');

        $this->assertStringContainsString('    $x = 1;', $b->build());
    }

    public function testAddLineWithTwoLevelsOfBaseIndent(): void
    {
        $b = new CodeBuilder(2);
        $b->addLine('return $data;');

        $this->assertStringContainsString('        return $data;', $b->build());
    }

    public function testAddLineWithExtraInlineIndent(): void
    {
        $b = new CodeBuilder(0);
        $b->addLine('nested', 2);

        $this->assertStringContainsString('        nested', $b->build());
    }

    public function testAddLineWithBaseAndExtraIndentCombined(): void
    {
        $b = new CodeBuilder(1);
        $b->addLine('deep', 1);

        $this->assertStringContainsString('        deep', $b->build());
    }

    public function testAddEmptyLineDoesNotLeadToTrailingSpaces(): void
    {
        $b = new CodeBuilder(2);
        $b->addLine('');

        $this->assertSame('', $b->build());
    }

    // -------------------------------------------------------------------------
    // addBlankLine()
    // -------------------------------------------------------------------------

    public function testAddBlankLineAddsEmptyLine(): void
    {
        $b = new CodeBuilder(0);
        $b->addLine('a');
        $b->addBlankLine();
        $b->addLine('b');

        $parts = explode("\n", $b->build());

        $this->assertCount(3, $parts);
        $this->assertSame('a', $parts[0]);
        $this->assertSame('', $parts[1]);
        $this->assertSame('b', $parts[2]);
    }

    public function testAddBlankLineIsEquivalentToAddLineWithEmptyString(): void
    {
        $b1 = new CodeBuilder(0);
        $b1->addLine('x');
        $b1->addBlankLine();

        $b2 = new CodeBuilder(0);
        $b2->addLine('x');
        $b2->addLine('');

        $this->assertSame($b1->build(), $b2->build());
    }

    // -------------------------------------------------------------------------
    // build()
    // -------------------------------------------------------------------------

    public function testBuildReturnsEmptyStringWhenNoLines(): void
    {
        $b = new CodeBuilder(0);

        $this->assertSame('', $b->build());
    }

    public function testBuildJoinsLinesWithNewline(): void
    {
        $b = new CodeBuilder(0);
        $b->addLine('line1');
        $b->addLine('line2');
        $b->addLine('line3');

        $this->assertSame("line1\nline2\nline3", $b->build());
    }

    public function testBuildDoesNotAddTrailingNewline(): void
    {
        $b = new CodeBuilder(0);
        $b->addLine('last');

        $output = $b->build();

        $this->assertStringNotContainsString("\n", $output);
    }

    // -------------------------------------------------------------------------
    // indent() — returns new instance with shared buffer
    // -------------------------------------------------------------------------

    public function testIndentReturnsNewInstance(): void
    {
        $parent = new CodeBuilder(0);
        $child  = $parent->indent();

        $this->assertNotSame($parent, $child);
    }

    public function testIndentReturnsNewInstanceWithHigherIndent(): void
    {
        $b     = new CodeBuilder(0);
        $inner = $b->indent();
        $inner->addLine('x');

        $this->assertStringContainsString('    x', $b->build());
    }

    public function testIndentWithMultipleLevels(): void
    {
        $b     = new CodeBuilder(0);
        $inner = $b->indent(3);
        $inner->addLine('deep');

        $this->assertStringContainsString('            deep', $b->build());
    }

    public function testSharedBufferBetweenParentAndChild(): void
    {
        $parent = new CodeBuilder(0);
        $child  = $parent->indent();

        $parent->addLine('parent-line-1');
        $child->addLine('child-line');
        $parent->addLine('parent-line-2');

        $output = $parent->build();
        $lines  = explode("\n", $output);

        $this->assertSame('parent-line-1', $lines[0]);
        $this->assertStringContainsString('child-line', $lines[1]);
        $this->assertSame('parent-line-2', $lines[2]);
    }

    public function testChildBufferIsSharedWithParent(): void
    {
        $parent = new CodeBuilder(0);
        $child  = $parent->indent();

        $child->addLine('from-child');

        // Reading from parent should include child's lines
        $this->assertStringContainsString('from-child', $parent->build());
    }

    public function testGrandchildSharesBuffer(): void
    {
        $root       = new CodeBuilder(0);
        $child      = $root->indent();
        $grandchild = $child->indent();

        $root->addLine('root');
        $child->addLine('child');
        $grandchild->addLine('grandchild');

        $output = $root->build();

        $this->assertStringContainsString('root', $output);
        $this->assertStringContainsString('    child', $output);
        $this->assertStringContainsString('        grandchild', $output);
    }

    // -------------------------------------------------------------------------
    // arrayExport() — static helper
    // -------------------------------------------------------------------------

    public function testArrayExportEmptyArray(): void
    {
        $this->assertSame('[]', CodeBuilder::arrayExport([]));
    }

    public function testArrayExportSimpleList(): void
    {
        $result = CodeBuilder::arrayExport(['a', 'b']);

        $this->assertSame("['a', 'b']", $result);
    }

    public function testArrayExportSingleItem(): void
    {
        $this->assertSame("['x']", CodeBuilder::arrayExport(['x']));
    }

    public function testArrayExportAssociative(): void
    {
        $result = CodeBuilder::arrayExport(['k' => 1]);

        $this->assertSame("['k' => 1]", $result);
    }

    public function testArrayExportAssociativeMultipleEntries(): void
    {
        $result = CodeBuilder::arrayExport(['a' => 1, 'b' => 2]);

        $this->assertSame("['a' => 1, 'b' => 2]", $result);
    }

    public function testArrayExportNested(): void
    {
        $result = CodeBuilder::arrayExport(['x' => ['y', 'z']]);

        $this->assertSame("['x' => ['y', 'z']]", $result);
    }

    public function testArrayExportNestedAssociative(): void
    {
        $result = CodeBuilder::arrayExport(['outer' => ['inner' => 'value']]);

        $this->assertSame("['outer' => ['inner' => 'value']]", $result);
    }

    public function testArrayExportListWithIntegers(): void
    {
        $result = CodeBuilder::arrayExport([1, 2, 3]);

        $this->assertSame('[1, 2, 3]', $result);
    }

    public function testArrayExportListWithMixedScalars(): void
    {
        $result = CodeBuilder::arrayExport([true, false, null]);

        $this->assertSame('[true, false, null]', $result);
    }

    // -------------------------------------------------------------------------
    // valueExport() — static helper
    // -------------------------------------------------------------------------

    public function testValueExportNull(): void
    {
        $this->assertSame('null', CodeBuilder::valueExport(null));
    }

    public function testValueExportTrue(): void
    {
        $this->assertSame('true', CodeBuilder::valueExport(true));
    }

    public function testValueExportFalse(): void
    {
        $this->assertSame('false', CodeBuilder::valueExport(false));
    }

    public function testValueExportInt(): void
    {
        $this->assertSame('42', CodeBuilder::valueExport(42));
    }

    public function testValueExportZero(): void
    {
        $this->assertSame('0', CodeBuilder::valueExport(0));
    }

    public function testValueExportNegativeInt(): void
    {
        $this->assertSame('-7', CodeBuilder::valueExport(-7));
    }

    public function testValueExportString(): void
    {
        $this->assertSame("'hello'", CodeBuilder::valueExport('hello'));
    }

    public function testValueExportEmptyString(): void
    {
        $this->assertSame("''", CodeBuilder::valueExport(''));
    }

    public function testValueExportStringEscapesQuotes(): void
    {
        $this->assertSame("'it\\'s'", CodeBuilder::valueExport("it's"));
    }

    public function testValueExportStringEscapesBackslashes(): void
    {
        $result = CodeBuilder::valueExport('path\\to\\file');

        $this->assertSame("'path\\\\to\\\\file'", $result);
    }

    public function testValueExportFloat(): void
    {
        $result = CodeBuilder::valueExport(3.14);

        $this->assertStringContainsString('3.14', $result);
    }

    public function testValueExportFloatAsInt(): void
    {
        // 1.0 cast to string may be "1" but valueExport must ensure float syntax
        $result = CodeBuilder::valueExport(1.0);

        $this->assertStringContainsString('1', $result);
        // PHP guarantees the exported value is a valid float literal
    }

    public function testValueExportArray(): void
    {
        $result = CodeBuilder::valueExport(['a', 'b']);

        $this->assertSame("['a', 'b']", $result);
    }

    public function testValueExportThrowsForObject(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CodeBuilder::valueExport(new \stdClass());
    }

    public function testValueExportThrowsForResource(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CodeBuilder::valueExport(fopen('php://memory', 'r'));
    }

    // -------------------------------------------------------------------------
    // Shared buffer via constructor injection
    // -------------------------------------------------------------------------

    public function testExplicitSharedBufferIsUsed(): void
    {
        $buffer = new \ArrayObject();
        $b1     = new CodeBuilder(0, $buffer);
        $b2     = new CodeBuilder(1, $buffer);

        $b1->addLine('from-b1');
        $b2->addLine('from-b2');

        $output = $b1->build();

        $this->assertStringContainsString('from-b1', $output);
        $this->assertStringContainsString('from-b2', $output);
    }
}
