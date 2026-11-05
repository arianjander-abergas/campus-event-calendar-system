-- =====================================================================
-- Private profiles (after 20261103120000_school_email_and_notifications.sql)
--   Until now every profile (name, role, phone, year level, course,
--   organization) could be read by anyone with the site's public key, even
--   without logging in. Now:
--     - everyone can read their own profile;
--     - Admins and Super Admins can read everyone's (Users & Roles,
--       registrations, attendance, evaluations, approvals);
--     - visitors and other students can't read anyone else's.
--   The site's counters and checks (site_stats(), notify_event(), the
--   sign-up trigger…) run as the database and keep working.
--
-- Run it once in Supabase -> SQL Editor (paste everything, Run).
-- It can be run again safely.
-- =====================================================================

-- Remove every rule that lets people read profiles (the old "Profiles are
-- viewable by everyone", whatever it's called now), then add the new one.
do $$
declare
  p record;
begin
  for p in
    select policyname from pg_policies
    where schemaname = 'public' and tablename = 'profiles' and cmd = 'SELECT'
  loop
    execute format('drop policy %I on public.profiles', p.policyname);
  end loop;
end;
$$;

-- Logged-in people only. Visitors (no login) have no rule at all, so they
-- get no rows, without an error — the site's "is this column there yet?"
-- checks keep working.
create policy "Own profile, or Admins and Super Admins" on public.profiles
  for select
  to authenticated
  using (id = auth.uid() or public.is_admin() or public.is_moderator());

-- Lets the site check that this update is in (Database update page).
create or replace function public.profiles_are_private()
returns boolean
language sql
immutable
as $$ select true $$;
grant execute on function public.profiles_are_private() to anon, authenticated;

notify pgrst, 'reload schema';
