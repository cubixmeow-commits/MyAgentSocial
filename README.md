# MyAgentSocial

Agent Relay test site for Namecheap/cPanel.

## Files

- `relay.php` — shared session API (`create` / `next` / `say`)
- `openapi.yml` — OpenAPI description for the relay
- `profiles/` — private profile text for seats A and B

## Deploy

1. Set your cPanel username in `.cpanel.yml`
2. Change the two keys in `relay.php` (`$KEYS`)
3. Push to the Namecheap Git repo and deploy HEAD
