# SimpleSAMLphp Example Attribute Server

![Build Status](https://github.com/simplesamlphp/simplesamlphp-module-exampleattributeserver/actions/workflows/php.yml/badge.svg)
[![Coverage Status](https://codecov.io/gh/simplesamlphp/simplesamlphp-module-exampleattributeserver/branch/master/graph/badge.svg)](https://codecov.io/gh/simplesamlphp/simplesamlphp-module-exampleattributeserver)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/simplesamlphp/simplesamlphp-module-exampleattributeserver/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/simplesamlphp/simplesamlphp-module-exampleattributeserver/?branch=master)
[![Type Coverage](https://shepherd.dev/github/simplesamlphp/simplesamlphp-module-exampleattributeserver/coverage.svg)](https://shepherd.dev/github/simplesamlphp/simplesamlphp-module-exampleattributeserver)
[![Psalm Level](https://shepherd.dev/github/simplesamlphp/simplesamlphp-module-exampleattributeserver/level.svg)](https://shepherd.dev/github/simplesamlphp/simplesamlphp-module-exampleattributeserver)

## Install

Install with composer

```bash
vendor/bin/composer require simplesamlphp/simplesamlphp-module-exampleattributeserver
```

## Configuration

Next thing you need to do is to enable the module:

in `config.php`, search for the `module.enable` key and set `exampleattributeserver` to true:

```php
    'module.enable' => [ 'exampleattributeserver' => true, … ],
```
