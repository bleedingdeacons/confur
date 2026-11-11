<?php

namespace Tests\Unit\Admin;

use BleedingDeacons\WpMocks\Exceptions\WpDieException;
use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Confur\Admin\QuestionSetAdminPage;
use Confur\Config\QuestionSetOption;
use Confur\Domain\QuestionSet;
use ReflectionMethod;

covers(QuestionSetAdminPage::class);

function questionsRedirect(QuestionSetAdminPage $page, array $post): string
{
    $m = new ReflectionMethod(QuestionSetAdminPage::class, 'resolveSubmissionRedirect');

    return (string) $m->invoke($page, $post);
}

beforeEach(function () {
    $_GET = [];
    $this->page = new QuestionSetAdminPage();

    Functions\when('get_admin_page_title')->justReturn('Conference Questions');
    Functions\when('submit_button')->alias(
        static function (string $text = 'Save', string $type = 'primary', string $name = 'submit'): void {
            echo '<button type="submit" name="' . $name . '">' . $text . '</button>';
        }
    );
});

afterEach(function () {
    $_GET = [];
});

describe('parseForm', function () {
    it('builds a set from the rows, dropping blank and zero rows', function () {
        $set = QuestionSetAdminPage::parseForm([
            'committee' => ['1', '2', '3', '', '9'],
            'questions' => ['3', '2', '0', '4', ''],
            'all_committees' => '1',
        ]);

        expect($set->questionCounts())->toBe([1 => 3, 2 => 2])
            ->and($set->allCommittees())->toBe(2);
    });

    it('defaults All Committees to the highest when the chosen row was removed', function () {
        $set = QuestionSetAdminPage::parseForm([
            'committee' => ['1', '2', '3'],
            'questions' => ['1', '1', '0'],
            'all_committees' => '2',
        ]);

        expect($set->allCommittees())->toBe(2);
    });

    it('rejects bad rows', function (array $post, string $message) {
        expect(fn () => QuestionSetAdminPage::parseForm($post))
            ->toThrow(\InvalidArgumentException::class, $message);
    })->with([
        'duplicate' => [['committee' => ['1', '1'], 'questions' => ['1', '2']], 'Committee 1 is listed twice.'],
        'not a number' => [['committee' => ['one'], 'questions' => ['1']], 'whole numbers'],
        'negative' => [['committee' => ['1'], 'questions' => ['-1']], 'whole numbers'],
        'nothing left' => [['committee' => ['1'], 'questions' => ['0']], 'at least one committee'],
    ]);
});

describe('submission', function () {
    it('saves a valid form', function () {
        $url = questionsRedirect($this->page, [
            'committee' => ['1', '2'],
            'questions' => ['2', '1'],
            'all_committees' => '1',
        ]);

        expect($url)->toContain('page=confur-questions', 'updated=1')
            ->and(QuestionSetOption::load()->questionCounts())->toBe([1 => 2, 2 => 1]);
    });

    it('keeps the saved set and explains why when the form is invalid', function () {
        QuestionSetOption::save(new QuestionSet([1 => 1]));

        $url = questionsRedirect($this->page, ['committee' => ['2', '2'], 'questions' => ['1', '1']]);

        expect($url)->toContain('error=invalid')
            ->not->toContain('twice')   // the reason is not carried in the URL
            ->and(QuestionSetOption::load()->questionCounts())->toBe([1 => 1]);

        $_GET = ['error' => 'invalid'];
        $html = captureOutput(fn () => $this->page->renderAdminPage());

        expect($html)->toContain('Not saved: Committee 2 is listed twice.');
    });

    it('resets to the default', function () {
        QuestionSetOption::save(new QuestionSet([1 => 1]));

        $url = questionsRedirect($this->page, ['reset_to_default' => '1']);

        expect($url)->toContain('updated=reset')
            ->and(QuestionSetOption::isCustomised())->toBeFalse();
    });

    it('refuses a user who cannot manage options', function () {
        WpState::$userCan = false;

        $this->page->handleFormSubmission();
    })->throws(WpDieException::class);

    it('refuses a missing nonce', function () {
        $_POST = [];

        $this->page->handleFormSubmission();
    })->throws(WpDieException::class);
});

describe('screen', function () {
    it('registers the menu and the form handler from init', function () {
        $this->page->init();

        $this->assertActionAdded('admin_menu', false, 'the menu should be registered');
        $this->assertActionAdded('admin_post_confur_update_questions', false, 'the form handler should be registered');
    });

    it('registers nothing on a front-end request', function () {
        WpState::$isAdmin = false;

        $this->page->init();

        $this->assertActionNotAdded('admin_menu');
    });

    it('adds the page under the Confur menu for administrators only', function () {
        $this->page->addAdminMenu();

        expect(WpState::$menus)->toHaveCount(1)
            ->and(WpState::$menus[0]['parent'])->toBe('confur')
            ->and(WpState::$menus[0]['slug'])->toBe('confur-questions')
            ->and(WpState::$menus[0]['cap'])->toBe('manage_options');
    });

    it('lists each committee with its fields and spare rows to add more', function () {
        QuestionSetOption::save(new QuestionSet([1 => 3, 2 => 1]));

        $html = captureOutput(fn () => $this->page->renderAdminPage());

        expect($html)->toContain('c1_a1 – c1_a3', 'c2_a1', 'New committee', 'Reset to Default')
            ->and(substr_count($html, 'name="committee['))->toBe(4); // 2 committees + 2 spare
    });

    it('offers no reset while the default is in force', function () {
        $html = captureOutput(fn () => $this->page->renderAdminPage());

        expect($html)->not->toContain('Reset to Default')
            ->and(substr_count($html, 'name="committee['))->toBe(count(QuestionSet::DEFAULT) + 2);
    });

    it('warns when a filter overrides the saved set', function () {
        \Brain\Monkey\Filters\expectApplied('confur_question_set')->andReturn([1 => 1]);

        $html = captureOutput(fn () => $this->page->renderAdminPage());

        expect($html)->toContain('confur_question_set');
    });
});
