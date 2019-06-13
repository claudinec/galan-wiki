<?php
/**
 * Implements Special:Lonelypaages
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup SpecialPage
 */

use MediaWiki\MediaWikiServices;

/**
 * A special page looking for articles with no article linking to them,
 * thus being lonely.
 *
 * @ingroup SpecialPage
 */
class LonelyPagesPage extends PageQueryPage {
	function __construct( $name = 'Lonelypages' ) {
		parent::__construct( $name );
	}

	function getPageHeader() {
		return $this->msg( 'lonelypagestext' )->parseAsBlock();
	}

	function sortDescending() {
		return false;
	}

	function isExpensive() {
		return true;
	}

	function isSyndicated() {
		return false;
	}

	function getQueryInfo() {
		$tables = [ 'page', 'pagelinks', 'templatelinks' ];
		$conds = [
			'pl_namespace IS NULL',
			'page_namespace' => MediaWikiServices::getInstance()->getNamespaceInfo()->
				getContentNamespaces(),
			'page_is_redirect' => 0,
			'tl_namespace IS NULL'
		];
		$joinConds = [
			'pagelinks' => [
				'LEFT JOIN', [
					'pl_namespace = page_namespace',
					'pl_title = page_title'
				]
			],
			'templatelinks' => [
				'LEFT JOIN', [
					'tl_namespace = page_namespace',
					'tl_title = page_title'
				]
			]
		];

		// Allow extensions to modify the query
		Hooks::run( 'LonelyPagesQuery', [ &$tables, &$conds, &$joinConds ] );

		return [
			'tables' => $tables,
			'fields' => [
				'namespace' => 'page_namespace',
				'title' => 'page_title',
				'value' => 'page_title'
			],
			'conds' => $conds,
			'join_conds' => $joinConds
		];
	}

	function getOrderFields() {
		// For some crazy reason ordering by a constant
		// causes a filesort in MySQL 5
		if ( count( MediaWikiServices::getInstance()->getNamespaceInfo()->
			getContentNamespaces() ) > 1
		) {
			return [ 'page_namespace', 'page_title' ];
		} else {
			return [ 'page_title' ];
		}
	}

	protected function getGroupName() {
		return 'maintenance';
	}
}
