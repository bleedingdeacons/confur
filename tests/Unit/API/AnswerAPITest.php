<?php

namespace Tests\Unit\API;

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Confur\API\AnswerAPI;
use Confur\Repositories\AnswerRepository;
use Mockery;
use WP_Error;
use WP_Post;
use WP_REST_Response;

covers(AnswerAPI::class);

beforeEach(function () {
    Functions\when('get_page_by_path')->justReturn(null);
    $this->api = new AnswerAPI();
});

it('registers the status route', function () {
    $this->api->registerRoutes();

    expect(WpState::$restRoutes)->not->toBeEmpty();
});

it('registers a validate callback that accepts and rejects', function () {
    $this->api->registerRoutes();
    $route = WpState::$restRoutes[0];
    $validate = WpState::$restRoutes[0]['args']['args']['n']['validate_callback'];

    expect((bool) $validate('valid_slug-1'))->toBeTrue()
        ->and((bool) $validate('has spaces!'))->toBeFalse();
});

it('wraps a repository error in getAnswerPostStatus', function () {
    $repo = Mockery::mock(AnswerRepository::class);
    $repo->shouldReceive('getAnswerStatus')->andThrow(new \RuntimeException('boom'));

    $prop = (new \ReflectionClass($this->api))->getProperty('answerRepository');
    $prop->setValue($this->api, $repo);

    Functions\when('get_page_by_path')->justReturn(new WP_Post(['ID' => 42]));

    $result = $this->api->getAnswerPostStatus(['n' => 'slug']);
    expect($result)->toBeInstanceOf(WP_Error::class)
        ->and($result->get_error_code())->toBe('repository_error');
});

it('rejects an empty name in getAnswerPostStatus', function () {
    $result = $this->api->getAnswerPostStatus(['n' => '']);
    expect($result)->toBeInstanceOf(WP_Error::class)
        ->and($result->get_error_code())->toBe('invalid_request');
});

it('answers 404 from getAnswerPostStatus when the post is missing', function () {
    Functions\when('get_page_by_path')->justReturn(null);
    $result = $this->api->getAnswerPostStatus(['n' => 'missing-slug']);
    expect($result)->toBeInstanceOf(WP_Error::class)
        ->and($result->get_error_code())->toBe('invalid_post');
});

it('returns a response from getAnswerPostStatus for a found post', function () {
    Functions\when('get_page_by_path')->justReturn(new WP_Post(['ID' => 42]));
    $this->seedFields([
        42 => ['state' => 'Draft', 'updated' => '2026-01-01'],
    ]);

    $result = $this->api->getAnswerPostStatus(['n' => 'found-slug']);

    expect($result)->toBeInstanceOf(WP_REST_Response::class);
    $data = $result->get_data();
    expect($data['state'])->toBe('Draft')
        ->and($data['updated'])->toBe('2026-01-01');
});

// ── POST confur/v1/answers/{slug} ────────────────────────────────────
describe('saving answers', function () {
    beforeEach(function () {
        $this->save = Mockery::mock(\Confur\Application\SaveAnswers::class);
        $this->api = new AnswerAPI($this->save);
    });

    it('registers a public POST route keyed by slug', function () {
        $this->api->registerRoutes();

        $route = registeredRoute(AnswerAPI::SAVE_NAMESPACE, AnswerAPI::SAVE_ROUTE);
        expect($route['args']['methods'])->toBe('POST')
            ->and($route['args']['permission_callback'])->toBe('__return_true')
            ->and($route['args']['args']['answers']['required'])->toBeTrue();
    });

    it('saves through SaveAnswers and returns the outcome', function () {
        Functions\when('get_page_by_path')->justReturn(new WP_Post(['ID' => 42]));
        $this->save->shouldReceive('save')
            ->once()
            ->with(42, ['c1_a1' => 'Yes'], 'Complete')
            ->andReturn(new \Confur\Application\SaveResult(\Confur\Domain\AnswerState::Draft, 'Monday', 'Answers Saved as Draft: 1 question still needs an answer.'));

        $result = $this->api->saveAnswers(['slug' => 'abc', 'state' => 'Complete', 'answers' => ['c1_a1' => 'Yes']]);

        expect($result)->toBeInstanceOf(WP_REST_Response::class)
            ->and($result->get_data())->toBe([
                'message' => 'Answers Saved as Draft: 1 question still needs an answer.',
                'updated' => 'Monday',
                'state' => 'Draft',
            ]);
    });

    it('answers 404 for a slug that is not an answer page', function () {
        Functions\when('get_page_by_path')->justReturn(null);
        $this->save->shouldNotReceive('save');

        $result = $this->api->saveAnswers(['slug' => 'nope', 'answers' => []]);

        expect($result->get_error_code())->toBe('invalid_post')
            ->and($result->get_error_data()['status'])->toBe(404);
    });

    it('answers 400 when answers is not an object', function () {
        Functions\when('get_page_by_path')->justReturn(new WP_Post(['ID' => 42]));
        $this->save->shouldNotReceive('save');

        expect($this->api->saveAnswers(['slug' => 'abc', 'answers' => 'nope'])->get_error_data()['status'])->toBe(400);
    });

    it('passes a refusal through with its status and reason', function () {
        Functions\when('get_page_by_path')->justReturn(new WP_Post(['ID' => 42]));
        $this->save->shouldReceive('save')->andThrow(\Confur\Application\SubmissionRejected::cancelled());

        $result = $this->api->saveAnswers(['slug' => 'abc', 'answers' => []]);

        expect($result->get_error_message())->toBe('This registration has been cancelled.')
            ->and($result->get_error_data()['status'])->toBe(403);
    });

    it('hides an unexpected failure behind a generic 500', function () {
        Functions\when('get_page_by_path')->justReturn(new WP_Post(['ID' => 42]));
        $this->save->shouldReceive('save')->andThrow(new \RuntimeException('database went away'));

        $result = $this->api->saveAnswers(['slug' => 'abc', 'answers' => []]);

        expect($result->get_error_data()['status'])->toBe(500)
            ->and($result->get_error_message())->not->toContain('database');
    });

    it('saves end to end against a real answer post', function () {
        $this->api = new AnswerAPI(); // the real SaveAnswers
        Functions\when('get_page_by_path')->justReturn(new WP_Post(['ID' => 77]));
        $this->statuses[77] = 'publish';
        WpState::$postTypes[77] = 'answer';
        $this->fields[77] = ['email' => 'group@example.org'];

        $result = $this->api->saveAnswers(['slug' => 'abc', 'state' => 'Draft', 'answers' => ['c1_a1' => 'Saved over REST']]);

        expect($result->get_data()['state'])->toBe('Draft')
            ->and(get_field('c1_a1', 77))->toBe('Saved over REST');
    });
});

/** The registered route matching a namespace and pattern. */
function registeredRoute(string $namespace, string $route): array
{
    foreach (WpState::$restRoutes as $registered) {
        if (($registered['namespace'] ?? null) === $namespace && ($registered['route'] ?? null) === $route) {
            return $registered;
        }
    }

    throw new \RuntimeException("Route $namespace$route was not registered");
}
