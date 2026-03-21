# Game Servers (Invision Community)

Game Servers is an Invision Community application (`applications/gameservers`) that integrates with the GameQuery API to publish live game server status, player counts, and quick connect actions for your community.

## Main Image

![Game Servers main preview](https://i.imgur.com/KOLKShT.png)

## Gallery

![Game Servers gallery 01](https://i.imgur.com/KOLKShT.png)
![Game Servers gallery 02](https://i.imgur.com/HnjUIdg.png)
![Game Servers gallery 03](https://i.imgur.com/qotUd4i.png)
![Game Servers gallery 04](https://i.imgur.com/zWHL1PG.png)
![Game Servers gallery 05](https://i.imgur.com/FR2UwI1.png)
![Game Servers gallery 06](https://i.imgur.com/lNMNuv8.png)
![Game Servers gallery 07](https://i.imgur.com/vVpcIQE.png)
![Game Servers gallery 08](https://i.imgur.com/occ8Q9a.png)
![Game Servers gallery 09](https://i.imgur.com/GiXvm4w.png)
![Game Servers gallery 10](https://i.imgur.com/Smy1rKI.png)

## Description

The app adds a complete game server directory to Invision Community with automatic polling from GameQuery. Administrators can manage servers in ACP, configure refresh intervals, and expose status blocks/pages to visitors with runtime server data.

## Key Features

- ACP management for servers, game profiles, and API settings
- Manual and scheduled status refresh through background tasks
- Front-end server listing and detailed server pages
- Player history charting and runtime data visibility
- Optional connect links, vote links, Discord links, and TeamSpeak links
- Configurable server status widget for forum pages

## Built With

- PHP
- Invision Community application framework
- GameQuery API

## Quick Start

1. Copy this repository into your Invision Community installation so the path is `applications/gameservers`.
2. In ACP, open **System -> Applications** and install **Game Servers**.
3. Open **Game Servers -> Settings**.
4. Add your GameQuery credentials:
   - `API Token`
   - `API Token Type` (`FREE`)
   - `Token Email`
   - `Refresh interval (minutes)`
5. Open **Game Servers -> Servers**, add your first server (`Name`, `Game ID`, `Address`), then click **Refresh Now**.

## Community Market Checklist

- [x] Public repository
- [x] `README.md` present and descriptive
- [x] `CHANGELOG.md` present with version sections
- [x] SPDX-detectable license file present (`LICENSE`, AGPL)
- [x] Main image and gallery image links included in `README.md`

## License

This project is licensed under the GNU Affero General Public License v3.0. See `LICENSE`.
