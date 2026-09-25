const SUPABASE_URL = "https://fgwaeugfkrljgbbgvaox.supabase.co";
const SUPABASE_ANON_KEY = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImZnd2FldWdma3JsamdiYmd2YW94Iiwicm9sZSI6ImFub24iLCJpYXQiOjE3ODY3MTExNjIsImV4cCI6MjEwMjI4NzE2Mn0.HvSYkJy2m0rXo5J-Dlx34tTplUT3qRDoZIM67gyY1dc";

let supabaseClient = null;

function getSupabaseClient() {
  if (supabaseClient) return supabaseClient;
  if (typeof window.supabase === "undefined") {
    console.warn("[Supabase] JS library not loaded — add the CDN script tag.");
    return null;
  }
  supabaseClient = window.supabase.createClient(SUPABASE_URL, SUPABASE_ANON_KEY);
  return supabaseClient;
}

const isSupabaseConfigured = () => SUPABASE_URL.startsWith("https://YOUR-PROJECT-REF") === false;

const db = {
  async getEvents() {
    if (isSupabaseConfigured()) {
      const client = getSupabaseClient();
      const { data, error } = await client
        .from("events")
        .select("*, categories(name)")
        .eq("is_published", true)
        .order("start_date", { ascending: true });
      if (!error) return data;
      console.error("[Supabase] getEvents error:", error);
    }
    return []; // no local sample data
  },

  async getNotifications(userId) {
    if (isSupabaseConfigured() && userId) {
      const client = getSupabaseClient();
      const { data, error } = await client
        .from("notifications")
        .select("*")
        .eq("user_id", userId)
        .order("created_at", { ascending: false });
      if (!error) return data;
      console.error("[Supabase] getNotifications error:", error);
    }
    return []; // no local sample data
  },

  async registerForEvent(eventId, userId) {
    if (isSupabaseConfigured()) {
      const client = getSupabaseClient();
      const { data, error } = await client
        .from("event_registrations")
        .insert([{ event_id: eventId, user_id: userId, status: "registered" }]);
      if (!error) return data;
      console.error("[Supabase] registerForEvent error:", error);
    }
    return null;
  },

  async getAttendeeCount(eventId) {
    if (isSupabaseConfigured()) {
      const client = getSupabaseClient();
      const { count, error } = await client
        .from("event_registrations")
        .select("*", { count: "exact", head: true })
        .eq("event_id", eventId)
        .eq("status", "registered");
      if (!error) return count;
      console.error("[Supabase] getAttendeeCount error:", error);
    }
    return 0;
  },

  // Live landing-page counters: { events, organizations, active_users, registrations }.
  async getSiteStats() {
    const client = getSupabaseClient();
    if (!client) return null;
    const { data, error } = await client.rpc("site_stats");
    if (error) {
      console.error("[Supabase] getSiteStats error:", error);
      return null;
    }
    return data?.[0] ?? null;
  },

  async signIn(email, password) {
    if (isSupabaseConfigured()) {
      const client = getSupabaseClient();
      return client.auth.signInWithPassword({ email, password });
    }
    return { data: null, error: { message: "Supabase is not configured." } };
  },
};

window.db = db;
