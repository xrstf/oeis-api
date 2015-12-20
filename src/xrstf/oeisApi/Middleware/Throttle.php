<?php
/*
 * Copyright (c) 2015, xrstf | MIT licensed
 */

namespace xrstf\oeisApi\Middleware;

use BehEh\Flaps\Flap;
use BehEh\Flaps\Throttling\LeakyBucketStrategy;
use Symfony\Component\HttpFoundation\Request;
use xrstf\oeisApi\Application;
use xrstf\oeisApi\Exception\TooManyRequestsException;
use xrstf\oeisApi\FlapsStorage;

class Throttle {
	public function __invoke(Request $request, Application $app) {
		$flap = new Flap(new FlapsStorage($app['database']), 'api');
		$flap->pushThrottlingStrategy(new LeakyBucketStrategy(50, '1m'));

		if ($flap->isViolator($request->getClientIp())) {
			throw new TooManyRequestsException('You have reached the maximum allowed number of requests per minute. Calm down, buddy.');
		}
	}
}
