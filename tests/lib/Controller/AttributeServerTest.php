<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\exampleattributeserver\Controller;

use PHPUnit\Framework\TestCase;
use SimpleSAML\Configuration;
use SimpleSAML\HTTP\RunnableResponse;
use SimpleSAML\Module\exampleattributeserver\Controller;
use SimpleSAML\Session;
use Symfony\Component\HttpFoundation\Request;

/**
 * Set of tests for the controllers in the "exampleattributeserver" module.
 *
 * @covers \SimpleSAML\Module\exampleattributeserver\Controller\AttributeServer
 * @package SimpleSAML\Test
 */
class ExampleAttributeServerTest extends TestCase
{
    /** @var \SimpleSAML\Configuration */
    protected $config;

    /** @var \SimpleSAML\Session */
    protected $session;


    /**
     * Set up for each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->config = Configuration::loadFromArray(
            [
                'module.enable' => ['exampleattributeserver' => true],
            ],
            '[ARRAY]',
            'simplesaml'
        );

        $this->session = Session::getSessionFromRequest();
/*
        Configuration::setPreLoadedConfig(
            Configuration::loadFromArray(
                [
                    'admin' => ['core:AdminPassword'],
                ],
                '[ARRAY]',
                'simplesaml'
            ),
            'authsources.php',
            'simplesaml'
        );
*/
    }


    /**
     */
    public function testMain(): void
    {
        $_SERVER['REQUEST_URI'] = '/module.php/exampleattributeserver/attributeserver';
        $request = Request::create(
            '/module.php/exampleattributeserver/attributeserver',
            'GET'
        );

        $c = new Controller\AttributeServer($this->config, $this->session);
        $response = $c->main($request);

        $this->assertInstanceOf(RunnableResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
    }
}
