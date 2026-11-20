-- =====================================================================
-- Venues list (after 20261112120000_security_hardening.sql)
--   The event form's Venue is a dropdown: the school's own places
--   (On-campus) and the online platforms (Online) come from this list,
--   which Super Admins keep under Categories → Venues. Off-campus places
--   are still typed in on the form.
--   events.venue keeps the venue's name as text, so deleting a venue
--   here never changes an event that already uses it.
--
-- Run it once in Supabase -> SQL Editor (paste everything, Run).
-- It can be run again safely.
-- =====================================================================

create table if not exists public.venues (
  id          uuid primary key default extensions.uuid_generate_v4(),
  name        text not null,
  venue_type  text not null,                -- 'on_campus' | 'online'
  created_at  timestamptz not null default now(),
  constraint venues_type_check check (venue_type in ('on_campus', 'online')),
  constraint venues_name_check check (length(btrim(name)) between 1 and 120)
);
comment on table public.venues is 'The event form''s Venue dropdown: on-campus places and online platforms. Off-campus places are typed in on the form.';

-- One venue of each name per list ("AVR 1" and "avr 1" are the same place).
create unique index if not exists venues_type_name_key on public.venues (venue_type, lower(btrim(name)));

alter table public.venues enable row level security;
drop policy if exists "Venues are viewable by everyone" on public.venues;
create policy "Venues are viewable by everyone" on public.venues
  for select using (true);
drop policy if exists "Admins manage venues" on public.venues;
create policy "Admins manage venues" on public.venues
  for all using (public.is_admin()) with check (public.is_admin());

grant select on public.venues to anon, authenticated;
grant insert, update, delete on public.venues to authenticated;

-- Starting list: the on-campus places events already use, and the usual platforms.
insert into public.venues (name, venue_type)
select distinct on (lower(btrim(venue))) btrim(venue), 'on_campus'
from public.events
where venue_type::text = 'on_campus' and coalesce(btrim(venue), '') <> ''
on conflict do nothing;

insert into public.venues (name, venue_type) values
  ('Zoom', 'online'),
  ('Google Meet', 'online'),
  ('Microsoft Teams', 'online'),
  ('Facebook Live', 'online')
on conflict do nothing;

-- Let the site see the new table straight away.
notify pgrst, 'reload schema';
