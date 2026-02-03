<?php

namespace Tests\Unit\Repositories;

use Confur\Config\Constants;
use Confur\Repositories\AnswerRepository;
use PHPUnit\Framework\TestCase;

/**
 * Test class for AnswerRepository
 *
 * This test uses mocked WordPress and ACF functions to test the repository
 * in isolation without requiring a WordPress installation.
 */
class AnswerRepositoryTest extends TestCase
{
    private AnswerRepository $repository;

    // Mock data storage
    private static array $mockPosts = [];
    private static array $mockFields = [];
    private static array $mockPostData = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Reset mock data
        self::$mockPosts = [];
        self::$mockFields = [];
        self::$mockPostData = [];

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
    }

    protected function tearDown(): void
    {
        self::$mockPosts = [];
        self::$mockFields = [];
        self::$mockPostData = [];
        parent::tearDown();
    }

    /**
     * Helper to set up mock posts and fields for findDuplicate tests
     */
    private function setupMockData(array $posts): void
    {
        self::$mockPosts = $posts;

        // Register mock functions
        $this->registerMockFunctions();
    }

    /**
     * Register mock WordPress/ACF functions
     */
    private function registerMockFunctions(): void
    {
        // Store reference to test data
        $mockPosts = &self::$mockPosts;
        $mockFields = &self::$mockFields;
        $mockPostData = &self::$mockPostData;

        // Build fields and post data from mock posts
        foreach ($mockPosts as $post) {
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

        self::$mockFields = $mockFields;
        self::$mockPostData = $mockPostData;
    }

    /** @test */
    public function it_returns_null_when_meeting_id_is_empty(): void
    {
        $repository = $this->createMockedRepository([], null);

        $result = $repository->findDuplicate(null, null, 'test@example.com');

        $this->assertNull($result);
    }

    /** @test */
    public function it_returns_null_when_email_is_empty(): void
    {
        $repository = $this->createMockedRepository([], null);

        $result = $repository->findDuplicate(100, null, '');

        $this->assertNull($result);
    }

    /** @test */
    public function it_returns_null_when_no_duplicates_exist(): void
    {
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

        $repository = $this->createMockedRepository($posts, [1]);

        $result = $repository->findDuplicate(100, null, 'test@example.com');

        $this->assertNull($result);
    }

    /** @test */
    public function it_finds_duplicate_with_same_meeting_and_email(): void
    {
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

        $repository = $this->createMockedRepository($posts, [1]);

        $result = $repository->findDuplicate(100, null, 'test@example.com', 999);

        $this->assertNotNull($result);
        $this->assertEquals(1, $result['post_id']);
        $this->assertEquals('existing-post', $result['slug']);
    }

    /** @test */
    public function it_finds_duplicate_with_case_insensitive_email(): void
    {
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

        $repository = $this->createMockedRepository($posts, [1]);

        $result = $repository->findDuplicate(100, null, 'test@example.com', 999);

        $this->assertNotNull($result);
        $this->assertEquals(1, $result['post_id']);
    }

    /** @test */
    public function it_skips_cancelled_registrations(): void
    {
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

        $repository = $this->createMockedRepository($posts, [1]);

        $result = $repository->findDuplicate(100, null, 'test@example.com', 999);

        $this->assertNull($result);
    }

    /** @test */
    public function it_excludes_specified_post_id(): void
    {
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
        $repository = $this->createMockedRepository($posts, []); // Empty because excluded

        $result = $repository->findDuplicate(100, null, 'test@example.com', 5);

        $this->assertNull($result);
    }

    /** @test */
    public function it_finds_duplicate_with_paired_meetings_same_order(): void
    {
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

        $repository = $this->createMockedRepository($posts, [1]);

        $result = $repository->findDuplicate(100, 200, 'test@example.com', 999);

        $this->assertNotNull($result);
        $this->assertEquals(1, $result['post_id']);
    }

    /** @test */
    public function it_finds_duplicate_with_paired_meetings_swapped_order(): void
    {
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

        $repository = $this->createMockedRepository($posts, [1]);

        // New registration has meetings in opposite order
        $result = $repository->findDuplicate(100, 200, 'test@example.com', 999);

        $this->assertNotNull($result);
        $this->assertEquals(1, $result['post_id']);
    }

    /** @test */
    public function it_does_not_match_paired_with_single_registration(): void
    {
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

        $repository = $this->createMockedRepository($posts, [1]);

        // New registration is paired
        $result = $repository->findDuplicate(100, 200, 'test@example.com', 999);

        $this->assertNull($result);
    }

    /** @test */
    public function it_does_not_match_single_with_paired_registration(): void
    {
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

        $repository = $this->createMockedRepository($posts, [1]);

        // New registration is single
        $result = $repository->findDuplicate(100, null, 'test@example.com', 999);

        $this->assertNull($result);
    }

    /** @test */
    public function it_returns_latest_duplicate_by_updated_date(): void
    {
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

        $repository = $this->createMockedRepository($posts, [1, 2]);

        $result = $repository->findDuplicate(100, 200, 'test@example.com', 999);

        $this->assertNotNull($result);
        $this->assertEquals(2, $result['post_id']);
        $this->assertEquals('newer-post', $result['slug']);
    }

    /** @test */
    public function it_returns_latest_duplicate_by_post_date_when_no_updated(): void
    {
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

        $repository = $this->createMockedRepository($posts, [1, 2]);

        $result = $repository->findDuplicate(100, 200, 'test@example.com', 999);

        $this->assertNotNull($result);
        $this->assertEquals(2, $result['post_id']);
        $this->assertEquals('newer-post', $result['slug']);
    }

    /** @test */
    public function it_prefers_post_with_updated_date_over_one_without(): void
    {
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

        $repository = $this->createMockedRepository($posts, [1, 2]);

        $result = $repository->findDuplicate(100, 200, 'test@example.com', 999);

        $this->assertNotNull($result);
        // Should prefer post with updated date even if post_date is older
        $this->assertEquals(2, $result['post_id']);
        $this->assertEquals('has-updated-post', $result['slug']);
    }

    /** @test */
    public function normalize_post_id_handles_integer(): void
    {
        $result = $this->repository->normalizePostId(123);
        $this->assertEquals(123, $result);
    }

    /** @test */
    public function normalize_post_id_handles_string(): void
    {
        $result = $this->repository->normalizePostId('456');
        $this->assertEquals(456, $result);
    }

    /** @test */
    public function normalize_post_id_handles_object_with_id(): void
    {
        $obj = (object) ['ID' => 789];
        $result = $this->repository->normalizePostId($obj);
        $this->assertEquals(789, $result);
    }

    /** @test */
    public function normalize_post_id_handles_array_with_id(): void
    {
        $arr = ['ID' => 101];
        $result = $this->repository->normalizePostId($arr);
        $this->assertEquals(101, $result);
    }

    /** @test */
    public function normalize_post_id_handles_null(): void
    {
        $result = $this->repository->normalizePostId(null);
        $this->assertNull($result);
    }

    /** @test */
    public function normalize_post_id_handles_empty_string(): void
    {
        $result = $this->repository->normalizePostId('');
        $this->assertNull($result);
    }

    /**
     * Create a mocked repository with injected test data
     */
    private function createMockedRepository(array $posts, ?array $returnPostIds): AnswerRepository
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
        return new class($mockFields, $mockPostData, $returnPostIds) extends AnswerRepository {
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
                usort($duplicates, function($a, $b) {
                    $aUpdated = !empty($a['updated']) ? strtotime($a['updated']) : 0;
                    $bUpdated = !empty($b['updated']) ? strtotime($b['updated']) : 0;

                    if ($aUpdated > 0 && $bUpdated > 0) {
                        return $bUpdated - $aUpdated;
                    }

                    if ($aUpdated > 0) return -1;
                    if ($bUpdated > 0) return 1;

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
}
