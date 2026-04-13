<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;
use RemcoSmitsDev\BuildableSerializerBundle\Metadata\PropertyContext;

/**
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Metadata\PropertyContext
 */
final class PropertyContextTest extends TestCase
{
    public function testEmptyContextReturnsEmptyArrays(): void
    {
        $context = new PropertyContext();

        $this->assertSame([], $context->getContext());
        $this->assertSame([], $context->getNormalizationContext());
        $this->assertSame([], $context->getDenormalizationContext());
        $this->assertSame([], $context->getGroups());
    }

    public function testCommonContextIsReturnedByGetContext(): void
    {
        $context = new PropertyContext(context: ['key' => 'value']);

        $this->assertSame(['key' => 'value'], $context->getContext());
    }

    public function testCommonContextIsMergedIntoNormalizationContext(): void
    {
        $context = new PropertyContext(context: ['common' => 'value'], normalizationContext: ['norm' => 'only']);

        $expected = ['common' => 'value', 'norm' => 'only'];
        $this->assertSame($expected, $context->getNormalizationContext());
    }

    public function testCommonContextIsMergedIntoDenormalizationContext(): void
    {
        $context = new PropertyContext(context: ['common' => 'value'], denormalizationContext: ['denorm' => 'only']);

        $expected = ['common' => 'value', 'denorm' => 'only'];
        $this->assertSame($expected, $context->getDenormalizationContext());
    }

    public function testNormalizationContextOverridesCommonContext(): void
    {
        $context = new PropertyContext(context: ['key' => 'common'], normalizationContext: ['key' => 'normalization']);

        $this->assertSame(['key' => 'normalization'], $context->getNormalizationContext());
    }

    public function testDenormalizationContextOverridesCommonContext(): void
    {
        $context = new PropertyContext(context: ['key' => 'common'], denormalizationContext: [
            'key' => 'denormalization',
        ]);

        $this->assertSame(['key' => 'denormalization'], $context->getDenormalizationContext());
    }

    public function testGetGroupsReturnsConfiguredGroups(): void
    {
        $context = new PropertyContext(context: ['key' => 'value'], groups: ['group1', 'group2']);

        $this->assertSame(['group1', 'group2'], $context->getGroups());
    }

    public function testIsUnconditionalReturnsTrueWhenNoGroups(): void
    {
        $context = new PropertyContext(context: ['key' => 'value']);

        $this->assertTrue($context->isUnconditional());
    }

    public function testIsUnconditionalReturnsFalseWhenGroupsExist(): void
    {
        $context = new PropertyContext(context: ['key' => 'value'], groups: ['group1']);

        $this->assertFalse($context->isUnconditional());
    }

    public function testIsApplicableForGroupsReturnsTrueWhenNoContextGroups(): void
    {
        $context = new PropertyContext(context: ['key' => 'value']);

        $this->assertTrue($context->isApplicableForGroups(['any_group']));
        $this->assertTrue($context->isApplicableForGroups([]));
    }

    public function testIsApplicableForGroupsReturnsTrueWhenNoActiveGroups(): void
    {
        $context = new PropertyContext(context: ['key' => 'value'], groups: ['group1']);

        // When no active groups, all contexts apply (Symfony's default behavior)
        $this->assertTrue($context->isApplicableForGroups([]));
    }

    public function testIsApplicableForGroupsReturnsTrueWhenGroupMatches(): void
    {
        $context = new PropertyContext(context: ['key' => 'value'], groups: ['group1', 'group2']);

        $this->assertTrue($context->isApplicableForGroups(['group1']));
        $this->assertTrue($context->isApplicableForGroups(['group2']));
        $this->assertTrue($context->isApplicableForGroups(['group1', 'group2']));
        $this->assertTrue($context->isApplicableForGroups(['group1', 'other']));
    }

    public function testIsApplicableForGroupsReturnsFalseWhenGroupDoesNotMatch(): void
    {
        $context = new PropertyContext(context: ['key' => 'value'], groups: ['group1', 'group2']);

        $this->assertFalse($context->isApplicableForGroups(['other']));
        $this->assertFalse($context->isApplicableForGroups(['group3', 'group4']));
    }

    public function testHasNormalizationContextReturnsTrueWhenCommonContextExists(): void
    {
        $context = new PropertyContext(context: ['key' => 'value']);

        $this->assertTrue($context->hasNormalizationContext());
    }

    public function testHasNormalizationContextReturnsTrueWhenNormalizationContextExists(): void
    {
        $context = new PropertyContext(normalizationContext: ['key' => 'value']);

        $this->assertTrue($context->hasNormalizationContext());
    }

    public function testHasNormalizationContextReturnsFalseWhenOnlyDenormalizationContextExists(): void
    {
        $context = new PropertyContext(denormalizationContext: ['key' => 'value']);

        $this->assertFalse($context->hasNormalizationContext());
    }

    public function testHasNormalizationContextReturnsFalseWhenEmpty(): void
    {
        $context = new PropertyContext();

        $this->assertFalse($context->hasNormalizationContext());
    }

    public function testHasDenormalizationContextReturnsTrueWhenCommonContextExists(): void
    {
        $context = new PropertyContext(context: ['key' => 'value']);

        $this->assertTrue($context->hasDenormalizationContext());
    }

    public function testHasDenormalizationContextReturnsTrueWhenDenormalizationContextExists(): void
    {
        $context = new PropertyContext(denormalizationContext: ['key' => 'value']);

        $this->assertTrue($context->hasDenormalizationContext());
    }

    public function testHasDenormalizationContextReturnsFalseWhenOnlyNormalizationContextExists(): void
    {
        $context = new PropertyContext(normalizationContext: ['key' => 'value']);

        $this->assertFalse($context->hasDenormalizationContext());
    }

    public function testHasDenormalizationContextReturnsFalseWhenEmpty(): void
    {
        $context = new PropertyContext();

        $this->assertFalse($context->hasDenormalizationContext());
    }

    public function testComplexContextMerging(): void
    {
        $context = new PropertyContext(
            context: [
                'common_key' => 'common_value',
                'override_key' => 'common_override',
            ],
            normalizationContext: [
                'norm_key' => 'norm_value',
                'override_key' => 'norm_override',
            ],
            denormalizationContext: [
                'denorm_key' => 'denorm_value',
                'override_key' => 'denorm_override',
            ],
        );

        $normContext = $context->getNormalizationContext();
        $this->assertSame('common_value', $normContext['common_key']);
        $this->assertSame('norm_value', $normContext['norm_key']);
        $this->assertSame('norm_override', $normContext['override_key']);
        $this->assertArrayNotHasKey('denorm_key', $normContext);

        $denormContext = $context->getDenormalizationContext();
        $this->assertSame('common_value', $denormContext['common_key']);
        $this->assertSame('denorm_value', $denormContext['denorm_key']);
        $this->assertSame('denorm_override', $denormContext['override_key']);
        $this->assertArrayNotHasKey('norm_key', $denormContext);
    }
}
