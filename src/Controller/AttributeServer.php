<?php

declare(strict_types=1);

namespace SimpleSAML\Module\exampleattributeserver\Controller;

use SAML2\Assertion;
use SAML2\AttributeQuery;
use SAML2\Binding;
use SAML2\Constants;
use SAML2\HTTPPost;
use SAML2\Response;
use SAML2\XML\saml\Issuer;
use SAML2\XML\saml\SubjectConfirmation;
use SAML2\XML\saml\SubjectConfirmationData;
use SimpleSAML\Configuration;
use SimpleSAML\Error;
use SimpleSAML\HTTP\RunnableResponse;
use SimpleSAML\Logger;
use SimpleSAML\Metadata\MetaDataStorageHandler;
use SimpleSAML\Module\saml\Message;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller class for the exampleattributeserver module.
 *
 * This class serves the attribute server available in the module.
 *
 * @package SimpleSAML\Module\exampleattributeserver
 */
class AttributeServer
{
    /** @var \SimpleSAML\Configuration */
    protected Configuration $config;

    /** @var \SimpleSAML\Metadata\MetaDataStorageHandler|null */
    protected ?MetaDataStorageHandler $metadataHandler = null;


    /**
     * ConfigController constructor.
     *
     * @param \SimpleSAML\Configuration $config The configuration to use.
     */
    public function __construct(Configuration $config)
    {
        $this->config = $config;
    }


    /**
     * Inject the \SimpleSAML\Metadata\MetaDataStorageHandler dependency.
     *
     * @param \SimpleSAML\Metadata\MetaDataStorageHandler $handler
     */
    public function setMetadataStorageHandler(MetaDataStorageHandler $handler): void
    {
        $this->metadataHandler = $handler;
    }


    /**
     * @param \Symfony\Component\HttpFoundation\Request $request The current request.
     *
     * @return \SimpleSAML\HTTP\RunnableResponse
     * @throws \SimpleSAML\Error\BadRequest
     */
    public function main(/** @scrutinizer ignore-unused */ Request $request): RunnableResponse
    {
        $binding = Binding::getCurrentBinding();
        $query = $binding->receive();
        if (!($query instanceof AttributeQuery)) {
            throw new Error\BadRequest('Invalid message received to AttributeQuery endpoint.');
        }

        $idpEntityId = $this->metadataHandler->getMetaDataCurrentEntityID('saml20-idp-hosted');

        $issuer = $query->getIssuer();
        if ($issuer === null) {
            throw new Error\BadRequest('Missing <saml:Issuer> in <samlp:AttributeQuery>.');
        } else {
            $spEntityId = $issuer->getValue();
            if ($spEntityId === '') {
                throw new Error\BadRequest('Empty <saml:Issuer> in <samlp:AttributeQuery>.');
            }
        }

        $idpMetadata = $this->metadataHandler->getMetaDataConfig($idpEntityId, 'saml20-idp-hosted');
        $spMetadata = $this->metadataHandler->getMetaDataConfig($spEntityId, 'saml20-sp-remote');

        // The endpoint we should deliver the message to
        $endpoint = $spMetadata->getString('testAttributeEndpoint');

        // The attributes we will return
        $attributes = [
            'name' => ['value1', 'value2', 'value3'],
            'test' => ['test'],
        ];

        // The name format of the attributes
        $attributeNameFormat = Constants::NAMEFORMAT_UNSPECIFIED;

        // Determine which attributes we will return
        $returnAttributes = array_keys($query->getAttributes());
        if (count($returnAttributes) === 0) {
            Logger::debug('No attributes requested - return all attributes.');
            $returnAttributes = $attributes;
        } elseif ($query->getAttributeNameFormat() !== $attributeNameFormat) {
            Logger::debug('Requested attributes with wrong NameFormat - no attributes returned.');
            $returnAttributes = [];
        } else {
            /** @var array $values */
            foreach ($returnAttributes as $name => $values) {
                if (!array_key_exists($name, $attributes)) {
                    // We don't have this attribute
                    unset($returnAttributes[$name]);
                    continue;
                }
                if (count($values) === 0) {
                    // Return all attributes
                    $returnAttributes[$name] = $attributes[$name];
                    continue;
                }

                // Filter which attribute values we should return
                $returnAttributes[$name] = array_intersect($values, $attributes[$name]);
            }
        }

        // $returnAttributes contains the attributes we should return. Send them
        $issuer = new Issuer();
        $issuer->setValue($idpEntityId);

        $assertion = new Assertion();
        $assertion->setIssuer($issuer);
        $assertion->setNameId($query->getNameId());
        $assertion->setNotBefore(time());
        $assertion->setNotOnOrAfter(time() + 300); // 60*5 = 5min
        $assertion->setValidAudiences([$spEntityId]);
        $assertion->setAttributes($returnAttributes);
        $assertion->setAttributeNameFormat($attributeNameFormat);

        $sc = new SubjectConfirmation();
        $sc->setMethod(Constants::CM_BEARER);

        $scd = new SubjectConfirmationData();
        $scd->setNotOnOrAfter(time() + 300); // 60*5 = 5min
        $scd->setRecipient($endpoint);
        $scd->setInResponseTo($query->getId());
        $sc->setSubjectConfirmationData($scd);
        $assertion->setSubjectConfirmation([$sc]);

        Message::addSign($idpMetadata, $spMetadata, $assertion);

        $response = new Response();
        $response->setRelayState($query->getRelayState());
        $response->setDestination($endpoint);
        $response->setIssuer($issuer);
        $response->setInResponseTo($query->getId());
        $response->setAssertions([$assertion]);
        Message::addSign($idpMetadata, $spMetadata, $response);

        return new RunnableResponse([new HTTPPost(), 'send'], [$response]);
    }
}
