<?php
/**
 * @brief		Game Servers Application Class
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community
 * @subpackage	Game Servers
 * @since		26 Feb 2026
 */

namespace IPS\gameservers;

use IPS\Application as SystemApplication;
use function array_key_exists;
use function defined;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * Game Servers Application Class
 */
class Application extends SystemApplication
{
	/**
	 * ACP menu
	 *
	 * @return	array
	 */
	public function acpMenu(): array
	{
		$menu = parent::acpMenu();

		if ( array_key_exists( 'manage', $menu ) )
		{
			( new UpdateCheck )->applyAcpMenuSuffix();
		}

		return $menu;
	}
}
