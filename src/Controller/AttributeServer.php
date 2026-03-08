<?php

declare(strict_types=1);

namespace SimpleSAML\Module\exampleattributeserver\Controller;

use DateInterval;
use Nyholm\Psr7\ServerRequest;
use SimpleSAML\Assert\Assert;
use SimpleSAML\Configuration;
use SimpleSAML\Error;
use SimpleSAML\HTTP\RunnableResponse;
use SimpleSAML\Logger;
use SimpleSAML\Metadata\MetaDataStorageHandler;
use SimpleSAML\SAML2\Binding\SOAP;
use SimpleSAML\SAML2\Constants as C;
use SimpleSAML\SAML2\Type\EntityIDValue;
use SimpleSAML\SAML2\Type\SAMLAnyURIValue;
use SimpleSAML\SAML2\Type\SAMLDateTimeValue;
use SimpleSAML\SAML2\Type\SAMLStringValue;
use SimpleSAML\SAML2\Utils as SAML2_Utils;
use SimpleSAML\SAML2\XML\saml\Assertion;
use SimpleSAML\SAML2\XML\saml\Attribute;
use SimpleSAML\SAML2\XML\saml\AttributeStatement;
use SimpleSAML\SAML2\XML\saml\AttributeValue;
use SimpleSAML\SAML2\XML\saml\Audience;
use SimpleSAML\SAML2\XML\saml\AudienceRestriction;
use SimpleSAML\SAML2\XML\saml\Conditions;
use SimpleSAML\SAML2\XML\saml\Issuer;
use SimpleSAML\SAML2\XML\saml\Subject;
use SimpleSAML\SAML2\XML\saml\SubjectConfirmation;
use SimpleSAML\SAML2\XML\saml\SubjectConfirmationData;
use SimpleSAML\SAML2\XML\samlp\AttributeQuery;
use SimpleSAML\SAML2\XML\samlp\Response;
use SimpleSAML\SAML2\XML\samlp\Status;
use SimpleSAML\SAML2\XML\samlp\StatusCode;
use SimpleSAML\Utils;
use SimpleSAML\XML\Utils\Random;
use SimpleSAML\XMLSchema\Exception\InvalidDOMElementException;
use SimpleSAML\XMLSchema\Type\IDValue;
use SimpleSAML\XMLSecurity\Alg\Signature\SignatureAlgorithmFactory;
use SimpleSAML\XMLSecurity\Key\PrivateKey;
use SimpleSAML\XMLSecurity\XML\ds\KeyInfo;
use SimpleSAML\XMLSecurity\XML\ds\X509Certificate;
use SimpleSAML\XMLSecurity\XML\ds\X509Data;
use SimpleSAML\XMLSecurity\XML\SignableElementInterface;

use function array_filter;

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
     * @param \Nyholm\Psr7\ServerRequest $request The current request.
     *
     * @return \SimpleSAML\HTTP\RunnableResponse
     * @throws \SimpleSAML\Error\BadRequest
     */
    public function main(/** @scrutinizer ignore-unused */ SOAP $soap, ServerRequest $request): RunnableResponse
    {
        /** @var \SimpleSAML\SAML2\XML\samlp\AttributeQuery $message */
        $message = $soap->receive($request);
        Assert::isInstanceOf($message, AttributeQuery::class, InvalidDOMElementException::class);

        $idpEntityId = $this->metadataHandler->getMetaDataCurrentEntityID('saml20-idp-hosted');

        $issuer = $message->getIssuer();
        if ($issuer === null) {
            throw new Error\BadRequest('Missing <saml:Issuer> in <samlp:AttributeQuery>.');
        } else {
            $spEntityId = $issuer->getContent();
        }

        $idpMetadata = $this->metadataHandler->getMetaDataConfig($idpEntityId, 'saml20-idp-hosted');
        $spMetadata = $this->metadataHandler->getMetaDataConfig($spEntityId->getValue(), 'saml20-sp-remote');

        // The endpoint we should deliver the message to
        $endpoint = $spMetadata->getString('testAttributeEndpoint');

        // The attributes we will return
        $attributes = [
            new Attribute(
                SAMLStringValue::fromString('name'),
                SAMLAnyURIValue::fromString(C::NAMEFORMAT_UNSPECIFIED),
                null,
                [
                    new AttributeValue(SAMLStringValue::fromString('value1')),
                    new AttributeValue(SAMLStringValue::fromString('value2')),
                    new AttributeValue(SAMLStringValue::fromString('value3')),
                ],
            ),
            new Attribute(
                SAMLStringValue::fromString('test'),
                SAMLAnyURIValue::fromString(C::NAMEFORMAT_UNSPECIFIED),
                null,
                [
                    new AttributeValue(SAMLStringValue::fromString('test')),
                ],
            ),
        ];

        // Determine which attributes we will return
        // @phpstan-ignore identical.alwaysFalse
        if (count($attributes) === 0) {
            Logger::debug('No attributes requested - return all attributes.');
            $attributeStatement = null;
        } else {
            $returnAttributes = [];
            foreach ($message->getAttributes() as $reqAttr) {
                foreach ($attributes as $attr) {
                    if (
                        $attr->getName() === $reqAttr->getName()
                        && $attr->getNameFormat() === $reqAttr->getNameFormat()
                    ) {
                        // The requested attribute is available
                        if ($reqAttr->getAttributeValues() === []) {
                            // If no specific values are requested, return all
                            $returnAttributes[] = $attr;
                        } else {
                            $returnValues = $this->filterAttributeValues(
                                $reqAttr->getAttributeValues(),
                                $attr->getAttributeValues(),
                            );

                            $returnAttributes[] = new Attribute(
                                $attr->getName(),
                                $attr->getNameFormat(),
                                null,
                                $returnValues,
                                $attr->getAttributesNS(),
                            );
                        }
                    }
                }
            }

            $attributeStatement = $returnAttributes ? (new AttributeStatement($returnAttributes)) : null;
        }

        // $returnAttributes contains the attributes we should return. Send them
        $clock = SAML2_Utils::getContainer()->getClock();

        $statements = array_filter([$attributeStatement]);
        $assertion = new Assertion(
            issuer: Issuer::fromString($idpEntityId),
            issueInstant: SAMLDateTimeValue::fromDateTime($clock->now()),
            id: IDValue::fromString((new Random())->generateID()),
            subject: new Subject(
                identifier: $message->getSubject()->getIdentifier(),
                subjectConfirmation: [
                    new SubjectConfirmation(
                        method: SAMLAnyURIValue::fromString(C::CM_BEARER),
                        subjectConfirmationData: new SubjectConfirmationData(
                            notOnOrAfter: SAMLDateTimeValue::fromDateTime(
                                $clock->now()->add(new DateInterval('PT300S')),
                            ),
                            recipient: EntityIDValue::fromString($endpoint),
                            inResponseTo: $message->getId(),
                        ),
                    ),
                ],
            ),
            conditions: new Conditions(
                notBefore: SAMLDateTimeValue::fromDateTime($clock->now()),
                notOnOrAfter: SAMLDateTimeValue::fromDateTime(
                    $clock->now()->add(new DateInterval('PT300S')),
                ),
                audienceRestriction: [
                    new AudienceRestriction([
                        Audience::fromString($spEntityId->getValue()),
                    ]),
                ],
            ),
            statements: $statements,
        );

        self::addSign($idpMetadata, $spMetadata, $assertion);

        $response = new Response(
            status: new Status(
                new StatusCode(SAMLAnyURIValue::fromString(C::STATUS_SUCCESS)),
            ),
            issueInstant: SAMLDateTimeValue::fromDateTime($clock->now()),
            issuer: $issuer,
            id: IDValue::fromString((new Random())->generateID()),
            inResponseTo: $message->getId(),
            destination: SAMLAnyURIValue::fromString($endpoint),
            assertions: [$assertion],
        );

        self::addSign($idpMetadata, $spMetadata, $response);

        $soap = new SOAP();
        return new RunnableResponse([$soap, 'send'], [$response]);
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


    /**
     * @deprecated This method is a modified version of \SimpleSAML\Module\saml\Message::addSign and
     *  should be replaced with a call to a future ServiceProvider-class in the saml2-library
     *
     * Add signature key and sender certificate to an element (Message or Assertion).
     *
     * @param \SimpleSAML\Configuration $srcMetadata The metadata of the sender.
     * @param \SimpleSAML\Configuration $dstMetadata The metadata of the recipient.
     * @param \SimpleSAML\XMLSecurity\XML\SignableElementInterface $element The element we should add the data to.
     */
    private static function addSign(
        Configuration $srcMetadata,
        Configuration $dstMetadata,
        SignableElementInterface &$element,
    ): void {
        $dstPrivateKey = $dstMetadata->getOptionalString('signature.privatekey', null);
        $cryptoUtils = new Utils\Crypto();

        if ($dstPrivateKey !== null) {
            /** @var string[] $keyArray */
            $keyArray = $cryptoUtils->loadPrivateKey($dstMetadata, true, 'signature.');
            $certArray = $cryptoUtils->loadPublicKey($dstMetadata, false, 'signature.');
        } else {
            /** @var string[] $keyArray */
            $keyArray = $cryptoUtils->loadPrivateKey($srcMetadata, true);
            $certArray = $cryptoUtils->loadPublicKey($srcMetadata, false);
        }

        $algo = $dstMetadata->getOptionalString('signature.algorithm', null);
        if ($algo === null) {
            $algo = $srcMetadata->getOptionalString('signature.algorithm', C::SIG_RSA_SHA256);
        }

        $privateKey = PrivateKey::fromFile($keyArray['PEM'], $keyArray['password']);

        $keyInfo = null;
        if ($certArray !== null) {
            $keyInfo = new KeyInfo([
                new X509Data(
                    [
                        new X509Certificate($certArray['PEM']),
                    ],
                ),
            ]);
        }

        $signer = (new SignatureAlgorithmFactory())->getAlgorithm(
            $algo,
            $privateKey,
        );

        $element->sign($signer, C::C14N_EXCLUSIVE_WITHOUT_COMMENTS, $keyInfo);
    }
}
