<?php
/*
 * Copyright (c) 2015, xrstf | MIT licensed
 */

namespace xrstf\oeisApi;

use Silex\Application as SilexApp;
use Silex\Provider;

class Application extends SilexApp {
	public function __construct(array $values = []) {
		parent::__construct($values);

		$this->register(new Provider\ServiceControllerServiceProvider());

		$this->setupServices();
		$this->setupRouting();
	}

	public function setupServices() {
		$this['user'] = null;

		$this['config'] = $this->share(function() {
			$file = OEIS_ROOT.'/resources/config.json';

			if (file_exists($file)) {
				return json_decode(file_get_contents($file), true);
			}

			return [];
		});

		$this['database'] = $this->share(function() {
			$config = $this['config']['database'];
			$db     = new \mysqli($config['host'], $config['username'], $config['password'], $config['database'], $config['port']);

			$db->set_charset('utf8');

			return new Database($db);
		});

		$this['controller.sequence'] = $this->share(function() {
			return new Controller\SequenceController($this);
		});

		// set Silex' debug flag
		$this['debug'] = !empty($this['config']['debug']);
	}

	public function setupRouting() {
		$this->get('/sequences',      'controller.sequence:searchAction');
		$this->get('/sequences/{id}', 'controller.sequence:viewAction');
	}
}
