<?php
/**
 * @brief		Game Servers Front Controller
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community
 * @subpackage	Game Servers
 * @since		26 Feb 2026
 */

namespace IPS\gameservers\modules\front\servers;

use IPS\Application;
use IPS\DateTime;
use IPS\Db;
use IPS\Dispatcher\Controller;
use IPS\forums\Forum;
use IPS\File;
use IPS\gameservers\GameProfiles;
use IPS\Helpers\Chart;
use IPS\Helpers\Form;
use IPS\Helpers\Form\Text;
use IPS\Helpers\Form\TextArea;
use IPS\Http\Url;
use IPS\Member;
use IPS\Output;
use IPS\Request;
use IPS\Theme;
use InvalidArgumentException;
use UnderflowException;
use function count;
use function defined;
use function explode;
use function htmlspecialchars;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_scalar;
use function iterator_to_array;
use function json_decode;
use function preg_match;
use function preg_replace;
use function preg_split;
use function rawurlencode;
use function rtrim;
use function strpos;
use function strnatcasecmp;
use function str_replace;
use function str_ends_with;
use function strtolower;
use function substr;
use function trim;
use function ucwords;
use function uasort;
use const ENT_DISALLOWED;
use const ENT_QUOTES;
use const JSON_THROW_ON_ERROR;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * Front controller
 */
class servers extends Controller
{
	/**
	 * @brief	Is this for displaying content?
	 */
	public bool $isContentPage = TRUE;

	/**
	 * Execute
	 *
	 * @return	void
	 */
	public function execute(): void
	{
		/* Backward-compatible fallback for stale/generic FURL cache matching /edit into address */
		if ( ( Request::i()->do ?? '' ) === 'view' )
		{
			$address = rtrim( trim( (string) ( Request::i()->address ?? '' ) ), '/' );
			if ( $address !== '' AND str_ends_with( $address, '/edit' ) )
			{
				Request::i()->address = trim( (string) substr( $address, 0, -5 ) );
				Request::i()->do = 'edit';
			}
			elseif ( $address !== '' AND str_ends_with( $address, '/votes' ) )
			{
				Request::i()->address = trim( (string) substr( $address, 0, -6 ) );
				Request::i()->do = 'votes';
			}
		}

		parent::execute();
	}

	/**
	 * Default action
	 *
	 * @return	void
	 */
	protected function manage(): void
	{
		if ( Request::i()->id )
		{
			$this->view();
			return;
		}

		$orderBy = Db::i()->checkForColumn( 'gameservers_servers', 'position' ) ? 'position ASC, name ASC' : 'online DESC, players_online DESC, name ASC';
		$servers = iterator_to_array( Db::i()->select( '*', 'gameservers_servers', array( 'enabled=?', 1 ), $orderBy ) );

		$title = Member::loggedIn()->language()->addToStack( 'gq_servers_directory_title' );
		Output::i()->breadcrumb[] = array( $this->listingUrl(), $title );
		Output::i()->title = $title;
		Output::i()->output = $this->renderList( $servers );
	}

	/**
	 * Server details view
	 *
	 * @return	void
	 */
	protected function view(): void
	{
		$server = $this->loadRequestedServer();

		$runtime = array();
		if ( !empty( $server['status_json'] ) )
		{
			try
			{
				$decoded = json_decode( (string) $server['status_json'], TRUE, 512, JSON_THROW_ON_ERROR );
				if ( is_array( $decoded ) )
				{
					$runtime = $decoded;
				}
			}
			catch ( \Throwable )
			{
				$runtime = array();
			}
		}

		$forum = $this->loadLinkedForum( $server );

		$listTitle = Member::loggedIn()->language()->addToStack( 'gq_servers_directory_title' );
		Output::i()->breadcrumb[] = array( $this->listingUrl(), $listTitle );
		Output::i()->breadcrumb[] = array( $this->detailUrl( $server ), $server['name'] );
		Output::i()->title = (string) $server['name'] . ' - ' . Member::loggedIn()->language()->addToStack( 'gq_server_statistics' );
		Output::i()->output = $this->renderDetails( $server, $runtime, $forum );
	}

	/**
	 * Owner details edit form
	 *
	 * @return	void
	 */
	protected function edit(): void
	{
		$server = $this->loadRequestedServer( FALSE );

		if ( !Db::i()->checkForColumn( 'gameservers_servers', 'discord_url' ) )
		{
			Output::i()->error( 'node_error', '2GQF/7', 500, '' );
		}

		if ( !Db::i()->checkForColumn( 'gameservers_servers', 'vote_links' ) )
		{
			Output::i()->error( 'node_error', '2GQF/9', 500, '' );
		}

		if ( !Db::i()->checkForColumn( 'gameservers_servers', 'ts3_url' ) )
		{
			Output::i()->error( 'node_error', '2GQF/11', 500, '' );
		}

		if ( !$this->canEditServer( $server ) )
		{
			Output::i()->error( 'no_module_permission', '2GQF/8', 403, '' );
		}

		$form = new Form( 'gq_server_edit_details' );
		$form->add( new Text( 'gq_server_discord_url', trim( (string) ( $server['discord_url'] ?? '' ) ), FALSE, array(
			'maxLength' => 255,
			'placeholder' => 'https://discord.gg/example',
		), function( $value )
		{
			$value = trim( (string) $value );
			if ( $value !== '' AND !$this->isValidDiscordUrl( $value ) )
			{
				throw new InvalidArgumentException( 'gq_server_discord_invalid' );
			}
		} ) );
		$form->add( new Text( 'gq_server_ts3_url', trim( (string) ( $server['ts3_url'] ?? '' ) ), FALSE, array(
			'maxLength' => 255,
			'placeholder' => 'ts3server://example.org?port=9987',
		), function( $value )
		{
			$value = trim( (string) $value );
			if ( $value !== '' AND !$this->isValidTs3Url( $value ) )
			{
				throw new InvalidArgumentException( 'gq_server_ts3_invalid' );
			}
		} ) );
		$form->add( new TextArea( 'gq_server_vote_links', trim( (string) ( $server['vote_links'] ?? '' ) ), FALSE, array(
			'rows' => 5,
			'placeholder' => "TopG Romania|https://topg.org/server/12345\nGameTracker|https://www.gametracker.com/server_info/example",
		), function( $value )
		{
			$this->normalizeVoteLinksText( (string) $value );
		} ) );

		if ( $values = $form->values() )
		{
			Db::i()->update( 'gameservers_servers', array(
				'discord_url' => trim( (string) ( $values['gq_server_discord_url'] ?? '' ) ),
				'ts3_url' => trim( (string) ( $values['gq_server_ts3_url'] ?? '' ) ),
				'vote_links' => $this->normalizeVoteLinksText( (string) ( $values['gq_server_vote_links'] ?? '' ) ),
				'updated_at' => time(),
			), array( 'id=?', (int) $server['id'] ) );

			Output::i()->redirect( $this->detailUrl( $server ), 'saved' );
		}

		$listTitle = Member::loggedIn()->language()->addToStack( 'gq_servers_directory_title' );
		Output::i()->breadcrumb[] = array( $this->listingUrl(), $listTitle );
		Output::i()->breadcrumb[] = array( $this->detailUrl( $server ), $server['name'] );
		Output::i()->breadcrumb[] = array( $this->editUrl( $server ), Member::loggedIn()->language()->addToStack( 'gq_server_edit_details' ) );
		Output::i()->title = (string) $server['name'] . ' - ' . Member::loggedIn()->language()->addToStack( 'gq_server_edit_details' );
		Output::i()->output = $form;
	}

	/**
	 * Vote links modal
	 *
	 * @return	void
	 */
	protected function votes(): void
	{
		$server = $this->loadRequestedServer();
		$votes = $this->parseVoteLinks( (string) ( $server['vote_links'] ?? '' ) );

		if ( !count( $votes ) )
		{
			Output::i()->error( 'node_error', '2GQF/10', 404, '' );
		}

		$title = Member::loggedIn()->language()->addToStack( 'gq_server_vote_sites' );
		$html = "<div class='ipsPad gqVoteModalWrap'>";
		$html .= "<style>.gqVoteModalWrap{width:70%;margin:0 auto;padding:15px;border-radius:12px;}.gqVoteModalList{display:grid;gap:8px;margin-top:10px;}.gqVoteModalItem{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px;border:1px solid rgba(148,163,184,.35);border-radius:8px;background:linear-gradient(155deg,#0b1220 0%,#102441 52%,#1b4d8e 100%);}.gqVoteModalLeft{display:flex;align-items:center;gap:8px;min-width:0;}.gqVoteModalMeta{min-width:0;}.gqVoteModalName{font-weight:600;color:#eff6ff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}.gqVoteModalUrl{font-size:11px;line-height:1.25;color:#cbd5e1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:42ch;}@media (max-width:900px){.gqVoteModalWrap{width:100%;}}</style>";
		$html .= "<p class='ipsType_light ipsType_small'>" . $this->escape( Member::loggedIn()->language()->addToStack( 'gq_server_vote_instruction' ) ) . "</p>";
		$html .= "<div class='gqVoteModalList'>";

		foreach ( $votes as $index => $vote )
		{
			$number = (int) $index + 1;
			$html .= "<div class='gqVoteModalItem'>";
			$html .= "<div class='gqVoteModalLeft'><span class='ipsBadge ipsBadge_neutral'>#{$number}</span><div class='gqVoteModalMeta'><div class='gqVoteModalName'>" . $this->escape( $vote['name'] ) . "</div><div class='gqVoteModalUrl'>" . $this->escape( $vote['url'] ) . "</div></div></div>";
			$html .= "<a href='" . $this->escape( $vote['url'] ) . "' class='ipsButton ipsButton--primary ipsButton--tiny' target='_blank' rel='noopener noreferrer'>" . $this->escape( Member::loggedIn()->language()->addToStack( 'gq_server_vote_open' ) ) . "</a>";
			$html .= "</div>";
		}

		$html .= "</div>";
		$html .= "</div>";

		Output::i()->title = $title;
		Output::i()->output = $html;
	}

	/**
	 * Load requested server by id or slug params
	 *
	 * @return	array
	 */
	protected function loadRequestedServer( bool $requireEnabled=TRUE ): array
	{
		try
		{
			if ( Request::i()->id )
			{
				$where = $requireEnabled ? array( 'id=? AND enabled=?', (int) Request::i()->id, 1 ) : array( 'id=?', (int) Request::i()->id );
				return Db::i()->select( '*', 'gameservers_servers', $where )->first();
			}

			$game = trim( (string) Request::i()->game );
			$address = rtrim( trim( (string) Request::i()->address ), '/' );

			if ( $game === '' OR $address === '' )
			{
				Output::i()->error( 'node_error', '2GQF/2', 404, '' );
			}

			$where = $requireEnabled
				? array( 'game_id=? AND address=? AND enabled=?', $game, $address, 1 )
				: array( 'game_id=? AND address=?', $game, $address );

			return Db::i()->select( '*', 'gameservers_servers', $where )->first();
		}
		catch ( UnderflowException )
		{
			Output::i()->error( 'node_error', '2GQF/1', 404, '' );
			return array();
		}
	}

	/**
	 * Load linked forum for this server
	 *
	 * @param	array	$server
	 * @return	Forum|null
	 */
	protected function loadLinkedForum( array $server ): ?Forum
	{
		if ( !Application::appIsEnabled( 'forums' ) OR empty( $server['forum_id'] ) )
		{
			return NULL;
		}

		try
		{
			return Forum::loadAndCheckPerms( (int) $server['forum_id'] );
		}
		catch ( \Throwable )
		{
			return NULL;
		}
	}

	/**
	 * Render front listing
	 *
	 * @param	array	$servers
	 * @return	string
	 */
	protected function renderList( array $servers ): string
	{
		if ( !count( $servers ) )
		{
			return "<div class='ipsBox ipsBox--padding'><div class='ipsType_light ipsType_medium'>" . $this->escape( Member::loggedIn()->language()->addToStack( 'gq_no_servers_configured' ) ) . "</div></div>";
		}

		$gameTabs = array();
		foreach ( $servers as $server )
		{
			$tabKey = $this->gameTabKey( $server );
			if ( !isset( $gameTabs[ $tabKey ] ) )
			{
				$gameTabs[ $tabKey ] = array(
					'label' => $this->gameDisplay( $server ),
					'icon' => $this->gameTabIconHtml( $server ),
				);
			}
		}

		if ( count( $gameTabs ) > 1 )
		{
			uasort( $gameTabs, function( $left, $right )
			{
				return strnatcasecmp( (string) ( $left['label'] ?? '' ), (string) ( $right['label'] ?? '' ) );
			} );
		}

		$showTabs = ( count( $gameTabs ) > 1 );

		$html = "<style>";
		$html .= ".gqServersFilterTabs{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 12px;padding:0;}";
		$html .= ".gqServersFilterTab{display:inline-flex;align-items:center;justify-content:center;min-width:16px;height:16px;border:0;background:none;padding:0;color:inherit;opacity:.68;cursor:pointer;transition:opacity .12s ease,transform .12s ease;}";
		$html .= ".gqServersFilterTab:hover{opacity:1;transform:translateY(-1px);}";
		$html .= ".gqServersFilterTab:focus-visible{outline:2px solid var(--ips-border--strong,#b8b8b8);outline-offset:2px;border-radius:3px;}";
		$html .= ".gqServersFilterTab.is-active{opacity:1;}";
		$html .= ".gqServersFilterTabIcon{display:inline-flex;align-items:center;justify-content:center;line-height:1;font-size:15px;}";
		$html .= ".gqServersFilterTabIcon i{font-size:15px;line-height:1;}";
		$html .= ".gqServersFilterTabIcon img{display:block;width:16px;height:16px;object-fit:cover;border-radius:4px;}";
		$html .= ".gqServersCard{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;column-gap:12px;}";
		$html .= ".gqServersCardMain{min-width:0;}";
		$html .= ".gqServersCardActions{display:flex;flex-direction:column;align-items:flex-end;text-align:right;gap:0;}";
		$html .= ".gqServersCardActions--withMap{justify-content:flex-start;}";
		$html .= ".gqServersCardActions--single{justify-content:center;}";
		$html .= ".gqServersPlayersMain{line-height:1.05;}";
		$html .= ".gqServersPlayersMap{margin-top:2px;font-size:11px;line-height:1.2;opacity:.85;max-width:120px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}";
		$html .= ".gqServersNameRow{display:flex;align-items:center;gap:8px;min-width:0;}";
		$html .= "@media (max-width:700px){.gqServersCard{grid-template-columns:1fr;row-gap:8px;}.gqServersCardActions{align-items:flex-start;}}";
		$html .= "</style>";

		$html .= "<div class='ipsBox ipsBox--padding gqServersListRoot'>";
		$html .= "<div class='i-flex i-align-items_center i-justify-content_space-between i-gap_2 i-margin-bottom_2'>";
		$html .= "<h1 class='ipsType_pageTitle ipsType_reset'><i class='fa-solid fa-server i-margin-end_icon'></i>" . $this->escape( Member::loggedIn()->language()->addToStack( 'gq_servers_directory_title' ) ) . "</h1>";
		$html .= "<span class='ipsType_light ipsType_small'>" . count( $servers ) . "</span>";
		$html .= "</div>";

		if ( $showTabs )
		{
			$allLabel = $this->escape( Member::loggedIn()->language()->addToStack( 'gq_widget_tab_all' ) );
			$allIcon = "<span class='gqServersFilterTabIcon'><i class='fa-solid fa-list' aria-hidden='true'></i></span>";

			$html .= "<div class='gqServersFilterTabs' role='tablist' aria-label='" . $this->escape( Member::loggedIn()->language()->addToStack( 'gq_servers_directory_title' ) ) . "'>";
			$html .= "<button type='button' class='gqServersFilterTab is-active' data-gq-tab='all' role='tab' aria-selected='true' data-ipsTooltip title='{$allLabel}' aria-label='{$allLabel}'>{$allIcon}<span class='ipsHide'>{$allLabel}</span></button>";

			foreach ( $gameTabs as $tabKey => $tabData )
			{
				$tabLabel = $this->escape( (string) ( $tabData['label'] ?? '' ) );
				$tabIcon = (string) ( $tabData['icon'] ?? "<span class='gqServersFilterTabIcon'><i class='fa-solid fa-server' aria-hidden='true'></i></span>" );
				$html .= "<button type='button' class='gqServersFilterTab' data-gq-tab='" . $this->escape( (string) $tabKey ) . "' role='tab' aria-selected='false' data-ipsTooltip title='{$tabLabel}' aria-label='{$tabLabel}'>{$tabIcon}<span class='ipsHide'>{$tabLabel}</span></button>";
			}

			$html .= "</div>";
		}

		$html .= "<div class='i-grid i-gap_2' style='grid-template-columns:1fr;'>";

		foreach ( $servers as $server )
		{
			$status = $this->statusLabel( $server );
			$players = $this->playersText( $server );
			$url = $this->escape( (string) $this->detailUrl( $server ) );
			$icon = $this->serverIconHtml( $server );
			$gameFilterKey = $this->escape( $this->gameTabKey( $server ) );
			$showPlayersMeta = ( $server['online'] === NULL OR (int) $server['online'] !== 0 );
			$playersMetaHtml = '';
			if ( $showPlayersMeta )
			{
				$map = $this->extractMapFromServer( $server );
				$hasMap = ( $map !== '' );
				$playersClass = $hasMap ? 'gqServersCardActions gqServersCardActions--withMap' : 'gqServersCardActions gqServersCardActions--single';
				$playersMapHtml = $hasMap ? "<div class='gqServersPlayersMap'>" . $this->escape( $map ) . "</div>" : '';
				$playersMetaHtml = "<div class='{$playersClass}'><div class='ipsType_light ipsType_small gqServersPlayersMain'><i class='fa-solid fa-users i-margin-end_icon'></i>" . $this->escape( $players ) . "</div>{$playersMapHtml}</div>";
			}

			$html .= "<article class='i-background_2 i-border-radius_box i-padding_2' data-gq-game='{$gameFilterKey}'>";
			$html .= "<div class='gqServersCard'>";
			$html .= "<div class='gqServersCardMain'>";
			$html .= "<div class='i-flex i-align-items_center i-gap_2'>";
			$html .= "<div class='i-flex i-align-items_center i-gap_2' style='min-width:0;'>";
			$html .= $icon;
			$html .= "<div style='min-width:0;'>";
			$html .= "<div class='gqServersNameRow'><span class='ipsBadge {$status['class']}'>" . $this->escape( $status['label'] ) . "</span><a href='{$url}' class='ipsType_blendLinks'><strong class='ipsType_medium i-display_block'>" . $this->escape( (string) $server['name'] ) . "</strong></a></div>";
			$html .= "<div class='ipsType_light ipsType_small' style='white-space:nowrap;overflow:hidden;text-overflow:ellipsis;'>" . $this->escape( $this->gameDisplay( $server ) ) . " - " . $this->escape( (string) $server['address'] ) . "</div>";
			$html .= "</div>";
			$html .= "</div>";
			$html .= "</div>";
			$html .= "</div>";
			$html .= $playersMetaHtml;
			$html .= "</div>";
			$html .= "</article>";
		}

		$html .= "</div>";
		$html .= "</div>";

		if ( $showTabs )
		{
			$html .= "<script>(function(){var script=document.currentScript;if(!script){return;}var root=script.previousElementSibling;if(!root||root.className.indexOf('gqServersListRoot')===-1){return;}var tabs=root.querySelectorAll('.gqServersFilterTab[data-gq-tab]');var cards=root.querySelectorAll('[data-gq-game]');if(!tabs.length||!cards.length){return;}var setTab=function(key){for(var i=0;i<tabs.length;i++){var tab=tabs[i];var active=tab.getAttribute('data-gq-tab')===key;tab.classList.toggle('is-active',active);tab.setAttribute('aria-selected',active?'true':'false');}for(var j=0;j<cards.length;j++){var card=cards[j];var visible=(key==='all'||card.getAttribute('data-gq-game')===key);card.style.display=visible?'':'none';}};for(var k=0;k<tabs.length;k++){tabs[k].addEventListener('click',function(ev){ev.preventDefault();setTab(this.getAttribute('data-gq-tab'));});}})();</script>";
		}

		return $html;
	}

	/**
	 * Render server details page
	 *
	 * @param	array	$server
	 * @param	array	$runtime
	 * @param	Forum|null	$forum
	 * @return	string
	 */
	protected function renderDetails( array $server, array $runtime, ?Forum $forum ): string
	{
		$status = $this->statusLabel( $server );
		$players = $this->playersText( $server );
		$owner = $this->ownerName( $server );
		$voteLinks = $this->parseVoteLinks( (string) ( $server['vote_links'] ?? '' ) );
		$canEdit = $this->canEditServer( $server );
		$backUrl = $this->escape( (string) $this->listingUrl() );
		$editUrl = $canEdit ? $this->escape( (string) $this->editUrl( $server ) ) : '';
		$map = $this->extractMap( $runtime );
		$icon = $this->serverIconHtml( $server, 'large' );
		$statusLabel = $this->escape( $status['label'] );
		$playersLabel = $this->escape( $players );
		$gameLabel = $this->escape( $this->gameDisplay( $server ) );
		$lastChecked = $this->formatTimestamp( $server['last_checked'] ? (int) $server['last_checked'] : NULL );
		$lastCheckedLabel = $this->escape( $lastChecked );
		$historyChart = $this->renderPlayersHistoryChart( (int) ( $server['id'] ?? 0 ) );

		$html = "<style>";
		$html .= ".gqServerDetailsPage{position:relative;overflow:hidden;--gqMetricBg:var(--i-background_4,#e4eaf0);--gqMetricBorder:var(--i-background_5,#d5dde6);--gqFactBg:var(--i-background_3,#edf1f5);--gqFactBorder:var(--i-background_4,#e0e6ed);}";
		$html .= "[data-ips-scheme='dark'] .gqServerDetailsPage{--gqMetricBg:var(--i-background_4,#29303a);--gqMetricBorder:var(--i-background_5,#394350);--gqFactBg:var(--i-background_3,#222a33);--gqFactBorder:var(--i-background_4,#2f3945);}";
		$html .= ".gqServerMetricGrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:10px;margin-bottom:12px;}";
		$html .= ".gqServerMetric{background:var(--gqMetricBg);border:1px solid var(--gqMetricBorder);border-radius:10px;padding:10px 12px;min-width:0;}";
		$html .= ".gqServerMetricLabel{font-size:11px;line-height:1.2;letter-spacing:.02em;text-transform:uppercase;color:var(--i-color_soft);margin-bottom:6px;}";
		$html .= ".gqServerMetricValue{font-size:15px;line-height:1.25;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--i-color_hard);}";
		$html .= ".gqServerDetailGrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:10px;}";
		$html .= ".gqServerFactList{display:grid;gap:6px;}";
		$html .= ".gqServerFact{display:grid;grid-template-columns:140px minmax(0,1fr);gap:8px;align-items:start;padding:7px 8px;border-radius:8px;background:var(--gqFactBg);border:1px solid var(--gqFactBorder);}";
		$html .= ".gqServerFactLabel{font-size:11px;line-height:1.2;text-transform:uppercase;letter-spacing:.02em;color:var(--i-color_soft);}";
		$html .= ".gqServerFactValue{font-size:13px;line-height:1.35;word-break:break-word;color:var(--i-color_hard);}";
		$html .= ".gqServerChartWrap{min-height:210px;}";
		$html .= "@media (max-width:700px){.gqServerFact{grid-template-columns:1fr;gap:4px;}}";
		$html .= "</style>";

		$html .= "<div class='ipsBox ipsBox--padding gqServerDetailsPage'>";
		$html .= "<p class='ipsType_reset ipsType_small'><a href='{$backUrl}'><i class='fa-solid fa-arrow-left i-margin-end_icon'></i>" . $this->escape( Member::loggedIn()->language()->addToStack( 'gq_back_to_servers' ) ) . "</a></p>";
		$html .= "<div class='i-flex i-align-items_center i-justify-content_space-between i-gap_2 i-margin-bottom_2'>";
		$html .= "<h1 class='ipsType_pageTitle ipsType_reset i-flex i-align-items_center i-gap_2'>" . $icon . "<span>" . $this->escape( (string) $server['name'] ) . "</span></h1>";
		$html .= "<div class='i-flex i-align-items_center i-gap_1'>";
		if ( $canEdit )
		{
			$html .= "<a href='{$editUrl}' class='ipsButton ipsButton--intermediate ipsButton--tiny'>" . $this->escape( Member::loggedIn()->language()->addToStack( 'gq_server_edit_details' ) ) . "</a>";
		}
		$html .= "<span class='ipsBadge {$status['class']}'>" . $this->escape( $status['label'] ) . "</span>";
		$html .= "</div>";
		$html .= "</div>";
		if ( count( $voteLinks ) )
		{
			$html .= "<div class='i-flex i-flex-wrap_wrap i-gap_1 i-margin-bottom_2'>";
			foreach ( $voteLinks as $vote )
			{
				$html .= "<a href='" . $this->escape( $vote['url'] ) . "' class='ipsButton ipsButton--intermediate ipsButton--tiny' target='_blank' rel='noopener noreferrer'><i class='fa-solid fa-circle-check i-margin-end_icon'></i>" . $this->escape( $vote['name'] ) . "</a>";
			}
			$html .= "</div>";
		}

		$html .= "<div class='gqServerMetricGrid'>";
		$html .= "<div class='gqServerMetric'><div class='gqServerMetricLabel'>" . $this->escape( Member::loggedIn()->language()->addToStack( 'gq_servers_online' ) ) . "</div><div class='gqServerMetricValue'><span class='ipsBadge {$status['class']}'>{$statusLabel}</span></div></div>";
		$html .= "<div class='gqServerMetric'><div class='gqServerMetricLabel'>" . $this->escape( Member::loggedIn()->language()->addToStack( 'gq_server_players' ) ) . "</div><div class='gqServerMetricValue'>{$playersLabel}</div></div>";
		$html .= "<div class='gqServerMetric'><div class='gqServerMetricLabel'>" . $this->escape( Member::loggedIn()->language()->addToStack( 'gq_server_game' ) ) . "</div><div class='gqServerMetricValue'>{$gameLabel}</div></div>";
		$html .= "<div class='gqServerMetric'><div class='gqServerMetricLabel'>" . $this->escape( Member::loggedIn()->language()->addToStack( 'gq_server_last_checked' ) ) . "</div><div class='gqServerMetricValue'>{$lastCheckedLabel}</div></div>";
		if ( $map !== '' )
		{
			$html .= "<div class='gqServerMetric'><div class='gqServerMetricLabel'>" . $this->escape( Member::loggedIn()->language()->addToStack( 'gq_server_map' ) ) . "</div><div class='gqServerMetricValue'>" . $this->escape( $map ) . "</div></div>";
		}
		if ( $owner !== '' )
		{
			$html .= "<div class='gqServerMetric'><div class='gqServerMetricLabel'>" . $this->escape( Member::loggedIn()->language()->addToStack( 'gq_server_owner_member_id' ) ) . "</div><div class='gqServerMetricValue'>" . $this->escape( $owner ) . "</div></div>";
		}
		$html .= "</div>";

		$html .= "<div class='gqServerDetailGrid'>";
		$html .= "<section class='i-background_2 i-border-radius_box i-padding_2'>";
		$html .= "<h2 class='ipsType_sectionHead ipsType_reset i-margin-bottom_1'>" . $this->escape( Member::loggedIn()->language()->addToStack( 'gq_server_current_status' ) ) . "</h2>";
		$html .= "<div class='gqServerFactList'>";
		$html .= $this->renderServerFact( Member::loggedIn()->language()->addToStack( 'gq_server_registered_name' ), (string) $server['name'] );
		if ( !empty( $runtime['name'] ) )
		{
			$html .= $this->renderServerFact( Member::loggedIn()->language()->addToStack( 'gq_server_reported_name' ), (string) $runtime['name'] );
		}
		$html .= $this->renderServerFact( Member::loggedIn()->language()->addToStack( 'gq_server_game' ), $this->gameDisplay( $server ) );
		$html .= $this->renderServerFact( Member::loggedIn()->language()->addToStack( 'gq_server_address_label' ), (string) $server['address'] );
		if ( $map !== '' )
		{
			$html .= $this->renderServerFact( Member::loggedIn()->language()->addToStack( 'gq_server_map' ), $map );
		}
		if ( $owner !== '' )
		{
			$html .= $this->renderServerFact( Member::loggedIn()->language()->addToStack( 'gq_server_owner_member_id' ), $owner );
		}
		$html .= $this->renderServerFact( Member::loggedIn()->language()->addToStack( 'gq_servers_online' ), $status['label'] );
		$html .= $this->renderServerFact( Member::loggedIn()->language()->addToStack( 'gq_server_players' ), $players );
		$html .= $this->renderServerFact( Member::loggedIn()->language()->addToStack( 'gq_server_last_checked' ), $lastChecked );
		$html .= "</div>";
		$html .= "</section>";

		$html .= "<section class='i-background_2 i-border-radius_box i-padding_2'>";
		$html .= "<h2 class='ipsType_sectionHead ipsType_reset i-margin-bottom_1'>" . $this->escape( Member::loggedIn()->language()->addToStack( 'gq_server_players_history_title' ) ) . "</h2>";
		$html .= "<div class='gqServerChartWrap'>{$historyChart}</div>";
		$html .= "</section>";
		$html .= "</div>";
		$html .= "</div>";

		if ( $forum !== NULL )
		{
			$html .= "<div class='ipsBox ipsBox--padding' style='margin-top:3px;'>";
			$html .= $this->renderForumCategories( $forum );
			$html .= "</div>";
		}

		return $html;
	}

	/**
	 * Normalize vote links text to one-entry-per-line format
	 *
	 * @param	string	$raw
	 * @return	string
	 */
	protected function normalizeVoteLinksText( string $raw ): string
	{
		$normalized = array();

		foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) ?: array() as $line )
		{
			$line = trim( (string) $line );
			if ( $line === '' )
			{
				continue;
			}

			$pos = strpos( $line, '|' );
			if ( $pos === FALSE )
			{
				throw new InvalidArgumentException( 'gq_server_vote_links_invalid' );
			}

			$name = trim( (string) substr( $line, 0, $pos ) );
			$url = trim( (string) substr( $line, $pos + 1 ) );

			if ( $name === '' OR $url === '' )
			{
				throw new InvalidArgumentException( 'gq_server_vote_links_invalid' );
			}

			if ( !$this->isValidVoteUrl( $url ) )
			{
				throw new InvalidArgumentException( 'gq_server_vote_links_url_invalid' );
			}

			$normalized[] = $name . '|' . $url;
		}

		return implode( "\n", $normalized );
	}

	/**
	 * Parse vote links into structured rows
	 *
	 * @param	string	$raw
	 * @return	array
	 */
	protected function parseVoteLinks( string $raw ): array
	{
		try
		{
			$raw = $this->normalizeVoteLinksText( $raw );
		}
		catch ( \Throwable )
		{
			return array();
		}

		if ( trim( $raw ) === '' )
		{
			return array();
		}

		$rows = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) ?: array() as $line )
		{
			$line = trim( (string) $line );
			if ( $line === '' )
			{
				continue;
			}

			$parts = explode( '|', $line, 2 );
			if ( count( $parts ) !== 2 )
			{
				continue;
			}

			$name = trim( (string) $parts[0] );
			$url = trim( (string) $parts[1] );

			if ( $name === '' OR $url === '' OR !$this->isValidVoteUrl( $url ) )
			{
				continue;
			}

			$rows[] = array(
				'name' => $name,
				'url' => $url,
			);
		}

		return $rows;
	}

	/**
	 * Validate vote URL
	 *
	 * @param	string	$url
	 * @return	bool
	 */
	protected function isValidVoteUrl( string $url ): bool
	{
		return preg_match( '/^https?:\/\/[^\s]+$/i', trim( $url ) ) === 1;
	}

	/**
	 * Render linked forum categories in native forum style
	 *
	 * @param	Forum	$forum
	 * @return	string
	 */
	protected function renderForumCategories( Forum $forum ): string
	{
		$children = iterator_to_array( $forum->children( 'view' ) );

		if ( !count( $children ) )
		{
			return "<div class='ipsType_light ipsType_medium'>" . $this->escape( Member::loggedIn()->language()->addToStack( 'gq_server_forum_empty' ) ) . "</div>";
		}

		if ( Member::loggedIn()->getLayoutValue( 'forums_forum' ) === 'grid' )
		{
			$html = "<div data-ips-hook='subForums' class='ipsBox ipsBox--forum-categories' data-controller='core.global.core.table, forums.front.forum.forumList' data-baseurl=''>";
			$html .= "<div class='ipsBox__content'><i-data><ol class='ipsData ipsData--grid ipsData--forum-grid'>";
			foreach ( $children as $childForum )
			{
				$html .= Theme::i()->getTemplate( 'index', 'forums' )->forumGridItem( $childForum );
			}
			$html .= "</ol></i-data></div></div>";

			return $html;
		}

		$html = "<div data-ips-hook='subForums' class='ipsBox ipsBox--forum-categories' data-controller='core.global.core.table, forums.front.forum.forumList' data-baseurl=''>";
		$html .= "<div class='ipsBox__content'><i-data><ol class='ipsData ipsData--table ipsData--category ipsData--forum-category'>";
		foreach ( $children as $childForum )
		{
			$html .= Theme::i()->getTemplate( 'index', 'forums' )->forumRow( $childForum, TRUE );
		}
		$html .= "</ol></i-data></div></div>";

		return $html;
	}

	/**
	 * Render simple detail row
	 *
	 * @param	string	$langKey
	 * @param	string	$value
	 * @return	string
	 */
	protected function renderDetailRow( string $langKey, string $value ): string
	{
		$label = $this->escape( Member::loggedIn()->language()->addToStack( $langKey ) );
		$value = $this->escape( $value );

		return "<div class='ipsDataItem'><dt class='ipsDataItem_main'><strong>{$label}</strong></dt><dd class='ipsDataItem_stats'>{$value}</dd></div>";
	}

	/**
	 * Render a modern details row
	 *
	 * @param	string	$label
	 * @param	string	$value
	 * @return	string
	 */
	protected function renderServerFact( string $label, string $value ): string
	{
		$label = $this->escape( $label );
		$value = $this->escape( $value );

		return "<div class='gqServerFact'><div class='gqServerFactLabel'>{$label}</div><div class='gqServerFactValue'>{$value}</div></div>";
	}

	/**
	 * Render players chart for last 24 hours
	 *
	 * @param	int	$serverId
	 * @return	string
	 */
	protected function renderPlayersHistoryChart( int $serverId ): string
	{
		$emptyText = $this->escape( Member::loggedIn()->language()->addToStack( 'gq_server_players_history_empty' ) );

		if ( !$serverId OR !Db::i()->checkForTable( 'gameservers_history' ) )
		{
			return "<div class='ipsType_light ipsType_medium'>{$emptyText}</div>";
		}

		$endHour = ( (int) ( time() / 3600 ) ) * 3600;
		$startHour = $endHour - ( 23 * 3600 );
		$historyByHour = array();

		foreach ( Db::i()->select( 'recorded_hour, online, players_online', 'gameservers_history', array( 'server_id=? AND recorded_hour>=? AND recorded_hour<=?', $serverId, $startHour, $endHour ), 'recorded_hour ASC' ) as $row )
		{
			$historyByHour[ (int) $row['recorded_hour'] ] = $row;
		}

		$chart = new Chart;
		$chart->addHeader( Member::loggedIn()->language()->addToStack( 'date' ), 'datetime' );
		$chart->addHeader( Member::loggedIn()->language()->addToStack( 'gq_server_players_history_series' ), 'number' );

		$hasPoints = FALSE;

		for ( $hour = $startHour; $hour <= $endHour; $hour += 3600 )
		{
			$value = NULL;

			if ( isset( $historyByHour[ $hour ] ) )
			{
				$row = $historyByHour[ $hour ];

				if ( $row['online'] !== NULL AND (int) $row['online'] === 0 )
				{
					$value = 0;
				}
				elseif ( $row['players_online'] !== NULL )
				{
					$value = (int) $row['players_online'];
				}
			}

			if ( $value !== NULL )
			{
				$hasPoints = TRUE;
			}

			$chart->addRow( array( DateTime::ts( $hour ), $value ) );
		}

		if ( !$hasPoints )
		{
			return "<div class='ipsType_light ipsType_medium'>{$emptyText}</div>";
		}

		return $chart->render( 'AreaChart', array(
			'backgroundColor' => 'transparent',
			'colors' => array( '#2f9d72' ),
			'chartArea' => array(
				'left' => 44,
				'top' => 12,
				'width' => '90%',
				'height' => '74%',
			),
			'legend' => array( 'position' => 'none' ),
			'lineWidth' => 2,
			'pointSize' => 2,
			'areaOpacity' => 0.2,
			'hAxis' => array(
				'format' => 'HH:mm',
				'gridlines' => array( 'color' => '#eef2f7' ),
				'textStyle' => array( 'fontSize' => 10 ),
			),
			'vAxis' => array(
				'viewWindow' => array( 'min' => 0 ),
				'gridlines' => array( 'color' => '#eef2f7' ),
				'textStyle' => array( 'fontSize' => 10 ),
			),
			'height' => 220,
		) );
	}

	/**
	 * Render key/value rows from runtime array
	 *
	 * @param	array	$data
	 * @param	array	$skip
	 * @return	string
	 */
	protected function renderKeyValueRows( array $data, array $skip=array() ): string
	{
		$rows = '';

		foreach ( $data as $key => $value )
		{
			if ( in_array( $key, $skip, TRUE ) )
			{
				continue;
			}

			if ( !is_scalar( $value ) AND !is_bool( $value ) AND $value !== NULL )
			{
				continue;
			}

			$label = ucwords( str_replace( '_', ' ', (string) $key ) );
			$rows .= $this->renderRawRow( $label, $this->formatValue( $value ) );
		}

		return $rows;
	}

	/**
	 * Render raw row
	 *
	 * @param	string	$label
	 * @param	string	$value
	 * @return	string
	 */
	protected function renderRawRow( string $label, string $value ): string
	{
		$label = $this->escape( $label );
		$value = $this->escape( $value );

		return "<div class='ipsDataItem'><dt class='ipsDataItem_main'><strong>{$label}</strong></dt><dd class='ipsDataItem_stats'>{$value}</dd></div>";
	}

	/**
	 * Convert runtime scalar to display string
	 *
	 * @param	mixed	$value
	 * @return	string
	 */
	protected function formatValue( $value ): string
	{
		if ( $value === NULL )
		{
			return Member::loggedIn()->language()->addToStack( 'gq_status_unknown' );
		}

		if ( is_bool( $value ) )
		{
			return $value ? Member::loggedIn()->language()->addToStack( 'yes' ) : Member::loggedIn()->language()->addToStack( 'no' );
		}

		return trim( (string) $value );
	}

	/**
	 * Build status label and class
	 *
	 * @param	array	$server
	 * @return	array
	 */
	protected function statusLabel( array $server ): array
	{
		if ( $server['online'] === NULL )
		{
			return array( 'class' => 'ipsBadge_neutral', 'label' => Member::loggedIn()->language()->addToStack( 'gq_status_unknown' ) );
		}

		if ( (int) $server['online'] === 1 )
		{
			return array( 'class' => 'ipsBadge_positive', 'label' => Member::loggedIn()->language()->addToStack( 'gq_status_online' ) );
		}

		return array( 'class' => 'ipsBadge_negative', 'label' => Member::loggedIn()->language()->addToStack( 'gq_status_offline' ) );
	}

	/**
	 * Build player display
	 *
	 * @param	array	$server
	 * @return	string
	 */
	protected function playersText( array $server ): string
	{
		if ( (int) $server['online'] === 1 )
		{
			if ( $server['players_online'] !== NULL AND $server['players_max'] !== NULL )
			{
				return (int) $server['players_online'] . '/' . (int) $server['players_max'];
			}

			if ( $server['players_online'] !== NULL )
			{
				return (string) (int) $server['players_online'];
			}
		}

		if ( (int) $server['online'] === 0 )
		{
			return Member::loggedIn()->language()->addToStack( 'gq_status_offline' );
		}

		return Member::loggedIn()->language()->addToStack( 'gq_status_unknown' );
	}

	/**
	 * Format timestamp
	 *
	 * @param	int|null	$timestamp
	 * @return	string
	 */
	protected function formatTimestamp( ?int $timestamp ): string
	{
		if ( !$timestamp )
		{
			return Member::loggedIn()->language()->addToStack( 'gq_never' );
		}

		return (string) DateTime::ts( $timestamp );
	}

	/**
	 * Listing URL
	 *
	 * @return	Url
	 */
	protected function listingUrl(): Url
	{
		try
		{
			return Url::internal( 'app=gameservers&module=servers&controller=servers', 'front', 'gameservers_servers' );
		}
		catch ( \Throwable )
		{
			return Url::internal( 'app=gameservers&module=servers&controller=servers', 'front' );
		}
	}

	/**
	 * Detail URL
	 *
	 * @param	array	$server
	 * @return	Url
	 */
	protected function detailUrl( array $server ): Url
	{
		$queryString = 'app=gameservers&module=servers&controller=servers&do=view&game=' . rawurlencode( (string) $server['game_id'] ) . '&address=' . rawurlencode( (string) $server['address'] );

		try
		{
			return Url::internal( $queryString, 'front', 'gameservers_server' );
		}
		catch ( \Throwable )
		{
			return Url::internal( $queryString, 'front' );
		}
	}

	/**
	 * Edit URL
	 *
	 * @param	array	$server
	 * @return	Url
	 */
	protected function editUrl( array $server ): Url
	{
		$queryString = 'app=gameservers&module=servers&controller=servers&do=edit&game=' . rawurlencode( (string) $server['game_id'] ) . '&address=' . rawurlencode( (string) $server['address'] );

		try
		{
			return Url::internal( $queryString, 'front', 'gameservers_server_edit' );
		}
		catch ( \Throwable )
		{
			return Url::internal( $queryString, 'front' );
		}
	}

	/**
	 * Can current user edit owner-managed details?
	 *
	 * @param	array	$server
	 * @return	bool
	 */
	protected function canEditServer( array $server ): bool
	{
		$member = Member::loggedIn();

		if ( !$member->member_id )
		{
			return FALSE;
		}

		if ( (int) ( $server['owner_member_id'] ?? 0 ) === (int) $member->member_id )
		{
			return TRUE;
		}

		try
		{
			return $member->hasAcpRestriction( 'gameservers', 'manage', 'servers_manage' );
		}
		catch ( \Throwable )
		{
			return FALSE;
		}
	}

	/**
	 * Validate Discord URL format
	 *
	 * @param	string	$url
	 * @return	bool
	 */
	protected function isValidDiscordUrl( string $url ): bool
	{
		$url = trim( $url );
		if ( $url === '' )
		{
			return FALSE;
		}

		return preg_match( '/^https?:\/\/(?:www\.)?(?:discord\.gg|discord\.com|discordapp\.com)(?:\/|$)/i', $url ) === 1;
	}

	/**
	 * Validate TeamSpeak URL format
	 *
	 * @param	string	$url
	 * @return	bool
	 */
	protected function isValidTs3Url( string $url ): bool
	{
		$url = trim( $url );
		if ( $url === '' )
		{
			return FALSE;
		}

		return preg_match( '/^(?:ts3server|teamspeak|https?):\/\/[^\s]+$/i', $url ) === 1;
	}

	/**
	 * Display game label
	 *
	 * @param	array	$server
	 * @return	string
	 */
	protected function gameDisplay( array $server ): string
	{
		$profileName = trim( (string) ( $this->gameProfileForServer( $server )['name'] ?? '' ) );
		if ( $profileName !== '' )
		{
			return $profileName;
		}

		if ( isset( $server['game_name'] ) AND trim( (string) $server['game_name'] ) !== '' )
		{
			return trim( (string) $server['game_name'] );
		}

		return trim( (string) ( $server['game_id'] ?? '' ) );
	}

	/**
	 * Build game key used by list tabs
	 *
	 * @param	array	$server
	 * @return	string
	 */
	protected function gameTabKey( array $server ): string
	{
		$raw = trim( (string) ( $server['game_id'] ?? '' ) );

		if ( $raw === '' )
		{
			$raw = $this->gameDisplay( $server );
		}

		$key = (string) preg_replace( '/[^a-z0-9]+/i', '-', strtolower( $raw ) );
		$key = trim( $key, '-' );

		if ( $key === '' )
		{
			return 'unknown-game';
		}

		return $key;
	}

	/**
	 * Render icon used in list tab button
	 *
	 * @param	array	$server
	 * @return	string
	 */
	protected function gameTabIconHtml( array $server ): string
	{
		$profile = $this->gameProfileForServer( $server );
		$iconType = trim( (string) ( $profile['icon_type'] ?? '' ) );
		$iconValue = trim( (string) ( $profile['icon_value'] ?? '' ) );

		if ( $iconType === 'upload' AND $iconValue !== '' )
		{
			try
			{
				$file = File::get( 'gameservers_Icons', $iconValue );
				return "<span class='gqServersFilterTabIcon'><img src='" . $this->escape( (string) $file->url ) . "' alt=''></span>";
			}
			catch ( \Throwable )
			{
			}
		}

		if ( $iconType === 'preset' )
		{
			$iconClasses = $this->resolvePresetIconClasses( $iconValue );
			if ( $iconClasses !== '' )
			{
				return "<span class='gqServersFilterTabIcon'><i class='{$iconClasses}' aria-hidden='true'></i></span>";
			}
		}

		return "<span class='gqServersFilterTabIcon'><i class='" . $this->defaultGameTabIconClasses( $server ) . "' aria-hidden='true'></i></span>";
	}

	/**
	 * Resolve fallback icon classes by game id
	 *
	 * @param	array	$server
	 * @return	string
	 */
	protected function defaultGameTabIconClasses( array $server ): string
	{
		$gameId = strtolower( trim( (string) ( $server['game_id'] ?? '' ) ) );

		if ( $gameId === '' )
		{
			return 'fa-solid fa-gamepad';
		}

		if ( strpos( $gameId, 'minecraft' ) !== FALSE )
		{
			return 'fa-solid fa-cube';
		}

		if ( strpos( $gameId, 'cs2' ) !== FALSE OR strpos( $gameId, 'csgo' ) !== FALSE OR strpos( $gameId, 'counter' ) !== FALSE )
		{
			return 'fa-solid fa-crosshairs';
		}

		if ( strpos( $gameId, 'rust' ) !== FALSE )
		{
			return 'fa-solid fa-hammer';
		}

		if ( strpos( $gameId, 'samp' ) !== FALSE OR strpos( $gameId, 'mta' ) !== FALSE OR strpos( $gameId, 'gta' ) !== FALSE OR strpos( $gameId, 'fivem' ) !== FALSE )
		{
			return 'fa-solid fa-car-side';
		}

		if ( strpos( $gameId, 'arma' ) !== FALSE )
		{
			return 'fa-solid fa-plane';
		}

		return 'fa-solid fa-gamepad';
	}

	/**
	 * Return profile mapped to server game id
	 *
	 * @param	array	$server
	 * @return	array
	 */
	protected function gameProfileForServer( array $server ): array
	{
		static $profiles = NULL;

		if ( $profiles === NULL )
		{
			$profiles = ( new GameProfiles )->all();
		}

		$gameId = strtolower( trim( (string) ( $server['game_id'] ?? '' ) ) );
		if ( $gameId === '' )
		{
			return array();
		}

		return $profiles[ $gameId ] ?? array();
	}

	/**
	 * Resolve owner member name
	 *
	 * @param	array	$server
	 * @return	string
	 */
	protected function ownerName( array $server ): string
	{
		$ownerId = (int) ( $server['owner_member_id'] ?? 0 );
		if ( !$ownerId )
		{
			return '';
		}

		try
		{
			$owner = Member::load( $ownerId );
			return $owner->member_id ? (string) $owner->name : '';
		}
		catch ( \Throwable )
		{
			return '';
		}
	}

	/**
	 * Extract map value from server payload
	 *
	 * @param	array	$server
	 * @return	string
	 */
	protected function extractMapFromServer( array $server ): string
	{
		if ( empty( $server['status_json'] ) )
		{
			return '';
		}

		try
		{
			$runtime = json_decode( (string) $server['status_json'], TRUE, 512, JSON_THROW_ON_ERROR );
		}
		catch ( \Throwable )
		{
			return '';
		}

		if ( !is_array( $runtime ) )
		{
			return '';
		}

		return $this->extractMap( $runtime );
	}

	/**
	 * Extract map value from runtime payload
	 *
	 * @param	array	$runtime
	 * @return	string
	 */
	protected function extractMap( array $runtime ): string
	{
		foreach ( array( 'map', 'mapname', 'map_name', 'current_map', 'level', 'mapTitle' ) as $key )
		{
			if ( isset( $runtime[ $key ] ) )
			{
				$value = trim( (string) $runtime[ $key ] );
				if ( $value !== '' )
				{
					return $value;
				}
			}
		}

		if ( isset( $runtime['_updater'] ) AND is_array( $runtime['_updater'] ) )
		{
			foreach ( array( 'map', 'mapname', 'map_name', 'current_map', 'level', 'mapTitle' ) as $key )
			{
				if ( isset( $runtime['_updater'][ $key ] ) )
				{
					$value = trim( (string) $runtime['_updater'][ $key ] );
					if ( $value !== '' )
					{
						return $value;
					}
				}
			}
		}

		return '';
	}

	/**
	 * Render server icon HTML
	 *
	 * @param	array	$server
	 * @param	string	$size
	 * @return	string
	 */
	protected function serverIconHtml( array $server, string $size='small' ): string
	{
		$iconType = trim( (string) ( $server['icon_type'] ?? '' ) );
		$iconValue = trim( (string) ( $server['icon_value'] ?? '' ) );
		$profile = $this->gameProfileForServer( $server );
		$profileIconType = trim( (string) ( $profile['icon_type'] ?? '' ) );
		$profileIconValue = trim( (string) ( $profile['icon_value'] ?? '' ) );
		$class = ( $size === 'large' ) ? 'fa-lg' : '';

		if ( $iconType === 'upload' AND $iconValue !== '' )
		{
			try
			{
				$file = File::get( 'gameservers_Icons', $iconValue );
				$style = ( $size === 'large' ) ? 'width:24px;height:24px;object-fit:cover;border-radius:4px;' : 'width:18px;height:18px;object-fit:cover;border-radius:4px;';
				return "<img src='" . $this->escape( (string) $file->url ) . "' alt='' style='{$style}'>";
			}
			catch ( \Throwable )
			{
			}
		}

		if ( $iconType === 'preset' AND $iconValue !== '' )
		{
			$iconClasses = $this->resolvePresetIconClasses( $iconValue );
			if ( $iconClasses !== '' )
			{
				if ( strtolower( $iconValue ) !== 'server' OR $profileIconType === '' )
				{
					return "<span class='ipsType_light'><i class='" . trim( $iconClasses . ' ' . $class ) . "' aria-hidden='true'></i></span>";
				}
			}
		}

		if ( $profileIconType === 'upload' AND $profileIconValue !== '' )
		{
			try
			{
				$file = File::get( 'gameservers_Icons', $profileIconValue );
				$style = ( $size === 'large' ) ? 'width:24px;height:24px;object-fit:cover;border-radius:4px;' : 'width:18px;height:18px;object-fit:cover;border-radius:4px;';
				return "<img src='" . $this->escape( (string) $file->url ) . "' alt='' style='{$style}'>";
			}
			catch ( \Throwable )
			{
			}
		}

		if ( $profileIconType === 'preset' AND $profileIconValue !== '' )
		{
			$profileClasses = $this->resolvePresetIconClasses( $profileIconValue );
			if ( $profileClasses !== '' )
			{
				return "<span class='ipsType_light'><i class='" . trim( $profileClasses . ' ' . $class ) . "' aria-hidden='true'></i></span>";
			}
		}

		return "<span class='ipsType_light'><i class='fa-solid fa-server {$class}' aria-hidden='true'></i></span>";
	}

	/**
	 * Resolve built-in or custom Font Awesome classes
	 *
	 * @param	string	$iconValue
	 * @return	string
	 */
	protected function resolvePresetIconClasses( string $iconValue ): string
	{
		$map = array(
			'server' => 'fa-solid fa-server',
			'gamepad' => 'fa-solid fa-gamepad',
			'crosshairs' => 'fa-solid fa-crosshairs',
			'shield' => 'fa-solid fa-shield-halved',
			'trophy' => 'fa-solid fa-trophy',
		);

		if ( isset( $map[ $iconValue ] ) )
		{
			return $map[ $iconValue ];
		}

		$classes = (string) preg_replace( '/[^A-Za-z0-9\-\s]/', '', $iconValue );
		$classes = trim( (string) preg_replace( '/\s+/', ' ', $classes ) );

		if ( $classes === '' OR !preg_match( '/\bfa-[a-z0-9-]+\b/i', $classes ) )
		{
			return '';
		}

		if ( !preg_match( '/\bfa-(solid|regular|brands|light|duotone|thin|sharp)\b/i', $classes ) )
		{
			$classes = 'fa-solid ' . $classes;
		}

		return $classes;
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
