<?php
/**
 * @brief		1.0.9 Upgrade Code
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community
 * @subpackage	Game Servers
 * @since		26 Feb 2026
 */

namespace IPS\gameservers\setup\upg_10009;

use IPS\Db;
use function defined;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * 1.0.9 Upgrade Code
 */
class Upgrade
{
	/**
	 * Add TeamSpeak URL column
	 *
	 * @return	bool
	 */
	public function step1(): bool
	{
		if ( !Db::i()->checkForColumn( 'gameservers_servers', 'ts3_url' ) )
		{
			Db::i()->addColumn( 'gameservers_servers', array(
				'name' => 'ts3_url',
				'type' => 'VARCHAR',
				'length' => 255,
				'allow_null' => FALSE,
				'default' => '',
				'unsigned' => FALSE,
				'auto_increment' => FALSE,
			) );
		}

		return TRUE;
	}
}
