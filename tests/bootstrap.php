<?php

declare(strict_types=1);

use Beste\Clock\LocalizedClock;
use SimpleSAML\SAML2\Compat\ContainerSingleton;
use SimpleSAML\SAML2\Compat\MockContainer;

$projectRoot = dirname(__DIR__);
require_once($projectRoot . '/vendor/autoload.php');

// Symlink module into ssp vendor lib so that templates and urls can resolve correctly
$linkPath = $projectRoot . '/vendor/simplesamlphp/simplesamlphp/modules/exampleattributeserver';
if (file_exists($linkPath) === false) {
    echo "Linking '$linkPath' to '$projectRoot'\n";
    symlink($projectRoot, $linkPath);
}

// Load the system clock
$systemClock = LocalizedClock::in(new DateTimeZone('Z'));

// And set the Mock container as the Container to use.
$container = new MockContainer();
$container->setClock($systemClock);
ContainerSingleton::setContainer($container);
