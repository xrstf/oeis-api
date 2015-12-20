<?php
/*
 * Copyright (c) 2015, xrstf | MIT licensed
 */

namespace xrstf\oeisApi\Sequence;

class Parser {
	private $doc;
	private $self;

	public function parseDocument($html) {
		$doc = new \DOMDocument();
		$doc->preserveWhiteSpace = false;

		if (!@$doc->loadHTML($html)) {
			return false;
		}

		$this->doc = $doc;

		// find all tables that look like they contain a sequence
		$tables    = $this->xpath(null, '//table[tr[3]//table/tr/td[1]/a]');
		$sequences = [];

		foreach ($tables as $table) {
			$sequences[] = $this->parse($table);
		}

		return $sequences;
	}

	public function parse(\DOMNode $node) {
		$this->doc = $node->ownerDocument;

		$sequence = [];
		$sequence = $this->parseHead($sequence, $node);
		$sequence = $this->parseBeginning($sequence, $node);

		// detail rows contain all the tagged stuff (be extra strict with selecting those, as they can contain
		// a lot of different markup)
		$detailsRows = $this->xpath($node, 'tr[5]/td/table/tr');

		assert(count($detailsRows) > 0, 'sequence must have at least one detail row');

		foreach ($detailsRows as $detailsRow) {
			$type      = strtolower($this->text($detailsRow, 'td[2]/font', 1, true));
			$lineNodes = $this->xpath($detailsRow, 'td[3]//tt');
			$lines     = [];

			foreach ($lineNodes as $lineNode) {
				$html = $this->toHTML($lineNode);

				// trim the surrounding <tt> tag
				$html = preg_replace('#^<tt>(.*?)</tt>$#', '$1', $html);

				$lines[] = [
					'text' => $this->toText($lineNode),
					'html' => $html,
					'node' => $lineNode
				];
			}

			// just in case we hit something that has no valid lines
			if (count($lines) > 0) {
				switch ($type) {
					case 'offset':      $sequence = $this->parseOffset($sequence, $lines);                 break;
					case 'status':      $sequence = $this->parseStatus($sequence, $lines);                 break;
					case 'author':      $sequence = $this->parseAuthor($sequence, $lines);                 break;
					case 'keyword':     $sequence = $this->parseKeyword($sequence, $lines);                break;
					case 'comments':    $sequence = $this->parseComments($sequence, $lines);               break;
					case 'references':  $sequence = $this->parseReferences($sequence, $lines);             break;
					case 'links':       $sequence = $this->parseLinks($sequence, $lines);                  break;
					case 'formula':     $sequence = $this->parseFormula($sequence, $lines);                break;
					case 'example':     $sequence = $this->parseExample($sequence, $lines);                break;
					case 'maple':       $sequence = $this->parseProgram($sequence, $lines, 'maple');       break;
					case 'mathematica': $sequence = $this->parseProgram($sequence, $lines, 'mathematica'); break;
					case 'prog':        $sequence = $this->parseProgram($sequence, $lines, 'other');       break;
					case 'crossrefs':   $sequence = $this->parseCrossrefs($sequence, $lines);              break;
					case 'extensions':  $sequence = $this->parseExtensions($sequence, $lines);             break;

					default:
						trigger_error("Unknown $type found in $sequence[identification][OEIS].", E_USER_WARNING);
						$sequence['junk'][$type] = $lines;
				}
			}
		}

		$sequence = new Sequence($sequence);

		// backup, in case we improve parsing later on and want to skip crawling everything again
		$sequence->setRawHTML($this->toHTML($node));

		return $sequence;
	}

	private function parseHead(array $sequence, \DOMNode $container) {
		$head = $this->xpath($container, 'tr[3]//table/tr', 1);

		// extract sequence ID
		$sequence['identification']['OEIS'] = $this->self = $this->text($head, 'td[1]/a', 1);

		// extract other IDs
		$tmp = $this->xpath($head, 'td[3]/font', 1);

		if ($tmp) {
			preg_match_all('/[MN]\d+/', $this->toText($tmp), $matches);

			foreach ($matches[0] as $match) {
				if ($match[0] === 'M') {
					$sequence['identification']['EOIS'] = $match;
				}
				elseif ($match[0] === 'N') {
					$sequence['identification']['HOIS'] = $match;
				}
				else {
					// should never happen
					$sequence['identification'][$match[0]] = $match;
				}
			}

			// remove the node so it doesn't show up in the sequence name we're about to extract
			$tmp->parentNode->removeChild($tmp);
		}

		// extract name
		$sequence['name'] = $this->text($head, 'td', 3, true);

		return $sequence;
	}

	private function parseBeginning(array $sequence, \DOMNode $container) {
		$numbers = $this->text($container, 'tr[4]//table//td[2]/tt', 1, true);
		$numbers = explode(', ', $numbers);

		$sequence['beginning'] = $numbers;

		return $sequence;
	}

	private function parseOffset(array $sequence, array $lines) {
		assert(count($lines) === 1, "{$this->self} expected only one line for OFFSET.");

		$line = explode(',', $lines[0]['text']);

		$sequence['offset'] = array_map('floatval', $line);

		return $sequence;
	}

	private function parseStatus(array $sequence, array $lines) {
		assert(count($lines) === 1, "{$this->self} expected only one line for STATUS.");

		$sequence['status'] = $lines[0]['text'];

		return $sequence;
	}

	private function parseAuthor(array $sequence, array $lines) {
		assert(count($lines) === 1, "{$this->self} expected only one line for AUTHOR.");

		$line = reset($lines);

		$sequence['authors'] = [
			'text'  => $line['text'],
			'html'  => $line['html'],
			'links' => $this->extractLinks($line['node'])
		];

		return $sequence;
	}

	private function parseKeyword(array $sequence, array $lines) {
		assert(count($lines) === 1, "{$this->self} expected only one line for KEYWORD.");

		$line     = reset($lines);
		$keywords = [];

		$nodes = $this->xpath($line['node'], 'span');

		assert($nodes->length > 0, "{$this->self} expected to see at least one keyword.");

		foreach ($nodes as $node) {
			$keyword     = $this->toText($node);
			$description = $node->getAttribute('title');

			assert(mb_strlen($description) > 0, "{$this->self} $keyword keyword description is empty.");

			$keywords[$keyword] = $description;
		}

		uksort($keywords, 'strnatcasecmp');

		$sequence['keywords'] = $keywords;

		return $sequence;
	}

	private function parseComments(array $sequence, array $lines) {
		$comments = [];

		foreach ($lines as $line) {
			$comments[] = [
				'text'  => $line['text'],
				'html'  => $line['html'],
				'links' => $this->extractLinks($line['node'])
			];
		}

		$sequence['comments'] = $comments;

		return $sequence;
	}

	private function parseReferences(array $sequence, array $lines) {
		foreach ($lines as $line) {
			$sequence['references'][] = $line['text'];
		}

		return $sequence;
	}

	private function parseLinks(array $sequence, array $lines) {
		$links = [];

		foreach ($lines as $line) {
			$links[] = [
				'text'  => $line['text'],
				'html'  => $line['html'],
				'links' => $this->extractLinks($line['node'])
			];
		}

		$sequence['links'] = $links;

		return $sequence;
	}

	private function parseFormula(array $sequence, array $lines) {
		$formulas = [];

		foreach ($lines as $line) {
			$formulas[] = [
				'text'  => $line['text'],
				'html'  => $line['html'],
				'links' => $this->extractLinks($line['node'])
			];
		}

		$sequence['formulas'] = $formulas;

		return $sequence;
	}

	private function parseExample(array $sequence, array $lines) {
		$examples = [];

		foreach ($lines as $line) {
			$examples[] = [
				'text'  => $line['text'],
				'html'  => $line['html'],
				'links' => $this->extractLinks($line['node'])
			];
		}

		$sequence['examples'] = $examples;

		return $sequence;
	}

	private function parseProgram(array $sequence, array $lines, $type) {
		$programs = [];

		foreach ($lines as $line) {
			$programs[] = [
				'text'  => $line['text'],
				'html'  => $line['html'],
				'links' => $this->extractLinks($line['node'])
			];
		}

		$sequence['programs'][$type] = $programs;

		return $sequence;
	}

	private function parseCrossrefs(array $sequence, array $lines) {
		$crossRefs = [];

		foreach ($lines as $line) {
			$crossRefs[] = [
				'text'  => $line['text'],
				'html'  => $line['html'],
				'links' => $this->extractLinks($line['node'])
			];
		}

		$sequence['crossrefs'] = $crossRefs;

		return $sequence;
	}

	private function parseExtensions(array $sequence, array $lines) {
		$extensions = [];

		foreach ($lines as $line) {
			$extensions[] = [
				'text'  => $line['text'],
				'html'  => $line['html'],
				'links' => $this->extractLinks($line['node'])
			];
		}

		$sequence['extensions'] = $extensions;

		return $sequence;
	}

	private function toHTML($node) {
		// C14N does a good job, but we need to manually transform the non-breaking spaces (0xC2 0xA0)
		$html = $node->C14N(false, false);

		// transform non-breaking spaces (0xC2 0xA0) into regular spaces
		$html = str_replace("\xC2\xA0", '&nbsp;', $html);

		return $html;
	}

	private function toText($node, $trim = false) {
		$text = $node->textContent;

		// transform non-breaking spaces (0xC2 0xA0) into regular spaces
		$text = str_replace("\xC2\xA0", ' ', $text);

		if ($trim) {
			$text = trim($text);
		}
		else {
			$text = rtrim($text);
		}

		return $text;
	}

	private function text($context, $xpath, $position = 1, $trim = false) {
		$node = $this->xpath($context, $xpath, $position);

		if ($node) {
			return $this->toText($node, $trim);
		}

		return '';
	}

	private function first($context, $query) {
		return $this->xpath($context, $query, 1);
	}

	private function xpath($context, $query, $position = -1) {
		$xpath = new \DOMXPath($this->doc);
		$nodes = $xpath->query($query, $context);

		if ($position == -1) {
			return $nodes;
		}

		if ($position > $nodes->length) {
			return null;
		}

		return $nodes[$position-1];
	}

	private function makeAbsoluteUrl($href) {
		if (substr($href, 0, 4) !== 'http') {
			$href = 'https://oeis.org'.$href;
		}

		return $href;
	}

	private function extractLinks($node) {
		$anchors = $this->xpath($node, 'a');
		$links   = [];

		foreach ($anchors as $anchor) {
			$text = $this->toText($anchor);
			$href = $anchor->getAttribute('href');
			$url  = $this->makeAbsoluteUrl($href);
			$seq  = !!preg_match('/^A\d+$/', $text);
			$user = substr($href, 0, 11) === '/wiki/User:';

			if ($seq) {
				$link = [
					'rel' => 'sequence',
					'id'  => $text
				];
			}
			elseif ($user) {
				preg_match('/User:(.*?)$/', $href, $match);

				$link = [
					'rel'  => 'user',
					'name' => $text,
					'slug' => $match[1]
				];
			}
			else {
				$link = [
					'rel' => 'external',
					'uri' => $url
				];
			}

			$links[] = $link;
		}

		return $links;
	}
}
