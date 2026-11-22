-- =====================================================================
-- "Your event was approved / not approved" notices
-- (after 20261120120000_venues.sql)
--   When a Super Admin approves or rejects an event that was sent for
--   approval, the person who created it gets a notification in the site's
--   bell, and by email (the usual email queue sends it). An approval note,
--   if one was written, is included.
--
-- Run it once in Supabase -> SQL Editor (paste everything, Run).
-- It can be run again safely.
-- =====================================================================

create or replace function public.notify_event_approval()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
  note text := nullif(btrim(coalesce(new.approval_note, '')), '');
begin
  -- Only a decision on an event that was waiting: pending -> approved / rejected.
  if old.approval_status is distinct from 'pending'
     or new.approval_status not in ('approved', 'rejected')
     or new.created_by is null then
    return new;
  end if;
  -- The Super Admin approving their own event doesn't need telling.
  if new.created_by = new.approval_reviewed_by then
    return new;
  end if;

  insert into public.notifications (event_id, user_id, title, message, status, sent_at)
  values (
    new.id,
    new.created_by,
    case when new.approval_status = 'approved'
      then 'Approved: ' || new.title
      else 'Not approved: ' || new.title
    end,
    case when new.approval_status = 'approved'
      then 'Your event ' || new.title || ' was approved by a Super Admin and is now published.'
      else 'Your event ' || new.title || ' wasn''t approved. It stays a draft: change what''s needed and send it for approval again.'
    end || coalesce(' Note from the Super Admin: "' || note || '"', ''),
    'sent',
    now()
  );
  return new;
end;
$$;
revoke execute on function public.notify_event_approval() from public, anon, authenticated;

drop trigger if exists trg_event_approval_notice on public.events;
create trigger trg_event_approval_notice
  after update of approval_status on public.events
  for each row execute function public.notify_event_approval();

-- Lets Database update (config/supabase.php → DB_UPDATES) see this was run.
create or replace function public.approval_notices_ready()
returns boolean
language sql
stable
set search_path = public
as $$ select true $$;
grant execute on function public.approval_notices_ready() to anon, authenticated;

notify pgrst, 'reload schema';
