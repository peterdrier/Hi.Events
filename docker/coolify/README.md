# Hi.Events on Coolify (Nobodies fork)

Runs this fork, with the Humans checkout endpoint, as one Coolify Docker Compose resource: the all-in-one app
(frontend, API, queue worker, scheduler), PostgreSQL and Redis.

## Deploy

1. In Coolify: **New resource → Public/Private Repository → Docker Compose**, repository `peterdrier/Hi.Events`,
   branch of your choice, compose file `docker/coolify/docker-compose.yml`.
2. Give the `hievents` service a domain, for example `https://tickets-test.example.org`. Coolify puts it in
   `SERVICE_FQDN_HIEVENTS` and generates the database password, `APP_KEY` and `JWT_SECRET`.
3. Set the Stripe variables (use test keys for a trial): `STRIPE_PUBLIC_KEY`, `STRIPE_SECRET_KEY`,
   `STRIPE_WEBHOOK_SECRET`. Point a Stripe webhook at `https://<domain>/api/public/webhooks/stripe`. Optionally set the
   `MAIL_*` variables; mail goes to the log otherwise.
4. Deploy. Migrations run on start.

## Connect Humans

1. Register the first user at `https://<domain>/auth/register` (set `APP_DISABLE_REGISTRATION=false` for that first
   deploy, then back to `true`), create an organizer and an event with currency EUR, and turn on Stripe payments.
2. Create exactly two products with **no taxes or fees**: one of type Ticket and one of type General (donations).
   Humans finds them by type, so the event must have no other product of either type. Any base price works; Humans
   sets the price of every order.
3. Settings → API keys: create a read-write key. In Humans set `HIEVENTS_API_KEY` to it, and
   `HiEvents:ApiUrl=https://<domain>/api`. The Hi.Events event id is then set as the backend event on the Humans
   event.
4. Event → Webhooks: add `https://<humans>/HiEvents/Webhook` for the `order.*` events and put its signing secret in
   Humans' `HIEVENTS_WEBHOOK_SECRET`.
