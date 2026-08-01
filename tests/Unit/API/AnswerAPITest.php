<?php

namespace Tests\Unit\API;

use Confur\API\AnswerAPI;
use Confur\Repositories\AnswerRepository;
use Mockery;
use Brain\Monkey\Functions;
use BleedingDeacons\WpMocks\WpState;
use Tests\ConfurTestCase;
use WP_Error;
use WP_REST_Response;

/**
 * @covers \Confur\API\AnswerAPI
 */
class AnswerAPITest extends ConfurTestCase
{
    private AnswerAPI $api;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('get_page_by_path')->justReturn(null);
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
        $this->assertNotEmpty(WpState::$restRoutes);
    }

    public function testRegisteredValidateCallbackAcceptsAndRejects(): void
    {
        $this->api->registerRoutes();
        $route = WpState::$restRoutes[0];
        $validate = WpState::$restRoutes[0]['args']['args']['n']['validate_callback'];

        $this->assertTrue((bool) $validate('valid_slug-1'));
        $this->assertFalse((bool) $validate('has spaces!'));
    }

    public function testGetStatusWrapsRepositoryError(): void
    {
        $repo = Mockery::mock(AnswerRepository::class);
        $repo->shouldReceive('getAnswerStatus')->andThrow(new \RuntimeException('boom'));

        $prop = (new \ReflectionClass($this->api))->getProperty('answerRepository');
        $prop->setValue($this->api, $repo);

        Functions\when('get_page_by_path')->justReturn((object) ['ID' => 42]);

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
        Functions\when('get_page_by_path')->justReturn(null);
        $result = $this->api->getAnswerPostStatus(['n' => 'missing-slug']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_post', $result->get_error_code());
    }

    public function testGetStatusReturnsResponseForFoundPost(): void
    {
        Functions\when('get_page_by_path')->justReturn((object) ['ID' => 42]);
        $this->seedFields([
            42 => ['state' => 'Draft', 'updated' => '2026-01-01'],
        ]);

        $result = $this->api->getAnswerPostStatus(['n' => 'found-slug']);

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $data = $result->get_data();
        $this->assertSame('Draft', $data['state']);
        $this->assertSame('2026-01-01', $data['updated']);
    }
}
