<?php

namespace Tests\Unit\Utils;

use Confur\Utils\HtmlHelper;
use Tests\ConfurTestCase;

/**
 * @covers \Confur\Utils\HtmlHelper
 */
class HtmlHelperTest extends ConfurTestCase
{
    public function testGeneratePdfLink(): void
    {
        $html = HtmlHelper::generatePdfLink('http://x/f.pdf', 'f.pdf', 'Download');
        $this->assertStringContainsString('href="http://x/f.pdf"', $html);
        $this->assertStringContainsString('download="f.pdf"', $html);
        $this->assertStringContainsString('>Download</a>', $html);
    }

    public function testCreateLink(): void
    {
        $html = HtmlHelper::createLink('http://x', 'btn', 'Go');
        $this->assertStringContainsString('class="btn"', $html);
        $this->assertStringContainsString('href="http://x"', $html);
        $this->assertStringContainsString('>Go</a>', $html);
    }

    public function testCreateEmailToAddressWithAndWithoutSubject(): void
    {
        $this->assertSame('mailto:a@b.com', HtmlHelper::createEmailToAddress('a@b.com'));
        $this->assertSame('mailto:a@b.com?subject=Hi', HtmlHelper::createEmailToAddress('a@b.com', 'Hi'));
    }

    public function testCreateEmailAnchor(): void
    {
        $html = HtmlHelper::createEmailAnchor('a@b.com', 'Hi', 'Mail');
        $this->assertStringContainsString('mailto:a@b.com?subject=Hi', $html);
        $this->assertStringContainsString('>Mail</a>', $html);
    }

    public function testCreatePhoneToAddress(): void
    {
        $this->assertSame('tel:0123', HtmlHelper::createPhoneToAddress('0123'));
    }

    public function testCreateMeetingLink(): void
    {
        $this->assertSame('/meetings/?meeting=my-group', HtmlHelper::createMeetingLink('my-group'));
    }
}
