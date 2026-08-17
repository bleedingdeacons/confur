<?php

/**
 * Faithful-enough escaping doubles, defined in HtmlHelper's own namespace.
 *
 * wp-mocks stubs esc_attr(), esc_url() and wp_kses_post() as pass-through
 * identity functions (stubs/wordpress.php declares them with eval(), so
 * Patchwork cannot instrument them either). Under those stubs an escaping
 * bug is invisible: output is byte-identical whether or not the production
 * code escapes anything.
 *
 * PHP resolves an unqualified call inside `namespace Confur\Utils` against
 * that namespace before falling back to global, so declaring these here
 * intercepts HtmlHelper's calls without touching the global stubs.
 *
 * These are deliberately minimal — enough to prove the payload is
 * neutralised, not a reimplementation of WordPress.
 */

namespace Confur\Utils {

    if (!function_exists('Confur\Utils\esc_attr')) {
        function esc_attr(string $text = ''): string
        {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        }
    }

    if (!function_exists('Confur\Utils\esc_url')) {
        /** @param list<string> $protocols */
        function esc_url(string $url = '', array $protocols = []): string
        {
            if (preg_match('#^([a-z0-9+.-]+):#i', $url, $m) && !in_array(strtolower($m[1]), $protocols, true)) {
                return '';
            }

            return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        }
    }

    if (!function_exists('Confur\Utils\wp_kses_post')) {
        function wp_kses_post(string $text = ''): string
        {
            return strip_tags($text, '<b><i><em><strong><br><p><span>');
        }
    }
}

namespace Tests\Unit\Utils {

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

        // ── Escaping contract ────────────────────────────────────────────
        //
        // These helpers feed the Confur status screen, which renders contact
        // names, telephone numbers and registration email addresses read from
        // post meta. createLink() previously interpolated all three of its
        // arguments raw, so a payload stored in a meeting's contact fields
        // executed in the browser of anyone who opened that screen.

        public function testCreateLinkEscapesScriptInContent(): void
        {
            $html = HtmlHelper::createLink('tel:0117', '', '<script>alert(1)</script>');

            // The tags are what matter: kses strips them and leaves the body
            // as inert text, which is the correct outcome rather than a miss.
            $this->assertStringNotContainsString('<script', $html);
            $this->assertStringNotContainsString('</script>', $html);
        }

        public function testCreateLinkEscapesQuoteBreakoutInHref(): void
        {
            $html = HtmlHelper::createLink('tel:" onmouseover="alert(1)', '', 'call');

            $this->assertStringNotContainsString('onmouseover="alert(1)"', $html);
        }

        public function testCreateLinkEscapesQuoteBreakoutInClass(): void
        {
            $html = HtmlHelper::createLink('https://example.org', '" onfocus="alert(1)', 'x');

            $this->assertStringNotContainsString('onfocus="alert(1)"', $html);
        }

        public function testCreateLinkRejectsJavascriptScheme(): void
        {
            $html = HtmlHelper::createLink('javascript:alert(1)', '', 'x');

            $this->assertStringNotContainsString('javascript:', $html);
        }

        public function testCreateLinkPreservesMailtoAndTel(): void
        {
            $this->assertStringContainsString(
                'mailto:a@example.org',
                HtmlHelper::createLink('mailto:a@example.org', '', 'mail')
            );
            $this->assertStringContainsString(
                'tel:01179',
                HtmlHelper::createLink('tel:01179', '', 'call')
            );
        }

        public function testCreateEmailAnchorEscapesContent(): void
        {
            $html = HtmlHelper::createEmailAnchor('a@example.org', null, '<script>alert(1)</script>');

            $this->assertStringNotContainsString('<script', $html);
        }

        /**
         * Pure string building — no WordPress function involved, so this one
         * holds regardless of which escaping doubles are in play.
         */
        public function testCreateEmailToAddressEncodesSubject(): void
        {
            $url = HtmlHelper::createEmailToAddress('a@example.org', 'Questions & Answers');

            $this->assertStringNotContainsString(' ', $url);
            $this->assertStringContainsString('Questions%20%26%20Answers', $url);
        }

        public function testGeneratePdfLinkEscapesContentAndUrl(): void
        {
            $html = HtmlHelper::generatePdfLink('javascript:alert(1)', 'r.pdf', '<script>alert(1)</script>');

            $this->assertStringNotContainsString('javascript:', $html);
            $this->assertStringNotContainsString('<script', $html);
        }
    }
}
