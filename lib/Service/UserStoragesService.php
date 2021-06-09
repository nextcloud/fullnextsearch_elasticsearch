<?php
declare(strict_types=1);


/**
 * FullTextSearch_ElasticSearch - Use Elasticsearch to index the content of your nextcloud
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Robin Windey <ro.windey@gmail.com>
 * @copyright 2020
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

use OCA\Files_External\Service\UserGlobalStoragesService;

/**
 * Wrapper class for native OCA\Files_External\Service\UserGlobalStoragesService
 */
class UserStoragesService implements IUserStoragesService {
    
    /** @var UserGlobalStoragesService */
    private $userGlobalStoragesService;

    public function __construct(UserGlobalStoragesService $userGlobalStoragesService) {
        $this->userGlobalStoragesService = $userGlobalStoragesService;
    }

   /**
     * Returns an array of strings with all external mountpoints of the current user
     * @return array
     */
    public function getAllStoragesForUser() {
        $userStorages = $this->userGlobalStoragesService->getAllStoragesForUser();
		$mountPoints = [];
		foreach($userStorages as $userStorage){
			/** @var $userStorage OCA\Files_External\Lib\StorageConfig */
			$mountPoint = $userStorage->getMountPoint();
			if (substr($mountPoint, 0, 1) === '/'){
				$mountPoint = substr($mountPoint, 1, strlen($mountPoint) - 1);
			}
			if (substr($mountPoint, strlen($mountPoint) - 1, 1) !== '/'){
				$mountPoint .= '/';
			}
			$mountPoints[] = $mountPoint;
		}
		return $mountPoints;
    }
}