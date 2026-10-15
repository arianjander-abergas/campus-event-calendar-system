-- =====================================================================
-- Manage Events update — branch manage-events-dropdown
--   1. "Other" category: the organizer types what kind of event it is
--      (the Create Event form no longer has a separate Event Type).
--   2. Multi-organization events: two or more organizations hold one
--      event. events.organization_id stays the main organizer (its
--      moderators run the event, as before); the others are listed in
--      event_organizations as co-organizers.
-- Additive: existing columns, rules and pages keep working.
--
-- Run it once in Supabase -> SQL Editor (paste everything, Run).
-- It can be run again safely.
-- =====================================================================


-- =====================================================================
-- 1. "Other" category: the event's type in the organizer's words
-- =====================================================================
alter table public.events add column if not exists category_other text;
alter table public.events drop constraint if exists events_category_other_check;
alter table public.events add constraint events_category_other_check
  check (category_other is null or char_length(category_other) between 1 and 60);
comment on column public.events.category_other is 'The event type typed in when the category is "Other"';


-- =====================================================================
-- 2. Co-organizers of multi-organization events
-- =====================================================================
create table if not exists public.event_organizations (
  event_id        uuid not null references public.events(id) on delete cascade,
  organization_id uuid not null references public.organizations(id) on delete restrict,
  created_at      timestamptz not null default now(),
  primary key (event_id, organization_id)
);
comment on table public.event_organizations is 'Co-organizers of multi-organization events (the main organizer is events.organization_id)';
create index if not exists event_organizations_org_idx on public.event_organizations(organization_id);

-- The main organizer isn't repeated as a co-organizer.
create or replace function public.check_event_co_organizer()
returns trigger
language plpgsql
set search_path = public
as $$
begin
  if exists (select 1 from public.events e where e.id = new.event_id and e.organization_id = new.organization_id) then
    raise exception 'The main organizer is already on this event.' using errcode = 'unique_violation';
  end if;
  return new;
end;
$$;
drop trigger if exists trg_event_organizations_not_main on public.event_organizations;
create trigger trg_event_organizations_not_main
  before insert or update on public.event_organizations
  for each row execute function public.check_event_co_organizer();

alter table public.event_organizations enable row level security;

-- Everyone can see who organizes an event (like events.organization_id).
drop policy if exists "Co-organizers are viewable by everyone" on public.event_organizations;
create policy "Co-organizers are viewable by everyone" on public.event_organizations
  for select using (true);

-- Admins, and the main organizer's moderators, choose the co-organizers.
drop policy if exists "Event managers choose co-organizers" on public.event_organizations;
create policy "Event managers choose co-organizers" on public.event_organizations
  for all to authenticated
  using (public.can_manage_event_id(event_id))
  with check (public.can_manage_event_id(event_id));

grant select on public.event_organizations to anon, authenticated;
grant insert, update, delete on public.event_organizations to authenticated;


-- Let the API see the new column and table right away.
notify pgrst, 'reload schema';
