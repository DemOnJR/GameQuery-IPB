# Game Servers

Game Servers is an Invision Community application (`applications/gameservers`) that connects to the GameQuery API and displays live game server data for your community.

![Cover](https://i.imgur.com/KOLKShT.png)

## Screenshots

![Servers screenshot 01](https://i.imgur.com/KOLKShT.png)
![Servers screenshot 02](https://i.imgur.com/HnjUIdg.png)
![Servers screenshot 03](https://i.imgur.com/qotUd4i.png)
![Servers screenshot 04](https://i.imgur.com/zWHL1PG.png)
![Servers screenshot 05](https://i.imgur.com/FR2UwI1.png)
![Servers screenshot 06](https://i.imgur.com/lNMNuv8.png)
![Servers screenshot 07](https://i.imgur.com/vVpcIQE.png)
![Servers screenshot 08](https://i.imgur.com/occ8Q9a.png)
![Servers screenshot 09](https://i.imgur.com/GiXvm4w.png)
![Servers screenshot 10](https://i.imgur.com/Smy1rKI.png)

## Description

This app provides a full game server listing workflow inside Invision Community. Administrators can configure API credentials, manage servers and game profiles in ACP, trigger refreshes, and publish front-end views with real-time server status, players, and runtime details.

## Key Features

- Centralized ACP management for servers, game profiles, and GameQuery settings
- Manual and scheduled refresh support through background tasks
- Front-end server list, details pages, player history, and server widget blocks

## Built With

- PHP

## Tags

- IPB

## Quick Start

1. Copy this repository into your Invision Community installation so the path is `applications/gameservers`.
2. In ACP, open **System -> Applications** and install **Game Servers**.
3. Open **Game Servers -> Settings** and add your GameQuery credentials (`API Token`, `API Token Type`, `Token Email`, `Refresh interval`).
4. Open **Game Servers -> Servers**, add your first server (`Name`, `Game ID`, `Address`), then click **Refresh Now**.

## Community Market Checklist

- [x] Public repository (not archived)
- [x] `README.md` present and descriptive
- [x] `CHANGELOG.md` present with version sections
- [x] SPDX license detected (`LICENSE` file)
- [x] Images included in `README.md` for cover/gallery import

## License

This project is licensed under the GNU Affero General Public License v3.0. See `LICENSE`.
