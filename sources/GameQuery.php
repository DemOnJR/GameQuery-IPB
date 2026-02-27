<?php
/**
 * @brief		GameQuery API Client
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community
 * @subpackage	Game Servers
 * @since		26 Feb 2026
 */

namespace IPS\gameservers;

use IPS\Data\Store;
use IPS\Http\Url;
use IPS\Settings;
use JsonException;
use RuntimeException;
use function array_key_exists;
use function array_unique;
use function array_values;
use function count;
use function defined;
use function is_array;
use function json_decode;
use function json_encode;
use function natcasesort;
use function strlen;
use function substr;
use function time;
use function trim;
use const JSON_THROW_ON_ERROR;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * GameQuery API Client
 */
class GameQuery
{
	/**
	 * API Endpoints (preferred first)
	 */
	protected array $endpoints = array(
		'https://api.gamequery.dev/v1/post/fetch',
		'https://gamequery.dev/v1/post/fetch',
	);

	/**
	 * Games endpoint
	 */
	protected string $gamesEndpoint = 'https://api.gamequery.dev/v1/get/games';

	/**
	 * Games cache key
	 */
	protected string $gamesCacheKey = 'gameservers_game_list';

	/**
	 * Games cache lifetime in seconds
	 */
	protected int $gamesCacheTtl = 86400;

	/**
	 * Fetch status data for servers
	 *
	 * @param	array	$servers	Server rows containing game_id and address
	 * @return	array
	 */
	public function fetch( array $servers ): array
	{
		$payload = $this->buildPayload( $servers );

		if ( !count( $payload['servers'] ) )
		{
			return array();
		}

		$token = trim( (string) Settings::i()->gq_api_token );
		$tokenType = trim( (string) Settings::i()->gq_api_token_type );
		$tokenEmail = trim( (string) Settings::i()->gq_api_token_email );

		if ( $token === '' OR $tokenEmail === '' )
		{
			throw new RuntimeException( 'GameQuery credentials are missing.' );
		}

		$requestBody = json_encode( $payload, JSON_THROW_ON_ERROR );
		$errors = array();

		foreach ( $this->endpoints as $endpoint )
		{
			$response = Url::external( $endpoint )
				->request( 20 )
				->setHeaders( array(
					'Content-Type'      => 'application/json',
					'Accept'            => 'application/json',
					'x-api-token'       => $token,
					'x-api-token-type'  => ( $tokenType ?: 'FREE' ),
					'x-api-token-email' => $tokenEmail,
				) )
				->post( $requestBody );

			$body = trim( (string) $response );
			$statusCode = (int) $response->httpResponseCode;

			if ( $body === '' )
			{
				$errors[] = "{$endpoint} returned an empty response (HTTP {$statusCode}).";
				continue;
			}

			try
			{
				$decoded = json_decode( $body, TRUE, 512, JSON_THROW_ON_ERROR );
			}
			catch ( JsonException $e )
			{
				$snippet = substr( $body, 0, 200 );
				if ( strlen( $body ) > 200 )
				{
					$snippet .= '...';
				}

				$errors[] = "{$endpoint} returned non-JSON response (HTTP {$statusCode}): {$snippet}";
				continue;
			}

			if ( !is_array( $decoded ) )
			{
				$errors[] = "{$endpoint} returned invalid JSON structure (HTTP {$statusCode}).";
				continue;
			}

			if ( $statusCode >= 400 )
			{
				$errors[] = "{$endpoint} returned API error HTTP {$statusCode}.";
				continue;
			}

			return $decoded;
		}

		throw new RuntimeException( 'GameQuery request failed: ' . implode( ' | ', $errors ) );
	}

	/**
	 * Get games list as id => name
	 *
	 * @param	bool	$forceRefresh	Force API refresh
	 * @return	array
	 */
	public function games( bool $forceRefresh = FALSE ): array
	{
		if ( !$forceRefresh )
		{
			$cached = $this->getCachedGames();
			if ( is_array( $cached ) )
			{
				return $cached;
			}
		}

		$games = $this->fetchGames();

		Store::i()->{$this->gamesCacheKey} = array(
			'fetched_at' => time(),
			'games'      => $games,
		);

		return $games;
	}

	/**
	 * Get games list as id => "Name (id)" for UI selects
	 *
	 * @param	bool	$forceRefresh	Force API refresh
	 * @return	array
	 */
	public function gameLabels( bool $forceRefresh = FALSE ): array
	{
		$labels = array();

		foreach ( $this->games( $forceRefresh ) as $id => $name )
		{
			$name = trim( (string) $name );
			$labels[ $id ] = ( $name !== '' AND $name !== $id ) ? "{$name} ({$id})" : $id;
		}

		return $labels;
	}

	/**
	 * Fetch games list from API
	 *
	 * @return	array
	 */
	protected function fetchGames(): array
	{
		$response = Url::external( $this->gamesEndpoint )
			->request( 20 )
			->setHeaders( array(
				'Accept' => 'application/json',
			) )
			->get();

		$body = trim( (string) $response );
		$statusCode = (int) $response->httpResponseCode;

		if ( $body === '' )
		{
			throw new RuntimeException( "GameQuery games endpoint returned empty response (HTTP {$statusCode})." );
		}

		try
		{
			$decoded = json_decode( $body, TRUE, 512, JSON_THROW_ON_ERROR );
		}
		catch ( JsonException )
		{
			throw new RuntimeException( "GameQuery games endpoint returned invalid JSON (HTTP {$statusCode})." );
		}

		if ( !is_array( $decoded ) )
		{
			throw new RuntimeException( 'GameQuery games endpoint returned invalid data structure.' );
		}

		$games = array();

		foreach ( $decoded as $item )
		{
			if ( !is_array( $item ) )
			{
				continue;
			}

			$id = trim( (string) ( $item['id'] ?? '' ) );
			$name = trim( (string) ( $item['name'] ?? '' ) );

			if ( $id === '' )
			{
				continue;
			}

			$games[ $id ] = ( $name !== '' ) ? $name : $id;
		}

		if ( !count( $games ) )
		{
			throw new RuntimeException( 'GameQuery games endpoint returned an empty game list.' );
		}

		natcasesort( $games );

		return $games;
	}

	/**
	 * Get cached games list if available and not expired
	 *
	 * @return	array|null
	 */
	protected function getCachedGames(): ?array
	{
		try
		{
			$cached = Store::i()->{$this->gamesCacheKey};
		}
		catch ( \Throwable )
		{
			return NULL;
		}

		if ( !is_array( $cached ) OR !array_key_exists( 'fetched_at', $cached ) OR !array_key_exists( 'games', $cached ) )
		{
			return NULL;
		}

		if ( !is_array( $cached['games'] ) OR !count( $cached['games'] ) )
		{
			return NULL;
		}

		if ( ( (int) $cached['fetched_at'] + $this->gamesCacheTtl ) < time() )
		{
			return NULL;
		}

		return $cached['games'];
	}

	/**
	 * Build API payload from servers
	 *
	 * @param	array	$servers
	 * @return	array
	 */
	protected function buildPayload( array $servers ): array
	{
		$grouped = array();

		foreach ( $servers as $server )
		{
			if ( !is_array( $server ) )
			{
				continue;
			}

			$gameId = trim( (string) ( $server['game_id'] ?? '' ) );
			$address = trim( (string) ( $server['address'] ?? '' ) );

			if ( $gameId === '' OR $address === '' )
			{
				continue;
			}

			$grouped[ $gameId ][] = $address;
		}

		$payload = array( 'servers' => array() );

		foreach ( $grouped as $gameId => $addresses )
		{
			$payload['servers'][] = array(
				'game_id' => $gameId,
				'servers' => array_values( array_unique( $addresses ) ),
			);
		}

		return $payload;
	}
}
