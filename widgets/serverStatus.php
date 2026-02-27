<?php
/**
 * @brief		serverStatus Widget
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community
 * @subpackage	Game Servers
 * @since		26 Feb 2026
 */

namespace IPS\gameservers\widgets;

use IPS\Application;
use IPS\Db;
use IPS\File;
use IPS\forums\Forum;
use IPS\gameservers\GameProfiles;
use IPS\Helpers\Form;
use IPS\Helpers\Form\Number;
use IPS\Helpers\Form\YesNo;
use IPS\Http\Url;
use IPS\Member;
use IPS\Theme;
use IPS\Widget;
use function array_key_exists;
use function count;
use function defined;
use function explode;
use function htmlspecialchars;
use function implode;
use function iterator_to_array;
use function is_array;
use function is_scalar;
use function json_decode;
use function max;
use function min;
use function preg_match;
use function preg_replace;
use function preg_split;
use function rawurlencode;
use function strpos;
use function str_replace;
use function strnatcasecmp;
use function strtolower;
use function substr;
use function usort;
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
 * serverStatus Widget
 */
class serverStatus extends Widget
{
	/**
	 * @brief	Widget Key
	 */
	public string $key = 'serverStatus';

	/**
	 * @brief	App
	 */
	public string $app = 'gameservers';

	/**
	 * @brief	Allow block to be reused
	 */
	public bool $allowReuse = TRUE;

	/**
	 * Return extra wrapper classes for this widget
	 *
	 * @return	array
	 */
	public function getWrapperClasses(): array
	{
		$classes = parent::getWrapperClasses();
		$classes[] = 'ipsWidget--transparent';

		return $classes;
	}

	/**
	 * Initialize this widget
	 *
	 * @return	void
	 */
	public function init(): void
	{
		$this->template( array( $this, 'templateOutput' ) );
		parent::init();
	}

	/**
	 * Specify widget configuration
	 *
	 * @param	Form|null	$form	Form object
	 * @return	Form
	 */
	public function configuration( Form &$form=NULL ): Form
	{
		$form = parent::configuration( $form );
		$form->add( new Number( 'gq_widget_limit', $this->configuration['gq_widget_limit'] ?? 5, TRUE, array( 'min' => 1, 'max' => 50 ) ) );
		$form->add( new YesNo( 'gq_widget_show_offline', $this->configuration['gq_widget_show_offline'] ?? TRUE ) );
		$form->add( new YesNo( 'gq_widget_show_owner_avatars', $this->configuration['gq_widget_show_owner_avatars'] ?? TRUE ) );
		$form->add( new YesNo( 'gq_widget_show_owner_names', $this->configuration['gq_widget_show_owner_names'] ?? TRUE ) );
		$form->add( new YesNo( 'gq_widget_two_columns', $this->configuration['gq_widget_two_columns'] ?? FALSE ) );
		$form->add( new YesNo( 'gq_widget_enable_tabs', $this->configuration['gq_widget_enable_tabs'] ?? FALSE ) );

		return $form;
	}

	/**
	 * Render a widget
	 *
	 * @return	string
	 */
	public function render(): string
	{
		$limit = max( 1, min( 50, (int) ( $this->configuration['gq_widget_limit'] ?? 5 ) ) );
		$showOffline = (bool) ( $this->configuration['gq_widget_show_offline'] ?? TRUE );
		$enableTabs = (bool) ( $this->configuration['gq_widget_enable_tabs'] ?? FALSE );

		$where = array( array( 'enabled=?', 1 ) );
		if ( !$showOffline )
		{
			$where[] = array( 'online=?', 1 );
		}

		$orderBy = Db::i()->checkForColumn( 'gameservers_servers', 'position' ) ? 'position ASC, name ASC' : 'online DESC, players_online DESC, name ASC';
		if ( $enableTabs )
		{
			$servers = iterator_to_array( Db::i()->select( '*', 'gameservers_servers', $where, $orderBy ) );
		}
		else
		{
			$servers = iterator_to_array( Db::i()->select( '*', 'gameservers_servers', $where, $orderBy, array( 0, $limit ) ) );
		}

		return $this->output( $servers );
	}

	/**
	 * Widget output callback
	 *
	 * @param	array	$servers
	 * @param	string	$layout
	 * @param	bool	$isCarousel
	 * @return	string
	 */
	public function templateOutput( array $servers, string $layout='table', bool $isCarousel=FALSE ): string
	{
		if ( !count( $servers ) )
		{
			return "<div><div class='ipsType_light ipsType_medium'>" . $this->escape( Member::loggedIn()->language()->addToStack( 'gq_no_servers' ) ) . "</div></div>";
		}

		$limit = max( 1, min( 50, (int) ( $this->configuration['gq_widget_limit'] ?? 5 ) ) );
		$showOwnerAvatars = (bool) ( $this->configuration['gq_widget_show_owner_avatars'] ?? TRUE );
		$showOwnerNames = (bool) ( $this->configuration['gq_widget_show_owner_names'] ?? TRUE );
		$showOwnerColumn = ( $showOwnerAvatars OR $showOwnerNames );
		$twoColumns = (bool) ( $this->configuration['gq_widget_two_columns'] ?? FALSE );
		$enableTabs = (bool) ( $this->configuration['gq_widget_enable_tabs'] ?? FALSE );
		$listClasses = $twoColumns ? 'ipsList_reset gqWidgetList gqWidgetList--twoCol' : 'ipsList_reset gqWidgetList';
		$showVoteColumn = FALSE;
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

			if ( count( $this->parseVoteLinks( (string) ( $server['vote_links'] ?? '' ) ) ) )
			{
				$showVoteColumn = TRUE;
			}
		}

		if ( count( $gameTabs ) > 1 )
		{
			uasort( $gameTabs, function( $left, $right )
			{
				return strnatcasecmp( (string) ( $left['label'] ?? '' ), (string) ( $right['label'] ?? '' ) );
			} );
		}

		$showTabs = ( $enableTabs AND count( $gameTabs ) > 1 );

		if ( $showOwnerColumn AND $showVoteColumn )
		{
			$rowColumns = 'minmax(0,2.2fr) minmax(0,1.35fr) minmax(0,1fr) minmax(0,0.9fr) minmax(0,1.15fr)';
		}
		elseif ( $showOwnerColumn )
		{
			$rowColumns = 'minmax(0,2.2fr) minmax(0,1.35fr) minmax(0,1fr) minmax(0,1.15fr)';
		}
		elseif ( $showVoteColumn )
		{
			$rowColumns = 'minmax(0,2.2fr) minmax(0,1.35fr) minmax(0,1fr) minmax(0,0.9fr)';
		}
		else
		{
			$rowColumns = 'minmax(0,2.2fr) minmax(0,1.35fr) minmax(0,1fr)';
		}

		$html = "";
		$html .= "<style>";
		$html .= ".gqWidgetRoot{background:transparent;}";
		$html .= ".gqWidgetTabs{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 8px;padding:0;}";
		$html .= ".gqWidgetTab{display:inline-flex;align-items:center;justify-content:center;min-width:16px;height:16px;border:0;background:none;padding:0;color:inherit;opacity:.68;cursor:pointer;transition:opacity .12s ease,transform .12s ease;}";
		$html .= ".gqWidgetTab:hover{opacity:1;transform:translateY(-1px);}";
		$html .= ".gqWidgetTab:focus-visible{outline:2px solid var(--ips-border--strong,#b8b8b8);outline-offset:2px;border-radius:3px;}";
		$html .= ".gqWidgetTab.is-active{opacity:1;}";
		$html .= ".gqWidgetTabIcon{display:inline-flex;align-items:center;justify-content:center;line-height:1;font-size:14px;}";
		$html .= ".gqWidgetTabIcon i{font-size:14px;line-height:1;}";
		$html .= ".gqWidgetTabIcon img{display:block;width:14px;height:14px;object-fit:cover;border-radius:3px;}";
		$html .= ".gqWidgetMoreWrap{display:flex;justify-content:center;margin-top:8px;}";
		$html .= ".gqWidgetList{list-style:none;margin:0;padding:0;}";
		$html .= ".gqWidgetList--twoCol{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));column-gap:8px;row-gap:6px;}";
		$html .= "@media (max-width:900px){.gqWidgetList--twoCol{grid-template-columns:minmax(0,1fr);}}";
		$html .= ".gqWidgetServerRow{display:grid;grid-template-columns:{$rowColumns};gap:8px;align-items:center;}";
		$html .= ".gqWidgetName{min-width:0;}";
		$html .= ".gqWidgetIp{min-width:0;}";
		$html .= ".gqWidgetIpGame{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:2px;}";
		$html .= ".gqWidgetIpWrap{display:flex;align-items:center;gap:6px;min-width:0;}";
		$html .= ".gqWidgetIpText{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}";
		$html .= ".gqWidgetCopyIp{border:0;background:transparent;padding:0;color:inherit;cursor:pointer;line-height:1;opacity:.7;}";
		$html .= ".gqWidgetCopyIp:hover{opacity:1;}";
		$html .= ".gqWidgetCopyIp.is-copied{color:#2bbf6a;opacity:1;}";
		$html .= ".gqWidgetConnect{display:inline-flex;align-items:center;gap:4px;padding:1px 7px;border:1px solid var(--ips-border--card,#d8d8d8);border-radius:999px;font-size:11px;line-height:1.35;text-decoration:none;color:inherit;white-space:nowrap;}";
		$html .= ".gqWidgetConnect:hover{color:inherit;border-color:var(--ips-border--strong,#b8b8b8);}";
		$html .= ".gqWidgetList--twoCol .gqWidgetConnect{border:0;background:none;padding:0;width:auto;height:auto;justify-content:flex-start;gap:0;line-height:1;opacity:.7;border-radius:0;box-shadow:none;}";
		$html .= ".gqWidgetList--twoCol .gqWidgetConnect:hover{border:0;opacity:1;color:inherit;}";
		$html .= ".gqWidgetList--twoCol .gqWidgetConnect span{display:none !important;}";
		$html .= ".gqWidgetVote{display:inline-flex;align-items:center;gap:4px;padding:1px 7px;border:1px solid var(--ips-border--card,#d8d8d8);border-radius:999px;font-size:11px;line-height:1.35;text-decoration:none;color:inherit;white-space:nowrap;}";
		$html .= ".gqWidgetVote:hover{color:inherit;border-color:var(--ips-border--strong,#b8b8b8);}";
		$html .= ".gqWidgetPlayersMain{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}";
		$html .= ".gqWidgetPlayersMap{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:inherit;line-height:1.25;opacity:.85;margin-top:2px;}";
		$html .= ".gqWidgetPlayers{display:flex;flex-direction:column;align-items:flex-end;text-align:right;}";
		$html .= ".gqWidgetPlayers--withMap{justify-content:flex-start;}";
		$html .= ".gqWidgetPlayers--single{justify-content:center;}";
		$html .= ".gqWidgetVoteCol{text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}";
		$html .= ".gqWidgetStatusDot{display:inline-block;width:8px;height:8px;border-radius:50%;flex:0 0 auto;}";
		$html .= ".gqWidgetStatusDot--online{background:#2bbf6a;box-shadow:0 0 0 0 rgba(43,191,106,.52);animation:gqWidgetPulseOnline 1.7s ease-out infinite;}";
		$html .= ".gqWidgetStatusDot--offline{background:#d64545;box-shadow:0 0 0 0 rgba(214,69,69,.5);animation:gqWidgetPulseOffline 1.7s ease-out infinite;}";
		$html .= ".gqWidgetStatusDot--unknown{background:#9ca3af;}";
		$html .= "@keyframes gqWidgetPulseOnline{0%{box-shadow:0 0 0 0 rgba(43,191,106,.52);}70%{box-shadow:0 0 0 6px rgba(43,191,106,0);}100%{box-shadow:0 0 0 0 rgba(43,191,106,0);}}";
		$html .= "@keyframes gqWidgetPulseOffline{0%{box-shadow:0 0 0 0 rgba(214,69,69,.5);}70%{box-shadow:0 0 0 6px rgba(214,69,69,0);}100%{box-shadow:0 0 0 0 rgba(214,69,69,0);}}";
		$html .= ".gqWidgetForum{display:flex;align-items:center;gap:6px;white-space:nowrap;margin-top:2px;font-size:11px;line-height:1.1;}";
		$html .= ".gqWidgetForumLink{display:inline-flex;align-items:center;justify-content:center;line-height:1;font-size:1em;}";
		$html .= ".gqWidgetForumLink i{font-size:1em;line-height:1;}";
		$html .= ".gqWidgetItem{}";
		$html .= ".gqWidgetListItem.i-background_2.i-border-radius_box.i-padding_2.i-margin-bottom_1{padding:3px 5px 3px 5px !important;}";
		$html .= ".gqWidgetServerIcon{display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;line-height:1;font-size:inherit;}";
		$html .= ".gqWidgetServerIcon i{font-size:1em;line-height:1;}";
		$html .= ".gqWidgetServerIcon img{display:block;width:1em;height:1em;object-fit:cover;border-radius:4px;}";
		$html .= ".gqWidgetOwner{text-align:right;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}";
		$html .= ".gqWidgetCompactMeta{display:none;}";
		$html .= ".gqWidgetCompactMetaInner{display:flex;align-items:center;justify-content:flex-start;gap:6px;}";
		$html .= ".gqWidgetCompactMetaInline{display:none;align-items:center;gap:6px;flex:0 0 auto;}";
		$html .= ".gqWidgetCompactUnder{display:none;align-items:center;gap:5px;min-width:0;}";
		$html .= ".gqWidgetCompactIp{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0;max-width:150px;font-size:11px;line-height:1.1;}";
		$html .= ".gqWidgetCompactUnder .gqWidgetCopyIp,.gqWidgetCompactUnder .gqWidgetConnect,.gqWidgetCompactUnder .gqWidgetVote,.gqWidgetCompactMetaInline .gqWidgetVote{font-size:11px;line-height:1.1;}";
		$html .= ".gqWidgetCompactOwner .gqWidgetOwnerName{display:none;}";
		$html .= ".gqWidgetCompactOwner .ipsUserPhoto{width:13px;height:13px;}";
		$html .= ".gqWidgetCompactOwner .ipsUserPhoto img{width:13px;height:13px;}";
		$html .= ".gqWidgetOwnerInner{display:inline-flex;align-items:center;gap:6px;max-width:100%;}";
		$html .= ".gqWidgetOwnerName{min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}";
		$html .= ".gqWidgetOwner .ipsUserPhoto{flex:0 0 auto;border-radius:50%;overflow:hidden;}";
		$html .= ".gqWidgetOwner .ipsUserPhoto img{border-radius:50%;}";
		$html .= "@container (max-width:380px){.gqWidgetListItem{padding:2px 5px 2px 5px !important;margin-bottom:3px !important;}.gqWidgetServerRow{grid-template-columns:minmax(0,1fr) auto;grid-template-rows:auto;row-gap:0;column-gap:8px;align-items:center;}.gqWidgetName{grid-column:1;grid-row:1;min-height:0;display:block;}.gqWidgetName>.i-flex{width:100%;min-width:0;}.gqWidgetForum{display:none;}.gqWidgetCompactUnder{display:flex;}.gqWidgetPlayers{grid-column:2;grid-row:1;min-height:0;display:flex;flex-direction:column;align-items:flex-end;justify-content:center;gap:0;text-align:right;}.gqWidgetPlayersMain{line-height:1.05;}.gqWidgetPlayersMap{margin-top:1px;font-size:11px;line-height:1.1;max-width:88px;}.gqWidgetIp{display:none !important;}.gqWidgetIpGame{display:none;}.gqWidgetCompactMeta{display:none !important;}.gqWidgetCompactMetaInline{display:inline-flex;}.gqWidgetVoteCol,.gqWidgetOwner{display:none !important;}.gqWidgetVote{border:0 !important;background:none !important;padding:0 !important;line-height:1;}.gqWidgetVote span{display:none !important;}.gqWidgetConnect{border:0 !important;background:none !important;padding:0 !important;width:auto !important;height:auto !important;justify-content:flex-start !important;gap:0 !important;line-height:1 !important;opacity:.7;border-radius:0 !important;box-shadow:none !important;}.gqWidgetConnect:hover{border:0 !important;opacity:1;}.gqWidgetConnect span{display:none !important;}}";
		$html .= "@media (max-width:420px){.gqWidgetListItem{padding:2px 5px 2px 5px !important;margin-bottom:3px !important;}.gqWidgetServerRow{grid-template-columns:minmax(0,1fr) auto;grid-template-rows:auto;row-gap:0;column-gap:8px;align-items:center;}.gqWidgetName{grid-column:1;grid-row:1;min-height:0;display:block;}.gqWidgetName>.i-flex{width:100%;min-width:0;}.gqWidgetForum{display:none;}.gqWidgetCompactUnder{display:flex;}.gqWidgetPlayers{grid-column:2;grid-row:1;min-height:0;display:flex;flex-direction:column;align-items:flex-end;justify-content:center;gap:0;text-align:right;}.gqWidgetPlayersMain{line-height:1.05;}.gqWidgetPlayersMap{margin-top:1px;font-size:11px;line-height:1.1;max-width:88px;}.gqWidgetIp{display:none !important;}.gqWidgetIpGame{display:none;}.gqWidgetCompactMeta{display:none !important;}.gqWidgetCompactMetaInline{display:inline-flex;}.gqWidgetVoteCol,.gqWidgetOwner{display:none !important;}.gqWidgetVote{border:0 !important;background:none !important;padding:0 !important;line-height:1;}.gqWidgetVote span{display:none !important;}.gqWidgetConnect{border:0 !important;background:none !important;padding:0 !important;width:auto !important;height:auto !important;justify-content:flex-start !important;gap:0 !important;line-height:1 !important;opacity:.7;border-radius:0 !important;box-shadow:none !important;}.gqWidgetConnect:hover{border:0 !important;opacity:1;}.gqWidgetConnect span{display:none !important;}}";
		$html .= "</style>";
		$html .= "<div class='gqWidgetRoot' data-gq-limit='{$limit}'>";

		if ( $showTabs )
		{
			$allLabel = $this->escape( Member::loggedIn()->language()->addToStack( 'gq_widget_tab_all' ) );
			$allIcon = "<span class='gqWidgetTabIcon'><i class='fa-solid fa-list' aria-hidden='true'></i></span>";
			$html .= "<div class='gqWidgetTabs' role='tablist' aria-label='" . $this->escape( Member::loggedIn()->language()->addToStack( 'block_serverStatus' ) ) . "'>";
			$html .= "<button type='button' class='gqWidgetTab is-active' data-gq-tab='all' role='tab' aria-selected='true' data-ipsTooltip title='{$allLabel}' aria-label='{$allLabel}'>{$allIcon}<span class='ipsHide'>{$allLabel}</span></button>";

			foreach ( $gameTabs as $tabKey => $tabData )
			{
				$tabLabel = $this->escape( (string) ( $tabData['label'] ?? '' ) );
				$tabIcon = (string) ( $tabData['icon'] ?? "<span class='gqWidgetTabIcon'><i class='fa-solid fa-server' aria-hidden='true'></i></span>" );
				$html .= "<button type='button' class='gqWidgetTab' data-gq-tab='" . $this->escape( (string) $tabKey ) . "' role='tab' aria-selected='false' data-ipsTooltip title='{$tabLabel}' aria-label='{$tabLabel}'>{$tabIcon}<span class='ipsHide'>{$tabLabel}</span></button>";
			}

			$html .= "</div>";
		}

		$html .= "<ul class='{$listClasses}' style='container-type:inline-size;background:transparent;'>";

		$overallIndex = 0;
		$gameIndexes = array();

		foreach ( $servers as $server )
		{
			$tabKeyRaw = $this->gameTabKey( $server );
			$overallIndex++;
			if ( !isset( $gameIndexes[ $tabKeyRaw ] ) )
			{
				$gameIndexes[ $tabKeyRaw ] = 0;
			}
			$gameIndexes[ $tabKeyRaw ]++;

			$allIndex = (int) $overallIndex;
			$gameIndex = (int) $gameIndexes[ $tabKeyRaw ];
			$rowVisible = ( $allIndex <= $limit );
			$rowStyle = $rowVisible ? '' : " style='display:none;'";

			$statusDotClass = 'gqWidgetStatusDot--unknown';
			$statusLabel = Member::loggedIn()->language()->addToStack( 'gq_status_unknown' );

			if ( $server['online'] === NULL )
			{
				$statusDotClass = 'gqWidgetStatusDot--unknown';
				$statusLabel = Member::loggedIn()->language()->addToStack( 'gq_status_unknown' );
			}
			elseif ( (int) $server['online'] === 1 )
			{
				$statusDotClass = 'gqWidgetStatusDot--online';
				$statusLabel = Member::loggedIn()->language()->addToStack( 'gq_status_online' );
			}
			else
			{
				$statusDotClass = 'gqWidgetStatusDot--offline';
				$statusLabel = Member::loggedIn()->language()->addToStack( 'gq_status_offline' );
			}

			$playersText = Member::loggedIn()->language()->addToStack( 'gq_status_unknown' );
			if ( (int) $server['online'] === 1 )
			{
				if ( $server['players_online'] !== NULL AND $server['players_max'] !== NULL )
				{
					$playersText = (int) $server['players_online'] . '/' . (int) $server['players_max'];
				}
				elseif ( $server['players_online'] !== NULL )
				{
					$playersText = (string) (int) $server['players_online'];
				}
			}
			elseif ( (int) $server['online'] === 0 )
			{
				$playersText = Member::loggedIn()->language()->addToStack( 'gq_status_offline' );
			}

			$url = $this->escape( $this->detailUrl( $server ) );
			$game = $this->escape( $this->gameDisplay( $server ) );
			$statusTitle = $this->escape( $statusLabel );
			$map = $this->extractMapLabel( $server );
			$icon = $this->serverIconHtml( $server );
			$addressEsc = $this->escape( (string) $server['address'] );
			$gameFilterKey = $this->escape( $tabKeyRaw );
			$hasMap = ( $map !== '' );
			$playersMapHtml = $hasMap ? "<div class='gqWidgetPlayersMap'>" . $this->escape( $map ) . "</div>" : '';
			$playersClass = $hasMap ? 'gqWidgetPlayers gqWidgetPlayers--withMap' : 'gqWidgetPlayers gqWidgetPlayers--single';
			$playersCell = $this->escape( $playersText );
			$copyLabel = $this->escape( Member::loggedIn()->language()->addToStack( 'gq_widget_copy_ip' ) );
			$connectLabel = $this->escape( Member::loggedIn()->language()->addToStack( 'gq_widget_connect' ) );
			$voteLabel = $this->escape( Member::loggedIn()->language()->addToStack( 'gq_widget_vote' ) );
			$discordLabel = $this->escape( Member::loggedIn()->language()->addToStack( 'gq_widget_discord' ) );
			$ts3Label = $this->escape( Member::loggedIn()->language()->addToStack( 'gq_widget_ts3' ) );
			$copyAction = $this->escape( "var b=this;var v=b.getAttribute('data-copy');if(window.navigator&&navigator.clipboard&&v){navigator.clipboard.writeText(v);b.classList.add('is-copied');setTimeout(function(){b.classList.remove('is-copied');},1200);}return false;" );
			$connectUrl = $this->connectUrl( $server );
			$connectButton = '';
			if ( $connectUrl !== '' )
			{
				$connectButton = "<a href='" . $this->escape( $connectUrl ) . "' class='gqWidgetConnect' data-ipsTooltip title='{$connectLabel}' aria-label='{$connectLabel}'><i class='fa-solid fa-plug' aria-hidden='true'></i><span>{$connectLabel}</span></a>";
			}
			$voteLinks = $this->parseVoteLinks( (string) ( $server['vote_links'] ?? '' ) );
			$voteButton = '';
			if ( count( $voteLinks ) === 1 )
			{
				$voteButton = "<a href='" . $this->escape( $voteLinks[0]['url'] ) . "' class='gqWidgetVote' data-ipsTooltip title='{$voteLabel}' aria-label='{$voteLabel}' target='_blank' rel='noopener noreferrer'><i class='fa-solid fa-square-check' aria-hidden='true'></i><span>{$voteLabel}</span></a>";
			}
			elseif ( count( $voteLinks ) > 1 )
			{
				$votesUrl = $this->escape( $this->voteModalUrl( $server ) );
				$voteTitle = $this->escape( Member::loggedIn()->language()->addToStack( 'gq_server_vote_sites' ) );
				$voteButton = "<a href='{$votesUrl}' class='gqWidgetVote' data-ipsDialog data-ipsDialog-title='{$voteTitle}' data-ipsDialog-size='medium' data-ipsTooltip title='{$voteLabel}' aria-label='{$voteLabel}'><i class='fa-solid fa-square-check' aria-hidden='true'></i><span>{$voteLabel}</span></a>";
			}
			$voteCell = ( $voteButton !== '' ) ? $voteButton : "<span class='ipsType_light'>-</span>";
			$discordUrl = $this->discordUrl( $server );
			$discordIcon = '';
			if ( $discordUrl !== '' )
			{
				$discordIcon = "<a href='" . $this->escape( $discordUrl ) . "' class='gqWidgetForumLink ipsType_light' data-ipsTooltip title='{$discordLabel}' aria-label='{$discordLabel}' target='_blank' rel='noopener noreferrer'><i class='fa-brands fa-discord' aria-hidden='true'></i><span class='ipsHide'>{$discordLabel}</span></a>";
			}
			$ts3Url = $this->ts3Url( $server );
			$ts3Icon = '';
			if ( $ts3Url !== '' )
			{
				$ts3Icon = "<a href='" . $this->escape( $ts3Url ) . "' class='gqWidgetForumLink ipsType_light' data-ipsTooltip title='{$ts3Label}' aria-label='{$ts3Label}' target='_blank' rel='noopener noreferrer'><i class='fa-solid fa-headset' aria-hidden='true'></i><span class='ipsHide'>{$ts3Label}</span></a>";
			}
			$ownerCell = '';
			if ( $showOwnerColumn )
			{
				$ownerLink = $this->ownerLinkHtml( $server, $showOwnerAvatars, $showOwnerNames );
				$ownerCell = ( $ownerLink !== '' ) ? $ownerLink : '-';
			}
			$ownerCompact = '';
			if ( $showOwnerColumn )
			{
				$ownerCompactLink = $this->ownerLinkHtml( $server, $showOwnerAvatars, ( !$showOwnerAvatars AND $showOwnerNames ) );
				if ( $ownerCompactLink !== '' )
				{
					$ownerCompact = "<span class='gqWidgetCompactOwner'>{$ownerCompactLink}</span>";
				}
			}

			$compactParts = array();
			if ( $ownerCompact !== '' )
			{
				$compactParts[] = $ownerCompact;
			}

			if ( $voteButton !== '' )
			{
				$compactParts[] = $voteButton;
			}
			elseif ( $showVoteColumn )
			{
				$compactParts[] = "<span class='ipsType_light'>-</span>";
			}

			$compactMetaInline = count( $compactParts ) ? "<span class='gqWidgetCompactMetaInline'>" . implode( '', $compactParts ) . "</span>" : '';
			$compactMeta = count( $compactParts ) ? implode( '', $compactParts ) : "<span class='ipsType_light'>-</span>";
			$forumUrl = $this->forumCategoryUrl( $server );
			$forumTitle = $this->escape( Member::loggedIn()->language()->addToStack( 'gq_server_forum_view' ) );

			$html .= "<li class='gqWidgetListItem i-background_2 i-border-radius_box i-padding_2 i-margin-bottom_1' data-gq-game='{$gameFilterKey}' data-gq-all-index='{$allIndex}' data-gq-game-index='{$gameIndex}'{$rowStyle}>";
			$html .= "<div class='gqWidgetServerRow'>";
			$html .= "<div class='gqWidgetName'>";
			$html .= "<div class='i-flex i-align-items_center i-gap_1' style='min-width:0;'>";
			$html .= "<span class='gqWidgetStatusDot {$statusDotClass}' data-ipsTooltip title='{$statusTitle}' aria-label='{$statusTitle}'></span><span class='ipsHide'>{$statusTitle}</span>";
			$html .= $icon;
			$html .= "<a href='{$url}' class='ipsType_blendLinks i-flex i-align-items_center' style='min-width:0;'><strong class='ipsType_small i-display_block' style='min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;'>" . $this->escape( (string) $server['name'] ) . "</strong></a>";
			$html .= "</div>";
			if ( $forumUrl !== '' OR $discordIcon !== '' OR $ts3Icon !== '' )
			{
				$html .= "<div class='ipsType_light ipsType_small gqWidgetForum'>";
				if ( $forumUrl !== '' )
				{
					$html .= "<a href='" . $this->escape( $forumUrl ) . "' class='gqWidgetForumLink ipsType_light' data-ipsTooltip title='{$forumTitle}' aria-label='{$forumTitle}'><i class='fa-solid fa-folder-open' aria-hidden='true'></i><span class='ipsHide'>{$forumTitle}</span></a>";
				}
				$html .= $discordIcon;
				$html .= $ts3Icon;
				$html .= "</div>";
			}
			$html .= "<div class='gqWidgetCompactUnder'>{$compactMetaInline}<span class='gqWidgetCompactIp'>{$addressEsc}</span><button type='button' class='gqWidgetCopyIp' data-copy='{$addressEsc}' data-ipsTooltip title='{$copyLabel}' aria-label='{$copyLabel}' onclick=\"{$copyAction}\"><i class='fa-regular fa-copy' aria-hidden='true'></i><span class='ipsHide'>{$copyLabel}</span></button>{$connectButton}</div>";
			$html .= "</div>";
			$html .= "<div class='ipsType_light ipsType_small gqWidgetIp'><div class='gqWidgetIpGame'>{$game}</div><div class='gqWidgetIpWrap'><span class='gqWidgetIpText'>{$addressEsc}</span><button type='button' class='gqWidgetCopyIp' data-copy='{$addressEsc}' data-ipsTooltip title='{$copyLabel}' aria-label='{$copyLabel}' onclick=\"{$copyAction}\"><i class='fa-regular fa-copy' aria-hidden='true'></i><span class='ipsHide'>{$copyLabel}</span></button>{$connectButton}</div></div>";
			$html .= "<div class='ipsType_light ipsType_small {$playersClass}'><div class='gqWidgetPlayersMain'>{$playersCell}</div>{$playersMapHtml}</div>";
			$html .= "<div class='ipsType_light ipsType_small gqWidgetCompactMeta'><div class='gqWidgetCompactMetaInner'>{$compactMeta}</div></div>";
			if ( $showVoteColumn )
			{
				$html .= "<div class='ipsType_light ipsType_small gqWidgetVoteCol'>{$voteCell}</div>";
			}
			if ( $showOwnerColumn )
			{
				$html .= "<div class='ipsType_light ipsType_small gqWidgetOwner'>{$ownerCell}</div>";
			}
			$html .= "</div>";
			$html .= "</li>";
		}

		$html .= "</ul>";
		$html .= "<div class='gqWidgetMoreWrap'><a href='" . $this->escape( $this->listingUrl() ) . "' class='ipsButton ipsButton--veryLight ipsButton--tiny'>+ more</a></div>";
		$html .= "</div>";

		if ( $showTabs )
		{
			$html .= "<script>(function(){var script=document.currentScript;if(!script){return;}var root=script.previousElementSibling;if(!root||root.className.indexOf('gqWidgetRoot')===-1){return;}var tabs=root.querySelectorAll('.gqWidgetTab[data-gq-tab]');var rows=root.querySelectorAll('[data-gq-game]');if(!tabs.length||!rows.length){return;}var limit=parseInt(root.getAttribute('data-gq-limit')||'5',10);if(!limit||limit<1){limit=5;}var setTab=function(key){for(var i=0;i<tabs.length;i++){var tab=tabs[i];var active=tab.getAttribute('data-gq-tab')===key;tab.classList.toggle('is-active',active);tab.setAttribute('aria-selected',active?'true':'false');}for(var j=0;j<rows.length;j++){var row=rows[j];var matches=(key==='all'||row.getAttribute('data-gq-game')===key);if(!matches){row.style.display='none';continue;}var rank=(key==='all')?parseInt(row.getAttribute('data-gq-all-index')||'0',10):parseInt(row.getAttribute('data-gq-game-index')||'0',10);row.style.display=(rank>0&&rank<=limit)?'':'none';}};for(var k=0;k<tabs.length;k++){tabs[k].addEventListener('click',function(ev){ev.preventDefault();setTab(this.getAttribute('data-gq-tab'));});}})();</script>";
		}

		return $html;
	}

	/**
	 * Escape HTML
	 *
	 * @param	string	$value
	 * @return	string
	 */
	protected function escape( string $value ): string
	{
		return htmlspecialchars( $value, ENT_QUOTES | ENT_DISALLOWED, 'UTF-8' );
	}

	/**
	 * Build server details URL
	 *
	 * @param	array	$server
	 * @return	string
	 */
	protected function detailUrl( array $server ): string
	{
		$queryString = 'app=gameservers&module=servers&controller=servers&do=view&game=' . rawurlencode( (string) $server['game_id'] ) . '&address=' . rawurlencode( (string) $server['address'] );

		try
		{
			return (string) Url::internal( $queryString, 'front', 'gameservers_server' );
		}
		catch ( \Throwable )
		{
			return (string) Url::internal( $queryString, 'front' );
		}
	}

	/**
	 * Build servers listing URL
	 *
	 * @return	string
	 */
	protected function listingUrl(): string
	{
		try
		{
			return (string) Url::internal( 'app=gameservers&module=servers&controller=servers', 'front', 'gameservers_servers' );
		}
		catch ( \Throwable )
		{
			return (string) Url::internal( 'app=gameservers&module=servers&controller=servers', 'front' );
		}
	}

	/**
	 * Build connect URL from server template
	 *
	 * @param	array	$server
	 * @return	string
	 */
	protected function connectUrl( array $server ): string
	{
		$template = trim( (string) ( $server['connect_uri'] ?? '' ) );
		$address = trim( (string) ( $server['address'] ?? '' ) );

		if ( $template === '' OR $address === '' )
		{
			return '';
		}

		$ip = $address;
		$port = '';
		if ( preg_match( '/^(.*):(\d+)$/', $address, $matches ) )
		{
			$ip = trim( (string) $matches[1] );
			$port = trim( (string) $matches[2] );
		}

		$url = str_replace(
			array( '{address_encoded}', '{address}', '{ip_encoded}', '{ip}', '{port}' ),
			array( rawurlencode( $address ), $address, rawurlencode( $ip ), $ip, $port ),
			$template
		);

		return trim( (string) $url );
	}

	/**
	 * URL to modal list of vote sites
	 *
	 * @param	array	$server
	 * @return	string
	 */
	protected function voteModalUrl( array $server ): string
	{
		$queryString = 'app=gameservers&module=servers&controller=servers&do=votes&game=' . rawurlencode( (string) $server['game_id'] ) . '&address=' . rawurlencode( (string) $server['address'] );

		try
		{
			return (string) Url::internal( $queryString, 'front', 'gameservers_server_votes' );
		}
		catch ( \Throwable )
		{
			return (string) Url::internal( $queryString, 'front' );
		}
	}

	/**
	 * Parse vote links text format (Name|URL per line)
	 *
	 * @param	string	$raw
	 * @return	array
	 */
	protected function parseVoteLinks( string $raw ): array
	{
		$rows = array();

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
				continue;
			}

			$name = trim( (string) substr( $line, 0, $pos ) );
			$url = trim( (string) substr( $line, $pos + 1 ) );

			if ( $name === '' OR $url === '' )
			{
				continue;
			}

			if ( preg_match( '/^https?:\/\/[^\s]+$/i', $url ) !== 1 )
			{
				continue;
			}

			$rows[] = array( 'name' => $name, 'url' => $url );
		}

		return $rows;
	}

	/**
	 * Validate and return discord URL
	 *
	 * @param	array	$server
	 * @return	string
	 */
	protected function discordUrl( array $server ): string
	{
		$url = trim( (string) ( $server['discord_url'] ?? '' ) );
		if ( $url === '' )
		{
			return '';
		}

		if ( preg_match( '/^https?:\/\/(?:www\.)?(?:discord\.gg|discord\.com|discordapp\.com)(?:\/|$)/i', $url ) !== 1 )
		{
			return '';
		}

		return $url;
	}

	/**
	 * Validate and return TeamSpeak URL
	 *
	 * @param	array	$server
	 * @return	string
	 */
	protected function ts3Url( array $server ): string
	{
		$url = trim( (string) ( $server['ts3_url'] ?? '' ) );
		if ( $url === '' )
		{
			return '';
		}

		if ( preg_match( '/^(?:ts3server|teamspeak|https?):\/\/[^\s]+$/i', $url ) !== 1 )
		{
			return '';
		}

		return $url;
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
	 * Return game profile mapped to this server game id
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
	 * Render icon used in game tab button
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
				return "<span class='gqWidgetTabIcon'><img src='" . $this->escape( (string) $file->url ) . "' alt=''></span>";
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
				return "<span class='gqWidgetTabIcon'><i class='{$iconClasses}' aria-hidden='true'></i></span>";
			}
		}

		return "<span class='gqWidgetTabIcon'><i class='" . $this->defaultGameTabIconClasses( $server ) . "' aria-hidden='true'></i></span>";
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
	 * Build game key used for tab filtering
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
	 * Extract map label from status payload
	 *
	 * @param	array	$server
	 * @return	string
	 */
	protected function extractMapLabel( array $server ): string
	{
		if ( empty( $server['status_json'] ) )
		{
			return '';
		}

		try
		{
			$decoded = json_decode( (string) $server['status_json'], TRUE, 512, JSON_THROW_ON_ERROR );
		}
		catch ( \Throwable )
		{
			return '';
		}

		if ( !is_array( $decoded ) )
		{
			return '';
		}

		foreach ( array( 'map', 'mapname', 'map_name', 'current_map', 'level', 'mapTitle' ) as $key )
		{
			if ( isset( $decoded[ $key ] ) )
			{
				$value = trim( (string) $decoded[ $key ] );
				if ( $value !== '' )
				{
					return $value;
				}
			}
		}

		if ( isset( $decoded['_updater'] ) AND is_array( $decoded['_updater'] ) )
		{
			foreach ( array( 'map', 'mapname', 'map_name', 'current_map', 'level', 'mapTitle' ) as $key )
			{
				if ( isset( $decoded['_updater'][ $key ] ) )
				{
					$value = trim( (string) $decoded['_updater'][ $key ] );
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
	 * Render icon for server line
	 *
	 * @param	array	$server
	 * @return	string
	 */
	protected function serverIconHtml( array $server ): string
	{
		$iconType = trim( (string) ( $server['icon_type'] ?? '' ) );
		$iconValue = trim( (string) ( $server['icon_value'] ?? '' ) );
		$profile = $this->gameProfileForServer( $server );
		$profileIconType = trim( (string) ( $profile['icon_type'] ?? '' ) );
		$profileIconValue = trim( (string) ( $profile['icon_value'] ?? '' ) );
		$gameTooltip = trim( $this->gameDisplay( $server ) );
		if ( $gameTooltip === '' )
		{
			$gameTooltip = Member::loggedIn()->language()->addToStack( 'gq_server_game' );
		}
		$gameTooltip = $this->escape( $gameTooltip );
		$iconTooltip = " data-ipsTooltip title='{$gameTooltip}' aria-label='{$gameTooltip}'";

		if ( $iconType === 'upload' AND $iconValue !== '' )
		{
			try
			{
				$file = File::get( 'gameservers_Icons', $iconValue );
				return "<span class='gqWidgetServerIcon ipsType_small'{$iconTooltip}><img src='" . $this->escape( (string) $file->url ) . "' alt=''></span>";
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
				if ( strtolower( $iconValue ) !== 'server' OR $profileIconType === '' )
				{
					return "<span class='gqWidgetServerIcon ipsType_small ipsType_light'{$iconTooltip}><i class='{$iconClasses}' aria-hidden='true'></i></span>";
				}
			}
		}

		if ( $profileIconType === 'upload' AND $profileIconValue !== '' )
		{
			try
			{
				$file = File::get( 'gameservers_Icons', $profileIconValue );
				return "<span class='gqWidgetServerIcon ipsType_small'{$iconTooltip}><img src='" . $this->escape( (string) $file->url ) . "' alt=''></span>";
			}
			catch ( \Throwable )
			{
			}
		}

		if ( $profileIconType === 'preset' )
		{
			$profileClasses = $this->resolvePresetIconClasses( $profileIconValue );
			if ( $profileClasses !== '' )
			{
				return "<span class='gqWidgetServerIcon ipsType_small ipsType_light'{$iconTooltip}><i class='{$profileClasses}' aria-hidden='true'></i></span>";
			}
		}

		return "<span class='gqWidgetServerIcon ipsType_small ipsType_light'{$iconTooltip}><i class='fa-solid fa-server' aria-hidden='true'></i></span>";
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
	 * Build players cell with tooltip list
	 *
	 * @param	array	$server
	 * @param	string	$playersText
	 * @return	string
	 */
	protected function playersCellHtml( array $server, string $playersText ): string
	{
		$players = $this->extractPlayersForTooltip( $server );
		$value = "<span class='gqWidgetPlayersValue'>" . $this->escape( $playersText ) . "</span>";
		$tooltip = "<div class='gqWidgetPlayersTip' role='tooltip'>";

		if ( count( $players ) )
		{
			$nameHeader = $this->escape( Member::loggedIn()->language()->addToStack( 'gq_widget_player_name' ) );
			$scoreHeader = $this->escape( Member::loggedIn()->language()->addToStack( 'gq_widget_player_score' ) );
			$timeHeader = $this->escape( Member::loggedIn()->language()->addToStack( 'gq_widget_player_time' ) );
			$tooltip .= "<div class='gqWidgetPlayersTipHead'><div>{$nameHeader}</div><div class='gqWidgetPlayersTipNum'>{$scoreHeader}</div><div class='gqWidgetPlayersTipNum'>{$timeHeader}</div></div>";
			$tooltip .= "<div class='gqWidgetPlayersTipList'>";

			foreach ( $players as $player )
			{
				$tooltip .= "<div class='gqWidgetPlayersTipRow'>";
				$tooltip .= "<div class='gqWidgetPlayersTipName'>" . $this->escape( $player['name'] ) . "</div>";
				$tooltip .= "<div class='gqWidgetPlayersTipNum'>" . $this->escape( $player['score_label'] ) . "</div>";
				$tooltip .= "<div class='gqWidgetPlayersTipNum'>" . $this->escape( $player['time_label'] ) . "</div>";
				$tooltip .= "</div>";
			}

			$tooltip .= "</div>";
		}
		else
		{
			$emptyText = $this->escape( Member::loggedIn()->language()->addToStack( 'gq_widget_player_no_data' ) );
			$tooltip .= "<div class='gqWidgetPlayersTipEmpty'>{$emptyText}</div>";
		}

		$tooltip .= "</div>";

		return "<span class='gqWidgetPlayersWrap'>{$value}{$tooltip}</span>";
	}

	/**
	 * Extract and normalize player rows from server payload
	 *
	 * @param	array	$server
	 * @return	array
	 */
	protected function extractPlayersForTooltip( array $server ): array
	{
		if ( empty( $server['status_json'] ) )
		{
			return array();
		}

		try
		{
			$decoded = json_decode( (string) $server['status_json'], TRUE, 512, JSON_THROW_ON_ERROR );
		}
		catch ( \Throwable )
		{
			return array();
		}

		if ( !is_array( $decoded ) )
		{
			return array();
		}

		$candidates = array();
		$candidatePaths = array(
			array( 'players' ),
			array( 'player_list' ),
			array( 'players_list' ),
			array( 'playerdata' ),
			array( '_updater', 'players' ),
			array( '_updater', 'player_list' ),
			array( '_updater', 'players_list' ),
			array( 'data', 'players' ),
			array( '_updater', 'data', 'players' ),
		);

		foreach ( $candidatePaths as $path )
		{
			$node = $this->arrayPath( $decoded, $path );
			if ( is_array( $node ) )
			{
				$candidates[] = $node;
			}
		}

		$nestedPlayers = $this->arrayPath( $decoded, array( 'players' ) );
		if ( is_array( $nestedPlayers ) )
		{
			foreach ( array( 'list', 'sample', 'items', 'values', 'players' ) as $subKey )
			{
				if ( array_key_exists( $subKey, $nestedPlayers ) AND is_array( $nestedPlayers[ $subKey ] ) )
				{
					$candidates[] = $nestedPlayers[ $subKey ];
				}
			}
		}

		$deepCandidate = $this->findLikelyPlayersList( $decoded );
		if ( count( $deepCandidate ) )
		{
			$candidates[] = $deepCandidate;
		}

		foreach ( $candidates as $candidate )
		{
			$rows = $this->normalizePlayersList( $candidate );
			if ( count( $rows ) )
			{
				return $rows;
			}
		}

		return array();
	}

	/**
	 * Get nested array path
	 *
	 * @param	array	$data
	 * @param	array	$path
	 * @return	mixed
	 */
	protected function arrayPath( array $data, array $path )
	{
		$current = $data;
		foreach ( $path as $segment )
		{
			if ( !is_array( $current ) OR !array_key_exists( $segment, $current ) )
			{
				return NULL;
			}

			$current = $current[ $segment ];
		}

		return $current;
	}

	/**
	 * Find likely players list recursively
	 *
	 * @param	array	$node
	 * @param	int	$depth
	 * @return	array
	 */
	protected function findLikelyPlayersList( array $node, int $depth=0 ): array
	{
		if ( $depth > 4 )
		{
			return array();
		}

		if ( $this->looksLikePlayersList( $node ) )
		{
			return $node;
		}

		foreach ( $node as $value )
		{
			if ( is_array( $value ) )
			{
				$found = $this->findLikelyPlayersList( $value, $depth + 1 );
				if ( count( $found ) )
				{
					return $found;
				}
			}
		}

		return array();
	}

	/**
	 * Check if node resembles players list
	 *
	 * @param	array	$list
	 * @return	bool
	 */
	protected function looksLikePlayersList( array $list ): bool
	{
		if ( !count( $list ) )
		{
			return FALSE;
		}

		foreach ( $list as $key => $item )
		{
			if ( is_array( $item ) )
			{
				foreach ( array( 'name', 'player', 'nickname', 'nick', 'username', 'score', 'frags', 'kills', 'time', 'duration' ) as $probe )
				{
					if ( array_key_exists( $probe, $item ) )
					{
						return TRUE;
					}
				}
			}
			elseif ( is_scalar( $item ) AND preg_match( '/^\d+$/', (string) $key ) )
			{
				return TRUE;
			}

			break;
		}

		return FALSE;
	}

	/**
	 * Normalize player list rows
	 *
	 * @param	array	$list
	 * @return	array
	 */
	protected function normalizePlayersList( array $list ): array
	{
		$rows = array();

		foreach ( $list as $key => $item )
		{
			$name = '';
			$scoreRaw = NULL;
			$timeRaw = NULL;

			if ( is_array( $item ) )
			{
				foreach ( array( 'name', 'player', 'nickname', 'nick', 'username' ) as $nameKey )
				{
					if ( array_key_exists( $nameKey, $item ) AND is_scalar( $item[ $nameKey ] ) )
					{
						$name = trim( (string) $item[ $nameKey ] );
						break;
					}
				}

				foreach ( array( 'score', 'frags', 'kills', 'points' ) as $scoreKey )
				{
					if ( array_key_exists( $scoreKey, $item ) )
					{
						$scoreRaw = $item[ $scoreKey ];
						break;
					}
				}

				foreach ( array( 'time', 'duration', 'playtime', 'connected', 'online_time' ) as $timeKey )
				{
					if ( array_key_exists( $timeKey, $item ) )
					{
						$timeRaw = $item[ $timeKey ];
						break;
					}
				}
			}
			elseif ( is_scalar( $item ) )
			{
				if ( preg_match( '/^\d+$/', (string) $key ) )
				{
					$name = trim( (string) $item );
				}
				else
				{
					$name = trim( (string) $key );
					$scoreRaw = $item;
				}
			}

			if ( $name === '' AND !preg_match( '/^\d+$/', (string) $key ) )
			{
				$name = trim( (string) $key );
			}

			if ( $name === '' )
			{
				continue;
			}

			$score = $this->normalizePlayerScore( $scoreRaw );
			$time = $this->normalizePlayerTime( $timeRaw );

			$rows[] = array(
				'name' => $name,
				'score_label' => $score['label'],
				'score_sort' => $score['sort'],
				'time_label' => $time['label'],
				'time_sort' => $time['sort'],
			);
		}

		usort( $rows, function( $a, $b )
		{
			if ( $a['score_sort'] !== $b['score_sort'] )
			{
				return ( $a['score_sort'] < $b['score_sort'] ) ? 1 : -1;
			}

			if ( $a['time_sort'] !== $b['time_sort'] )
			{
				return ( $a['time_sort'] < $b['time_sort'] ) ? 1 : -1;
			}

			return strnatcasecmp( (string) $a['name'], (string) $b['name'] );
		} );

		return $rows;
	}

	/**
	 * Normalize score for display/sort
	 *
	 * @param	mixed	$value
	 * @return	array
	 */
	protected function normalizePlayerScore( $value ): array
	{
		if ( is_numeric( $value ) )
		{
			$number = (float) $value;
			$label = ( (int) $number == $number ) ? (string) (int) $number : (string) $number;
			return array( 'label' => $label, 'sort' => $number );
		}

		if ( is_scalar( $value ) AND trim( (string) $value ) !== '' )
		{
			return array( 'label' => trim( (string) $value ), 'sort' => 0 );
		}

		return array( 'label' => '-', 'sort' => 0 );
	}

	/**
	 * Normalize time for display/sort
	 *
	 * @param	mixed	$value
	 * @return	array
	 */
	protected function normalizePlayerTime( $value ): array
	{
		if ( is_numeric( $value ) )
		{
			$seconds = max( 0, (int) $value );
			return array( 'label' => $this->formatSeconds( $seconds ), 'sort' => $seconds );
		}

		if ( is_scalar( $value ) )
		{
			$raw = trim( (string) $value );
			if ( $raw === '' )
			{
				return array( 'label' => '-', 'sort' => 0 );
			}

			if ( preg_match( '/^(\d+):(\d{1,2})(?::(\d{1,2}))?$/', $raw, $matches ) )
			{
				$parts = explode( ':', $raw );
				if ( count( $parts ) === 2 )
				{
					$seconds = ( (int) $parts[0] * 60 ) + (int) $parts[1];
				}
				else
				{
					$seconds = ( (int) $parts[0] * 3600 ) + ( (int) $parts[1] * 60 ) + (int) $parts[2];
				}

				return array( 'label' => $raw, 'sort' => $seconds );
			}

			return array( 'label' => $raw, 'sort' => 0 );
		}

		return array( 'label' => '-', 'sort' => 0 );
	}

	/**
	 * Format seconds to human readable duration
	 *
	 * @param	int	$seconds
	 * @return	string
	 */
	protected function formatSeconds( int $seconds ): string
	{
		$hours = intdiv( $seconds, 3600 );
		$minutes = intdiv( $seconds % 3600, 60 );
		$secs = $seconds % 60;

		if ( $hours > 0 )
		{
			return sprintf( '%d:%02d:%02d', $hours, $minutes, $secs );
		}

		return sprintf( '%d:%02d', $minutes, $secs );
	}

	/**
	 * Render owner block using IPS member format
	 *
	 * @param	array	$server
	 * @return	string
	 */
	protected function ownerLinkHtml( array $server, bool $showAvatar=TRUE, bool $showName=TRUE ): string
	{
		if ( !$showAvatar AND !$showName )
		{
			return '';
		}

		$ownerId = (int) ( $server['owner_member_id'] ?? 0 );
		if ( !$ownerId )
		{
			return '';
		}

		try
		{
			$owner = Member::load( $ownerId );
			if ( !$owner->member_id )
			{
				return '';
			}

			$parts = array();

			if ( $showAvatar )
			{
				$parts[] = Theme::i()->getTemplate( 'global', 'core' )->userPhoto( $owner, 'tiny' );
			}

			if ( $showName )
			{
				$parts[] = "<span class='gqWidgetOwnerName'>" . (string) $owner->link() . "</span>";
			}

			if ( !count( $parts ) )
			{
				return '';
			}

			return "<span class='gqWidgetOwnerInner'>" . implode( '', $parts ) . "</span>";
		}
		catch ( \Throwable )
		{
			return '';
		}
	}

	/**
	 * Linked forum/category URL if available
	 *
	 * @param	array	$server
	 * @return	string
	 */
	protected function forumCategoryUrl( array $server ): string
	{
		if ( !Application::appIsEnabled( 'forums' ) OR empty( $server['forum_id'] ) )
		{
			return '';
		}

		try
		{
			$forum = Forum::loadAndCheckPerms( (int) $server['forum_id'] );
			return (string) $forum->url();
		}
		catch ( \Throwable )
		{
			return '';
		}
	}
}
