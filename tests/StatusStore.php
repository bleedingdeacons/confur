<?php

declare(strict_types=1);

namespace Tests;

use ArrayAccess;
use BleedingDeacons\WpMocks\WpState;
use ReturnTypeWillChange;

/**
 * Post statuses.
 *
 * get_post_status() answers false for a post that does not exist, and Confur
 * branches on exactly that, so writing false here removes the post rather than
 * storing a nonsense status.
 */
final class StatusStore implements ArrayAccess
{
    public function offsetExists(mixed $postId): bool
    {
        return isset(WpState::$postStatuses[(int) $postId]);
    }

    #[ReturnTypeWillChange]
    public function offsetGet(mixed $postId): string|false
    {
        return WpState::$postStatuses[(int) $postId] ?? false;
    }

    public function offsetSet(mixed $postId, mixed $status): void
    {
        $id = (int) $postId;

        if ($status === false) {
            unset(WpState::$postStatuses[$id], WpState::$posts[$id]);

            return;
        }

        if (!isset(WpState::$posts[$id])) {
            WpState::addPost($id, ['post_status' => (string) $status]);

            return;
        }

        WpState::$posts[$id]->post_status = (string) $status;
        WpState::$postStatuses[$id] = (string) $status;
    }

    public function offsetUnset(mixed $postId): void
    {
        unset(WpState::$postStatuses[(int) $postId]);
    }
}
