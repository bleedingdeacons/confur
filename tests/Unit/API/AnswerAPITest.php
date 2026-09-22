<?php

namespace Tests\Unit\API;

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Confur\API\AnswerAPI;
use Confur\Repositories\AnswerRepository;
use Mockery;
use WP_Error;
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

    Functions\when('get_page_by_path')->justReturn((object) ['ID' => 42]);

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
    Functions\when('get_page_by_path')->justReturn((object) ['ID' => 42]);
    $this->seedFields([
        42 => ['state' => 'Draft', 'updated' => '2026-01-01'],
    ]);

    $result = $this->api->getAnswerPostStatus(['n' => 'found-slug']);

    expect($result)->toBeInstanceOf(WP_REST_Response::class);
    $data = $result->get_data();
    expect($data['state'])->toBe('Draft')
        ->and($data['updated'])->toBe('2026-01-01');
});
