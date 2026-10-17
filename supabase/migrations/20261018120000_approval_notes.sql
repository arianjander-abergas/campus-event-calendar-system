-- =====================================================================
-- Approval notes — branch manage-events-dropdown
-- (after 20261018090000_three_role_levels.sql)
--   A Super Admin can leave a note on something still waiting for
--   approval (an Admin's event or a profile edit) without deciding yet;
--   the person who sent it sees the note. Events already had
--   approval_note; profile edits get note_profile_change().
--   The sender can still change or withdraw a request that has a note,
--   but can't change the note.
--
-- Run it once in Supabase -> SQL Editor (paste everything, Run).
-- It can be run again safely.
-- =====================================================================

-- A note on a waiting profile edit (Super Admins).
create or replace function public.note_profile_change(p_request uuid, p_note text)
returns void
language plpgsql
security definer
set search_path = public
as $$
begin
  if not public.is_super_admin() then
    raise exception 'SUPER_ADMIN_ONLY: Only a Super Admin can leave notes on profile changes.' using errcode = '42501';
  end if;
  if char_length(coalesce(p_note, '')) > 500 then
    raise exception 'TOO_LONG: Notes can be up to 500 characters.';
  end if;
  update public.profile_change_requests
     set review_note = nullif(btrim(coalesce(p_note, '')), '')
   where id = p_request and status = 'pending';
  if not found then
    raise exception 'ALREADY_HANDLED: That request was already handled or withdrawn.';
  end if;
end;
$$;
revoke execute on function public.note_profile_change(uuid, text) from public, anon;
grant execute on function public.note_profile_change(uuid, text) to authenticated;

-- The sender keeps the Super Admin's note (and decision fields) as they are.
create or replace function public.guard_profile_request_review()
returns trigger
language plpgsql
set search_path = public
as $$
begin
  if current_user in ('anon', 'authenticated') and not public.is_super_admin() then
    new.review_note := old.review_note;
    new.reviewed_by := old.reviewed_by;
    new.reviewed_at := old.reviewed_at;
  end if;
  return new;
end;
$$;
revoke execute on function public.guard_profile_request_review() from public, anon, authenticated;
drop trigger if exists trg_profile_change_requests_guard_review on public.profile_change_requests;
create trigger trg_profile_change_requests_guard_review
  before update on public.profile_change_requests
  for each row execute function public.guard_profile_request_review();

-- A request with a note can still be changed or withdrawn by its sender.
drop policy if exists "Users update or withdraw their waiting request" on public.profile_change_requests;
create policy "Users update or withdraw their waiting request" on public.profile_change_requests
  for update to authenticated
  using (user_id = auth.uid() and status = 'pending')
  with check (user_id = auth.uid() and status in ('pending', 'withdrawn')
              and reviewed_by is null and reviewed_at is null);

-- Deciding keeps an earlier note when no new one is given.
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
    raise exception 'SUPER_ADMIN_ONLY: Only a Super Admin can approve or reject profile changes.' using errcode = '42501';
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
         review_note = coalesce(nullif(btrim(coalesce(p_note, '')), ''), r.review_note),
         reviewed_by = auth.uid(),
         reviewed_at = now()
   where id = r.id;
end;
$$;
revoke execute on function public.review_profile_change(uuid, boolean, text) from public, anon;
grant execute on function public.review_profile_change(uuid, boolean, text) to authenticated;

notify pgrst, 'reload schema';
