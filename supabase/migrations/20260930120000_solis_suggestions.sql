-- =====================================================================
-- Panel suggestions (Solis, Sept 2026) — branch solis-suggestions
--   1. Moderator role: runs its own organization's events
--   2. Accounts: student / faculty, department, school ID (private
--      table), regular / irregular students and their year levels
--   3. Events: open to chosen year levels; registration checks the
--      audience and year levels
--   4. No duplicate events and no double-booked venues
--   5. Events are marked Completed automatically once they end
-- Additive: every existing column, rule and page keeps working, so the
-- other branches can keep using the same database.
--
-- Run it once in Supabase -> SQL Editor (paste everything, Run).
-- It can be run again safely.
-- =====================================================================


-- =====================================================================
-- 1. Roles: admin | moderator | user
-- =====================================================================
alter type public.user_role add value if not exists 'moderator';
-- The new value can't be used as an enum literal in this same run, so
-- the functions below compare role::text instead.

create or replace function public.is_moderator()
returns boolean
language sql
stable
security definer
set search_path = public
as $$
  select exists (select 1 from public.profiles where id = auth.uid() and role::text = 'moderator');
$$;

-- The logged-in user's organization (a moderator's scope).
create or replace function public.my_organization_id()
returns uuid
language sql
stable
security definer
set search_path = public
as $$
  select organization_id from public.profiles where id = auth.uid();
$$;

-- Admins manage every event; moderators the events of their own organization.
create or replace function public.can_manage_event(p_organization_id uuid)
returns boolean
language sql
stable
security definer
set search_path = public
as $$
  select public.is_admin()
      or (p_organization_id is not null
          and public.is_moderator()
          and p_organization_id = public.my_organization_id());
$$;

create or replace function public.can_manage_event_id(p_event_id uuid)
returns boolean
language sql
stable
security definer
set search_path = public
as $$
  select public.is_admin()
      or exists (select 1 from public.events e
                 where e.id = p_event_id and public.can_manage_event(e.organization_id));
$$;

revoke execute on function public.is_moderator(), public.my_organization_id(),
  public.can_manage_event(uuid), public.can_manage_event_id(uuid) from public, anon;
grant execute on function public.is_moderator(), public.my_organization_id(),
  public.can_manage_event(uuid), public.can_manage_event_id(uuid) to authenticated;


-- =====================================================================
-- 2. Accounts
-- =====================================================================
alter table public.profiles
  add column if not exists account_type text,
  add column if not exists department_id uuid references public.departments(id) on delete set null,
  add column if not exists student_type text,
  add column if not exists irregular_year_levels int2[];

alter table public.profiles drop constraint if exists profiles_account_type_check;
alter table public.profiles add constraint profiles_account_type_check
  check (account_type in ('student', 'faculty'));
alter table public.profiles drop constraint if exists profiles_student_type_check;
alter table public.profiles add constraint profiles_student_type_check
  check (student_type in ('regular', 'irregular'));
alter table public.profiles drop constraint if exists profiles_irregular_year_levels_check;
alter table public.profiles add constraint profiles_irregular_year_levels_check
  check (irregular_year_levels <@ array[1, 2, 3, 4, 5]::int2[]);

comment on column public.profiles.account_type is 'student | faculty (chosen at sign-up; only admins change it afterwards)';
comment on column public.profiles.department_id is 'Department the account belongs to (required with the organization)';
comment on column public.profiles.year_level is 'Highest year level (1-5) for students; null for faculty';
comment on column public.profiles.student_type is 'regular | irregular (students only)';
comment on column public.profiles.irregular_year_levels is 'Irregular students: every year level they have subjects in (includes the highest)';
create index if not exists profiles_department_idx on public.profiles(department_id);

-- School IDs are kept out of `profiles` (which everyone can read).
create table if not exists public.school_ids (
  user_id     uuid primary key references public.profiles(id) on delete cascade,
  school_id   text not null,
  verified_at timestamptz,               -- set when an admin confirms it against the registrar's list
  verified_by uuid,                      -- the admin who did (no FK, so embeds stay unambiguous)
  created_at  timestamptz not null default now(),
  updated_at  timestamptz not null default now(),
  constraint school_ids_school_id_key unique (school_id),
  constraint school_ids_format check (school_id ~ '^[A-Z0-9][A-Z0-9-]{3,19}$')
);
comment on table public.school_ids is 'Student school ID numbers (uppercase, no spaces). Visible to the student, admins, and moderators whose events they joined.';

drop trigger if exists trg_school_ids_updated_at on public.school_ids;
create trigger trg_school_ids_updated_at
  before update on public.school_ids
  for each row execute function public.set_updated_at();

alter table public.school_ids enable row level security;

drop policy if exists "School IDs visible to their owner and staff" on public.school_ids;
create policy "School IDs visible to their owner and staff" on public.school_ids
  for select to authenticated using (
    user_id = auth.uid()
    or public.is_admin()
    or (public.is_moderator() and (
      exists (select 1 from public.profiles p where p.id = school_ids.user_id and p.organization_id = public.my_organization_id())
      or exists (select 1 from public.event_registrations r
                 join public.events e on e.id = r.event_id
                 where r.user_id = school_ids.user_id and e.organization_id = public.my_organization_id()))));

drop policy if exists "Users add their own school ID" on public.school_ids;
create policy "Users add their own school ID" on public.school_ids
  for insert to authenticated
  with check (user_id = auth.uid() and verified_at is null and verified_by is null);

-- Students can fix a typo until an admin verifies the ID.
drop policy if exists "Users fix their own unverified school ID" on public.school_ids;
create policy "Users fix their own unverified school ID" on public.school_ids
  for update to authenticated
  using (user_id = auth.uid() and verified_at is null)
  with check (user_id = auth.uid() and verified_at is null and verified_by is null);

drop policy if exists "Admins manage school IDs" on public.school_ids;
create policy "Admins manage school IDs" on public.school_ids
  for all to authenticated using (public.is_admin()) with check (public.is_admin());

grant select, insert, update, delete on public.school_ids to authenticated;

-- Whether a school ID is already used (sign-up form), without revealing whose.
create or replace function public.school_id_taken(p_school_id text)
returns boolean
language sql
stable
security definer
set search_path = public
as $$
  select exists (select 1 from public.school_ids
                 where school_id = upper(regexp_replace(coalesce(p_school_id, ''), '\s', '', 'g')));
$$;
grant execute on function public.school_id_taken(text) to anon, authenticated;

-- Sign-up creates the profile (and the school ID) from the sign-up form.
-- Nothing here raises: accounts from Google sign-in have no form data, and
-- the site asks them to complete their profile before anything else.
create or replace function public.handle_new_user()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
  meta jsonb := coalesce(new.raw_user_meta_data, '{}'::jsonb);
  v_type text;
  v_org uuid;
  v_dept uuid;
  v_levels int2[];
  v_school text;
begin
  v_type := case when meta->>'account_type' in ('student', 'faculty') then meta->>'account_type' end;
  select o.id into v_org from public.organizations o where o.id::text = meta->>'organization_id';
  select d.id into v_dept from public.departments d where d.id::text = meta->>'department_id';
  if v_dept is null and v_org is not null then
    select o.department_id into v_dept from public.organizations o where o.id = v_org;
  end if;
  if jsonb_typeof(meta->'irregular_year_levels') = 'array' then
    select array_agg(distinct x::int2 order by x::int2) into v_levels
    from jsonb_array_elements_text(meta->'irregular_year_levels') x
    where x ~ '^[1-5]$';
  end if;
  v_school := upper(regexp_replace(coalesce(meta->>'school_id', ''), '\s', '', 'g'));

  insert into public.profiles (id, full_name, role, year_level, course, organization_id,
                               department_id, account_type, student_type, irregular_year_levels)
  values (
    new.id,
    coalesce(nullif(trim(meta->>'full_name'), ''), nullif(trim(meta->>'name'), ''), new.email),
    'user',
    case when v_type is distinct from 'faculty' and (meta->>'year_level') ~ '^[1-5]$' then (meta->>'year_level')::int2 end,
    case when v_type is distinct from 'faculty' then nullif(trim(meta->>'course'), '') end,
    v_org,
    v_dept,
    v_type,
    case when v_type = 'student' and meta->>'student_type' in ('regular', 'irregular') then meta->>'student_type' end,
    case when v_type = 'student' and meta->>'student_type' = 'irregular' then v_levels end
  )
  on conflict (id) do nothing;

  -- A taken or badly formatted ID is skipped; the profile form asks again.
  if v_type = 'student' and v_school ~ '^[A-Z0-9][A-Z0-9-]{3,19}$' then
    insert into public.school_ids (user_id, school_id) values (new.id, v_school)
    on conflict do nothing;
  end if;
  return new;
end;
$$;
revoke execute on function public.handle_new_user() from public, anon, authenticated;

-- Only admins change roles (as before). Also: moderators can't move
-- themselves to another organization (that's their scope), and the
-- student / faculty choice is fixed once made.
create or replace function public.protect_profile_role()
returns trigger
language plpgsql
set search_path = public
as $$
begin
  if current_user in ('anon', 'authenticated') and not public.is_admin() then
    if new.role is distinct from old.role then
      raise exception 'Only an admin can change a user''s role.' using errcode = '42501';
    end if;
    if old.role::text = 'moderator' and new.organization_id is distinct from old.organization_id then
      raise exception 'ORG_LOCKED: Moderators can''t change their organization. Ask an admin.' using errcode = '42501';
    end if;
    if old.account_type is not null and new.account_type is distinct from old.account_type then
      raise exception 'ACCOUNT_TYPE_LOCKED: Only an admin can switch an account between student and faculty.' using errcode = '42501';
    end if;
  end if;
  return new;
end;
$$;
revoke execute on function public.protect_profile_role() from public, anon, authenticated;


-- =====================================================================
-- 3. Events: year levels + what moderators may do
-- =====================================================================
alter table public.events add column if not exists year_levels int2[];
alter table public.events drop constraint if exists events_year_levels_check;
alter table public.events add constraint events_year_levels_check
  check (year_levels <@ array[1, 2, 3, 4, 5]::int2[]);
comment on column public.events.year_levels is 'Student year levels that may register (null or empty = every year level)';

-- {1,2} -> '1st & 2nd year', {2,3,4} -> '2nd, 3rd & 4th year' (registration error messages).
create or replace function public.year_levels_text(p_levels int2[])
returns text
language plpgsql
immutable
as $$
declare
  words text[];
  n int;
begin
  select array_agg(l::text || case l when 1 then 'st' when 2 then 'nd' when 3 then 'rd' else 'th' end order by l)
  into words
  from (select distinct unnest(p_levels) as l) x;
  n := coalesce(cardinality(words), 0);
  if n = 0 then
    return '';
  elsif n = 1 then
    return words[1] || ' year';
  end if;
  return array_to_string(words[1:n - 1], ', ') || ' & ' || words[n] || ' year';
end;
$$;

-- Moderators: their organization's events (any status), no deleting.
drop policy if exists "Moderators view their organization's events" on public.events;
create policy "Moderators view their organization's events" on public.events
  for select to authenticated using (public.can_manage_event(organization_id));

drop policy if exists "Moderators add events for their organization" on public.events;
create policy "Moderators add events for their organization" on public.events
  for insert to authenticated
  with check (public.is_moderator() and organization_id = public.my_organization_id());

drop policy if exists "Moderators edit their organization's events" on public.events;
create policy "Moderators edit their organization's events" on public.events
  for update to authenticated
  using (public.is_moderator() and organization_id = public.my_organization_id())
  with check (public.is_moderator() and organization_id = public.my_organization_id());

drop policy if exists "Event managers manage programs" on public.event_programs;
create policy "Event managers manage programs" on public.event_programs
  for all to authenticated
  using (public.can_manage_event_id(event_id)) with check (public.can_manage_event_id(event_id));

drop policy if exists "Event managers view registrations" on public.event_registrations;
create policy "Event managers view registrations" on public.event_registrations
  for select to authenticated using (public.can_manage_event_id(event_id));

drop policy if exists "Event managers mark attendance" on public.event_registrations;
create policy "Event managers mark attendance" on public.event_registrations
  for update to authenticated
  using (public.can_manage_event_id(event_id)) with check (public.can_manage_event_id(event_id));

drop policy if exists "Event managers view evaluations" on public.event_evaluations;
create policy "Event managers view evaluations" on public.event_evaluations
  for select to authenticated using (public.can_manage_event_id(event_id));

drop policy if exists "Event managers notify about their events" on public.notifications;
create policy "Event managers notify about their events" on public.notifications
  for insert to authenticated
  with check (event_id is not null and public.can_manage_event_id(event_id));
-- ...and can read those back (the site reads what it saves to count who was notified).
drop policy if exists "Event managers view their events' notifications" on public.notifications;
create policy "Event managers view their events' notifications" on public.notifications
  for select to authenticated
  using (event_id is not null and public.can_manage_event_id(event_id));
grant select, insert on public.notifications to authenticated;

drop policy if exists "Moderators manage their organization's officers" on public.organization_officers;
create policy "Moderators manage their organization's officers" on public.organization_officers
  for all to authenticated
  using (public.is_moderator() and organization_id = public.my_organization_id())
  with check (public.is_moderator() and organization_id = public.my_organization_id());

drop policy if exists "Moderators upload posters and officer photos" on storage.objects;
create policy "Moderators upload posters and officer photos" on storage.objects
  for insert to authenticated
  with check (bucket_id in ('event-posters', 'officer-photos') and public.is_moderator());

-- Notifications about an event: its admins / moderators (was admins only).
create or replace function public.notify_event(p_event_id uuid, p_audience text, p_title text, p_message text)
returns integer
language plpgsql
security definer
set search_path = public
as $$
declare
  sent integer;
begin
  if not public.can_manage_event_id(p_event_id) then
    raise exception 'Only admins and the event''s moderators can send notifications.' using errcode = '42501';
  end if;

  if p_audience = 'registered' then
    insert into public.notifications (event_id, user_id, title, message, status, sent_at)
    select p_event_id, r.user_id, p_title, p_message, 'sent', now()
    from public.event_registrations r
    where r.event_id = p_event_id and r.status in ('registered', 'attended');
  else
    insert into public.notifications (event_id, user_id, title, message, status, sent_at)
    select p_event_id, p.id, p_title, p_message, 'sent', now()
    from public.profiles p
    where p.id <> auth.uid();
  end if;

  get diagnostics sent = row_count;
  return sent;
end;
$$;
revoke all on function public.notify_event(uuid, text, text, text) from public, anon;
grant execute on function public.notify_event(uuid, text, text, text) to authenticated;


-- =====================================================================
-- 4. Registration rules (adds: audience + year levels; moderators may
--    mark attendance for their organization's events)
-- =====================================================================
create or replace function public.check_event_registration()
returns trigger
language plpgsql
security definer            -- needs to count everyone's registrations
set search_path = public
as $$
declare
  ev public.events%rowtype;
  p public.profiles%rowtype;
  taken integer;
  today date := (now() at time zone 'Asia/Manila')::date;
  staff boolean := public.can_manage_event_id(new.event_id);
  my_levels int2[];
begin
  if tg_op = 'INSERT' then
    new.registered_at := now();
  end if;

  if tg_op = 'UPDATE' and not staff then
    if new.event_id <> old.event_id or new.user_id <> old.user_id then
      raise exception 'A registration can''t be moved to another event or user.' using errcode = '42501';
    end if;
    if new.status not in ('registered', 'cancelled') then
      raise exception 'Only an admin can mark attendance.' using errcode = '42501';
    end if;
  end if;

  -- The rules apply whenever a seat is taken: a new registration, or a
  -- cancelled one re-activated by the student.
  if new.status = 'registered'
     and (tg_op = 'INSERT' or (old.status is distinct from 'registered' and not staff)) then
    -- Serialize seat-taking per event so two people can't grab the last seat.
    perform pg_advisory_xact_lock(hashtextextended(new.event_id::text, 0));

    select * into ev from public.events where id = new.event_id;
    if not found or ev.status <> 'published' or not ev.is_published then
      raise exception 'EVENT_NOT_OPEN: This event isn''t open for registration.';
    end if;
    if not ev.enable_registration then
      raise exception 'REGISTRATION_DISABLED: Registration isn''t enabled for this event.';
    end if;
    if ev.registration_deadline is not null and today > ev.registration_deadline then
      raise exception 'DEADLINE_PASSED: The registration deadline has passed.';
    end if;
    if coalesce(ev.end_date, ev.start_date) < today then
      raise exception 'EVENT_ENDED: This event has already ended.';
    end if;

    -- Who the event is for (the event's admins / moderators are exempt).
    if not staff then
      select * into p from public.profiles where id = new.user_id;
      if ev.audience::text = 'faculty' and p.account_type = 'student' then
        raise exception 'FACULTY_ONLY: This event is for faculty only.';
      end if;
      if ev.audience::text = 'students' and p.account_type = 'faculty' then
        raise exception 'STUDENTS_ONLY: This event is for students only.';
      end if;
      -- Year levels: regular students by their year level; irregular
      -- students by every year level they have subjects in.
      if coalesce(cardinality(ev.year_levels), 0) > 0 and p.account_type is distinct from 'faculty' then
        if p.year_level is null then
          raise exception 'YEAR_LEVEL_REQUIRED: This event is only for some year levels. Add your year level in My Profile first.';
        end if;
        my_levels := case
          when p.student_type = 'irregular' and coalesce(cardinality(p.irregular_year_levels), 0) > 0
            then p.irregular_year_levels || p.year_level
          else array[p.year_level]
        end;
        if not (my_levels && ev.year_levels) then
          raise exception 'YEAR_LEVEL_RESTRICTED: This event is only open to % students.', public.year_levels_text(ev.year_levels);
        end if;
      end if;
    end if;

    if ev.registration_limit is not null then
      select count(*) into taken
      from public.event_registrations
      where event_id = new.event_id
        and status in ('registered', 'attended')
        and id <> new.id;
      if taken >= ev.registration_limit then
        raise exception 'EVENT_FULL: This event is full.';
      end if;
    end if;
  end if;

  return new;
end;
$$;
revoke execute on function public.check_event_registration() from public, anon, authenticated;


-- =====================================================================
-- 5. No duplicate events, no double-booked venues
-- =====================================================================
-- Start and end of an event as a timestamp range. No start time = from
-- midnight; no end time = until the end of the end date.
create or replace function public.event_time_range(p_start_date date, p_start_time time, p_end_date date, p_end_time time)
returns tsrange
language sql
immutable
as $$
  select tsrange(lo, greatest(lo, hi), '[)')
  from (select p_start_date + coalesce(p_start_time, time '00:00') as lo,
               case when p_end_time is null
                    then (coalesce(p_end_date, p_start_date) + 1)::timestamp
                    else coalesce(p_end_date, p_start_date) + p_end_time
               end as hi) x;
$$;

create or replace function public.check_event_schedule()
returns trigger
language plpgsql
security definer            -- must see every event, drafts included
set search_path = public
as $$
declare
  clash record;
  new_online boolean := coalesce(new.is_online, false) or new.venue_type::text = 'online';
begin
  if new.status::text in ('cancelled', 'completed') then
    return new;
  end if;
  -- Only when the title, date, time or venue changes (older rows stay editable).
  if tg_op = 'UPDATE'
     and new.title is not distinct from old.title
     and new.start_date is not distinct from old.start_date
     and new.end_date is not distinct from old.end_date
     and new.start_time is not distinct from old.start_time
     and new.end_time is not distinct from old.end_time
     and new.venue is not distinct from old.venue
     and new.venue_type is not distinct from old.venue_type
     and new.is_online is not distinct from old.is_online
     and new.status is not distinct from old.status then
    return new;
  end if;

  -- The same title on the same start date is the same event entered twice.
  select e.title, e.start_date into clash
  from public.events e
  where e.id <> new.id
    and e.status::text <> 'cancelled'
    and lower(btrim(e.title)) = lower(btrim(new.title))
    and e.start_date = new.start_date
  limit 1;
  if found then
    raise exception 'DUPLICATE_EVENT: "%" is already scheduled on %. Change the title or the date.',
      clash.title, to_char(clash.start_date, 'Mon FMDD, YYYY');
  end if;

  -- A venue can hold one event at a time.
  if not new_online and nullif(btrim(new.venue), '') is not null then
    perform pg_advisory_xact_lock(hashtextextended(lower(btrim(new.venue)), 1));
    select e.title, e.start_date, e.venue into clash
    from public.events e
    where e.id <> new.id
      and e.status::text <> 'cancelled'
      and not (coalesce(e.is_online, false) or e.venue_type::text = 'online')
      and lower(btrim(e.venue)) = lower(btrim(new.venue))
      and public.event_time_range(e.start_date, e.start_time, e.end_date, e.end_time)
          && public.event_time_range(new.start_date, new.start_time, new.end_date, new.end_time)
    limit 1;
    if found then
      raise exception 'VENUE_CONFLICT: % is already booked for "%" (%) at that time. Choose another venue, date or time.',
        btrim(clash.venue), clash.title, to_char(clash.start_date, 'Mon FMDD, YYYY');
    end if;
  end if;
  return new;
end;
$$;
revoke execute on function public.check_event_schedule() from public, anon, authenticated;

drop trigger if exists trg_events_schedule_rules on public.events;
create trigger trg_events_schedule_rules
  before insert or update on public.events
  for each row execute function public.check_event_schedule();


-- =====================================================================
-- 6. Completed automatically once an event ends
-- =====================================================================
-- Published events whose end (end date + end time, or the end of the end
-- date) has passed in Philippine time become 'completed'. The site calls
-- this every few minutes; it only ever touches events that are over.
create or replace function public.complete_ended_events()
returns integer
language plpgsql
security definer
set search_path = public
as $$
declare
  n integer;
begin
  update public.events e
  set status = 'completed'
  where e.status::text = 'published'
    and upper(public.event_time_range(e.start_date, e.start_time, e.end_date, e.end_time))
        <= (now() at time zone 'Asia/Manila');
  get diagnostics n = row_count;
  return n;
end;
$$;
revoke all on function public.complete_ended_events() from public;
grant execute on function public.complete_ended_events() to anon, authenticated;

-- Home-page "Events" counter: completed events still count.
create or replace function public.site_stats()
returns table (events bigint, organizations bigint, active_users bigint, registrations bigint)
language sql
stable
security definer
set search_path = public
as $$
  select
    (select count(*) from public.events where is_published = true and status::text in ('published', 'completed')),
    (select count(*) from public.organizations),
    (select count(*) from public.user_presence where last_seen_at > now() - interval '2 minutes'),
    (select count(*) from public.event_registrations where status in ('registered', 'attended'));
$$;
revoke all on function public.site_stats() from public;
grant execute on function public.site_stats() to anon, authenticated;

-- Mark the events that are already over right away.
select public.complete_ended_events();

-- Optional: if the pg_cron extension is on (Database -> Extensions), this
-- also runs it every 5 minutes without anyone visiting the site:
--   select cron.schedule('complete-ended-events', '*/5 * * * *', 'select public.complete_ended_events()');

-- Let the API see the new columns and functions right away.
notify pgrst, 'reload schema';
