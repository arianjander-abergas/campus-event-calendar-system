-- =====================================================================
-- UNDO for supabase/migrations/20261112120000_security_hardening.sql
--   Only if that update causes a problem. Puts the database back exactly
--   as it was before it: no registration guard, no bucket limits, and the
--   four helper functions without a fixed search_path.
--
-- Run it in Supabase -> SQL Editor (paste everything, Run).
-- No data is deleted.
-- =====================================================================

drop trigger if exists trg_event_registrations_insert_status on public.event_registrations;
drop function if exists public.guard_registration_insert();
drop function if exists public.registrations_are_guarded();

update storage.buckets
   set file_size_limit = null,
       allowed_mime_types = null
 where id in ('event-posters', 'officer-photos', 'org-logos');

do $$
declare f record;
begin
  for f in
    select p.oid::regprocedure as sig
    from pg_proc p join pg_namespace n on n.oid = p.pronamespace
    where n.nspname = 'public'
      and p.proname in ('year_levels_text', 'event_time_range', 'is_school_email', 'profiles_are_private')
  loop
    execute format('alter function %s reset search_path', f.sig);
  end loop;
end;
$$;

notify pgrst, 'reload schema';
