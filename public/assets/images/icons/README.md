# UI icons

Every small icon on the site is an image from this folder: simple line icons drawn in blue (`#3d74c9`, 24×24, stroke 1.9). On dark backgrounds (the navy sidebar, blue buttons and circles) `style.css` shows them white, so one file works everywhere. The "Label" column below is the short name the old placeholder boxes showed.

## How to replace an icon

1. Make the real icon (square works best; it's shown at 12–24 px).
2. Save it **over the file with the same name**, e.g. `bell.svg`.
3. Refresh the site. That icon changes everywhere it's used.

If your icons are PNGs, save them with the same names (`bell.png`, `home.png`, …) and change `ICON_EXT` to `'png'` in `config/supabase.php`. Replace **all** of them before switching.

In code, an icon is `<?= icon('bell') ?>` (optional size: `icon('bell', 20)`), defined in `config/supabase.php`.

## The icons

| File | Label | What it means | Where it's used |
|---|---|---|---|
| `home.svg` | HOM | Home | Sidebar |
| `dashboard.svg` | DSH | Dashboard | Sidebar |
| `ticket.svg` | TKT | Events group | Sidebar ("Events" dropdown) |
| `chevron-down.svg` | CHV | Dropdown arrow | Sidebar "Events" dropdown, user menu in the top bar |
| `list.svg` | LST | Next event | Sidebar "Next Event" |
| `check.svg` | CHK | My events | Sidebar |
| `calendar.svg` | CAL | Date / calendar | Sidebar "Event Calendar", event cards, dashboard lists, organizations page, evaluation page, home "All Campus Events" |
| `organizations.svg` | ORG | Organizations | Sidebar, home About section |
| `profile.svg` | PRF | My profile | Sidebar ("My Profile", or "Log in" when logged out) |
| `chart.svg` | CHT | Admin dashboard | Sidebar (admin) |
| `tools.svg` | TLS | Manage events | Sidebar (admin + moderator) |
| `reports.svg` | RPT | Reports | Sidebar (admin) |
| `evaluation.svg` | EVL | Evaluation | Sidebar (admin), dashboard "Please evaluate" box, Manage Events side links |
| `tag.svg` | TAG | Categories | Sidebar (admin) |
| `schedule.svg` | SCH | School calendar / schedule | Sidebar (admin), Manage Events "Edit the schedule" |
| `people.svg` | PPL | People / going / users | Sidebar (admin "Users & Roles", moderator "Our Officers"), event cards "N going", event details, dashboard trending, home "Easy Participation", Manage Events |
| `search.svg` | SRC | Search | Top-bar search box, home About "Discover" |
| `bell.svg` | BEL | Notifications | Top-bar bell, home "Stay Updated" + About |
| `location.svg` | LOC | Venue | Event cards, dashboard lists, organizations page, evaluation page |
| `online.svg` | ONL | Online event | Event cards, event details |
| `campus.svg` | CMP | On / off campus | Event details |
| `audience.svg` | AUD | Who it's for (students / faculty) | Event cards, event details |
| `certificate.svg` | CRT | Certificates | Home "Earn Certificates" |
| `link.svg` | LNK | Online link | Event details "Join online" |
| `email.svg` | EML | Contact | Event details contact line |
| `megaphone.svg` | MEG | Announcements | Home hero "Stay Updated" badge, dashboard Announcements |
| `print.svg` | PRT | Print | Manage Events "Print the attendance sheet" |
| `view.svg` | VEW | View page | Manage Events "View the event page" |
| `register.svg` | REG | Register | Home About "Register", sidebar "Sign up" (logged out) |
| `import.svg` | IMP | Import & Verify (Excel upload) | Sidebar (admin + moderator) |
| `arrow-left.svg` | — | Back | Event details "Back to All Events" |
| `menu.svg` | — | Open the menu | Top bar on tablets and phones (opens the sidebar) |
| `close.svg` | — | Close the menu | Sidebar on tablets and phones |

The login page (`../login/`) and the event details page (`../event-details/`) have their own numbered image slots — see the `IMAGE SLOT` comments in those pages.
