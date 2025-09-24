<?php

declare(strict_types=1);

namespace SimpleSAML\Module\exampleattributeserver\Controller;

use DateInterval;
use Nyholm\Psr7\ServerRequest;
use SimpleSAML\{Configuration, Error, Logger};
use SimpleSAML\HTTP\RunnableResponse;
use SimpleSAML\Metadata\MetaDataStorageHandler;
use SimpleSAML\SAML2\Binding\{SynchronousBindingInterface, SOAP};
use SimpleSAML\SAML2\Constants as C;
use SimpleSAML\SAML2\Utils as SAML2_Utils;
use SimpleSAML\SAML2\XML\saml\{
    Assertion,
    Attribute,
    AttributeStatement,
    AttributeValue,
    Audience,
    AudienceRestriction,
    Conditions,
    Issuer,
    Subject,
    SubjectConfirmation,
    SubjectConfirmationData,
};
use SimpleSAML\SAML2\XML\samlp\{AttributeQuery, Response, Status, StatusCode};
use SimpleSAML\Utils;
use SimpleSAML\XML\Utils\Random;
use SimpleSAML\XMLSecurity\Alg\Signature\SignatureAlgorithmFactory;
use SimpleSAML\XMLSecurity\Key\PrivateKey;
use SimpleSAML\XMLSecurity\XML\ds\{KeyInfo, X509Certificate, X509Data};
use SimpleSAML\XMLSecurity\XML\SignableElementInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\{HttpFoundationFactory, PsrHttpFactory};

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
        $message = $soap->receive($request);
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
                ],
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
            statements: $statements,
        );

        self::addSign($idpMetadata, $spMetadata, $assertion);

        $response = new Response(
            status: new Status(
                new StatusCode(C::STATUS_SUCCESS),
            ),
            issueInstant: $clock->now(),
            issuer: $issuer,
            id: (new Random())->generateID(),
            version: '2.0',
            inResponseTo: $message->getId(),
            destination: $endpoint,
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
