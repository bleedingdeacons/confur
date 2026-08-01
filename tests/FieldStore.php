<?php

declare(strict_types=1);

namespace Tests;

use ArrayAccess;
use BleedingDeacons\WpMocks\WpState;
use ReturnTypeWillChange;

/**
 * Post-keyed views onto WpState, so Confur's tests keep the shape they were
 * written in.
 *
 * The old harness kept ACF values as $GLOBALS['confur_fields'][$postId][$selector],
 * and titles and statuses in the same per-post style. WpState keys fields flat
 * — "postId|selector" — and holds titles and statuses on the seeded WP_Post.
 *
 * Flattening a hundred and thirty call sites by hand would have been a lot of
 * churn for no gain, and would have lost the per-post grouping that makes
 * these tests readable. These adapters do the conversion instead: a test still
 * writes $this->fields[42] = [...] and reads it back the same way, while the
 * code under test sees ordinary get_field() answers.
 */
final class FieldStore implements ArrayAccess
{
    public function offsetExists(mixed $postId): bool
    {
        return $this->offsetGet($postId) !== [];
    }

    /**
     * @return array<string, mixed>
     */
    #[ReturnTypeWillChange]
    public function offsetGet(mixed $postId): array
    {
        $prefix = ((int) $postId) . '|';
        $out = [];

        foreach (WpState::$fields as $key => $value) {
            if (str_starts_with((string) $key, $prefix)) {
                $out[substr((string) $key, strlen($prefix))] = $value;
            }
        }

        return $out;
    }

    /**
     * Replaces the post's fields outright, matching the assignment it stands
     * in for: seeding the same post twice means the second call wins.
     */
    public function offsetSet(mixed $postId, mixed $fields): void
    {
        $this->offsetUnset($postId);

        foreach ((array) $fields as $selector => $value) {
            update_field((string) $selector, $value, (int) $postId);
        }
    }

    public function offsetUnset(mixed $postId): void
    {
        $prefix = ((int) $postId) . '|';

        foreach (array_keys(WpState::$fields) as $key) {
            if (str_starts_with((string) $key, $prefix)) {
                unset(WpState::$fields[$key]);
            }
        }
    }
}
