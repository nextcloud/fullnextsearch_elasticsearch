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


use OCA\FullTextSearch_ElasticSearch\Exceptions\ConfigurationException;
use OCA\FullTextSearch_ElasticSearch\Exceptions\QueryContentGenerationException;
use OCA\FullTextSearch_ElasticSearch\Exceptions\SearchQueryGenerationException;
use OCA\FullTextSearch_ElasticSearch\Model\QueryContent;
use OCP\FullTextSearch\Model\IDocumentAccess;
use OCP\FullTextSearch\Model\ISearchRequest;
use OCP\FullTextSearch\Model\ISearchRequestSimpleQuery;
use stdClass;


/**
 * Class SearchMappingService
 *
 * @package OCA\FullTextSearch_ElasticSearch\Service
 */
class SearchMappingService {

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	/**
	 * SearchMappingService constructor.
	 *
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(ConfigService $configService, MiscService $miscService) {
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 * @param ISearchRequest $request
	 * @param IDocumentAccess $access
	 * @param string $providerId
	 *
	 * @return array
	 * @throws ConfigurationException
	 * @throws SearchQueryGenerationException
	 */
	public function generateSearchQuery(
		ISearchRequest $request, IDocumentAccess $access, string $providerId
	): array {
		$query['params'] = $this->generateSearchQueryParams($request, $access, $providerId);

		return $query;
	}


	/**
	 * @param ISearchRequest $request
	 * @param IDocumentAccess $access
	 * @param string $providerId
	 *
	 * @return array
	 * @throws ConfigurationException
	 * @throws SearchQueryGenerationException
	 */
	public function generateSearchQueryParams(
		ISearchRequest $request, IDocumentAccess $access, string $providerId
	): array {
		$params = [
			'index' => $this->configService->getElasticIndex(),
			'type'  => 'standard',
			'size'  => $request->getSize(),
			'from'  => (($request->getPage() - 1) * $request->getSize())
		];

		$bool = [];
		if ($request->getSearch() !== '') {
			$bool['must']['bool'] = $this->generateSearchQueryContent($request);
		}

		$bool['filter'][]['bool']['must'] = ['term' => ['provider' => $providerId]];
		$bool['filter'][]['bool']['should'] = $this->generateSearchQueryAccess($access);
		$bool['filter'][]['bool']['should'] =
			$this->generateSearchQueryTags('metatags', $request->getMetaTags());

		$bool['filter'][]['bool']['must'] =
			$this->generateSearchQueryTags('subtags', $request->getSubTags(true));

		$bool['filter'][]['bool']['must'] =
			$this->generateSearchSimpleQuery($request->getSimpleQueries());

//		$bool['filter'][]['bool']['should'] = $this->generateSearchQueryTags($request->getTags());

		$params['body']['query']['bool'] = $bool;
		$params['body']['highlight'] = $this->generateSearchHighlighting($request);

		$this->improveSearchQuerying($request, $params['body']['query']);

		$params['body']['aggs']['subtags']['terms']['field'] = "subtags";
		return $params;
	}


	/**
	 * @param ISearchRequest $request
	 * @param array $arr
	 */
	private function improveSearchQuerying(ISearchRequest $request, array &$arr) {
//		$this->improveSearchWildcardQueries($request, $arr);
		$this->improveSearchWildcardFilters($request, $arr);
		$this->improveSearchRegexFilters($request, $arr);
	}


//	/**
//	 * @param SearchRequest $request
//	 * @param array $arr
//	 */
//	private function improveSearchWildcardQueries(SearchRequest $request, &$arr) {
//
//		$queries = $request->getWildcardQueries();
//		foreach ($queries as $query) {
//			$wildcards = [];
//			foreach ($query as $entry) {
//				$wildcards[] = ['wildcard' => $entry];
//			}
//
//			array_push($arr['bool']['must']['bool']['should'], $wildcards);
//		}
//
//	}


	/**
	 * @param ISearchRequest $request
	 * @param array $arr
	 */
	private function improveSearchWildcardFilters(ISearchRequest $request, array &$arr) {

		$filters = $request->getWildcardFilters();
		foreach ($filters as $filter) {
			$wildcards = [];
			foreach ($filter as $entry) {
				$wildcards[] = ['wildcard' => $entry];
			}

			$arr['bool']['filter'][]['bool']['should'] = $wildcards;
		}

	}


	/**
	 * @param ISearchRequest $request
	 * @param array $arr
	 */
	private function improveSearchRegexFilters(ISearchRequest $request, array &$arr) {

		$filters = $request->getRegexFilters();
		foreach ($filters as $filter) {
			$regex = [];
			foreach ($filter as $entry) {
				$regex[] = ['regexp' => $entry];
			}

			$arr['bool']['filter'][]['bool']['should'] = $regex;
		}

	}


	/**
	 * @param ISearchRequest $request
	 *
	 * @return array
	 * @throws SearchQueryGenerationException
	 */
	private function generateSearchQueryContent(ISearchRequest $request): array {
		$str = strtolower($request->getSearch());

		preg_match_all('/[^?]"(?:\\\\.|[^\\\\"])*"|\S+/', " $str ", $words);
		$queryContent = [];
		foreach ($words[0] as $word) {
			try {
				$queryContent[] = $this->generateQueryContent(trim($word));
			} catch (QueryContentGenerationException $e) {
				continue;
			}
		}

		if (sizeof($queryContent) === 0) {
			throw new SearchQueryGenerationException();
		}

		return $this->generateSearchQueryFromQueryContent($request, $queryContent);
	}


	/**
	 * @param string $word
	 *
	 * @return QueryContent
	 * @throws QueryContentGenerationException
	 */
	private function generateQueryContent(string $word): QueryContent {

		$searchQueryContent = new QueryContent($word);
		if (strlen($searchQueryContent->getWord()) === 0) {
			throw new QueryContentGenerationException();
		}

		return $searchQueryContent;
	}


	/**
	 * @param ISearchRequest $request
	 * @param QueryContent[] $contents
	 *
	 * @return array
	 */
	private function generateSearchQueryFromQueryContent(ISearchRequest $request, array $contents): array {
		$query = [];
		foreach ($contents as $content) {
			if (!array_key_exists($content->getShould(), $query)) {
				$query[$content->getShould()] = [];
			}

			if ($content->getShould() === 'must') {
				$query[$content->getShould()][] =
					['bool' => ['should' => $this->generateQueryContentFields($request, $content)]];
			} else {
				$query[$content->getShould()] = array_merge(
					$query[$content->getShould()], $this->generateQueryContentFields($request, $content)
				);
			}
		}

		return $query;
	}


	/**
	 * @param ISearchRequest $request
	 * @param QueryContent $content
	 *
	 * @return array
	 */
	private function generateQueryContentFields(ISearchRequest $request, QueryContent $content): array {
		$queryFields = [];

		$fields = array_merge(['content', 'title'], $request->getFields());
		foreach ($fields as $field) {
			if (!$this->fieldIsOutLimit($request, $field)) {
				$queryFields[] = [$content->getMatch() => [$field => $content->getWord()]];
			}
		}

		foreach ($request->getWildcardFields() as $field) {
			if (!$this->fieldIsOutLimit($request, $field)) {
				$queryFields[] = ['wildcard' => [$field => '*' . $content->getWord() . '*']];
			}
		}

		$parts = [];
		foreach ($this->getPartsFields($request) as $field) {
			if (!$this->fieldIsOutLimit($request, $field)) {
				$parts[] = $field;
			}
		}

		if (sizeof($parts) > 0) {
			$queryFields[] = [
				'query_string' => [
					'fields' => $parts,
					'query'  => $content->getWord()
				]
			];
		}

		return $queryFields;
	}


	/**
	 * @param IDocumentAccess $access
	 *
	 * @return array
	 */
	private function generateSearchQueryAccess(IDocumentAccess $access): array {

		$query = [];
		$query[] = ['term' => ['owner' => $access->getViewerId()]];
		$query[] = ['term' => ['users' => $access->getViewerId()]];
		$query[] = ['term' => ['users' => '__all']];

		foreach ($access->getGroups() as $group) {
			$query[] = ['term' => ['groups' => $group]];
		}

		foreach ($access->getCircles() as $circle) {
			$query[] = ['term' => ['circles' => $circle]];
		}

		return $query;
	}


	/**
	 * @param ISearchRequest $request
	 * @param string $field
	 *
	 * @return bool
	 */
	private function fieldIsOutLimit(ISearchRequest $request, string $field): bool {
		$limit = $request->getLimitFields();
		if (sizeof($limit) === 0) {
			return false;
		}

		if (in_array($field, $limit)) {
			return false;
		}

		return true;
	}


	/**
	 * @param string $k
	 * @param array $tags
	 *
	 * @return array
	 */
	private function generateSearchQueryTags(string $k, array $tags): array {

		$query = [];
		foreach ($tags as $t) {
			$query[] = ['term' => [$k => $t]];
		}

		return $query;
	}


	/**
	 * @param ISearchRequestSimpleQuery[] $queries
	 *
	 * @return array
	 */
	private function generateSearchSimpleQuery(array $queries): array {
		$simpleQuery = [];
		foreach ($queries as $query) {
			// TODO: manage multiple entries array

			if ($query->getType() === ISearchRequestSimpleQuery::COMPARE_TYPE_KEYWORD) {
				$value = $query->getValues()[0];
				$simpleQuery[] = ['term' => [$query->getField() => $value]];
			}

			if ($query->getType() === ISearchRequestSimpleQuery::COMPARE_TYPE_WILDCARD) {
				$value = $query->getValues()[0];
				$simpleQuery[] = ['wildcard' => [$query->getField() => $value]];
			}

			if ($query->getType() === ISearchRequestSimpleQuery::COMPARE_TYPE_INT_EQ) {
				$value = $query->getValues()[0];
				$simpleQuery[] = ['term' => [$query->getField() => $value]];
			}

			if ($query->getType() === ISearchRequestSimpleQuery::COMPARE_TYPE_INT_GTE) {
				$value = $query->getValues()[0];
				$simpleQuery[] = ['range' => [$query->getField() => ['gte' => $value]]];
			}

			if ($query->getType() === ISearchRequestSimpleQuery::COMPARE_TYPE_INT_LTE) {
				$value = $query->getValues()[0];
				$simpleQuery[] = ['range' => [$query->getField() => ['lte' => $value]]];
			}

			if ($query->getType() === ISearchRequestSimpleQuery::COMPARE_TYPE_INT_GT) {
				$value = $query->getValues()[0];
				$simpleQuery[] = ['range' => [$query->getField() => ['gt' => $value]]];
			}

			if ($query->getType() === ISearchRequestSimpleQuery::COMPARE_TYPE_INT_LT) {
				$value = $query->getValues()[0];
				$simpleQuery[] = ['range' => [$query->getField() => ['lt' => $value]]];
			}

		}

		return $simpleQuery;
	}


	/**
	 * @param ISearchRequest $request
	 *
	 * @return array
	 */
	private function generateSearchHighlighting(ISearchRequest $request): array {

		$parts = $this->getPartsFields($request);
		$fields = ['content' => new stdClass()];
		foreach ($parts as $part) {
			$fields[$part] = new stdClass();
		}

		return [
			'fields'    => $fields,
			'pre_tags'  => [''],
			'post_tags' => ['']
		];
	}


	/**
	 * @param string $providerId
	 * @param string $documentId
	 *
	 * @return array
	 * @throws ConfigurationException
	 */
	public function getDocumentQuery(string $providerId, string $documentId): array {
		return [
			'index' => $this->configService->getElasticIndex(),
			'type'  => 'standard',
			'id'    => $providerId . ':' . $documentId
		];
	}


	/**
	 * @param ISearchRequest $request
	 *
	 * @return array
	 */
	private function getPartsFields(ISearchRequest $request) {
		return array_map(
			function($value) {
				return 'parts.' . $value;
			}, $request->getParts()
		);
	}

}

