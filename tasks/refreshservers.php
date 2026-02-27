<?php
/**
 * @brief		Refresh Servers Task
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community
 * @subpackage	Game Servers
 * @since		26 Feb 2026
 */

namespace IPS\gameservers\tasks;

use IPS\gameservers\ServerUpdater;
use IPS\Settings as SettingsClass;
use IPS\Task;
use IPS\Task\Exception as TaskException;
use function defined;
use function max;
use function trim;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * refreshservers Task
 */
class refreshservers extends Task
{
	/**
	 * Execute
	 *
	 * @return	mixed
	 * @throws	TaskException
	 */
	public function execute(): mixed
	{
		$minutes = max( 1, (int) ( SettingsClass::i()->gq_refresh_minutes ?: 5 ) );
		$lastRefresh = (int) ( SettingsClass::i()->gq_last_refresh ?: 0 );

		if ( $lastRefresh > ( time() - ( $minutes * 60 ) ) )
		{
			return NULL;
		}

		if ( trim( (string) SettingsClass::i()->gq_api_token ) === '' OR trim( (string) SettingsClass::i()->gq_api_token_email ) === '' )
		{
			return NULL;
		}

		try
		{
			$updated = ( new ServerUpdater )->refresh();
			SettingsClass::i()->changeValues( array( 'gq_last_refresh' => time() ) );
			return $updated ? "Updated {$updated} game server(s)." : NULL;
		}
		catch ( \Throwable $e )
		{
			throw new TaskException( $this, $e->getMessage() );
		}
	}

	/**
	 * Cleanup
	 *
	 * @return	void
	 */
	public function cleanup(): void
	{
	}
}
