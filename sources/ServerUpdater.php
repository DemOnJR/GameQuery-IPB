<?php
/**
 * @brief		Game Server Status Updater
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community
 * @subpackage	Game Servers
 * @since		26 Feb 2026
 */

namespace IPS\gameservers;

use IPS\Db;
use RuntimeException;
use function array_key_exists;
use function count;
use function defined;
use function in_array;
use function is_array;
use function is_bool;
use function is_numeric;
use function is_string;
use function iterator_to_array;
use function json_encode;
use function preg_match;
use function strtolower;
use function trim;
use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_UNESCAPED_SLASHES;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * Game Server Status Updater
 */
class ServerUpdater
{
	/**
	 * Refresh all enabled servers
	 *
	 * @return	int	Number of updated servers
	 */
	public function refresh(): int
	{
		$servers = iterator_to_array( Db::i()->select( '*', 'gameservers_servers', array( 'enabled=?', 1 ) ) );

		if ( !count( $servers ) )
		{
			return 0;
		}

		$response = ( new GameQuery )->fetch( $servers );

		if ( !is_array( $response ) OR !count( $response ) )
		{
			throw new RuntimeException( 'GameQuery returned an empty response.' );
		}

		$mapped = array();
		$this->walkResponse( $response, $mapped );

		if ( !count( $mapped ) )
		{
			throw new RuntimeException( 'Could not parse GameQuery response.' );
		}

		$now = time();
		$updated = 0;
		$hasHistoryTable = Db::i()->checkForTable( 'gameservers_history' );

		if ( $hasHistoryTable )
		{
			Db::i()->delete( 'gameservers_history', array( 'recorded_hour<?', $now - ( 60 * 60 * 24 * 30 ) ) );
		}

		foreach ( $servers as $server )
		{
			$address = $this->normalizeAddress( (string) $server['address'] );
			$status = $mapped[ $address ] ?? NULL;

			$save = array(
				'last_checked' => $now,
				'updated_at'   => $now,
			);

			if ( $status !== NULL )
			{
				$save['online'] = $status['online'];
				$save['players_online'] = $status['players_online'];
				$save['players_max'] = $status['players_max'];
				$save['status_json'] = json_encode( $status['raw'], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
			}
			else
			{
				$save['online'] = 0;
				$save['players_online'] = NULL;
				$save['players_max'] = NULL;
				$save['status_json'] = NULL;
			}

			Db::i()->update( 'gameservers_servers', $save, array( 'id=?', $server['id'] ) );

			if ( $hasHistoryTable )
			{
				$this->recordHourlyHistory( (int) $server['id'], $save, $now );
			}

			$updated++;
		}

		return $updated;
	}

	/**
	 * Store one hourly history point for charting
	 *
	 * @param	int	$serverId
	 * @param	array	$status
	 * @param	int	$timestamp
	 * @return	void
	 */
	protected function recordHourlyHistory( int $serverId, array $status, int $timestamp ): void
	{
		if ( !$serverId )
		{
			return;
		}

		$hour = ( (int) ( $timestamp / 3600 ) ) * 3600;

		Db::i()->insert( 'gameservers_history', array(
			'server_id' => $serverId,
			'recorded_hour' => $hour,
			'online' => $status['online'] ?? NULL,
			'players_online' => $status['players_online'] ?? NULL,
			'players_max' => $status['players_max'] ?? NULL,
		), TRUE );
	}

	/**
	 * Recursively walk API response and map statuses by address
	 *
	 * @param	array	$node
	 * @param	array	&$mapped
	 * @param	string|null	$hint
	 * @return	void
	 */
	protected function walkResponse( array $node, array &$mapped, ?string $hint=NULL ): void
	{
		$address = $this->extractAddress( $node, $hint );

		if ( $address !== NULL AND $this->hasStatusData( $node ) )
		{
			$mapped[ $this->normalizeAddress( $address ) ] = array(
				'online' => $this->extractOnlineStatus( $node ),
				'players_online' => $this->extractInteger( $node, array(
					array( 'players_online' ),
					array( 'online_players' ),
					array( 'numplayers' ),
					array( 'players' ),
					array( 'players', 'online' ),
					array( 'players', 'current' ),
				) ),
				'players_max' => $this->extractInteger( $node, array(
					array( 'players_max' ),
					array( 'maxplayers' ),
					array( 'max_players' ),
					array( 'players', 'max' ),
					array( 'players', 'maximum' ),
				) ),
				'raw' => $node,
			);
		}

		foreach ( $node as $key => $value )
		{
			if ( is_array( $value ) )
			{
				$this->walkResponse( $value, $mapped, is_string( $key ) ? trim( $key ) : NULL );
			}
		}
	}

	/**
	 * Check if response node has useful status data
	 *
	 * @param	array	$node
	 * @return	bool
	 */
	protected function hasStatusData( array $node ): bool
	{
		$keys = array(
			'online',
			'is_online',
			'status',
			'state',
			'_updater',
			'players',
			'players_online',
			'players_max',
			'online_players',
			'numplayers',
			'maxplayers',
		);

		foreach ( $keys as $key )
		{
			if ( array_key_exists( $key, $node ) )
			{
				return TRUE;
			}
		}

		return FALSE;
	}

	/**
	 * Extract server address from a response node
	 *
	 * @param	array	$node
	 * @param	string|null	$hint
	 * @return	string|null
	 */
	protected function extractAddress( array $node, ?string $hint=NULL ): ?string
	{
		foreach ( array( 'server', 'address', 'ip_port', 'host' ) as $key )
		{
			if ( !empty( $node[ $key ] ) AND is_string( $node[ $key ] ) )
			{
				return trim( $node[ $key ] );
			}
		}

		if ( !empty( $node['ip'] ) AND !empty( $node['port'] ) )
		{
			return trim( (string) $node['ip'] ) . ':' . trim( (string) $node['port'] );
		}

		if ( $hint !== NULL AND preg_match( '/^[A-Za-z0-9._:-]+:\d+$/', $hint ) )
		{
			return $hint;
		}

		return NULL;
	}

	/**
	 * Extract online/offline value
	 *
	 * @param	array	$node
	 * @return	int|null
	 */
	protected function extractOnlineStatus( array $node ): ?int
	{
		foreach ( array( array( 'online' ), array( 'is_online' ), array( 'status' ), array( 'state' ), array( '_updater', 'status' ), array( '_updater', 'online' ), array( '_updater', 'is_online' ) ) as $path )
		{
			$value = $this->valueByPath( $node, $path );

			if ( $value === NULL )
			{
				continue;
			}

			$bool = $this->toBool( $value );

			if ( $bool !== NULL )
			{
				return $bool ? 1 : 0;
			}
		}

		return NULL;
	}

	/**
	 * Extract an integer value from candidate paths
	 *
	 * @param	array	$node
	 * @param	array	$paths
	 * @return	int|null
	 */
	protected function extractInteger( array $node, array $paths ): ?int
	{
		foreach ( $paths as $path )
		{
			$value = $this->valueByPath( $node, $path );

			if ( $value === NULL OR $value === '' )
			{
				continue;
			}

			if ( is_numeric( $value ) )
			{
				return (int) $value;
			}
		}

		return NULL;
	}

	/**
	 * Extract nested array value by path
	 *
	 * @param	array	$node
	 * @param	array	$path
	 * @return	mixed
	 */
	protected function valueByPath( array $node, array $path )
	{
		$current = $node;

		foreach ( $path as $segment )
		{
			if ( !is_array( $current ) OR !array_key_exists( $segment, $current ) )
			{
				return NULL;
			}

			$current = $current[ $segment ];
		}

		return $current;
	}

	/**
	 * Convert mixed value to bool where possible
	 *
	 * @param	mixed	$value
	 * @return	bool|null
	 */
	protected function toBool( $value ): ?bool
	{
		if ( is_bool( $value ) )
		{
			return $value;
		}

		if ( is_numeric( $value ) )
		{
			return ( (int) $value ) > 0;
		}

		if ( is_string( $value ) )
		{
			$value = strtolower( trim( $value ) );

			if ( in_array( $value, array( 'online', 'up', 'alive', 'true', 'yes', 'ok', 'running' ), TRUE ) )
			{
				return TRUE;
			}

			if ( in_array( $value, array( 'offline', 'down', 'dead', 'false', 'no', 'error', 'stopped' ), TRUE ) )
			{
				return FALSE;
			}
		}

		return NULL;
	}

	/**
	 * Normalize host:port for matching
	 *
	 * @param	string	$address
	 * @return	string
	 */
	protected function normalizeAddress( string $address ): string
	{
		return strtolower( trim( $address ) );
	}
}
