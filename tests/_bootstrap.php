<?php

// This is global bootstrap for autoloading

use tad\FunctionMocker\FunctionMocker;

require_once __DIR__ . '/../vendor/autoload.php';

include_once('_support/extra.php');

// Shared by the wpunit tests that need an account and a configuration answer.
// Required rather than autoloaded: this suite has no autoload-dev, and
// Codeception loads only `*Test.php` out of the suite directories.
include_once(__DIR__ . '/_support/ConfiguresMerchant.php');

FunctionMocker::init();
