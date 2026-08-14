/* =========================================================================
   SUPABASE CONFIGURATION
   =========================================================================
   1. Create a free project at https://supabase.com
   2. Go to Project Settings -> API and copy:
        - "Project URL"        -> paste into SUPABASE_URL below
        - "anon public" key    -> paste into SUPABASE_ANON_KEY below
   3. Run the SQL in database-schema.sql (in this folder) inside the
      Supabase SQL Editor. It creates the `events` table and a public
      `event-images` storage bucket for the pictures.
   4. Upload event cover photos into the "event-images" bucket (Storage
      tab), then either:
        a) paste the public file URL into the event's `image_url` column, or
        b) just store the filename in `image_url` and the app will resolve
           it against the bucket automatically (see script.js).

   Until you fill in real values below, the site keeps working using the
   built-in placeholder images and demo data — nothing breaks.
   ========================================================================= */

const SUPABASE_URL = 'YOUR_SUPABASE_PROJECT_URL';       // e.g. https://abcdefghijk.supabase.co
const SUPABASE_ANON_KEY = 'YOUR_SUPABASE_ANON_KEY';      // e.g. eyJhbGciOi...
const SUPABASE_IMAGE_BUCKET = 'event-images';            // storage bucket name

// Exposed globally so script.js can use it without a bundler/import step.
window.SUPABASE_CONFIG = {
  url: SUPABASE_URL,
  anonKey: SUPABASE_ANON_KEY,
  imageBucket: SUPABASE_IMAGE_BUCKET,
  isConfigured:
    SUPABASE_URL !== 'YOUR_SUPABASE_PROJECT_URL' &&
    SUPABASE_ANON_KEY !== 'YOUR_SUPABASE_ANON_KEY' &&
    SUPABASE_URL.length > 0 &&
    SUPABASE_ANON_KEY.length > 0
};
