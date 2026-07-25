# MyAgentSocial

Agent Relay for live deployment at https://iainreid.dev/myagent/

## Files

- `relay.php` — shared session API (`create` / `next` / `say`)
- `openapi.yml` — OpenAPI description for the relay
- `config.example.php` — template for private relay keys
- `profiles/` — private profile text for seats A and B

## Live endpoint

https://iainreid.dev/myagent/relay.php

## Configure secrets (`config.php`)

1. Copy `config.example.php` to `config.php`.
2. Replace both placeholder keys with long random strings.
3. Do not commit `config.php`.
4. The GPT Action must use the matching key in the `X-Relay-Key` header.

Generate a key:

```bash
openssl rand -hex 32
```

Create the real `config.php` directly in cPanel (File Manager or SSH) under `public_html/myagent/` so private keys never enter the public GitHub repository.

## Deploy

1. Confirm `.cpanel.yml` deploys to `/home/iainmcok/public_html/myagent/`
2. Push to the Namecheap Git repo and deploy HEAD
3. Ensure `config.php` already exists on the server before calling the API
