-- =====================================================================
-- School emails, required attendance, notifications by email
-- (after 20261022120000_evaluation_form_parts.sql)
--   1. New accounts must use an @email.lcup.edu.ph address (sign-up and
--      Google). Exceptions, for staff with another address, are added in
--      the SQL Editor:  insert into public.allowed_email_exceptions (email, note)
--                       values ('name@gmail.com', 'Registrar');
--   2. Events can be required: for everyone they're open to, for the
--      organizing organizations' members, or for their departments'.
--   3. Notifications go only to the people an event is open to (it used to
--      be everyone), required attendees are always told, and every new
--      notification is also sent by email by the site (config/mail.local.php).
--
-- Run it once in Supabase -> SQL Editor (paste everything, Run).
-- It can be run again safely.
-- =====================================================================


-- =====================================================================
-- 1. Only @email.lcup.edu.ph accounts
-- =====================================================================
create or replace function public.is_school_email(p_email text)
returns boolean
language sql
immutable
as $$
  select coalesce(lower(btrim(p_email)) ~ '^[a-z0-9._%+''-]+@email\.lcup\.edu\.ph$', false);
$$;

-- Addresses allowed although they aren't school emails (SQL Editor only:
-- RLS on, no policies, so the API can't read or change it).
create table if not exists public.allowed_email_exceptions (
  email      text primary key check (email = lower(btrim(email))),
  note       text,
  created_at timestamptz not null default now()
);
comment on table public.allowed_email_exceptions is 'Non-school email addresses that may still have accounts (e.g. staff). Lowercase. Managed in the SQL Editor.';
alter table public.allowed_email_exceptions enable row level security;

-- Whether an address may have an account: a school email or an exception.
create or replace function public.email_allowed(p_email text)
returns boolean
language sql
stable
security definer
set search_path = public
as $$
  select public.is_school_email(p_email)
      or exists (select 1 from public.allowed_email_exceptions where email = lower(btrim(p_email)));
$$;
grant execute on function public.email_allowed(text) to anon, authenticated;

-- New accounts (and changed addresses) must be allowed. Existing accounts
-- aren't touched: only an INSERT, or an UPDATE that changes the email, is
-- checked (Supabase rewrites the row at every login).
create or replace function public.enforce_school_email()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
begin
  if new.email is null then
    return new;  -- no address (phone / anonymous sign-ins, which are off)
  end if;
  if tg_op = 'UPDATE' and lower(new.email) is not distinct from lower(old.email) then
    return new;
  end if;
  if not public.email_allowed(new.email) then
    raise exception 'SCHOOL_EMAIL_ONLY: Only LCUP school emails (ending in @email.lcup.edu.ph) can be used.'
      using errcode = '42501';
  end if;
  return new;
end;
$$;
revoke execute on function public.enforce_school_email() from public, anon, authenticated;

drop trigger if exists trg_auth_users_school_email on auth.users;
create trigger trg_auth_users_school_email
  before insert or update of email on auth.users
  for each row execute function public.enforce_school_email();


-- =====================================================================
-- 2. Required attendance
-- =====================================================================
alter table public.events add column if not exists required_for text;
alter table public.events drop constraint if exists events_required_for_check;
alter table public.events add constraint events_required_for_check
  check (required_for in ('everyone', 'members', 'department'));
comment on column public.events.required_for is 'Required attendance: everyone (it is open to) | members (of the organizing organizations) | department (of the organizing organizations) | null = not required';

-- Whether an event is open to a person: the same rules as registering
-- (audiences, the older audience, year levels; irregular students count
-- every year level they have subjects in).
create or replace function public.event_open_to(e public.events, p public.profiles)
returns boolean
language sql
stable
set search_path = public
as $$
  select
    (case
       when e.audiences is not null then
             (p.account_type is distinct from 'student' or 'students' = any (e.audiences))
         and (p.account_type is distinct from 'faculty' or 'faculty' = any (e.audiences))
       else
             not (e.audience::text = 'faculty' and p.account_type = 'student')
         and not (e.audience::text = 'students' and p.account_type = 'faculty')
     end)
    and (
      coalesce(cardinality(e.year_levels), 0) = 0
      or p.account_type = 'faculty'
      or (p.year_level is not null and
          (case when p.student_type = 'irregular' and coalesce(cardinality(p.irregular_year_levels), 0) > 0
                then p.irregular_year_levels || p.year_level
                else array[p.year_level]
           end) && e.year_levels)
    );
$$;

-- Whether a person is required to attend an event (they must be someone it's open to).
create or replace function public.event_required_for(e public.events, p public.profiles)
returns boolean
language sql
stable
set search_path = public
as $$
  select e.required_for is not null
     and public.event_open_to(e, p)
     and (
           e.required_for = 'everyone'
        or (e.required_for = 'members' and p.organization_id is not null
            and (p.organization_id = e.organization_id
                 or exists (select 1 from public.event_organizations eo
                            where eo.event_id = e.id and eo.organization_id = p.organization_id)))
        or (e.required_for = 'department' and p.department_id is not null
            and exists (select 1 from public.organizations o
                        where o.department_id = p.department_id
                          and (o.id = e.organization_id
                               or exists (select 1 from public.event_organizations eo
                                          where eo.event_id = e.id and eo.organization_id = o.id))))
     );
$$;

-- The logged-in person's required events that haven't ended (dashboard,
-- event cards, the event page).
create or replace function public.my_required_events()
returns table (event_id uuid)
language sql
stable
security definer
set search_path = public
as $$
  select e.id
  from public.events e
  join public.profiles p on p.id = auth.uid()
  where e.status::text = 'published'
    and coalesce(e.end_date, e.start_date) >= (now() at time zone 'Asia/Manila')::date
    and public.event_required_for(e, p);
$$;
revoke all on function public.my_required_events() from public, anon;
grant execute on function public.my_required_events() to authenticated;


-- =====================================================================
-- 3. Notifications: who gets them, and email delivery
-- =====================================================================
-- New rows wait to be emailed; the rows that already exist don't.
alter table public.notifications
  add column if not exists email_pending boolean not null default false,
  add column if not exists email_attempts int2 not null default 0,
  add column if not exists email_retry_at timestamptz,
  add column if not exists emailed_at timestamptz,
  add column if not exists email_error text;
alter table public.notifications alter column email_pending set default true;
comment on column public.notifications.email_pending is 'Waiting to be emailed by the site (true for new notifications)';
comment on column public.notifications.email_retry_at is 'The email failed: tried again after this time';
create index if not exists notifications_email_pending_idx on public.notifications(created_at) where email_pending;

-- People can mark their notifications read, but not touch the email columns.
create or replace function public.guard_notification_email()
returns trigger
language plpgsql
set search_path = public
as $$
begin
  if current_user in ('anon', 'authenticated') and not (public.is_admin() or public.is_moderator()) then
    new.email_pending := old.email_pending;
    new.email_attempts := old.email_attempts;
    new.email_retry_at := old.email_retry_at;
    new.emailed_at := old.emailed_at;
    new.email_error := old.email_error;
  end if;
  return new;
end;
$$;
revoke execute on function public.guard_notification_email() from public, anon, authenticated;
drop trigger if exists trg_notifications_email_guard on public.notifications;
create trigger trg_notifications_email_guard
  before update on public.notifications
  for each row execute function public.guard_notification_email();

-- p_audience:
--   'all' / 'open'  everyone the event is open to (not everyone any more)
--   'open_others'   everyone it's open to who isn't required to attend
--   'required'      the people required to attend
--   'registered'    people registered for it, and the people required to attend
create or replace function public.notify_event(p_event_id uuid, p_audience text, p_title text, p_message text)
returns integer
language plpgsql
security definer
set search_path = public
as $$
declare
  ev public.events%rowtype;
  sent integer;
begin
  if not public.can_manage_event_id(p_event_id) then
    raise exception 'Only admins and the event''s moderators can send notifications.' using errcode = '42501';
  end if;
  select * into ev from public.events where id = p_event_id;
  if not found then
    return 0;
  end if;

  if p_audience = 'registered' then
    insert into public.notifications (event_id, user_id, title, message, status, sent_at)
    select p_event_id, x.user_id, p_title, p_message, 'sent', now()
    from (
      select r.user_id from public.event_registrations r
      where r.event_id = p_event_id and r.status in ('registered', 'attended')
      union
      select p.id from public.profiles p where public.event_required_for(ev, p)
    ) x;
  elsif p_audience = 'required' then
    insert into public.notifications (event_id, user_id, title, message, status, sent_at)
    select p_event_id, p.id, p_title, p_message, 'sent', now()
    from public.profiles p
    where p.id <> auth.uid() and public.event_required_for(ev, p);
  elsif p_audience = 'open_others' then
    insert into public.notifications (event_id, user_id, title, message, status, sent_at)
    select p_event_id, p.id, p_title, p_message, 'sent', now()
    from public.profiles p
    where p.id <> auth.uid() and public.event_open_to(ev, p) and not public.event_required_for(ev, p);
  else -- 'all' / 'open'
    insert into public.notifications (event_id, user_id, title, message, status, sent_at)
    select p_event_id, p.id, p_title, p_message, 'sent', now()
    from public.profiles p
    where p.id <> auth.uid() and public.event_open_to(ev, p);
  end if;

  get diagnostics sent = row_count;
  return sent;
end;
$$;
revoke all on function public.notify_event(uuid, text, text, text) from public, anon;
grant execute on function public.notify_event(uuid, text, text, text) to authenticated;

-- Notifications still to be emailed, with the address (admins: all;
-- moderators: their organization's events). Only addresses that may sign
-- in: school emails, exceptions, and Super Admins.
create or replace function public.pending_notification_emails(p_limit integer default 100)
returns table (id uuid, email text, full_name text, title text, message text, event_id uuid, created_at timestamptz)
language sql
stable
security definer
set search_path = public
as $$
  select n.id, u.email::text, p.full_name, n.title, n.message, n.event_id, n.created_at
  from public.notifications n
  join public.profiles p on p.id = n.user_id
  join auth.users u on u.id = n.user_id
  where n.email_pending
    and n.created_at > now() - interval '3 days'
    and (n.email_retry_at is null or n.email_retry_at <= now())
    and u.email is not null
    and (public.email_allowed(u.email) or p.role::text = 'admin')
    and (public.is_admin() or (n.event_id is not null and public.can_manage_event_id(n.event_id)))
  order by n.created_at, n.id
  limit greatest(1, least(coalesce(p_limit, 100), 500));
$$;
revoke all on function public.pending_notification_emails(integer) from public, anon;
grant execute on function public.pending_notification_emails(integer) to authenticated;

-- After sending: emailed (p_error null), or failed: tried again after 10
-- minutes, 2 hours and a day (e.g. Gmail's daily sending limit), then dropped.
create or replace function public.mark_notification_emails(p_ids uuid[], p_error text default null)
returns integer
language plpgsql
security definer
set search_path = public
as $$
declare
  n integer;
begin
  if not (public.is_admin() or public.is_moderator()) then
    raise exception 'Only admins and moderators send notification emails.' using errcode = '42501';
  end if;
  update public.notifications
  set email_attempts = email_attempts + 1,
      email_pending  = (p_error is not null and email_attempts + 1 < 4),
      email_retry_at = case when p_error is not null then
                         now() + case email_attempts when 0 then interval '10 minutes'
                                                     when 1 then interval '2 hours'
                                                     else interval '1 day' end
                       end,
      emailed_at     = case when p_error is null then now() end,
      email_error    = left(p_error, 500)
  where id = any (p_ids)
    and (public.is_admin() or (event_id is not null and public.can_manage_event_id(event_id)));
  get diagnostics n = row_count;
  return n;
end;
$$;
revoke all on function public.mark_notification_emails(uuid[], text) from public, anon;
grant execute on function public.mark_notification_emails(uuid[], text) to authenticated;

notify pgrst, 'reload schema';
