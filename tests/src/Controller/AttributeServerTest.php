<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\exampleattributeserver\Controller;

use Mockery\Adapter\Phpunit\MockeryTestCase;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SimpleSAML\SAML2\Binding\SOAP;
use SimpleSAML\Configuration;
use SimpleSAML\HTTP\RunnableResponse;
use SimpleSAML\Metadata\MetaDataStorageHandler;
use SimpleSAML\Module\exampleattributeserver\Controller\AttributeServer;
use SimpleSAML\XMLSecurity\TestUtils\PEMCertificatesMock;
use Symfony\Component\HttpFoundation\Request;
use SAML2\Message;

/**
 * Set of tests for the controllers in the "exampleattributeserver" module.
 *
 * @package simplesamlphp/simplesamlphp-module-exampleattributeserver
 */
#[CoversClass(AttributeServer::class)]
class ExampleAttributeServerTest extends MockeryTestCase
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
        $_GET = ['SAMLRequest' => 'rZNRa8IwFIXfB/sPIe9Nm7SaNWhBEEFwg+HYw95ie+sy2rQkKei/X9o6kQ1EhoQQOLnn3I8bMrOyrlqxcM6oXefgtQNzRIe60lYMV3PcGS0aaZUVWtZghcvFdvG8EYxEojWNa/KmwheW6w5pLRinGo3RejnHUpYspuk0oJzHAaM0DhKeyKAESpOEMrnjDKN3MNZb5tgneJ+1Hay1dVI7L0WUB1EaRNM3SkWSCsY/cPb4gNCspxFDtUGrxtTSXWfrFVUE5VAqQDvljjj7dK61IgzhIOu2AtKYfTgLL7Ivmm273RfkblB+tBeful7+A6DTtoVclQoKnPW2E4GwY5cTxJg/QoR/KEaG8/uivvoEoQpBSUymhJLE70nKYn/0i+Oh7hZk6ZN79QxtFEYro0AX1XFsNgyygtqfFoc3UDEy8UTJXSGsvrE1nZKnJOrHQGMeR6SfSZzelaU1UIIxUGyk3ndyDwPa+Hi//2L2DQ=='];
        $_GET['RelayState'] = 'something';
        $_SERVER['QUERY_STRING'] = 'SAMLRequest=rZNRa8IwFIXfB%2FsPIe9Nm7SaNWhBEEFwg%2BHYw95ie%2Bsy2rQkKei%2FX9o6kQ1EhoQQOLnn3I8bMrOyrlqxcM6oXefgtQNzRIe60lYMV3PcGS0aaZUVWtZghcvFdvG8EYxEojWNa%2FKmwheW6w5pLRinGo3RejnHUpYspuk0oJzHAaM0DhKeyKAESpOEMrnjDKN3MNZb5tgneJ%2B1Hay1dVI7L0WUB1EaRNM3SkWSCsY%2FcPb4gNCspxFDtUGrxtTSXWfrFVUE5VAqQDvljjj7dK61IgzhIOu2AtKYfTgLL7Ivmm273RfkblB%2BtBeful7%2BA6DTtoVclQoKnPW2E4GwY5cTxJg%2FQoR%2FKEaG8%2FuivvoEoQpBSUymhJLE70nKYn%2F0i%2BOh7hZk6ZN79QxtFEYro0AX1XFsNgyygtqfFoc3UDEy8UTJXSGsvrE1nZKnJOrHQGMeR6SfSZzelaU1UIIxUGyk3ndyDwPa%2BHi%2F%2F2L2DQ%3D%3D&RelayState=something';

        self::$config = Configuration::loadFromArray(
            [
                'module.enable' => ['exampleattributeserver' => true],
            ],
            '[ARRAY]',
            'simplesaml',
        );
    }


    /**
     */
    public function testMain(): void
    {
        $soap = $this->getStubWithInput(<<<SOAP
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">
  <SOAP-ENV:Body>
          <samlp:AttributeQuery xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="aaf23196-1773-2113-474a-fe114412ab72" Version="2.0" IssueInstant="2017-09-06T11:49:27Z">
            <saml:Issuer Format="urn:oasis:names:tc:SAML:2.0:nameid-format:entity">https://example.org/</saml:Issuer>
            <saml:Subject>
              <saml:NameID Format="urn:oasis:names:tc:SAML:2.0:nameid-format:unspecified">urn:example:subject</saml:NameID>
            </saml:Subject>
            <saml:Attribute Name="urn:oid:1.3.6.1.4.1.5923.1.1.1.7" NameFormat="urn:oasis:names:tc:SAML:2.0:attrname-format:uri" FriendlyName="entitlements"/>
            <saml:Attribute Name="urn:oid:2.5.4.4" NameFormat="urn:oasis:names:tc:SAML:2.0:attrname-format:uri" FriendlyName="sn"/>
            <saml:Attribute Name="urn:oid:2.16.840.1.113730.3.1.39" NameFormat="urn:oasis:names:tc:SAML:2.0:attrname-format:uri" FriendlyName="preferredLanguage"/>
          </samlp:AttributeQuery>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>
SOAP);


        $mdh = $this->createMock(MetaDataStorageHandler::class);
        $mdh->method('getMetaDataCurrentEntityID')->willReturn('https://example.org/');
        $mdh->method('getMetaDataConfig')->willReturn(Configuration::loadFromArray([
            'EntityID' => 'auth_source_id',
            'testAttributeEndpoint' => 'https://example.org/testAttributeEndpoint',
            'privatekey' => PEMCertificatesMock::buildKeysPath(PEMCertificatesMock::SELFSIGNED_PRIVATE_KEY),
            'privatekey_pass' => '1234',
        ]));

        $c = new AttributeServer(self::$config);
        $c->setMetadataStorageHandler($mdh);

        $request = new ServerRequest('', '');
        $response = $c->main($soap, $request);

        $this->assertInstanceOf(RunnableResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
    }

    /**
     * @return \SimpleSAML\SAML2\Binding\SOAP
     */
    private function getStubWithInput($input): SOAP
    {
        $stub = $this->getMockBuilder(SOAP::class)->onlyMethods(['getInputStream'])->getMock();
        $stub->expects($this->once())
             ->method('getInputStream')
             ->willReturn($input);
        return $stub;
    }
}
