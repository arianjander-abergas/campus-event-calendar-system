<?php
/**
 * Event Hub Assistant — BASIC MODE: answers without AI, so the chat box works
 * with no Anthropic API key (and whenever the AI can't answer). It looks for
 * common words and phrases in the question (English only), runs
 * the same read-only lookups as the AI's tools (includes/assistant.php), and
 * writes the answer from templates. It understands:
 *   - events: upcoming, today, tomorrow, this / next / last week or month, a
 *     month, a weekday or a date, ended, cancelled; by organization, category,
 *     audience or venue; one event by name ("when is …", "tell me about …")
 *     or the one on the current page ("this event")
 *   - organizations: the list (or one department's), one organization with its
 *     officers and upcoming events
 *   - the user's own registrations, events to evaluate and notifications
 *   - how-to questions (registering, cancelling, evaluating, accounts,
 *     passwords, appearance, notifications; running events for moderators and
 *     admins)
 *   - the school calendar, categories, departments and the site's numbers
 *   - opening pages ("take me to …"), and "yes" to its offer to open one
 * Like the AI it only reads the site, and it turns down requests to attack or
 * misuse it.
 */

const ASSISTANT_BASIC_YES = ['yes', 'yeah', 'yep', 'yup', 'ya', 'sure', 'ok', 'okay', 'oki', 'okie', 'go', 'go ahead', 'please', 'yes please', 'open it', 'do it', 'game'];
const ASSISTANT_BASIC_NO = ['no', 'nope', 'nah', 'no thanks', 'not now'];
const ASSISTANT_BASIC_HELLO = ['hi', 'hello', 'hey', 'heya', 'hiya', 'yo', 'good morning', 'good afternoon', 'good evening'];
const ASSISTANT_BASIC_THANKS = ['thanks', 'thank you', 'thank u', 'thx', 'ty', 'tysm', 'great', 'nice', 'cool', 'awesome', 'got it', 'noted'];
const ASSISTANT_BASIC_HOW = ['how', 'steps', 'procedure', 'where do i', 'where can i', 'what do i do', 'can i', 'could i', 'is it possible'];
const ASSISTANT_BASIC_PAST = ['ended', 'past', 'finished', 'done', 'previous', 'previously', 'already happened', 'happened', 'was held', 'were held', 'recent', 'recently', 'latest', 'last event'];
const ASSISTANT_BASIC_UPCOMING = ['upcoming', 'coming', 'coming up', 'next', 'soon', 'future', 'later', 'scheduled', 'will there', 'will be'];
const ASSISTANT_BASIC_CANCELLED = ['cancelled', 'canceled', 'postponed'];

/** The assistant only understands English: common Filipino words that give a question away. */
const ASSISTANT_NOT_ENGLISH_WORDS = ['ang', 'ng', 'mga', 'po', 'opo', 'ba', 'ko', 'ako', 'mo', 'kayo', 'sa', 'yung', 'iyong',
    'ito', 'iyan', 'yan', 'dito', 'doon', 'paano', 'pano', 'kailan', 'saan', 'nasaan', 'sino', 'ano', 'anong', 'meron',
    'mayroon', 'wala', 'pwede', 'puwede', 'ngayon', 'ngayong', 'bukas', 'kahapon', 'susunod', 'salamat', 'kumusta',
    'kamusta', 'naman', 'lang', 'talaga', 'gusto', 'kasi', 'nang', 'nga', 'pala', 'sige', 'hindi', 'huwag', 'kailangan',
    'dapat', 'magandang', 'buksan', 'dalhin', 'ipakita'];
const ASSISTANT_ENGLISH_ONLY_REPLY = 'Sorry, I can only understand English. Please ask your question in English — for example, "What events are coming up this week?"';

const ASSISTANT_BASIC_MONTHS = [
    'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6, 'july' => 7,
    'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
    'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'sept' => 9,
    'oct' => 10, 'nov' => 11, 'dec' => 12,
];
/** Month words that are also ordinary words ("may") or short forms: a month only with a day, a year, or "in / of / this / next / last …". */
const ASSISTANT_BASIC_LOOSE_MONTHS = ['may', 'mar', 'jan', 'feb', 'apr', 'jun', 'jul', 'aug', 'sep', 'sept', 'oct', 'nov', 'dec'];
const ASSISTANT_BASIC_WEEKDAYS = [
    'monday' => 'monday', 'tuesday' => 'tuesday', 'wednesday' => 'wednesday', 'thursday' => 'thursday', 'friday' => 'friday',
    'saturday' => 'saturday', 'sunday' => 'sunday',
];

/** Pages "take me to …" can open, most specific first: page key => phrases. */
const ASSISTANT_BASIC_PAGES = [
    'forgot_password' => ['forgot password', 'forgot my password', 'reset password', 'reset my password', 'password reset'],
    'sign_up' => ['sign up', 'signup', 'create account', 'create an account', 'make an account', 'register an account'],
    'log_in' => ['log in', 'login', 'sign in', 'signin'],
    'my_events' => ['my events', 'my event', 'my registrations', 'my registration', 'my registered events', 'registered events'],
    'edit_profile' => ['edit my profile', 'edit profile', 'edit my details', 'update my profile', 'change my details'],
    'approvals' => ['approvals', 'approvals tab'],
    'my_profile' => ['my profile', 'profile', 'my account', 'account', 'change password', 'change my password', 'password'],
    'create_event' => ['create event', 'create an event', 'create events', 'new event', 'add event', 'add an event', 'make an event', 'event form'],
    'update_events' => ['update events', 'update event', 'manage events', 'manage event', 'edit events', 'edit event', 'event list', 'events list'],
    'admin_dashboard' => ['admin dashboard', 'admin'],
    'dashboard' => ['dashboard'],
    'reports' => ['reports', 'report'],
    'evaluation_form' => ['evaluation form', 'evaluation questions'],
    'categories_orgs' => ['categories and orgs', 'categories orgs', 'categories', 'category', 'departments', 'department'],
    'school_calendar' => ['school calendar', 'academic calendar', 'semester dates', 'school year'],
    'users_roles' => ['users and roles', 'user roles', 'users', 'roles'],
    'import_verify' => ['import and verify', 'import', 'verify', 'attendance sheet'],
    'event_calendar' => ['event calendar', 'events calendar', 'calendar', 'events page', 'all events', 'events'],
    'organizations' => ['organizations', 'organization', 'orgs', 'org', 'clubs'],
    'home' => ['home', 'homepage', 'home page', 'main page', 'start page'],
];

/** Words that are never part of an event's name (questions, filters, dates). */
const ASSISTANT_BASIC_STOP = [
    'a', 'an', 'the', 'is', 'are', 'was', 'were', 'be', 'been', 'am', 'do', 'does', 'did', 'will', 'would', 'can', 'could', 'should',
    'i', 'me', 'my', 'you', 'your', 'we', 'our', 'us', 'it', 'its', 'this', 'that', 'these', 'those', 'there', 'here', 'what', 'whats',
    'when', 'where', 'who', 'whom', 'which', 'why', 'how', 'about', 'any', 'some', 'all', 'every', 'of', 'in', 'on', 'at', 'to', 'for',
    'from', 'by', 'with', 'and', 'or', 'but', 'not', 'no', 'yes', 'please', 'pls', 'plz', 'thanks', 'show', 'tell', 'give', 'list',
    'find', 'search', 'look', 'see', 'get', 'open', 'take', 'bring', 'go', 'want', 'need', 'like', 'know', 'more', 'still', 'also',
    'event', 'events', 'happening', 'happen', 'happens', 'held', 'hold', 'start', 'starts', 'end', 'ends', 'time', 'date', 'day',
    'days', 'week', 'weeks', 'month', 'months', 'year', 'years', 'today', 'tonight', 'tomorrow', 'yesterday', 'now', 'next', 'last',
    'upcoming', 'coming', 'up', 'soon', 'later', 'past', 'ended', 'previous', 'recent', 'latest', 'new', 'info', 'information',
    'details', 'detail', 'called', 'named', 'name', 'titled', 'regarding', 'page', 'site', 'website', 'register', 'registration',
    'registered', 'slots', 'slot', 'open', 'venue', 'place', 'made', 'make', 'created', 'creator', 'built', 'developer', 'weekend',
    'left', 'going', 'attending', 'people', 'many', 'much', 'number', 'total', 'organization', 'organizations', 'org', 'orgs',
    'club', 'clubs', 'officers', 'officer', 'already', 'yet', 'ever', 'just', 'only', 'again', 'even', 'really', 'actually',
    'have', 'has', 'had', 'having', 'happened', 'occur', 'occurred', 'occurring', 'available', 'closed', 'join', 'attend',
    'view', 'ask', 'question', 'kind', 'kinds', 'type', 'types', 'during', 'until', 'within', 'between', 'after', 'before',
    'since', 'around', 'most', 'other', 'others', 'another', 'they', 'them', 'their', 'he', 'she', 'his', 'her', 'must',
    'think', 'right', 'currently', 'ongoing', 'tmrw', 'tmr', 'recently', 'previously', 'finished', 'done', 'over', 'future',
    'scheduled', 'happenings', 'activity', 'activities', 'program', 'programs', 'schedule', 'schedules', 'calendar', 'school',
    'university', 'lcup', 'campus', 'teachers', 'teacher', 'professors', 'instructors', 'webinars', 'virtual', 'zoom',
    'outside', 'inside', 'semester', 'sem', 'sy', 'term', 'were', 'whats', 'whos', 'wheres', 'hows',
];

/** An answer: the reply, a page to open now (or null), a page to offer next (or null). */
function assistant_basic_say(string $reply, ?array $navigate = null, ?array $offer = null): array
{
    return ['reply' => $reply, 'navigate' => $navigate, 'offer' => $offer];
}

/**
 * Basic-mode answer to one question, in the shape assistant_run() returns
 * (so assistant_handle() saves it the same way), plus the page it offered.
 */
function assistant_basic(string $message, ?array $user, array $page, ?array $offer): array
{
    $answer = assistant_basic_answer($message, $user, $page, $offer);
    $reply = assistant_clean_reply($answer['reply'], $user);
    return [
        'ok' => true,
        'reply' => $reply,
        'navigate' => $answer['navigate'],
        'offer' => $answer['offer'],
        'turn' => [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => $message]]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => $reply]]],
        ],
    ];
}

function assistant_basic_answer(string $message, ?array $user, array $page, ?array $offer): array
{
    $m = assistant_basic_norm($message);
    $wordCount = count(array_filter(explode(' ', trim($m))));

    // Things it never helps with.
    $refusal = assistant_basic_refusal($m);
    if ($refusal !== '') return assistant_basic_say($refusal);

    // English only.
    if (assistant_basic_not_english($m, $wordCount)) {
        return assistant_basic_say(ASSISTANT_ENGLISH_ONLY_REPLY);
    }

    // "Yes" / "no" to its offer to open a page.
    if ($offer && $wordCount <= 5) {
        if (assistant_basic_has($m, ASSISTANT_BASIC_YES)) return assistant_basic_open($offer, $user);
        if (assistant_basic_has($m, ASSISTANT_BASIC_NO)) return assistant_basic_say('Okay! Ask me anything else about events or the site.');
    }

    // Greetings, thanks, help.
    if ($wordCount <= 4 && assistant_basic_has($m, ASSISTANT_BASIC_HELLO)) return assistant_basic_say(assistant_greeting($user));
    if ($wordCount <= 5 && assistant_basic_has($m, ASSISTANT_BASIC_THANKS)) return assistant_basic_say("You're welcome! Anything else about events or the site?");
    if ($wordCount <= 6 && assistant_basic_has($m, ['help', 'what can you do', 'what do you do', 'who are you', 'what are you', 'tulong', 'commands'])) {
        return assistant_basic_say(assistant_basic_help($user));
    }

    $orgs = assistant_basic_orgs($m);
    $dates = assistant_basic_dates($m);

    // "This event" on an event's page.
    if ($page['event_id'] !== '' && preg_match('/ (this event|this one|this page) | (for|is|about|join|attend|of|register) this $/', $m)) {
        $details = assistant_tool_event_details(['event_id' => $page['event_id']], $user);
        if (!isset($details['error'])) return assistant_basic_say(assistant_basic_event_reply($details, $user));
    }

    // Open a page.
    if (preg_match('/^ (open|go|goto|navigate|bring me|take me) | (take me|bring me|go to|goto|navigate to|redirect me|open the|open my|open up|show me the [\p{L} ]{1,40}page) /u', $m)) {
        $answer = assistant_basic_navigate($m, $user, $orgs, $dates);
        if ($answer) return $answer;
    }

    // "Where is the evaluation form / my registrations / the calendar?": that page.
    // ("Where are JPSSITE's events?" is left to the event answers below.)
    if (!$orgs && !$dates && assistant_basic_has($m, ['where'])) {
        $answer = assistant_basic_page_mention($m, $user);
        if ($answer) return $answer;
    }

    foreach (['assistant_basic_howto', 'assistant_basic_mine', 'assistant_basic_info'] as $fn) {
        $answer = $fn($m, $user);
        if ($answer) return $answer;
    }
    $answer = assistant_basic_organizations($m, $orgs);
    if ($answer) return $answer;
    $answer = assistant_basic_events($m, $user, $orgs, $dates);
    if ($answer) return $answer;

    // Just a page's name ("reports", "my profile"): offer that page.
    if ($wordCount <= 4) {
        $answer = assistant_basic_page_mention($m, $user, true);
        if ($answer) return $answer;
    }

    // A last try: the question's words as an event's name.
    $keywords = assistant_basic_keywords($m);
    if ($keywords !== '') {
        $answer = assistant_basic_named_event($keywords, $user);
        if ($answer) return $answer;
        if (assistant_basic_has($m, ['is there', 'are there', 'any', 'when is', 'where is'])) {
            return assistant_basic_not_found($keywords);
        }
    }
    return assistant_basic_say(assistant_basic_help($user, true));
}

/**
 * The page a question names, offered with "Want me to open it?". $sayBlocked:
 * a page the user can't open gets "log in first" / "not for your account".
 */
function assistant_basic_page_mention(string $m, ?array $user, bool $sayBlocked = false): ?array
{
    $pages = assistant_pages($user);
    foreach (ASSISTANT_BASIC_PAGES as $key => $phrases) {
        if (!assistant_basic_has($m, $phrases)) continue;
        if (isset($pages[$key])) {
            return assistant_basic_say('That\'s the **' . $pages[$key][0] . '** page. Want me to open it?', null, ['page' => $key]);
        }
        if ($sayBlocked && !in_array($key, ['sign_up', 'log_in', 'forgot_password'], true)) {
            return $user
                ? assistant_basic_say("That page isn't available for your account.")
                : assistant_basic_say('You need to log in for that page. Want me to open the login page?', null, ['page' => 'log_in']);
        }
    }
    return null;
}

function assistant_basic_not_found(string $keywords): array
{
    return assistant_basic_say('I couldn\'t find any event matching "' . $keywords . '". The [Event Calendar](' . BASE_URL . '/events.php) lists everything, or ask me what\'s coming up.');
}

// =============================================================================
// READING THE QUESTION
// =============================================================================

/** Lower case, no punctuation (dates keep / and -), single spaces, a space at each end (so ' word ' matches whole words). */
function assistant_basic_norm(string $text): string
{
    $text = mb_strtolower($text, 'UTF-8');
    $text = str_replace(["'", '’', '‘', '`'], '', $text);
    $text = preg_replace('/(?<=\p{L})[-\/](?=\p{L})/u', ' ', $text) ?? $text; // on-campus -> on campus
    $text = preg_replace('/[^\p{L}\p{N}\/-]+/u', ' ', $text) ?? $text;
    return ' ' . trim(preg_replace('/\s+/u', ' ', $text) ?? $text) . ' ';
}

/**
 * True if the question looks like it isn't English: two or more common
 * Filipino words, or one in a question of three words or fewer.
 */
function assistant_basic_not_english(string $m, int $wordCount): bool
{
    $hits = count(array_intersect(array_unique(explode(' ', trim($m))), ASSISTANT_NOT_ENGLISH_WORDS));
    return $hits >= 2 || ($hits === 1 && $wordCount <= 3);
}

/** True if any of the phrases is in the question as whole words. */
function assistant_basic_has(string $m, array $phrases): bool
{
    foreach ($phrases as $phrase) {
        if (str_contains($m, ' ' . $phrase . ' ')) return true;
    }
    return false;
}

/** '' or why it won't help: attacking or misusing the site, how it's built, other people's private details. */
function assistant_basic_refusal(string $m): string
{
    if (preg_match('/ (make me|give me|turn me into|set me as|promote me to) (an |a )?(admin|moderator) /', $m)) {
        return 'Only an admin can change roles, on the Users & Roles page. Ask an admin if you need one.';
    }
    $attacks = [
        '/ (hack|hacking|hacked) (into|the|this|your|a|an|my|someone|someones|admin|account|accounts|site|website|system|database|server|events?|users?) /',
        '/ (how (do i|to|can i) )hack /',
        '/ (sql injection|xss|cross site scripting|csrf|ddos|dos attack|brute force|bruteforce|malware|ransomware|phishing|keylogger|backdoor|exploit|exploits|vulnerability|vulnerabilities|jailbreak|privilege escalation) /',
        '/ bypass (the )?(login|password|security|admin|role|roles|rules|limit|limits|restriction|restrictions|registration) /',
        '/ (steal|leak|dump|scrape) /',
        '/ (delete|remove|drop|destroy|wipe|erase|shut down|shutdown|take down|crash|overload|spam|flood) .*(all|every|everything|whole|entire|site|website|system|database|server|data) /',
        '/ ignore (all |your |the |previous |prior |these |those )*(rules|instructions|limits) /',
    ];
    foreach ($attacks as $re) {
        if (preg_match($re, $m)) {
            return "I can't help with that. I only read the site — I can't change, test or get around anything on it. I can help you find events, organizations and pages, though.";
        }
    }
    if (preg_match('/ (source code|codebase|the code|your code|api key|apikey|secret key|access token|system prompt|your prompt|your instructions|admin password) /', $m)) {
        return "I can only help with using the site, not with how it's built. Ask me about events, organizations or where to find something.";
    }
    if (preg_match('/ (passwords?|school ids?) .*(of|ni|nina|someone|somebody|others?|everyone|all|their|his|her|admins?|users|students|members|officers) /', $m)
        || preg_match('/ who (registered|signed up|joined|attended|is registered|are registered) /', $m)
        || preg_match('/ (emails?|phone numbers?|contact numbers?|addresses|home address) (of|ni|nina) (the )?(students?|users?|members?|officers?|admins?|moderators?|everyone|people|attendees|participants) /', $m)) {
        return "I can't share people's private details, like passwords, School IDs, contact details or who registered. I can tell you about events and organizations, though.";
    }
    return '';
}

/** Organizations named in the question (by full name, or a distinctive word of it like "JPSSITE"). */
function assistant_basic_orgs(string $m): array
{
    $generic = ['organization', 'organisation', 'org', 'orgs', 'society', 'club', 'council', 'student', 'students', 'college',
        'collegiate', 'coordinating', 'board', 'the', 'of', 'and', 'for', 'in', 'association', 'group', 'chapter', 'inc', '-'];
    $found = [];
    foreach (assistant_organizations() as $org) {
        $name = assistant_basic_norm($org['name']);
        if (str_contains($m, $name)) {
            $found[] = $org;
            continue;
        }
        foreach (explode(' ', trim($name)) as $word) {
            if (mb_strlen($word) >= 3 && !in_array($word, $generic, true) && str_contains($m, ' ' . $word . ' ')) {
                $found[] = $org;
                break;
            }
        }
    }
    return $found;
}

/** The category named in the question ("seminars", "sports"…), or null. "Other" is never matched. */
function assistant_basic_category(string $m): ?array
{
    foreach (get_categories() as $category) {
        if (is_other_category($category['name'])) continue;
        $name = trim(assistant_basic_norm($category['name']));
        foreach (array_unique([$name, $name . 's', rtrim($name, 's')]) as $form) {
            if ($form !== '' && str_contains($m, ' ' . $form . ' ')) return $category;
        }
    }
    return null;
}

/**
 * The days a question is about: ['from', 'to', 'label' ("tomorrow", "next
 * week", "in October 2026"…), 'today' (true for today)], or null. Understands
 * a date ("Oct 20", "20 October 2026", "10/20", "2026-10-20"), today /
 * tomorrow / yesterday, this / next / last week, weekend or month, this
 * semester / school year / year, a month and a weekday.
 */
function assistant_basic_dates(string $m): ?array
{
    $today = new DateTimeImmutable('today');
    $range = fn(DateTimeImmutable $from, DateTimeImmutable $to, string $label): array => [
        'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'label' => $label,
        'today' => $from->format('Y-m-d') === $today->format('Y-m-d') && $to->format('Y-m-d') === $today->format('Y-m-d'),
    ];
    $onDay = fn(DateTimeImmutable $d): array => $range($d, $d, 'on ' . $d->format('D, M j'));
    $months = implode('|', array_keys(ASSISTANT_BASIC_MONTHS));
    $thisYear = (int) $today->format('Y');

    // One day.
    if (preg_match('~ (\d{4})-(\d{1,2})-(\d{1,2}) ~', $m, $x) && checkdate((int) $x[2], (int) $x[3], (int) $x[1])) {
        return $onDay($today->setDate((int) $x[1], (int) $x[2], (int) $x[3]));
    }
    if (preg_match('~ (\d{1,2})/(\d{1,2})(?:/(\d{4}|\d{2}))? ~', $m, $x)) {
        $year = !empty($x[3]) ? (int) (strlen($x[3]) === 2 ? '20' . $x[3] : $x[3]) : $thisYear;
        if (checkdate((int) $x[1], (int) $x[2], $year)) return $onDay($today->setDate($year, (int) $x[1], (int) $x[2]));
    }
    if (preg_match("~ ($months) (\\d{1,2})(?:st|nd|rd|th)?(?: (\\d{4}))? ~", $m, $x)
        || (preg_match("~ (\\d{1,2})(?:st|nd|rd|th)? (?:of )?($months)(?: (\\d{4}))? ~", $m, $y) && ($x = [$y[0], $y[2], $y[1], $y[3] ?? '']))) {
        $month = ASSISTANT_BASIC_MONTHS[$x[1]];
        $year = !empty($x[3]) ? (int) $x[3] : $thisYear;
        if (checkdate($month, (int) $x[2], $year)) return $onDay($today->setDate($year, $month, (int) $x[2]));
    }

    if (assistant_basic_has($m, ['today', 'tonight', 'right now', 'happening now', 'ongoing', 'on going', 'currently'])) {
        return $range($today, $today, 'today');
    }
    if (assistant_basic_has($m, ['tomorrow', 'tmrw', 'tmr'])) {
        $d = $today->modify('+1 day');
        return $range($d, $d, 'tomorrow');
    }
    if (assistant_basic_has($m, ['yesterday'])) {
        $d = $today->modify('-1 day');
        return $range($d, $d, 'yesterday');
    }

    // Weeks run Monday to Sunday.
    if (assistant_basic_has($m, ['next weekend'])) return $range($today->modify('saturday next week'), $today->modify('sunday next week'), 'next weekend');
    if (assistant_basic_has($m, ['this weekend', 'weekend'])) return $range($today->modify('saturday this week'), $today->modify('sunday this week'), 'this weekend');
    if (assistant_basic_has($m, ['next week'])) return $range($today->modify('monday next week'), $today->modify('sunday next week'), 'next week');
    if (assistant_basic_has($m, ['last week'])) return $range($today->modify('monday last week'), $today->modify('sunday last week'), 'last week');
    if (assistant_basic_has($m, ['this week', 'within the week'])) return $range($today->modify('monday this week'), $today->modify('sunday this week'), 'this week');

    $first = $today->modify('first day of this month');
    if (assistant_basic_has($m, ['next month', 'susunod na buwan'])) {
        $f = $first->modify('+1 month');
        return $range($f, $f->modify('last day of this month'), 'next month');
    }
    if (assistant_basic_has($m, ['last month', 'nakaraang buwan'])) {
        $f = $first->modify('-1 month');
        return $range($f, $f->modify('last day of this month'), 'last month');
    }
    if (assistant_basic_has($m, ['this month', 'within the month'])) return $range($first, $first->modify('last day of this month'), 'this month');

    if (assistant_basic_has($m, ['this semester', 'this sem'])) {
        $p = school_period_of();
        [$from, $to] = term_range($p['school_year'], $p['semester']);
        return $range(new DateTimeImmutable($from), new DateTimeImmutable($to), 'this semester');
    }
    if (assistant_basic_has($m, ['this school year', 'this sy'])) {
        [$from, $to] = term_range(school_period_of()['school_year']);
        return $range(new DateTimeImmutable($from), new DateTimeImmutable($to), 'this school year');
    }
    if (assistant_basic_has($m, ['this year'])) {
        return $range($today->setDate($thisYear, 1, 1), $today->setDate($thisYear, 12, 31), 'this year');
    }

    // A month: "October", "in May", "October 2026".
    foreach (ASSISTANT_BASIC_MONTHS as $word => $month) {
        if (!preg_match('~ ' . $word . '(?: (\d{4}))? ~', $m, $x)) continue;
        if (in_array($word, ASSISTANT_BASIC_LOOSE_MONTHS, true) && empty($x[1])
            && !preg_match('~ (in|of|this|next|last|during|until|for) ' . $word . ' ~', $m)) {
            continue;
        }
        $year = !empty($x[1]) ? (int) $x[1] : $thisYear;
        // A month that's already over this year means next year's, unless they ask about the past.
        if (empty($x[1]) && $month < (int) $today->format('n') && !assistant_basic_has($m, array_merge(ASSISTANT_BASIC_PAST, ['last']))) {
            $year++;
        }
        $f = $today->setDate($year, $month, 1);
        return $range($f, $f->modify('last day of this month'), 'in ' . $f->format('F Y'));
    }

    // A weekday: "Friday", "this Friday", "next Friday", "last Friday".
    foreach (ASSISTANT_BASIC_WEEKDAYS as $word => $day) {
        if (!assistant_basic_has($m, [$word])) continue;
        if (assistant_basic_has($m, ['last ' . $word])) return $onDay($today->modify('last ' . $day));
        if (assistant_basic_has($m, ['next ' . $word])) return $onDay($today->modify('next ' . $day));
        return $onDay($today->modify($day)); // today if it's that day, else the next one
    }
    return null;
}

/** The words of the question that could be part of an event's name (no question words, dates or filters). */
function assistant_basic_keywords(string $m): string
{
    static $skip = null;
    if ($skip === null) {
        $words = array_merge(ASSISTANT_BASIC_STOP, array_keys(ASSISTANT_BASIC_MONTHS), array_keys(ASSISTANT_BASIC_WEEKDAYS),
            ['students', 'student', 'faculty', 'online', 'webinar', 'campus', 'off', 'cancelled', 'canceled']);
        foreach (get_categories() as $category) {
            $name = trim(assistant_basic_norm($category['name']));
            array_push($words, $name, $name . 's', rtrim($name, 's'));
        }
        $skip = array_flip($words);
    }
    $kept = [];
    foreach (explode(' ', trim($m)) as $word) {
        if ($word === '' || isset($skip[$word]) || mb_strlen($word) < 2 || preg_match('/^[\d\/-]+$/', $word)) continue;
        $kept[] = $word;
    }
    return implode(' ', array_slice($kept, 0, 6));
}

/** The event named in "when is …", "where is …", "tell me about …" (its name words), or ''. */
function assistant_basic_event_name(string $m): string
{
    $patterns = [
        '/ (?:when|where|what time) (?:(?:is|was|will|does|did) )?(?:the )?(.+?) (?:(?:be|happen|happening|start|starting|end|ending|held|take place|taking place|begin|located) )?(?:event )?$/',
        '/ (?:tell me about|tell me more about|details (?:of|on|about|for)|info (?:on|about|for)|information (?:on|about|for)|more about|know about|describe) (?:the )?(.+?) (?:event )?$/',
    ];
    foreach ($patterns as $re) {
        if (preg_match($re, $m, $x)) return assistant_basic_keywords(' ' . $x[1] . ' ');
    }
    return '';
}

// =============================================================================
// ANSWERS
// =============================================================================

/** "Take me to …": a page by name, an organization, a date on the calendar, or an event by name. Null = not a page request after all. */
function assistant_basic_navigate(string $m, ?array $user, array $orgs, ?array $dates): ?array
{
    $role = $user['role'] ?? '';
    $pages = assistant_pages($user);

    // Officers: the moderator's own page, an organization's page (admins), or its card on Organizations.
    if (assistant_basic_has($m, ['officers', 'officer', 'our officers'])) {
        if (isset($pages['our_officers']) && !$orgs) return assistant_basic_open(['page' => 'our_officers'], $user);
        if ($role === 'admin' && $orgs) return assistant_basic_open(['page' => 'organization_officers', 'organization' => $orgs[0]['name']], $user);
        return assistant_basic_open(['page' => 'organizations'] + ($orgs ? ['organization' => $orgs[0]['name']] : []), $user);
    }

    foreach (ASSISTANT_BASIC_PAGES as $key => $phrases) {
        if (!assistant_basic_has($m, $phrases)) continue;
        if (!isset($pages[$key])) {
            if ($user && in_array($key, ['sign_up', 'log_in', 'forgot_password'], true)) {
                return assistant_basic_say("You're already logged in" . (assistant_first_name($user) !== '' ? ' as ' . assistant_first_name($user) : '') . '.');
            }
            return $user
                ? assistant_basic_say("That page isn't available for your account.")
                : assistant_basic_say('You need to log in for that page. Want me to open the login page?', null, ['page' => 'log_in']);
        }
        $input = ['page' => $key];
        if (in_array($key, ['event_calendar', 'organizations'], true) && $orgs) $input['organization'] = $orgs[0]['name'];
        if ($key === 'event_calendar' && $dates) {
            $input['month'] = substr($dates['from'], 0, 7);
            if ($dates['from'] === $dates['to']) $input['date'] = $dates['from'];
        }
        if ($key === 'categories_orgs') {
            if (assistant_basic_has($m, ['departments', 'department'])) $input['tab'] = 'departments';
            elseif (assistant_basic_has($m, ['organizations', 'organization', 'orgs'])) $input['tab'] = 'organizations';
        }
        return assistant_basic_open($input, $user);
    }

    if ($orgs) return assistant_basic_open(['page' => 'organizations', 'organization' => $orgs[0]['name']], $user);
    if ($dates) {
        return assistant_basic_open(['page' => 'event_calendar', 'month' => substr($dates['from'], 0, 7)]
            + ($dates['from'] === $dates['to'] ? ['date' => $dates['from']] : []), $user);
    }

    // An event by name.
    $keywords = assistant_basic_keywords($m);
    if ($keywords === '') return null;
    $found = assistant_tool_search_events(['keywords' => $keywords, 'when' => 'any', 'limit' => 5, 'include_cancelled' => true], $user);
    $events = $found['events'] ?? [];
    if (!$events) return null;
    if (count($events) > 1) {
        return assistant_basic_say(assistant_basic_list($events, (int) $found['found'], 'I found a few events — which one do you mean?', $found['event_calendar']));
    }
    $ev = $events[0];
    if (!$user) {
        return assistant_basic_say('**[' . assistant_basic_link_text($ev['title']) . '](' . $ev['url'] . ')** has its own page, but you need to log in to open it. Want me to open the login page?', null, ['page' => 'log_in']);
    }
    return assistant_basic_open(['page' => 'event_details', 'event_id' => $ev['id']], $user);
}

/** Opens a page through the same checks as the AI's open_page tool. */
function assistant_basic_open(array $input, ?array $user): array
{
    $nav = null;
    $result = assistant_tool_open_page($input, $user, $nav);
    if ($nav) {
        return assistant_basic_say('Opening **' . $nav['label'] . '**.', $nav);
    }
    if (!$user) {
        return assistant_basic_say('You need to log in for that page. Want me to open the login page?', null, ['page' => 'log_in']);
    }
    $error = (string) ($result['error'] ?? '');
    $friendly = [
        "This user can't open that page." => "That page isn't available for your account.",
        'There is no event with that id. Find it with search_events first.' => "I couldn't find that event.",
        "This user can only manage their own organization's events." => "You can only manage your organization's events.",
        'Only people marked Attended can evaluate this event, and this user is not.' => 'Only people marked Attended can evaluate that event.',
        'This user has already evaluated this event.' => "You've already evaluated that event.",
        'Say which organization (organization).' => 'Which organization?',
    ];
    return assistant_basic_say($friendly[$error] ?? ($error !== '' ? $error : "Sorry, I couldn't open that page."));
}

/** How-to questions (role-aware), with an offer to open the page where it's done. */
function assistant_basic_howto(string $m, ?array $user): ?array
{
    $role = $user['role'] ?? '';
    $staff = $role === 'admin' || ($role === 'moderator' && !empty($user['organization_id']));
    $how = assistant_basic_has($m, ASSISTANT_BASIC_HOW);
    $event = (bool) preg_match('/ (event|events|seminar|seminars|workshop|workshops|activity|activities) /', $m);
    $guestNote = $user ? '' : ' You need to log in first.';

    // Always how-to, even without "how".
    if (assistant_basic_has($m, ['forgot password', 'forgot my password', 'reset password', 'reset my password', 'change password', 'change my password', 'new password', 'password'])) {
        return $user
            ? assistant_basic_say('You can change your password on **My Profile**. Want me to open it?', null, ['page' => 'my_profile'])
            : assistant_basic_say('On the login page, press **Forgot Password?** and enter your email; a reset link is sent to it. Want me to open that page?', null, ['page' => 'forgot_password']);
    }
    if (assistant_basic_has($m, ['dark mode', 'light mode', 'dim mode', 'night mode', 'theme', 'appearance', 'dark', 'dim'])) {
        return assistant_basic_say('Use the round button next to the bell, at the top right, to switch between **Light**, **Dim** and **Dark**. Your choice is remembered on this browser.');
    }
    if (!$how) return null;

    if (assistant_basic_has($m, ['sign up', 'signup', 'create an account', 'create account', 'make an account', 'register an account', 'new account', 'account'])) {
        return $user
            ? assistant_basic_say("You already have an account — you're logged in.")
            : assistant_basic_say("Press **Sign up** at the top right. You'll choose whether you're a student or faculty, your department and your organization; students also give their School ID, course, highest year level and whether they're regular or irregular. Want me to open the sign-up page?", null, ['page' => 'sign_up']);
    }
    if (assistant_basic_has($m, ['log in', 'login', 'sign in', 'signin'])) {
        return $user
            ? assistant_basic_say("You're already logged in.")
            : assistant_basic_say('Press **LOG IN** at the top right and use your email and password (or Google, if it\'s switched on). Want me to open the login page?', null, ['page' => 'log_in']);
    }
    if (assistant_basic_has($m, ['cancel', 'unregister'])) {
        if ($staff && $event && !assistant_basic_has($m, ['registration', 'my registration'])) {
            return assistant_basic_say('On **Update Events**, open the event\'s **Edit ▾** menu and press **Mark Cancelled**. Everyone who registered is notified. Want me to open Update Events?', null, ['page' => 'update_events']);
        }
        return assistant_basic_say("Open the event's page and press **Cancel registration**. You can cancel until the event ends, and it frees your slot. Your registrations are listed in **My Events**." . $guestNote
            . ($user ? ' Want me to open My Events?' : ''), null, $user ? ['page' => 'my_events'] : null);
    }
    if (preg_match('/ (evaluat\w*|feedback|rate|rating) /', $m)) {
        return assistant_basic_say('Once the organizers mark you **Attended**, **Evaluate this event** appears on the event\'s page and on your Dashboard. You evaluate each event once, rating it from 1 (Needs Improvement) to 5 (Excellent) stars and adding comments; answers are anonymous.' . $guestNote
            . ($user ? ' Want me to open your Dashboard?' : ''), null, $user ? ['page' => 'dashboard'] : null);
    }
    if ($event && assistant_basic_has($m, ['create', 'make', 'add', 'post', 'publish', 'set up', 'organize', 'gumawa'])) {
        return $staff
            ? assistant_basic_say('Go to **Manage Events → Create Event**, fill in the form (category, organizers, dates, venue, audience and registration) and publish it. Want me to open Create Event?', null, ['page' => 'create_event'])
            : assistant_basic_say("Only Admins and Super Admins can create events. If your organization wants to post one, ask its Admin.");
    }
    if ($staff && $event && assistant_basic_has($m, ['edit', 'update', 'change', 'delete', 'remove'])) {
        return assistant_basic_say('On **Update Events**, find the event and open its **Edit ▾** menu: **Update** changes the details, and you can mark it cancelled or completed there.'
            . ($role === 'admin' ? ' Admins can also **Delete** it.' : ' Only admins can delete events.') . ' Want me to open Update Events?', null, ['page' => 'update_events']);
    }
    if ($staff && assistant_basic_has($m, ['attendance', 'attended', 'no show', 'noshow', 'present', 'absent'])) {
        return assistant_basic_say('On **Update Events**, open the event\'s **Manage ▾** menu → **Registrations**: mark people Attended or No-show (one by one or several at once) and print the attendance sheet. **Import & Verify** can mark attendance from an Excel sheet. Want me to open Update Events?', null, ['page' => 'update_events']);
    }
    if ($staff && assistant_basic_has($m, ['schedule', 'program', 'speaker', 'speakers'])) {
        return assistant_basic_say('On **Update Events**, open the event\'s **Manage ▾** menu → **Schedule** to add its program parts and speakers. Want me to open Update Events?', null, ['page' => 'update_events']);
    }
    if (assistant_basic_has($m, ['join', 'switch']) && (assistant_basic_has($m, ['organization', 'org', 'orgs', 'club']) || assistant_basic_orgs($m))) {
        return assistant_basic_say('You choose your organization when you sign up' . ($user ? ', and it\'s shown on **My Profile**. Want me to open it?' : '.'), null, $user ? ['page' => 'my_profile'] : null);
    }
    if (assistant_basic_has($m, ['register', 'registration', 'join', 'attend', 'reserve', 'slot', 'sign up for', 'magregister', 'mag register', 'sumali'])) {
        return assistant_basic_say("Open the event's page and press **Register Now!**. It works while registration is open — before the deadline, before the event ends and while slots are left — and only if the event is for you (students, faculty or both, and sometimes only some year levels). Some events need no registration: just show up." . $guestNote
            . ' Want me to open the Event Calendar?', null, ['page' => 'event_calendar']);
    }
    if (assistant_basic_has($m, ['notification', 'notifications', 'notified', 'notify', 'bell'])) {
        return assistant_basic_say('The bell at the top right shows your notifications: new events, changes, cancellations and evaluation reminders. Opening it marks them read.' . $guestNote);
    }
    if (assistant_basic_has($m, ['moderator', 'admin', 'role', 'roles'])) {
        return $role === 'admin'
            ? assistant_basic_say('You change roles on **Users & Roles**. Want me to open it?', null, ['page' => 'users_roles'])
            : assistant_basic_say('Roles are given by Super Admins on the Users & Roles page. Ask a Super Admin if you need to be an Admin.');
    }
    if (assistant_basic_has($m, ['profile', 'school id', 'department', 'course', 'year level', 'irregular', 'my details', 'my info'])) {
        return assistant_basic_say('Your department, organization, School ID, course and year level are on **My Profile**; change them with **Edit my profile** (a Super Admin approves changes before they\'re saved).' . $guestNote
            . ($user ? ' Want me to open it?' : ''), null, $user ? ['page' => 'my_profile'] : null);
    }
    if (assistant_basic_has($m, ['search', 'find', 'filter', 'look for', 'hanapin'])) {
        return assistant_basic_say('Use the search box at the top of Home, or the filter bar on the **Event Calendar** (keyword, category, organization, audience and venue). You can also just ask me, like "seminars next week". Want me to open the Event Calendar?', null, ['page' => 'event_calendar']);
    }
    return null;
}

/** The user's own registrations, events to evaluate and notifications. */
function assistant_basic_mine(string $m, ?array $user): ?array
{
    $registrations = assistant_basic_has($m, ['my events', 'my event', 'my registrations', 'my registration', 'am i registered', 'registered for',
        'did i register', 'i registered', 'have i registered', 'i joined', 'naka register', 'nakaregister', 'nag register', 'nagregister',
        'my schedule', 'events i registered', 'events i joined']);
    $evaluations = preg_match('/ (evaluat\w*|feedback) /', $m)
        && assistant_basic_has($m, ['my', 'do i', 'i have', 'have i', 'i need', 'pending', 'need to', 'should i', 'left', 'to']);
    $notifications = assistant_basic_has($m, ['notifications', 'notification', 'announcements', 'announcement', 'any updates', 'my updates', 'inbox']);
    if (!$registrations && !$evaluations && !$notifications) return null;

    if (!$user) {
        $what = $evaluations ? 'events to evaluate' : ($notifications ? 'notifications' : 'registrations');
        return assistant_basic_say("Log in first and I can show you your $what. Want me to open the login page?", null, ['page' => 'log_in']);
    }
    $activity = assistant_tool_my_activity();

    if ($evaluations) {
        $pending = $activity['to_evaluate'];
        if (!$pending) return assistant_basic_say("You're all caught up: no events are waiting for your evaluation.");
        $lines = array_map(fn($e) => '- [Evaluate ' . assistant_basic_link_text($e['event']) . '](' . $e['url'] . ')', $pending);
        return assistant_basic_say('You have ' . count($pending) . ' event' . (count($pending) === 1 ? '' : 's') . " to evaluate:\n" . implode("\n", $lines));
    }
    if ($notifications) {
        $latest = $activity['latest_notifications'];
        if (!$latest) return assistant_basic_say("You don't have any notifications yet.");
        $lines = array_map(fn($n) => '- **' . $n['title'] . '** — ' . $n['message'] . ' (' . $n['when'] . ($n['read'] ? '' : ', new') . ')', $latest);
        return assistant_basic_say("Your latest notifications:\n" . implode("\n", $lines) . "\n\nThe bell at the top right shows them all.");
    }
    $registered = $activity['registrations'];
    if (!$registered) {
        return assistant_basic_say("You haven't registered for any events yet. Want me to open the Event Calendar?", null, ['page' => 'event_calendar']);
    }
    $lines = array_map(fn($r) => '- [' . assistant_basic_link_text($r['event']) . '](' . $r['url'] . ') — ' . $r['date']
        . ' (' . $r['event_status'] . ') · you: ' . $r['my_status'], $registered);
    $more = $activity['registrations_total'] - count($registered);
    return assistant_basic_say('Your registrations (' . $activity['registrations_total'] . "):\n" . implode("\n", $lines)
        . ($more > 0 ? "\n\n…and $more more in [My Events](" . $activity['my_events_page'] . ').' : ''));
}

/** The school calendar, the site's numbers, categories and departments. */
function assistant_basic_info(string $m, ?array $user): ?array
{
    $eventWords = (bool) preg_match('/ (event|events|seminar\w*|workshop\w*|activit\w+|happening) /', $m);
    // "How many events / organizations / users are there?" (not "how many slots are left for …").
    if (assistant_basic_has($m, ['how many', 'number of', 'ilan', 'stats', 'statistics', 'total'])
        && (assistant_basic_has($m, ['stats', 'statistics']) || preg_match('/ (events|organizations|orgs|users|accounts|registrations|members) /', $m))
        && !assistant_basic_has($m, ['going', 'slots', 'slot', 'left', 'registered for', 'attending', 'attended', 'joined'])) {
        $numbers = assistant_tool_overview()['site_numbers'] ?? [];
        if (!$numbers) return assistant_basic_say("Sorry, I couldn't load the site's numbers right now.");
        $parts = [];
        foreach ($numbers as $label => $value) $parts[] = $value . ' ' . strtolower($label);
        $last = array_pop($parts);
        return assistant_basic_say('Right now the site has ' . ($parts ? implode(', ', $parts) . ' and ' : '') . $last . '.');
    }
    if ($eventWords) return null; // "events this semester" etc. are event questions
    if (assistant_basic_has($m, ['semester', 'sem', 'school year', 'school calendar', 'academic calendar', 'academic year', 'term', 'terms', 'sy'])) {
        $o = assistant_tool_overview();
        $lines = array_map(fn($t) => '- ' . $t, $o['school_calendar'] ?? []);
        return assistant_basic_say("It's " . $o['school_year'] . ', ' . $o['semester'] . '.' . ($lines ? "\n\nSchool calendar:\n" . implode("\n", $lines) : '')
            . "\n\n" . $o['school_calendar_note']);
    }
    if (assistant_basic_has($m, ['categories', 'category', 'types of events', 'kinds of events', 'event types', 'type of events', 'kind of events', 'types', 'kinds'])) {
        $names = array_column(get_categories(), 'name');
        if (!$names) return assistant_basic_say("Sorry, I couldn't load the categories right now.");
        $last = array_pop($names);
        return assistant_basic_say('Events are grouped into these categories: ' . ($names ? implode(', ', $names) . ' and ' : '') . $last
            . '. You can filter by category on the [Event Calendar](' . BASE_URL . '/events.php).');
    }
    if (assistant_basic_has($m, ['departments', 'department', 'colleges'])) {
        $departments = array_map('department_label', get_departments());
        if (!$departments) return assistant_basic_say("Sorry, I couldn't load the departments right now.");
        return assistant_basic_say("The departments are:\n- " . implode("\n- ", $departments));
    }
    return null;
}

/** The organizations, one department's, or one organization with its officers and upcoming events. */
function assistant_basic_organizations(string $m, array $orgs): ?array
{
    $orgWords = assistant_basic_has($m, ['organization', 'organizations', 'org', 'orgs', 'club', 'clubs', 'officers', 'officer', 'president',
        'vice president', 'secretary', 'treasurer', 'adviser', 'advisers', 'members', 'samahan']);
    $aboutWords = assistant_basic_has($m, ['about', 'what is', 'whats', 'who is', 'who are', 'tell me', 'info', 'information']);
    $eventWords = (bool) preg_match('/ (event|events|seminar\w*|workshop\w*|activit\w+|happening|ganap) /', $m);

    if ($orgs && ($orgWords || ($aboutWords && !$eventWords))) {
        $result = assistant_tool_organizations(['name' => $orgs[0]['name']]);
        $org = $result['organizations'][0] ?? null;
        return $org ? assistant_basic_say(assistant_basic_org_reply($org)) : null;
    }
    if (!$orgWords || $eventWords) return null;

    $department = '';
    foreach (get_departments() as $d) {
        if (!empty($d['code']) && assistant_basic_has($m, [mb_strtolower($d['code'])])) {
            $department = $d['code'];
            break;
        }
    }
    $result = assistant_tool_organizations($department !== '' ? ['department' => $department] : []);
    if (isset($result['error'])) return assistant_basic_say("Sorry, I couldn't load the organizations right now.");
    if (!empty($result['organizations'])) {
        return assistant_basic_say(implode("\n\n", array_map('assistant_basic_org_reply', $result['organizations'])));
    }
    $lines = [];
    foreach ($result['departments'] ?? [] as $dept => $names) {
        $lines[] = '- **' . $dept . '**: ' . implode(', ', $names);
    }
    if (!$lines) return assistant_basic_say('There are no organizations listed yet.');
    return assistant_basic_say("Here are the organizations, by department:\n" . implode("\n", $lines)
        . "\n\nTheir officers and events are on the [Organizations](" . BASE_URL . '/organizations.php) page.');
}

/** Event lists (filtered by date, organization, category, audience, venue) and single events by name. */
function assistant_basic_events(string $m, ?array $user, array $orgs, ?array $dates): ?array
{
    $name = assistant_basic_event_name($m);
    if ($name !== '') {
        $answer = assistant_basic_named_event($name, $user);
        if ($answer) return $answer;
    }

    // Words that may be part of an event's name ("hackathon"), besides the filters below.
    $keywords = assistant_basic_keywords($m);
    foreach ($orgs as $org) {
        $orgWords = array_flip(explode(' ', trim(assistant_basic_norm($org['name']))));
        $keywords = implode(' ', array_filter(explode(' ', $keywords), fn($w) => $w !== '' && !isset($orgWords[$w])));
    }

    $category = assistant_basic_category($m);
    $eventWords = (bool) preg_match('/ (event|events|happening|happenings|activity|activities|ganap|kaganapan|program|programs|schedule|schedules|whats on) /', $m);
    $past = assistant_basic_has($m, ASSISTANT_BASIC_PAST);
    $upcoming = assistant_basic_has($m, ASSISTANT_BASIC_UPCOMING);
    $cancelled = assistant_basic_has($m, ASSISTANT_BASIC_CANCELLED);
    $audience = assistant_basic_has($m, ['faculty', 'teachers', 'teacher', 'professors', 'instructors']) ? 'faculty'
        : (assistant_basic_has($m, ['students', 'student']) ? 'students' : '');
    $venue = assistant_basic_has($m, ['online', 'webinar', 'webinars', 'virtual', 'zoom']) ? 'online'
        : (assistant_basic_has($m, ['off campus', 'outside campus', 'outside the campus']) ? 'off_campus'
        : (assistant_basic_has($m, ['on campus', 'in campus', 'inside campus']) ? 'on_campus' : ''));
    if (!$eventWords && !$category && !$dates && !$past && !$upcoming && !$cancelled && !$orgs && $venue === '') {
        return $name !== '' ? assistant_basic_not_found($name) : null;
    }

    $when = $dates && $dates['today'] ? 'today' : ($past ? 'past' : ($upcoming || !$dates ? 'upcoming' : 'any'));
    if ($cancelled && !$dates && !$past && !$upcoming) $when = 'any';
    $input = ['when' => $when, 'limit' => $cancelled ? 15 : 6];
    if ($dates) {
        $input['date_from'] = $dates['from'];
        $input['date_to'] = $dates['to'];
    }
    if ($orgs) $input['organization'] = $orgs[0]['name'];
    if ($category) $input['category'] = $category['name'];
    if ($audience !== '') $input['audience'] = $audience;
    if ($venue !== '') $input['venue_type'] = $venue;
    if ($cancelled) $input['include_cancelled'] = true;

    // With the extra words first (at any time, unless a time was asked for); if no event has them, without (and say so).
    $found = null;
    $missed = '';
    if ($keywords !== '') {
        $timeAsked = $dates || $past || $upcoming;
        $found = assistant_tool_search_events(['keywords' => $keywords] + ($timeAsked ? [] : ['when' => 'any']) + $input, $user);
        if (!empty($found['events']) && !$timeAsked) $when = 'any';
        if (empty($found['events']) && !isset($found['error'])) {
            $missed = $keywords;
            $found = null;
        }
    }
    $found ??= assistant_tool_search_events($input, $user);
    if (isset($found['error'])) return assistant_basic_say("Sorry, I couldn't load the events right now. Please try again in a moment.");
    $events = $found['events'] ?? [];
    $total = (int) ($found['found'] ?? 0);
    if ($cancelled) {
        $events = array_values(array_filter($events, fn($e) => ($e['status'] ?? '') === 'cancelled'));
        $total = count($events);
        $events = array_slice($events, 0, 6);
    }

    $subject = ($category ? assistant_basic_plural($category['name']) : 'events')
        . ($keywords !== '' && $missed === '' ? ' matching "' . $keywords . '"' : '')
        . ($orgs ? ' by ' . $orgs[0]['name'] : '')
        . ($audience !== '' ? ' for ' . $audience : '')
        . ['online' => ' online', 'off_campus' => ' off campus', 'on_campus' => ' on campus', '' => ''][$venue];
    $span = $dates && !$dates['today'] ? ' ' . $dates['label'] : '';
    [$intro, $none] = match (true) {
        $cancelled => ['Cancelled ' . $subject . $span . ':', 'No ' . $subject . $span . ' were cancelled.'],
        $when === 'today' => [ucfirst($subject) . ' happening today:', 'No ' . $subject . ' are happening today.'],
        $when === 'past' => [ucfirst($subject) . ' that already ended' . $span . ' (most recent first):', 'No ' . $subject . ' have ended' . $span . ' yet.'],
        $when === 'upcoming' => [ucfirst($subject) . ' coming up' . $span . ':', 'There are no upcoming ' . $subject . $span . ' right now.'],
        default => [ucfirst($subject) . $span . ':', 'No ' . $subject . $span . '.'],
    };
    $calendar = BASE_URL . '/events.php';
    $query = array_filter([
        'organization' => $orgs[0]['id'] ?? '',
        'category' => $category['id'] ?? '',
        'audience' => $audience,
        'venue_type' => $venue,
        'month' => $dates ? substr($dates['from'], 0, 7) : '',
        'date' => $dates && $dates['from'] === $dates['to'] ? $dates['from'] : '',
    ]);
    if ($query) $calendar .= '?' . http_build_query($query);

    $prefix = $missed !== '' ? 'I couldn\'t find any event matching "' . $missed . '". ' : '';
    if (!$events) {
        return assistant_basic_say($prefix . $none . ($when === 'upcoming'
            ? ' Check the [Event Calendar](' . $calendar . ') later, or ask me which events already ended.'
            : ' The [Event Calendar](' . $calendar . ') has everything.'));
    }
    // "I couldn't find any event matching "hackathon". These are the events coming up next week:"
    $heading = $prefix !== '' ? $prefix . 'These are the ' . lcfirst($intro) : $intro;
    return assistant_basic_say(assistant_basic_list($events, $total, $heading, $calendar));
}

/** One event by its name words: its details if only one matches, a short list if several, null if none. */
function assistant_basic_named_event(string $keywords, ?array $user): ?array
{
    $found = assistant_tool_search_events(['keywords' => $keywords, 'when' => 'any', 'limit' => 6, 'include_cancelled' => true], $user);
    $events = $found['events'] ?? [];
    if (!$events) return null;
    // One match, or one whose title is exactly the name asked about.
    $exact = array_values(array_filter($events, fn($e) => trim(assistant_basic_norm($e['title'])) === trim(assistant_basic_norm($keywords))));
    $one = count($events) === 1 ? $events[0] : (count($exact) === 1 ? $exact[0] : null);
    if ($one) {
        $details = assistant_tool_event_details(['event_id' => $one['id']], $user);
        if (!isset($details['error'])) return assistant_basic_say(assistant_basic_event_reply($details, $user));
    }
    return assistant_basic_say(assistant_basic_list($events, (int) $found['found'], 'Here\'s what I found for "' . $keywords . '":', $found['event_calendar']));
}

// =============================================================================
// WRITING THE ANSWER
// =============================================================================

/** An event title made safe inside [link text]. */
function assistant_basic_link_text(string $title): string
{
    return str_replace(['[', ']'], ['(', ')'], $title);
}

/** "Seminar" -> "seminars", "Sports" -> "sports events", "Cultural" -> "cultural events". */
function assistant_basic_plural(string $category): string
{
    $c = mb_strtolower(trim($category));
    return preg_match('/(al|ic|s|other)$/', $c) ? $c . ' events' : $c . 's';
}

/** "- [Title](url) — Friday, Oct 23, 2026 · 1:00 PM – 3:00 PM · Venue · by Org (ended)" */
function assistant_basic_event_line(array $e): string
{
    $parts = array_filter([$e['date'] ?? '', $e['time'] ?? '', $e['venue'] ?? '']);
    $line = '- [' . assistant_basic_link_text($e['title']) . '](' . $e['url'] . ') — ' . implode(' · ', $parts);
    if (!empty($e['organizers'])) $line .= ' · by ' . $e['organizers'];
    $status = (string) ($e['status'] ?? '');
    if ($status !== '' && !str_starts_with($status, 'upcoming — in ')) {
        $line .= ' (' . str_replace('upcoming — ', '', $status) . ')';
    }
    return $line;
}

function assistant_basic_list(array $events, int $total, string $intro, string $calendarUrl): string
{
    $text = $intro . "\n" . implode("\n", array_map('assistant_basic_event_line', $events));
    $more = $total - count($events);
    return $text . ($more > 0 ? "\n\n…and $more more on the [Event Calendar]($calendarUrl)." : '');
}

/** One event's details (from assistant_tool_event_details()). */
function assistant_basic_event_reply(array $d, ?array $user): string
{
    $lines = ['**[' . assistant_basic_link_text($d['title']) . '](' . $d['url'] . ')** — ' . $d['status']];
    $lines[] = '- When: ' . $d['date'] . (!empty($d['time']) ? ', ' . $d['time'] : '');
    $lines[] = '- Where: ' . $d['venue'] . (!empty($d['address']) ? ' — ' . $d['address'] : '');
    if (!empty($d['online'])) $lines[] = '- Online: the join link is on the event\'s page' . ($user ? '' : ' (after logging in)');
    $lines[] = '- Organized by: ' . $d['organizers'];
    $lines[] = '- For: ' . $d['for'];
    if (!empty($d['registration'])) $lines[] = '- Registration: ' . $d['registration'];
    if (isset($d['my_registration']) && $d['my_registration'] !== 'not registered') {
        $evaluation = $d['my_evaluation'] ?? '';
        $lines[] = '- You: ' . $d['my_registration']
            . ($evaluation === 'done' ? ', evaluated' : ($evaluation !== '' ? ' — [evaluate it](' . BASE_URL . '/enduser/evaluate.php?event=' . rawurlencode($d['id']) . ')' : ''));
    }
    if (is_array($d['schedule'] ?? null)) {
        $parts = array_map(fn($p) => ($p['part'] ?? '') . (!empty($p['speaker']) ? ' (' . $p['speaker'] . ')' : ''), $d['schedule']);
        $lines[] = '- Schedule: ' . implode(', ', array_slice($parts, 0, 5)) . (count($parts) > 5 ? ', …' : '');
    }
    if (!empty($d['contact'])) $lines[] = '- Contact: ' . $d['contact'];
    $text = implode("\n", $lines);
    if (!empty($d['description'])) $text .= "\n\n" . excerpt($d['description'], 260);
    if (!$user) $text .= "\n\nLog in to open its page and register.";
    return $text;
}

/** One organization (from assistant_org_details()). */
function assistant_basic_org_reply(array $org): string
{
    $parts = ['**[' . assistant_basic_link_text($org['name']) . '](' . $org['page'] . ')**'
        . (!empty($org['department']) && $org['department'] !== '—' ? ' — ' . $org['department'] : '')];
    if (!empty($org['about'])) $parts[] = $org['about'];
    $parts[] = !empty($org['officers'])
        ? 'Officers' . (!empty($org['officers_school_year']) ? ' (SY ' . $org['officers_school_year'] . ')' : '') . ":\n- " . implode("\n- ", $org['officers'])
        : 'No officers are listed yet.';
    $parts[] = !empty($org['upcoming_events'])
        ? "Upcoming events:\n" . implode("\n", array_map(fn($e) => '- [' . assistant_basic_link_text($e['title']) . '](' . $e['url'] . ') — ' . $e['date'], $org['upcoming_events']))
        : 'No upcoming events right now.';
    $parts[] = '[See all its events](' . $org['all_events'] . ')';
    return implode("\n\n", $parts);
}

/** What basic mode understands (and "Sorry, I didn't get that" first when $missed). */
function assistant_basic_help(?array $user, bool $missed = false): string
{
    $examples = [
        "What events are coming up? · What's happening today?",
        'Which events ended this month?',
        'Seminars next week · Events of an organization',
        'When is (event name)?',
        'Who are the officers of (organization)?',
        'How do I register for an event?',
        'Take me to the Event Calendar',
    ];
    if ($user) array_splice($examples, 5, 0, ['What am I registered for? · Do I have events to evaluate?']);
    return ($missed ? "Sorry, I didn't get that. " : '') . "I understand questions like these (in English):\n- " . implode("\n- ", $examples);
}
