<?php

$metadata = \SimpleSAML\Metadata\MetaDataStorageHandler::getMetadataHandler();

$binding = \SAML2\Binding::getCurrentBinding();
$query = $binding->receive();
if (!($query instanceof \SAML2\AttributeQuery)) {
    throw new \SimpleSAML\Error\BadRequest('Invalid message received to AttributeQuery endpoint.');
}

$idpEntityId = $metadata->getMetaDataCurrentEntityID('saml20-idp-hosted');

$issuer = $query->getIssuer();
if ($issuer === null) {
    throw new \SimpleSAML\Error\BadRequest('Missing <saml:Issuer> in <samlp:AttributeQuery>.');
} else {
    $spEntityId = $issuer->getValue();
    if ($spEntityId === '') {
        throw new \SimpleSAML\Error\BadRequest('Empty <saml:Issuer> in <samlp:AttributeQuery>.');
    }
}

$idpMetadata = $metadata->getMetaDataConfig($idpEntityId, 'saml20-idp-hosted');
$spMetadata = $metadata->getMetaDataConfig($spEntityId, 'saml20-sp-remote');

// The endpoint we should deliver the message to
$endpoint = $spMetadata->getString('testAttributeEndpoint');

// The attributes we will return
$attributes = [
    'name' => ['value1', 'value2', 'value3'],
    'test' => ['test'],
];

// The name format of the attributes
$attributeNameFormat = \SAML2\Constants::NAMEFORMAT_UNSPECIFIED;

// Determine which attributes we will return
$returnAttributes = array_keys($query->getAttributes());
if (count($returnAttributes) === 0) {
    SimpleSAML\Logger::debug('No attributes requested - return all attributes.');
    $returnAttributes = $attributes;
} elseif ($query->getAttributeNameFormat() !== $attributeNameFormat) {
    SimpleSAML\Logger::debug('Requested attributes with wrong NameFormat - no attributes returned.');
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
$issuer = new \SAML2\XML\saml\Issuer();
$issuer->setValue($idpEntityId);

$assertion = new \SAML2\Assertion();
$assertion->setIssuer($issuer);
$assertion->setNameId($query->getNameId());
$assertion->setNotBefore(time());
$assertion->setNotOnOrAfter(time() + 300); // 60*5 = 5min
$assertion->setValidAudiences([$spEntityId]);
$assertion->setAttributes($returnAttributes);
$assertion->setAttributeNameFormat($attributeNameFormat);

$sc = new \SAML2\XML\saml\SubjectConfirmation();
$sc->setMethod(\SAML2\Constants::CM_BEARER);

$scd = new \SAML2\XML\saml\SubjectConfirmationData();
$scd->setNotOnOrAfter(time() + 300); // 60*5 = 5min
$scd->setRecipient($endpoint);
$scd->setInResponseTo($query->getId());
$sc->setSubjectConfirmationData($scd);
$assertion->setSubjectConfirmation([$sc]);

\SimpleSAML\Module\saml\Message::addSign($idpMetadata, $spMetadata, $assertion);

$response = new \SAML2\Response();
$response->setRelayState($query->getRelayState());
$response->setDestination($endpoint);
$response->setIssuer($issuer);
$response->setInResponseTo($query->getId());
$response->setAssertions([$assertion]);
\SimpleSAML\Module\saml\Message::addSign($idpMetadata, $spMetadata, $response);

$binding = new \SAML2\HTTPPost();
$binding->send($response);