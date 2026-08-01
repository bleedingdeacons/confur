<?php

declare(strict_types=1);

namespace Tests;

use BleedingDeacons\WpMocks\TestCase;
use WP_Post;

/**
 * Base case for Confur's unit tests.
 *
 * The WordPress and ACF stand-ins come from bleedingdeacons/wp-mocks, so the
 * shared TestCase handles the Brain Monkey lifecycle, Mockery integration and
 * resetting WpState between tests.
 *
 * What is left here is a translation layer. Confur's tests were written
 * against a harness that kept ACF values, titles and statuses keyed per post;
 * WpState keys fields flat and holds the other two on the seeded WP_Post.
 * These three properties are post-keyed views onto it, so a test still writes
 * $this->fields[42] = [...] and reads it back the same way.
 */
abstract class ConfurTestCase extends TestCase
{
    protected FieldStore $fields;
    protected TitleStore $titles;
    protected StatusStore $statuses;

    protected function setUp(): void
    {
        parent::setUp();

        // Stateless views over WpState, which parent::setUp() has just reset.
        $this->fields = new FieldStore();
        $this->titles = new TitleStore();
        $this->statuses = new StatusStore();
    }

    /**
     * Ids of posts wp_trash_post() has moved to the trash.
     *
     * The old harness kept its own list; the shared stub records the move as a
     * status change, which is what WordPress actually does, so this reads it
     * back out.
     *
     * @return array<int, int>
     */
    protected function trashedPostIds(): array
    {
        return array_values(array_keys(
            array_filter(
                \BleedingDeacons\WpMocks\WpState::$postStatuses,
                static fn (string $status): bool => $status === 'trash'
            )
        ));
    }

    /** Shortcode tags registered via add_shortcode(). */
    protected function registeredShortcodes(): array
    {
        return array_keys(\BleedingDeacons\WpMocks\WpState::$shortcodes);
    }

    /**
     * Seed several posts' ACF fields at once, as postId => [selector => value].
     *
     * The per-post form, $this->fields[42] = [...], is the same thing for one
     * post; this exists for the tests that seeded the whole map in one go.
     *
     * @param array<int, array<string, mixed>> $byPost
     */
    protected function seedFields(array $byPost): void
    {
        foreach ($byPost as $postId => $fields) {
            $this->fields[$postId] = $fields;
        }
    }

    /**
     * Add fields to a post without disturbing what is already there.
     *
     * The old harness kept two stores — $GLOBALS['confur_fields'] behind
     * get_field() and $GLOBALS['confur_allfields'] behind get_fields() — so a
     * test could seed one and then the other. Real ACF has one store, and so
     * does WpState, which means the second seeding would otherwise wipe the
     * first. This is the merging form for those call sites.
     *
     * @param array<string, mixed> $fields
     */
    protected function addFields(int $postId, array $fields): void
    {
        foreach ($fields as $selector => $value) {
            update_field($selector, $value, $postId);
        }
    }

    /**
     * @param array<int, string> $byPost
     */
    protected function seedTitles(array $byPost): void
    {
        foreach ($byPost as $postId => $title) {
            $this->titles[$postId] = $title;
        }
    }

    /**
     * @param array<int, string|false> $byPost
     */
    protected function seedStatuses(array $byPost): void
    {
        foreach ($byPost as $postId => $status) {
            $this->statuses[$postId] = $status;
        }
    }

    /**
     * Seed a post so get_post(), get_the_title() and get_post_status() all
     * resolve it. Returns the post for tests that also want the object.
     */
    protected function makePost(int $id, string $title = '', string $status = 'publish', string $type = 'post'): WP_Post
    {
        $post = new WP_Post([
            'ID' => $id,
            'post_title' => $title !== '' ? $title : 'Title ' . $id,
            'post_status' => $status,
            'post_type' => $type,
        ]);

        \BleedingDeacons\WpMocks\WpState::$posts[$id] = $post;
        \BleedingDeacons\WpMocks\WpState::$postTypes[$id] = $type;
        \BleedingDeacons\WpMocks\WpState::$postStatuses[$id] = $status;

        return $post;
    }
}
