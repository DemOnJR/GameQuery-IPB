<?php
/**
 * @brief		1.0.5 Upgrade Code
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community
 * @subpackage	Game Servers
 * @since		26 Feb 2026
 */

namespace IPS\gameservers\setup\upg_10005;

use IPS\Db;
use function defined;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * 1.0.5 Upgrade Code
 */
class Upgrade
{
	/**
	 * Add server metadata columns
	 *
	 * @return	bool
	 */
	public function step1(): bool
	{
		if ( !Db::i()->checkForColumn( 'gameservers_servers', 'game_name' ) )
		{
			Db::i()->addColumn( 'gameservers_servers', array(
				'name' => 'game_name',
				'type' => 'VARCHAR',
				'length' => 120,
				'allow_null' => FALSE,
				'default' => '',
				'unsigned' => FALSE,
				'auto_increment' => FALSE,
			) );
		}

		if ( !Db::i()->checkForColumn( 'gameservers_servers', 'owner_member_id' ) )
		{
			Db::i()->addColumn( 'gameservers_servers', array(
				'name' => 'owner_member_id',
				'type' => 'INT',
				'length' => 10,
				'allow_null' => FALSE,
				'default' => '0',
				'unsigned' => TRUE,
				'auto_increment' => FALSE,
			) );
		}

		if ( !Db::i()->checkForColumn( 'gameservers_servers', 'icon_type' ) )
		{
			Db::i()->addColumn( 'gameservers_servers', array(
				'name' => 'icon_type',
				'type' => 'VARCHAR',
				'length' => 20,
				'allow_null' => FALSE,
				'default' => '',
				'unsigned' => FALSE,
				'auto_increment' => FALSE,
			) );
		}

		if ( !Db::i()->checkForColumn( 'gameservers_servers', 'icon_value' ) )
		{
			Db::i()->addColumn( 'gameservers_servers', array(
				'name' => 'icon_value',
				'type' => 'VARCHAR',
				'length' => 255,
				'allow_null' => FALSE,
				'default' => '',
				'unsigned' => FALSE,
				'auto_increment' => FALSE,
			) );
		}

		if ( !Db::i()->checkForIndex( 'gameservers_servers', 'owner_idx' ) )
		{
			Db::i()->addIndex( 'gameservers_servers', array(
				'type' => 'key',
				'name' => 'owner_idx',
				'columns' => array( 'owner_member_id' ),
				'length' => array( NULL ),
			) );
		}

		return TRUE;
	}
}
