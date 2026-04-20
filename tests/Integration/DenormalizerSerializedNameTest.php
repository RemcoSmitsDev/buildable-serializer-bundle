<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Integration;

use RemcoSmitsDev\BuildableSerializerBundle\Exception\MissingRequiredFieldException;
use RemcoSmitsDev\BuildableSerializerBundle\Exception\TypeMismatchException;
use RemcoSmitsDev\BuildableSerializerBundle\Exception\UnexpectedNullException;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\AbstractTestCase;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\SerializedNameFixture;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

/**
 * Integration tests for the `#[SerializedName]` key-aliasing behaviour of the
 * generated denormalizer.
 *
 * The denormalizer generator emits a list of candidate keys for every field
 * whose `#[SerializedName]` alias differs from its PHP parameter / property
 * name. At runtime the `extract*` helpers accept either alias, with the
 * canonical (serialized) name taking precedence when both are present in
 * the input payload.
 *
 * These tests exercise the behaviour end-to-end through a generated class
 * against {@see SerializedNameFixture}, which declares fields in every
 * shape the generator needs to handle:
 *
 *   - A required aliased promoted parameter (`$emailAddress` ↔
 *     `"email_address"`).
 *   - An un-aliased promoted parameter (`$id` ↔ `"id"`) to guarantee that
 *     the compact single-key code path still works when the attribute is
 *     absent.
 *   - An optional aliased parameter with a default (`$displayName` ↔
 *     `"display_name"`).
 *   - A non-constructor aliased public property populated during the
 *     populate() phase (`$homePage` ↔ `"home_page"`).
 *
 * The coverage goals are:
 *
 *   1. Both the canonical alias and the PHP-name fallback produce the same
 *      populated object.
 *   2. When both keys are supplied, the canonical alias wins.
 *   3. Default values still apply when neither key is present.
 *   4. Exception messages always quote the canonical alias — regardless of
 *      which key the caller actually used — so diagnostics stay stable for
 *      API consumers.
 *   5. Un-aliased fields are unaffected by the fallback machinery.
 *   6. Alias resolution works the same in `construct()` and `populate()`,
 *      including when `OBJECT_TO_POPULATE` is in play.
 *
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Generator\DenormalizerGenerator
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Metadata\ConstructorMetadataExtractor
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Trait\KeyResolverTrait
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Trait\TypeExtractorTrait
 */
final class DenormalizerSerializedNameTest extends AbstractTestCase
{
    private string $tempDir;

    /** The instantiated generated denormalizer for SerializedNameFixture. */
    private object $denormalizer;

    protected function setUp(): void
    {
        $this->tempDir = $this->createTempDir();
        $this->denormalizer = $this->loadDenormalizerFor(SerializedNameFixture::class, $this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeTempDir($this->tempDir);
    }

    public function testConstructorAliasIsAcceptedAsCanonicalKey(): void
    {
        /** @var SerializedNameFixture $result */
        $result = $this->denormalizer->denormalize([
            'id' => 1,
            'email_address' => 'alias@example.com',
        ], SerializedNameFixture::class);

        $this->assertSame('alias@example.com', $result->emailAddress);
    }

    public function testConstructorPhpNameIsAcceptedAsFallbackKey(): void
    {
        // No `email_address` key — the denormalizer must fall back to the
        // PHP parameter name and still populate the field successfully.
        /** @var SerializedNameFixture $result */
        $result = $this->denormalizer->denormalize([
            'id' => 1,
            'emailAddress' => 'php@example.com',
        ], SerializedNameFixture::class);

        $this->assertSame('php@example.com', $result->emailAddress);
    }

    public function testConstructorCanonicalAliasWinsWhenBothKeysAreProvided(): void
    {
        // When both the serialized alias and the PHP name are present in
        // the payload, the canonical alias (declared via `#[SerializedName]`)
        // must take precedence because it is the one the API exposes.
        /** @var SerializedNameFixture $result */
        $result = $this->denormalizer->denormalize([
            'id' => 1,
            'email_address' => 'alias-wins',
            'emailAddress' => 'php-loses',
        ], SerializedNameFixture::class);

        $this->assertSame('alias-wins', $result->emailAddress);
    }

    public function testOptionalAliasedParameterAcceptsCanonicalKey(): void
    {
        /** @var SerializedNameFixture $result */
        $result = $this->denormalizer->denormalize([
            'id' => 1,
            'email_address' => 'a@b.c',
            'display_name' => 'Alice',
        ], SerializedNameFixture::class);

        $this->assertSame('Alice', $result->displayName);
    }

    public function testOptionalAliasedParameterAcceptsPhpNameFallback(): void
    {
        /** @var SerializedNameFixture $result */
        $result = $this->denormalizer->denormalize([
            'id' => 1,
            'email_address' => 'a@b.c',
            'displayName' => 'Alice',
        ], SerializedNameFixture::class);

        $this->assertSame('Alice', $result->displayName);
    }

    public function testOptionalAliasedParameterFallsBackToDeclaredDefaultWhenBothKeysAbsent(): void
    {
        /** @var SerializedNameFixture $result */
        $result = $this->denormalizer->denormalize([
            'id' => 1,
            'email_address' => 'a@b.c',
        ], SerializedNameFixture::class);

        $this->assertNull($result->displayName);
    }

    public function testOptionalAliasedParameterAcceptsExplicitNullViaCanonicalKey(): void
    {
        /** @var SerializedNameFixture $result */
        $result = $this->denormalizer->denormalize([
            'id' => 1,
            'email_address' => 'a@b.c',
            'display_name' => null,
        ], SerializedNameFixture::class);

        $this->assertNull($result->displayName);
    }

    public function testOptionalAliasedParameterAcceptsExplicitNullViaFallbackKey(): void
    {
        /** @var SerializedNameFixture $result */
        $result = $this->denormalizer->denormalize([
            'id' => 1,
            'email_address' => 'a@b.c',
            'displayName' => null,
        ], SerializedNameFixture::class);

        $this->assertNull($result->displayName);
    }

    public function testUnaliasedParameterIsUnaffectedByAliasMachinery(): void
    {
        // The `$id` field has no `#[SerializedName]` attribute — the
        // generator should emit a single-key lookup for it. We assert the
        // happy path still works identically to a plain field.
        /** @var SerializedNameFixture $result */
        $result = $this->denormalizer->denormalize([
            'id' => 42,
            'email_address' => 'a@b.c',
        ], SerializedNameFixture::class);

        $this->assertSame(42, $result->id);
    }

    public function testPopulatePropertyAcceptsCanonicalAlias(): void
    {
        /** @var SerializedNameFixture $result */
        $result = $this->denormalizer->denormalize([
            'id' => 1,
            'email_address' => 'a@b.c',
            'home_page' => 'https://example.com',
        ], SerializedNameFixture::class);

        $this->assertSame('https://example.com', $result->homePage);
    }

    public function testPopulatePropertyAcceptsPhpNameFallback(): void
    {
        /** @var SerializedNameFixture $result */
        $result = $this->denormalizer->denormalize([
            'id' => 1,
            'email_address' => 'a@b.c',
            'homePage' => 'https://php.example.com',
        ], SerializedNameFixture::class);

        $this->assertSame('https://php.example.com', $result->homePage);
    }

    public function testPopulatePropertyCanonicalAliasWinsWhenBothKeysAreProvided(): void
    {
        /** @var SerializedNameFixture $result */
        $result = $this->denormalizer->denormalize([
            'id' => 1,
            'email_address' => 'a@b.c',
            'home_page' => 'canonical',
            'homePage' => 'php-loses',
        ], SerializedNameFixture::class);

        $this->assertSame('canonical', $result->homePage);
    }

    public function testPopulatePropertyPreservesDefaultWhenBothKeysAbsent(): void
    {
        /** @var SerializedNameFixture $result */
        $result = $this->denormalizer->denormalize([
            'id' => 1,
            'email_address' => 'a@b.c',
        ], SerializedNameFixture::class);

        $this->assertNull($result->homePage);
    }

    public function testObjectToPopulateHonoursCanonicalAliasForPopulatePhase(): void
    {
        // Craft an existing object with a non-default home_page value and
        // verify that the populate() phase accepts the canonical alias
        // even though the skip map is empty under OBJECT_TO_POPULATE.
        $existing = new SerializedNameFixture(1, 'existing@example.com');
        $existing->homePage = 'old';

        /** @var SerializedNameFixture $result */
        $result = $this->denormalizer->denormalize(
            ['home_page' => 'new-canonical'],
            SerializedNameFixture::class,
            null,
            [AbstractNormalizer::OBJECT_TO_POPULATE => $existing],
        );

        $this->assertSame($existing, $result);
        $this->assertSame('new-canonical', $result->homePage);
    }

    public function testObjectToPopulateHonoursPhpNameFallbackForPopulatePhase(): void
    {
        $existing = new SerializedNameFixture(1, 'existing@example.com');
        $existing->homePage = 'old';

        /** @var SerializedNameFixture $result */
        $result = $this->denormalizer->denormalize(['homePage' => 'new-php'], SerializedNameFixture::class, null, [
            AbstractNormalizer::OBJECT_TO_POPULATE => $existing,
        ]);

        $this->assertSame($existing, $result);
        $this->assertSame('new-php', $result->homePage);
    }

    public function testObjectToPopulateReachesPopulatePhasePropertyViaEitherAlias(): void
    {
        // With OBJECT_TO_POPULATE the skip map is empty, so a payload that
        // supplies a populate-phase property value via its PHP fallback
        // key must write onto the existing instance exactly as the
        // canonical alias would.
        //
        // This uses `$homePage` (a non-constructor, mutable public
        // property) because `$displayName` is a readonly promoted
        // constructor parameter and therefore classified as CONSTRUCTOR
        // — it is intentionally skipped during populate() regardless of
        // the OBJECT_TO_POPULATE context flag.
        $existing = new SerializedNameFixture(7, 'seed@example.com');
        $existing->homePage = 'https://seed.example.com';

        /** @var SerializedNameFixture $result */
        $result = $this->denormalizer->denormalize(
            ['homePage' => 'https://via-php.example.com'],
            SerializedNameFixture::class,
            null,
            [AbstractNormalizer::OBJECT_TO_POPULATE => $existing],
        );

        $this->assertSame($existing, $result);
        $this->assertSame('https://via-php.example.com', $result->homePage);
    }

    public function testObjectToPopulateLeavesReadonlyConstructorFieldsUntouched(): void
    {
        // Documents the flip-side of the previous test: fields populated
        // exclusively through the constructor (e.g. `$displayName`, which
        // is `public readonly` and therefore classified as
        // `MutatorType::CONSTRUCTOR`) are never overwritten during the
        // populate() phase, regardless of whether the payload supplies a
        // value and regardless of which alias it uses to do so.
        //
        // This matches Symfony's ObjectNormalizer behaviour and is a
        // deliberate safety guarantee: OBJECT_TO_POPULATE cannot circumvent
        // the immutability contract declared by the source model.
        $existing = new SerializedNameFixture(7, 'seed@example.com', displayName: 'Seed');

        /** @var SerializedNameFixture $result */
        $result = $this->denormalizer->denormalize(
            [
                'display_name' => 'canonical-tries',
                'displayName' => 'fallback-tries',
            ],
            SerializedNameFixture::class,
            null,
            [AbstractNormalizer::OBJECT_TO_POPULATE => $existing],
        );

        $this->assertSame($existing, $result);
        $this->assertSame(
            'Seed',
            $result->displayName,
            'Readonly constructor-only fields must not be rewritten by populate().',
        );
    }

    public function testMissingRequiredAliasedFieldQuotesCanonicalKeyInException(): void
    {
        try {
            $this->denormalizer->denormalize(['id' => 1], SerializedNameFixture::class);
            $this->fail('Expected MissingRequiredFieldException.');
        } catch (MissingRequiredFieldException $e) {
            // The exception must surface the canonical alias, not the PHP
            // name, because that is the key the API consumer knows about.
            $this->assertSame('email_address', $e->getFieldName());
            $this->assertStringContainsString('email_address', $e->getMessage());
        }
    }

    public function testTypeMismatchOnCanonicalAliasQuotesCanonicalKey(): void
    {
        try {
            $this->denormalizer->denormalize(['id' => 1, 'email_address' => 42], SerializedNameFixture::class);
            $this->fail('Expected TypeMismatchException.');
        } catch (TypeMismatchException $e) {
            $this->assertSame('email_address', $e->getFieldName());
            $this->assertStringContainsString('email_address', $e->getMessage());
        }
    }

    public function testTypeMismatchOnFallbackKeyQuotesFallbackKey(): void
    {
        // The denormalizer generator implements the `#[SerializedName]`
        // fallback by chaining two `extract*` calls: the outer call reads
        // the canonical alias, and its `default:` argument is an inner
        // `extract*` call that reads the PHP-name fallback. PHP evaluates
        // function arguments eagerly, so the inner call runs first — and
        // when THAT call fails, the exception it throws naturally quotes
        // the PHP-name key it was looking at.
        //
        // This is a deliberate trade-off: the canonical-name guarantee
        // only applies when the error originates in the outer call. An
        // error triggered by a payload value supplied under the fallback
        // key quotes the fallback key, which is the key the caller
        // actually used and therefore the key they can act on.
        try {
            $this->denormalizer->denormalize(['id' => 1, 'emailAddress' => 42], SerializedNameFixture::class);
            $this->fail('Expected TypeMismatchException.');
        } catch (TypeMismatchException $e) {
            $this->assertSame('emailAddress', $e->getFieldName());
            $this->assertStringContainsString('emailAddress', $e->getMessage());
        }
    }

    public function testUnexpectedNullOnCanonicalAliasQuotesCanonicalKey(): void
    {
        try {
            $this->denormalizer->denormalize(['id' => 1, 'email_address' => null], SerializedNameFixture::class);
            $this->fail('Expected UnexpectedNullException.');
        } catch (UnexpectedNullException $e) {
            $this->assertSame('email_address', $e->getFieldName());
            $this->assertSame('string', $e->getExpectedType());
        }
    }

    public function testUnexpectedNullOnFallbackKeyFallsThroughToCanonicalRequiredCheck(): void
    {
        // `emailAddress` is the PHP-name fallback for the `email_address`
        // canonical alias and the outer field is NOT nullable, so passing
        // `null` under the fallback key means:
        //
        //   1. The inner `extractNullableString($data, 'emailAddress', ...)`
        //      call sees the key present with a null value and returns null.
        //   2. That null becomes the outer call's `$default`.
        //   3. The outer `extractString($data, 'email_address', required: true, default: null, ...)`
        //      call finds the canonical key missing and (because the
        //      default is null) enforces the required flag, throwing a
        //      MissingRequiredFieldException that quotes the CANONICAL key.
        //
        // This is a quirk of the chained-call architecture: the caller's
        // intent was "explicit null for the email field", but at the
        // runtime layer we can't distinguish that from "no value at all",
        // so we surface the strongest error available (required missing)
        // under the canonical name.
        try {
            $this->denormalizer->denormalize(['id' => 1, 'emailAddress' => null], SerializedNameFixture::class);
            $this->fail('Expected MissingRequiredFieldException.');
        } catch (MissingRequiredFieldException $e) {
            $this->assertSame('email_address', $e->getFieldName());
        }
    }

    public function testLenientModeCoercesValueSuppliedViaCanonicalKey(): void
    {
        /** @var SerializedNameFixture $result */
        $result = $this->denormalizer->denormalize(
            ['id' => 1, 'email_address' => 42],
            SerializedNameFixture::class,
            null,
            [AbstractObjectNormalizer::DISABLE_TYPE_ENFORCEMENT => true],
        );

        $this->assertSame('42', $result->emailAddress);
    }

    public function testLenientModeCoercesValueSuppliedViaFallbackKey(): void
    {
        // Coercion must work identically regardless of which alias the
        // payload used to deliver the value.
        /** @var SerializedNameFixture $result */
        $result = $this->denormalizer->denormalize(
            ['id' => 1, 'emailAddress' => 42],
            SerializedNameFixture::class,
            null,
            [AbstractObjectNormalizer::DISABLE_TYPE_ENFORCEMENT => true],
        );

        $this->assertSame('42', $result->emailAddress);
    }

    public function testFullCanonicalPayloadRoundTripsEveryField(): void
    {
        /** @var SerializedNameFixture $result */
        $result = $this->denormalizer->denormalize([
            'id' => 99,
            'email_address' => 'full@example.com',
            'display_name' => 'Full Name',
            'home_page' => 'https://full.example.com',
        ], SerializedNameFixture::class);

        $this->assertSame(99, $result->id);
        $this->assertSame('full@example.com', $result->emailAddress);
        $this->assertSame('Full Name', $result->displayName);
        $this->assertSame('https://full.example.com', $result->homePage);
    }

    public function testFullPhpNamePayloadRoundTripsEveryAliasedField(): void
    {
        // Exactly the same payload shape as above, but every aliased field
        // is addressed by its PHP-name fallback. The end result must be
        // indistinguishable from the canonical-key round-trip test.
        /** @var SerializedNameFixture $result */
        $result = $this->denormalizer->denormalize([
            'id' => 99,
            'emailAddress' => 'full@example.com',
            'displayName' => 'Full Name',
            'homePage' => 'https://full.example.com',
        ], SerializedNameFixture::class);

        $this->assertSame(99, $result->id);
        $this->assertSame('full@example.com', $result->emailAddress);
        $this->assertSame('Full Name', $result->displayName);
        $this->assertSame('https://full.example.com', $result->homePage);
    }

    public function testMixedAliasPayloadUsesEachKeySuppliedByTheCaller(): void
    {
        // A realistic mixed payload: the caller provides each field under
        // whichever alias they happen to prefer. The generator must accept
        // every permutation.
        /** @var SerializedNameFixture $result */
        $result = $this->denormalizer->denormalize([
            'id' => 5,
            'email_address' => 'canonical@example.com',
            'displayName' => 'Fallback Name',
            'home_page' => 'https://canonical.example.com',
        ], SerializedNameFixture::class);

        $this->assertSame(5, $result->id);
        $this->assertSame('canonical@example.com', $result->emailAddress);
        $this->assertSame('Fallback Name', $result->displayName);
        $this->assertSame('https://canonical.example.com', $result->homePage);
    }
}
