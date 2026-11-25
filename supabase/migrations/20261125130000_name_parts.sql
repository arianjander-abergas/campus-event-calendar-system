-- =====================================================================
-- Names in three parts: last name, first name, middle name
-- (after 20261122120000_approval_notices.sql)
--   Sign-up, My Profile, Edit my profile and Users › Edit ask for Last
--   name, First name and Middle name. profiles.full_name stays ("First
--   Middle Last") and is still what the rest of the site shows; the site
--   writes it together with the parts.
--     - New columns profiles.last_name, first_name, middle_name.
--     - Existing accounts: their full name is split once (the last word is
--       the last name, with "Dela", "De La", "San"… and "Jr.", "III"… kept
--       with it; an initial like "S." is the middle name; the rest is the
--       first name). full_name itself isn't changed. Anything split wrong
--       can be fixed in Edit my profile / Users › Edit.
--     - New accounts: the parts come from the sign-up form (or Google's
--       given / family name).
--     - Profile change requests and their approval carry the parts too, and
--       changing your own name parts needs the Super Admin's approval, like
--       the full name.
--
-- Run it once in Supabase -> SQL Editor (paste everything, Run).
-- It can be run again safely.
-- =====================================================================

alter table public.profiles
  add column if not exists last_name text,
  add column if not exists first_name text,
  add column if not exists middle_name text;

-- ---- Split the existing full names (only accounts with no parts yet) ----
do $$
declare
  r record;
  w text[];
  n int;
  i int;
  v_last text;
  v_first text;
  v_middle text;
  particles constant text[] := array['de', 'dela', 'del', 'delos', 'la', 'las', 'los', 'san', 'santa', 'sta', 'sta.', 'sto', 'sto.', 'santo', 'van', 'von', 'di', 'da', 'mac', 'mc'];
  suffixes constant text[] := array['jr', 'jr.', 'sr', 'sr.', 'ii', 'iii', 'iv', 'v'];
begin
  for r in select id, full_name from public.profiles
           where last_name is null and first_name is null and middle_name is null
             and nullif(btrim(full_name), '') is not null and full_name !~ '@'
  loop
    w := regexp_split_to_array(btrim(r.full_name), '\s+');
    n := array_length(w, 1);
    if n = 1 then
      update public.profiles set first_name = w[1] where id = r.id;
      continue;
    end if;
    -- Last name: the last word (plus a suffix before it, e.g. "Cruz Jr."), and any particles before it.
    i := n;
    if lower(w[i]) = any (suffixes) and i > 2 then i := i - 1; end if;
    while i > 2 and lower(w[i - 1]) = any (particles) loop i := i - 1; end loop;
    v_last := array_to_string(w[i:n], ' ');
    -- Middle name: an initial just before the last name ("S." / "S").
    v_middle := null;
    if i > 2 and w[i - 1] ~ '^[A-Za-z]\.?$' then
      v_middle := w[i - 1];
      i := i - 1;
    end if;
    v_first := array_to_string(w[1:i - 1], ' ');
    update public.profiles
       set last_name = v_last, first_name = nullif(v_first, ''), middle_name = v_middle
     where id = r.id;
  end loop;
end;
$$;

-- ---- New accounts: the parts from the sign-up form (or Google) ----
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
  v_school := upper(regexp_replace(coalesce(meta->>'school_id', ''), '\s', '', 'g'));

  insert into public.profiles (id, full_name, last_name, first_name, middle_name, role, year_level, course,
                               organization_id, department_id, account_type, student_type, irregular_year_levels)
  values (
    new.id,
    coalesce(nullif(trim(meta->>'full_name'), ''), nullif(trim(meta->>'name'), ''),
             nullif(btrim(concat_ws(' ', v_first, v_middle, v_last)), ''), new.email),
    v_last, v_first, v_middle,
    'user',
    case when v_type is distinct from 'faculty' and (meta->>'year_level') ~ '^[1-5]$' then (meta->>'year_level')::int2 end,
    case when v_type is distinct from 'faculty' then nullif(trim(meta->>'course'), '') end,
    v_org, v_dept, v_type,
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

-- ---- Approving a profile change: the name parts too ----
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

-- ---- Changing your own name parts needs approval, like the full name ----
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
create or replace function public.profile_names_ready()
returns boolean
language sql
immutable
as $$ select true $$;
grant execute on function public.profile_names_ready() to anon, authenticated;

notify pgrst, 'reload schema';
