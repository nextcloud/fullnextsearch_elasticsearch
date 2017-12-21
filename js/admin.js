/*
 * FullNextSearch - Full Text Search your Nextcloud.
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2017
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
 *
 */

/** global: OCA */

(function () {


	/**
	 * @constructs ElasticSearchAdmin
	 */
	var ElasticSearchAdmin = function () {
		$.extend(ElasticSearchAdmin.prototype, elasticsearch_elements);
		$.extend(ElasticSearchAdmin.prototype, elasticsearch_settings);

		elasticsearch_elements.init();
		elasticsearch_settings.refreshSettingPage();
	};

	OCA.NextSearchAdmin.elasticSearch = ElasticSearchAdmin;
	OCA.NextSearchAdmin.elasticSearch.settings = new ElasticSearchAdmin();

})();
