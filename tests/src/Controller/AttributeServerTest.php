<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\exampleattributeserver\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SimpleSAML\Configuration;
use SimpleSAML\HTTP\RunnableResponse;
use SimpleSAML\Metadata\MetaDataStorageHandler;
use SimpleSAML\Module\exampleattributeserver\Controller\AttributeServer;
use SimpleSAML\XMLSecurity\TestUtils\PEMCertificatesMock;
use Symfony\Component\HttpFoundation\Request;

/**
 * Set of tests for the controllers in the "exampleattributeserver" module.
 *
 * @package simplesamlphp/simplesamlphp-module-exampleattributeserver
 */
#[CoversClass(AttributeServer::class)]
class ExampleAttributeServerTest extends TestCase
{
    /** @var \SimpleSAML\Configuration */
    protected static Configuration $config;


    /**
     * Set up for each test.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['SAMLRequest' => 'rZNRa8IwFIXfB%2FsPIe9Nm7SaNWhBEEFwg%2BHYw95ie%2Bsy2rQkKei%2FX9o6kQ1EhoQQOLnn3I8bMrOyrlqxcM6oXefgtQNzRIe60lYMV3PcGS0aaZUVWtZghcvFdvG8EYxEojWNa%2FKmwheW6w5pLRinGo3RejnHUpYspuk0oJzHAaM0DhKeyKAESpOEMrnjDKN3MNZb5tgneJ%2B1Hay1dVI7L0WUB1EaRNM3SkWSCsY%2FcPb4gNCspxFDtUGrxtTSXWfrFVUE5VAqQDvljjj7dK61IgzhIOu2AtKYfTgLL7Ivmm273RfkblB%2BtBeful7%2BA6DTtoVclQoKnPW2E4GwY5cTxJg%2FQoR%2FKEaG8%2FuivvoEoQpBSUymhJLE70nKYn%2F0i%2BOh7hZk6ZN79QxtFEYro0AX1XFsNgyygtqfFoc3UDEy8UTJXSGsvrE1nZKnJOrHQGMeR6SfSZzelaU1UIIxUGyk3ndyDwPa%2BHi%2F%2F2L2DQ%3D%3D'];
        $_GET['RelayState'] = 'something';
        $_SERVER['QUERY_STRING'] = 'SAMLRequest=rZNRa8IwFIXfB%2FsPIe9Nm7SaNWhBEEFwg%2BHYw95ie%2Bsy2rQkKei%2FX9o6kQ1EhoQQOLnn3I8bMrOyrlqxcM6oXefgtQNzRIe60lYMV3PcGS0aaZUVWtZghcvFdvG8EYxEojWNa%2FKmwheW6w5pLRinGo3RejnHUpYspuk0oJzHAaM0DhKeyKAESpOEMrnjDKN3MNZb5tgneJ%2B1Hay1dVI7L0WUB1EaRNM3SkWSCsY%2FcPb4gNCspxFDtUGrxtTSXWfrFVUE5VAqQDvljjj7dK61IgzhIOu2AtKYfTgLL7Ivmm273RfkblB%2BtBeful7%2BA6DTtoVclQoKnPW2E4GwY5cTxJg%2FQoR%2FKEaG8%2FuivvoEoQpBSUymhJLE70nKYn%2F0i%2BOh7hZk6ZN79QxtFEYro0AX1XFsNgyygtqfFoc3UDEy8UTJXSGsvrE1nZKnJOrHQGMeR6SfSZzelaU1UIIxUGyk3ndyDwPa%2BHi%2F%2F2L2DQ%3D%3D&RelayState=something';

        self::$config = Configuration::loadFromArray(
            [
                'module.enable' => ['exampleattributeserver' => true],
            ],
            '[ARRAY]',
            'simplesaml'
        );
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

        $mdh = $this->createMock(MetaDataStorageHandler::class);
        $mdh->method('getMetaDataCurrentEntityID')->willReturn('entityID');
        $mdh->method('getMetaDataConfig')->willReturn(Configuration::loadFromArray([
            'EntityID' => 'auth_source_id',
            'testAttributeEndpoint' => 'test',
            'privatekey' => PEMCertificatesMock::buildKeysPath(PEMCertificatesMock::SELFSIGNED_PRIVATE_KEY),
            'privatekey_pass' => '1234',
        ]));

        $c = new AttributeServer(self::$config);
        $c->setMetadataStorageHandler($mdh);
        $response = $c->main($request);

        $this->assertInstanceOf(RunnableResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
    }
}
