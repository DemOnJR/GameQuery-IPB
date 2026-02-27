# GameQuery-IPB

`GameQuery-IPB` is an Invision Community application (`applications/gameservers`) for listing game servers and syncing their status from the GameQuery API.

## Install

1. Copy this repository's files into your Invision Community app folder so the app path becomes `applications/gameservers`.
2. In your ACP, go to **System -> Applications**.
3. Install **Game Servers**.
4. Open **Game Servers -> Settings** in ACP.

## Get a free GameQuery API key

1. Open `https://gamequery.dev/dashboard/keys`.
2. Sign in (or create an account).
3. Create a new key on the **FREE** plan.
4. Copy the token details.

## Configure the app in ACP

In **Game Servers -> Settings**, fill in:

- `API Token`: your generated API key
- `API Token Type`: `FREE`
- `Token Email`: the email linked to your GameQuery account
- `Refresh Minutes`: how often to auto-refresh server data

Save settings when done.

## Add your first server

1. Go to **Game Servers -> Servers** and click **Add Server**.
2. Enter server details (`Name`, `Game ID`, `Address` in `host:port` format).
3. Save, then use **Refresh now** to fetch live status immediately.
