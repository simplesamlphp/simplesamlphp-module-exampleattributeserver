<?php

declare(strict_types=1);

namespace SimpleSAML\Module\exampleattributeserver\Controller;

use DateInterval;
use Nyholm\Psr7\Factory\Psr17Factory;
use SimpleSAML\{Configuration, Error, Logger};
use SimpleSAML\HTTP\RunnableResponse;
use SimpleSAML\Metadata\MetaDataStorageHandler;
use SimpleSAML\SAML2\Binding;
use SimpleSAML\SAML2\Binding\HTTPPost;
use SimpleSAML\SAML2\Constants as C;
use SimpleSAML\SAML2\Utils;
use SimpleSAML\SAML2\XML\saml\{
    Assertion,
    Attribute,
    AttributeStatement,
    AttributeValue,
    Audience,
    AudienceRestriction,
    Conditions,
    Issuer,
    Status,
    StatusCode,
    Subject,
    SubjectConfirmation,
    SubjectConfirmationData,
};
use SimpleSAML\SAML2\XML\samlp\{AttributeQuery, Response};
use SimpleSAML\XML\Utils\Random;
use Symfony\Bridge\PsrHttpMessage\Factory\{HttpFoundationFactory, PsrHttpFactory};
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
        $psr17Factory = new Psr17Factory();
        $psrHttpFactory = new PsrHttpFactory($psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory);
        $psrRequest = $psrHttpFactory->createRequest($request);

        $binding = Binding::getCurrentBinding($psrRequest);
        $message = $binding->receive($psrRequest);
        if (!($message instanceof AttributeQuery)) {
            throw new Error\BadRequest('Invalid message received to AttributeQuery endpoint.');
        }

        $idpEntityId = $this->metadataHandler->getMetaDataCurrentEntityID('saml20-idp-hosted');

        $issuer = $message->getIssuer();
        if ($issuer === null) {
            throw new Error\BadRequest('Missing <saml:Issuer> in <samlp:AttributeQuery>.');
        } else {
            $spEntityId = $issuer->getContent();
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
            new Attribute(
                'name',
                C::NAMEFORMAT_UNSPECIFIED,
                null,
                [
                    new AttributeValue('value1'),
                    new AttributeValue('value2'),
                    new AttributeValue('value3'),
                ]
            ),
            new Attribute(
                'test',
                C::NAMEFORMAT_UNSPECIFIED,
                null,
                [
                    new AttributeValue('test'),
                ],
            ),
        ];

        // Determine which attributes we will return
        $returnAttributes = [];

        if (count($returnAttributes) === 0) {
            Logger::debug('No attributes requested - return all attributes.');
            $returnAttributes = $attributes;
        } else {
            foreach ($message->getAttributes() as $reqAttr) {
                foreach ($attributes as $attr) {
                    if ($attr->getName() === $reqAttr->getName() && $attr->getNameFormat() === $reqAttr->getNameFormat()) {
                        // The requested attribute is available
                        if ($reqAttr->getAttributeValues() === []) {
                            // If no specific values are requested, return all
                            $returnAttributes[] = $attr;
                        } else {
                            $returnValues = $this->filterAttributeValues($reqAttr->getAttributeValues(), $attr->getAttributeValues());
                            $returnAttributes[] = new Attribute(
                                $attr->getName(),
                                $attr->getNameFormat(),
                                $returnValues,
                                $attr->getAttributesNS(),
                            );
                        }
                    }
                }
            }
        }

        // $returnAttributes contains the attributes we should return. Send them
        $clock = Utils::getContainer()->getClock();

        $assertion = new Assertion(
            issuer: new Issuer($idpEntityId),
            issueInstant: $clock->now(),
            id: (new Random())->generateID(),
            subject: new Subject(
                identifier: $message->getSubject()->getIdentifier(),
                subjectConfirmation: [
                    new SubjectConfirmation(
                        method: C::CM_BEARER,
                        subjectConfirmationData: new SubjectConfirmationData(
                            notOnOrAfter: $clock->now()->add(new DateInterval('PT300S')),
                            recipient: $endpoint,
                            inResponseTo: $message->getId(),
                        ),
                    ),
                ],
            ),
            conditions: new Conditions(
                notBefore: $clock->now(),
                notOnOrAfter: $clock->now()->add(new DateInterval('PT300S')),
                audienceRestriction: [
                    new AudienceRestriction([
                        new Audience($spEntityId),
                    ]),
                ],
            ),
            statements: [
                new AttributeStatement($returnAttributes),
            ],
        );

        // TODO:  Fix signing; should use xml-security lib
        Message::addSign($idpMetadata, $spMetadata, $assertion);

        $response = new Response(
            status: new Status(
                new StatusCode(C::STATUS_SUCCESS),
            ),
            issueInstant: $clock->now(),
            issuer: new Issuer($issuer),
            id: (new Random())->generateID(),
            version: '2.0',
            inResponseTo: $message->getId(),
            destination: $endpoint,
            assertions: [$assertion],
        );

        // TODO:  Fix signing; should use xml-security lib
        Message::addSign($idpMetadata, $spMetadata, $response);

        $httpPost = new HTTPPost();
        $httpPost->setRelayState($binding->getRelayState());

        return new RunnableResponse([$httpPost, 'send'], [$response]);
    }


    /**
     * @param array<\SimpleSAML\SAML2\XML\saml\AttributeValue> $reqValues
     * @param array<\SimpleSAML\SAML2\XML\saml\AttributeValue> $values
     *
     * @return array<\SimpleSAML\SAML2\XML\saml\AttributeValue>
     */
    private function filterAttributeValues(array $reqValues, array $values): array
    {
        $result = [];

        foreach ($reqValues as $x) {
            foreach ($values as $y) {
                if ($x->getValue() === $y->getValue()) {
                    $result[] = $y;
                }
            }
        }

        return $result;
    }
}
