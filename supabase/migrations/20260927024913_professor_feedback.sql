-- =====================================================================
-- Professor's feedback (Sept 2026)
--   1. Departments (organizations belong to one) ... report filter
--   2. School calendar (school years + semesters) ... report periods
--   3. Events: audience (students / faculty) + venue type (on-campus,
--      off-campus, online)
--   4. Registrations: time the attendee was marked Attended
--   5. Event evaluation: one standard form for every event
--   6. event_report view: one row per event with everything the
--      dashboard / reports need
-- Additive only: every existing column, rule and page keeps working.
-- =====================================================================


-- =====================================================================
-- 1. Departments
-- =====================================================================
create table if not exists public.departments (
  id          uuid primary key default extensions.uuid_generate_v4(),
  name        text not null unique,
  code        text unique,                -- short label, e.g. 'IT'
  description text,
  created_at  timestamptz not null default now(),
  updated_at  timestamptz not null default now()
);
comment on table public.departments is 'Academic departments. Each organization belongs to one; reports can be filtered by department.';

create trigger trg_departments_updated_at
  before update on public.departments
  for each row execute function public.set_updated_at();

alter table public.departments enable row level security;
create policy "Departments are viewable by everyone" on public.departments
  for select using (true);
create policy "Admins manage departments" on public.departments
  for all using (public.is_admin()) with check (public.is_admin());

-- Can't delete a department while organizations still use it (same rule as categories).
alter table public.organizations
  add column if not exists department_id uuid references public.departments(id) on delete restrict;
create index if not exists organizations_department_idx on public.organizations(department_id);

-- Starting departments, taken from the current organizations' descriptions.
insert into public.departments (name, code) values
  ('Information Technology', 'IT'),
  ('Industrial Engineering', 'IE'),
  ('Computer Engineering', 'CpE')
on conflict (name) do nothing;

update public.organizations o set department_id = d.id
from public.departments d
where o.department_id is null
  and ((o.name ilike 'JPSSITE%' and d.code = 'IT')
    or (o.name ilike 'SIES%'    and d.code = 'IE')
    or (o.name ilike 'ACpES%'   and d.code = 'CpE'));


-- =====================================================================
-- 2. School calendar
-- =====================================================================
create table if not exists public.academic_terms (
  id          uuid primary key default extensions.uuid_generate_v4(),
  school_year text not null,              -- e.g. '2026-2027'
  semester    text not null,              -- '1st' | '2nd' | 'summer'
  start_date  date not null,
  end_date    date not null,
  created_at  timestamptz not null default now(),
  updated_at  timestamptz not null default now(),
  constraint academic_terms_school_year_format check (
    school_year ~ '^\d{4}-\d{4}$'
    and split_part(school_year, '-', 2)::int = split_part(school_year, '-', 1)::int + 1),
  constraint academic_terms_semester_check check (semester in ('1st', '2nd', 'summer')),
  constraint academic_terms_dates_check check (end_date >= start_date),
  constraint academic_terms_one_per_semester unique (school_year, semester),
  -- No two terms may share a day.
  constraint academic_terms_no_overlap exclude using gist (daterange(start_date, end_date, '[]') with &&)
);
comment on table public.academic_terms is 'Dates of each semester. Events are counted in the term their start date falls in; dates outside every term use the default calendar in school_period().';

create trigger trg_academic_terms_updated_at
  before update on public.academic_terms
  for each row execute function public.set_updated_at();

alter table public.academic_terms enable row level security;
create policy "School calendar is viewable by everyone" on public.academic_terms
  for select using (true);
create policy "Admins manage the school calendar" on public.academic_terms
  for all using (public.is_admin()) with check (public.is_admin());

insert into public.academic_terms (school_year, semester, start_date, end_date) values
  ('2026-2027', '1st',    '2026-08-01', '2026-12-31'),
  ('2026-2027', '2nd',    '2027-01-01', '2027-05-31'),
  ('2026-2027', 'summer', '2027-06-01', '2027-07-31')
on conflict (school_year, semester) do nothing;

-- School year + semester of a date: the admin-defined term that contains
-- it, or else the default calendar (school year starts in August;
-- 1st sem Aug-Dec, 2nd sem Jan-May, summer Jun-Jul).
create or replace function public.school_period(p_date date)
returns table (school_year text, semester text)
language sql
stable
set search_path = public
as $$
  select x.school_year, x.semester
  from (
    select t.school_year, t.semester, 1 as priority
    from public.academic_terms t
    where p_date between t.start_date and t.end_date
    union all
    select
      case when extract(month from p_date) >= 8
           then extract(year from p_date)::int || '-' || (extract(year from p_date)::int + 1)
           else (extract(year from p_date)::int - 1) || '-' || extract(year from p_date)::int
      end,
      case when extract(month from p_date) >= 8 then '1st'
           when extract(month from p_date) <= 5 then '2nd'
           else 'summer'
      end,
      2
  ) x
  order by x.priority
  limit 1;
$$;


-- =====================================================================
-- 3. Event audience + venue type
-- =====================================================================
create type public.event_audience as enum ('students', 'faculty', 'all');
create type public.event_venue_type as enum ('on_campus', 'off_campus', 'online');

alter table public.events
  add column if not exists audience public.event_audience not null default 'students',
  add column if not exists venue_type public.event_venue_type not null default 'on_campus';
comment on column public.events.audience is 'Who the event is for: students, faculty, or all (students & faculty)';
comment on column public.events.venue_type is 'on_campus | off_campus | online (webinar). Kept in step with is_online by trg_events_venue_type.';

update public.events set venue_type = 'online' where is_online;

-- is_online stays the source of truth for "online", so pages written
-- before venue_type existed still categorize events correctly.
create or replace function public.sync_event_venue_type()
returns trigger
language plpgsql
set search_path = public
as $$
begin
  if new.is_online then
    new.venue_type := 'online';
  elsif new.venue_type = 'online' then
    new.venue_type := 'on_campus';
  end if;
  return new;
end;
$$;

create trigger trg_events_venue_type
  before insert or update on public.events
  for each row execute function public.sync_event_venue_type();

create index if not exists idx_events_audience on public.events(audience);
create index if not exists idx_events_venue_type on public.events(venue_type);


-- =====================================================================
-- 4. Attendance time
-- =====================================================================
alter table public.event_registrations add column if not exists attended_at timestamptz;
comment on column public.event_registrations.attended_at is 'When an admin marked the person Attended (shown on the printed attendance report)';

create or replace function public.set_attended_at()
returns trigger
language plpgsql
set search_path = public
as $$
begin
  if new.status = 'attended' then
    if tg_op = 'INSERT' or old.status is distinct from 'attended' then
      new.attended_at := now();
    end if;
  else
    new.attended_at := null;
  end if;
  return new;
end;
$$;

create trigger trg_event_registrations_attended_at
  before insert or update on public.event_registrations
  for each row execute function public.set_attended_at();


-- =====================================================================
-- 5. Event evaluation — ONE standard form used for every event
-- =====================================================================
create table if not exists public.evaluation_questions (
  id            uuid primary key default extensions.uuid_generate_v4(),
  section       text not null default 'General',
  question      text not null,
  question_type text not null default 'rating' check (question_type in ('rating', 'text')),
  is_required   boolean not null default true,
  sort_order    int4 not null default 0,
  is_active     boolean not null default true,   -- hidden questions keep their past answers
  created_at    timestamptz not null default now(),
  updated_at    timestamptz not null default now()
);
comment on table public.evaluation_questions is 'The event evaluation form (same for every event). rating = 1-5 scale; text = written answer.';
create index if not exists evaluation_questions_order_idx on public.evaluation_questions(sort_order);

create trigger trg_evaluation_questions_updated_at
  before update on public.evaluation_questions
  for each row execute function public.set_updated_at();

create table if not exists public.event_evaluations (
  id           uuid primary key default extensions.uuid_generate_v4(),
  event_id     uuid not null references public.events(id) on delete cascade,
  user_id      uuid not null references public.profiles(id) on delete cascade,
  submitted_at timestamptz not null default now(),
  constraint event_evaluations_one_per_person unique (event_id, user_id)
);
comment on table public.event_evaluations is 'One submitted evaluation per attendee per event (created only through submit_event_evaluation()).';
create index if not exists event_evaluations_user_idx on public.event_evaluations(user_id);

create table if not exists public.event_evaluation_answers (
  evaluation_id uuid not null references public.event_evaluations(id) on delete cascade,
  question_id   uuid not null references public.evaluation_questions(id) on delete restrict,
  rating        int2 check (rating between 1 and 5),
  answer_text   text check (char_length(answer_text) <= 2000),
  primary key (evaluation_id, question_id),
  constraint event_evaluation_answers_has_value check (rating is not null or answer_text is not null)
);
create index if not exists event_evaluation_answers_question_idx on public.event_evaluation_answers(question_id);

alter table public.evaluation_questions enable row level security;
alter table public.event_evaluations enable row level security;
alter table public.event_evaluation_answers enable row level security;

create policy "Evaluation form is viewable by everyone" on public.evaluation_questions
  for select using (true);
create policy "Admins manage the evaluation form" on public.evaluation_questions
  for all using (public.is_admin()) with check (public.is_admin());

-- No insert/update policies: evaluations are written only by
-- submit_event_evaluation(), which checks attendance first.
create policy "Users view their own evaluations" on public.event_evaluations
  for select using ((user_id = auth.uid()) or public.is_admin());
create policy "Admins delete evaluations" on public.event_evaluations
  for delete using (public.is_admin());
create policy "Answers viewable with their evaluation" on public.event_evaluation_answers
  for select using (exists (select 1 from public.event_evaluations ev where ev.id = event_evaluation_answers.evaluation_id));

-- Default form. Replace it with the school's official evaluation form
-- from Admin -> Evaluation Form.
insert into public.evaluation_questions (section, question, question_type, is_required, sort_order)
select * from (values
  ('Program / Activity',          'The objectives of the activity were clearly explained.',            'rating', true,  1),
  ('Program / Activity',          'The activity was relevant and useful to me.',                       'rating', true,  2),
  ('Program / Activity',          'The activity was well organized and followed the program.',         'rating', true,  3),
  ('Resource Speaker / Facilitator', 'The speaker or facilitator showed mastery of the topic.',       'rating', true,  4),
  ('Resource Speaker / Facilitator', 'The speaker or facilitator explained ideas clearly and engaged the participants.', 'rating', true, 5),
  ('Venue, Time & Logistics',     'The venue or online platform was comfortable and suitable.',        'rating', true,  6),
  ('Venue, Time & Logistics',     'The activity started and ended on time.',                           'rating', true,  7),
  ('Venue, Time & Logistics',     'The materials, sound system and equipment were adequate.',          'rating', true,  8),
  ('Overall',                     'Overall, how satisfied are you with the activity?',                 'rating', true,  9),
  ('Comments',                    'What did you like most about the activity?',                        'text',   false, 10),
  ('Comments',                    'What can be improved for future activities?',                       'text',   false, 11)
) as q(section, question, question_type, is_required, sort_order)
where not exists (select 1 from public.evaluation_questions);

-- Submit the logged-in user's evaluation of an event, all answers at once.
-- p_answers: {"<question uuid>": 1-5 or "text", ...}
create or replace function public.submit_event_evaluation(p_event_id uuid, p_answers jsonb)
returns uuid
language plpgsql
security definer            -- writes rows the user can't insert directly
set search_path = public
as $$
declare
  uid uuid := auth.uid();
  eval_id uuid;
  q record;
  val text;
begin
  if uid is null then
    raise exception 'NOT_LOGGED_IN: Please log in to evaluate this event.' using errcode = '42501';
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
$$;


-- =====================================================================
-- 6. Report view: one row per event (drafts left out)
--    security_invoker: the caller's own RLS rights apply, so only admins
--    see real registration / evaluation totals.
-- =====================================================================
create or replace view public.event_report
with (security_invoker = true)
as
select
  e.id,
  e.title,
  e.status,
  e.start_date,
  e.end_date,
  e.start_time,
  e.end_time,
  e.venue,
  e.event_type,
  e.audience,
  e.venue_type,
  e.category_id,
  c.name                    as category_name,
  e.organization_id,
  o.name                    as organization_name,
  o.logo_url                as organization_logo_url,
  o.department_id,
  d.name                    as department_name,
  d.code                    as department_code,
  p.school_year,
  p.semester,
  coalesce(r.signed_up, 0)  as registered_count,   -- everyone who signed up and didn't cancel
  coalesce(r.attended, 0)   as attended_count,
  coalesce(r.no_show, 0)    as no_show_count,
  coalesce(ev.responses, 0) as evaluation_count,
  ev.average_rating
from public.events e
left join public.categories c on c.id = e.category_id
left join public.organizations o on o.id = e.organization_id
left join public.departments d on d.id = o.department_id
cross join lateral public.school_period(e.start_date) p
left join lateral (
  select count(*) filter (where rr.status in ('registered', 'attended', 'no_show')) as signed_up,
         count(*) filter (where rr.status = 'attended')                             as attended,
         count(*) filter (where rr.status = 'no_show')                              as no_show
  from public.event_registrations rr
  where rr.event_id = e.id
) r on true
left join lateral (
  select count(distinct x.id) as responses,
         round(avg(a.rating), 2) as average_rating
  from public.event_evaluations x
  left join public.event_evaluation_answers a on a.evaluation_id = x.id
  where x.event_id = e.id
) ev on true
where e.status <> 'draft';

comment on view public.event_report is 'One row per non-draft event with department, school year / semester, registration, attendance and evaluation totals (Admin Dashboard + Reports).';


-- =====================================================================
-- Access
-- =====================================================================
grant select on public.departments, public.academic_terms, public.evaluation_questions,
                public.event_evaluations, public.event_evaluation_answers, public.event_report
  to anon, authenticated;
grant insert, update, delete on public.departments, public.academic_terms, public.evaluation_questions
  to authenticated;                         -- RLS still limits these to admins
grant delete on public.event_evaluations to authenticated;

revoke all on function public.submit_event_evaluation(uuid, jsonb) from public, anon;
grant execute on function public.submit_event_evaluation(uuid, jsonb) to authenticated;
-- Trigger-only functions: nobody calls them through the API.
revoke execute on function public.sync_event_venue_type() from public, anon, authenticated;
revoke execute on function public.set_attended_at() from public, anon, authenticated;
