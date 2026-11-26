-- =====================================================================
-- "Evaluation after the event" — on or off per event
-- (Create Event › 7. Publishing Options)
--   events.has_evaluation (default on: every event so far keeps its
--   evaluation). When it's off, attendees aren't asked to evaluate, the
--   "Evaluate this event" button and reminders don't show, and the
--   database refuses evaluations of that event.
--
-- Run it once in Supabase -> SQL Editor (paste everything, Run).
-- It can be run again safely.
-- =====================================================================

alter table public.events
  add column if not exists has_evaluation boolean not null default true;

create or replace function public.submit_event_evaluation(p_event_id uuid, p_answers jsonb)
returns uuid
language plpgsql
security definer
set search_path to 'public'
as $function$
declare
  uid uuid := auth.uid();
  eval_id uuid;
  q record;
  val text;
begin
  if uid is null then
    raise exception 'NOT_LOGGED_IN: Please log in to evaluate this event.' using errcode = '42501';
  end if;
  if exists (select 1 from public.events e where e.id = p_event_id and not e.has_evaluation) then
    raise exception 'NO_EVALUATION: This event doesn''t have an evaluation.' using errcode = '42501';
  end if;
  if not exists (select 1 from public.event_registrations r
                 where r.event_id = p_event_id and r.user_id = uid and r.status = 'attended') then
    raise exception 'NOT_ATTENDED: Only people marked as attended can evaluate this event.' using errcode = '42501';
  end if;
  if exists (select 1 from public.event_evaluations where event_id = p_event_id and user_id = uid) then
    raise exception 'ALREADY_EVALUATED: You already evaluated this event. Thank you!';
  end if;
  if jsonb_typeof(p_answers) is distinct from 'object' then
    raise exception 'INVALID_ANSWERS: The evaluation form was incomplete. Please try again.';
  end if;

  insert into public.event_evaluations (event_id, user_id) values (p_event_id, uid)
  returning id into eval_id;

  for q in select * from public.evaluation_questions where is_active order by sort_order, created_at loop
    val := btrim(coalesce(p_answers ->> q.id::text, ''));
    if val = '' then
      if q.is_required then
        raise exception 'MISSING_ANSWER: Please answer every required question.';
      end if;
      continue;
    end if;
    if q.question_type = 'rating' then
      if val !~ '^[1-5]$' then
        raise exception 'INVALID_ANSWER: Ratings must be from 1 to 5.';
      end if;
      insert into public.event_evaluation_answers (evaluation_id, question_id, rating)
      values (eval_id, q.id, val::int2);
    else
      insert into public.event_evaluation_answers (evaluation_id, question_id, answer_text)
      values (eval_id, q.id, left(val, 2000));
    end if;
  end loop;

  return eval_id;
end;
$function$;

-- Lets Database update (config/supabase.php → DB_UPDATES) see this was run.
create or replace function public.event_evaluation_toggle_ready()
returns boolean
language sql
immutable
as $$ select true $$;
grant execute on function public.event_evaluation_toggle_ready() to anon, authenticated;

notify pgrst, 'reload schema';
