<?php
/**
 * @brief		Game Servers Update Check Helper
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community
 * @subpackage	Game Servers
 * @since		27 Feb 2026
 */

namespace IPS\gameservers;

use IPS\Application;
use IPS\Data\Store;
use IPS\Http\Url;
use IPS\Member;
use function count;
use function defined;
use function htmlspecialchars;
use function is_array;
use function preg_match;
use function strpos;
use function time;
use function trim;
use const ENT_DISALLOWED;
use const ENT_QUOTES;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * Update check helper
 */
class UpdateCheck
{
	/**
	 * Remote endpoint that returns latest version payload
	 */
	protected string $feedUrl = 'https://gamequery.dev/shop/ipbgametracker/changelog';

	/**
	 * Fallback public download URL
	 */
	protected string $downloadUrl = 'https://gamequery.dev/shop/ipbgametracker';

	/**
	 * Cache key for payload
	 */
	protected string $payloadKey = 'gqUpdateCheckPayload';

	/**
	 * Cache key for last check timestamp
	 */
	protected string $timestampKey = 'gqUpdateCheckTimestamp';

	/**
	 * Cache TTL (seconds)
	 */
	protected int $cacheTtl = 21600;

	/**
	 * Return latest available update payload
	 *
	 * @return	array
	 */
	public function latestAvailable(): array
	{
		$latest = $this->latest();
		if ( !count( $latest ) )
		{
			return array();
		}

		try
		{
			$currentLongVersion = (int) Application::load( 'gameservers' )->long_version;
		}
		catch ( \Throwable )
		{
			$currentLongVersion = 0;
		}

		if ( (int) $latest['longversion'] <= $currentLongVersion )
		{
			return array();
		}

		return $latest;
	}

	/**
	 * Apply ACP menu suffix when update is available
	 *
	 * @return	void
	 */
	public function applyAcpMenuSuffix(): void
	{
		if ( !count( $this->latestAvailable() ) )
		{
			return;
		}

		$language = Member::loggedIn()->language();
		$base = (string) $language->addToStack( 'menu__gameservers_manage' );
		$suffix = (string) $language->addToStack( 'gq_update_available_suffix' );
		$append = ' (' . $suffix . ')';

		if ( strpos( $base, $append ) !== FALSE )
		{
			return;
		}

		$language->words['menu__gameservers_manage'] = $base . $append;
	}

	/**
	 * Build warning message HTML for ACP pages
	 *
	 * @return	string
	 */
	public function warningMessageHtml(): string
	{
		$latest = $this->latestAvailable();
		if ( !count( $latest ) )
		{
			return '';
		}

		$version = $this->escape( (string) ( $latest['version'] ?? '' ) );
		$downloadUrl = $this->escape( (string) ( $latest['updateurl'] ?? '' ) );
		if ( $downloadUrl === '' )
		{
			$downloadUrl = $this->escape( $this->downloadUrl );
		}

		$linkText = $this->escape( $this->downloadUrl );
		$link = "<a href='{$downloadUrl}' target='_blank' rel='noopener noreferrer'>{$linkText}</a>";

		return Member::loggedIn()->language()->addToStack( 'gq_update_detected_message', FALSE, array(
			'htmlsprintf' => array( $version, $link ),
		) );
	}

	/**
	 * Return latest payload, using cache when possible
	 *
	 * @return	array
	 */
	protected function latest(): array
	{
		$cached = $this->getCached();
		if ( count( $cached ) )
		{
			return $cached;
		}

		$remote = $this->fetchRemote();
		if ( count( $remote ) )
		{
			$this->storeCached( $remote );
			return $remote;
		}

		return array();
	}

	/**
	 * Read cached payload
	 *
	 * @return	array
	 */
	protected function getCached(): array
	{
		try
		{
			$payload = Store::i()->{$this->payloadKey};
			$checkedAt = (int) Store::i()->{$this->timestampKey};
		}
		catch ( \Throwable )
		{
			return array();
		}

		if ( $checkedAt <= 0 OR ( $checkedAt + $this->cacheTtl ) < time() )
		{
			return array();
		}

		return $this->normalizePayload( $payload );
	}

	/**
	 * Save payload to cache
	 *
	 * @param	array	$payload
	 * @return	void
	 */
	protected function storeCached( array $payload ): void
	{
		Store::i()->{$this->payloadKey} = $payload;
		Store::i()->{$this->timestampKey} = time();
	}

	/**
	 * Fetch payload from remote endpoint
	 *
	 * @return	array
	 */
	protected function fetchRemote(): array
	{
		try
		{
			$response = Url::external( $this->feedUrl )->request( 10 )->get();
		}
		catch ( \Throwable )
		{
			return array();
		}

		$decoded = NULL;

		try
		{
			$decoded = $response->decodeJson();
		}
		catch ( \Throwable )
		{
			$decoded = $this->parseLoosePayload( (string) $response );
		}

		return $this->normalizePayload( $decoded );
	}

	/**
	 * Normalize endpoint payload
	 *
	 * @param	mixed	$payload
	 * @return	array
	 */
	protected function normalizePayload( $payload ): array
	{
		if ( is_array( $payload ) AND isset( $payload[0] ) )
		{
			$latest = array();
			foreach ( $payload as $item )
			{
				$item = $this->normalizePayload( $item );
				if ( !count( $item ) )
				{
					continue;
				}

				if ( !count( $latest ) OR (int) $item['longversion'] > (int) $latest['longversion'] )
				{
					$latest = $item;
				}
			}

			return $latest;
		}

		if ( !is_array( $payload ) )
		{
			return array();
		}

		$version = trim( (string) ( $payload['version'] ?? '' ) );
		$longVersion = (int) ( $payload['longversion'] ?? 0 );
		$released = trim( (string) ( $payload['released'] ?? '' ) );
		$updateUrl = trim( (string) ( $payload['updateurl'] ?? '' ) );
		$releaseNotes = trim( (string) ( $payload['releasenotes'] ?? '' ) );

		if ( $version === '' OR $longVersion <= 0 )
		{
			return array();
		}

		if ( $updateUrl === '' )
		{
			$updateUrl = $this->downloadUrl;
		}

		return array(
			'version' => $version,
			'longversion' => $longVersion,
			'released' => $released,
			'updateurl' => $updateUrl,
			'releasenotes' => $releaseNotes,
		);
	}

	/**
	 * Parse fallback payload from non-strict JSON text
	 *
	 * @param	string	$body
	 * @return	array
	 */
	protected function parseLoosePayload( string $body ): array
	{
		$body = trim( $body );
		if ( $body === '' )
		{
			return array();
		}

		$values = array();

		if ( preg_match( '/version\s*[:=]\s*["\']?([^,\n\r"\'}]+)/i', $body, $matches ) )
		{
			$values['version'] = trim( (string) $matches[1] );
		}

		if ( preg_match( '/longversion\s*[:=]\s*["\']?([0-9]+)/i', $body, $matches ) )
		{
			$values['longversion'] = (int) $matches[1];
		}

		if ( preg_match( '/released\s*[:=]\s*["\']?([^,\n\r"\'}]+)/i', $body, $matches ) )
		{
			$values['released'] = trim( (string) $matches[1] );
		}

		if ( preg_match( '/updateurl\s*[:=]\s*["\']?(https?:\/\/[^,\n\r"\'}\s]+)/i', $body, $matches ) )
		{
			$values['updateurl'] = trim( (string) $matches[1] );
		}

		if ( preg_match( '/releasenotes\s*[:=]\s*["\']?([^\n\r\}]+)/i', $body, $matches ) )
		{
			$values['releasenotes'] = trim( (string) $matches[1] );
		}

		return $values;
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
