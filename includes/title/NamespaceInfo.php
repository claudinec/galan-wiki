<?php
/**
 * Provide things related to namespaces.
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
 */

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Linker\LinkTarget;

/**
 * This is a utility class for dealing with namespaces that encodes all the "magic" behaviors of
 * them based on index.  The textual names of the namespaces are handled by Language.php.
 *
 * @since 1.34
 */
class NamespaceInfo {

	/**
	 * These namespaces should always be first-letter capitalized, now and
	 * forevermore. Historically, they could've probably been lowercased too,
	 * but some things are just too ingrained now. :)
	 */
	private $alwaysCapitalizedNamespaces = [ NS_SPECIAL, NS_USER, NS_MEDIAWIKI ];

	/** @var string[]|null Canonical namespaces cache */
	private $canonicalNamespaces = null;

	/** @var array|false Canonical namespaces index cache */
	private $namespaceIndexes = false;

	/** @var int[]|null Valid namespaces cache */
	private $validNamespaces = null;

	/** @var ServiceOptions */
	private $options;

	/**
	 * TODO Make this const when HHVM support is dropped (T192166)
	 *
	 * @since 1.34
	 * @var array
	 */
	public static $constructorOptions = [
		'AllowImageMoving',
		'CanonicalNamespaceNames',
		'CapitalLinkOverrides',
		'CapitalLinks',
		'ContentNamespaces',
		'ExtraNamespaces',
		'ExtraSignatureNamespaces',
		'NamespaceContentModels',
		'NamespaceProtection',
		'NamespacesWithSubpages',
		'NonincludableNamespaces',
		'RestrictionLevels',
	];

	/**
	 * @param ServiceOptions $options
	 */
	public function __construct( ServiceOptions $options ) {
		$options->assertRequiredOptions( self::$constructorOptions );
		$this->options = $options;
	}

	/**
	 * Throw an exception when trying to get the subject or talk page
	 * for a given namespace where it does not make sense.
	 * Special namespaces are defined in includes/Defines.php and have
	 * a value below 0 (ex: NS_SPECIAL = -1 , NS_MEDIA = -2)
	 *
	 * @param int $index
	 * @param string $method
	 *
	 * @throws MWException
	 * @return bool
	 */
	private function isMethodValidFor( $index, $method ) {
		if ( $index < NS_MAIN ) {
			throw new MWException( "$method does not make any sense for given namespace $index" );
		}
		return true;
	}

	/**
	 * Can pages in the given namespace be moved?
	 *
	 * @param int $index Namespace index
	 * @return bool
	 */
	public function isMovable( $index ) {
		$result = $index >= NS_MAIN &&
			( $index != NS_FILE || $this->options->get( 'AllowImageMoving' ) );

		/**
		 * @since 1.20
		 */
		Hooks::run( 'NamespaceIsMovable', [ $index, &$result ] );

		return $result;
	}

	/**
	 * Is the given namespace is a subject (non-talk) namespace?
	 *
	 * @param int $index Namespace index
	 * @return bool
	 */
	public function isSubject( $index ) {
		return !$this->isTalk( $index );
	}

	/**
	 * Is the given namespace a talk namespace?
	 *
	 * @param int $index Namespace index
	 * @return bool
	 */
	public function isTalk( $index ) {
		return $index > NS_MAIN
			&& $index % 2;
	}

	/**
	 * Get the talk namespace index for a given namespace
	 *
	 * @param int $index Namespace index
	 * @return int
	 */
	public function getTalk( $index ) {
		$this->isMethodValidFor( $index, __METHOD__ );
		return $this->isTalk( $index )
			? $index
			: $index + 1;
	}

	/**
	 * @param LinkTarget $target
	 * @return LinkTarget Talk page for $target
	 * @throws MWException if $target's namespace doesn't have talk pages (e.g., NS_SPECIAL)
	 */
	public function getTalkPage( LinkTarget $target ) : LinkTarget {
		if ( $this->isTalk( $target->getNamespace() ) ) {
			return $target;
		}
		return new TitleValue( $this->getTalk( $target->getNamespace() ), $target->getDbKey() );
	}

	/**
	 * Get the subject namespace index for a given namespace
	 * Special namespaces (NS_MEDIA, NS_SPECIAL) are always the subject.
	 *
	 * @param int $index Namespace index
	 * @return int
	 */
	public function getSubject( $index ) {
		# Handle special namespaces
		if ( $index < NS_MAIN ) {
			return $index;
		}

		return $this->isTalk( $index )
			? $index - 1
			: $index;
	}

	/**
	 * @param LinkTarget $target
	 * @return LinkTarget Subject page for $target
	 */
	public function getSubjectPage( LinkTarget $target ) : LinkTarget {
		if ( $this->isSubject( $target->getNamespace() ) ) {
			return $target;
		}
		return new TitleValue( $this->getSubject( $target->getNamespace() ), $target->getDbKey() );
	}

	/**
	 * Get the associated namespace.
	 * For talk namespaces, returns the subject (non-talk) namespace
	 * For subject (non-talk) namespaces, returns the talk namespace
	 *
	 * @param int $index Namespace index
	 * @return int
	 * @throws MWException if called on a namespace that has no talk pages (e.g., NS_SPECIAL)
	 */
	public function getAssociated( $index ) {
		$this->isMethodValidFor( $index, __METHOD__ );

		if ( $this->isSubject( $index ) ) {
			return $this->getTalk( $index );
		}
		return $this->getSubject( $index );
	}

	/**
	 * @param LinkTarget $target
	 * @return LinkTarget Talk page for $target if it's a subject page, subject page if it's a talk
	 *   page
	 * @throws MWException if $target's namespace doesn't have talk pages (e.g., NS_SPECIAL)
	 */
	public function getAssociatedPage( LinkTarget $target ) : LinkTarget {
		return new TitleValue(
			$this->getAssociated( $target->getNamespace() ), $target->getDbKey() );
	}

	/**
	 * Returns whether the specified namespace exists
	 *
	 * @param int $index
	 *
	 * @return bool
	 */
	public function exists( $index ) {
		$nslist = $this->getCanonicalNamespaces();
		return isset( $nslist[$index] );
	}

	/**
	 * Returns whether the specified namespaces are the same namespace
	 *
	 * @note It's possible that in the future we may start using something
	 * other than just namespace indexes. Under that circumstance making use
	 * of this function rather than directly doing comparison will make
	 * sure that code will not potentially break.
	 *
	 * @param int $ns1 The first namespace index
	 * @param int $ns2 The second namespace index
	 *
	 * @return bool
	 */
	public function equals( $ns1, $ns2 ) {
		return $ns1 == $ns2;
	}

	/**
	 * Returns whether the specified namespaces share the same subject.
	 * eg: NS_USER and NS_USER wil return true, as well
	 *     NS_USER and NS_USER_TALK will return true.
	 *
	 * @param int $ns1 The first namespace index
	 * @param int $ns2 The second namespace index
	 *
	 * @return bool
	 */
	public function subjectEquals( $ns1, $ns2 ) {
		return $this->getSubject( $ns1 ) == $this->getSubject( $ns2 );
	}

	/**
	 * Returns array of all defined namespaces with their canonical
	 * (English) names.
	 *
	 * @return array
	 */
	public function getCanonicalNamespaces() {
		if ( $this->canonicalNamespaces === null ) {
			$this->canonicalNamespaces =
				[ NS_MAIN => '' ] + $this->options->get( 'CanonicalNamespaceNames' );
			$this->canonicalNamespaces +=
				ExtensionRegistry::getInstance()->getAttribute( 'ExtensionNamespaces' );
			if ( is_array( $this->options->get( 'ExtraNamespaces' ) ) ) {
				$this->canonicalNamespaces += $this->options->get( 'ExtraNamespaces' );
			}
			Hooks::run( 'CanonicalNamespaces', [ &$this->canonicalNamespaces ] );
		}
		return $this->canonicalNamespaces;
	}

	/**
	 * Returns the canonical (English) name for a given index
	 *
	 * @param int $index Namespace index
	 * @return string|bool If no canonical definition.
	 */
	public function getCanonicalName( $index ) {
		$nslist = $this->getCanonicalNamespaces();
		return $nslist[$index] ?? false;
	}

	/**
	 * Returns the index for a given canonical name, or NULL
	 * The input *must* be converted to lower case first
	 *
	 * @param string $name Namespace name
	 * @return int|null
	 */
	public function getCanonicalIndex( $name ) {
		if ( $this->namespaceIndexes === false ) {
			$this->namespaceIndexes = [];
			foreach ( $this->getCanonicalNamespaces() as $i => $text ) {
				$this->namespaceIndexes[strtolower( $text )] = $i;
			}
		}
		if ( array_key_exists( $name, $this->namespaceIndexes ) ) {
			return $this->namespaceIndexes[$name];
		} else {
			return null;
		}
	}

	/**
	 * Returns an array of the namespaces (by integer id) that exist on the wiki. Used primarily by
	 * the API in help documentation. The array is sorted numerically and omits negative namespaces.
	 * @return array
	 */
	public function getValidNamespaces() {
		if ( is_null( $this->validNamespaces ) ) {
			foreach ( array_keys( $this->getCanonicalNamespaces() ) as $ns ) {
				if ( $ns >= 0 ) {
					$this->validNamespaces[] = $ns;
				}
			}
			// T109137: sort numerically
			sort( $this->validNamespaces, SORT_NUMERIC );
		}

		return $this->validNamespaces;
	}

	/*

	/**
	 * Does this namespace ever have a talk namespace?
	 *
	 * @param int $index Namespace ID
	 * @return bool True if this namespace either is or has a corresponding talk namespace.
	 */
	public function hasTalkNamespace( $index ) {
		return $index >= NS_MAIN;
	}

	/**
	 * Does this namespace contain content, for the purposes of calculating
	 * statistics, etc?
	 *
	 * @param int $index Index to check
	 * @return bool
	 */
	public function isContent( $index ) {
		return $index == NS_MAIN || in_array( $index, $this->options->get( 'ContentNamespaces' ) );
	}

	/**
	 * Might pages in this namespace require the use of the Signature button on
	 * the edit toolbar?
	 *
	 * @param int $index Index to check
	 * @return bool
	 */
	public function wantSignatures( $index ) {
		return $this->isTalk( $index ) ||
			in_array( $index, $this->options->get( 'ExtraSignatureNamespaces' ) );
	}

	/**
	 * Can pages in a namespace be watched?
	 *
	 * @param int $index
	 * @return bool
	 */
	public function isWatchable( $index ) {
		return $index >= NS_MAIN;
	}

	/**
	 * Does the namespace allow subpages?
	 *
	 * @param int $index Index to check
	 * @return bool
	 */
	public function hasSubpages( $index ) {
		return !empty( $this->options->get( 'NamespacesWithSubpages' )[$index] );
	}

	/**
	 * Get a list of all namespace indices which are considered to contain content
	 * @return array Array of namespace indices
	 */
	public function getContentNamespaces() {
		$contentNamespaces = $this->options->get( 'ContentNamespaces' );
		if ( !is_array( $contentNamespaces ) || $contentNamespaces === [] ) {
			return [ NS_MAIN ];
		} elseif ( !in_array( NS_MAIN, $contentNamespaces ) ) {
			// always force NS_MAIN to be part of array (to match the algorithm used by isContent)
			return array_merge( [ NS_MAIN ], $contentNamespaces );
		} else {
			return $contentNamespaces;
		}
	}

	/**
	 * List all namespace indices which are considered subject, aka not a talk
	 * or special namespace. See also NamespaceInfo::isSubject
	 *
	 * @return array Array of namespace indices
	 */
	public function getSubjectNamespaces() {
		return array_filter(
			$this->getValidNamespaces(),
			[ $this, 'isSubject' ]
		);
	}

	/**
	 * List all namespace indices which are considered talks, aka not a subject
	 * or special namespace. See also NamespaceInfo::isTalk
	 *
	 * @return array Array of namespace indices
	 */
	public function getTalkNamespaces() {
		return array_filter(
			$this->getValidNamespaces(),
			[ $this, 'isTalk' ]
		);
	}

	/**
	 * Is the namespace first-letter capitalized?
	 *
	 * @param int $index Index to check
	 * @return bool
	 */
	public function isCapitalized( $index ) {
		// Turn NS_MEDIA into NS_FILE
		$index = $index === NS_MEDIA ? NS_FILE : $index;

		// Make sure to get the subject of our namespace
		$index = $this->getSubject( $index );

		// Some namespaces are special and should always be upper case
		if ( in_array( $index, $this->alwaysCapitalizedNamespaces ) ) {
			return true;
		}
		$overrides = $this->options->get( 'CapitalLinkOverrides' );
		if ( isset( $overrides[$index] ) ) {
			// CapitalLinkOverrides is explicitly set
			return $overrides[$index];
		}
		// Default to the global setting
		return $this->options->get( 'CapitalLinks' );
	}

	/**
	 * Does the namespace (potentially) have different aliases for different
	 * genders. Not all languages make a distinction here.
	 *
	 * @param int $index Index to check
	 * @return bool
	 */
	public function hasGenderDistinction( $index ) {
		return $index == NS_USER || $index == NS_USER_TALK;
	}

	/**
	 * It is not possible to use pages from this namespace as template?
	 *
	 * @param int $index Index to check
	 * @return bool
	 */
	public function isNonincludable( $index ) {
		$namespaces = $this->options->get( 'NonincludableNamespaces' );
		return $namespaces && in_array( $index, $namespaces );
	}

	/**
	 * Get the default content model for a namespace
	 * This does not mean that all pages in that namespace have the model
	 *
	 * @note To determine the default model for a new page's main slot, or any slot in general,
	 * use SlotRoleHandler::getDefaultModel() together with SlotRoleRegistry::getRoleHandler().
	 *
	 * @param int $index Index to check
	 * @return null|string Default model name for the given namespace, if set
	 */
	public function getNamespaceContentModel( $index ) {
		return $this->options->get( 'NamespaceContentModels' )[$index] ?? null;
	}

	/**
	 * Determine which restriction levels it makes sense to use in a namespace,
	 * optionally filtered by a user's rights.
	 *
	 * @todo Move this to PermissionManager and remove the dependency here on permissions-related
	 * config settings.
	 *
	 * @param int $index Index to check
	 * @param User|null $user User to check
	 * @return array
	 */
	public function getRestrictionLevels( $index, User $user = null ) {
		if ( !isset( $this->options->get( 'NamespaceProtection' )[$index] ) ) {
			// All levels are valid if there's no namespace restriction.
			// But still filter by user, if necessary
			$levels = $this->options->get( 'RestrictionLevels' );
			if ( $user ) {
				$levels = array_values( array_filter( $levels, function ( $level ) use ( $user ) {
					$right = $level;
					if ( $right == 'sysop' ) {
						$right = 'editprotected'; // BC
					}
					if ( $right == 'autoconfirmed' ) {
						$right = 'editsemiprotected'; // BC
					}
					return ( $right == '' || $user->isAllowed( $right ) );
				} ) );
			}
			return $levels;
		}

		// $wgNamespaceProtection can require one or more rights to edit the namespace, which
		// may be satisfied by membership in multiple groups each giving a subset of those rights.
		// A restriction level is redundant if, for any one of the namespace rights, all groups
		// giving that right also give the restriction level's right. Or, conversely, a
		// restriction level is not redundant if, for every namespace right, there's at least one
		// group giving that right without the restriction level's right.
		//
		// First, for each right, get a list of groups with that right.
		$namespaceRightGroups = [];
		foreach ( (array)$this->options->get( 'NamespaceProtection' )[$index] as $right ) {
			if ( $right == 'sysop' ) {
				$right = 'editprotected'; // BC
			}
			if ( $right == 'autoconfirmed' ) {
				$right = 'editsemiprotected'; // BC
			}
			if ( $right != '' ) {
				$namespaceRightGroups[$right] = User::getGroupsWithPermission( $right );
			}
		}

		// Now, go through the protection levels one by one.
		$usableLevels = [ '' ];
		foreach ( $this->options->get( 'RestrictionLevels' ) as $level ) {
			$right = $level;
			if ( $right == 'sysop' ) {
				$right = 'editprotected'; // BC
			}
			if ( $right == 'autoconfirmed' ) {
				$right = 'editsemiprotected'; // BC
			}

			if ( $right != '' &&
				!isset( $namespaceRightGroups[$right] ) &&
				( !$user || $user->isAllowed( $right ) )
			) {
				// Do any of the namespace rights imply the restriction right? (see explanation above)
				foreach ( $namespaceRightGroups as $groups ) {
					if ( !array_diff( $groups, User::getGroupsWithPermission( $right ) ) ) {
						// Yes, this one does.
						continue 2;
					}
				}
				// No, keep the restriction level
				$usableLevels[] = $level;
			}
		}

		return $usableLevels;
	}

	/**
	 * Returns the link type to be used for categories.
	 *
	 * This determines which section of a category page titles
	 * in the namespace will appear within.
	 *
	 * @param int $index Namespace index
	 * @return string One of 'subcat', 'file', 'page'
	 */
	public function getCategoryLinkType( $index ) {
		$this->isMethodValidFor( $index, __METHOD__ );

		if ( $index == NS_CATEGORY ) {
			return 'subcat';
		} elseif ( $index == NS_FILE ) {
			return 'file';
		} else {
			return 'page';
		}
	}
}
