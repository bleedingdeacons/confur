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

    covers(HtmlHelper::class);

    it('generates a PDF link', function () {
        $html = HtmlHelper::generatePdfLink('http://x/f.pdf', 'f.pdf', 'Download');
        expect($html)->toContain('href="http://x/f.pdf"', 'download="f.pdf"', '>Download</a>');
    });

    it('creates a link', function () {
        $html = HtmlHelper::createLink('http://x', 'btn', 'Go');
        expect($html)->toContain('class="btn"', 'href="http://x"', '>Go</a>');
    });

    it('creates an email address with and without a subject', function () {
        expect(HtmlHelper::createEmailToAddress('a@b.com'))->toBe('mailto:a@b.com')
            ->and(HtmlHelper::createEmailToAddress('a@b.com', 'Hi'))->toBe('mailto:a@b.com?subject=Hi');
    });

    it('creates an email anchor', function () {
        $html = HtmlHelper::createEmailAnchor('a@b.com', 'Hi', 'Mail');
        expect($html)->toContain('mailto:a@b.com?subject=Hi', '>Mail</a>');
    });

    it('creates a phone address', function () {
        expect(HtmlHelper::createPhoneToAddress('0123'))->toBe('tel:0123');
    });

    it('creates a meeting link', function () {
        expect(HtmlHelper::createMeetingLink('my-group'))->toBe('/meetings/?meeting=my-group');
    });

    // ── Escaping contract ────────────────────────────────────────────
    //
    // These helpers feed the Confur status screen, which renders contact
    // names, telephone numbers and registration email addresses read from
    // post meta. createLink() previously interpolated all three of its
    // arguments raw, so a payload stored in a meeting's contact fields
    // executed in the browser of anyone who opened that screen.
    describe('escaping contract', function () {
        it('escapes a script in createLink content', function () {
            $html = HtmlHelper::createLink('tel:0117', '', '<script>alert(1)</script>');

            // The tags are what matter: kses strips them and leaves the body
            // as inert text, which is the correct outcome rather than a miss.
            expect($html)->not->toContain('<script')
                ->not->toContain('</script>');
        });

        it('escapes a quote breakout in the createLink href', function () {
            $html = HtmlHelper::createLink('tel:" onmouseover="alert(1)', '', 'call');

            expect($html)->not->toContain('onmouseover="alert(1)"');
        });

        it('escapes a quote breakout in the createLink class', function () {
            $html = HtmlHelper::createLink('https://example.org', '" onfocus="alert(1)', 'x');

            expect($html)->not->toContain('onfocus="alert(1)"');
        });

        it('rejects the javascript scheme in createLink', function () {
            $html = HtmlHelper::createLink('javascript:alert(1)', '', 'x');

            expect($html)->not->toContain('javascript:');
        });

        it('preserves mailto and tel in createLink', function () {
            expect(HtmlHelper::createLink('mailto:a@example.org', '', 'mail'))->toContain('mailto:a@example.org')
                ->and(HtmlHelper::createLink('tel:01179', '', 'call'))->toContain('tel:01179');
        });

        it('escapes createEmailAnchor content', function () {
            $html = HtmlHelper::createEmailAnchor('a@example.org', null, '<script>alert(1)</script>');

            expect($html)->not->toContain('<script');
        });

        // Pure string building — no WordPress function involved, so this one
        // holds regardless of which escaping doubles are in play.
        it('encodes the subject in createEmailToAddress', function () {
            $url = HtmlHelper::createEmailToAddress('a@example.org', 'Questions & Answers');

            expect($url)->not->toContain(' ')
                ->toContain('Questions%20%26%20Answers');
        });

        it('escapes the content and URL in generatePdfLink', function () {
            $html = HtmlHelper::generatePdfLink('javascript:alert(1)', 'r.pdf', '<script>alert(1)</script>');

            expect($html)->not->toContain('javascript:')
                ->not->toContain('<script');
        });
    });
}
