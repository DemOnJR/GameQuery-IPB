<?php
/**
 * @brief		Game Profiles Settings Helper
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community
 * @subpackage	Game Servers
 * @since		27 Feb 2026
 */

namespace IPS\gameservers;

use IPS\Settings;
use JsonException;
use function defined;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function strtolower;
use function trim;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * Game Profiles Settings Helper
 */
class GameProfiles
{
	/**
	 * Settings key used to store profile mapping
	 */
	protected string $settingsKey = 'gq_game_profiles';

	/**
	 * Fetch all profiles keyed by normalized game id
	 *
	 * @return	array
	 */
	public function all(): array
	{
		$raw = trim( (string) Settings::i()->{$this->settingsKey} );
		if ( $raw === '' )
		{
			return array();
		}

		try
		{
			$decoded = json_decode( $raw, TRUE, 512, JSON_THROW_ON_ERROR );
		}
		catch ( JsonException )
		{
			return array();
		}

		if ( !is_array( $decoded ) )
		{
			return array();
		}

		$profiles = array();

		foreach ( $decoded as $gameId => $profile )
		{
			if ( !is_string( $gameId ) OR !is_array( $profile ) )
			{
				continue;
			}

			$gameId = $this->normalizeGameId( $gameId );
			if ( $gameId === '' )
			{
				continue;
			}

			$name = trim( (string) ( $profile['name'] ?? '' ) );
			$iconType = trim( (string) ( $profile['icon_type'] ?? '' ) );
			$iconValue = trim( (string) ( $profile['icon_value'] ?? '' ) );

			if ( $iconType !== 'upload' AND $iconType !== 'preset' )
			{
				$iconType = '';
				$iconValue = '';
			}

			if ( $iconValue === '' )
			{
				$iconType = '';
			}

			if ( $name === '' AND $iconType === '' )
			{
				continue;
			}

			$profiles[ $gameId ] = array(
				'name' => $name,
				'icon_type' => $iconType,
				'icon_value' => $iconValue,
			);
		}

		return $profiles;
	}

	/**
	 * Get one profile by game id
	 *
	 * @param	string	$gameId
	 * @return	array
	 */
	public function get( string $gameId ): array
	{
		$gameId = $this->normalizeGameId( $gameId );
		if ( $gameId === '' )
		{
			return array();
		}

		$all = $this->all();

		return $all[ $gameId ] ?? array();
	}

	/**
	 * Normalize game id used as profile key
	 *
	 * @param	string	$gameId
	 * @return	string
	 */
	public function normalizeGameId( string $gameId ): string
	{
		return strtolower( trim( $gameId ) );
	}

	/**
	 * Encode profiles array for settings storage
	 *
	 * @param	array	$profiles
	 * @return	string
	 */
	public function encode( array $profiles ): string
	{
		$normalized = array();

		foreach ( $profiles as $gameId => $profile )
		{
			if ( !is_array( $profile ) )
			{
				continue;
			}

			$gameId = $this->normalizeGameId( (string) $gameId );
			if ( $gameId === '' )
			{
				continue;
			}

			$name = trim( (string) ( $profile['name'] ?? '' ) );
			$iconType = trim( (string) ( $profile['icon_type'] ?? '' ) );
			$iconValue = trim( (string) ( $profile['icon_value'] ?? '' ) );

			if ( $iconType !== 'upload' AND $iconType !== 'preset' )
			{
				$iconType = '';
				$iconValue = '';
			}

			if ( $iconValue === '' )
			{
				$iconType = '';
			}

			if ( $name === '' AND $iconType === '' )
			{
				continue;
			}

			$normalized[ $gameId ] = array(
				'name' => $name,
				'icon_type' => $iconType,
				'icon_value' => $iconValue,
			);
		}

		try
		{
			return json_encode( $normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}
		catch ( JsonException )
		{
			return '{}';
		}
	}
}
