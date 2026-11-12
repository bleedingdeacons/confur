<?php

namespace Tests\Unit\Application;

use BleedingDeacons\WpMocks\WpState;
use Confur\Application\DuplicateFinder;
use Confur\Application\RegisterGroup;
use Confur\Config\Constants;
use Confur\Config\Settings;
use Confur\Storage\AnswerStore;
use Tests\RecordingMailer;

covers(RegisterGroup::class);

beforeEach(function () {
    WpState::$queryPosts = [];
    $this->mailer = new RecordingMailer();

    $this->service = function (array $settings = []): RegisterGroup {
        $store = new AnswerStore();

        return new RegisterGroup($store, new DuplicateFinder($store), $this->mailer, new Settings(
            registrationReplyEmail: 'reply@example.org',
            supportEmail: 'support@example.org',
            backupEmail: 'backup@example.org',
            deleteBlockedPosts: $settings['deleteBlockedPosts'] ?? false,
            duplicateDetection: $settings['duplicateDetection'] ?? false,
            blocklist: $settings['blocklist'] ?? [],
        ));
    };

    // The post the registration form just created.
    $this->registration = function (int $id, array $fields): void {
        $post = (object) ['ID' => $id, 'post_type' => 'answer', 'post_status' => 'publish', 'post_name' => "new-$id", 'post_date' => '2024-01-01 00:00:00'];
        WpState::$posts[$id] = $post;
        WpState::$postTypes[$id] = 'answer';
        WpState::$postStatuses[$id] = 'publish';
        $this->fields[$id] = $fields;
    };

    $this->seedTitles([100 => 'Monday Group', 200 => 'Tuesday Group']);
});

describe('a new registration', function () {
    it('gets a title, an unguessable slug, the Draft state and a confirmation', function () {
        ($this->registration)(1, [Constants::GROUP_FIELD => 100, Constants::EMAIL_FIELD => 'group@example.org']);
        $this->fields[100] = [Constants::ALLOCATION_FIELD => '3'];

        ($this->service)()->register(1);

        $update = end(WpState::$updatedPosts);
        expect($update['post_title'])->toBe('Answers from Monday Group')
            ->and($update['post_name'])->toMatch('/^[0-9a-f]{16}_monday_group$/')
            ->and(get_field(Constants::STATUS_FIELD, 1))->toBe('Draft');

        [$to, $name, , $allocation, $isDuplicate] = $this->mailer->calls('sendConfirmation')[0];
        expect([$to, $name, $allocation, $isDuplicate])->toBe(['group@example.org', 'Monday Group', '3', false]);
    });

    it('names both meetings of a pair, each cut to 85 characters', function () {
        $this->seedTitles([100 => str_repeat('M', 100), 200 => 'Tuesday Group']);
        ($this->registration)(2, [
            Constants::GROUP_FIELD => (object) ['ID' => 100],
            Constants::FELLOW_GROUP_FIELD => ['ID' => 200],
            Constants::EMAIL_FIELD => 'group@example.org',
        ]);

        ($this->service)()->register(2);

        expect($this->mailer->calls('sendConfirmation')[0][1])->toBe(str_repeat('M', 85) . ' and Tuesday Group');
    });

    it('reports a registration with no meeting to the registrant', function () {
        ($this->registration)(3, [Constants::GROUP_FIELD => null, Constants::EMAIL_FIELD => 'group@example.org']);

        ($this->service)()->register(3);

        expect($this->mailer->calls('sendRegistrationError')[0][1])->toBe('Error: Missing Group')
            ->and($this->mailer->calls('sendConfirmation'))->toBe([])
            ->and(WpState::$updatedPosts)->toBe([]);
    });
});

describe('a blocked address', function () {
    it('is told no, and its post deleted when the setting says so', function () {
        ($this->registration)(4, [Constants::GROUP_FIELD => 100, Constants::EMAIL_FIELD => ' Blocked@Example.org ']);

        ($this->service)(['blocklist' => ['blocked@example.org'], 'deleteBlockedPosts' => true])->register(4);

        expect($this->mailer->calls('sendRegistrationBlocked'))->toHaveCount(1)
            ->and(WpState::$deletedPosts)->toContain(4)
            ->and($this->mailer->calls('sendConfirmation'))->toBe([]);
    });

    it('keeps the post when the setting is off', function () {
        ($this->registration)(5, [Constants::GROUP_FIELD => 100, Constants::EMAIL_FIELD => 'blocked@example.org']);

        ($this->service)(['blocklist' => ['blocked@example.org']])->register(5);

        expect(WpState::$deletedPosts)->not->toContain(5)
            ->and($this->mailer->calls('sendRegistrationBlocked'))->toHaveCount(1);
    });
});

describe('a repeat registration', function () {
    beforeEach(function () {
        // The group's earlier registration.
        $existing = (object) ['ID' => 50, 'post_type' => 'answer', 'post_status' => 'publish', 'post_name' => 'existing', 'post_date' => '2024-01-01 00:00:00'];
        WpState::$queryPosts = [$existing];
        WpState::$posts[50] = $existing;
        WpState::$postStatuses[50] = 'publish';
        $this->fields[50] = [
            Constants::GROUP_FIELD => 100,
            Constants::EMAIL_FIELD => 'group@example.org',
            Constants::STATUS_FIELD => 'Draft',
        ];

        ($this->registration)(6, [Constants::GROUP_FIELD => 100, Constants::EMAIL_FIELD => 'GROUP@example.org']);
    });

    it('is trashed, and the group re-sent its existing link', function () {
        ($this->service)(['duplicateDetection' => true])->register(6);

        [$to, , $url, , $isDuplicate] = $this->mailer->calls('sendConfirmation')[0];
        expect($this->trashedPostIds())->toBe([6])
            ->and($to)->toBe('GROUP@example.org')
            ->and($url)->toBe(get_permalink(50))
            ->and($isDuplicate)->toBeTrue()
            ->and(WpState::$updatedPosts)->toBe([]);
    });

    it('goes through as new when duplicate detection is off', function () {
        ($this->service)()->register(6);

        expect($this->trashedPostIds())->toBe([])
            ->and($this->mailer->calls('sendConfirmation')[0][4])->toBeFalse();
    });
});
