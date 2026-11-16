# Kimai (local)

Local [Kimai](https://www.kimai.org) time-tracking instance for development, run
as its own compose stack (like `~/n8n` and `~/orbis-time-management`), not part of
`ai-automation/docker-compose.yml`.

- Kimai UI + REST API: http://localhost:8001
- MySQL 8.3: internal only (no host port); reached by the app over the compose network
- Image: `kimai/kimai2:apache-2.55.0` (Apache, Symfony/PHP) — 2.55.0 is the
  production version, so local runs the same one; official template from
  https://www.kimai.org/documentation/docker-compose.html

## Start / stop

```sh
cp .env.example .env   # then fill in the passwords and APP_SECRET
docker compose up -d   # pulls on first run
docker compose down    # keeps volumes
docker compose down -v # wipes data volumes too
```

## Admin login

`ADMIN_EMAIL` / `ADMIN_PASSWORD` live in `.env`. The container creates the
admin user on first boot if it does not exist.

## Data

Volumes: `kimai_data` (`/opt/kimai/var/data`), `kimai_mysql`
(`/var/lib/mysql`). Plugins are a bind mount from `./plugins`
(`/opt/kimai/var/plugins`), so they are versioned with this repo.

## Local plugin: WorkingTimeApiBundle

`plugins/WorkingTimeApiBundle/` exposes Kimai's core working-time calculation
(expected vs recorded minutes per user and month, from the contract
preferences) over the REST API, which the core does not serve:

```
GET /api/working-time/month?month=YYYY-MM[&user=<email|username>]
```

- `user` omitted → the caller's own month; another user needs the
  `view_other_timesheet` permission (an admin token has it).
- Response: `{ month, expectedMinutes, recordedMinutes, days: [{ date,
  expectedMinutes, recordedMinutes }] }`. Expected minutes only count up to
  today within the month.
- Setup so it works: each user's contract preferences must be set
  (`work_contract_type=day` + `work_monday..sunday` in seconds, as Kimai's
  user settings save them: 28800 = 8h). The API cannot set them; use the
  user's Contract settings in Kimai. Without them expected minutes are 0.
- Reload after editing: `docker exec kimai-kimai-1 /opt/kimai/bin/console
  cache:clear --env=prod` (the bind mount is live; only the cache needs to
  forget the old container).

## API

The REST API is served on the same origin: http://localhost:8001/api
(Kimai 2 API docs: https://www.kimai.org/documentation/rest-api.html).

**Auth:** Kimai ≥ 2.54 removed API password (Basic Auth) — only **API tokens**
work, sent as `Authorization: Bearer <token>`. Tokens live in
`kimai2_access_token` (a null `expires_at` never expires). An `ai-automation`
token for the admin user is pre-created and stored in `.env` as
`KIMAI_API_TOKEN` (you can verify it with
`curl -H "Authorization: Bearer $KIMAI_API_TOKEN" http://localhost:8001/api/ping`).
New tokens can be generated in the user profile UI.
