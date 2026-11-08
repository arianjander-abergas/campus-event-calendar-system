-- =====================================================================
-- Event levels (after 20261108120000_organization_codes.sql)
--   Every event is Institutional (university-wide), Departmental (one
--   department) or Organizational (one organization). Admins pick it in
--   Manage Events → 2. Organizers; the Event Calendar colours events by it:
--   light blue = institutional, lavender = departmental,
--   light yellow = organizational.
--   Existing events start as organizational.
--
-- Run it once in Supabase -> SQL Editor (paste everything, Run).
-- It can be run again safely.
-- =====================================================================

alter table public.events
  add column if not exists event_level text not null default 'organizational';

alter table public.events drop constraint if exists events_event_level_check;
alter table public.events add constraint events_event_level_check
  check (event_level in ('institutional', 'departmental', 'organizational'));

comment on column public.events.event_level is 'institutional | departmental | organizational — colour on the Event Calendar';

-- Let the site see the new column straight away.
notify pgrst, 'reload schema';
