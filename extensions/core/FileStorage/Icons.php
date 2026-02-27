<?php
/**
 * @brief		File Storage Extension: Icons
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community
 * @subpackage	Game Servers
 * @since		26 Feb 2026
 */

namespace IPS\gameservers\extensions\core\FileStorage;

use Exception;
use IPS\Db;
use IPS\Extensions\FileStorageAbstract;
use IPS\File;
use UnderflowException;
use function defined;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * File Storage Extension: Icons
 */
class Icons extends FileStorageAbstract
{
	/**
	 * Count stored files
	 *
	 * @return	int
	 */
	public function count(): int
	{
		if ( !Db::i()->checkForColumn( 'gameservers_servers', 'icon_type' ) OR !Db::i()->checkForColumn( 'gameservers_servers', 'icon_value' ) )
		{
			return 0;
		}

		return (int) Db::i()->select( 'COUNT(*)', 'gameservers_servers', array( 'icon_type=? AND icon_value<>?', 'upload', '' ) )->first();
	}

	/**
	 * Move stored files
	 *
	 * @param	int			$offset
	 * @param	int			$storageConfiguration
	 * @param	int|NULL	$oldConfiguration
	 * @return	void
	 */
	public function move( int $offset, int $storageConfiguration, int $oldConfiguration=NULL ): void
	{
		if ( !Db::i()->checkForColumn( 'gameservers_servers', 'icon_type' ) OR !Db::i()->checkForColumn( 'gameservers_servers', 'icon_value' ) )
		{
			throw new UnderflowException;
		}

		$row = Db::i()->select( 'id, icon_value', 'gameservers_servers', array( 'icon_type=? AND icon_value<>?', 'upload', '' ), 'id', array( $offset, 1 ) )->first();

		try
		{
			$newValue = (string) File::get( $oldConfiguration ?: 'gameservers_Icons', $row['icon_value'] )->move( $storageConfiguration );
			Db::i()->update( 'gameservers_servers', array( 'icon_value' => $newValue ), array( 'id=?', (int) $row['id'] ) );
		}
		catch ( Exception )
		{
			/* Any issues are logged */
		}
	}

	/**
	 * Check if file is valid
	 *
	 * @param	File|string	$file
	 * @return	bool
	 */
	public function isValidFile( File|string $file ): bool
	{
		if ( !Db::i()->checkForColumn( 'gameservers_servers', 'icon_type' ) OR !Db::i()->checkForColumn( 'gameservers_servers', 'icon_value' ) )
		{
			return FALSE;
		}

		try
		{
			Db::i()->select( 'id', 'gameservers_servers', array( 'icon_type=? AND icon_value=?', 'upload', (string) $file ) )->first();
			return TRUE;
		}
		catch ( UnderflowException )
		{
			return FALSE;
		}
	}

	/**
	 * Delete stored files
	 *
	 * @return	void
	 */
	public function delete(): void
	{
		if ( !Db::i()->checkForColumn( 'gameservers_servers', 'icon_type' ) OR !Db::i()->checkForColumn( 'gameservers_servers', 'icon_value' ) )
		{
			return;
		}

		foreach ( Db::i()->select( 'icon_value', 'gameservers_servers', array( 'icon_type=? AND icon_value<>?', 'upload', '' ) ) as $value )
		{
			try
			{
				File::get( 'gameservers_Icons', $value )->delete();
			}
			catch ( Exception )
			{
			}
		}
	}
}
