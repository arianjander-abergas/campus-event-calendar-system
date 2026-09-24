# Authentication — what changed & how to finish setup

## New/changed files
- `config/supabase.php` — added `supabase_auth_signup()`, `supabase_auth_signin()`,
  `supabase_auth_signout()`, `create_profile()`, `ensure_profile_exists()`,
  `require_login()`. `current_user()` now reads the real session instead of
  returning a hardcoded "Juan Dela Cruz". Also synced the project URL/anon key
  to the real project already used in `js/supabase-client.js` (previously
  `config/supabase.php` was still pointing at the placeholder, so every page
  was silently rendering mock data).
- `login.php` — new. Email/password sign-in form.
- `register.php` — new. Sign-up form (full name, email, password).
- `logout.php` — new. Clears the Supabase session + PHP session.
- `dashboard.php` — now calls `require_login()`, which redirects to
  `login.php` if nobody's signed in. Everything else is unchanged.
- `includes/header.php` — shows "LOGIN" when logged out, or the user's name
  + "LOG OUT" when logged in.
- `css/style.css` — added `.auth-*` styles for the login/register cards.

## How the auth flow works
Supabase Auth (GoTrue) is called directly from PHP via cURL — no JS SDK
needed for auth, since the app is server-rendered. On successful
login/signup, the access token + user info are stored in `$_SESSION`
(mirrors what `current_user()` already expected).

Each user also gets a row in `profiles` (full_name, role) created the
moment they sign up — that's what powers `dashboard.php`'s avatar, name,
and role display.

## One-time setup you still need to do in the Supabase dashboard
1. **Auth → Providers → Email** — make sure the Email provider is enabled.
   If you want to skip email-confirmation while testing, turn off
   "Confirm email" here (turn it back on before your defense/production).
2. **Table editor / SQL editor → RLS policies on `profiles`** — add:
   ```sql
   create policy "Users can insert own profile"
     on profiles for insert
     with check (auth.uid() = id);

   create policy "Profiles are viewable by everyone"
     on profiles for select
     using (true);

   create policy "Users can update own profile"
     on profiles for update
     using (auth.uid() = id)
     with check (auth.uid() = id);
   ```
   Without the insert policy, sign-up will succeed in Auth but silently
   fail to create the profile row (dashboard would then show a blank name).
3. Confirm the `profiles` table exists with columns: `id uuid primary key
   references auth.users(id)`, `full_name text`, `role text`, `avatar_url text`.

## Testing locally
1. Start XAMPP/Apache, drop this folder in `htdocs`.
2. Visit `register.php`, create an account.
3. If email confirmation is on, check the inbox and confirm before logging in.
4. Visit `dashboard.php` directly while logged out — it should bounce you
   to `login.php?redirect=dashboard.php`, and land you back on the
   dashboard after logging in.
5. Click "LOG OUT" in the header — session should clear and you're
   redirected to `index.php`.

## Not yet built (good next steps for Chapter 4)
- Password reset / "forgot password" flow.
- Role-based access (e.g. admin-only pages for JPSSITE/SIES/ACpES officers
  posting events) — `profiles.role` is already there to support this.
- Wiring `db.registerForEvent()` (in `js/main.js`) to a real event ID and
  the logged-in user's ID from the PHP session.
