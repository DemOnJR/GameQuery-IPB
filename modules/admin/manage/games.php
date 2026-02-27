<?php
/**
 * @brief		Game Profiles Controller
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community
 * @subpackage	Game Servers
 * @since		27 Feb 2026
 */

namespace IPS\gameservers\modules\admin\manage;

use IPS\Db;
use IPS\Dispatcher;
use IPS\Dispatcher\Controller;
use IPS\File;
use IPS\gameservers\GameProfiles;
use IPS\gameservers\GameQuery;
use IPS\Helpers\Form;
use IPS\Helpers\Form\Select;
use IPS\Helpers\Form\Text;
use IPS\Helpers\Form\Upload;
use IPS\Http\Url;
use IPS\Image;
use IPS\Member;
use IPS\Output;
use IPS\Request;
use IPS\Session;
use IPS\Settings as SettingsClass;
use InvalidArgumentException;
use function array_key_exists;
use function count;
use function defined;
use function is_array;
use function natcasesort;
use function preg_match;
use function preg_replace;
use function rawurlencode;
use function trim;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * Game profiles controller
 */
class games extends Controller
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
		Dispatcher::i()->checkAcpPermission( 'games_manage', 'gameservers' );
		parent::execute();
	}

	/**
	 * Manage game profiles list
	 *
	 * @return	void
	 */
	protected function manage(): void
	{
		$profiles = ( new GameProfiles )->all();
		$games = $this->availableGames( $profiles );

		Output::i()->sidebar['actions']['add'] = array(
			'primary' => TRUE,
			'icon' => 'plus',
			'title' => 'gq_add_game_profile',
			'link' => Url::internal( 'app=gameservers&module=manage&controller=games&do=form' ),
		);

		Output::i()->title = Member::loggedIn()->language()->addToStack( 'menu__gameservers_manage_games' );

		if ( !count( $games ) )
		{
			Output::i()->output = "<div class='ipsBox ipsPad'><div class='ipsType_light ipsType_medium'>" . $this->escape( Member::loggedIn()->language()->addToStack( 'gq_game_profiles_empty' ) ) . "</div></div>";
			return;
		}

		$gameIdLabel = $this->escape( Member::loggedIn()->language()->addToStack( 'gq_games_game_id' ) );
		$nameLabel = $this->escape( Member::loggedIn()->language()->addToStack( 'gq_games_name' ) );
		$iconLabel = $this->escape( Member::loggedIn()->language()->addToStack( 'gq_games_icon' ) );
		$actionsLabel = $this->escape( Member::loggedIn()->language()->addToStack( 'gq_games_actions' ) );

		$html = "<div class='ipsBox'><table class='ipsTable ipsTable_responsive'><thead><tr>";
		$html .= "<th>{$iconLabel}</th><th>{$nameLabel}</th><th>{$gameIdLabel}</th><th class='ipsType_right'>{$actionsLabel}</th>";
		$html .= "</tr></thead><tbody>";

		foreach ( $games as $gameId => $catalogName )
		{
			$profile = $profiles[ $gameId ] ?? array();
			$hasProfile = isset( $profiles[ $gameId ] );
			$name = trim( (string) ( $profile['name'] ?? '' ) );
			if ( $name === '' )
			{
				$name = trim( (string) $catalogName );
			}
			if ( $name === '' )
			{
				$name = $gameId;
			}

			$icon = $this->iconPreviewHtml( $profile );
			$editUrl = $this->escape( (string) Url::internal( 'app=gameservers&module=manage&controller=games&do=form&game=' . rawurlencode( $gameId ) ) );
			$deleteUrl = $this->escape( (string) Url::internal( 'app=gameservers&module=manage&controller=games&do=delete&game=' . rawurlencode( $gameId ) )->csrf() );
			$editLabel = $this->escape( Member::loggedIn()->language()->addToStack( 'edit' ) );
			$deleteLabel = $this->escape( Member::loggedIn()->language()->addToStack( 'delete' ) );
			$deleteConfirm = $this->escape( Member::loggedIn()->language()->addToStack( 'gq_game_profile_delete_confirm' ) );
			$actions = "<a href='{$editUrl}' class='ipsButton ipsButton--veryLight ipsButton--tiny'>{$editLabel}</a>";
			if ( $hasProfile )
			{
				$actions .= " <a href='{$deleteUrl}' class='ipsButton ipsButton--veryLight ipsButton--tiny' data-confirm data-confirmSubMessage='{$deleteConfirm}'>{$deleteLabel}</a>";
			}

			$html .= "<tr>";
			$html .= "<td>{$icon}</td>";
			$html .= "<td><strong>" . $this->escape( $name ) . "</strong></td>";
			$html .= "<td><code>" . $this->escape( $gameId ) . "</code></td>";
			$html .= "<td class='ipsType_right'>{$actions}</td>";
			$html .= "</tr>";
		}

		$html .= "</tbody></table></div>";

		Output::i()->output = $html;
	}

	/**
	 * Add/edit game profile
	 *
	 * @return	void
	 */
	protected function form(): void
	{
		$profilesHelper = new GameProfiles;
		$profiles = $profilesHelper->all();
		$requestedGameId = $profilesHelper->normalizeGameId( (string) ( Request::i()->game ?? '' ) );
		$lockGameId = ( $requestedGameId !== '' );
		$knownGames = $this->availableGames( $profiles );
		$profile = ( $requestedGameId !== '' AND isset( $profiles[ $requestedGameId ] ) ) ? $profiles[ $requestedGameId ] : array();

		$defaultName = trim( (string) ( $profile['name'] ?? '' ) );
		if ( $defaultName === '' AND $requestedGameId !== '' AND isset( $knownGames[ $requestedGameId ] ) )
		{
			$defaultName = trim( (string) $knownGames[ $requestedGameId ] );
		}

		$iconType = trim( (string) ( $profile['icon_type'] ?? '' ) );
		$iconValue = trim( (string) ( $profile['icon_value'] ?? '' ) );
		$iconPresetMap = $this->iconPresetClassMap();
		$defaultPreset = 'none';
		$defaultCustom = '';
		$defaultUpload = NULL;

		if ( $iconType === 'preset' AND $iconValue !== '' )
		{
			if ( isset( $iconPresetMap[ $iconValue ] ) )
			{
				$defaultPreset = $iconValue;
			}
			else
			{
				$defaultPreset = 'custom';
				$defaultCustom = $iconValue;
			}
		}
		elseif ( $iconType === 'upload' AND $iconValue !== '' )
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

		$form = new Form;
		$form->add( new Text( 'gq_game_profile_game_id', $requestedGameId !== '' ? $requestedGameId : NULL, !$lockGameId, array(
			'maxLength' => 40,
			'placeholder' => 'minecraft',
			'disabled' => $lockGameId,
		), function( $value ) use ( $profiles, $profilesHelper, $requestedGameId, $lockGameId )
		{
			if ( $lockGameId )
			{
				return;
			}

			$normalized = $profilesHelper->normalizeGameId( (string) $value );
			if ( $normalized === '' OR preg_match( '/^[a-z0-9._-]{1,40}$/', $normalized ) !== 1 )
			{
				throw new InvalidArgumentException( 'gq_game_profile_game_id_invalid' );
			}

			if ( $normalized !== $requestedGameId AND array_key_exists( $normalized, $profiles ) )
			{
				throw new InvalidArgumentException( 'gq_game_profile_game_id_exists' );
			}
		} ) );

		$form->add( new Text( 'gq_game_profile_name', $defaultName, FALSE, array( 'maxLength' => 120 ) ) );

		$form->add( new Select( 'gq_game_profile_icon_preset', $defaultPreset, FALSE, array(
			'options' => $this->iconPresetOptions(),
			'toggles' => array(
				'custom' => array( 'gq_game_profile_icon_custom' ),
			),
		) ) );

		$form->add( new Upload( 'gq_game_profile_icon_upload', $defaultUpload, FALSE, array(
			'image' => TRUE,
			'allowedFileTypes' => array_merge( Image::supportedExtensions(), array( 'svg' ) ),
			'storageExtension' => 'gameservers_Icons',
			'allowStockPhotos' => FALSE,
		) ) );

		$form->add( new Text( 'gq_game_profile_icon_custom', $defaultCustom, FALSE, array(
			'maxLength' => 120,
			'placeholder' => 'fa-brands fa-steam',
		), function( $value )
		{
			if ( trim( (string) ( Request::i()->gq_game_profile_icon_preset ?? 'none' ) ) === 'custom' )
			{
				if ( trim( (string) $value ) === '' )
				{
					throw new InvalidArgumentException( 'gq_game_profile_icon_custom_required' );
				}

				if ( $this->sanitizeFontAwesomeClasses( (string) $value ) === '' )
				{
					throw new InvalidArgumentException( 'gq_game_profile_icon_custom_invalid' );
				}
			}
		}, NULL, NULL, 'gq_game_profile_icon_custom' ) );

		if ( $values = $form->values() )
		{
			$newGameId = $lockGameId ? $requestedGameId : $profilesHelper->normalizeGameId( (string) ( $values['gq_game_profile_game_id'] ?? '' ) );
			$newName = trim( (string) ( $values['gq_game_profile_name'] ?? '' ) );

			$presetMap = $this->iconPresetClassMap();
			$preset = trim( (string) ( $values['gq_game_profile_icon_preset'] ?? 'none' ) );
			$customPreset = $this->sanitizeFontAwesomeClasses( (string) ( $values['gq_game_profile_icon_custom'] ?? '' ) );

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

			$upload = $values['gq_game_profile_icon_upload'] ?? NULL;
			if ( $upload instanceof File AND (string) $upload !== '' )
			{
				$newIconType = 'upload';
				$newIconValue = (string) $upload;
			}

			$oldProfile = ( $requestedGameId !== '' AND isset( $profiles[ $requestedGameId ] ) ) ? $profiles[ $requestedGameId ] : array();
			$oldIconType = trim( (string) ( $oldProfile['icon_type'] ?? '' ) );
			$oldIconValue = trim( (string) ( $oldProfile['icon_value'] ?? '' ) );

			if ( $requestedGameId !== '' AND $requestedGameId !== $newGameId )
			{
				unset( $profiles[ $requestedGameId ] );
			}

			if ( $newName === '' AND $newIconType === '' )
			{
				unset( $profiles[ $newGameId ] );
			}
			else
			{
				$profiles[ $newGameId ] = array(
					'name' => $newName,
					'icon_type' => $newIconType,
					'icon_value' => $newIconValue,
				);
			}

			if ( $oldIconType === 'upload' AND $oldIconValue !== '' AND ( $newGameId !== $requestedGameId OR $newIconType !== 'upload' OR $newIconValue !== $oldIconValue ) )
			{
				try
				{
					File::get( 'gameservers_Icons', $oldIconValue )->delete();
				}
				catch ( \Throwable )
				{
				}
			}

			$this->saveProfiles( $profiles );
			Output::i()->redirect( Url::internal( 'app=gameservers&module=manage&controller=games' ), 'saved' );
		}

		Output::i()->title = Member::loggedIn()->language()->addToStack( $requestedGameId !== '' ? 'gq_edit_game_profile' : 'gq_add_game_profile' );
		Output::i()->output = $form;
	}

	/**
	 * Delete one game profile
	 *
	 * @return	void
	 */
	protected function delete(): void
	{
		Session::i()->csrfCheck();

		$profilesHelper = new GameProfiles;
		$profiles = $profilesHelper->all();
		$gameId = $profilesHelper->normalizeGameId( (string) ( Request::i()->game ?? '' ) );

		if ( $gameId !== '' AND isset( $profiles[ $gameId ] ) )
		{
			$profile = $profiles[ $gameId ];
			$iconType = trim( (string) ( $profile['icon_type'] ?? '' ) );
			$iconValue = trim( (string) ( $profile['icon_value'] ?? '' ) );

			if ( $iconType === 'upload' AND $iconValue !== '' )
			{
				try
				{
					File::get( 'gameservers_Icons', $iconValue )->delete();
				}
				catch ( \Throwable )
				{
				}
			}

			unset( $profiles[ $gameId ] );
			$this->saveProfiles( $profiles );
		}

		Output::i()->redirect( Url::internal( 'app=gameservers&module=manage&controller=games' ), 'deleted' );
	}

	/**
	 * Persist profiles map into settings
	 *
	 * @param	array	$profiles
	 * @return	void
	 */
	protected function saveProfiles( array $profiles ): void
	{
		$encoded = ( new GameProfiles )->encode( $profiles );
		SettingsClass::i()->changeValues( array( 'gq_game_profiles' => $encoded ) );
		Session::i()->log( 'acplogs__gameservers_games' );
	}

	/**
	 * Build available games map from profiles, API and configured servers
	 *
	 * @param	array	$profiles
	 * @return	array
	 */
	protected function availableGames( array $profiles ): array
	{
		$profilesHelper = new GameProfiles;
		$games = array();

		foreach ( $profiles as $gameId => $profile )
		{
			$games[ $gameId ] = trim( (string) ( $profile['name'] ?? '' ) );
		}

		try
		{
			foreach ( ( new GameQuery )->games() as $gameId => $gameName )
			{
				$gameId = $profilesHelper->normalizeGameId( (string) $gameId );
				if ( $gameId === '' )
				{
					continue;
				}

				if ( !array_key_exists( $gameId, $games ) OR trim( (string) $games[ $gameId ] ) === '' )
				{
					$games[ $gameId ] = trim( (string) $gameName );
				}
			}
		}
		catch ( \Throwable )
		{
		}

		foreach ( Db::i()->select( 'game_id, game_name', 'gameservers_servers' ) as $row )
		{
			$gameId = $profilesHelper->normalizeGameId( (string) ( $row['game_id'] ?? '' ) );
			if ( $gameId === '' )
			{
				continue;
			}

			if ( !array_key_exists( $gameId, $games ) OR trim( (string) $games[ $gameId ] ) === '' )
			{
				$games[ $gameId ] = trim( (string) ( $row['game_name'] ?? '' ) );
			}
		}

		foreach ( $games as $gameId => $name )
		{
			$name = trim( (string) $name );
			$games[ $gameId ] = ( $name !== '' ) ? $name : $gameId;
		}

		natcasesort( $games );

		return $games;
	}

	/**
	 * Render icon preview for list row
	 *
	 * @param	array	$profile
	 * @return	string
	 */
	protected function iconPreviewHtml( array $profile ): string
	{
		$iconType = trim( (string) ( $profile['icon_type'] ?? '' ) );
		$iconValue = trim( (string) ( $profile['icon_value'] ?? '' ) );

		if ( $iconType === 'upload' AND $iconValue !== '' )
		{
			try
			{
				$file = File::get( 'gameservers_Icons', $iconValue );
				return "<span class='ipsType_light'><img src='" . $this->escape( (string) $file->url ) . "' alt='' style='width:18px;height:18px;object-fit:cover;border-radius:3px;'></span>";
			}
			catch ( \Throwable )
			{
			}
		}

		if ( $iconType === 'preset' AND $iconValue !== '' )
		{
			$iconClasses = $this->resolvePresetIconClasses( $iconValue );
			if ( $iconClasses !== '' )
			{
				return "<span class='ipsType_light'><i class='{$iconClasses}' aria-hidden='true'></i></span>";
			}
		}

		return "<span class='ipsType_light'>-</span>";
	}

	/**
	 * Game profile icon preset options
	 *
	 * @return	array
	 */
	protected function iconPresetOptions(): array
	{
		return array(
			'none' => 'gq_game_profile_icon_none',
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
	 * Resolve built-in or custom Font Awesome classes
	 *
	 * @param	string	$iconValue
	 * @return	string
	 */
	protected function resolvePresetIconClasses( string $iconValue ): string
	{
		$map = array(
			'server' => 'fa-solid fa-server',
			'gamepad' => 'fa-solid fa-gamepad',
			'crosshairs' => 'fa-solid fa-crosshairs',
			'shield' => 'fa-solid fa-shield-halved',
			'trophy' => 'fa-solid fa-trophy',
		);

		if ( isset( $map[ $iconValue ] ) )
		{
			return $map[ $iconValue ];
		}

		return $this->sanitizeFontAwesomeClasses( $iconValue );
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

		if ( $classes === '' OR preg_match( '/\bfa-[a-z0-9-]+\b/i', $classes ) !== 1 )
		{
			return '';
		}

		if ( preg_match( '/\bfa-(solid|regular|brands|light|duotone|thin|sharp)\b/i', $classes ) !== 1 )
		{
			$classes = 'fa-solid ' . $classes;
		}

		return $classes;
	}

	/**
	 * Escape output
	 *
	 * @param	string	$value
	 * @return	string
	 */
	protected function escape( string $value ): string
	{
		return htmlspecialchars( $value, ENT_QUOTES | ENT_DISALLOWED, 'UTF-8' );
	}
}
