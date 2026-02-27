<?php
/**
 * @brief		Game Servers Settings Controller
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community
 * @subpackage	Game Servers
 * @since		26 Feb 2026
 */

namespace IPS\gameservers\modules\admin\manage;

use IPS\Dispatcher;
use IPS\Dispatcher\Controller;
use IPS\Helpers\Form;
use IPS\Helpers\Form\Number;
use IPS\Helpers\Form\Text;
use IPS\Http\Url;
use IPS\Member;
use IPS\Output;
use IPS\Session;
use IPS\Settings as SettingsClass;
use function defined;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * Settings controller
 */
class settings extends Controller
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
		Dispatcher::i()->checkAcpPermission( 'settings_manage', 'gameservers' );
		parent::execute();
	}

	/**
	 * Manage settings
	 *
	 * @return	void
	 */
	protected function manage(): void
	{
		$keysUrl = (string) Url::external( 'https://gamequery.dev/dashboard/keys' );
		$keysLink = "<a href='{$keysUrl}' target='_blank' rel='noopener noreferrer'>{$keysUrl}</a>";
		$apiHelp = Member::loggedIn()->language()->addToStack( 'gq_settings_api_help', FALSE, array( 'htmlsprintf' => array( $keysLink ) ) );

		$form = new Form;
		$form->add( new Text( 'gq_api_token', SettingsClass::i()->gq_api_token, FALSE, array( 'maxLength' => 255 ) ) );
		$form->add( new Text( 'gq_api_token_type', SettingsClass::i()->gq_api_token_type ?: 'FREE', TRUE, array( 'maxLength' => 32 ) ) );
		$form->add( new Text( 'gq_api_token_email', SettingsClass::i()->gq_api_token_email, FALSE, array( 'maxLength' => 255 ) ) );
		$form->add( new Number( 'gq_refresh_minutes', (int) ( SettingsClass::i()->gq_refresh_minutes ?: 5 ), TRUE, array( 'min' => 1, 'max' => 60 ) ) );

		if ( $form->values() )
		{
			$form->saveAsSettings();
			Session::i()->log( 'acplogs__gameservers_settings' );
		}

		Output::i()->title = Member::loggedIn()->language()->addToStack( 'menu__gameservers_manage_settings' );
		Output::i()->output = "<div class='ipsMessage ipsMessage_info i-margin-bottom_2'>{$apiHelp}</div>" . $form;
	}
}
