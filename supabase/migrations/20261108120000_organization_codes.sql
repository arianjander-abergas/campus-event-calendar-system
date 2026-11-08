-- =====================================================================
-- Organization short codes (after 20261106120000_registration_notices.sql)
--   Organizations get an optional short code, like departments already
--   have, so dropdowns can show them the same way:
--     "College of Information Technology and Engineering (CITE)"
--     "University Ambassadors Organization (UAO)"
--   Admins fill it in under Categories & Orgs → Organizations → Edit.
--
-- Run it once in Supabase -> SQL Editor (paste everything, Run).
-- It can be run again safely.
-- =====================================================================

alter table public.organizations add column if not exists code text;

comment on column public.organizations.code is 'Short label shown after the name, e.g. UAO';

-- Let the site see the new column straight away.
notify pgrst, 'reload schema';
