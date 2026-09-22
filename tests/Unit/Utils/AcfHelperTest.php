<?php

namespace Tests\Unit\Utils;

use Brain\Monkey\Functions;
use Confur\Utils\AcfHelper;

covers(AcfHelper::class);

/**
 * Make acf_get_field() answer for exactly these selectors and false for
 * anything else, which is what the real ACF does for an unknown name.
 *
 * @param array<string, array<string, mixed>> $fields
 */
function knownFields(array $fields): void
{
    Functions\when('acf_get_field')->alias(
        static fn (string $selector): array|false => $fields[$selector] ?? false
    );
}

beforeEach(function () {
    unset($_POST['acf']);

    // AcfHelper's whole job is refusing to write a field ACF does not
    // know, so acf_get_field() has to be able to answer "no". wp-mocks'
    // default invents a field object for any selector, which would make
    // that branch unreachable, so every test here declares the fields it
    // considers to exist through knownFields().
    knownFields([]);
});

// ── updateAcfField ─────────────────────────────────────────────────
describe('updateAcfField', function () {
    it('rejects empty arguments', function () {
        expect(AcfHelper::updateAcfField(0, 'name', 'v'))->toBeFalse()
            ->and(AcfHelper::updateAcfField(5, '', 'v'))->toBeFalse();
    });

    it('returns false when the field is unknown', function () {
        expect(AcfHelper::updateAcfField(5, 'unknown', 'v'))->toBeFalse();
    });

    it('succeeds', function () {
        knownFields(['price' => ['key' => 'field_abc']]);
        expect(AcfHelper::updateAcfField(5, 'price', '10'))->toBeTrue()
            ->and($_POST)->not->toHaveKey('acf');
    });
});

// ── updateAcfFields ────────────────────────────────────────────────
describe('updateAcfFields', function () {
    it('rejects invalid input', function () {
        expect(AcfHelper::updateAcfFields(0, ['a' => 1]))->toBeFalse()
            ->and(AcfHelper::updateAcfFields(5, []))->toBeFalse();
    });

    it('returns false when none resolve', function () {
        expect(AcfHelper::updateAcfFields(5, ['unknown' => 'v']))->toBeFalse();
    });

    it('succeeds for known fields', function () {
        knownFields([
            'price' => ['key' => 'field_price'],
            'name'  => ['key' => 'field_name'],
        ]);
        expect(AcfHelper::updateAcfFields(5, ['price' => '10', 'name' => 'x', 'unknown' => 'y']))->toBeTrue();
    });
});

// ── updateAcfField2 ────────────────────────────────────────────────
describe('updateAcfField2', function () {
    it('rejects empty arguments', function () {
        expect(AcfHelper::updateAcfField2(0, 'name', 'v'))->toBeFalse();
    });

    it('returns false when the post is missing', function () {
        // Nothing seeded, so get_post() answers null.
        expect(AcfHelper::updateAcfField2(999, 'price', 'v'))->toBeFalse();
    });

    it('returns false when the field is unknown', function () {
        $this->makePost(5, '', 'publish', 'answer');
        expect(AcfHelper::updateAcfField2(5, 'unknown', 'v'))->toBeFalse();
    });

    it('succeeds', function () {
        $this->makePost(5, '', 'publish', 'answer');
        knownFields(['price' => ['key' => 'field_price']]);
        expect(AcfHelper::updateAcfField2(5, 'price', '10'))->toBeTrue();
    });

    it('returns false when the save throws', function () {
        $this->makePost(5, '', 'publish', 'answer');
        knownFields(['price' => ['key' => 'field_price']]);
        Functions\when('acf_save_post')->alias(static function (): bool {
            throw new \RuntimeException('acf save failed');
        });
        try {
            expect(AcfHelper::updateAcfField2(5, 'price', '10'))->toBeFalse();
        } finally {
        }
    });
});
