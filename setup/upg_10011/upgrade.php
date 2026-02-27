<?php
/**
 * @brief		1.0.11 Upgrade Code
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community
 * @subpackage	Game Servers
 * @since		27 Feb 2026
 */

namespace IPS\gameservers\setup\upg_10011;

use IPS\Db;
use function defined;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * 1.0.11 Upgrade Code
 */
class Upgrade
{
	/**
	 * Ensure sortable server position exists
	 *
	 * @return	bool
	 */
	public function step1(): bool
	{
		$needsInitialization = FALSE;

		if ( !Db::i()->checkForColumn( 'gameservers_servers', 'position' ) )
		{
			Db::i()->addColumn( 'gameservers_servers', array(
				'name' => 'position',
				'type' => 'INT',
				'length' => 10,
				'allow_null' => FALSE,
				'default' => 0,
				'unsigned' => TRUE,
				'auto_increment' => FALSE,
			) );

			$needsInitialization = TRUE;
		}

		if ( !Db::i()->checkForIndex( 'gameservers_servers', 'position_idx' ) )
		{
			Db::i()->addIndex( 'gameservers_servers', array(
				'type' => 'key',
				'name' => 'position_idx',
				'columns' => array( 'position' ),
				'length' => array( NULL ),
			) );
		}

		if ( $needsInitialization )
		{
			$position = 1;

			foreach ( Db::i()->select( 'id', 'gameservers_servers', NULL, 'online DESC, players_online DESC, name ASC, id ASC' ) as $id )
			{
				Db::i()->update( 'gameservers_servers', array( 'position' => $position ), array( 'id=?', (int) $id ) );
				$position++;
			}
		}

		return TRUE;
	}
}
