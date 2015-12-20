<?php
/*
 * Copyright (c) 2015, xrstf | MIT licensed
 */

namespace xrstf\oeisApi\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use xrstf\oeisApi\Application;
use xrstf\oeisApi\SequenceParser;

class SequenceController {
	protected $app;

	public function __construct(Application $app) {
		$this->app = $app;
	}

	public function searchAction(Request $request) {
		$query    = $request->query;
		$keywords = trim($query->get('search'));

		if (mb_strlen($keywords) === 0) {
			throw new Ex\BadRequestException('Missing `search` parameter in query string.');
		}

		$sort       = $query->get('sort', 'relevance');
		$sortValues = ['relevance', 'references', 'number', 'modified', 'created'];

		if (!in_array($sort, $sortValues, true)) {
			throw new Ex\BadRequestException('Invalid `sort` parameter. Possible values are: '.implode(', ', $sortValues));
		}

		$page = $query->getInt('page', 1);

		if ($page < 1) {
			throw new Ex\BadRequestException('`page` parameter is out of range. Smallest allowed value is 1.');
		}

		$query = ['q' => $keywords, 'sort' => $sort, 'start' => ($page-1)*10, 'fmt' => 'text'];
		$text  = @file_get_contents('https://oeis.org/search?'.http_build_query($query, '', '&'));

		if ($text === false) {
			throw new Ex\GatewayTimeoutException('The OEIS did not respond in a timely fashion, sorry.');
		}

		$lines         = explode("\n", $text);
		$totalHits     = 0;
		$currentOffset = 0;
		$sequences     = [];
		$sequenceLines = [];

		foreach ($lines as $idx => $line) {
			$line = trim($line);

			if (strlen($line) === 0 || $line[0] === '#') {
				continue;
			}

			if ($line[0] === '%') {
				// a new sequence started, put the old one on the heap
				if ($line[1] === SequenceParser::LINE_IDENTIFICATION && count($sequenceLines) > 0) {
					$sequences[]   = $sequenceLines;
					$sequenceLines = [];
				}

				$sequenceLines[] = $line;
			}
			elseif (substr($line, 0, 8) === 'Showing ') {
				if (preg_match('/Showing ([0-9]+)-[0-9]+ of ([0-9]+)/', $line, $match)) {
					$currentOffset = $match[1] - 1;
					$totalHits     = (int) $match[2];
				}
			}
		}

		if (count($sequenceLines) > 0) {
			$sequences[] = $sequenceLines;
		}

		$parser = new SequenceParser();

		foreach ($sequences as $idx => $sequenceLines) {
			$sequences[$idx] = $parser->parse($sequenceLines);
		}

		return $this->respondWithArray($sequences);
	}

	public function viewAction(Request $request) {
		$sequenceID = $request->attributes->get('id');

		if (!preg_match('/^A[0-9]+$/', $sequenceID)) {
			throw new Ex\NotFoundException('Unknown sequence ID given.');
		}

		$text = @file_get_contents("https://oeis.org/search?q=id:$sequenceID&fmt=text");

		if ($text === false) {
			throw new Ex\GatewayTimeoutException('The OEIS did not respond in a timely fashion, sorry.');
		}

		$lines         = explode("\n", $text);
		$sequenceLines = [];

		foreach ($lines as $idx => $line) {
			$line = trim($line);

			if (strlen($line) === 0 || $line[0] === '#') {
				continue;
			}

			if ($line[0] === '%') {
				$sequenceLines[] = $line;
			}
		}

		if (count($sequenceLines) === 0) {
			throw new Ex\NotFoundException('There is no sequence '.$sequenceID.'.');
		}

		$parser   = new SequenceParser();
		$sequence = $parser->parse($sequenceLines);

		return $this->respondWithArray($sequence);
	}

	protected function respondWithArray($content = [], $status = 200, array $headers = []) {
		$response = new JsonResponse($content, $status, $headers);

		$response->setExpires(new \DateTime('1924-10-10 12:00:00 UTC'));
		$response->headers->addCacheControlDirective('no-cache', true);
		$response->headers->addCacheControlDirective('private', true);

		return $response;
	}
}
