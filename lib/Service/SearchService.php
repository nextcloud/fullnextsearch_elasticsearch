<?php
declare(strict_types=1);


/**
 * FullTextSearch_ElasticSearch - Use Elasticsearch to index the content of your nextcloud
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2018
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace OCA\FullTextSearch_ElasticSearch\Service;


use daita\MySmallPhpTools\Traits\TArrayTools;
use Elasticsearch\Client;
use Exception;
use OC\FullTextSearch\Model\DocumentAccess;
use OC\FullTextSearch\Model\IndexDocument;
use OCA\FullTextSearch_ElasticSearch\Exceptions\ConfigurationException;
use OCA\FullTextSearch_ElasticSearch\Exceptions\SearchQueryGenerationException;
use OCP\FullTextSearch\Model\IDocumentAccess;
use OCP\FullTextSearch\Model\IIndexDocument;
use OCP\FullTextSearch\Model\ISearchResult;


/**
 * Class SearchService
 *
 * @package OCA\FullTextSearch_ElasticSearch\Service
 */
class SearchService {


	use TArrayTools;


	/** @var SearchMappingService */
	private $searchMappingService;

	/** @var MiscService */
	private $miscService;


	/**
	 * SearchService constructor.
	 *
	 * @param SearchMappingService $searchMappingService
	 * @param MiscService $miscService
	 */
	public function __construct(
		SearchMappingService $searchMappingService, MiscService $miscService
	) {
		$this->searchMappingService = $searchMappingService;
		$this->miscService = $miscService;
	}

	/**
	 * @param Client $client
	 * @param ISearchResult $searchResult
	 * @param IDocumentAccess $access
	 *
	 * @throws Exception
	 */
	public function searchRequest(
		Client $client, ISearchResult $searchResult, IDocumentAccess $access
	) {
		try {
			$this->miscService->log('New Search Request; SearchResult Model: ' . json_encode($searchResult), 0);
			$query = $this->searchMappingService->generateSearchQuery(
				$searchResult->getRequest(), $access, $searchResult->getProvider()
																   ->getId()
			);
		} catch (SearchQueryGenerationException $e) {
			return;
		}

		try {
			$this->miscService->log('Searching ES: ' . json_encode($query['params']), 0);

			$result = $client->search($query['params']);
		} catch (Exception $e) {
			$this->miscService->log(
				'debug - request: ' . json_encode($searchResult->getRequest()) . '   - query: '
				. json_encode($query)
			);
			throw $e;
		}

		$this->miscService->log('Result from ES: ' . json_encode($result), 0);
		$this->updateSearchResult($searchResult, $result);

		foreach ($result['hits']['hits'] as $entry) {
			$searchResult->addDocument($this->parseSearchEntry($entry, $access->getViewerId()));
		}

		$this->miscService->log('Filled SearchResult Model: ' . json_encode($searchResult), 0);
	}


	/**
	 * @param Client $client
	 * @param string $providerId
	 * @param string $documentId
	 *
	 * @return IIndexDocument
	 * @throws ConfigurationException
	 */
	public function getDocument(Client $client, string $providerId, string $documentId
	): IIndexDocument {
		$query = $this->searchMappingService->getDocumentQuery($providerId, $documentId);
		$result = $client->get($query);

		$access = new DocumentAccess($result['_source']['owner']);
		$access->setUsers($result['_source']['users']);
		$access->setGroups($result['_source']['groups']);
		$access->setCircles($result['_source']['circles']);
		$access->setLinks($result['_source']['links']);

		$index = new IndexDocument($providerId, $documentId);
		$index->setAccess($access);
		$index->setMetaTags($result['_source']['metatags']);
		$index->setSubTags($result['_source']['subtags']);
		$index->setTags($result['_source']['tags']);
//		$index->setMore($result['_source']['more']);
		$index->setHash($result['_source']['hash']);
		$index->setSource($result['_source']['source']);
		$index->setTitle($result['_source']['title']);
		$index->setParts($result['_source']['parts']);

		$this->getDocumentInfos($index, $result['_source']);

		$content = $this->get('content', $result['_source'], '');
		$index->setContent($content);

		return $index;
	}


	/**
	 * @param IndexDocument $index
	 * @param $source
	 */
	private function getDocumentInfos(IndexDocument $index, $source) {
		$ak = array_keys($source);
		foreach ($ak as $k) {
			if (substr($k, 0, 5) !== 'info_') {
				continue;
			}
			$value = $source[$k];
			if (is_array($value)) {
				$index->setInfoArray($k, $value);
				continue;
			}

			if (is_bool($value)) {
				$index->setInfoBool($k, $value);
				continue;
			}

			if (is_numeric($value)) {
				$index->setInfoInt($k, (int)$value);
				continue;
			}

			$index->setInfo($k, (string)$value);
		}
	}


	/**
	 * @param ISearchResult $searchResult
	 * @param array $result
	 */
	private function updateSearchResult(ISearchResult $searchResult, array $result) {
		$searchResult->setRawResult(json_encode($result));

		$total = $result['hits']['total'];
		if (is_array($total)) {
			$total = $total['value'];
		}

		$searchResult->setTotal($total);
		$searchResult->setMaxScore($this->getInt('max_score', $result['hits'], 0));
		$searchResult->setTime($result['took']);
		$searchResult->setTimedOut($result['timed_out']);

		foreach ($result['aggregations']['subtags']['buckets'] as $bucket) {
			$searchResult->addAggregation("subtags", $bucket['key'], $bucket['doc_count']);
		}
	}


	/**
	 * @param array $entry
	 * @param string $viewerId
	 *
	 * @return IIndexDocument
	 */
	private function parseSearchEntry(array $entry, string $viewerId): IIndexDocument {
		$access = new DocumentAccess();
		$access->setViewerId($viewerId);

		list($providerId, $documentId) = explode(':', $entry['_id'], 2);
		$document = new IndexDocument($providerId, $documentId);
		$document->setAccess($access);
		$document->setHash($this->get('hash', $entry['_source']));
		$document->setScore($this->get('_score', $entry, '0'));
		$document->setSource($this->get('source', $entry['_source']));
		$document->setTitle($this->get('title', $entry['_source']));

		$document->setExcerpts(
			$this->parseSearchEntryExcerpts(
				(array_key_exists('highlight', $entry)) ? $entry['highlight'] : []
			)
		);

		return $document;
	}


	private function parseSearchEntryExcerpts(array $highlights): array {
		$result = [];
		foreach (array_keys($highlights) as $source) {
			foreach ($highlights[$source] as $highlight) {
				$result[] =
					[
						'source'  => $source,
						'excerpt' => $highlight
					];
			}
		}

		return $result;
	}

}

