-- =====================================================================
-- Security hardening (after 20261108120000_organization_codes.sql)
--   1. A new registration must start as "registered". Until now a student
--      calling the database directly could create one already marked
--      "attended" (any event, even past or full ones), which skipped every
--      registration rule and unlocked the evaluation form. The event's
--      admins / moderators can still add someone as attended (walk-ins).
--   2. The three image buckets accept only JPG, PNG, WEBP or GIF up to
--      5 MB, the same rule the site's upload form already uses.
--   3. Four helper functions get a fixed search_path (Supabase's security
--      advisor warning "Function Search Path Mutable").
--
-- Run it once in Supabase -> SQL Editor (paste everything, Run).
-- It can be run again safely. To undo it:
-- supabase/rollback/20261112120000_security_hardening_undo.sql
-- =====================================================================

-- 1. New registrations start as "registered" ---------------------------
create or replace function public.guard_registration_insert()
returns trigger
language plpgsql
set search_path = public
as $$
begin
  -- Only requests from the site / API users (not the database itself).
  if current_user in ('anon', 'authenticated')
     and new.status::text <> 'registered'
     and not public.can_manage_event_id(new.event_id) then
    raise exception 'REGISTER_FIRST: A new registration starts as registered; only the event''s admins mark attendance.'
      using errcode = '42501';
  end if;
  return new;
end;
$$;

drop trigger if exists trg_event_registrations_insert_status on public.event_registrations;
create trigger trg_event_registrations_insert_status
  before insert on public.event_registrations
  for each row execute function public.guard_registration_insert();

-- Lets Database update (config/supabase.php → DB_UPDATES) see this was run.
create or replace function public.registrations_are_guarded()
returns boolean
language sql
stable
set search_path = public
as $$ select true $$;
grant execute on function public.registrations_are_guarded() to anon, authenticated;

-- 2. Images only, up to 5 MB -------------------------------------------
update storage.buckets
   set file_size_limit = 5242880,
       allowed_mime_types = array['image/jpeg', 'image/png', 'image/webp', 'image/gif']
 where id in ('event-posters', 'officer-photos', 'org-logos');

-- 3. Fixed search_path for the flagged helpers ---------------------------
do $$
declare f record;
begin
  for f in
    select p.oid::regprocedure as sig
    from pg_proc p join pg_namespace n on n.oid = p.pronamespace
    where n.nspname = 'public'
      and p.proname in ('year_levels_text', 'event_time_range', 'is_school_email', 'profiles_are_private')
  loop
    execute format('alter function %s set search_path = public', f.sig);
  end loop;
end;
$$;

-- Let the site see the new function straight away.
notify pgrst, 'reload schema';
