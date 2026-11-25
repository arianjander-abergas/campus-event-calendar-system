-- =====================================================================
-- More than one organization per account
-- (after 20261125130000_name_parts.sql — run that one first)
--   Sign-up, Edit my profile and Users › Edit keep the one required
--   Organization (the main one: an Admin's events, officers and reports
--   still follow it) and add "Other organizations" — any number, from any
--   department. My Profile and the Users / Roles lists show them, and the
--   Organization filter on Users / Roles finds members of either kind.
--     - New column profiles.other_organization_ids uuid[] (empty = none).
--       Kept tidy by a trigger: no duplicates, never the main organization,
--       only organizations that exist. A deleted organization is taken off
--       everyone's list.
--     - New accounts: the list comes from the sign-up form.
--     - Profile change requests and their approval carry it too, and
--       changing your own list (once it has something in it) needs the
--       Super Admin's approval, like the other details.
--
-- Run it once in Supabase -> SQL Editor (paste everything, Run).
-- It can be run again safely.
-- =====================================================================

do $$
begin
  if not exists (select 1 from information_schema.columns
                 where table_schema = 'public' and table_name = 'profiles' and column_name = 'last_name') then
    raise exception 'Run 20261125130000_name_parts.sql first, then this one.';
  end if;
end;
$$;

alter table public.profiles
  add column if not exists other_organization_ids uuid[] not null default '{}'::uuid[];

-- ---- Keep the list tidy ----
create or replace function public.tidy_other_organizations()
returns trigger
language plpgsql
security definer
set search_path = public
as $function$
begin
  new.other_organization_ids := coalesce((
    select array_agg(o.id order by o.name)
    from public.organizations o
    where o.id = any (coalesce(new.other_organization_ids, '{}'::uuid[]))
      and o.id is distinct from new.organization_id
  ), '{}'::uuid[]);
  return new;
end;
$function$;

drop trigger if exists trg_profiles_tidy_other_orgs on public.profiles;
create trigger trg_profiles_tidy_other_orgs
  before insert or update of other_organization_ids, organization_id on public.profiles
  for each row execute function public.tidy_other_organizations();

-- A deleted organization comes off everyone's list.
create or replace function public.drop_deleted_other_organization()
returns trigger
language plpgsql
security definer
set search_path = public
as $function$
begin
  update public.profiles
     set other_organization_ids = array_remove(other_organization_ids, old.id)
   where old.id = any (other_organization_ids);
  return old;
end;
$function$;

drop trigger if exists trg_organizations_drop_other on public.organizations;
create trigger trg_organizations_drop_other
  after delete on public.organizations
  for each row execute function public.drop_deleted_other_organization();

-- ---- New accounts: the list from the sign-up form ----
create or replace function public.handle_new_user()
returns trigger
language plpgsql
security definer
set search_path = public
as $function$
declare
  meta jsonb := coalesce(new.raw_user_meta_data, '{}'::jsonb);
  v_type text;
  v_org uuid;
  v_dept uuid;
  v_levels int2[];
  v_others uuid[];
  v_school text;
  v_first text := coalesce(nullif(btrim(meta->>'first_name'), ''), nullif(btrim(meta->>'given_name'), ''));
  v_middle text := nullif(btrim(meta->>'middle_name'), '');
  v_last text := coalesce(nullif(btrim(meta->>'last_name'), ''), nullif(btrim(meta->>'family_name'), ''));
begin
  v_type := case when meta->>'account_type' in ('student', 'faculty') then meta->>'account_type' end;
  select o.id into v_org from public.organizations o where o.id::text = meta->>'organization_id';
  select d.id into v_dept from public.departments d where d.id::text = meta->>'department_id';
  if v_dept is null and v_org is not null then
    select o.department_id into v_dept from public.organizations o where o.id = v_org;
  end if;
  if jsonb_typeof(meta->'irregular_year_levels') = 'array' then
    select array_agg(distinct x::int2 order by x::int2) into v_levels
    from jsonb_array_elements_text(meta->'irregular_year_levels') x where x ~ '^[1-5]$';
  end if;
  if jsonb_typeof(meta->'other_organization_ids') = 'array' then
    select array_agg(o.id) into v_others
    from public.organizations o
    where o.id::text in (select jsonb_array_elements_text(meta->'other_organization_ids'));
  end if;
  v_school := upper(regexp_replace(coalesce(meta->>'school_id', ''), '\s', '', 'g'));

  insert into public.profiles (id, full_name, last_name, first_name, middle_name, role, year_level, course,
                               organization_id, other_organization_ids, department_id, account_type, student_type, irregular_year_levels)
  values (
    new.id,
    coalesce(nullif(trim(meta->>'full_name'), ''), nullif(trim(meta->>'name'), ''),
             nullif(btrim(concat_ws(' ', v_first, v_middle, v_last)), ''), new.email),
    v_last, v_first, v_middle,
    'user',
    case when v_type is distinct from 'faculty' and (meta->>'year_level') ~ '^[1-5]$' then (meta->>'year_level')::int2 end,
    case when v_type is distinct from 'faculty' then nullif(trim(meta->>'course'), '') end,
    v_org, coalesce(v_others, '{}'::uuid[]), v_dept, v_type,
    case when v_type = 'student' and meta->>'student_type' in ('regular', 'irregular') then meta->>'student_type' end,
    case when v_type = 'student' and meta->>'student_type' = 'irregular' then v_levels end
  )
  on conflict (id) do nothing;

  -- A taken or badly formatted ID is skipped; the profile form asks again.
  if v_type = 'student' and v_school ~ '^[A-Z0-9][A-Z0-9-]{3,19}$' then
    insert into public.school_ids (user_id, school_id) values (new.id, v_school) on conflict do nothing;
  end if;
  return new;
end;
$function$;

-- ---- Approving a profile change: the other organizations too ----
create or replace function public.review_profile_change(p_request uuid, p_approve boolean, p_note text default null)
returns void
language plpgsql
security definer
set search_path = public
as $function$
declare
  r public.profile_change_requests;
  c jsonb;
begin
  if not public.is_super_admin() then
    raise exception 'SUPER_ADMIN_ONLY: Only a Super Admin can approve or reject profile changes.' using errcode = '42501';
  end if;
  select * into r from public.profile_change_requests where id = p_request for update;
  if not found or r.status <> 'pending' then
    raise exception 'ALREADY_HANDLED: That request was already handled or withdrawn.';
  end if;

  if p_approve then
    c := r.changes;
    update public.profiles p set
      full_name = case when c ? 'full_name' then nullif(btrim(c->>'full_name'), '') else p.full_name end,
      last_name = case when c ? 'last_name' then nullif(btrim(c->>'last_name'), '') else p.last_name end,
      first_name = case when c ? 'first_name' then nullif(btrim(c->>'first_name'), '') else p.first_name end,
      middle_name = case when c ? 'middle_name' then nullif(btrim(c->>'middle_name'), '') else p.middle_name end,
      account_type = case when c ? 'account_type' then c->>'account_type' else p.account_type end,
      department_id = case when c ? 'department_id' then (c->>'department_id')::uuid else p.department_id end,
      organization_id = case when c ? 'organization_id' then (c->>'organization_id')::uuid else p.organization_id end,
      other_organization_ids = case when c ? 'other_organization_ids' then
          case when jsonb_typeof(c->'other_organization_ids') = 'array' then
            coalesce((select array_agg(x::uuid) from jsonb_array_elements_text(c->'other_organization_ids') x
                      where x ~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'), '{}'::uuid[])
          else '{}'::uuid[] end
        else p.other_organization_ids end,
      year_level = case when c ? 'year_level' then (c->>'year_level')::int2 else p.year_level end,
      course = case when c ? 'course' then nullif(btrim(c->>'course'), '') else p.course end,
      student_type = case when c ? 'student_type' then c->>'student_type' else p.student_type end,
      irregular_year_levels = case when c ? 'irregular_year_levels' then
          case when jsonb_typeof(c->'irregular_year_levels') = 'array' then
            (select array_agg(distinct x::int2 order by x::int2) from jsonb_array_elements_text(c->'irregular_year_levels') x) end
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
         review_note = coalesce(nullif(btrim(coalesce(p_note, '')), ''), r.review_note),
         reviewed_by = auth.uid(),
         reviewed_at = now()
   where id = r.id;
end;
$function$;

-- ---- Changing your own list (once it has something) needs approval ----
create or replace function public.guard_profile_changes()
returns trigger
language plpgsql
set search_path = public
as $function$
begin
  if current_user not in ('anon', 'authenticated') then return new; end if;
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
  if old.id is distinct from auth.uid() or public.is_super_admin() then return new; end if;
  if ( (nullif(btrim(old.full_name), '') is not null and new.full_name is distinct from old.full_name)
    or (nullif(btrim(old.last_name), '') is not null and new.last_name is distinct from old.last_name)
    or (nullif(btrim(old.first_name), '') is not null and new.first_name is distinct from old.first_name)
    or (nullif(btrim(old.middle_name), '') is not null and new.middle_name is distinct from old.middle_name)
    or (old.account_type is not null and new.account_type is distinct from old.account_type)
    or (old.department_id is not null and new.department_id is distinct from old.department_id)
    or (old.organization_id is not null and new.organization_id is distinct from old.organization_id)
    or (coalesce(cardinality(old.other_organization_ids), 0) > 0
        and (select coalesce(array_agg(x order by x), '{}') from unnest(new.other_organization_ids) x)
            is distinct from (select coalesce(array_agg(x order by x), '{}') from unnest(old.other_organization_ids) x))
    or (old.year_level is not null and new.year_level is distinct from old.year_level)
    or (nullif(btrim(old.course), '') is not null and new.course is distinct from old.course)
    or (old.student_type is not null and new.student_type is distinct from old.student_type)
    or (coalesce(cardinality(old.irregular_year_levels), 0) > 0 and new.irregular_year_levels is distinct from old.irregular_year_levels) ) then
    raise exception 'APPROVAL_NEEDED: Changes to your profile need the super admin''s approval. Use Edit my profile to send them.' using errcode = '42501';
  end if;
  return new;
end;
$function$;

-- Lets Database update (config/supabase.php → DB_UPDATES) see this was run.
create or replace function public.profile_other_organizations_ready()
returns boolean
language sql
immutable
as $$ select true $$;
grant execute on function public.profile_other_organizations_ready() to anon, authenticated;

notify pgrst, 'reload schema';
