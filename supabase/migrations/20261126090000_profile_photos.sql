-- =====================================================================
-- Profile pictures (My Profile › Change photo)
--   Everyone can add, change or remove their own profile picture. It shows
--   on My Profile, the top bar, Edit my profile and Users › Edit (initials
--   when there's none). A photo isn't a "detail", so it doesn't need the
--   Super Admin's approval.
--     - New public Storage bucket "profile-photos" (JPG / PNG / WEBP / GIF,
--       up to 5 MB). Each person can only upload, replace and delete files
--       in their own folder (<their account id>/…); Super Admins can delete
--       any (e.g. an inappropriate photo).
--     - profiles.avatar_url may only point at a photo in that account's own
--       folder of this bucket (no outside links).
--
-- Run it once in Supabase -> SQL Editor (paste everything, Run).
-- It can be run again safely.
-- =====================================================================

insert into storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
values ('profile-photos', 'profile-photos', true, 5242880, array['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
on conflict (id) do update
  set public = true, file_size_limit = excluded.file_size_limit, allowed_mime_types = excluded.allowed_mime_types;

drop policy if exists "Profile photos are publicly readable" on storage.objects;
create policy "Profile photos are publicly readable" on storage.objects
  for select using (bucket_id = 'profile-photos');

drop policy if exists "Users upload their own profile photo" on storage.objects;
create policy "Users upload their own profile photo" on storage.objects
  for insert to authenticated
  with check (bucket_id = 'profile-photos' and (storage.foldername(name))[1] = auth.uid()::text);

drop policy if exists "Users replace their own profile photo" on storage.objects;
create policy "Users replace their own profile photo" on storage.objects
  for update to authenticated
  using (bucket_id = 'profile-photos' and (storage.foldername(name))[1] = auth.uid()::text)
  with check (bucket_id = 'profile-photos' and (storage.foldername(name))[1] = auth.uid()::text);

drop policy if exists "Users delete their own profile photo" on storage.objects;
create policy "Users delete their own profile photo" on storage.objects
  for delete to authenticated
  using (bucket_id = 'profile-photos' and ((storage.foldername(name))[1] = auth.uid()::text or public.is_admin()));

-- The picture must be one of this account's own uploads in the bucket.
alter table public.profiles drop constraint if exists profiles_avatar_url_own_photo;
alter table public.profiles add constraint profiles_avatar_url_own_photo check (
  avatar_url is null
  or (avatar_url ~ '^https://[a-z0-9-]+\.supabase\.co/storage/v1/object/public/profile-photos/'
      and position('/profile-photos/' || id::text || '/' in avatar_url) > 0
      and avatar_url !~ '\.\.')
);

-- Lets Database update (config/supabase.php → DB_UPDATES) see this was run.
create or replace function public.profile_photos_ready()
returns boolean
language sql
immutable
as $$ select true $$;
grant execute on function public.profile_photos_ready() to anon, authenticated;

notify pgrst, 'reload schema';
