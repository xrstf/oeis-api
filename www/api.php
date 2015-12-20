<?php
/*
 * Copyright (c) 2015, xrstf | MIT licensed
 */

define('OEIS_ROOT', dirname(__DIR__));
require OEIS_ROOT.'/vendor/autoload.php';

Symfony\Component\HttpFoundation\Request::enableHttpMethodParameterOverride();

mb_internal_encoding('UTF-8');

$app = new xrstf\oeisApi\Application();
$app->run();
