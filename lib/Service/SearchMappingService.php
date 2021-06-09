<?php
declare(strict_types=1);


/**
 * FullTextSearch_Elasticsearch - Use Elasticsearch to index the content of your nextcloud
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


namespace OCA\FullTextSearch_Elasticsearch\Service;


use OCA\FullTextSearch_Elasticsearch\Exceptions\ConfigurationException;
use OCA\FullTextSearch_Elasticsearch\Exceptions\QueryContentGenerationException;
use OCA\FullTextSearch_Elasticsearch\Exceptions\SearchQueryGenerationException;
use OCA\FullTextSearch_Elasticsearch\Model\QueryContent;
use OCP\FullTextSearch\Model\IDocumentAccess;
use OCP\FullTextSearch\Model\ISearchRequest;
use OCP\FullTextSearch\Model\ISearchRequestSimpleQuery;
use stdClass;

/**
 * Class SearchMappingService
 *
 * @package OCA\FullTextSearch_Elasticsearch\Service
 */
class SearchMappingService {

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;

	/** @var IUserStoragesService */
	private $userStoragesService;

	/**
	 * SearchMappingService constructor.
	 *
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 * @param null|IUserStoragesService $userStoragesService
	 */
	public function __construct(ConfigService $configService, MiscService $miscService, IUserStoragesService $userStoragesService = null) {
		$this->configService = $configService;
		$this->miscService = $miscService;
		$this->userStoragesService = $userStoragesService;
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

		$externalFilesConditions = $this->getExternalFilesConditions();
		if (!empty($externalFilesConditions)) {
			$query[] = ['bool' => ['must' => $externalFilesConditions]];
		}
		
		return $query;
	}

	/**
	 * @return array
	 */
	private function getExternalFileShares() : array {
		if (!$this->userStoragesService) {
			return [];
		}
		return $this->userStoragesService->getAllStoragesForUser();
	}

	/**
	 * Generates condition array for external files
	 * @return array
	 */
	private function getExternalFilesConditions(): array {
		// TODO :: normally we should check if user want's to search 
		// external files with "$request->getOption('files_external', '1') === '1'"
		$externalFileShares = $this->getExternalFileShares();
		if (empty($externalFileShares)) {
			return [];
		}
		$allowedExternalShares = [];
		foreach ($externalFileShares as $fileShare) {
			// If any external share is mounted as root, every
			// path is allowed
			if ($fileShare === '/') {
				$allowedExternalShares = [];
				break;
			}
			$allowedExternalShares[] = ['prefix' => ['title' => $fileShare]];
		}
		$externalFilesConditions = [];
		$externalFilesConditions[] = ['term' => ['source' => 'files_external']];
		$externalFilesConditions[] = ['term' => ['owner' => '']];
		if (!empty($allowedExternalShares)) {
			$externalFilesConditions[] = ['bool' => ['should' => $allowedExternalShares]];
		}

		return $externalFilesConditions;
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

