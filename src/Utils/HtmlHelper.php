<?php

namespace Confur\Utils;

if (!class_exists('HtmlHelper')) {
    /**
     * HTML helper utilities
     */
    class HtmlHelper
    {
        /**
         * Protocols every link built here may use.
         *
         * esc_url() defaults to wp_allowed_protocols(), which is far wider
         * than anything this class emits and is filterable by other plugins.
         * Naming the three schemes we actually produce keeps a filtered
         * allow-list from widening what a link here can point at.
         *
         * @var list<string>
         */
        private const ALLOWED_PROTOCOLS = ['http', 'https', 'mailto', 'tel'];

        /**
         * Generate PDF link
         *
         * @param string $url PDF URL
         * @param string $name PDF filename
         * @param string $content Link content
         * @return string Rendered HTML
         */
        public static function generatePdfLink(string $url, string $name, string $content): string
        {
            return sprintf(
                '<a href="%s" download="%s" type="application/pdf" target="_blank" rel="noreferrer noopener">%s</a>',
                esc_url($url, self::ALLOWED_PROTOCOLS),
                esc_attr($name),
                wp_kses_post($content)
            );
        }

        /**
         * Create generic link
         *
         * @param string $href Link href
         * @param string $class CSS class
         * @param string|null $content Link content
         * @return string Rendered HTML
         */
        public static function createLink(string $href, string $class = '', ?string $content = null): string
        {
            return sprintf(
                '<a target="_blank" rel="noreferrer noopener" class="%s" href="%s">%s</a>',
                esc_attr($class),
                esc_url($href, self::ALLOWED_PROTOCOLS),
                wp_kses_post((string) $content)
            );
        }

        /**
         * Create email mailto address
         *
         * @param string $address Email address
         * @param string|null $subject Email subject
         * @return string Email mailto URL
         */
        public static function createEmailToAddress(string $address, ?string $subject = null): string
        {
            if (!empty($subject)) {
                // Encoded, not interpolated: an unencoded subject containing
                // '&' or '?' silently corrupts the query, and one containing
                // a quote would otherwise have to be caught downstream.
                $address = $address . '?subject=' . rawurlencode($subject);
            }

            return 'mailto:' . $address;
        }

        /**
         * Create email anchor
         *
         * @param string $address Email address
         * @param string|null $subject Email subject
         * @param string|null $content Link content
         * @return string Rendered HTML
         */
        public static function createEmailAnchor(string $address, ?string $subject = null, ?string $content = null): string
        {
            $target = self::createEmailToAddress($address, $subject);

            return sprintf(
                '<a href="%s">%s</a>',
                esc_url($target, self::ALLOWED_PROTOCOLS),
                wp_kses_post((string) $content)
            );
        }

        /**
         * Create phone tel address
         *
         * @param string $number Phone number
         * @return string Phone tel URL
         */
        public static function createPhoneToAddress(string $number): string
        {
            return 'tel:' . $number;
        }

        /**
         * Create meeting link
         *
         * @param string $slug Meeting slug
         * @return string Meeting URL
         */
        public static function createMeetingLink(string $slug): string
        {
            return '/meetings/?meeting=' . $slug;
        }
    }
}
