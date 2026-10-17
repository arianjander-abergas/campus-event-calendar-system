-- =====================================================================
-- Super admin approvals — branch manage-events-dropdown
--   1. Super admin: Arian Abergas. Only they approve what's below; who is
--      super admin can only be changed here, in the SQL Editor.
--   2. Events created by (other) admins need the super admin's approval
--      before they're published. Publishing one sends it for approval:
--      it stays a draft until the super admin approves it.
--   3. Profile edits need the super admin's approval. Filling in details
--      that are still empty (completing a profile) doesn't; changing
--      details that are already there goes in as a change request, which
--      the super admin approves (applied) or rejects.
-- Moderators' events work as before. Additive: existing columns, rules
-- and pages keep working.
--
-- Run it once in Supabase -> SQL Editor (paste everything, Run).
-- It can be run again safely.
-- =====================================================================


-- =====================================================================
-- 1. Super admin
-- =====================================================================
alter table public.profiles add column if not exists is_super_admin boolean not null default false;
comment on column public.profiles.is_super_admin is 'The super admin approves admins'' events and profile edits. Set only in the SQL Editor.';

-- Arian Abergas (by account id, so nobody gets this by signing up with the same name).
update public.profiles
   set role = 'admin', is_super_admin = true
 where id = 'f5cb4a3c-e56a-4c98-8f7d-e1d80f450255';

create or replace function public.is_super_admin()
returns boolean
language sql
stable
security definer
set search_path = public
as $$
  select exists (select 1 from public.profiles
                 where id = auth.uid() and is_super_admin and role::text = 'admin');
$$;
-- (anon too: the rules below run it on every change, and it's simply false without a login)
grant execute on function public.is_super_admin() to anon, authenticated;

-- Profiles, from the site (not the SQL Editor or the functions below):
--  - nobody makes themselves (or anyone) super admin, and the super admin stays an admin;
--  - your own details: filling in an empty one is fine, changing one that's
--    there needs the super admin's approval (profile_change_requests).
create or replace function public.guard_profile_changes()
returns trigger
language plpgsql
set search_path = public
as $$
begin
  if current_user not in ('anon', 'authenticated') then
    return new;
  end if;
  if tg_op = 'INSERT' then
    new.is_super_admin := false;
    return new;
  end if;
  if new.is_super_admin is distinct from old.is_super_admin then
    raise exception 'SUPER_ADMIN_LOCKED: Who is super admin can only be changed in the database.' using errcode = '42501';
  end if;
  if old.is_super_admin and new.role::text <> 'admin' then
    raise exception 'SUPER_ADMIN_LOCKED: The super admin''s role can''t be changed here.' using errcode = '42501';
  end if;
  if old.id is distinct from auth.uid() or public.is_super_admin() then
    return new;
  end if;
  if (
       (nullif(btrim(old.full_name), '') is not null and new.full_name is distinct from old.full_name)
    or (old.account_type is not null and new.account_type is distinct from old.account_type)
    or (old.department_id is not null and new.department_id is distinct from old.department_id)
    or (old.organization_id is not null and new.organization_id is distinct from old.organization_id)
    or (old.year_level is not null and new.year_level is distinct from old.year_level)
    or (nullif(btrim(old.course), '') is not null and new.course is distinct from old.course)
    or (old.student_type is not null and new.student_type is distinct from old.student_type)
    or (coalesce(cardinality(old.irregular_year_levels), 0) > 0 and new.irregular_year_levels is distinct from old.irregular_year_levels)
  ) then
    raise exception 'APPROVAL_NEEDED: Changes to your profile need the super admin''s approval. Use Edit my profile to send them.' using errcode = '42501';
  end if;
  return new;
end;
$$;
revoke execute on function public.guard_profile_changes() from public, anon, authenticated;
drop trigger if exists trg_profiles_guard_changes on public.profiles;
create trigger trg_profiles_guard_changes
  before insert or update on public.profiles
  for each row execute function public.guard_profile_changes();

-- Your own School ID: adding one is fine, changing it needs approval too.
create or replace function public.guard_school_id_changes()
returns trigger
language plpgsql
set search_path = public
as $$
begin
  if current_user not in ('anon', 'authenticated') or old.user_id is distinct from auth.uid()
     or new.school_id is not distinct from old.school_id then
    return new;
  end if;
  if not public.is_super_admin() then
    raise exception 'APPROVAL_NEEDED: Changes to your School ID need the super admin''s approval. Use Edit my profile to send them.' using errcode = '42501';
  end if;
  return new;
end;
$$;
revoke execute on function public.guard_school_id_changes() from public, anon, authenticated;
drop trigger if exists trg_school_ids_guard_changes on public.school_ids;
create trigger trg_school_ids_guard_changes
  before update on public.school_ids
  for each row execute function public.guard_school_id_changes();


-- =====================================================================
-- 2. Event approvals
-- =====================================================================
--   approval_status: null    = no approval needed (moderators' events, older events)
--                    needed  = an admin's event, not sent yet (a draft)
--                    pending = waiting for the super admin (still a draft)
--                    approved / rejected = decided (approval_note: why)
alter table public.events
  add column if not exists approval_status text,
  add column if not exists approval_note text,
  add column if not exists approval_requested_at timestamptz,
  add column if not exists approval_reviewed_by uuid,      -- no FK, so embeds stay unambiguous
  add column if not exists approval_reviewed_at timestamptz;

alter table public.events drop constraint if exists events_approval_status_check;
alter table public.events add constraint events_approval_status_check
  check (approval_status in ('needed', 'pending', 'approved', 'rejected'));
alter table public.events drop constraint if exists events_approval_note_check;
alter table public.events add constraint events_approval_note_check
  check (approval_note is null or char_length(approval_note) <= 500);
comment on column public.events.approval_status is 'null (no approval needed) | needed | pending | approved | rejected — admins'' events need the super admin''s approval';
create index if not exists events_approval_pending_idx on public.events(approval_requested_at) where approval_status = 'pending';

-- Admins (not the super admin) can't publish their own new events: publishing
-- sends them for approval. Only the super admin decides; the approval
-- columns can't be set from the site by anyone else.
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
    new.approval_status := case when public.is_admin() then 'needed' end;
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
drop trigger if exists trg_events_approval on public.events;
create trigger trg_events_approval
  before insert or update on public.events
  for each row execute function public.enforce_event_approval();


-- =====================================================================
-- 3. Profile change requests
-- =====================================================================
create table if not exists public.profile_change_requests (
  id          uuid primary key default gen_random_uuid(),
  user_id     uuid not null references public.profiles(id) on delete cascade,
  changes     jsonb not null,        -- {column: new value}; school_id included for students
  status      text not null default 'pending',
  review_note text,
  reviewed_by uuid,                  -- the super admin (no FK, so embeds stay unambiguous)
  reviewed_at timestamptz,
  created_at  timestamptz not null default now(),
  updated_at  timestamptz not null default now(),
  constraint profile_change_requests_status_check check (status in ('pending', 'approved', 'rejected', 'withdrawn')),
  constraint profile_change_requests_changes_check check (jsonb_typeof(changes) = 'object'),
  constraint profile_change_requests_note_check check (review_note is null or char_length(review_note) <= 500)
);
comment on table public.profile_change_requests is 'Profile edits waiting for (or decided by) the super admin';
-- One request waiting per account (sending again updates it).
create unique index if not exists profile_change_requests_one_pending
  on public.profile_change_requests(user_id) where status = 'pending';
create index if not exists profile_change_requests_status_idx on public.profile_change_requests(status, created_at desc);

drop trigger if exists trg_profile_change_requests_updated_at on public.profile_change_requests;
create trigger trg_profile_change_requests_updated_at
  before update on public.profile_change_requests
  for each row execute function public.set_updated_at();

alter table public.profile_change_requests enable row level security;

drop policy if exists "Own requests visible to their owner and admins" on public.profile_change_requests;
create policy "Own requests visible to their owner and admins" on public.profile_change_requests
  for select to authenticated using (user_id = auth.uid() or public.is_admin());

drop policy if exists "Users send their own profile changes" on public.profile_change_requests;
create policy "Users send their own profile changes" on public.profile_change_requests
  for insert to authenticated
  with check (user_id = auth.uid() and status = 'pending'
              and review_note is null and reviewed_by is null and reviewed_at is null);

-- While it waits, the owner can change or withdraw it. Deciding goes
-- through review_profile_change() only.
drop policy if exists "Users update or withdraw their waiting request" on public.profile_change_requests;
create policy "Users update or withdraw their waiting request" on public.profile_change_requests
  for update to authenticated
  using (user_id = auth.uid() and status = 'pending')
  with check (user_id = auth.uid() and status in ('pending', 'withdrawn')
              and review_note is null and reviewed_by is null and reviewed_at is null);

grant select, insert, update on public.profile_change_requests to authenticated;

-- The super admin approves (the changes are applied) or rejects a request.
create or replace function public.review_profile_change(p_request uuid, p_approve boolean, p_note text default null)
returns void
language plpgsql
security definer
set search_path = public
as $$
declare
  r public.profile_change_requests;
  c jsonb;
begin
  if not public.is_super_admin() then
    raise exception 'SUPER_ADMIN_ONLY: Only the super admin can approve or reject profile changes.' using errcode = '42501';
  end if;
  select * into r from public.profile_change_requests where id = p_request for update;
  if not found or r.status <> 'pending' then
    raise exception 'ALREADY_HANDLED: That request was already handled or withdrawn.';
  end if;

  if p_approve then
    c := r.changes;
    update public.profiles p set
      full_name       = case when c ? 'full_name' then nullif(btrim(c->>'full_name'), '') else p.full_name end,
      account_type    = case when c ? 'account_type' then c->>'account_type' else p.account_type end,
      department_id   = case when c ? 'department_id' then (c->>'department_id')::uuid else p.department_id end,
      organization_id = case when c ? 'organization_id' then (c->>'organization_id')::uuid else p.organization_id end,
      year_level      = case when c ? 'year_level' then (c->>'year_level')::int2 else p.year_level end,
      course          = case when c ? 'course' then nullif(btrim(c->>'course'), '') else p.course end,
      student_type    = case when c ? 'student_type' then c->>'student_type' else p.student_type end,
      irregular_year_levels = case when c ? 'irregular_year_levels' then
          case when jsonb_typeof(c->'irregular_year_levels') = 'array' then
            (select array_agg(distinct x::int2 order by x::int2) from jsonb_array_elements_text(c->'irregular_year_levels') x)
          end
        else p.irregular_year_levels end
    where p.id = r.user_id;

    if nullif(c->>'school_id', '') is not null then
      insert into public.school_ids (user_id, school_id)
      values (r.user_id, upper(regexp_replace(c->>'school_id', '\s', '', 'g')))
      on conflict (user_id) do update
        set school_id = excluded.school_id, verified_at = null, verified_by = null
        where school_ids.school_id is distinct from excluded.school_id;
    end if;
  end if;

  update public.profile_change_requests
     set status = case when p_approve then 'approved' else 'rejected' end,
         review_note = nullif(btrim(coalesce(p_note, '')), ''),
         reviewed_by = auth.uid(),
         reviewed_at = now()
   where id = r.id;
end;
$$;
revoke execute on function public.review_profile_change(uuid, boolean, text) from public, anon;
grant execute on function public.review_profile_change(uuid, boolean, text) to authenticated;

notify pgrst, 'reload schema';
