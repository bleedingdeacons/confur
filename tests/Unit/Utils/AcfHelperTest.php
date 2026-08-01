<?php

namespace Tests\Unit\Utils;

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Confur\Utils\AcfHelper;
use Tests\ConfurTestCase;

/**
 * @covers \Confur\Utils\AcfHelper
 */
class AcfHelperTest extends ConfurTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        unset($_POST['acf']);

        // AcfHelper's whole job is refusing to write a field ACF does not
        // know, so acf_get_field() has to be able to answer "no". wp-mocks'
        // default invents a field object for any selector, which would make
        // that branch unreachable, so every test here declares the fields it
        // considers to exist through knownFields().
        $this->knownFields([]);
    }

    /**
     * Make acf_get_field() answer for exactly these selectors and false for
     * anything else, which is what the real ACF does for an unknown name.
     *
     * @param array<string, array<string, mixed>> $fields
     */
    private function knownFields(array $fields): void
    {
        Functions\when('acf_get_field')->alias(
            static fn (string $selector): array|false => $fields[$selector] ?? false
        );
    }

    // ── update_acf_field ─────────────────────────────────────────────────

    public function testUpdateFieldRejectsEmptyArguments(): void
    {
        $this->assertFalse(AcfHelper::update_acf_field(0, 'name', 'v'));
        $this->assertFalse(AcfHelper::update_acf_field(5, '', 'v'));
    }

    public function testUpdateFieldReturnsFalseWhenFieldUnknown(): void
    {
        $this->assertFalse(AcfHelper::update_acf_field(5, 'unknown', 'v'));
    }

    public function testUpdateFieldSucceeds(): void
    {
        $this->knownFields(['price' => ['key' => 'field_abc']]);
        $this->assertTrue(AcfHelper::update_acf_field(5, 'price', '10'));
        $this->assertArrayNotHasKey('acf', $_POST);
    }

    // ── update_acf_fields ────────────────────────────────────────────────

    public function testUpdateFieldsRejectsInvalidInput(): void
    {
        $this->assertFalse(AcfHelper::update_acf_fields(0, ['a' => 1]));
        $this->assertFalse(AcfHelper::update_acf_fields(5, []));
    }

    public function testUpdateFieldsReturnsFalseWhenNoneResolve(): void
    {
        $this->assertFalse(AcfHelper::update_acf_fields(5, ['unknown' => 'v']));
    }

    public function testUpdateFieldsSucceedsForKnownFields(): void
    {
        $this->knownFields([
            'price' => ['key' => 'field_price'],
            'name'  => ['key' => 'field_name'],
        ]);
        $this->assertTrue(AcfHelper::update_acf_fields(5, ['price' => '10', 'name' => 'x', 'unknown' => 'y']));
    }

    // ── update_acf_field2 ────────────────────────────────────────────────

    public function testUpdateField2RejectsEmptyArguments(): void
    {
        $this->assertFalse(AcfHelper::update_acf_field2(0, 'name', 'v'));
    }

    public function testUpdateField2ReturnsFalseWhenPostMissing(): void
    {
        // Nothing seeded, so get_post() answers null.
        $this->assertFalse(AcfHelper::update_acf_field2(999, 'price', 'v'));
    }

    public function testUpdateField2ReturnsFalseWhenFieldUnknown(): void
    {
        $this->makePost(5, '', 'publish', 'answer');
        $this->assertFalse(AcfHelper::update_acf_field2(5, 'unknown', 'v'));
    }

    public function testUpdateField2Succeeds(): void
    {
        $this->makePost(5, '', 'publish', 'answer');
        $this->knownFields(['price' => ['key' => 'field_price']]);
        $this->assertTrue(AcfHelper::update_acf_field2(5, 'price', '10'));
    }

    public function testUpdateField2ReturnsFalseWhenSaveThrows(): void
    {
        $this->makePost(5, '', 'publish', 'answer');
        $this->knownFields(['price' => ['key' => 'field_price']]);
        Functions\when('acf_save_post')->alias(static function (): bool {
            throw new \RuntimeException('acf save failed');
        });
        try {
            $this->assertFalse(AcfHelper::update_acf_field2(5, 'price', '10'));
        } finally {
        }
    }
}
