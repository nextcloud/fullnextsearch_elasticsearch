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


namespace OCA\FullTextSearch_Elasticsearch\AppInfo;


use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCA\FullTextSearch_ElasticSearch\Service\SearchMappingService;
use OCA\FullTextSearch_ElasticSearch\Service\ConfigService;
use OCA\FullTextSearch_ElasticSearch\Service\MiscService;
use OCA\FullTextSearch_ElasticSearch\Service\UserStoragesService;
use OCA\Files_External\Service\UserGlobalStoragesService;


require_once __DIR__ . '/../../vendor/autoload.php';


/**
 * Class Application
 *
 * @package OCA\FullTextSearch_Elasticsearch\AppInfo
 */
class Application extends App implements IBootstrap {


	const APP_NAME = 'fulltextsearch_elasticsearch';


	/**
	 * Application constructor.
	 *
	 * @param array $params
	 */
	public function __construct(array $params = []) {
		parent::__construct(self::APP_NAME, $params);
	}

	/**
	 * @param IRegistrationContext $context
	 */
	public function register(IRegistrationContext $context): void {
		// Make SearchMappingService also work without external storage
		// if app is inactive or not installed.
		$context->registerService(SearchMappingService::class, function($c) {
			try{
				$userStoragesService = $c->query(UserGlobalStoragesService::class);
				return new SearchMappingService(
					$c->query(ConfigService::class),
					$c->query(MiscService::class),
					new UserStoragesService($userStoragesService)
				);
			}
			catch (\Psr\Container\ContainerExceptionInterface $e) {
				return new SearchMappingService(
					$c->query(ConfigService::class),
					$c->query(MiscService::class)
				);
			}
		});
	}

	/**
	 * @param IBootContext $context
	 */
	public function boot(IBootContext $context): void {
	}

}

