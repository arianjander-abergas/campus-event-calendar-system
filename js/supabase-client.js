/**
 * Supabase client — PLACEHOLDER.
 *
 * 1. Include the Supabase JS library in your page:
 *    <script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>
 * 2. Fill in your real project URL + anon key below.
 * 3. Then everything in campus.js that calls `db.*` will start hitting
 *    your real Supabase tables instead of the local mock data.
 */

const SUPABASE_URL = "https://YOUR-PROJECT-REF.supabase.co";
const SUPABASE_ANON_KEY = "YOUR-SUPABASE-ANON-KEY";

let supabaseClient = null;

function getSupabaseClient() {
  if (supabaseClient) return supabaseClient;

  if (typeof window.supabase === "undefined") {
    console.warn(
      "[Supabase] JS library not loaded — add the CDN script tag. Falling back to mock mode."
    );
    return null;
  }

  supabaseClient = window.supabase.createClient(SUPABASE_URL, SUPABASE_ANON_KEY);
  return supabaseClient;
}

const isSupabaseConfigured = () => SUPABASE_URL.startsWith("https://YOUR-PROJECT-REF") === false;

/**
 * Tiny data-access layer used by the pages. Every method tries Supabase
 * first (when configured) and falls back to bundled mock/sample data so
 * the UI never breaks while the backend isn't wired up yet.
 */
const db = {
  async getEvents() {
    if (isSupabaseConfigured()) {
      const client = getSupabaseClient();
      const { data, error } = await client
        .from("events")
        .select("*")
        .order("event_date", { ascending: true });
      if (!error) return data;
      console.error("[Supabase] getEvents error:", error);
    }
    return window.MOCK_EVENTS || [];
  },

  async getAnnouncements() {
    if (isSupabaseConfigured()) {
      const client = getSupabaseClient();
      const { data, error } = await client
        .from("announcements")
        .select("*")
        .order("created_at", { ascending: false });
      if (!error) return data;
      console.error("[Supabase] getAnnouncements error:", error);
    }
    return window.MOCK_ANNOUNCEMENTS || [];
  },

  async registerForEvent(eventId, userId) {
    if (isSupabaseConfigured()) {
      const client = getSupabaseClient();
      const { data, error } = await client
        .from("registrations")
        .insert([{ event_id: eventId, user_id: userId, status: "registered" }]);
      if (!error) return data;
      console.error("[Supabase] registerForEvent error:", error);
    }
    console.info("[Mock mode] Would register user", userId, "for event", eventId);
    return { mock: true };
  },

  async signIn(email, password) {
    if (isSupabaseConfigured()) {
      const client = getSupabaseClient();
      return client.auth.signInWithPassword({ email, password });
    }
    console.info("[Mock mode] Would sign in as", email);
    return { data: null, error: { message: "Supabase not configured (mock mode)." } };
  },
};

window.db = db;
