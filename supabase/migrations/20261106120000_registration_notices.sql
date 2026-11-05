-- =====================================================================
-- "You're registered" notices (after 20261105120000_private_profiles.sql)
--   When someone registers for an event (or registers again after
--   cancelling), they get a notification in the site's bell. The site
--   emails it to them straight away, then marks it emailed with
--   my_notification_emailed() (if that email fails, the usual email queue
--   tries again).
--
-- Run it once in Supabase -> SQL Editor (paste everything, Run).
-- It can be run again safely.
-- =====================================================================

create or replace function public.notify_registration()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
  ev public.events%rowtype;
begin
  if new.status::text <> 'registered' then
    return new;
  end if;
  -- Only a new registration: not an update of one that was already in.
  if tg_op = 'UPDATE' and old.status::text in ('registered', 'attended') then
    return new;
  end if;
  select * into ev from public.events where id = new.event_id;
  if not found then
    return new;
  end if;
  insert into public.notifications (event_id, user_id, title, message, status, sent_at)
  values (
    new.event_id,
    new.user_id,
    'Registered: ' || ev.title,
    'You''re registered for ' || ev.title || '. '
      || to_char(ev.start_date, 'FMMon FMDD, YYYY')
      || coalesce(' · ' || to_char(ev.start_time, 'FMHH12:MI AM'), '')
      || ' · ' || case when ev.is_online then 'Online' else coalesce(nullif(btrim(ev.venue), ''), 'Venue to be announced') end,
    'sent',
    now()
  );
  return new;
end;
$$;
revoke execute on function public.notify_registration() from public, anon, authenticated;

drop trigger if exists trg_registration_notice on public.event_registrations;
create trigger trg_registration_notice
  after insert or update of status on public.event_registrations
  for each row execute function public.notify_registration();

-- The site emailed one of the logged-in person's own notifications itself
-- (their registration notice): take it out of the email queue.
create or replace function public.my_notification_emailed(p_id uuid)
returns boolean
language sql
security definer
set search_path = public
as $$
  with done as (
    update public.notifications
    set email_pending = false, emailed_at = now(), email_attempts = email_attempts + 1
    where id = p_id and user_id = auth.uid() and email_pending
    returning 1
  )
  select exists (select 1 from done);
$$;
revoke all on function public.my_notification_emailed(uuid) from public, anon;
grant execute on function public.my_notification_emailed(uuid) to authenticated;

notify pgrst, 'reload schema';
