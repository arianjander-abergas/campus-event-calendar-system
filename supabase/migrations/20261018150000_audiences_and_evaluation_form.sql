-- =====================================================================
-- Audiences + the evaluation form for Admins — branch manage-events-dropdown
-- (after 20261018120000_approval_notes.sql)
--   1. Who an event is for: any of Students, Faculty and Outsiders
--      (people from outside the school) — events.audiences. The older
--      events.audience (students | faculty | all) is still filled in from
--      it for the reports. Registering follows audiences: a student can't
--      join an event that isn't open to students, a faculty member one that
--      isn't open to faculty.
--   2. Admins (moderator role) can edit the evaluation form too, like
--      Super Admins.
--
-- Run it once in Supabase -> SQL Editor (paste everything, Run).
-- It can be run again safely.
-- =====================================================================


-- =====================================================================
-- 1. Audiences
-- =====================================================================
alter table public.events add column if not exists audiences text[];
alter table public.events drop constraint if exists events_audiences_check;
alter table public.events add constraint events_audiences_check
  check (audiences is null
         or (cardinality(audiences) > 0 and audiences <@ array['students', 'faculty', 'outsiders']::text[]));
comment on column public.events.audiences is 'Who the event is for: any of students, faculty, outsiders (null on older events: see audience)';

-- Registering: the person's account must be one of the event's audiences
-- (the event's Admins and the Super Admins are exempt, as with the other rules).
create or replace function public.check_registration_audiences()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
  v_audiences text[];
  v_type text;
begin
  if new.status <> 'registered' or (tg_op = 'UPDATE' and old.status = 'registered') then
    return new;
  end if;
  if public.can_manage_event_id(new.event_id) then
    return new;
  end if;
  select audiences into v_audiences from public.events where id = new.event_id;
  if v_audiences is null then
    return new;
  end if;
  select account_type into v_type from public.profiles where id = new.user_id;
  if v_type = 'student' and not ('students' = any (v_audiences)) then
    raise exception 'NOT_FOR_STUDENTS: This event isn''t open to students.';
  end if;
  if v_type = 'faculty' and not ('faculty' = any (v_audiences)) then
    raise exception 'NOT_FOR_FACULTY: This event isn''t open to faculty.';
  end if;
  return new;
end;
$$;
revoke execute on function public.check_registration_audiences() from public, anon, authenticated;
drop trigger if exists trg_event_registrations_audiences on public.event_registrations;
create trigger trg_event_registrations_audiences
  before insert or update on public.event_registrations
  for each row execute function public.check_registration_audiences();


-- =====================================================================
-- 2. Admins edit the evaluation form too
-- =====================================================================
drop policy if exists "Organization admins manage the evaluation form" on public.evaluation_questions;
create policy "Organization admins manage the evaluation form" on public.evaluation_questions
  for all to authenticated
  using (public.is_moderator()) with check (public.is_moderator());

notify pgrst, 'reload schema';
