<?php
/**
 * Import & Verify helpers (admin/import.php): reading uploaded spreadsheets
 * — Excel workbooks (.xlsx) and CSV files, first sheet — and comparing
 * their rows with the student accounts or an event's registrations.
 *
 * An .xlsx file is a ZIP of XML files. It's unpacked here with plain PHP
 * (gzinflate), so XAMPP's "zip" extension doesn't have to be switched on.
 */

const SPREADSHEET_MAX_ROWS = 5000;
const SPREADSHEET_MAX_COLS = 40;

/**
 * Rows of the first sheet as lists of trimmed strings (empty rows skipped).
 * @return array{ok:bool, rows?:array<int, string[]>, message?:string}
 */
function read_spreadsheet(string $path, string $originalName): array
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $result = match ($ext) {
        'xlsx' => read_xlsx_file($path),
        'csv', 'txt' => read_csv_file($path),
        'xls' => ['ok' => false, 'message' => 'Old Excel (.xls) files can\'t be read. In Excel choose File → Save As → Excel Workbook (.xlsx) or CSV, then upload that file.'],
        default => ['ok' => false, 'message' => 'Please upload an Excel workbook (.xlsx) or a CSV file.'],
    };
    if (!$result['ok']) return $result;

    $rows = [];
    foreach ($result['rows'] as $rowNumber => $row) {
        $row = array_map(fn($c) => trim(preg_replace('/\s+/u', ' ', (string) $c)), array_slice($row, 0, SPREADSHEET_MAX_COLS));
        if (implode('', $row) === '') continue;
        $rows[$rowNumber] = $row; // keyed by the row number in the file (for "row 12" in the results)
        if (count($rows) > SPREADSHEET_MAX_ROWS) {
            return ['ok' => false, 'message' => 'That file has more than ' . SPREADSHEET_MAX_ROWS . ' rows. Split it into smaller files.'];
        }
    }
    if (count($rows) < 2) {
        return ['ok' => false, 'message' => 'The file needs a header row (e.g. School ID, Name) and at least one row of data.'];
    }
    return ['ok' => true, 'rows' => $rows];
}

// ---- CSV -------------------------------------------------------------------

function read_csv_file(string $path): array
{
    $text = (string) file_get_contents($path);
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);            // UTF-8 BOM (Excel adds it)
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252'); // older Excel "CSV" saves
    }
    // Excel uses ; or tabs in some regions: pick the separator the header line uses most.
    $firstLine = strtok($text, "\r\n") ?: '';
    $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
    arsort($counts);
    $separator = array_key_first($counts);

    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $text);
    rewind($stream);
    $rows = [];
    while (($row = fgetcsv($stream, 0, $separator, '"', '')) !== false) {
        $rows[count($rows) + 1] = $row;
        if (count($rows) > SPREADSHEET_MAX_ROWS + 50) break;
    }
    fclose($stream);
    return ['ok' => true, 'rows' => $rows];
}

// ---- XLSX ------------------------------------------------------------------

function read_xlsx_file(string $path): array
{
    $zip = (string) file_get_contents($path);
    $entries = zip_list_entries($zip);
    if ($entries === null || !isset($entries['xl/workbook.xml'])) {
        return ['ok' => false, 'message' => 'That file isn\'t a readable Excel workbook. Try saving it again as .xlsx or CSV.'];
    }

    // Which file holds the first sheet (usually xl/worksheets/sheet1.xml).
    $sheetPath = 'xl/worksheets/sheet1.xml';
    $workbook = xml_document(zip_read_entry($zip, $entries['xl/workbook.xml']));
    $rels = isset($entries['xl/_rels/workbook.xml.rels']) ? xml_document(zip_read_entry($zip, $entries['xl/_rels/workbook.xml.rels'])) : null;
    if ($workbook && $rels) {
        $firstSheet = $workbook->getElementsByTagNameNS('*', 'sheet')->item(0);
        $relId = $firstSheet?->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
        foreach ($rels->getElementsByTagNameNS('*', 'Relationship') as $rel) {
            if ($relId && $rel->getAttribute('Id') === $relId) {
                $target = ltrim($rel->getAttribute('Target'), '/');
                $sheetPath = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
            }
        }
    }
    if (!isset($entries[$sheetPath])) {
        return ['ok' => false, 'message' => 'The workbook\'s first sheet couldn\'t be found.'];
    }

    // Text cells point into the shared strings list.
    $shared = [];
    if (isset($entries['xl/sharedStrings.xml'])) {
        $sst = xml_document(zip_read_entry($zip, $entries['xl/sharedStrings.xml']));
        foreach ($sst ? $sst->getElementsByTagNameNS('*', 'si') : [] as $si) {
            $text = '';
            foreach ($si->getElementsByTagNameNS('*', 't') as $t) {
                // Skip phonetic hints (<rPh>) some Asian-language files include.
                if ($t->parentNode && $t->parentNode->localName === 'rPh') continue;
                $text .= $t->textContent;
            }
            $shared[] = $text;
        }
    }

    $sheet = xml_document(zip_read_entry($zip, $entries[$sheetPath]));
    if (!$sheet) {
        return ['ok' => false, 'message' => 'The first sheet couldn\'t be read.'];
    }
    $rows = [];
    foreach ($sheet->getElementsByTagNameNS('*', 'row') as $rowNode) {
        $row = [];
        $next = 0;
        foreach ($rowNode->getElementsByTagNameNS('*', 'c') as $c) {
            $col = $c->hasAttribute('r') ? xlsx_column_index($c->getAttribute('r')) : $next;
            $next = $col + 1;
            if ($col >= SPREADSHEET_MAX_COLS) continue;
            $row[$col] = xlsx_cell_value($c, $shared);
        }
        $rowNumber = $rowNode->hasAttribute('r') ? (int) $rowNode->getAttribute('r') : count($rows) + 1;
        if ($row) {
            $filled = array_fill(0, max(array_keys($row)) + 1, '');
            $rows[$rowNumber] = array_replace($filled, $row);
        }
        if (count($rows) > SPREADSHEET_MAX_ROWS + 50) break;
    }
    return ['ok' => true, 'rows' => $rows];
}

/** 'C12' -> 2 (zero-based column). */
function xlsx_column_index(string $ref): int
{
    $letters = preg_replace('/[^A-Z]/', '', strtoupper($ref));
    $n = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }
    return max(0, $n - 1);
}

function xlsx_cell_value(DOMElement $c, array $shared): string
{
    $type = $c->getAttribute('t');
    $v = $c->getElementsByTagNameNS('*', 'v')->item(0)?->textContent ?? '';
    switch ($type) {
        case 's':
            return $shared[(int) $v] ?? '';
        case 'inlineStr':
            $text = '';
            foreach ($c->getElementsByTagNameNS('*', 't') as $t) $text .= $t->textContent;
            return $text;
        case 'b':
            return $v === '1' ? 'TRUE' : 'FALSE';
        case 'str':
        case 'e':
            return $v;
        default:
            // Numbers: 2023001 stays "2023001" (not "2.023001E+6"), 3.50 -> "3.5".
            if (is_numeric($v)) {
                $f = (float) $v;
                if (floor($f) === $f && abs($f) < 1e15) return sprintf('%.0f', $f);
                return rtrim(rtrim(sprintf('%.10F', $f), '0'), '.');
            }
            return $v;
    }
}

function xml_document(?string $xml): ?DOMDocument
{
    if ($xml === null || $xml === '') return null;
    $doc = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return $ok ? $doc : null;
}

/**
 * Files inside a ZIP (from its central directory).
 * @return array<string, array{method:int, size:int, offset:int}>|null
 */
function zip_list_entries(string $zip): ?array
{
    $eocd = strrpos(substr($zip, -65557), "PK\x05\x06");
    if ($eocd === false) return null;
    $eocd += max(0, strlen($zip) - 65557);
    $end = unpack('vdisk/vcddisk/vdiskentries/ventries/Vcdsize/Vcdoffset', substr($zip, $eocd + 4, 16));
    if (!$end) return null;

    $entries = [];
    $pos = $end['cdoffset'];
    for ($i = 0; $i < $end['entries']; $i++) {
        if (substr($zip, $pos, 4) !== "PK\x01\x02") return null;
        $h = unpack('vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnamelen/vextralen/vcommentlen/vdisk/vinternal/Vexternal/Voffset', substr($zip, $pos + 4, 42));
        $name = substr($zip, $pos + 46, $h['namelen']);
        $entries[$name] = ['method' => $h['method'], 'size' => $h['csize'], 'offset' => $h['offset']];
        $pos += 46 + $h['namelen'] + $h['extralen'] + $h['commentlen'];
    }
    return $entries;
}

/** Contents of one file inside a ZIP (stored or deflated), or null. */
function zip_read_entry(string $zip, array $entry): ?string
{
    $pos = $entry['offset'];
    if (substr($zip, $pos, 4) !== "PK\x03\x04") return null;
    $h = unpack('vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnamelen/vextralen', substr($zip, $pos + 4, 26));
    $data = substr($zip, $pos + 30 + $h['namelen'] + $h['extralen'], $entry['size']);
    if ($entry['method'] === 0) return $data;
    if ($entry['method'] === 8) {
        $out = @gzinflate($data, 50 * 1024 * 1024); // cap: a small upload can't unpack into a huge file
        return $out === false ? null : $out;
    }
    return null;
}

// ---- Columns and comparisons ------------------------------------------------

/** Header names that mean each field (compared lowercase, letters and digits only). */
const IMPORT_COLUMNS = [
    'school_id' => ['schoolid', 'studentid', 'idnumber', 'idno', 'studentnumber', 'studentno', 'schoolno', 'schoolnumber', 'studno', 'id'],
    'full_name' => ['name', 'fullname', 'studentname', 'completename', 'nameofstudent', 'student'],
    'last_name' => ['lastname', 'surname', 'familyname'],
    'first_name' => ['firstname', 'givenname'],
    'middle_name' => ['middlename', 'middleinitial', 'mi'],
    'course' => ['course', 'program', 'degree', 'courseprogram', 'programcourse'],
    'year_level' => ['year', 'yearlevel', 'yr', 'level', 'yearlvl'],
    'email' => ['email', 'emailaddress'],
];

/**
 * Turn the rows into records keyed by field (school_id, name, course,
 * year_level, email) using the header row; unknown columns are ignored.
 * @return array{columns: array<string,int>, records: array<int, array>}
 *         records keep their spreadsheet row number as 'row'
 */
function spreadsheet_records(array $rows): array
{
    $headerRow = array_key_first($rows);
    $header = array_map(fn($h) => preg_replace('/[^a-z0-9]/', '', strtolower($h)), $rows[$headerRow]);
    $columns = [];
    foreach (IMPORT_COLUMNS as $field => $names) {
        foreach ($header as $i => $h) {
            if ($h !== '' && in_array($h, $names, true) && !in_array($i, $columns, true)) {
                $columns[$field] = $i;
                break;
            }
        }
    }
    $records = [];
    foreach ($rows as $rowNumber => $row) {
        if ($rowNumber === $headerRow) continue;
        $get = fn(string $field) => isset($columns[$field]) ? trim((string) ($row[$columns[$field]] ?? '')) : '';
        $name = $get('full_name');
        if ($name === '' && ($get('last_name') !== '' || $get('first_name') !== '')) {
            $name = preg_replace('/\s+/', ' ', trim($get('first_name') . ' ' . $get('middle_name') . ' ' . $get('last_name')));
        }
        $record = [
            'row' => $rowNumber,
            'school_id' => normalize_school_id($get('school_id')),
            'name' => $name,
            'course' => $get('course'),
            'year_level' => year_from_text($get('year_level')),
            'email' => strtolower($get('email')),
        ];
        if ($record['school_id'] === '' && $record['name'] === '' && $record['email'] === '') continue;
        $records[] = $record;
    }
    return ['columns' => $columns, 'records' => $records];
}

/** '3', '3rd', '3rd Year', 'III', 'Third year' -> 3; anything else -> null. */
function year_from_text(string $text): ?int
{
    $t = strtolower(trim($text));
    if ($t === '') return null;
    if (preg_match('/([1-5])/', $t, $m)) return (int) $m[1];
    foreach (['first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'fifth' => 5] as $word => $n) {
        if (str_contains($t, $word)) return $n;
    }
    $roman = ['v' => 5, 'iv' => 4, 'iii' => 3, 'ii' => 2, 'i' => 1];
    $first = preg_split('/[^a-z]+/', $t)[0] ?? '';
    return $roman[$first] ?? null;
}

/** Words of a name for comparing: lowercase, no accents or punctuation, no initials. */
function name_tokens(string $name): array
{
    $name = strtr(mb_strtolower($name), ['ñ' => 'n', 'á' => 'a', 'à' => 'a', 'é' => 'e', 'è' => 'e', 'í' => 'i', 'ì' => 'i', 'ó' => 'o', 'ò' => 'o', 'ú' => 'u', 'ù' => 'u', 'ü' => 'u']);
    $name = preg_replace('/[^a-z]+/', ' ', $name);
    // Surname particles join the next word, so "De La Cruz" = "Dela Cruz" = "delacruz".
    $name = preg_replace_callback('/\b(de la|de los|dela|delos|del|de|san|sta|santa|sto|santo|van|von)\s+(?=[a-z])/',
        fn($m) => str_replace(' ', '', $m[1]), $name);
    $words = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY);
    $words = array_values(array_unique(array_filter($words, fn($w) => strlen($w) > 1 && !in_array($w, ['jr', 'sr', 'ii', 'iii', 'iv'], true))));
    sort($words);
    return $words;
}

/**
 * Whether two names are the same person's, in any order ("Dela Cruz, Juan
 * P." = "Juan Dela Cruz"): every word of the shorter name appears in the
 * longer one, and they share at least two words.
 */
function names_match(string $a, string $b): bool
{
    $ta = name_tokens($a);
    $tb = name_tokens($b);
    if (!$ta || !$tb) return false;
    if ($ta === $tb) return true;
    $shared = count(array_intersect($ta, $tb));
    return $shared >= 2 && $shared === min(count($ta), count($tb));
}

/** How school IDs are compared: '2023-00123' = '2023 00123' = '202300123'. */
function school_id_key(string $schoolId): string
{
    return preg_replace('/[^A-Z0-9]/', '', strtoupper($schoolId));
}

/** 'BS IT' = 'BSIT' = 'bs-it'. */
function same_course(string $a, string $b): bool
{
    $n = fn($s) => preg_replace('/[^A-Z0-9]/', '', strtoupper($s));
    return $n($a) === $n($b);
}

// ---- Comparing a file with the system (admin/import.php) -----------------------

/**
 * Registrar's list vs. student accounts.
 * @return array{matched: array, mismatch: array, no_account: array, duplicate: array, not_in_file: array}
 *   each item: ['record' => file row|null, 'account' => profile|null, 'notes' => string[]]
 */
function compare_roster(array $records, array $accounts, bool $hasIdColumn): array
{
    $out = ['matched' => [], 'mismatch' => [], 'no_account' => [], 'duplicate' => [], 'not_in_file' => []];
    $byId = [];
    foreach ($accounts as $a) {
        if (!empty($a['school_ids']['school_id'])) $byId[school_id_key($a['school_ids']['school_id'])] = $a;
    }
    $seenIds = [];
    $seenAccounts = [];

    foreach ($records as $r) {
        if ($hasIdColumn && $r['school_id'] !== '' && isset($seenIds[school_id_key($r['school_id'])])) {
            $out['duplicate'][] = ['record' => $r, 'account' => null, 'notes' => ['School ID also on row ' . $seenIds[school_id_key($r['school_id'])] . ' of the file.']];
            continue;
        }
        if ($r['school_id'] !== '') $seenIds[school_id_key($r['school_id'])] = $r['row'];

        $account = null;
        $notes = [];
        if ($hasIdColumn && $r['school_id'] !== '') {
            $account = $byId[school_id_key($r['school_id'])] ?? null;
            if (!$account) {
                // Maybe they registered with a different (mistyped) School ID, or none yet.
                foreach ($accounts as $a) {
                    if ($r['name'] !== '' && names_match($r['name'], $a['full_name'])) {
                        $notes[] = 'Possible account: ' . $a['full_name'] . ' (School ID ' . ($a['school_ids']['school_id'] ?? 'not set') . ').';
                        break;
                    }
                }
            }
        } elseif ($r['name'] !== '') {
            $found = array_values(array_filter($accounts, fn($a) => names_match($r['name'], $a['full_name'])));
            if (count($found) === 1) $account = $found[0];
            if (count($found) > 1) $notes[] = count($found) . ' accounts have this name — add a School ID column to tell them apart.';
        }

        if (!$account) {
            $out['no_account'][] = ['record' => $r, 'account' => null, 'notes' => $notes ?: ['No account yet.']];
            continue;
        }
        $seenAccounts[$account['id']] = true;
        if ($r['name'] !== '' && !names_match($r['name'], $account['full_name'])) {
            $notes[] = 'Name: "' . $r['name'] . '" in the file, "' . $account['full_name'] . '" in the account.';
        }
        if ($r['course'] !== '' && !empty($account['course']) && !same_course($r['course'], $account['course'])) {
            $notes[] = 'Course: ' . $r['course'] . ' in the file, ' . $account['course'] . ' in the account.';
        }
        if ($r['year_level'] && !empty($account['year_level']) && (int) $account['year_level'] !== $r['year_level']) {
            $notes[] = 'Year level: ' . ordinal($r['year_level']) . ' in the file, ' . ordinal((int) $account['year_level']) . ' (highest) in the account.';
        }
        if (!$hasIdColumn && empty($account['school_ids']['school_id'])) {
            $notes[] = 'The account has no School ID yet.';
        }
        $out[$notes ? 'mismatch' : 'matched'][] = ['record' => $r, 'account' => $account, 'notes' => $notes];
    }

    foreach ($accounts as $a) {
        if (!isset($seenAccounts[$a['id']]) && ($a['account_type'] ?? '') === 'student') {
            $out['not_in_file'][] = ['record' => null, 'account' => $a, 'notes' => [!empty($a['school_ids']['school_id']) ? 'Not on the list — check the School ID.' : 'No School ID on the account.']];
        }
    }
    return $out;
}

/**
 * Attendance sheet vs. an event's registrations.
 * @return array{to_mark: array, already: array, cancelled: array, walk_in: array, duplicate: array, absent: array}
 *   each item: ['record' => file row|null, 'registration' => row|null, 'notes' => string[]]
 */
function compare_attendance(array $records, array $registrations, bool $hasIdColumn): array
{
    $out = ['to_mark' => [], 'already' => [], 'cancelled' => [], 'walk_in' => [], 'duplicate' => [], 'absent' => []];
    $byId = [];
    foreach ($registrations as $reg) {
        $sid = $reg['profiles']['school_ids']['school_id'] ?? '';
        if ($sid !== '') $byId[school_id_key($sid)] = $reg;
    }
    $seenRows = [];
    $found = [];

    foreach ($records as $r) {
        $key = $r['school_id'] !== '' ? 'id:' . school_id_key($r['school_id']) : 'name:' . implode(' ', name_tokens($r['name']));
        if (isset($seenRows[$key])) {
            $out['duplicate'][] = ['record' => $r, 'registration' => null, 'notes' => ['Also on row ' . $seenRows[$key] . ' of the file.']];
            continue;
        }
        $seenRows[$key] = $r['row'];

        $reg = ($hasIdColumn && $r['school_id'] !== '') ? ($byId[school_id_key($r['school_id'])] ?? null) : null;
        if (!$reg && $r['name'] !== '') {
            $byName = array_values(array_filter($registrations, fn($x) => names_match($r['name'], $x['profiles']['full_name'] ?? '')));
            if (count($byName) === 1) $reg = $byName[0];
        }
        if (!$reg) {
            $out['walk_in'][] = ['record' => $r, 'registration' => null, 'notes' => ['On the sheet but not registered for this event.']];
            continue;
        }
        $found[$reg['id']] = true;
        $bucket = match ($reg['status']) {
            'attended' => 'already',
            'cancelled' => 'cancelled',
            default => 'to_mark', // registered / no_show / waitlisted
        };
        $notes = $bucket === 'cancelled' ? ['Cancelled their registration, but is on the sheet.'] : [];
        $out[$bucket][] = ['record' => $r, 'registration' => $reg, 'notes' => $notes];
    }
    foreach ($registrations as $reg) {
        if (!isset($found[$reg['id']]) && $reg['status'] === 'registered') {
            $out['absent'][] = ['record' => null, 'registration' => $reg, 'notes' => ['Registered, but not on the sheet.']];
        }
    }
    return $out;
}

