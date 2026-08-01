<?php

declare(strict_types=1);

namespace Tests;

use ArrayAccess;
use BleedingDeacons\WpMocks\WpState;
use ReturnTypeWillChange;

/**
 * Post titles, seeding a WP_Post on first write so get_the_title() resolves.
 */
final class TitleStore implements ArrayAccess
{
    public function offsetExists(mixed $postId): bool
    {
        return isset(WpState::$posts[(int) $postId]);
    }

    #[ReturnTypeWillChange]
    public function offsetGet(mixed $postId): string
    {
        return (string) (WpState::$posts[(int) $postId]->post_title ?? '');
    }

    public function offsetSet(mixed $postId, mixed $title): void
    {
        $id = (int) $postId;

        if (!isset(WpState::$posts[$id])) {
            WpState::addPost($id, ['post_title' => (string) $title]);

            return;
        }

        WpState::$posts[$id]->post_title = (string) $title;
    }

    public function offsetUnset(mixed $postId): void
    {
        unset(WpState::$posts[(int) $postId]);
    }
}
