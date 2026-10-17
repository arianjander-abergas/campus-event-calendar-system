-- =====================================================================
-- Three role levels — branch manage-events-dropdown
-- (after 20261017120000_super_admin_approvals.sql)
--   The roles are shown as:  user      -> Student / Faculty
--                            moderator -> Admin       (their organization's events)
--                            admin     -> Super Admin (everything)
--   1. Every Super Admin approves; new events by Admins (moderator role)
--      need a Super Admin's approval. Super Admins' own events don't.
--      Profile edits still need approval, except a Super Admin's own.
--   2. Super Admins can delete accounts (delete_account()).
--   profiles.is_super_admin (Arian Abergas) now just protects that account:
--   it can't be demoted or deleted from the site.
--
-- Run it once in Supabase -> SQL Editor (paste everything, Run).
-- It can be run again safely.
-- =====================================================================


-- =====================================================================
-- 1. Who approves, and whose events need approval
-- =====================================================================
-- A Super Admin = the 'admin' role.
create or replace function public.is_super_admin()
returns boolean
language sql
stable
security definer
set search_path = public
as $$
  select exists (select 1 from public.profiles where id = auth.uid() and role::text = 'admin');
$$;
grant execute on function public.is_super_admin() to anon, authenticated;

comment on column public.profiles.is_super_admin is 'The site owner''s Super Admin account (Arian Abergas): can''t be demoted or deleted from the site. Set only in the SQL Editor.';

-- Same rule as before, but it's Admins' (moderator role) new events that wait.
create or replace function public.enforce_event_approval()
returns trigger
language plpgsql
set search_path = public
as $$
declare
  v_requested text := new.approval_status;
begin
  if current_user not in ('anon', 'authenticated') then
    return new;
  end if;
  if public.is_super_admin() then
    return new;
  end if;

  if tg_op = 'INSERT' then
    new.approval_status := case when public.is_moderator() then 'needed' end;
    new.approval_note := null;
    new.approval_requested_at := null;
    new.approval_reviewed_by := null;
    new.approval_reviewed_at := null;
  else
    new.approval_status := old.approval_status;
    new.approval_note := old.approval_note;
    new.approval_requested_at := old.approval_requested_at;
    new.approval_reviewed_by := old.approval_reviewed_by;
    new.approval_reviewed_at := old.approval_reviewed_at;
    -- Saving it as a draft again takes the request back.
    if old.approval_status = 'pending' and v_requested = 'needed' and new.status::text = 'draft' then
      new.approval_status := 'needed';
      new.approval_requested_at := null;
    end if;
  end if;

  if new.approval_status in ('needed', 'pending', 'rejected') and new.status::text = 'published' then
    new.status := 'draft';
    new.is_published := false;
    new.approval_status := 'pending';
    new.approval_requested_at := now();
  end if;
  return new;
end;
$$;
revoke execute on function public.enforce_event_approval() from public, anon, authenticated;


-- =====================================================================
-- 2. Deleting an account (Super Admins)
-- =====================================================================
-- Removes the login and the profile (with its School ID, registrations and
-- requests, where those are set to go with it). Events, categories and
-- organizations it created stay; they just no longer say who made them.
create or replace function public.delete_account(p_user uuid)
returns text
language plpgsql
security definer
set search_path = public
as $$
declare
  v_name text;
begin
  if not public.is_super_admin() then
    raise exception 'SUPER_ADMIN_ONLY: Only a Super Admin can delete accounts.' using errcode = '42501';
  end if;
  if p_user = auth.uid() then
    raise exception 'OWN_ACCOUNT: You can''t delete your own account.';
  end if;
  select full_name into v_name from public.profiles where id = p_user;
  if not found then
    raise exception 'NOT_FOUND: That account doesn''t exist anymore.';
  end if;
  if exists (select 1 from public.profiles where id = p_user and is_super_admin) then
    raise exception 'PROTECTED: This Super Admin account is protected and can''t be deleted.' using errcode = '42501';
  end if;

  -- Keep what they made; forget who made it (skipped where a table has no such column).
  begin update public.events set created_by = null where created_by = p_user; exception when others then null; end;
  begin update public.categories set created_by = null where created_by = p_user; exception when others then null; end;
  begin update public.organizations set created_by = null where created_by = p_user; exception when others then null; end;
  begin update public.organization_officers set profile_id = null where profile_id = p_user; exception when others then null; end;

  delete from public.profiles where id = p_user;
  delete from auth.users where id = p_user;
  return v_name;
exception
  when foreign_key_violation then
    raise exception 'IN_USE: This account is still linked to other records, so it can''t be deleted. Change its role to Student / Faculty instead.';
end;
$$;
revoke execute on function public.delete_account(uuid) from public, anon;
grant execute on function public.delete_account(uuid) to authenticated;

notify pgrst, 'reload schema';
