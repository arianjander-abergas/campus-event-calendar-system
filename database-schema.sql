-- =========================================================================
-- Campus Event Hub — Supabase schema
-- Run this whole file in: Supabase Dashboard -> SQL Editor -> New query
-- =========================================================================

-- 1. EVENTS TABLE ---------------------------------------------------------
create table if not exists public.events (
  id            uuid primary key default gen_random_uuid(),
  title         text not null,
  badge_type    text not null default 'seminar' check (badge_type in ('seminar', 'event')),
  event_date    text not null default 'Month 00 0000',   -- display string, e.g. "Aug 20 2026"
  location      text not null default 'AVR 1, CITE Building',
  slots_text    text not null default '000 slots',
  image_url     text,             -- public Storage URL, or just a filename in the event-images bucket
  is_upcoming   boolean not null default false,   -- shown in the "Upcoming Today" sidebar
  sort_order    int not null default 0,
  created_at    timestamptz not null default now()
);

-- Keep the grid/sidebar ordering stable and predictable
create index if not exists events_sort_idx on public.events (sort_order, created_at);

-- 2. ROW LEVEL SECURITY ----------------------------------------------------
alter table public.events enable row level security;

-- Public (anon) read access — anyone visiting the site can view events
create policy if not exists "Public can read events"
  on public.events for select
  using (true);

-- NOTE: no insert/update/delete policy is created for the anon role on
-- purpose. Manage events from the Supabase Table Editor (as an authenticated
-- admin) or add an authenticated-only policy later once you build a login
-- flow, e.g.:
--   create policy "Admins can manage events" on public.events
--     for all using (auth.role() = 'authenticated');

-- 3. STORAGE BUCKET FOR IMAGES ---------------------------------------------
insert into storage.buckets (id, name, public)
values ('event-images', 'event-images', true)
on conflict (id) do nothing;

-- Allow public read of files in the bucket (needed so <img> tags can load them)
create policy if not exists "Public can view event images"
  on storage.objects for select
  using (bucket_id = 'event-images');

-- 4. SAMPLE DATA (matches the current placeholder cards — replace freely) --
insert into public.events (title, badge_type, event_date, location, slots_text, image_url, is_upcoming, sort_order)
values
  ('JPSSITE PACE LEVEL UP v.6.2', 'seminar', 'Month 00 0000', 'AVR 1, CITE Building', '000 slots', null, false, 1),
  ('OSH Training or SIES-ACpEs',  'seminar', 'Month 00 0000', 'AVR 1, CITE Building', '000 slots', null, false, 2),
  ('GEN Z Night 2026',            'event',   'Month 00 0000', 'AVR 1, CITE Building', '000 slots', null, true,  3),
  ('JPSSITE TALK MISINFORMATION', 'seminar', 'Month 00 0000', 'AVR 1, CITE Building', '000 slots', null, false, 4),
  ('JPSSITE LIVE BAND UPSTATE',   'event',   'Month 00 0000', 'AVR 1, CITE Building', '000 slots', null, true,  5),
  ('TAWAG NG JPSSITE',            'event',   'Month 00 0000', 'AVR 1, CITE Building', '000 slots', null, true,  6)
on conflict do nothing;

-- After running this, go to Storage -> event-images and upload real photos,
-- then update each row's image_url (Table Editor) to the file's public URL
-- or just its filename, e.g. "genz-night.jpg".
