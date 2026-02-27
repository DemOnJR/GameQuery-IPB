<?php
/**
 * @brief		1.0.10 Upgrade Code
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community
 * @subpackage	Game Servers
 * @since		27 Feb 2026
 */

namespace IPS\gameservers\setup\upg_10010;

use IPS\Db;
use function defined;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * 1.0.10 Upgrade Code
 */
class Upgrade
{
	/**
	 * Add server history table for player charts
	 *
	 * @return	bool
	 */
	public function step1(): bool
	{
		if ( !Db::i()->checkForTable( 'gameservers_history' ) )
		{
			Db::i()->createTable( array(
				'name' => 'gameservers_history',
				'columns' => array(
					'id' => array(
						'name' => 'id',
						'type' => 'INT',
						'length' => 10,
						'allow_null' => FALSE,
						'default' => NULL,
						'unsigned' => TRUE,
						'auto_increment' => TRUE,
					),
					'server_id' => array(
						'name' => 'server_id',
						'type' => 'INT',
						'length' => 10,
						'allow_null' => FALSE,
						'default' => 0,
						'unsigned' => TRUE,
						'auto_increment' => FALSE,
					),
					'recorded_hour' => array(
						'name' => 'recorded_hour',
						'type' => 'INT',
						'length' => 10,
						'allow_null' => FALSE,
						'default' => 0,
						'unsigned' => TRUE,
						'auto_increment' => FALSE,
					),
					'online' => array(
						'name' => 'online',
						'type' => 'TINYINT',
						'length' => 1,
						'allow_null' => TRUE,
						'default' => NULL,
						'unsigned' => TRUE,
						'auto_increment' => FALSE,
					),
					'players_online' => array(
						'name' => 'players_online',
						'type' => 'INT',
						'length' => 10,
						'allow_null' => TRUE,
						'default' => NULL,
						'unsigned' => TRUE,
						'auto_increment' => FALSE,
					),
					'players_max' => array(
						'name' => 'players_max',
						'type' => 'INT',
						'length' => 10,
						'allow_null' => TRUE,
						'default' => NULL,
						'unsigned' => TRUE,
						'auto_increment' => FALSE,
					),
				),
				'indexes' => array(
					'PRIMARY' => array(
						'type' => 'primary',
						'name' => 'PRIMARY',
						'columns' => array( 'id' ),
						'length' => array( NULL ),
					),
					'server_hour_unique' => array(
						'type' => 'unique',
						'name' => 'server_hour_unique',
						'columns' => array( 'server_id', 'recorded_hour' ),
						'length' => array( NULL, NULL ),
					),
					'server_idx' => array(
						'type' => 'key',
						'name' => 'server_idx',
						'columns' => array( 'server_id' ),
						'length' => array( NULL ),
					),
					'recorded_hour_idx' => array(
						'type' => 'key',
						'name' => 'recorded_hour_idx',
						'columns' => array( 'recorded_hour' ),
						'length' => array( NULL ),
					),
				),
				'comment' => '',
			) );
		}

		return TRUE;
	}

	/**
	 * Add sortable position column for servers list
	 *
	 * @return	bool
	 */
	public function step2(): bool
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
