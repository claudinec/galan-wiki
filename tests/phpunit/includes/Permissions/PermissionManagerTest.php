<?php

namespace MediaWiki\Tests\Permissions;

use Action;
use FauxRequest;
use MediaWiki\Session\SessionId;
use MediaWiki\Session\TestUtils;
use MediaWikiLangTestCase;
use RequestContext;
use stdClass;
use Title;
use User;
use MediaWiki\Block\DatabaseBlock;
use MediaWiki\Block\Restriction\NamespaceRestriction;
use MediaWiki\Block\Restriction\PageRestriction;
use MediaWiki\Block\SystemBlock;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\PermissionManager;
use Wikimedia\TestingAccessWrapper;

/**
 * @group Database
 *
 * @covers \MediaWiki\Permissions\PermissionManager
 */
class PermissionManagerTest extends MediaWikiLangTestCase {

	/**
	 * @var string
	 */
	protected $userName, $altUserName;

	/**
	 * @var Title
	 */
	protected $title;

	/**
	 * @var User
	 */
	protected $user, $anonUser, $userUser, $altUser;

	/**
	 * @var PermissionManager
	 */
	protected $permissionManager;

	/** Constant for self::testIsBlockedFrom */
	const USER_TALK_PAGE = '<user talk page>';

	protected function setUp() {
		parent::setUp();

		$localZone = 'UTC';
		$localOffset = date( 'Z' ) / 60;

		$this->setMwGlobals( [
			'wgLocaltimezone' => $localZone,
			'wgLocalTZoffset' => $localOffset,
			'wgNamespaceProtection' => [
				NS_MEDIAWIKI => 'editinterface',
			],
			'wgRevokePermissions' => [
				'formertesters' => [
					'runtest' => true
				]
			],
			'wgAvailableRights' => [
				'test',
				'runtest',
				'writetest',
				'nukeworld',
				'modifytest',
				'editmyoptions'
			]
		] );

		$this->setGroupPermissions( 'unittesters', 'test', true );
		$this->setGroupPermissions( 'unittesters', 'runtest', true );
		$this->setGroupPermissions( 'unittesters', 'writetest', false );
		$this->setGroupPermissions( 'unittesters', 'nukeworld', false );

		$this->setGroupPermissions( 'testwriters', 'test', true );
		$this->setGroupPermissions( 'testwriters', 'writetest', true );
		$this->setGroupPermissions( 'testwriters', 'modifytest', true );

		$this->setGroupPermissions( '*', 'editmyoptions', true );

		// Without this testUserBlock will use a non-English context on non-English MediaWiki
		// installations (because of how Title::checkUserBlock is implemented) and fail.
		RequestContext::resetMain();

		$this->userName = 'Useruser';
		$this->altUserName = 'Altuseruser';
		date_default_timezone_set( $localZone );

		$this->title = Title::makeTitle( NS_MAIN, "Main Page" );
		if ( !isset( $this->userUser ) || !( $this->userUser instanceof User ) ) {
			$this->userUser = User::newFromName( $this->userName );

			if ( !$this->userUser->getId() ) {
				$this->userUser = User::createNew( $this->userName, [
					"email" => "test@example.com",
					"real_name" => "Test User" ] );
				$this->userUser->load();
			}

			$this->altUser = User::newFromName( $this->altUserName );
			if ( !$this->altUser->getId() ) {
				$this->altUser = User::createNew( $this->altUserName, [
					"email" => "alttest@example.com",
					"real_name" => "Test User Alt" ] );
				$this->altUser->load();
			}

			$this->anonUser = User::newFromId( 0 );

			$this->user = $this->userUser;
		}

		$this->resetServices();
	}

	public function tearDown() {
		parent::tearDown();
		$this->restoreMwServices();
	}

	protected function setTitle( $ns, $title = "Main_Page" ) {
		$this->title = Title::makeTitle( $ns, $title );
	}

	protected function setUser( $userName = null ) {
		if ( $userName === 'anon' ) {
			$this->user = $this->anonUser;
		} elseif ( $userName === null || $userName === $this->userName ) {
			$this->user = $this->userUser;
		} else {
			$this->user = $this->altUser;
		}
		$this->resetServices();
	}

	/**
	 * @todo This test method should be split up into separate test methods and
	 * data providers
	 *
	 * This test is failing per T201776.
	 *
	 * @group Broken
	 * @covers \MediaWiki\Permissions\PermissionManager::checkQuickPermissions
	 */
	public function testQuickPermissions() {
		$prefix = MediaWikiServices::getInstance()->getContentLanguage()->
		getFormattedNsText( NS_PROJECT );

		$this->setUser( 'anon' );
		$this->setTitle( NS_TALK );
		$this->overrideUserPermissions( $this->user, "createtalk" );
		$res = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( 'create', $this->user, $this->title );
		$this->assertEquals( [], $res );

		$this->setTitle( NS_TALK );
		$this->overrideUserPermissions( $this->user, "createpage" );
		$res = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( 'create', $this->user, $this->title );
		$this->assertEquals( [ [ "nocreatetext" ] ], $res );

		$this->setTitle( NS_TALK );
		$this->overrideUserPermissions( $this->user, "" );
		$res = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( 'create', $this->user, $this->title );
		$this->assertEquals( [ [ 'nocreatetext' ] ], $res );

		$this->setTitle( NS_MAIN );
		$this->overrideUserPermissions( $this->user, "createpage" );
		$res = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( 'create', $this->user, $this->title );
		$this->assertEquals( [], $res );

		$this->setTitle( NS_MAIN );
		$this->overrideUserPermissions( $this->user, "createtalk" );
		$res = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( 'create', $this->user, $this->title );
		$this->assertEquals( [ [ 'nocreatetext' ] ], $res );

		$this->setUser( $this->userName );
		$this->setTitle( NS_TALK );
		$this->overrideUserPermissions( $this->user, "createtalk" );
		$res = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( 'create', $this->user, $this->title );
		$this->assertEquals( [], $res );

		$this->setTitle( NS_TALK );
		$this->overrideUserPermissions( $this->user, "createpage" );
		$res = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( 'create', $this->user, $this->title );
		$this->assertEquals( [ [ 'nocreate-loggedin' ] ], $res );

		$this->setTitle( NS_TALK );
		$this->overrideUserPermissions( $this->user, "" );
		$res = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( 'create', $this->user, $this->title );
		$this->assertEquals( [ [ 'nocreate-loggedin' ] ], $res );

		$this->setTitle( NS_MAIN );
		$this->overrideUserPermissions( $this->user, "createpage" );
		$res = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( 'create', $this->user, $this->title );
		$this->assertEquals( [], $res );

		$this->setTitle( NS_MAIN );
		$this->overrideUserPermissions( $this->user, "createtalk" );
		$res = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( 'create', $this->user, $this->title );
		$this->assertEquals( [ [ 'nocreate-loggedin' ] ], $res );

		$this->setTitle( NS_MAIN );
		$this->overrideUserPermissions( $this->user, "" );
		$res = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( 'create', $this->user, $this->title );
		$this->assertEquals( [ [ 'nocreate-loggedin' ] ], $res );

		$this->setUser( 'anon' );
		$this->setTitle( NS_USER, $this->userName . '' );
		$this->overrideUserPermissions( $this->user, "" );
		$res = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( 'move', $this->user, $this->title );
		$this->assertEquals( [ [ 'cant-move-user-page' ], [ 'movenologintext' ] ], $res );

		$this->setTitle( NS_USER, $this->userName . '/subpage' );
		$this->overrideUserPermissions( $this->user, "" );
		$res = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( 'move', $this->user, $this->title );
		$this->assertEquals( [ [ 'movenologintext' ] ], $res );

		$this->setTitle( NS_USER, $this->userName . '' );
		$this->overrideUserPermissions( $this->user, "move-rootuserpages" );
		$res = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( 'move', $this->user, $this->title );
		$this->assertEquals( [ [ 'movenologintext' ] ], $res );

		$this->setTitle( NS_USER, $this->userName . '/subpage' );
		$this->overrideUserPermissions( $this->user, "move-rootuserpages" );
		$res = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( 'move', $this->user, $this->title );
		$this->assertEquals( [ [ 'movenologintext' ] ], $res );

		$this->setTitle( NS_USER, $this->userName . '' );
		$this->overrideUserPermissions( $this->user, "" );
		$res = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( 'move', $this->user, $this->title );
		$this->assertEquals( [ [ 'cant-move-user-page' ], [ 'movenologintext' ] ], $res );

		$this->setTitle( NS_USER, $this->userName . '/subpage' );
		$this->overrideUserPermissions( $this->user, "" );
		$res = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( 'move', $this->user, $this->title );
		$this->assertEquals( [ [ 'movenologintext' ] ], $res );

		$this->setTitle( NS_USER, $this->userName . '' );
		$this->overrideUserPermissions( $this->user, "move-rootuserpages" );
		$res = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( 'move', $this->user, $this->title );
		$this->assertEquals( [ [ 'movenologintext' ] ], $res );

		$this->setTitle( NS_USER, $this->userName . '/subpage' );
		$this->overrideUserPermissions( $this->user, "move-rootuserpages" );
		$res = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( 'move', $this->user, $this->title );
		$this->assertEquals( [ [ 'movenologintext' ] ], $res );

		$this->setUser( $this->userName );
		$this->setTitle( NS_FILE, "img.png" );
		$this->overrideUserPermissions( $this->user, "" );
		$res = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( 'move', $this->user, $this->title );
		$this->assertEquals( [ [ 'movenotallowedfile' ], [ 'movenotallowed' ] ], $res );

		$this->setTitle( NS_FILE, "img.png" );
		$this->overrideUserPermissions( $this->user, "movefile" );
		$res = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( 'move', $this->user, $this->title );
		$this->assertEquals( [ [ 'movenotallowed' ] ], $res );

		$this->setUser( 'anon' );
		$this->setTitle( NS_FILE, "img.png" );
		$this->overrideUserPermissions( $this->user, "" );
		$res = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( 'move', $this->user, $this->title );
		$this->assertEquals( [ [ 'movenotallowedfile' ], [ 'movenologintext' ] ], $res );

		$this->setTitle( NS_FILE, "img.png" );
		$this->overrideUserPermissions( $this->user, "movefile" );
		$res = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( 'move', $this->user, $this->title );
		$this->assertEquals( [ [ 'movenologintext' ] ], $res );

		$this->setUser( $this->userName );
		// $this->setUserPerm( "move" );
		$this->runGroupPermissions( 'move', 'move', [ [ 'movenotallowedfile' ] ] );

		// $this->setUserPerm( "" );
		$this->runGroupPermissions(
			'',
			'move',
			[ [ 'movenotallowedfile' ], [ 'movenotallowed' ] ]
		);

		$this->setUser( 'anon' );
		//$this->setUserPerm( "move" );
		$this->runGroupPermissions( 'move', 'move', [ [ 'movenotallowedfile' ] ] );

		// $this->setUserPerm( "" );
		$this->runGroupPermissions(
			'',
			'move',
			[ [ 'movenotallowedfile' ], [ 'movenotallowed' ] ],
			[ [ 'movenotallowedfile' ], [ 'movenologintext' ] ]
		);

		if ( $this->isWikitextNS( NS_MAIN ) ) {
			// NOTE: some content models don't allow moving
			// @todo find a Wikitext namespace for testing

			$this->setTitle( NS_MAIN );
			$this->setUser( 'anon' );
			// $this->setUserPerm( "move" );
			$this->runGroupPermissions( 'move', 'move', [] );

			// $this->setUserPerm( "" );
			$this->runGroupPermissions( '', 'move', [ [ 'movenotallowed' ] ],
				[ [ 'movenologintext' ] ] );

			$this->setUser( $this->userName );
			// $this->setUserPerm( "" );
			$this->runGroupPermissions( '', 'move', [ [ 'movenotallowed' ] ] );

			//$this->setUserPerm( "move" );
			$this->runGroupPermissions( 'move', 'move', [] );

			$this->setUser( 'anon' );
			$this->overrideUserPermissions( $this->user, 'move' );
			$res = MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'move-target', $this->user, $this->title );
			$this->assertEquals( [], $res );

			$this->overrideUserPermissions( $this->user, '' );
			$res = MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'move-target', $this->user, $this->title );
			$this->assertEquals( [ [ 'movenotallowed' ] ], $res );
		}

		$this->setTitle( NS_USER );
		$this->setUser( $this->userName );
		$this->overrideUserPermissions( $this->user, [ "move", "move-rootuserpages" ] );
		$res = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( 'move-target', $this->user, $this->title );
		$this->assertEquals( [], $res );

		$this->overrideUserPermissions( $this->user, "move" );
		$res = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( 'move-target', $this->user, $this->title );
		$this->assertEquals( [ [ 'cant-move-to-user-page' ] ], $res );

		$this->setUser( 'anon' );
		$this->overrideUserPermissions( $this->user, [ "move", "move-rootuserpages" ] );
		$res = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( 'move-target', $this->user, $this->title );
		$this->assertEquals( [], $res );

		$this->setTitle( NS_USER, "User/subpage" );
		$this->overrideUserPermissions( $this->user, [ "move", "move-rootuserpages" ] );
		$res = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( 'move-target', $this->user, $this->title );
		$this->assertEquals( [], $res );

		$this->overrideUserPermissions( $this->user, "move" );
		$res = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( 'move-target', $this->user, $this->title );
		$this->assertEquals( [], $res );

		$this->setUser( 'anon' );
		$check = [
			'edit' => [
				[ [ 'badaccess-groups', "*, [[$prefix:Users|Users]]", 2 ] ],
				[ [ 'badaccess-group0' ] ],
				[],
				true
			],
			'protect' => [
				[ [
					'badaccess-groups',
					"[[$prefix:Administrators|Administrators]]", 1 ],
					[ 'protect-cantedit'
					] ],
				[ [ 'badaccess-group0' ], [ 'protect-cantedit' ] ],
				[ [ 'protect-cantedit' ] ],
				false
			],
			'' => [ [], [], [], true ]
		];

		foreach ( [ "edit", "protect", "" ] as $action ) {
			$this->overrideUserPermissions( $this->user );
			$this->assertEquals( $check[$action][0],
				MediaWikiServices::getInstance()->getPermissionManager()
					->getPermissionErrors( $action, $this->user, $this->title, true ) );
			$this->assertEquals( $check[$action][0],
				MediaWikiServices::getInstance()->getPermissionManager()
					->getPermissionErrors( $action, $this->user, $this->title, 'full' ) );
			$this->assertEquals( $check[$action][0],
				MediaWikiServices::getInstance()->getPermissionManager()
					->getPermissionErrors( $action, $this->user, $this->title, 'secure' ) );

			global $wgGroupPermissions;
			$old = $wgGroupPermissions;
			$wgGroupPermissions = [];
			$this->resetServices();

			$this->assertEquals( $check[$action][1],
				MediaWikiServices::getInstance()->getPermissionManager()
					->getPermissionErrors( $action, $this->user, $this->title, true ) );
			$this->assertEquals( $check[$action][1],
				MediaWikiServices::getInstance()->getPermissionManager()
					->getPermissionErrors( $action, $this->user, $this->title, 'full' ) );
			$this->assertEquals( $check[$action][1],
				MediaWikiServices::getInstance()->getPermissionManager()
					->getPermissionErrors( $action, $this->user, $this->title, 'secure' ) );
			$wgGroupPermissions = $old;
			$this->resetServices();

			$this->overrideUserPermissions( $this->user, $action );
			$this->assertEquals( $check[$action][2],
				MediaWikiServices::getInstance()->getPermissionManager()
					->getPermissionErrors( $action, $this->user, $this->title, true ) );
			$this->assertEquals( $check[$action][2],
				MediaWikiServices::getInstance()->getPermissionManager()
					->getPermissionErrors( $action, $this->user, $this->title, 'full' ) );
			$this->assertEquals( $check[$action][2],
				MediaWikiServices::getInstance()->getPermissionManager()
					->getPermissionErrors( $action, $this->user, $this->title, 'secure' ) );

			$this->overrideUserPermissions( $this->user, $action );
			$this->assertEquals( $check[$action][3],
				MediaWikiServices::getInstance()->getPermissionManager()
					->userCan( $action, $this->user, $this->title, true ) );
			$this->assertEquals( $check[$action][3],
				MediaWikiServices::getInstance()->getPermissionManager()
					->userCan( $action, $this->user, $this->title,
					PermissionManager::RIGOR_QUICK ) );
			# count( User::getGroupsWithPermissions( $action ) ) < 1
		}
	}

	protected function runGroupPermissions( $perm, $action, $result, $result2 = null ) {
		global $wgGroupPermissions;

		if ( $result2 === null ) {
			$result2 = $result;
		}

		$wgGroupPermissions['autoconfirmed']['move'] = false;
		$wgGroupPermissions['user']['move'] = false;
		$this->resetServices();
		$this->overrideUserPermissions( $this->user, $perm );
		$res = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( $action, $this->user, $this->title );
		$this->assertEquals( $result, $res );

		$wgGroupPermissions['autoconfirmed']['move'] = true;
		$wgGroupPermissions['user']['move'] = false;
		$this->resetServices();
		$this->overrideUserPermissions( $this->user, $perm );
		$res = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( $action, $this->user, $this->title );
		$this->assertEquals( $result2, $res );

		$wgGroupPermissions['autoconfirmed']['move'] = true;
		$wgGroupPermissions['user']['move'] = true;
		$this->resetServices();
		$this->overrideUserPermissions( $this->user, $perm );
		$res = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( $action, $this->user, $this->title );
		$this->assertEquals( $result2, $res );

		$wgGroupPermissions['autoconfirmed']['move'] = false;
		$wgGroupPermissions['user']['move'] = true;
		$this->resetServices();
		$this->overrideUserPermissions( $this->user, $perm );
		$res = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( $action, $this->user, $this->title );
		$this->assertEquals( $result2, $res );
	}

	/**
	 * @todo This test method should be split up into separate test methods and
	 * data providers
	 * @covers MediaWiki\Permissions\PermissionManager::checkSpecialsAndNSPermissions
	 */
	public function testSpecialsAndNSPermissions() {
		global $wgNamespaceProtection;
		$this->setUser( $this->userName );

		$this->setTitle( NS_SPECIAL );

		$this->assertEquals( [ [ 'badaccess-group0' ], [ 'ns-specialprotected' ] ],
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'bogus', $this->user, $this->title ) );

		$this->setTitle( NS_MAIN );
		$this->overrideUserPermissions( $this->user, 'bogus' );
		$this->assertEquals( [],
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'bogus', $this->user, $this->title ) );

		$this->setTitle( NS_MAIN );
		$this->overrideUserPermissions( $this->user, '' );
		$this->assertEquals( [ [ 'badaccess-group0' ] ],
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'bogus', $this->user, $this->title ) );

		$wgNamespaceProtection[NS_USER] = [ 'bogus' ];

		$this->setTitle( NS_USER );
		$this->overrideUserPermissions( $this->user, '' );
		$this->assertEquals( [ [ 'badaccess-group0' ],
			[ 'namespaceprotected', 'User', 'bogus' ] ],
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'bogus', $this->user, $this->title ) );

		$this->setTitle( NS_MEDIAWIKI );
		$this->overrideUserPermissions( $this->user, 'bogus' );
		$this->assertEquals( [ [ 'protectedinterface', 'bogus' ] ],
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'bogus', $this->user, $this->title ) );

		$this->setTitle( NS_MEDIAWIKI );
		$this->overrideUserPermissions( $this->user, 'bogus' );
		$this->assertEquals( [ [ 'protectedinterface', 'bogus' ] ],
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'bogus', $this->user, $this->title ) );

		$wgNamespaceProtection = null;

		$this->overrideUserPermissions( $this->user, 'bogus' );
		$this->assertEquals( [],
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'bogus', $this->user, $this->title ) );
		$this->assertEquals( true,
			MediaWikiServices::getInstance()->getPermissionManager()
				->userCan( 'bogus', $this->user, $this->title ) );

		$this->overrideUserPermissions( $this->user, '' );
		$this->assertEquals( [ [ 'badaccess-group0' ] ],
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'bogus', $this->user, $this->title ) );
		$this->assertEquals( false,
			MediaWikiServices::getInstance()->getPermissionManager()
				->userCan( 'bogus', $this->user, $this->title ) );
	}

	/**
	 * @todo This test method should be split up into separate test methods and
	 * data providers
	 * @covers \MediaWiki\Permissions\PermissionManager::checkUserConfigPermissions
	 */
	public function testJsConfigEditPermissions() {
		$this->setUser( $this->userName );

		$this->setTitle( NS_USER, $this->userName . '/test.js' );
		$this->runConfigEditPermissions(
			[ [ 'badaccess-group0' ], [ 'mycustomjsprotected', 'bogus' ] ],

			[ [ 'badaccess-group0' ], [ 'mycustomjsprotected', 'bogus' ] ],
			[ [ 'badaccess-group0' ], [ 'mycustomjsprotected', 'bogus' ] ],
			[ [ 'badaccess-group0' ] ],

			[ [ 'badaccess-group0' ], [ 'mycustomjsprotected', 'bogus' ] ],
			[ [ 'badaccess-group0' ], [ 'mycustomjsprotected', 'bogus' ] ],
			[ [ 'badaccess-group0' ] ],
			[ [ 'badaccess-groups' ] ]
		);
	}

	/**
	 * @todo This test method should be split up into separate test methods and
	 * data providers
	 * @covers \MediaWiki\Permissions\PermissionManager::checkUserConfigPermissions
	 */
	public function testJsonConfigEditPermissions() {
		$prefix = MediaWikiServices::getInstance()->getContentLanguage()->
		getFormattedNsText( NS_PROJECT );
		$this->setUser( $this->userName );

		$this->setTitle( NS_USER, $this->userName . '/test.json' );
		$this->runConfigEditPermissions(
			[ [ 'badaccess-group0' ], [ 'mycustomjsonprotected', 'bogus' ] ],

			[ [ 'badaccess-group0' ], [ 'mycustomjsonprotected', 'bogus' ] ],
			[ [ 'badaccess-group0' ] ],
			[ [ 'badaccess-group0' ], [ 'mycustomjsonprotected', 'bogus' ] ],

			[ [ 'badaccess-group0' ], [ 'mycustomjsonprotected', 'bogus' ] ],
			[ [ 'badaccess-group0' ] ],
			[ [ 'badaccess-group0' ], [ 'mycustomjsonprotected', 'bogus' ] ],
			[ [ 'badaccess-groups' ] ]
		);
	}

	/**
	 * @todo This test method should be split up into separate test methods and
	 * data providers
	 * @covers \MediaWiki\Permissions\PermissionManager::checkUserConfigPermissions
	 */
	public function testCssConfigEditPermissions() {
		$this->setUser( $this->userName );

		$this->setTitle( NS_USER, $this->userName . '/test.css' );
		$this->runConfigEditPermissions(
			[ [ 'badaccess-group0' ], [ 'mycustomcssprotected', 'bogus' ] ],

			[ [ 'badaccess-group0' ] ],
			[ [ 'badaccess-group0' ], [ 'mycustomcssprotected', 'bogus' ] ],
			[ [ 'badaccess-group0' ], [ 'mycustomcssprotected', 'bogus' ] ],

			[ [ 'badaccess-group0' ] ],
			[ [ 'badaccess-group0' ], [ 'mycustomcssprotected', 'bogus' ] ],
			[ [ 'badaccess-group0' ], [ 'mycustomcssprotected', 'bogus' ] ],
			[ [ 'badaccess-groups' ] ]
		);
	}

	/**
	 * @todo This test method should be split up into separate test methods and
	 * data providers
	 * @covers \MediaWiki\Permissions\PermissionManager::checkUserConfigPermissions
	 */
	public function testOtherJsConfigEditPermissions() {
		$this->setUser( $this->userName );

		$this->setTitle( NS_USER, $this->altUserName . '/test.js' );
		$this->runConfigEditPermissions(
			[ [ 'badaccess-group0' ], [ 'customjsprotected', 'bogus' ] ],

			[ [ 'badaccess-group0' ], [ 'customjsprotected', 'bogus' ] ],
			[ [ 'badaccess-group0' ], [ 'customjsprotected', 'bogus' ] ],
			[ [ 'badaccess-group0' ], [ 'customjsprotected', 'bogus' ] ],

			[ [ 'badaccess-group0' ], [ 'customjsprotected', 'bogus' ] ],
			[ [ 'badaccess-group0' ], [ 'customjsprotected', 'bogus' ] ],
			[ [ 'badaccess-group0' ] ],
			[ [ 'badaccess-groups' ] ]
		);
	}

	/**
	 * @todo This test method should be split up into separate test methods and
	 * data providers
	 * @covers \MediaWiki\Permissions\PermissionManager::checkUserConfigPermissions
	 */
	public function testOtherJsonConfigEditPermissions() {
		$this->setUser( $this->userName );

		$this->setTitle( NS_USER, $this->altUserName . '/test.json' );
		$this->runConfigEditPermissions(
			[ [ 'badaccess-group0' ], [ 'customjsonprotected', 'bogus' ] ],

			[ [ 'badaccess-group0' ], [ 'customjsonprotected', 'bogus' ] ],
			[ [ 'badaccess-group0' ], [ 'customjsonprotected', 'bogus' ] ],
			[ [ 'badaccess-group0' ], [ 'customjsonprotected', 'bogus' ] ],

			[ [ 'badaccess-group0' ], [ 'customjsonprotected', 'bogus' ] ],
			[ [ 'badaccess-group0' ] ],
			[ [ 'badaccess-group0' ], [ 'customjsonprotected', 'bogus' ] ],
			[ [ 'badaccess-groups' ] ]
		);
	}

	/**
	 * @todo This test method should be split up into separate test methods and
	 * data providers
	 * @covers \MediaWiki\Permissions\PermissionManager::checkUserConfigPermissions
	 */
	public function testOtherCssConfigEditPermissions() {
		$this->setUser( $this->userName );

		$this->setTitle( NS_USER, $this->altUserName . '/test.css' );
		$this->runConfigEditPermissions(
			[ [ 'badaccess-group0' ], [ 'customcssprotected', 'bogus' ] ],

			[ [ 'badaccess-group0' ], [ 'customcssprotected', 'bogus' ] ],
			[ [ 'badaccess-group0' ], [ 'customcssprotected', 'bogus' ] ],
			[ [ 'badaccess-group0' ], [ 'customcssprotected', 'bogus' ] ],

			[ [ 'badaccess-group0' ] ],
			[ [ 'badaccess-group0' ], [ 'customcssprotected', 'bogus' ] ],
			[ [ 'badaccess-group0' ], [ 'customcssprotected', 'bogus' ] ],
			[ [ 'badaccess-groups' ] ]
		);
	}

	/**
	 * @todo This test method should be split up into separate test methods and
	 * data providers
	 * @covers \MediaWiki\Permissions\PermissionManager::checkUserConfigPermissions
	 */
	public function testOtherNonConfigEditPermissions() {
		$this->setUser( $this->userName );

		$this->setTitle( NS_USER, $this->altUserName . '/tempo' );
		$this->runConfigEditPermissions(
			[ [ 'badaccess-group0' ] ],

			[ [ 'badaccess-group0' ] ],
			[ [ 'badaccess-group0' ] ],
			[ [ 'badaccess-group0' ] ],

			[ [ 'badaccess-group0' ] ],
			[ [ 'badaccess-group0' ] ],
			[ [ 'badaccess-group0' ] ],
			[ [ 'badaccess-groups' ] ]
		);
	}

	/**
	 * @todo This should use data providers like the other methods here.
	 * @covers \MediaWiki\Permissions\PermissionManager::checkUserConfigPermissions
	 */
	public function testPatrolActionConfigEditPermissions() {
		$this->setUser( 'anon' );
		$this->setTitle( NS_USER, 'ToPatrolOrNotToPatrol' );
		$this->runConfigEditPermissions(
			[ [ 'badaccess-group0' ] ],

			[ [ 'badaccess-group0' ] ],
			[ [ 'badaccess-group0' ] ],
			[ [ 'badaccess-group0' ] ],

			[ [ 'badaccess-group0' ] ],
			[ [ 'badaccess-group0' ] ],
			[ [ 'badaccess-group0' ] ],
			[ [ 'badaccess-groups' ] ]
		);
	}

	protected function runConfigEditPermissions(
		$resultNone,
		$resultMyCss,
		$resultMyJson,
		$resultMyJs,
		$resultUserCss,
		$resultUserJson,
		$resultUserJs,
		$resultPatrol
	) {
		$this->overrideUserPermissions( $this->user );
		$result = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( 'bogus', $this->user, $this->title );
		$this->assertEquals( $resultNone, $result );

		$this->overrideUserPermissions( $this->user, 'editmyusercss' );
		$result = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( 'bogus', $this->user, $this->title );
		$this->assertEquals( $resultMyCss, $result );

		$this->overrideUserPermissions( $this->user, 'editmyuserjson' );
		$result = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( 'bogus', $this->user, $this->title );
		$this->assertEquals( $resultMyJson, $result );

		$this->overrideUserPermissions( $this->user, 'editmyuserjs' );
		$result = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( 'bogus', $this->user, $this->title );
		$this->assertEquals( $resultMyJs, $result );

		$this->overrideUserPermissions( $this->user, 'editusercss' );
		$result = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( 'bogus', $this->user, $this->title );
		$this->assertEquals( $resultUserCss, $result );

		$this->overrideUserPermissions( $this->user, 'edituserjson' );
		$result = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( 'bogus', $this->user, $this->title );
		$this->assertEquals( $resultUserJson, $result );

		$this->overrideUserPermissions( $this->user, 'edituserjs' );
		$result = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( 'bogus', $this->user, $this->title );
		$this->assertEquals( $resultUserJs, $result );

		$this->overrideUserPermissions( $this->user );
		$result = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( 'patrol', $this->user, $this->title );
		$this->assertEquals( reset( $resultPatrol[0] ), reset( $result[0] ) );

		$this->overrideUserPermissions( $this->user, [ 'edituserjs', 'edituserjson', 'editusercss' ] );
		$result = MediaWikiServices::getInstance()->getPermissionManager()
			->getPermissionErrors( 'bogus', $this->user, $this->title );
		$this->assertEquals( [ [ 'badaccess-group0' ] ], $result );
	}

	/**
	 * @todo This test method should be split up into separate test methods and
	 * data providers
	 *
	 * This test is failing per T201776.
	 *
	 * @group Broken
	 * @covers \MediaWiki\Permissions\PermissionManager::checkPageRestrictions
	 */
	public function testPageRestrictions() {
		$prefix = MediaWikiServices::getInstance()->getContentLanguage()->
		getFormattedNsText( NS_PROJECT );

		$this->setTitle( NS_MAIN );
		$this->title->mRestrictionsLoaded = true;
		$this->overrideUserPermissions( $this->user, "edit" );
		$this->title->mRestrictions = [ "bogus" => [ 'bogus', "sysop", "protect", "" ] ];

		$this->assertEquals( [],
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'edit', $this->user, $this->title ) );

		$this->assertEquals( true,
			MediaWikiServices::getInstance()->getPermissionManager()
				->userCan( 'edit', $this->user, $this->title, PermissionManager::RIGOR_QUICK ) );

		$this->title->mRestrictions = [ "edit" => [ 'bogus', "sysop", "protect", "" ],
			"bogus" => [ 'bogus', "sysop", "protect", "" ] ];

		$this->assertEquals( [ [ 'badaccess-group0' ],
			[ 'protectedpagetext', 'bogus', 'bogus' ],
			[ 'protectedpagetext', 'editprotected', 'bogus' ],
			[ 'protectedpagetext', 'protect', 'bogus' ] ],
			MediaWikiServices::getInstance()->getPermissionManager()->getPermissionErrors(
				'bogus', $this->user, $this->title ) );
		$this->assertEquals( [ [ 'protectedpagetext', 'bogus', 'edit' ],
			[ 'protectedpagetext', 'editprotected', 'edit' ],
			[ 'protectedpagetext', 'protect', 'edit' ] ],
			MediaWikiServices::getInstance()->getPermissionManager()->getPermissionErrors(
				'edit', $this->user, $this->title ) );
		$this->overrideUserPermissions( $this->user );
		$this->assertEquals( [ [ 'badaccess-group0' ],
			[ 'protectedpagetext', 'bogus', 'bogus' ],
			[ 'protectedpagetext', 'editprotected', 'bogus' ],
			[ 'protectedpagetext', 'protect', 'bogus' ] ],
			MediaWikiServices::getInstance()->getPermissionManager()->getPermissionErrors(
				'bogus', $this->user, $this->title ) );
		$this->assertEquals( [ [ 'badaccess-groups', "*, [[$prefix:Users|Users]]", 2 ],
			[ 'protectedpagetext', 'bogus', 'edit' ],
			[ 'protectedpagetext', 'editprotected', 'edit' ],
			[ 'protectedpagetext', 'protect', 'edit' ] ],
			MediaWikiServices::getInstance()->getPermissionManager()->getPermissionErrors(
				'edit', $this->user, $this->title ) );
		$this->overrideUserPermissions( $this->user, [ "edit", "editprotected" ] );
		$this->assertEquals( [ [ 'badaccess-group0' ],
			[ 'protectedpagetext', 'bogus', 'bogus' ],
			[ 'protectedpagetext', 'protect', 'bogus' ] ],
			MediaWikiServices::getInstance()->getPermissionManager()->getPermissionErrors(
				'bogus', $this->user, $this->title ) );
		$this->assertEquals( [
			[ 'protectedpagetext', 'bogus', 'edit' ],
			[ 'protectedpagetext', 'protect', 'edit' ] ],
			MediaWikiServices::getInstance()->getPermissionManager()->getPermissionErrors(
				'edit', $this->user, $this->title ) );

		$this->title->mCascadeRestriction = true;
		$this->overrideUserPermissions( $this->user, "edit" );

		$this->assertEquals( false,
			MediaWikiServices::getInstance()->getPermissionManager()
				->userCan( 'bogus', $this->user, $this->title, PermissionManager::RIGOR_QUICK ) );

		$this->assertEquals( false,
			MediaWikiServices::getInstance()->getPermissionManager()->userCan(
				'edit', $this->user, $this->title, PermissionManager::RIGOR_QUICK ) );

		$this->assertEquals( [ [ 'badaccess-group0' ],
			[ 'protectedpagetext', 'bogus', 'bogus' ],
			[ 'protectedpagetext', 'editprotected', 'bogus' ],
			[ 'protectedpagetext', 'protect', 'bogus' ] ],
			MediaWikiServices::getInstance()->getPermissionManager()->getPermissionErrors(
				'bogus', $this->user, $this->title ) );
		$this->assertEquals( [ [ 'protectedpagetext', 'bogus', 'edit' ],
			[ 'protectedpagetext', 'editprotected', 'edit' ],
			[ 'protectedpagetext', 'protect', 'edit' ] ],
			MediaWikiServices::getInstance()->getPermissionManager()->getPermissionErrors(
				'edit', $this->user, $this->title ) );

		$this->overrideUserPermissions( $this->user, [ "edit", "editprotected" ] );
		$this->assertEquals( false,
			MediaWikiServices::getInstance()->getPermissionManager()->userCan(
				'bogus', $this->user, $this->title, PermissionManager::RIGOR_QUICK ) );

		$this->assertEquals( false,
			MediaWikiServices::getInstance()->getPermissionManager()->userCan(
				'edit', $this->user, $this->title, PermissionManager::RIGOR_QUICK ) );

		$this->assertEquals( [ [ 'badaccess-group0' ],
			[ 'protectedpagetext', 'bogus', 'bogus' ],
			[ 'protectedpagetext', 'protect', 'bogus' ],
			[ 'protectedpagetext', 'protect', 'bogus' ] ],
			MediaWikiServices::getInstance()->getPermissionManager()->getPermissionErrors(
				'bogus', $this->user, $this->title ) );
		$this->assertEquals( [ [ 'protectedpagetext', 'bogus', 'edit' ],
			[ 'protectedpagetext', 'protect', 'edit' ],
			[ 'protectedpagetext', 'protect', 'edit' ] ],
			MediaWikiServices::getInstance()->getPermissionManager()->getPermissionErrors(
				'edit', $this->user, $this->title ) );
	}

	/**
	 * @covers \MediaWiki\Permissions\PermissionManager::checkCascadingSourcesRestrictions
	 */
	public function testCascadingSourcesRestrictions() {
		$this->setTitle( NS_MAIN, "test page" );
		$this->overrideUserPermissions( $this->user, [ "edit", "bogus" ] );

		$this->title->mCascadeSources = [
			Title::makeTitle( NS_MAIN, "Bogus" ),
			Title::makeTitle( NS_MAIN, "UnBogus" )
		];
		$this->title->mCascadingRestrictions = [
			"bogus" => [ 'bogus', "sysop", "protect", "" ]
		];

		$this->assertEquals( false,
			MediaWikiServices::getInstance()->getPermissionManager()->userCan(
				'bogus', $this->user, $this->title ) );
		$this->assertEquals( [
			[ "cascadeprotected", 2, "* [[:Bogus]]\n* [[:UnBogus]]\n", 'bogus' ],
			[ "cascadeprotected", 2, "* [[:Bogus]]\n* [[:UnBogus]]\n", 'bogus' ],
			[ "cascadeprotected", 2, "* [[:Bogus]]\n* [[:UnBogus]]\n", 'bogus' ] ],
			MediaWikiServices::getInstance()->getPermissionManager()->getPermissionErrors(
				'bogus', $this->user, $this->title ) );

		$this->assertEquals( true,
			MediaWikiServices::getInstance()->getPermissionManager()->userCan(
				'edit', $this->user, $this->title ) );
		$this->assertEquals( [],
			MediaWikiServices::getInstance()->getPermissionManager()->getPermissionErrors(
				'edit', $this->user, $this->title ) );
	}

	/**
	 * @todo This test method should be split up into separate test methods and
	 * data providers
	 * @covers \MediaWiki\Permissions\PermissionManager::checkActionPermissions
	 */
	public function testActionPermissions() {
		$this->overrideUserPermissions( $this->user, [ "createpage" ] );
		$this->setTitle( NS_MAIN, "test page" );
		$this->title->mTitleProtection['permission'] = '';
		$this->title->mTitleProtection['user'] = $this->user->getId();
		$this->title->mTitleProtection['expiry'] = 'infinity';
		$this->title->mTitleProtection['reason'] = 'test';
		$this->title->mCascadeRestriction = false;

		$this->assertEquals( [ [ 'titleprotected', 'Useruser', 'test' ] ],
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'create', $this->user, $this->title ) );
		$this->assertEquals( false,
			MediaWikiServices::getInstance()->getPermissionManager()->userCan(
				'create', $this->user, $this->title ) );

		$this->title->mTitleProtection['permission'] = 'editprotected';
		$this->overrideUserPermissions( $this->user, [ 'createpage', 'protect' ] );
		$this->assertEquals( [ [ 'titleprotected', 'Useruser', 'test' ] ],
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'create', $this->user, $this->title ) );
		$this->assertEquals( false,
			MediaWikiServices::getInstance()->getPermissionManager()->userCan(
				'create', $this->user, $this->title ) );

		$this->overrideUserPermissions( $this->user, [ 'createpage', 'editprotected' ] );
		$this->assertEquals( [],
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'create', $this->user, $this->title ) );
		$this->assertEquals( true,
			MediaWikiServices::getInstance()->getPermissionManager()->userCan(
				'create', $this->user, $this->title ) );

		$this->overrideUserPermissions( $this->user, [ 'createpage' ] );
		$this->assertEquals( [ [ 'titleprotected', 'Useruser', 'test' ] ],
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'create', $this->user, $this->title ) );
		$this->assertEquals( false,
			MediaWikiServices::getInstance()->getPermissionManager()->userCan(
				'create', $this->user, $this->title ) );

		$this->setTitle( NS_MEDIA, "test page" );
		$this->overrideUserPermissions( $this->user, [ "move" ] );
		$this->assertEquals( false,
			MediaWikiServices::getInstance()->getPermissionManager()->userCan(
				'move', $this->user, $this->title ) );
		$this->assertEquals( [ [ 'immobile-source-namespace', 'Media' ] ],
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'move', $this->user, $this->title ) );

		$this->setTitle( NS_HELP, "test page" );
		$this->assertEquals( [],
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'move', $this->user, $this->title ) );
		$this->assertEquals( true,
			MediaWikiServices::getInstance()->getPermissionManager()->userCan(
				'move', $this->user, $this->title ) );

		$this->title->mInterwiki = "no";
		$this->assertEquals( [ [ 'immobile-source-page' ] ],
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'move', $this->user, $this->title ) );
		$this->assertEquals( false,
			MediaWikiServices::getInstance()->getPermissionManager()->userCan(
				'move', $this->user, $this->title ) );

		$this->setTitle( NS_MEDIA, "test page" );
		$this->assertEquals( false,
			MediaWikiServices::getInstance()->getPermissionManager()->userCan(
				'move-target', $this->user, $this->title ) );
		$this->assertEquals( [ [ 'immobile-target-namespace', 'Media' ] ],
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'move-target', $this->user, $this->title ) );

		$this->setTitle( NS_HELP, "test page" );
		$this->assertEquals( [],
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'move-target', $this->user, $this->title ) );
		$this->assertEquals( true,
			MediaWikiServices::getInstance()->getPermissionManager()->userCan(
				'move-target', $this->user, $this->title ) );

		$this->title->mInterwiki = "no";
		$this->assertEquals( [ [ 'immobile-target-page' ] ],
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'move-target', $this->user, $this->title ) );
		$this->assertEquals( false,
			MediaWikiServices::getInstance()->getPermissionManager()->userCan(
				'move-target', $this->user, $this->title ) );
	}

	/**
	 * @covers \MediaWiki\Permissions\PermissionManager::checkUserBlock
	 */
	public function testUserBlock() {
		$this->setMwGlobals( [
			'wgEmailConfirmToEdit' => true,
			'wgEmailAuthentication' => true,
			'wgBlockDisablesLogin' => false,
		] );

		$this->overrideUserPermissions( $this->user, [
			'createpage',
			'edit',
			'move',
			'rollback',
			'patrol',
			'upload',
			'purge'
		] );
		$this->setTitle( NS_HELP, "test page" );

		# $wgEmailConfirmToEdit only applies to 'edit' action
		$this->assertEquals( [],
			MediaWikiServices::getInstance()->getPermissionManager()->getPermissionErrors(
				'move-target', $this->user, $this->title ) );
		$this->assertContains( [ 'confirmedittext' ],
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'edit', $this->user, $this->title ) );

		$this->setMwGlobals( 'wgEmailConfirmToEdit', false );
		$this->resetServices();
		$this->overrideUserPermissions( $this->user, [
			'createpage',
			'edit',
			'move',
			'rollback',
			'patrol',
			'upload',
			'purge'
		] );

		$this->assertNotContains( [ 'confirmedittext' ],
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'edit', $this->user, $this->title ) );

		# $wgEmailConfirmToEdit && !$user->isEmailConfirmed() && $action != 'createaccount'
		$this->assertEquals( [],
			MediaWikiServices::getInstance()->getPermissionManager()->getPermissionErrors(
				'move-target', $this->user, $this->title ) );

		global $wgLang;
		$prev = time();
		$now = time() + 120;
		$this->user->mBlockedby = $this->user->getId();
		$this->user->mBlock = new DatabaseBlock( [
			'address' => '127.0.8.1',
			'by' => $this->user->getId(),
			'reason' => 'no reason given',
			'timestamp' => $prev + 3600,
			'auto' => true,
			'expiry' => 0
		] );
		$this->user->mBlock->mTimestamp = 0;
		$this->assertEquals( [ [ 'autoblockedtext',
			'[[User:Useruser|Useruser]]', 'no reason given', '127.0.0.1',
			'Useruser', null, 'infinite', '127.0.8.1',
			$wgLang->timeanddate( wfTimestamp( TS_MW, $prev ), true ) ] ],
			MediaWikiServices::getInstance()->getPermissionManager()->getPermissionErrors(
				'move-target', $this->user, $this->title ) );

		$this->assertEquals( false, MediaWikiServices::getInstance()->getPermissionManager()
			->userCan( 'move-target', $this->user, $this->title ) );
		// quickUserCan should ignore user blocks
		$this->assertEquals( true, MediaWikiServices::getInstance()->getPermissionManager()
			->userCan( 'move-target', $this->user, $this->title,
				PermissionManager::RIGOR_QUICK ) );

		global $wgLocalTZoffset;
		$wgLocalTZoffset = -60;
		$this->user->mBlockedby = $this->user->getName();
		$this->user->mBlock = new DatabaseBlock( [
			'address' => '127.0.8.1',
			'by' => $this->user->getId(),
			'reason' => 'no reason given',
			'timestamp' => $now,
			'auto' => false,
			'expiry' => 10,
		] );
		$this->assertEquals( [ [ 'blockedtext',
			'[[User:Useruser|Useruser]]', 'no reason given', '127.0.0.1',
			'Useruser', null, '23:00, 31 December 1969', '127.0.8.1',
			$wgLang->timeanddate( wfTimestamp( TS_MW, $now ), true ) ] ],
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'move-target', $this->user, $this->title ) );
		# $action != 'read' && $action != 'createaccount' && $user->isBlockedFrom( $this )
		#   $user->blockedFor() == ''
		#   $user->mBlock->mExpiry == 'infinity'

		$this->user->mBlockedby = $this->user->getName();
		$this->user->mBlock = new SystemBlock( [
			'address' => '127.0.8.1',
			'by' => $this->user->getId(),
			'reason' => 'no reason given',
			'timestamp' => $now,
			'auto' => false,
			'systemBlock' => 'test',
		] );

		$errors = [ [ 'systemblockedtext',
			'[[User:Useruser|Useruser]]', 'no reason given', '127.0.0.1',
			'Useruser', 'test', 'infinite', '127.0.8.1',
			$wgLang->timeanddate( wfTimestamp( TS_MW, $now ), true ) ] ];

		$this->assertEquals( $errors,
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'edit', $this->user, $this->title ) );
		$this->assertEquals( $errors,
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'move-target', $this->user, $this->title ) );
		$this->assertEquals( $errors,
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'rollback', $this->user, $this->title ) );
		$this->assertEquals( $errors,
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'patrol', $this->user, $this->title ) );
		$this->assertEquals( $errors,
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'upload', $this->user, $this->title ) );
		$this->assertEquals( [],
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'purge', $this->user, $this->title ) );

		// partial block message test
		$this->user->mBlockedby = $this->user->getName();
		$this->user->mBlock = new DatabaseBlock( [
			'address' => '127.0.8.1',
			'by' => $this->user->getId(),
			'reason' => 'no reason given',
			'timestamp' => $now,
			'sitewide' => false,
			'expiry' => 10,
		] );

		$this->assertEquals( [],
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'edit', $this->user, $this->title ) );
		$this->assertEquals( [],
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'move-target', $this->user, $this->title ) );
		$this->assertEquals( [],
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'rollback', $this->user, $this->title ) );
		$this->assertEquals( [],
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'patrol', $this->user, $this->title ) );
		$this->assertEquals( [],
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'upload', $this->user, $this->title ) );
		$this->assertEquals( [],
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'purge', $this->user, $this->title ) );

		$this->user->mBlock->setRestrictions( [
			( new PageRestriction( 0, $this->title->getArticleID() ) )->setTitle( $this->title ),
		] );

		$errors = [ [ 'blockedtext-partial',
			'[[User:Useruser|Useruser]]', 'no reason given', '127.0.0.1',
			'Useruser', null, '23:00, 31 December 1969', '127.0.8.1',
			$wgLang->timeanddate( wfTimestamp( TS_MW, $now ), true ) ] ];

		$this->assertEquals( $errors,
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'edit', $this->user, $this->title ) );
		$this->assertEquals( $errors,
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'move-target', $this->user, $this->title ) );
		$this->assertEquals( $errors,
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'rollback', $this->user, $this->title ) );
		$this->assertEquals( $errors,
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'patrol', $this->user, $this->title ) );
		$this->assertEquals( [],
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'upload', $this->user, $this->title ) );
		$this->assertEquals( [],
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'purge', $this->user, $this->title ) );

		// Test no block.
		$this->user->mBlockedby = null;
		$this->user->mBlock = null;

		$this->assertEquals( [],
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'edit', $this->user, $this->title ) );
	}

	/**
	 * @covers \MediaWiki\Permissions\PermissionManager::checkUserBlock
	 *
	 * Tests to determine that the passed in permission does not get mixed up with
	 * an action of the same name.
	 */
	public function testUserBlockAction() {
		global $wgLang;

		$tester = $this->getMockBuilder( Action::class )
					   ->disableOriginalConstructor()
					   ->getMock();
		$tester->method( 'getName' )
			   ->willReturn( 'tester' );
		$tester->method( 'getRestriction' )
			   ->willReturn( 'test' );
		$tester->method( 'requiresUnblock' )
			   ->willReturn( false );

		$this->setMwGlobals( [
			'wgActions' => [
				'tester' => $tester,
			],
			'wgGroupPermissions' => [
				'*' => [
					'tester' => true,
				],
			],
		] );

		$now = time();
		$this->user->mBlockedby = $this->user->getName();
		$this->user->mBlock = new DatabaseBlock( [
			'address' => '127.0.8.1',
			'by' => $this->user->getId(),
			'reason' => 'no reason given',
			'timestamp' => $now,
			'auto' => false,
			'expiry' => 'infinity',
		] );

		$errors = [ [ 'blockedtext',
			'[[User:Useruser|Useruser]]', 'no reason given', '127.0.0.1',
			'Useruser', null, 'infinite', '127.0.8.1',
			$wgLang->timeanddate( wfTimestamp( TS_MW, $now ), true ) ] ];

		$this->assertEquals( $errors,
			MediaWikiServices::getInstance()->getPermissionManager()
				->getPermissionErrors( 'tester', $this->user, $this->title ) );
	}

	/**
	 * @covers \MediaWiki\Permissions\PermissionManager::isBlockedFrom
	 */
	public function testBlockInstanceCache() {
		// First, check the user isn't blocked
		$user = $this->getMutableTestUser()->getUser();
		$ut = Title::makeTitle( NS_USER_TALK, $user->getName() );
		$this->assertNull( $user->getBlock( false ), 'sanity check' );
		//$this->assertSame( '', $user->blockedBy(), 'sanity check' );
		//$this->assertSame( '', $user->blockedFor(), 'sanity check' );
		//$this->assertFalse( (bool)$user->isHidden(), 'sanity check' );
		$this->assertFalse( MediaWikiServices::getInstance()->getPermissionManager()
			->isBlockedFrom( $user, $ut ), 'sanity check' );

		// Block the user
		$blocker = $this->getTestSysop()->getUser();
		$block = new DatabaseBlock( [
			'hideName' => true,
			'allowUsertalk' => false,
			'reason' => 'Because',
		] );
		$block->setTarget( $user );
		$block->setBlocker( $blocker );
		$res = $block->insert();
		$this->assertTrue( (bool)$res['id'], 'sanity check: Failed to insert block' );

		// Clear cache and confirm it loaded the block properly
		$user->clearInstanceCache();
		$this->assertInstanceOf( DatabaseBlock::class, $user->getBlock( false ) );
		//$this->assertSame( $blocker->getName(), $user->blockedBy() );
		//$this->assertSame( 'Because', $user->blockedFor() );
		//$this->assertTrue( (bool)$user->isHidden() );
		$this->assertTrue( MediaWikiServices::getInstance()->getPermissionManager()
			->isBlockedFrom( $user, $ut ) );

		// Unblock
		$block->delete();

		// Clear cache and confirm it loaded the not-blocked properly
		$user->clearInstanceCache();
		$this->assertNull( $user->getBlock( false ) );
		//$this->assertSame( '', $user->blockedBy() );
		//$this->assertSame( '', $user->blockedFor() );
		//$this->assertFalse( (bool)$user->isHidden() );
		$this->assertFalse( MediaWikiServices::getInstance()->getPermissionManager()
			->isBlockedFrom( $user, $ut ) );
	}

	/**
	 * @covers \MediaWiki\Permissions\PermissionManager::isBlockedFrom
	 * @dataProvider provideIsBlockedFrom
	 * @param string|null $title Title to test.
	 * @param bool $expect Expected result from User::isBlockedFrom()
	 * @param array $options Additional test options:
	 *  - 'blockAllowsUTEdit': (bool, default true) Value for $wgBlockAllowsUTEdit
	 *  - 'allowUsertalk': (bool, default false) Passed to DatabaseBlock::__construct()
	 *  - 'pageRestrictions': (array|null) If non-empty, page restriction titles for the block.
	 */
	public function testIsBlockedFrom( $title, $expect, array $options = [] ) {
		$this->setMwGlobals( [
			'wgBlockAllowsUTEdit' => $options['blockAllowsUTEdit'] ?? true,
		] );

		$user = $this->getTestUser()->getUser();

		if ( $title === self::USER_TALK_PAGE ) {
			$title = $user->getTalkPage();
		} else {
			$title = Title::newFromText( $title );
		}

		$restrictions = [];
		foreach ( $options['pageRestrictions'] ?? [] as $pagestr ) {
			$page = $this->getExistingTestPage(
				$pagestr === self::USER_TALK_PAGE ? $user->getTalkPage() : $pagestr
			);
			$restrictions[] = new PageRestriction( 0, $page->getId() );
		}
		foreach ( $options['namespaceRestrictions'] ?? [] as $ns ) {
			$restrictions[] = new NamespaceRestriction( 0, $ns );
		}

		$block = new DatabaseBlock( [
			'expiry' => wfTimestamp( TS_MW, wfTimestamp() + ( 40 * 60 * 60 ) ),
			'allowUsertalk' => $options['allowUsertalk'] ?? false,
			'sitewide' => !$restrictions,
		] );
		$block->setTarget( $user );
		$block->setBlocker( $this->getTestSysop()->getUser() );
		if ( $restrictions ) {
			$block->setRestrictions( $restrictions );
		}
		$block->insert();

		try {
			$this->assertSame( $expect, MediaWikiServices::getInstance()->getPermissionManager()
				->isBlockedFrom( $user, $title ) );
		} finally {
			$block->delete();
		}
	}

	public static function provideIsBlockedFrom() {
		return [
			'Sitewide block, basic operation' => [ 'Test page', true ],
			'Sitewide block, not allowing user talk' => [
				self::USER_TALK_PAGE, true, [
					'allowUsertalk' => false,
				]
			],
			'Sitewide block, allowing user talk' => [
				self::USER_TALK_PAGE, false, [
					'allowUsertalk' => true,
				]
			],
			'Sitewide block, allowing user talk but $wgBlockAllowsUTEdit is false' => [
				self::USER_TALK_PAGE, true, [
					'allowUsertalk' => true,
					'blockAllowsUTEdit' => false,
				]
			],
			'Partial block, blocking the page' => [
				'Test page', true, [
					'pageRestrictions' => [ 'Test page' ],
				]
			],
			'Partial block, not blocking the page' => [
				'Test page 2', false, [
					'pageRestrictions' => [ 'Test page' ],
				]
			],
			'Partial block, not allowing user talk but user talk page is not blocked' => [
				self::USER_TALK_PAGE, false, [
					'allowUsertalk' => false,
					'pageRestrictions' => [ 'Test page' ],
				]
			],
			'Partial block, allowing user talk but user talk page is blocked' => [
				self::USER_TALK_PAGE, true, [
					'allowUsertalk' => true,
					'pageRestrictions' => [ self::USER_TALK_PAGE ],
				]
			],
			'Partial block, user talk page is not blocked but $wgBlockAllowsUTEdit is false' => [
				self::USER_TALK_PAGE, false, [
					'allowUsertalk' => false,
					'pageRestrictions' => [ 'Test page' ],
					'blockAllowsUTEdit' => false,
				]
			],
			'Partial block, user talk page is blocked and $wgBlockAllowsUTEdit is false' => [
				self::USER_TALK_PAGE, true, [
					'allowUsertalk' => true,
					'pageRestrictions' => [ self::USER_TALK_PAGE ],
					'blockAllowsUTEdit' => false,
				]
			],
			'Partial user talk namespace block, not allowing user talk' => [
				self::USER_TALK_PAGE, true, [
					'allowUsertalk' => false,
					'namespaceRestrictions' => [ NS_USER_TALK ],
				]
			],
			'Partial user talk namespace block, allowing user talk' => [
				self::USER_TALK_PAGE, false, [
					'allowUsertalk' => true,
					'namespaceRestrictions' => [ NS_USER_TALK ],
				]
			],
			'Partial user talk namespace block, where $wgBlockAllowsUTEdit is false' => [
				self::USER_TALK_PAGE, true, [
					'allowUsertalk' => true,
					'namespaceRestrictions' => [ NS_USER_TALK ],
					'blockAllowsUTEdit' => false,
				]
			],
		];
	}

	/**
	 * @covers \MediaWiki\Permissions\PermissionManager::getUserPermissions
	 */
	public function testGetUserPermissions() {
		$user = $this->getTestUser( [ 'unittesters' ] )->getUser();
		$rights = MediaWikiServices::getInstance()->getPermissionManager()
			->getUserPermissions( $user );
		$this->assertContains( 'runtest', $rights );
		$this->assertNotContains( 'writetest', $rights );
		$this->assertNotContains( 'modifytest', $rights );
		$this->assertNotContains( 'nukeworld', $rights );
	}

	/**
	 * @covers \MediaWiki\Permissions\PermissionManager::getUserPermissions
	 */
	public function testGetUserPermissionsHooks() {
		$user = $this->getTestUser( [ 'unittesters', 'testwriters' ] )->getUser();
		$userWrapper = TestingAccessWrapper::newFromObject( $user );

		$rights = MediaWikiServices::getInstance()->getPermissionManager()
			->getUserPermissions( $user );
		$this->assertContains( 'test', $rights, 'sanity check' );
		$this->assertContains( 'runtest', $rights, 'sanity check' );
		$this->assertContains( 'writetest', $rights, 'sanity check' );
		$this->assertNotContains( 'nukeworld', $rights, 'sanity check' );

		// Add a hook manipluating the rights
		$this->mergeMwGlobalArrayValue( 'wgHooks', [ 'UserGetRights' => [ function ( $user, &$rights ) {
			$rights[] = 'nukeworld';
			$rights = array_diff( $rights, [ 'writetest' ] );
		} ] ] );

		$this->resetServices();
		$rights = MediaWikiServices::getInstance()->getPermissionManager()
			->getUserPermissions( $user );
		$this->assertContains( 'test', $rights );
		$this->assertContains( 'runtest', $rights );
		$this->assertNotContains( 'writetest', $rights );
		$this->assertContains( 'nukeworld', $rights );

		// Add a Session that limits rights
		$mock = $this->getMockBuilder( stdClass::class )
			->setMethods( [ 'getAllowedUserRights', 'deregisterSession', 'getSessionId' ] )
			->getMock();
		$mock->method( 'getAllowedUserRights' )->willReturn( [ 'test', 'writetest' ] );
		$mock->method( 'getSessionId' )->willReturn(
			new SessionId( str_repeat( 'X', 32 ) )
		);
		$session = TestUtils::getDummySession( $mock );
		$mockRequest = $this->getMockBuilder( FauxRequest::class )
			->setMethods( [ 'getSession' ] )
			->getMock();
		$mockRequest->method( 'getSession' )->willReturn( $session );
		$userWrapper->mRequest = $mockRequest;

		$this->resetServices();
		$rights = MediaWikiServices::getInstance()->getPermissionManager()
			->getUserPermissions( $user );
		$this->assertContains( 'test', $rights );
		$this->assertNotContains( 'runtest', $rights );
		$this->assertNotContains( 'writetest', $rights );
		$this->assertNotContains( 'nukeworld', $rights );
	}

	/**
	 * @covers \MediaWiki\Permissions\PermissionManager::getGroupPermissions
	 */
	public function testGroupPermissions() {
		$rights = MediaWikiServices::getInstance()->getPermissionManager()
			->getGroupPermissions( [ 'unittesters' ] );
		$this->assertContains( 'runtest', $rights );
		$this->assertNotContains( 'writetest', $rights );
		$this->assertNotContains( 'modifytest', $rights );
		$this->assertNotContains( 'nukeworld', $rights );

		$rights = MediaWikiServices::getInstance()->getPermissionManager()
			->getGroupPermissions( [ 'unittesters', 'testwriters' ] );
		$this->assertContains( 'runtest', $rights );
		$this->assertContains( 'writetest', $rights );
		$this->assertContains( 'modifytest', $rights );
		$this->assertNotContains( 'nukeworld', $rights );
	}

	/**
	 * @covers \MediaWiki\Permissions\PermissionManager::getGroupPermissions
	 */
	public function testRevokePermissions() {
		$rights = MediaWikiServices::getInstance()->getPermissionManager()
			->getGroupPermissions( [ 'unittesters', 'formertesters' ] );
		$this->assertNotContains( 'runtest', $rights );
		$this->assertNotContains( 'writetest', $rights );
		$this->assertNotContains( 'modifytest', $rights );
		$this->assertNotContains( 'nukeworld', $rights );
	}

	/**
	 * @dataProvider provideGetGroupsWithPermission
	 * @covers \MediaWiki\Permissions\PermissionManager::getGroupsWithPermission
	 */
	public function testGetGroupsWithPermission( $expected, $right ) {
		$result = MediaWikiServices::getInstance()->getPermissionManager()
			->getGroupsWithPermission( $right );
		sort( $result );
		sort( $expected );

		$this->assertEquals( $expected, $result, "Groups with permission $right" );
	}

	public static function provideGetGroupsWithPermission() {
		return [
			[
				[ 'unittesters', 'testwriters' ],
				'test'
			],
			[
				[ 'unittesters' ],
				'runtest'
			],
			[
				[ 'testwriters' ],
				'writetest'
			],
			[
				[ 'testwriters' ],
				'modifytest'
			],
		];
	}

	/**
	 * @covers \MediaWiki\Permissions\PermissionManager::userHasRight
	 */
	public function testUserHasRight() {
		$result = MediaWikiServices::getInstance()->getPermissionManager()->userHasRight(
			$this->getTestUser( 'unittesters' )->getUser(),
			'test'
		);
		$this->assertTrue( $result );

		$result = MediaWikiServices::getInstance()->getPermissionManager()->userHasRight(
			$this->getTestUser( 'formertesters' )->getUser(),
			'runtest'
		);
		$this->assertFalse( $result );

		$result = MediaWikiServices::getInstance()->getPermissionManager()->userHasRight(
			$this->getTestUser( 'formertesters' )->getUser(),
			''
		);
		$this->assertTrue( $result );
	}

	/**
	 * @covers \MediaWiki\Permissions\PermissionManager::groupHasPermission
	 */
	public function testGroupHasPermission() {
		$result = MediaWikiServices::getInstance()->getPermissionManager()->groupHasPermission(
			'unittesters',
			'test'
		);
		$this->assertTrue( $result );

		$result = MediaWikiServices::getInstance()->getPermissionManager()->groupHasPermission(
			'formertesters',
			'runtest'
		);
		$this->assertFalse( $result );
	}

	/**
	 * @covers \MediaWiki\Permissions\PermissionManager::isEveryoneAllowed
	 */
	public function testIsEveryoneAllowed() {
		$result = MediaWikiServices::getInstance()->getPermissionManager()
								   ->isEveryoneAllowed( 'editmyoptions' );
		$this->assertTrue( $result );

		$result = MediaWikiServices::getInstance()->getPermissionManager()
								   ->isEveryoneAllowed( 'test' );
		$this->assertFalse( $result );
	}

}
