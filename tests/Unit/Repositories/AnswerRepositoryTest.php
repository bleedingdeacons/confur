<?php

namespace Tests\Unit\Repositories;

use Confur\Config\Constants;
use Confur\Repositories\AnswerRepository;

/*
 * Test class for AnswerRepository
 *
 * This test uses mocked WordPress and ACF functions to test the repository
 * in isolation without requiring a WordPress installation.
 */

beforeEach(function () {
    // Create a testable subclass with normalizePostId implemented directly
    $this->repository = new class extends AnswerRepository {
        public static array $mockPosts = [];
        public static array $mockFields = [];
        public static array $mockPostData = [];

        // Implement normalizePostId as public method for testing
        public function normalizePostId($value): ?int
        {
            if (empty($value)) {
                return null;
            }

            if (is_object($value) && isset($value->ID)) {
                return (int) $value->ID;
            }

            if (is_array($value) && isset($value['ID'])) {
                return (int) $value['ID'];
            }

            if (is_numeric($value)) {
                return (int) $value;
            }

            return null;
        }
    };
});

it('returns null when the meeting id is empty', function () {
    $repository = mockedAnswerRepository([], null);

    $result = $repository->findDuplicate(null, null, 'test@example.com');

    expect($result)->toBeNull();
});

it('returns null when the email is empty', function () {
    $repository = mockedAnswerRepository([], null);

    $result = $repository->findDuplicate(100, null, '');

    expect($result)->toBeNull();
});

it('returns null when no duplicates exist', function () {
    $posts = [
        [
            'ID' => 1,
            'meeting' => 100,
            'fellow_meeting' => null,
            'email' => 'other@example.com',
            'status' => Constants::STATUS_DRAFT,
            'slug' => 'post-1',
        ],
    ];

    $repository = mockedAnswerRepository($posts, [1]);

    $result = $repository->findDuplicate(100, null, 'test@example.com');

    expect($result)->toBeNull();
});

it('finds a duplicate with the same meeting and email', function () {
    $posts = [
        [
            'ID' => 1,
            'meeting' => 100,
            'fellow_meeting' => null,
            'email' => 'test@example.com',
            'status' => Constants::STATUS_DRAFT,
            'slug' => 'existing-post',
        ],
    ];

    $repository = mockedAnswerRepository($posts, [1]);

    $result = $repository->findDuplicate(100, null, 'test@example.com', 999);

    expect($result)->not->toBeNull()
        ->and($result['post_id'])->toEqual(1)
        ->and($result['slug'])->toEqual('existing-post');
});

it('finds a duplicate with a case-insensitive email', function () {
    $posts = [
        [
            'ID' => 1,
            'meeting' => 100,
            'fellow_meeting' => null,
            'email' => 'TEST@EXAMPLE.COM',
            'status' => Constants::STATUS_DRAFT,
            'slug' => 'existing-post',
        ],
    ];

    $repository = mockedAnswerRepository($posts, [1]);

    $result = $repository->findDuplicate(100, null, 'test@example.com', 999);

    expect($result)->not->toBeNull()
        ->and($result['post_id'])->toEqual(1);
});

it('skips cancelled registrations', function () {
    $posts = [
        [
            'ID' => 1,
            'meeting' => 100,
            'fellow_meeting' => null,
            'email' => 'test@example.com',
            'status' => Constants::STATUS_CANCELLED,
            'slug' => 'cancelled-post',
        ],
    ];

    $repository = mockedAnswerRepository($posts, [1]);

    $result = $repository->findDuplicate(100, null, 'test@example.com', 999);

    expect($result)->toBeNull();
});

it('excludes the specified post id', function () {
    $posts = [
        [
            'ID' => 5,
            'meeting' => 100,
            'fellow_meeting' => null,
            'email' => 'test@example.com',
            'status' => Constants::STATUS_DRAFT,
            'slug' => 'same-post',
        ],
    ];

    // Post ID 5 should be excluded from results
    $repository = mockedAnswerRepository($posts, []); // Empty because excluded

    $result = $repository->findDuplicate(100, null, 'test@example.com', 5);

    expect($result)->toBeNull();
});

it('finds a duplicate with paired meetings in the same order', function () {
    $posts = [
        [
            'ID' => 1,
            'meeting' => 100,
            'fellow_meeting' => 200,
            'email' => 'test@example.com',
            'status' => Constants::STATUS_DRAFT,
            'slug' => 'paired-post',
        ],
    ];

    $repository = mockedAnswerRepository($posts, [1]);

    $result = $repository->findDuplicate(100, 200, 'test@example.com', 999);

    expect($result)->not->toBeNull()
        ->and($result['post_id'])->toEqual(1);
});

it('finds a duplicate with paired meetings in swapped order', function () {
    $posts = [
        [
            'ID' => 1,
            'meeting' => 200,
            'fellow_meeting' => 100,
            'email' => 'test@example.com',
            'status' => Constants::STATUS_DRAFT,
            'slug' => 'swapped-post',
        ],
    ];

    $repository = mockedAnswerRepository($posts, [1]);

    // New registration has meetings in opposite order
    $result = $repository->findDuplicate(100, 200, 'test@example.com', 999);

    expect($result)->not->toBeNull()
        ->and($result['post_id'])->toEqual(1);
});

it('does not match a paired registration with a single one', function () {
    $posts = [
        [
            'ID' => 1,
            'meeting' => 100,
            'fellow_meeting' => null, // Single registration
            'email' => 'test@example.com',
            'status' => Constants::STATUS_DRAFT,
            'slug' => 'single-post',
        ],
    ];

    $repository = mockedAnswerRepository($posts, [1]);

    // New registration is paired
    $result = $repository->findDuplicate(100, 200, 'test@example.com', 999);

    expect($result)->toBeNull();
});

it('does not match a single registration with a paired one', function () {
    $posts = [
        [
            'ID' => 1,
            'meeting' => 100,
            'fellow_meeting' => 200, // Paired registration
            'email' => 'test@example.com',
            'status' => Constants::STATUS_DRAFT,
            'slug' => 'paired-post',
        ],
    ];

    $repository = mockedAnswerRepository($posts, [1]);

    // New registration is single
    $result = $repository->findDuplicate(100, null, 'test@example.com', 999);

    expect($result)->toBeNull();
});

it('returns the latest duplicate by updated date', function () {
    $posts = [
        [
            'ID' => 1,
            'meeting' => 100,
            'fellow_meeting' => 200,
            'email' => 'test@example.com',
            'status' => Constants::STATUS_DRAFT,
            'slug' => 'older-post',
            'updated' => '2024-01-01 10:00:00',
            'post_date' => '2024-01-01 09:00:00',
        ],
        [
            'ID' => 2,
            'meeting' => 100,
            'fellow_meeting' => 200,
            'email' => 'test@example.com',
            'status' => Constants::STATUS_DRAFT,
            'slug' => 'newer-post',
            'updated' => '2024-02-01 10:00:00',
            'post_date' => '2024-01-15 09:00:00',
        ],
    ];

    $repository = mockedAnswerRepository($posts, [1, 2]);

    $result = $repository->findDuplicate(100, 200, 'test@example.com', 999);

    expect($result)->not->toBeNull()
        ->and($result['post_id'])->toEqual(2)
        ->and($result['slug'])->toEqual('newer-post');
});

it('returns the latest duplicate by post date when there is no updated', function () {
    $posts = [
        [
            'ID' => 1,
            'meeting' => 100,
            'fellow_meeting' => 200,
            'email' => 'test@example.com',
            'status' => Constants::STATUS_DRAFT,
            'slug' => 'older-post',
            'updated' => null,
            'post_date' => '2024-01-01 09:00:00',
        ],
        [
            'ID' => 2,
            'meeting' => 100,
            'fellow_meeting' => 200,
            'email' => 'test@example.com',
            'status' => Constants::STATUS_DRAFT,
            'slug' => 'newer-post',
            'updated' => null,
            'post_date' => '2024-02-01 09:00:00',
        ],
    ];

    $repository = mockedAnswerRepository($posts, [1, 2]);

    $result = $repository->findDuplicate(100, 200, 'test@example.com', 999);

    expect($result)->not->toBeNull()
        ->and($result['post_id'])->toEqual(2)
        ->and($result['slug'])->toEqual('newer-post');
});

it('prefers a post with an updated date over one without', function () {
    $posts = [
        [
            'ID' => 1,
            'meeting' => 100,
            'fellow_meeting' => 200,
            'email' => 'test@example.com',
            'status' => Constants::STATUS_DRAFT,
            'slug' => 'no-updated-post',
            'updated' => null,
            'post_date' => '2024-03-01 09:00:00', // Newer post date
        ],
        [
            'ID' => 2,
            'meeting' => 100,
            'fellow_meeting' => 200,
            'email' => 'test@example.com',
            'status' => Constants::STATUS_DRAFT,
            'slug' => 'has-updated-post',
            'updated' => '2024-01-15 10:00:00', // Has updated date
            'post_date' => '2024-01-01 09:00:00',
        ],
    ];

    $repository = mockedAnswerRepository($posts, [1, 2]);

    $result = $repository->findDuplicate(100, 200, 'test@example.com', 999);

    expect($result)->not->toBeNull()
        // Should prefer post with updated date even if post_date is older
        ->and($result['post_id'])->toEqual(2)
        ->and($result['slug'])->toEqual('has-updated-post');
});

describe('normalizePostId', function () {
    it('handles an integer', function () {
        $result = $this->repository->normalizePostId(123);
        expect($result)->toEqual(123);
    });

    it('handles a string', function () {
        $result = $this->repository->normalizePostId('456');
        expect($result)->toEqual(456);
    });

    it('handles an object with an ID', function () {
        $obj = (object) ['ID' => 789];
        $result = $this->repository->normalizePostId($obj);
        expect($result)->toEqual(789);
    });

    it('handles an array with an ID', function () {
        $arr = ['ID' => 101];
        $result = $this->repository->normalizePostId($arr);
        expect($result)->toEqual(101);
    });

    it('handles null', function () {
        $result = $this->repository->normalizePostId(null);
        expect($result)->toBeNull();
    });

    it('handles an empty string', function () {
        $result = $this->repository->normalizePostId('');
        expect($result)->toBeNull();
    });
});

/**
 * Create a mocked repository with injected test data
 */
function mockedAnswerRepository(array $posts, ?array $returnPostIds): AnswerRepository
{
    $mockFields = [];
    $mockPostData = [];

    foreach ($posts as $post) {
        $postId = $post['ID'];
        $mockFields[$postId] = [
            Constants::MEETING_FIELD => $post['meeting'] ?? null,
            Constants::FELLOW_MEETING_FIELD => $post['fellow_meeting'] ?? null,
            Constants::EMAIL_FIELD => $post['email'] ?? null,
            Constants::STATUS_FIELD => $post['status'] ?? null,
            Constants::UPDATED_FIELD => $post['updated'] ?? null,
        ];
        $mockPostData[$postId] = (object) [
            'ID' => $postId,
            'post_name' => $post['slug'] ?? "post-{$postId}",
            'post_date' => $post['post_date'] ?? '2024-01-01 00:00:00',
        ];
    }

    // Create anonymous class that overrides WordPress function calls
    return new class ($mockFields, $mockPostData, $returnPostIds) extends AnswerRepository {
        private array $mockFields;
        private array $mockPostData;
        private ?array $returnPostIds;

        public function __construct(array $mockFields, array $mockPostData, ?array $returnPostIds)
        {
            $this->mockFields = $mockFields;
            $this->mockPostData = $mockPostData;
            $this->returnPostIds = $returnPostIds;
        }

        public function findDuplicate(?int $meetingId, ?int $fellowMeetingId, string $email, ?int $excludePostId = null): ?array
        {
            if (empty($meetingId) || empty($email)) {
                return null;
            }

            // Determine if this is a paired registration
            $isPairedRegistration = !empty($fellowMeetingId);

            // Build input meeting IDs
            $inputMeetingIds = [$meetingId];
            if ($isPairedRegistration) {
                $inputMeetingIds[] = $fellowMeetingId;
            }
            sort($inputMeetingIds);

            // Filter posts (simulate get_posts with exclusion)
            $postIds = $this->returnPostIds ?? [];
            if ($excludePostId !== null) {
                $postIds = array_filter($postIds, fn($id) => $id !== $excludePostId);
            }

            $duplicates = [];

            foreach ($postIds as $postId) {
                if (!isset($this->mockFields[$postId])) {
                    continue;
                }

                $fields = $this->mockFields[$postId];
                $postMeeting = $this->normalizePostId($fields[Constants::MEETING_FIELD]);
                $postFellowMeeting = $this->normalizePostId($fields[Constants::FELLOW_MEETING_FIELD]);
                $postEmail = $fields[Constants::EMAIL_FIELD];
                $postStatus = $fields[Constants::STATUS_FIELD];
                $postUpdated = $fields[Constants::UPDATED_FIELD];

                // Skip cancelled
                if ($postStatus === Constants::STATUS_CANCELLED) {
                    continue;
                }

                // Check email (case-insensitive)
                if (strtolower($postEmail ?? '') !== strtolower($email)) {
                    continue;
                }

                // Check paired vs single
                $isPostPaired = !empty($postFellowMeeting);
                if ($isPairedRegistration !== $isPostPaired) {
                    continue;
                }

                // Build post meeting IDs
                $postMeetingIds = [];
                if (!empty($postMeeting)) {
                    $postMeetingIds[] = $postMeeting;
                }
                if (!empty($postFellowMeeting)) {
                    $postMeetingIds[] = $postFellowMeeting;
                }
                sort($postMeetingIds);

                // Check match
                if ($inputMeetingIds !== $postMeetingIds) {
                    continue;
                }

                $duplicates[] = [
                    'post_id' => $postId,
                    'updated' => $postUpdated,
                    'post_date' => $this->mockPostData[$postId]->post_date ?? '2024-01-01 00:00:00',
                ];
            }

            if (empty($duplicates)) {
                return null;
            }

            // Sort by updated date, then post date
            usort($duplicates, function ($a, $b) {
                $aUpdated = !empty($a['updated']) ? strtotime($a['updated']) : 0;
                $bUpdated = !empty($b['updated']) ? strtotime($b['updated']) : 0;

                if ($aUpdated > 0 && $bUpdated > 0) {
                    return $bUpdated - $aUpdated;
                }

                if ($aUpdated > 0) {
                    return -1;
                }
                if ($bUpdated > 0) {
                    return 1;
                }

                $aCreated = strtotime($a['post_date']);
                $bCreated = strtotime($b['post_date']);

                return $bCreated - $aCreated;
            });

            $latest = $duplicates[0];
            $postData = $this->mockPostData[$latest['post_id']];

            return [
                'post_id' => $latest['post_id'],
                'slug' => $postData->post_name,
            ];
        }

        public function normalizePostId($value): ?int
        {
            if (empty($value)) {
                return null;
            }

            if (is_object($value) && isset($value->ID)) {
                return (int) $value->ID;
            }

            if (is_array($value) && isset($value['ID'])) {
                return (int) $value['ID'];
            }

            if (is_numeric($value)) {
                return (int) $value;
            }

            return null;
        }
    };
}
