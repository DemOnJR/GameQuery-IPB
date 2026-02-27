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

use IPS\Db;
use IPS\Dispatcher;
use IPS\Dispatcher\Controller;
use IPS\Helpers\Form;
use IPS\Helpers\Form\Number;
use IPS\Helpers\Form\Text;
use IPS\Helpers\Form\TextArea;
use IPS\Http\Url;
use IPS\Member;
use IPS\Output;
use IPS\Request;
use IPS\Session;
use IPS\Settings as SettingsClass;
use function array_key_exists;
use function count;
use function defined;
use function htmlspecialchars;
use function implode;
use function is_array;
use function is_numeric;
use function iterator_to_array;
use function json_decode;
use function json_encode;
use function max;
use function min;
use function strtolower;
use function time;
use function trim;
use const ENT_DISALLOWED;
use const ENT_QUOTES;
use const JSON_PRETTY_PRINT;
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
		$restoreNotice = '';

		if ( isset( Request::i()->gq_restored ) )
		{
			$restoredServers = max( 0, (int) ( Request::i()->gq_restored_servers ?? 0 ) );
			$restoredSettings = max( 0, (int) ( Request::i()->gq_restored_settings ?? 0 ) );
			$restoreText = "Import complete: {$restoredServers} servers and {$restoredSettings} settings restored.";
			$restoreNotice = "<div class='ipsMessage ipsMessage_success i-margin-bottom_2'>" . $this->escape( $restoreText ) . "</div>";
		}

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

		$exportUrl = $this->escape( (string) Url::internal( 'app=gameservers&module=manage&controller=settings&do=backupExport' )->csrf() );
		$importUrl = $this->escape( (string) Url::internal( 'app=gameservers&module=manage&controller=settings&do=backupImport' ) );
		$backupTools = "<div class='ipsBox ipsPad i-margin-top_2'>";
		$backupTools .= "<h2 class='ipsType_sectionHead ipsType_reset i-margin-bottom_1'>Development Backup</h2>";
		$backupTools .= "<p class='ipsType_light ipsType_small i-margin-bottom_1'>Export your settings and servers before uninstall, then import the JSON after reinstall.</p>";
		$backupTools .= "<div class='i-flex i-gap_1'><a href='{$exportUrl}' class='ipsButton ipsButton--intermediate ipsButton--small'>Export JSON</a><a href='{$importUrl}' class='ipsButton ipsButton--veryLight ipsButton--small'>Import JSON</a></div>";
		$backupTools .= "</div>";

		Output::i()->title = Member::loggedIn()->language()->addToStack( 'menu__gameservers_manage_settings' );
		Output::i()->output = $restoreNotice . "<div class='ipsMessage ipsMessage_info i-margin-bottom_2'>{$apiHelp}</div>" . $form . $backupTools;
	}

	/**
	 * Export development backup JSON
	 *
	 * @return	void
	 */
	protected function backupExport(): void
	{
		Session::i()->csrfCheck();

		$payload = $this->buildBackupPayload();
		$json = $this->encodeJson( $payload, TRUE );
		$jsonEscaped = $this->escape( $json );
		$settingsUrl = $this->escape( (string) Url::internal( 'app=gameservers&module=manage&controller=settings' ) );
		$importUrl = $this->escape( (string) Url::internal( 'app=gameservers&module=manage&controller=settings&do=backupImport' ) );

		$html = "<div class='ipsBox ipsPad'>";
		$html .= "<h2 class='ipsType_sectionHead ipsType_reset i-margin-bottom_1'>Export Backup JSON</h2>";
		$html .= "<p class='ipsType_light ipsType_small i-margin-bottom_1'>This backup includes API credentials, game profiles, and server rows. Keep it private.</p>";
		$html .= "<textarea readonly style='width:100%;min-height:360px;font-family:monospace;line-height:1.4;'>{$jsonEscaped}</textarea>";
		$html .= "<div class='i-flex i-gap_1 i-margin-top_1'><a href='{$importUrl}' class='ipsButton ipsButton--intermediate ipsButton--small'>Go to import</a><a href='{$settingsUrl}' class='ipsButton ipsButton--veryLight ipsButton--small'>Back to settings</a></div>";
		$html .= "</div>";

		Output::i()->title = 'Export Backup';
		Output::i()->output = $html;
	}

	/**
	 * Import development backup JSON
	 *
	 * @return	void
	 */
	protected function backupImport(): void
	{
		$form = new Form( 'gq_backup_import_form' );
		$form->add( new TextArea( 'gq_backup_payload', '', TRUE, array( 'rows' => 16, 'maxLength' => 3000000 ) ) );
		$errorMessage = '';

		if ( $values = $form->values() )
		{
			try
			{
				$result = $this->applyBackupPayload( (string) ( $values['gq_backup_payload'] ?? '' ) );
				$redirectUrl = Url::internal( 'app=gameservers&module=manage&controller=settings' )->setQueryString( array(
					'gq_restored' => 1,
					'gq_restored_servers' => $result['servers'],
					'gq_restored_settings' => $result['settings'],
				) );
				Output::i()->redirect( $redirectUrl );
			}
			catch ( \RuntimeException $e )
			{
				$errorMessage = $this->escape( $e->getMessage() );
			}
			catch ( \Throwable )
			{
				$errorMessage = $this->escape( 'Import failed. Verify the JSON payload and try again.' );
			}
		}

		$settingsUrl = $this->escape( (string) Url::internal( 'app=gameservers&module=manage&controller=settings' ) );
		$html = "";
		if ( $errorMessage !== '' )
		{
			$html .= "<div class='ipsMessage ipsMessage_error i-margin-bottom_2'>{$errorMessage}</div>";
		}

		$html .= "<div class='ipsMessage ipsMessage_info i-margin-bottom_2'>Paste a JSON backup created from Export JSON. Existing servers will be replaced.</div>";
		$html .= $form;
		$html .= "<p class='i-margin-top_1'><a href='{$settingsUrl}'><i class='fa-solid fa-arrow-left i-margin-end_icon'></i>Back to settings</a></p>";

		Output::i()->title = 'Import Backup';
		Output::i()->output = $html;
	}

	/**
	 * Build backup payload
	 *
	 * @return	array
	 */
	protected function buildBackupPayload(): array
	{
		$settings = array();
		foreach ( $this->backupSettingsKeys() as $key )
		{
			$settings[ $key ] = (string) ( SettingsClass::i()->$key ?? '' );
		}

		return array(
			'format' => 'gameservers_backup_v1',
			'generated_at' => time(),
			'settings' => $settings,
			'servers' => $this->exportServersForBackup(),
		);
	}

	/**
	 * Export servers from database for backup
	 *
	 * @return	array
	 */
	protected function exportServersForBackup(): array
	{
		if ( !Db::i()->checkForTable( 'gameservers_servers' ) )
		{
			return array();
		}

		$columns = array();
		foreach ( $this->backupServerColumns() as $column )
		{
			if ( Db::i()->checkForColumn( 'gameservers_servers', $column ) )
			{
				$columns[] = $column;
			}
		}

		if ( !count( $columns ) )
		{
			return array();
		}

		$orderBy = Db::i()->checkForColumn( 'gameservers_servers', 'position' ) ? 'position ASC, name ASC' : 'name ASC';
		$rows = iterator_to_array( Db::i()->select( implode( ',', $columns ), 'gameservers_servers', NULL, $orderBy ) );
		$servers = array();

		foreach ( $rows as $row )
		{
			$servers[] = $row;
		}

		return $servers;
	}

	/**
	 * Decode and restore backup payload
	 *
	 * @param	string	$payload
	 * @return	array
	 */
	protected function applyBackupPayload( string $payload ): array
	{
		$payload = trim( $payload );
		if ( $payload === '' )
		{
			throw new \RuntimeException( 'Backup payload is empty.' );
		}

		try
		{
			$decoded = json_decode( $payload, TRUE, 512, JSON_THROW_ON_ERROR );
		}
		catch ( \Throwable )
		{
			throw new \RuntimeException( 'Backup payload is not valid JSON.' );
		}

		if ( !is_array( $decoded ) )
		{
			throw new \RuntimeException( 'Backup payload must be a JSON object.' );
		}

		$format = trim( (string) ( $decoded['format'] ?? '' ) );
		if ( $format !== 'gameservers_backup_v1' )
		{
			throw new \RuntimeException( 'Backup format is not supported. Use a file exported by this app.' );
		}

		$settings = $decoded['settings'] ?? array();
		if ( !array_key_exists( 'servers', $decoded ) )
		{
			throw new \RuntimeException( 'Backup servers section is missing.' );
		}

		$servers = $decoded['servers'];
		if ( !is_array( $settings ) )
		{
			throw new \RuntimeException( 'Backup settings section is invalid.' );
		}

		if ( !is_array( $servers ) )
		{
			throw new \RuntimeException( 'Backup servers section is invalid.' );
		}

		$settingsRestored = $this->restoreBackupSettings( $settings );
		$serversRestored = $this->restoreBackupServers( $servers );

		return array(
			'settings' => $settingsRestored,
			'servers' => $serversRestored,
		);
	}

	/**
	 * Restore backup settings
	 *
	 * @param	array	$settings
	 * @return	int
	 */
	protected function restoreBackupSettings( array $settings ): int
	{
		$updates = array();

		foreach ( $this->backupSettingsKeys() as $key )
		{
			if ( !array_key_exists( $key, $settings ) )
			{
				continue;
			}

			$updates[ $key ] = $this->normalizeBackupSettingValue( $key, $settings[ $key ] );
		}

		if ( count( $updates ) )
		{
			SettingsClass::i()->changeValues( $updates );
		}

		return count( $updates );
	}

	/**
	 * Restore backup servers
	 *
	 * @param	array	$servers
	 * @return	int
	 */
	protected function restoreBackupServers( array $servers ): int
	{
		if ( !Db::i()->checkForTable( 'gameservers_servers' ) )
		{
			throw new \RuntimeException( 'Servers table was not found.' );
		}

		if ( Db::i()->checkForTable( 'gameservers_history' ) )
		{
			Db::i()->delete( 'gameservers_history' );
		}

		Db::i()->delete( 'gameservers_servers' );

		$availableColumns = array();
		foreach ( $this->backupServerColumns() as $column )
		{
			if ( Db::i()->checkForColumn( 'gameservers_servers', $column ) )
			{
				$availableColumns[ $column ] = TRUE;
			}
		}

		$seen = array();
		$nextPosition = 1;
		$inserted = 0;
		$now = time();

		foreach ( $servers as $serverRow )
		{
			if ( !is_array( $serverRow ) )
			{
				continue;
			}

			$normalized = $this->normalizeServerBackupRow( $serverRow, $nextPosition, $now );
			if ( !count( $normalized ) )
			{
				continue;
			}

			$dedupeKey = strtolower( (string) $normalized['game_id'] ) . '|' . strtolower( (string) $normalized['address'] );
			if ( isset( $seen[ $dedupeKey ] ) )
			{
				continue;
			}

			$seen[ $dedupeKey ] = TRUE;

			$save = array();
			foreach ( $normalized as $column => $value )
			{
				if ( isset( $availableColumns[ $column ] ) )
				{
					$save[ $column ] = $value;
				}
			}

			if ( !isset( $save['name'] ) OR !isset( $save['game_id'] ) OR !isset( $save['address'] ) )
			{
				continue;
			}

			Db::i()->insert( 'gameservers_servers', $save );
			$inserted++;

			$nextPosition++;
			if ( isset( $save['position'] ) AND (int) $save['position'] >= $nextPosition )
			{
				$nextPosition = (int) $save['position'] + 1;
			}
		}

		return $inserted;
	}

	/**
	 * Normalize one setting value during import
	 *
	 * @param	string	$key
	 * @param	mixed	$value
	 * @return	string
	 */
	protected function normalizeBackupSettingValue( string $key, $value ): string
	{
		if ( $key === 'gq_refresh_minutes' )
		{
			return (string) max( 1, min( 60, (int) $value ) );
		}

		if ( $key === 'gq_last_refresh' )
		{
			return (string) max( 0, (int) $value );
		}

		if ( $key === 'gq_game_profiles' )
		{
			if ( is_array( $value ) )
			{
				return $this->encodeJson( $value );
			}

			$json = trim( (string) $value );
			if ( $json === '' )
			{
				return '{}';
			}

			try
			{
				$decoded = json_decode( $json, TRUE, 512, JSON_THROW_ON_ERROR );
			}
			catch ( \Throwable )
			{
				return '{}';
			}

			if ( !is_array( $decoded ) )
			{
				return '{}';
			}

			return $this->encodeJson( $decoded );
		}

		$stringValue = trim( (string) $value );
		if ( $key === 'gq_api_token_type' AND $stringValue === '' )
		{
			return 'FREE';
		}

		return $stringValue;
	}

	/**
	 * Normalize one server row during import
	 *
	 * @param	array	$row
	 * @param	int	$position
	 * @param	int	$now
	 * @return	array
	 */
	protected function normalizeServerBackupRow( array $row, int $position, int $now ): array
	{
		$name = trim( (string) ( $row['name'] ?? '' ) );
		$gameId = strtolower( trim( (string) ( $row['game_id'] ?? '' ) ) );
		$address = trim( (string) ( $row['address'] ?? '' ) );

		if ( $name === '' OR $gameId === '' OR $address === '' )
		{
			return array();
		}

		$gameName = trim( (string) ( $row['game_name'] ?? '' ) );
		if ( $gameName === '' )
		{
			$gameName = $gameId;
		}

		$iconType = trim( (string) ( $row['icon_type'] ?? '' ) );
		if ( $iconType !== 'preset' AND $iconType !== 'upload' )
		{
			$iconType = '';
		}

		return array(
			'name' => $name,
			'game_id' => $gameId,
			'game_name' => $gameName,
			'address' => $address,
			'connect_uri' => trim( (string) ( $row['connect_uri'] ?? '' ) ),
			'discord_url' => trim( (string) ( $row['discord_url'] ?? '' ) ),
			'ts3_url' => trim( (string) ( $row['ts3_url'] ?? '' ) ),
			'vote_links' => $this->normalizeNullableText( $row['vote_links'] ?? NULL ),
			'enabled' => ( (int) ( $row['enabled'] ?? 1 ) === 0 ) ? 0 : 1,
			'forum_id' => max( 0, (int) ( $row['forum_id'] ?? 0 ) ),
			'owner_member_id' => max( 0, (int) ( $row['owner_member_id'] ?? 0 ) ),
			'icon_type' => $iconType,
			'icon_value' => ( $iconType === '' ) ? '' : trim( (string) ( $row['icon_value'] ?? '' ) ),
			'online' => $this->normalizeOnlineValue( $row['online'] ?? NULL ),
			'players_online' => $this->normalizeNullableInt( $row['players_online'] ?? NULL ),
			'players_max' => $this->normalizeNullableInt( $row['players_max'] ?? NULL ),
			'status_json' => $this->normalizeNullableText( $row['status_json'] ?? NULL ),
			'last_checked' => $this->normalizeNullableInt( $row['last_checked'] ?? NULL ),
			'added_by' => max( 0, (int) ( $row['added_by'] ?? Member::loggedIn()->member_id ) ),
			'added_at' => max( 1, (int) ( $row['added_at'] ?? $now ) ),
			'updated_at' => max( 1, (int) ( $row['updated_at'] ?? $now ) ),
			'position' => max( 1, (int) ( $row['position'] ?? $position ) ),
		);
	}

	/**
	 * Normalize nullable integer
	 *
	 * @param	mixed	$value
	 * @return	int|null
	 */
	protected function normalizeNullableInt( $value ): ?int
	{
		if ( $value === NULL )
		{
			return NULL;
		}

		$raw = trim( (string) $value );
		if ( $raw === '' OR !is_numeric( $raw ) )
		{
			return NULL;
		}

		$number = (int) $raw;
		return ( $number < 0 ) ? 0 : $number;
	}

	/**
	 * Normalize online state value
	 *
	 * @param	mixed	$value
	 * @return	int|null
	 */
	protected function normalizeOnlineValue( $value ): ?int
	{
		$normalized = $this->normalizeNullableInt( $value );
		if ( $normalized === NULL )
		{
			return NULL;
		}

		return ( $normalized === 1 ) ? 1 : 0;
	}

	/**
	 * Normalize nullable text
	 *
	 * @param	mixed	$value
	 * @return	string|null
	 */
	protected function normalizeNullableText( $value ): ?string
	{
		if ( $value === NULL )
		{
			return NULL;
		}

		$value = trim( (string) $value );
		return ( $value === '' ) ? NULL : $value;
	}

	/**
	 * Setting keys included in backup
	 *
	 * @return	array
	 */
	protected function backupSettingsKeys(): array
	{
		return array(
			'gq_api_token',
			'gq_api_token_type',
			'gq_api_token_email',
			'gq_refresh_minutes',
			'gq_last_refresh',
			'gq_game_profiles',
		);
	}

	/**
	 * Server columns included in backup
	 *
	 * @return	array
	 */
	protected function backupServerColumns(): array
	{
		return array(
			'name',
			'game_id',
			'game_name',
			'address',
			'connect_uri',
			'discord_url',
			'ts3_url',
			'vote_links',
			'enabled',
			'forum_id',
			'owner_member_id',
			'icon_type',
			'icon_value',
			'online',
			'players_online',
			'players_max',
			'status_json',
			'last_checked',
			'added_by',
			'added_at',
			'updated_at',
			'position',
		);
	}

	/**
	 * Encode JSON payload safely
	 *
	 * @param	array	$payload
	 * @param	bool	$pretty
	 * @return	string
	 */
	protected function encodeJson( array $payload, bool $pretty=FALSE ): string
	{
		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		if ( $pretty )
		{
			$flags |= JSON_PRETTY_PRINT;
		}

		try
		{
			return (string) json_encode( $payload, $flags | JSON_THROW_ON_ERROR );
		}
		catch ( \Throwable )
		{
			return (string) json_encode( $payload, $flags );
		}
	}

	/**
	 * Escape output
	 *
	 * @param	string	$value
	 * @return	string
	 */
	protected function escape( string $value ): string
	{
		return htmlspecialchars( $value, ENT_QUOTES | ENT_DISALLOWED, 'UTF-8' );
	}
}
