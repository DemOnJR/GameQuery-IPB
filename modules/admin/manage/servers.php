<?php
/**
 * @brief		Game Servers Controller
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community
 * @subpackage	Game Servers
 * @since		26 Feb 2026
 */

namespace IPS\gameservers\modules\admin\manage;

use IPS\Application;
use IPS\Db;
use IPS\Dispatcher;
use IPS\Dispatcher\Controller;
use IPS\forums\Forum;
use IPS\gameservers\GameProfiles;
use IPS\gameservers\GameQuery;
use IPS\gameservers\ServerUpdater;
use IPS\gameservers\UpdateCheck;
use IPS\Helpers\Form;
use IPS\Helpers\Form\Member as FormMember;
use IPS\Helpers\Form\Node;
use IPS\Helpers\Form\Select;
use IPS\Helpers\Form\Text;
use IPS\Helpers\Form\TextArea;
use IPS\Helpers\Tree\Tree;
use IPS\Helpers\Form\Upload;
use IPS\Helpers\Form\YesNo;
use IPS\Http\Url;
use IPS\File;
use IPS\Image;
use IPS\Log;
use IPS\Member;
use IPS\Output;
use IPS\Request;
use IPS\Session;
use IPS\Settings as SettingsClass;
use IPS\Theme;
use InvalidArgumentException;
use UnderflowException;
use function array_key_exists;
use function array_shift;
use function count;
use function defined;
use function explode;
use function htmlspecialchars;
use function implode;
use function in_array;
use function is_array;
use function natcasesort;
use function preg_match;
use function preg_replace;
use function preg_split;
use function strpos;
use function strtolower;
use function substr;
use function trim;
use const ENT_DISALLOWED;
use const ENT_QUOTES;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * Servers controller
 */
class servers extends Controller
{
	/**
	 * @brief	Has been CSRF-protected
	 */
	public static bool $csrfProtected = TRUE;

	/**
	 * Execute
	 *
	 * @return	void
	 */
	public function execute(): void
	{
		Dispatcher::i()->checkAcpPermission( 'servers_manage', 'gameservers' );
		parent::execute();
	}

	/**
	 * Manage server list
	 *
	 * @return	void
	 */
	protected function manage(): void
	{
		$updateNotice = ( new UpdateCheck )->warningMessageHtml();
		if ( $updateNotice !== '' )
		{
			Output::i()->output .= "<div class='ipsMessage ipsMessage_warning i-margin-bottom_2'>{$updateNotice}</div>";
		}

		if ( trim( (string) SettingsClass::i()->gq_api_token ) === '' OR trim( (string) SettingsClass::i()->gq_api_token_email ) === '' )
		{
			Output::i()->output .= Theme::i()->getTemplate( 'global', 'core' )->message( 'gq_missing_credentials', 'warning' );
		}

		Output::i()->sidebar['actions']['add'] = array(
			'primary' => TRUE,
			'icon' => 'plus',
			'title' => 'gq_add_server',
			'link' => Url::internal( 'app=gameservers&module=manage&controller=servers&do=form' ),
		);

		Output::i()->sidebar['actions']['refresh'] = array(
			'icon' => 'sync',
			'title' => 'gq_refresh_now',
			'link' => Url::internal( 'app=gameservers&module=manage&controller=servers&do=refresh' )->csrf(),
		);

		$tree = new Tree(
			Url::internal( 'app=gameservers&module=manage&controller=servers' ),
			'menu__gameservers_manage_servers',
			array( $this, 'getServerRows' ),
			array( $this, 'getServerRow' ),
			function( $id )
			{
				return NULL;
			},
			function( $id )
			{
				return array();
			},
			NULL,
			FALSE,
			TRUE,
			TRUE
		);

		Output::i()->title = Member::loggedIn()->language()->addToStack( 'menu__gameservers_manage_servers' );
		Output::i()->output .= (string) $tree;
	}

	/**
	 * Get server rows for ACP tree
	 *
	 * @return	array
	 */
	public function getServerRows(): array
	{
		$rows = array();
		$orderBy = Db::i()->checkForColumn( 'gameservers_servers', 'position' ) ? 'position ASC, name ASC' : 'name ASC';

		foreach ( Db::i()->select( '*', 'gameservers_servers', NULL, $orderBy ) as $server )
		{
			$rows[ $server['id'] ] = $this->getServerRow( $server );
		}

		return $rows;
	}

	/**
	 * Build one ACP tree row for a server
	 *
	 * @param	int|string|array	$server
	 * @param	bool	$root
	 * @return	string
	 */
	public function getServerRow( int|string|array $server, bool $root=FALSE ): string
	{
		if ( is_numeric( $server ) )
		{
			try
			{
				$server = Db::i()->select( '*', 'gameservers_servers', array( 'id=?', (int) $server ) )->first();
			}
			catch ( UnderflowException )
			{
				return '';
			}
		}

		$title = $this->serverTitleWithGame( $server );
		$status = $this->serverStatusText( $server );
		$description = trim( (string) ( $server['address'] ?? '' ) ) . ' - ' . $status;
		$position = Db::i()->checkForColumn( 'gameservers_servers', 'position' ) ? (int) ( $server['position'] ?? 0 ) : NULL;

		$buttons = array(
			'edit' => array(
				'icon' => 'pencil',
				'title' => 'edit',
				'link' => Url::internal( "app=gameservers&module=manage&controller=servers&do=form&id={$server['id']}" ),
			),
			'toggle' => array(
				'icon' => (int) ( $server['enabled'] ?? 0 ) ? 'toggle-on' : 'toggle-off',
				'title' => (int) ( $server['enabled'] ?? 0 ) ? 'disable' : 'enable',
				'link' => Url::internal( "app=gameservers&module=manage&controller=servers&do=toggle&id={$server['id']}" )->csrf(),
			),
			'delete' => array(
				'icon' => 'trash',
				'title' => 'delete',
				'link' => Url::internal( "app=gameservers&module=manage&controller=servers&do=delete&id={$server['id']}" )->csrf(),
				'data' => array(
					'confirm' => '',
					'confirmSubMessage' => Member::loggedIn()->language()->addToStack( 'gq_delete_confirm' ),
				),
			),
		);

		return Theme::i()->getTemplate( 'trees', 'core' )->row(
			Url::internal( 'app=gameservers&module=manage&controller=servers' ),
			$server['id'],
			htmlspecialchars( $title, ENT_QUOTES | ENT_DISALLOWED, 'UTF-8' ),
			FALSE,
			$buttons,
			htmlspecialchars( $description, ENT_QUOTES | ENT_DISALLOWED, 'UTF-8' ),
			NULL,
			$position,
			$root
		);
	}

	/**
	 * Reorder servers using drag and drop tree
	 *
	 * @return	void
	 */
	protected function reorder(): void
	{
		Session::i()->csrfCheck();

		if ( !Db::i()->checkForColumn( 'gameservers_servers', 'position' ) )
		{
			if ( Request::i()->isAjax() )
			{
				return;
			}

			Output::i()->redirect( Url::internal( 'app=gameservers&module=manage&controller=servers' ) );
		}

		if ( isset( Request::i()->ajax_order ) )
		{
			$order = array();
			$position = array();

			foreach ( Request::i()->ajax_order as $id => $parent )
			{
				if ( !isset( $order[ $parent ] ) )
				{
					$order[ $parent ] = array();
					$position[ $parent ] = 1;
				}

				$order[ $parent ][ $id ] = $position[ $parent ]++;
			}
		}
		else
		{
			$order = array( Request::i()->root ?: 'null' => Request::i()->order );
		}

		$now = time();
		foreach ( $order as $children )
		{
			foreach ( $children as $id => $position )
			{
				Db::i()->update( 'gameservers_servers', array( 'position' => (int) $position, 'updated_at' => $now ), array( 'id=?', (int) $id ) );
			}
		}

		if ( Request::i()->isAjax() )
		{
			return;
		}

		Output::i()->redirect( Url::internal( 'app=gameservers&module=manage&controller=servers' )->setQueryString( array( 'root' => Request::i()->root ) ) );
	}

	/**
	 * Build display title as name + game label
	 *
	 * @param	array	$server
	 * @return	string
	 */
	protected function serverTitleWithGame( array $server ): string
	{
		static $profiles = NULL;

		if ( $profiles === NULL )
		{
			$profiles = ( new GameProfiles )->all();
		}

		$title = trim( (string) ( $server['name'] ?? '' ) );
		$profileKey = strtolower( trim( (string) ( $server['game_id'] ?? '' ) ) );
		$gameLabel = trim( (string) ( $profiles[ $profileKey ]['name'] ?? '' ) );

		if ( $gameLabel === '' AND Db::i()->checkForColumn( 'gameservers_servers', 'game_name' ) )
		{
			$gameLabel = trim( (string) ( $server['game_name'] ?? '' ) );
		}

		if ( $gameLabel === '' )
		{
			$gameLabel = trim( (string) ( $server['game_id'] ?? '' ) );
		}

		if ( $gameLabel === '' )
		{
			return $title;
		}

		return $title . ' - ' . $gameLabel;
	}

	/**
	 * Human status text for tree description
	 *
	 * @param	array	$server
	 * @return	string
	 */
	protected function serverStatusText( array $server ): string
	{
		if ( (int) ( $server['online'] ?? 0 ) === 1 )
		{
			return Member::loggedIn()->language()->addToStack( 'gq_status_online' );
		}

		return Member::loggedIn()->language()->addToStack( 'gq_status_offline' );
	}

	/**
	 * Add/Edit form
	 *
	 * @return	void
	 */
	protected function form(): void
	{
		$hasForumColumn = Db::i()->checkForColumn( 'gameservers_servers', 'forum_id' );
		$hasGameNameColumn = Db::i()->checkForColumn( 'gameservers_servers', 'game_name' );
		$hasOwnerColumn = Db::i()->checkForColumn( 'gameservers_servers', 'owner_member_id' );
		$hasConnectColumn = Db::i()->checkForColumn( 'gameservers_servers', 'connect_uri' );
		$hasDiscordColumn = Db::i()->checkForColumn( 'gameservers_servers', 'discord_url' );
		$hasTs3Column = Db::i()->checkForColumn( 'gameservers_servers', 'ts3_url' );
		$hasVoteLinksColumn = Db::i()->checkForColumn( 'gameservers_servers', 'vote_links' );
		$hasIconTypeColumn = Db::i()->checkForColumn( 'gameservers_servers', 'icon_type' );
		$hasIconValueColumn = Db::i()->checkForColumn( 'gameservers_servers', 'icon_value' );
		$hasIconColumns = $hasIconTypeColumn AND $hasIconValueColumn;
		$profilesHelper = new GameProfiles;
		$gameProfiles = $profilesHelper->all();

		$id = Request::i()->id ? (int) Request::i()->id : 0;
		$server = ( $id ? $this->loadServer( $id ) : NULL );
		$currentGameId = trim( (string) ( $server['game_id'] ?? 'minecraft' ) );
		$gameOptions = $this->gameOptions( $currentGameId );
		$hasLinksTab = ( $hasConnectColumn OR $hasVoteLinksColumn OR $hasDiscordColumn OR $hasTs3Column );
		$hasForumTab = ( $hasForumColumn AND Application::appIsEnabled( 'forums' ) );

		$form = new Form;
		$form->class = trim( (string) $form->class ) . ' gqServerFormGrid';
		$form->addTab( 'gq_server_tab_main' );
		$form->add( new Text( 'gq_server_name', $server['name'] ?? NULL, TRUE, array( 'maxLength' => 120, 'rowClasses' => array( 'gqFieldHalf' ) ) ) );
		$form->add( new YesNo( 'gq_server_enabled', isset( $server['enabled'] ) ? (bool) $server['enabled'] : TRUE, options: array( 'rowClasses' => array( 'gqFieldHalf' ) ) ) );

		if ( count( $gameOptions ) )
		{
			$form->add( new Text( 'gq_server_game_id', $currentGameId, TRUE, array(
				'maxLength' => 40,
				'placeholder' => 'minecraft',
				'rowClasses' => array( 'gqFieldHalf' ),
				'autocomplete' => array(
					'source' => $gameOptions,
					'formatSource' => TRUE,
					'freeChoice' => FALSE,
					'suggestionsOnly' => TRUE,
					'maxItems' => 1,
					'commaTrigger' => FALSE,
					'minimized' => FALSE,
				),
			), function( $value ) use ( $gameOptions )
			{
				if ( is_array( $value ) )
				{
					$value = array_shift( $value );
				}

				$value = trim( (string) $value );

				if ( $value === '' OR !isset( $gameOptions[ $value ] ) )
				{
					throw new InvalidArgumentException( 'gq_server_game_id_invalid' );
				}
			} ) );
		}
		else
		{
			$form->add( new Text( 'gq_server_game_id', $currentGameId, TRUE, array( 'maxLength' => 40, 'rowClasses' => array( 'gqFieldHalf' ) ) ) );
		}

		$form->add( new Text( 'gq_server_address', $server['address'] ?? NULL, TRUE, array( 'maxLength' => 120, 'placeholder' => '127.0.0.1:25565', 'rowClasses' => array( 'gqFieldHalf' ) ), function( $value )
		{
			if ( !preg_match( '/^[A-Za-z0-9._:-]+:\d+$/', (string) $value ) )
			{
				throw new InvalidArgumentException( 'gq_server_address_invalid' );
			}
		} ) );

		if ( $hasLinksTab )
		{
			$form->addTab( 'gq_server_tab_links' );
		}

		if ( $hasConnectColumn )
		{
			$connectTemplates = $this->connectPresetTemplates();
			$currentConnect = trim( (string) ( $server['connect_uri'] ?? '' ) );
			$connectPreset = 'none';
			$connectCustom = '';

			foreach ( $connectTemplates as $presetKey => $presetTemplate )
			{
				if ( $currentConnect !== '' AND $currentConnect === $presetTemplate )
				{
					$connectPreset = $presetKey;
					break;
				}
			}

			if ( $connectPreset === 'none' AND $currentConnect !== '' )
			{
				$connectPreset = 'custom';
				$connectCustom = $currentConnect;
			}

			$form->add( new Select( 'gq_server_connect_preset', $connectPreset, FALSE, array(
				'rowClasses' => array( 'gqFieldHalf' ),
				'options' => $this->connectPresetOptions(),
				'toggles' => array(
					'custom' => array( 'gq_server_connect_custom' ),
				),
			) ) );

			$form->add( new Text( 'gq_server_connect_custom', $connectCustom, FALSE, array(
				'rowClasses' => array( 'gqFieldHalf' ),
				'maxLength' => 255,
				'placeholder' => 'steam://connect/{address}',
			), function( $value )
			{
				if ( trim( (string) ( Request::i()->gq_server_connect_preset ?? 'none' ) ) === 'custom' AND trim( (string) $value ) === '' )
				{
					throw new InvalidArgumentException( 'gq_server_connect_custom_required' );
				}
			} ) );
		}

		if ( $hasVoteLinksColumn )
		{
			$form->add( new TextArea( 'gq_server_vote_links', trim( (string) ( $server['vote_links'] ?? '' ) ), FALSE, array(
				'rowClasses' => array( 'gqFieldHalf', 'gqFieldLeft' ),
				'rows' => 5,
				'placeholder' => "TopG Romania|https://topg.org/server/12345\nGameTracker|https://www.gametracker.com/server_info/example",
			), function( $value )
			{
				$this->normalizeVoteLinksText( (string) $value );
			} ) );
		}

		if ( $hasDiscordColumn )
		{
			$form->add( new Text( 'gq_server_discord_url', trim( (string) ( $server['discord_url'] ?? '' ) ), FALSE, array(
				'rowClasses' => array( 'gqFieldHalf', 'gqFieldLeft' ),
				'maxLength' => 255,
				'placeholder' => 'https://discord.gg/example',
			), function( $value )
			{
				$value = trim( (string) $value );
				if ( $value !== '' AND !$this->isValidDiscordUrl( $value ) )
				{
					throw new InvalidArgumentException( 'gq_server_discord_invalid' );
				}
			} ) );
		}

		if ( $hasTs3Column )
		{
			$form->add( new Text( 'gq_server_ts3_url', trim( (string) ( $server['ts3_url'] ?? '' ) ), FALSE, array(
				'rowClasses' => array( 'gqFieldHalf', 'gqFieldLeft' ),
				'maxLength' => 255,
				'placeholder' => 'ts3server://example.org?port=9987',
			), function( $value )
			{
				$value = trim( (string) $value );
				if ( $value !== '' AND !$this->isValidTs3Url( $value ) )
				{
					throw new InvalidArgumentException( 'gq_server_ts3_invalid' );
				}
			} ) );
		}

		if ( $hasForumTab )
		{
			$form->addTab( 'gq_server_tab_forum' );

			$forumDefault = NULL;

			if ( isset( $server['forum_id'] ) and $server['forum_id'] )
			{
				try
				{
					$forumDefault = Forum::load( (int) $server['forum_id'] );
				}
				catch ( \Throwable )
				{
					$forumDefault = NULL;
				}
			}

			$form->add( new Node( 'gq_server_forum_id', $forumDefault, FALSE, array(
				'class' => '\\IPS\\forums\\Forum',
				'multiple' => FALSE,
				'clubs' => FALSE,
				'zeroVal' => 'none',
				'rowClasses' => array( 'gqFieldHalf' ),
			) ) );
		}

		if ( $hasOwnerColumn OR $hasIconColumns )
		{
			$form->addTab( 'gq_server_tab_main' );
		}

		if ( $hasOwnerColumn )
		{
			$ownerDefault = NULL;

			if ( !empty( $server['owner_member_id'] ) )
			{
				try
				{
					$member = Member::load( (int) $server['owner_member_id'] );
					if ( $member->member_id )
					{
						$ownerDefault = $member;
					}
				}
				catch ( \Throwable )
				{
					$ownerDefault = NULL;
				}
			}

			$form->add( new FormMember( 'gq_server_owner_member_id', $ownerDefault, FALSE, array( 'rowClasses' => array( 'gqFieldHalf' ) ) ) );
		}

		if ( $hasIconColumns )
		{
			$iconType = trim( (string) ( $server['icon_type'] ?? '' ) );
			$iconValue = trim( (string) ( $server['icon_value'] ?? '' ) );
			$iconPresetMap = $this->iconPresetClassMap();
			$iconPresetOptions = $this->iconPresetOptions();
			$defaultPreset = 'none';
			$defaultCustomIcon = '';

			if ( $iconType === 'preset' AND $iconValue !== '' )
			{
				if ( isset( $iconPresetMap[ $iconValue ] ) )
				{
					$defaultPreset = $iconValue;
				}
				else
				{
					$defaultPreset = 'custom';
					$defaultCustomIcon = $iconValue;
				}
			}

			$defaultUpload = NULL;
			if ( $iconType === 'upload' AND $iconValue !== '' )
			{
				try
				{
					$defaultUpload = File::get( 'gameservers_Icons', $iconValue );
				}
				catch ( \Throwable )
				{
					$defaultUpload = NULL;
				}
			}

			$form->add( new Select( 'gq_server_icon_preset', $defaultPreset, FALSE, array(
				'rowClasses' => array( 'gqFieldHalf' ),
				'options' => $iconPresetOptions,
				'toggles' => array(
					'custom' => array( 'gq_server_icon_preset_custom' ),
				),
			) ) );

			$form->add( new Upload( 'gq_server_icon_upload', $defaultUpload, FALSE, array(
				'rowClasses' => array( 'gqFieldHalf' ),
				'image' => TRUE,
				'allowedFileTypes' => array_merge( Image::supportedExtensions(), array( 'svg' ) ),
				'storageExtension' => 'gameservers_Icons',
				'allowStockPhotos' => FALSE,
			) ) );

			$form->add( new Text( 'gq_server_icon_preset_custom', $defaultCustomIcon, FALSE, array(
				'maxLength' => 120,
				'placeholder' => 'fa-brands fa-steam',
				'rowClasses' => array( 'gqFieldHalf' ),
			), function( $value )
			{
				if ( trim( (string) ( Request::i()->gq_server_icon_preset ?? 'none' ) ) === 'custom' )
				{
					if ( trim( (string) $value ) === '' )
					{
						throw new InvalidArgumentException( 'gq_server_icon_preset_custom_required' );
					}

					if ( $this->sanitizeFontAwesomeClasses( (string) $value ) === '' )
					{
						throw new InvalidArgumentException( 'gq_server_icon_preset_custom_invalid' );
					}
				}
			}, NULL, NULL, 'gq_server_icon_preset_custom' ) );
		}

		if ( $values = $form->values() )
		{
			$gameId = $values['gq_server_game_id'];
			if ( is_array( $gameId ) )
			{
				$gameId = array_shift( $gameId );
			}

			$now = time();
			$gameName = $gameId;

			if ( $hasGameNameColumn )
			{
				$profileName = trim( (string) ( $gameProfiles[ $profilesHelper->normalizeGameId( (string) $gameId ) ]['name'] ?? '' ) );
				if ( $profileName !== '' )
				{
					$gameName = $profileName;
				}
				else
				{
					try
					{
						$games = ( new GameQuery )->games();
						if ( isset( $games[ $gameId ] ) AND trim( (string) $games[ $gameId ] ) !== '' )
						{
							$gameName = trim( (string) $games[ $gameId ] );
						}
					}
					catch ( \Throwable )
					{
						$gameName = $gameId;
					}
				}
			}

			$save = array(
				'name' => $values['gq_server_name'],
				'game_id' => trim( (string) $gameId ),
				'address' => $values['gq_server_address'],
				'enabled' => (int) $values['gq_server_enabled'],
				'updated_at' => $now,
			);

			if ( $hasGameNameColumn )
			{
				$save['game_name'] = $gameName;
			}

			if ( $hasForumColumn )
			{
				$forumValue = $values['gq_server_forum_id'] ?? NULL;
				$save['forum_id'] = ( $forumValue instanceof Forum ) ? (int) $forumValue->_id : 0;
			}

			if ( $hasOwnerColumn )
			{
				$ownerMember = $values['gq_server_owner_member_id'] ?? NULL;
				$save['owner_member_id'] = ( $ownerMember instanceof Member ) ? (int) $ownerMember->member_id : 0;
			}

			if ( $hasConnectColumn )
			{
				$connectTemplates = $this->connectPresetTemplates();
				$connectPreset = trim( (string) ( $values['gq_server_connect_preset'] ?? 'none' ) );
				$connectUri = '';

				if ( $connectPreset === 'custom' )
				{
					$connectUri = trim( (string) ( $values['gq_server_connect_custom'] ?? '' ) );
				}
				elseif ( isset( $connectTemplates[ $connectPreset ] ) )
				{
					$connectUri = $connectTemplates[ $connectPreset ];
				}

				$save['connect_uri'] = $connectUri;
			}

			if ( $hasVoteLinksColumn )
			{
				$save['vote_links'] = $this->normalizeVoteLinksText( (string) ( $values['gq_server_vote_links'] ?? '' ) );
			}

			if ( $hasDiscordColumn )
			{
				$save['discord_url'] = trim( (string) ( $values['gq_server_discord_url'] ?? '' ) );
			}

			if ( $hasTs3Column )
			{
				$save['ts3_url'] = trim( (string) ( $values['gq_server_ts3_url'] ?? '' ) );
			}

			if ( $hasIconColumns )
			{
				$oldIconType = trim( (string) ( $server['icon_type'] ?? '' ) );
				$oldIconValue = trim( (string) ( $server['icon_value'] ?? '' ) );
				$presetMap = $this->iconPresetClassMap();
				$preset = trim( (string) ( $values['gq_server_icon_preset'] ?? 'none' ) );
				$customPreset = $this->sanitizeFontAwesomeClasses( (string) ( $values['gq_server_icon_preset_custom'] ?? '' ) );

				$newIconType = '';
				$newIconValue = '';

				if ( $preset === 'custom' AND $customPreset !== '' )
				{
					$newIconType = 'preset';
					$newIconValue = $customPreset;
				}
				elseif ( isset( $presetMap[ $preset ] ) )
				{
					$newIconType = 'preset';
					$newIconValue = $preset;
				}

				$upload = $values['gq_server_icon_upload'] ?? NULL;

				if ( $upload instanceof File AND (string) $upload !== '' )
				{
					$newIconType = 'upload';
					$newIconValue = (string) $upload;
				}

				$save['icon_type'] = $newIconType;
				$save['icon_value'] = $newIconValue;

				if ( $oldIconType === 'upload' AND $oldIconValue !== '' AND ( $newIconType !== 'upload' OR $newIconValue !== $oldIconValue ) )
				{
					try
					{
						File::get( 'gameservers_Icons', $oldIconValue )->delete();
					}
					catch ( \Throwable )
					{
					}
				}
			}

			if ( $id )
			{
				Db::i()->update( 'gameservers_servers', $save, array( 'id=?', $id ) );
			}
			else
			{
				$save['added_by'] = Member::loggedIn()->member_id;
				$save['added_at'] = $now;
				if ( Db::i()->checkForColumn( 'gameservers_servers', 'position' ) )
				{
					$save['position'] = ( (int) Db::i()->select( 'MAX(position)', 'gameservers_servers' )->first() ) + 1;
				}
				Db::i()->insert( 'gameservers_servers', $save );
			}

			Output::i()->redirect( Url::internal( 'app=gameservers&module=manage&controller=servers' ), 'saved' );
		}

		Output::i()->title = Member::loggedIn()->language()->addToStack( $id ? 'gq_edit_server' : 'gq_add_server' );
		Output::i()->output = "<style>ul.gqServerFormGrid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));column-gap:16px;}ul.gqServerFormGrid > li.ipsFieldRow{grid-column:1/-1;}ul.gqServerFormGrid > li.ipsFieldRow.gqFieldHalf{grid-column:auto;}ul.gqServerFormGrid > li.ipsFieldRow.gqFieldLeft{grid-column:1;}@media (max-width:900px){ul.gqServerFormGrid{grid-template-columns:1fr;}ul.gqServerFormGrid > li.ipsFieldRow.gqFieldLeft{grid-column:1/-1;}}</style>" . $form;
	}

	/**
	 * Get GameQuery game options for autocomplete
	 *
	 * @param	string|null	$currentGameId
	 * @return	array
	 */
	protected function gameOptions( ?string $currentGameId = NULL ): array
	{
		$options = array();
		$profiles = ( new GameProfiles )->all();

		try
		{
			$options = ( new GameQuery )->gameLabels();
		}
		catch ( \Throwable )
		{
			$options = array();
		}

		foreach ( $profiles as $gameId => $profile )
		{
			$profileName = trim( (string) ( $profile['name'] ?? '' ) );
			if ( $profileName !== '' )
			{
				$options[ $gameId ] = ( $profileName !== $gameId ) ? "{$profileName} ({$gameId})" : $gameId;
			}
			elseif ( !isset( $options[ $gameId ] ) )
			{
				$options[ $gameId ] = $gameId;
			}
		}

		$currentGameId = trim( (string) $currentGameId );
		if ( $currentGameId !== '' AND !isset( $options[ $currentGameId ] ) )
		{
			$options[ $currentGameId ] = $currentGameId;
		}

		natcasesort( $options );

		return $options;
	}

	/**
	 * Icon preset choices
	 *
	 * @return	array
	 */
	protected function iconPresetOptions(): array
	{
		return array(
			'none' => 'gq_server_icon_none',
			'server' => 'gq_icon_preset_server',
			'gamepad' => 'gq_icon_preset_gamepad',
			'crosshairs' => 'gq_icon_preset_crosshairs',
			'shield' => 'gq_icon_preset_shield',
			'trophy' => 'gq_icon_preset_trophy',
			'custom' => 'gq_icon_preset_custom',
		);
	}

	/**
	 * Built-in preset icon map
	 *
	 * @return	array
	 */
	protected function iconPresetClassMap(): array
	{
		return array(
			'server' => 'fa-server',
			'gamepad' => 'fa-gamepad',
			'crosshairs' => 'fa-crosshairs',
			'shield' => 'fa-shield-halved',
			'trophy' => 'fa-trophy',
		);
	}

	/**
	 * Sanitize custom Font Awesome class string
	 *
	 * @param	string	$classes
	 * @return	string
	 */
	protected function sanitizeFontAwesomeClasses( string $classes ): string
	{
		$classes = (string) preg_replace( '/[^A-Za-z0-9\-\s]/', '', $classes );
		$classes = trim( (string) preg_replace( '/\s+/', ' ', $classes ) );

		if ( $classes === '' OR !preg_match( '/\bfa-[a-z0-9-]+\b/i', $classes ) )
		{
			return '';
		}

		if ( !preg_match( '/\bfa-(solid|regular|brands|light|duotone|thin|sharp)\b/i', $classes ) )
		{
			$classes = 'fa-solid ' . $classes;
		}

		return $classes;
	}

	/**
	 * Connect preset option labels
	 *
	 * @return	array
	 */
	protected function connectPresetOptions(): array
	{
		return array(
			'none' => 'gq_server_connect_none',
			'steam_connect' => 'gq_server_connect_steam_connect',
			'steam_cs2' => 'gq_server_connect_steam_cs2',
			'steam_cs16' => 'gq_server_connect_steam_cs16',
			'samp' => 'gq_server_connect_samp',
			'mta' => 'gq_server_connect_mta',
			'custom' => 'gq_server_connect_custom_option',
		);
	}

	/**
	 * Connect preset templates
	 *
	 * @return	array
	 */
	protected function connectPresetTemplates(): array
	{
		return array(
			'steam_connect' => 'steam://connect/{address}',
			'steam_cs2' => 'steam://run/730//+connect {address}',
			'steam_cs16' => 'steam://run/10//+connect {address}',
			'samp' => 'samp://{address}',
			'mta' => 'mtasa://{address}',
		);
	}

	/**
	 * Normalize vote links input text
	 *
	 * @param	string	$raw
	 * @return	string
	 */
	protected function normalizeVoteLinksText( string $raw ): string
	{
		$normalized = array();

		foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) ?: array() as $line )
		{
			$line = trim( (string) $line );
			if ( $line === '' )
			{
				continue;
			}

			$pos = strpos( $line, '|' );
			if ( $pos === FALSE )
			{
				throw new InvalidArgumentException( 'gq_server_vote_links_invalid' );
			}

			$name = trim( (string) substr( $line, 0, $pos ) );
			$url = trim( (string) substr( $line, $pos + 1 ) );

			if ( $name === '' OR $url === '' )
			{
				throw new InvalidArgumentException( 'gq_server_vote_links_invalid' );
			}

			if ( !$this->isValidVoteUrl( $url ) )
			{
				throw new InvalidArgumentException( 'gq_server_vote_links_url_invalid' );
			}

			$normalized[] = $name . '|' . $url;
		}

		return implode( "\n", $normalized );
	}

	/**
	 * Validate vote URL
	 *
	 * @param	string	$url
	 * @return	bool
	 */
	protected function isValidVoteUrl( string $url ): bool
	{
		return preg_match( '/^https?:\/\/[^\s]+$/i', trim( $url ) ) === 1;
	}

	/**
	 * Validate Discord URL format
	 *
	 * @param	string	$url
	 * @return	bool
	 */
	protected function isValidDiscordUrl( string $url ): bool
	{
		$url = trim( $url );
		if ( $url === '' )
		{
			return FALSE;
		}

		return preg_match( '/^https?:\/\/(?:www\.)?(?:discord\.gg|discord\.com|discordapp\.com)(?:\/|$)/i', $url ) === 1;
	}

	/**
	 * Validate TeamSpeak URL format
	 *
	 * @param	string	$url
	 * @return	bool
	 */
	protected function isValidTs3Url( string $url ): bool
	{
		$url = trim( $url );
		if ( $url === '' )
		{
			return FALSE;
		}

		return preg_match( '/^(?:ts3server|teamspeak|https?):\/\/[^\s]+$/i', $url ) === 1;
	}

	/**
	 * Toggle server enabled state
	 *
	 * @return	void
	 */
	protected function toggle(): void
	{
		Session::i()->csrfCheck();
		$server = $this->loadServer( (int) Request::i()->id );

		Db::i()->update( 'gameservers_servers', array(
			'enabled' => $server['enabled'] ? 0 : 1,
			'updated_at' => time(),
		), array( 'id=?', $server['id'] ) );

		Output::i()->redirect( Url::internal( 'app=gameservers&module=manage&controller=servers' ), 'saved' );
	}

	/**
	 * Delete server
	 *
	 * @return	void
	 */
	protected function delete(): void
	{
		Session::i()->csrfCheck();

		try
		{
			$server = $this->loadServer( (int) Request::i()->id );
			if ( Db::i()->checkForColumn( 'gameservers_servers', 'icon_type' ) AND Db::i()->checkForColumn( 'gameservers_servers', 'icon_value' ) )
			{
				if ( trim( (string) ( $server['icon_type'] ?? '' ) ) === 'upload' AND trim( (string) ( $server['icon_value'] ?? '' ) ) !== '' )
				{
					try
					{
						File::get( 'gameservers_Icons', (string) $server['icon_value'] )->delete();
					}
					catch ( \Throwable )
					{
					}
				}
			}
		}
		catch ( \Throwable )
		{
		}

		Db::i()->delete( 'gameservers_servers', array( 'id=?', (int) Request::i()->id ) );
		Output::i()->redirect( Url::internal( 'app=gameservers&module=manage&controller=servers' ), 'deleted' );
	}

	/**
	 * Refresh server data now
	 *
	 * @return	void
	 */
	protected function refresh(): void
	{
		Session::i()->csrfCheck();

		try
		{
			$updated = ( new ServerUpdater )->refresh();
			SettingsClass::i()->changeValues( array( 'gq_last_refresh' => time() ) );
			Session::i()->log( 'acplogs__gameservers_refresh', array( $updated ) );
			Output::i()->redirect( Url::internal( 'app=gameservers&module=manage&controller=servers' ), 'gq_refresh_done' );
		}
		catch ( \Throwable $e )
		{
			Log::log( $e, 'gameservers_refresh' );
			Output::i()->redirect( Url::internal( 'app=gameservers&module=manage&controller=servers' ), 'gq_refresh_failed' );
		}
	}

	/**
	 * Load server row
	 *
	 * @param	int	$id
	 * @return	array
	 */
	protected function loadServer( int $id ): array
	{
		try
		{
			return Db::i()->select( '*', 'gameservers_servers', array( 'id=?', $id ) )->first();
		}
		catch ( UnderflowException )
		{
			Output::i()->error( 'node_error', '2GQ/1', 404, '' );
			return array();
		}
	}
}
