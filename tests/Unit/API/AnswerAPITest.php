<?php

namespace Tests\Unit\API;

use Confur\API\AnswerAPI;
use Confur\Repositories\AnswerRepository;
use Mockery;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Response;

/**
 * @covers \Confur\API\AnswerAPI
 */
class AnswerAPITest extends TestCase
{
    private AnswerAPI $api;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['confur_rest_routes'] = [];
        $GLOBALS['confur_page_by_path'] = null;
        $GLOBALS['confur_fields'] = [];
        $this->api = new AnswerAPI();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testRegisterRoutesRegistersTheStatusRoute(): void
    {
        $this->api->registerRoutes();
        $this->assertNotEmpty($GLOBALS['confur_rest_routes']);
    }

    public function testRegisteredValidateCallbackAcceptsAndRejects(): void
    {
        $this->api->registerRoutes();
        $route = $GLOBALS['confur_rest_routes'][0];
        $validate = $GLOBALS['confur_rest_args'][$route]['args']['n']['validate_callback'];

        $this->assertTrue((bool) $validate('valid_slug-1'));
        $this->assertFalse((bool) $validate('has spaces!'));
    }

    public function testGetStatusWrapsRepositoryError(): void
    {
        $repo = Mockery::mock(AnswerRepository::class);
        $repo->shouldReceive('getAnswerStatus')->andThrow(new \RuntimeException('boom'));

        $prop = (new \ReflectionClass($this->api))->getProperty('answerRepository');
        $prop->setValue($this->api, $repo);

        $GLOBALS['confur_page_by_path'] = (object) ['ID' => 42];

        $result = $this->api->getAnswerPostStatus(['n' => 'slug']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('repository_error', $result->get_error_code());
    }

    public function testGetStatusRejectsEmptyName(): void
    {
        $result = $this->api->getAnswerPostStatus(['n' => '']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_request', $result->get_error_code());
    }

    public function testGetStatusReturns404WhenPostMissing(): void
    {
        $GLOBALS['confur_page_by_path'] = null;
        $result = $this->api->getAnswerPostStatus(['n' => 'missing-slug']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_post', $result->get_error_code());
    }

    public function testGetStatusReturnsResponseForFoundPost(): void
    {
        $GLOBALS['confur_page_by_path'] = (object) ['ID' => 42];
        $GLOBALS['confur_fields'] = [
            42 => ['state' => 'Draft', 'updated' => '2026-01-01'],
        ];

        $result = $this->api->getAnswerPostStatus(['n' => 'found-slug']);

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $data = $result->get_data();
        $this->assertSame('Draft', $data['state']);
        $this->assertSame('2026-01-01', $data['updated']);
    }
}
