<?php

namespace Tests\Unit\Utils;

use Confur\Utils\AcfHelper;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Confur\Utils\AcfHelper
 */
class AcfHelperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['confur_acf_fieldobj'] = [];
        $GLOBALS['confur_posts'] = [];
        unset($_POST['acf']);
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
        $GLOBALS['confur_acf_fieldobj']['price'] = ['key' => 'field_abc'];
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
        $GLOBALS['confur_acf_fieldobj'] = [
            'price' => ['key' => 'field_price'],
            'name'  => ['key' => 'field_name'],
        ];
        $this->assertTrue(AcfHelper::update_acf_fields(5, ['price' => '10', 'name' => 'x', 'unknown' => 'y']));
    }

    // ── update_acf_field2 ────────────────────────────────────────────────

    public function testUpdateField2RejectsEmptyArguments(): void
    {
        $this->assertFalse(AcfHelper::update_acf_field2(0, 'name', 'v'));
    }

    public function testUpdateField2ReturnsFalseWhenPostMissing(): void
    {
        $GLOBALS['confur_posts'] = [];
        $this->assertFalse(AcfHelper::update_acf_field2(999, 'price', 'v'));
    }

    public function testUpdateField2ReturnsFalseWhenFieldUnknown(): void
    {
        $GLOBALS['confur_posts'] = [(object) ['ID' => 5, 'post_type' => 'answer']];
        $this->assertFalse(AcfHelper::update_acf_field2(5, 'unknown', 'v'));
    }

    public function testUpdateField2Succeeds(): void
    {
        $GLOBALS['confur_posts'] = [(object) ['ID' => 5, 'post_type' => 'answer']];
        $GLOBALS['confur_acf_fieldobj']['price'] = ['key' => 'field_price'];
        $this->assertTrue(AcfHelper::update_acf_field2(5, 'price', '10'));
    }

    public function testUpdateField2ReturnsFalseWhenSaveThrows(): void
    {
        $GLOBALS['confur_posts'] = [(object) ['ID' => 5, 'post_type' => 'answer']];
        $GLOBALS['confur_acf_fieldobj']['price'] = ['key' => 'field_price'];
        $GLOBALS['confur_acf_save_throws'] = true;
        try {
            $this->assertFalse(AcfHelper::update_acf_field2(5, 'price', '10'));
        } finally {
            unset($GLOBALS['confur_acf_save_throws']);
        }
    }
}
