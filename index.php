<?php

declare(strict_types=1);

const DB_FILE = __DIR__ . '/data/langtube.sqlite';

$databaseError = initializeDatabase();

$action = $_GET['action'] ?? null;
if ($action !== null) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        switch ($action) {
            case 'captions':
                $videoUrl = trim((string) ($_GET['video_url'] ?? ''));
                $language = trim((string) ($_GET['language'] ?? 'en'));
                $videoId = parseYouTubeVideoId($videoUrl);
                if ($videoId === null) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid YouTube URL.']);
                    exit;
                }

                $captions = fetchCaptions($videoId, $language);
                echo json_encode([
                    'videoId' => $videoId,
                    'captions' => $captions,
                ], JSON_UNESCAPED_UNICODE);
                exit;

            case 'translate':
                $word = trim((string) ($_GET['word'] ?? ''));
                $from = trim((string) ($_GET['from'] ?? 'en'));
                $to = trim((string) ($_GET['to'] ?? 'en'));

                if ($word === '') {
                    http_response_code(400);
                    echo json_encode(['error' => 'Word is required.']);
                    exit;
                }

                $translation = translateWord($word, $from, $to);
                echo json_encode($translation, JSON_UNESCAPED_UNICODE);
                exit;

            case 'toggle_star':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    http_response_code(405);
                    echo json_encode(['error' => 'Use POST for this endpoint.']);
                    exit;
                }

                $payload = json_decode((string) file_get_contents('php://input'), true);
                if (!is_array($payload)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid JSON payload.']);
                    exit;
                }

                $word = trim((string) ($payload['word'] ?? ''));
                $meaning = trim((string) ($payload['meaning'] ?? ''));
                $learningLanguage = trim((string) ($payload['learningLanguage'] ?? ''));
                $nativeLanguage = trim((string) ($payload['nativeLanguage'] ?? ''));

                if ($word === '' || $learningLanguage === '' || $nativeLanguage === '') {
                    http_response_code(400);
                    echo json_encode(['error' => 'Missing required fields.']);
                    exit;
                }

                if ($databaseError !== null) {
                    http_response_code(503);
                    echo json_encode(['error' => 'Vocabulary storage is unavailable right now.']);
                    exit;
                }

                $result = toggleWord(
                    lowercaseWord($word),
                    $meaning,
                    $learningLanguage,
                    $nativeLanguage
                );

                echo json_encode($result, JSON_UNESCAPED_UNICODE);
                exit;

            case 'vocab':
                $learningLanguage = trim((string) ($_GET['learningLanguage'] ?? ''));
                $nativeLanguage = trim((string) ($_GET['nativeLanguage'] ?? ''));
                if ($learningLanguage === '' || $nativeLanguage === '') {
                    http_response_code(400);
                    echo json_encode(['error' => 'learningLanguage and nativeLanguage are required.']);
                    exit;
                }

                if ($databaseError !== null) {
                    echo json_encode(['items' => []], JSON_UNESCAPED_UNICODE);
                    exit;
                }

                $items = getVocabulary($learningLanguage, $nativeLanguage);
                echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);
                exit;

            default:
                http_response_code(404);
                echo json_encode(['error' => 'Unknown action.']);
                exit;
        }
    } catch (Throwable $exception) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Server error.',
            'details' => $exception->getMessage(),
        ]);
        exit;
    }
}

function initializeDatabase(): ?string
{
    $dir = dirname(DB_FILE);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return 'Unable to create data directory.';
        }
    }

    $pdo = getConnection();
    if (!$pdo instanceof PDO) {
        return 'Database connection unavailable.';
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS vocabulary (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            word TEXT NOT NULL,
            meaning TEXT,
            learning_language TEXT NOT NULL,
            native_language TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(word, learning_language, native_language)
        )'
    );

    return null;
}

function getConnection(): ?PDO
{
    static $pdo = null;
    static $failed = false;

    if ($failed) {
        return null;
    }

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    try {
        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (Throwable $exception) {
        $failed = true;
        return null;
    }
}

function stringContains(string $haystack, string $needle): bool
{
    if (function_exists('str_contains')) {
        return str_contains($haystack, $needle);
    }

    if ($needle === '') {
        return true;
    }

    return strpos($haystack, $needle) !== false;
}

function stringStartsWith(string $haystack, string $prefix): bool
{
    if (function_exists('str_starts_with')) {
        return str_starts_with($haystack, $prefix);
    }

    return substr($haystack, 0, strlen($prefix)) === $prefix;
}

function lowercaseWord(string $value): string
{
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($value, 'UTF-8');
    }

    return strtolower($value);
}

function parseYouTubeVideoId(string $url): ?string
{
    $trimmed = trim($url);
    if ($trimmed === '') {
        return null;
    }

    if (preg_match('/^[A-Za-z0-9_-]{11}$/', $trimmed) === 1) {
        return $trimmed;
    }

    $parts = parse_url($trimmed);
    if (!is_array($parts)) {
        return null;
    }

    $host = $parts['host'] ?? '';
    $path = $parts['path'] ?? '';

    if (stringContains($host, 'youtu.be')) {
        $id = trim($path, '/');
        return preg_match('/^[A-Za-z0-9_-]{11}$/', $id) === 1 ? $id : null;
    }

    if (stringContains($host, 'youtube.com')) {
        parse_str($parts['query'] ?? '', $queryParams);
        if (isset($queryParams['v']) && preg_match('/^[A-Za-z0-9_-]{11}$/', (string) $queryParams['v']) === 1) {
            return (string) $queryParams['v'];
        }

        if (stringStartsWith($path, '/shorts/')) {
            $id = explode('/', trim($path, '/'))[1] ?? '';
            return preg_match('/^[A-Za-z0-9_-]{11}$/', $id) === 1 ? $id : null;
        }

        if (stringStartsWith($path, '/embed/')) {
            $id = explode('/', trim($path, '/'))[1] ?? '';
            return preg_match('/^[A-Za-z0-9_-]{11}$/', $id) === 1 ? $id : null;
        }
    }

    return null;
}

function fetchCaptions(string $videoId, string $language): array
{
    $language = preg_replace('/[^A-Za-z-]/', '', $language) ?: 'en';

    $listResponses = fetchTimedtextLists($videoId);
    $manualTracks = extractTracks((string) ($listResponses['manual'] ?? ''));
    $autoTracks = extractTracks((string) ($listResponses['auto'] ?? ''));
    $tracks = mergeTracks($manualTracks, $autoTracks);

    $candidates = buildCaptionCandidates($tracks, $language);

    foreach ($candidates as $candidate) {
        $vtt = downloadCaptionsVtt($videoId, $candidate);
        if ($vtt !== '') {
            $captions = parseVtt($vtt);
            if ($captions !== []) {
                return $captions;
            }
        }

        $xml = downloadCaptionsXml($videoId, $candidate);
        if ($xml !== '') {
            $captions = parseTimedtextXml($xml);
            if ($captions !== []) {
                return $captions;
            }
        }
    }

    return [];
}

function fetchTimedtextLists(string $videoId): array
{
    $endpoints = [
        'https://video.google.com/timedtext',
        'https://www.youtube.com/api/timedtext',
    ];

    foreach ($endpoints as $endpoint) {
        $base = $endpoint . '?type=list&v=' . rawurlencode($videoId);
        $manual = httpGet($base);
        $auto = httpGet($base . '&asrs=1');

        if ($manual !== '' || $auto !== '') {
            return ['manual' => $manual, 'auto' => $auto];
        }
    }

    return ['manual' => '', 'auto' => ''];
}

function mergeTracks(array $manualTracks, array $autoTracks): array
{
    return dedupeCaptionCandidates(array_merge($manualTracks, $autoTracks));
}

function extractTracks(string $listXml): array
{
    $tracks = [];
    if ($listXml === '') {
        return $tracks;
    }

    if (function_exists('simplexml_load_string')) {
        $list = @simplexml_load_string($listXml);
        if ($list !== false && isset($list->track)) {
            foreach ($list->track as $track) {
                $tracks[] = [
                    'lang' => trim((string) ($track['lang_code'] ?? '')),
                    'name' => trim((string) ($track['name'] ?? '')),
                    'kind' => trim((string) ($track['kind'] ?? '')),
                ];
            }
        }
    }

    if ($tracks === []) {
        if (preg_match_all('/<track\s+([^>]+)>/i', $listXml, $matches) > 0) {
            foreach ($matches[1] as $attrs) {
                $lang = '';
                $name = '';
                $kind = '';
                if (preg_match('/lang_code="([^"]*)"/', $attrs, $m) === 1) {
                    $lang = trim((string) $m[1]);
                }
                if (preg_match('/name="([^"]*)"/', $attrs, $m) === 1) {
                    $name = trim((string) html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                }
                if (preg_match('/kind="([^"]*)"/', $attrs, $m) === 1) {
                    $kind = trim((string) $m[1]);
                }

                if ($lang !== '') {
                    $tracks[] = ['lang' => $lang, 'name' => $name, 'kind' => $kind];
                }
            }
        }
    }

    return array_values(array_filter($tracks, static fn(array $t): bool => $t['lang'] !== ''));
}

function buildCaptionCandidates(array $tracks, string $preferredLanguage): array
{
    $orderedLanguages = array_values(array_unique(array_filter([
        $preferredLanguage,
        'en',
        ...array_map(static fn(array $t): string => $t['lang'], $tracks),
    ])));

    $candidates = [];
    foreach ($orderedLanguages as $lang) {
        $matchingTracks = array_values(array_filter($tracks, static fn(array $t): bool => $t['lang'] === $lang));

        foreach ($matchingTracks as $track) {
            $candidates[] = [
                'lang' => $lang,
                'name' => $track['name'] ?? '',
                'kind' => $track['kind'] ?? '',
            ];
        }

        // Explicitly try auto-generated subtitles even if track-list metadata is incomplete.
        $candidates[] = ['lang' => $lang, 'name' => '', 'kind' => 'asr'];

        // And finally try plain language-only fetch.
        $candidates[] = ['lang' => $lang, 'name' => '', 'kind' => ''];
    }

    return dedupeCaptionCandidates($candidates);
}

function dedupeCaptionCandidates(array $candidates): array
{
    $seen = [];
    $unique = [];

    foreach ($candidates as $candidate) {
        $key = implode('|', [
            (string) ($candidate['lang'] ?? ''),
            (string) ($candidate['name'] ?? ''),
            (string) ($candidate['kind'] ?? ''),
        ]);

        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $unique[] = $candidate;
    }

    return $unique;
}

function downloadCaptionsVtt(string $videoId, array $track): string
{
    $query = [
        'v' => $videoId,
        'lang' => (string) ($track['lang'] ?? ''),
        'fmt' => 'vtt',
    ];

    if (($track['name'] ?? '') !== '') {
        $query['name'] = (string) $track['name'];
    }

    if (($track['kind'] ?? '') !== '') {
        $query['kind'] = (string) $track['kind'];
    }

    return fetchTimedtextPayload($query);
}

function downloadCaptionsXml(string $videoId, array $track): string
{
    $query = [
        'v' => $videoId,
        'lang' => (string) ($track['lang'] ?? ''),
    ];

    if (($track['name'] ?? '') !== '') {
        $query['name'] = (string) $track['name'];
    }

    if (($track['kind'] ?? '') !== '') {
        $query['kind'] = (string) $track['kind'];
    }

    return fetchTimedtextPayload($query);
}

function fetchTimedtextPayload(array $query): string
{
    $endpoints = [
        'https://video.google.com/timedtext',
        'https://www.youtube.com/api/timedtext',
    ];

    foreach ($endpoints as $endpoint) {
        $url = $endpoint . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $payload = httpGet($url);
        if ($payload !== '') {
            return $payload;
        }
    }

    return '';
}

function parseTimedtextXml(string $xml): array
{
    if ($xml === '') {
        return [];
    }

    $captions = [];

    if (function_exists('simplexml_load_string')) {
        $root = @simplexml_load_string($xml);
        if ($root !== false && isset($root->text)) {
            foreach ($root->text as $node) {
                $start = (float) ($node['start'] ?? 0);
                $duration = (float) ($node['dur'] ?? 0);
                $end = $duration > 0 ? $start + $duration : $start + 2;
                $text = trim(html_entity_decode((string) $node, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($text === '') {
                    continue;
                }

                $captions[] = [
                    'start' => $start,
                    'end' => $end,
                    'text' => $text,
                ];
            }
        }
    }

    if ($captions === [] && preg_match_all('/<text\b([^>]*)>(.*?)<\/text>/si', $xml, $matches, PREG_SET_ORDER) > 0) {
        foreach ($matches as $match) {
            $attributes = $match[1] ?? '';
            $content = $match[2] ?? '';

            $start = 0.0;
            $duration = 0.0;

            if (preg_match('/\bstart="([^"]+)"/', $attributes, $startMatch) === 1) {
                $start = (float) $startMatch[1];
            }

            if (preg_match('/\bdur="([^"]+)"/', $attributes, $durationMatch) === 1) {
                $duration = (float) $durationMatch[1];
            }

            $end = $duration > 0 ? $start + $duration : $start + 2;
            $text = trim(html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($text === '') {
                continue;
            }

            $captions[] = [
                'start' => $start,
                'end' => $end,
                'text' => $text,
            ];
        }
    }

    return $captions;
}

function parseVtt(string $vtt): array
{
    $lines = preg_split('/\R/', $vtt) ?: [];
    $captions = [];

    $currentStart = null;
    $currentEnd = null;
    $textBuffer = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if ($trimmed === '' && $currentStart !== null && $currentEnd !== null) {
            $text = trim(html_entity_decode(strip_tags(implode(' ', $textBuffer)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($text !== '') {
                $captions[] = [
                    'start' => $currentStart,
                    'end' => $currentEnd,
                    'text' => $text,
                ];
            }

            $currentStart = null;
            $currentEnd = null;
            $textBuffer = [];
            continue;
        }

        if (preg_match('/^((?:\d{2}:)?\d{2}:\d{2}\.\d{3})\s-->\s((?:\d{2}:)?\d{2}:\d{2}\.\d{3})/', $trimmed, $matches) === 1) {
            $currentStart = vttTimeToSeconds($matches[1]);
            $currentEnd = vttTimeToSeconds($matches[2]);
            $textBuffer = [];
            continue;
        }

        if ($currentStart !== null && $currentEnd !== null && !preg_match('/^\d+$/', $trimmed)) {
            $textBuffer[] = $trimmed;
        }
    }

    if ($currentStart !== null && $currentEnd !== null && $textBuffer !== []) {
        $text = trim(html_entity_decode(strip_tags(implode(' ', $textBuffer)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($text !== '') {
            $captions[] = [
                'start' => $currentStart,
                'end' => $currentEnd,
                'text' => $text,
            ];
        }
    }

    return $captions;
}

function vttTimeToSeconds(string $timestamp): float
{
    $parts = explode(':', $timestamp);

    if (count($parts) === 3) {
        [$hours, $minutes, $seconds] = $parts;
        return ((int) $hours * 3600) + ((int) $minutes * 60) + (float) $seconds;
    }

    if (count($parts) === 2) {
        [$minutes, $seconds] = $parts;
        return ((int) $minutes * 60) + (float) $seconds;
    }

    return 0.0;
}

function translateWord(string $word, string $from, string $to): array
{
    $cleanWord = trim($word);
    $from = preg_replace('/[^A-Za-z-]/', '', $from) ?: 'en';
    $to = preg_replace('/[^A-Za-z-]/', '', $to) ?: 'en';

    $url = 'https://api.mymemory.translated.net/get?q=' . rawurlencode($cleanWord)
        . '&langpair=' . rawurlencode($from . '|' . $to);

    $response = httpGet($url);
    if ($response === '') {
        return [
            'word' => $cleanWord,
            'translation' => 'No translation found',
            'source' => 'unavailable',
        ];
    }

    $data = json_decode($response, true);
    $translated = trim((string) ($data['responseData']['translatedText'] ?? ''));

    if ($translated === '') {
        $translated = 'No translation found';
    }

    return [
        'word' => $cleanWord,
        'translation' => html_entity_decode($translated, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'source' => 'MyMemory',
    ];
}

function httpGet(string $url): string
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'header' => "User-Agent: LangTube/1.0\r\n",
        ],
    ]);

    $result = @file_get_contents($url, false, $context);
    return $result === false ? '' : $result;
}

function toggleWord(string $word, string $meaning, string $learningLanguage, string $nativeLanguage): array
{
    $pdo = getConnection();
    if (!$pdo instanceof PDO) {
        return ['starred' => false, 'message' => 'Vocabulary storage unavailable.'];
    }

    $select = $pdo->prepare(
        'SELECT id FROM vocabulary WHERE word = :word AND learning_language = :learning_language AND native_language = :native_language'
    );
    $select->execute([
        ':word' => $word,
        ':learning_language' => $learningLanguage,
        ':native_language' => $nativeLanguage,
    ]);

    $existing = $select->fetch(PDO::FETCH_ASSOC);

    if ($existing !== false) {
        $delete = $pdo->prepare('DELETE FROM vocabulary WHERE id = :id');
        $delete->execute([':id' => $existing['id']]);

        return ['starred' => false, 'message' => 'Word removed from vocabulary.'];
    }

    $insert = $pdo->prepare(
        'INSERT INTO vocabulary (word, meaning, learning_language, native_language) VALUES (:word, :meaning, :learning_language, :native_language)'
    );
    $insert->execute([
        ':word' => $word,
        ':meaning' => $meaning,
        ':learning_language' => $learningLanguage,
        ':native_language' => $nativeLanguage,
    ]);

    return ['starred' => true, 'message' => 'Word added to vocabulary.'];
}

function getVocabulary(string $learningLanguage, string $nativeLanguage): array
{
    $pdo = getConnection();
    if (!$pdo instanceof PDO) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT word, meaning, created_at
         FROM vocabulary
         WHERE learning_language = :learning_language
           AND native_language = :native_language
         ORDER BY created_at DESC'
    );
    $stmt->execute([
        ':learning_language' => $learningLanguage,
        ':native_language' => $nativeLanguage,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>LangTube - Learn Languages with YouTube</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f5f7fb;
            --panel: #ffffff;
            --line: #dce3f0;
            --text: #14213d;
            --muted: #5b6785;
            --accent: #2563eb;
            --accent-soft: #dbeafe;
            --good: #16a34a;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Inter, Arial, sans-serif;
            color: var(--text);
            background: var(--bg);
        }

        header {
            background: linear-gradient(120deg, #1d4ed8, #3b82f6);
            color: #fff;
            padding: 1.2rem;
        }

        header h1 {
            margin: 0 0 .35rem;
            font-size: 1.45rem;
        }

        header p {
            margin: 0;
            opacity: .95;
        }

        .container {
            max-width: 1160px;
            margin: 1.2rem auto;
            padding: 0 1rem 2rem;
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1rem;
        }

        .card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 1rem;
            box-shadow: 0 4px 14px rgba(18, 41, 87, .07);
        }

        .controls {
            display: grid;
            gap: .75rem;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .controls .full { grid-column: 1 / -1; }

        label {
            display: block;
            font-size: .88rem;
            margin-bottom: .25rem;
            color: var(--muted);
        }

        input, select, button {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 8px;
            font-size: .95rem;
            padding: .6rem .72rem;
            background: #fff;
        }

        button {
            background: var(--accent);
            color: #fff;
            border-color: var(--accent);
            cursor: pointer;
            font-weight: 600;
        }

        button:hover { filter: brightness(1.05); }

        #player {
            width: 100%;
            aspect-ratio: 16 / 9;
            border-radius: 12px;
            overflow: hidden;
            margin-top: 1rem;
            border: 1px solid var(--line);
        }

        .caption-box {
            margin-top: 1rem;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: .75rem;
            background: #f9fbff;
            min-height: 122px;
        }

        #captionText {
            font-size: 1.05rem;
            line-height: 1.7;
        }

        .word {
            padding: .1rem .22rem;
            border-radius: 6px;
            cursor: pointer;
            transition: background .2s;
        }

        .word:hover {
            background: var(--accent-soft);
        }

        .translation-panel h3,
        .vocab-panel h3 {
            margin: 0 0 .65rem;
        }

        .result {
            padding: .75rem;
            border: 1px dashed var(--line);
            border-radius: 10px;
            background: #fbfcff;
            min-height: 98px;
        }

        .result strong { color: var(--accent); }

        .star-btn {
            margin-top: .7rem;
            background: #f59e0b;
            border-color: #f59e0b;
        }

        .vocab-list {
            list-style: none;
            padding: 0;
            margin: 0;
            display: grid;
            gap: .5rem;
            max-height: 520px;
            overflow: auto;
        }

        .vocab-list li {
            padding: .55rem .65rem;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #fff;
            font-size: .92rem;
        }

        .pill {
            display: inline-block;
            font-size: .74rem;
            color: var(--good);
            background: #dcfce7;
            padding: .16rem .45rem;
            border-radius: 99px;
            margin-left: .35rem;
        }

        @media (max-width: 960px) {
            .container { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<header>
    <h1>LangTube</h1>
    <p>Learn from YouTube captions. Click words to translate and save them to your vocabulary list.</p>
</header>

<main class="container">
    <section class="card">
        <div class="controls">
            <div>
                <label for="learningLanguage">Language to learn</label>
                <select id="learningLanguage">
                    <option value="en">English</option>
                    <option value="es">Spanish</option>
                    <option value="fr">French</option>
                    <option value="de">German</option>
                    <option value="it">Italian</option>
                    <option value="pt">Portuguese</option>
                    <option value="ar">Arabic</option>
                    <option value="ja">Japanese</option>
                    <option value="ko">Korean</option>
                    <option value="zh">Chinese</option>
                </select>
            </div>
            <div>
                <label for="nativeLanguage">Native language</label>
                <select id="nativeLanguage">
                    <option value="en">English</option>
                    <option value="es">Spanish</option>
                    <option value="fr">French</option>
                    <option value="de">German</option>
                    <option value="it">Italian</option>
                    <option value="pt">Portuguese</option>
                    <option value="ar">Arabic</option>
                    <option value="ja">Japanese</option>
                    <option value="ko">Korean</option>
                    <option value="zh">Chinese</option>
                </select>
            </div>
            <div class="full">
                <label for="videoUrl">YouTube video URL</label>
                <input id="videoUrl" type="text" placeholder="https://www.youtube.com/watch?v=...">
            </div>
            <div class="full">
                <button id="loadButton">Load Video + Captions</button>
            </div>
        </div>

        <div id="player"></div>

        <div class="caption-box">
            <h3>Synced caption</h3>
            <p id="captionText">Load a video to start.</p>
        </div>
    </section>

    <aside>
        <section class="card translation-panel">
            <h3>Word meaning</h3>
            <div class="result" id="translationResult">
                Click any word in the caption to view its meaning.
            </div>
            <button class="star-btn" id="starButton" disabled>⭐ Star this word</button>
        </section>

        <section class="card vocab-panel" style="margin-top: 1rem;">
            <h3>Your starred vocabulary</h3>
            <ul class="vocab-list" id="vocabList">
                <li>No starred words yet.</li>
            </ul>
        </section>
    </aside>
</main>

<script src="https://www.youtube.com/iframe_api"></script>
<script>
    const state = {
        player: null,
        captions: [],
        selectedWord: null,
        selectedMeaning: null,
        selectedCaptionLanguage: 'en',
        activeCaptionIndex: -1,
    };

    const elements = {
        learningLanguage: document.getElementById('learningLanguage'),
        nativeLanguage: document.getElementById('nativeLanguage'),
        videoUrl: document.getElementById('videoUrl'),
        loadButton: document.getElementById('loadButton'),
        captionText: document.getElementById('captionText'),
        translationResult: document.getElementById('translationResult'),
        starButton: document.getElementById('starButton'),
        vocabList: document.getElementById('vocabList'),
    };

    elements.loadButton.addEventListener('click', loadVideoAndCaptions);
    elements.learningLanguage.addEventListener('change', refreshVocab);
    elements.nativeLanguage.addEventListener('change', refreshVocab);

    elements.starButton.addEventListener('click', async () => {
        if (!state.selectedWord || !state.selectedMeaning) return;

        const response = await fetch('?action=toggle_star', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                word: state.selectedWord,
                meaning: state.selectedMeaning,
                learningLanguage: elements.learningLanguage.value,
                nativeLanguage: elements.nativeLanguage.value,
            }),
        });

        const data = await response.json();
        if (data.message) {
            elements.translationResult.innerHTML += `<div class="pill">${escapeHtml(data.message)}</div>`;
        }
        refreshVocab();
    });

    async function loadVideoAndCaptions() {
        const videoUrl = elements.videoUrl.value.trim();
        const learningLanguage = elements.learningLanguage.value;

        if (!videoUrl) {
            alert('Please enter a YouTube video link.');
            return;
        }

        elements.captionText.textContent = 'Loading captions...';

        const response = await fetch(`?action=captions&video_url=${encodeURIComponent(videoUrl)}&language=${encodeURIComponent(learningLanguage)}`);
        const data = await response.json();

        if (data.error) {
            elements.captionText.textContent = data.error;
            return;
        }

        state.captions = data.captions || [];
        state.selectedCaptionLanguage = learningLanguage;
        state.activeCaptionIndex = -1;

        if (state.captions.length === 0) {
            elements.captionText.textContent = 'No captions found for this video/language.';
        } else {
            elements.captionText.textContent = 'Video loaded. Captions will sync while playing.';
        }

        createOrLoadPlayer(data.videoId);
        refreshVocab();
    }

    function createOrLoadPlayer(videoId) {
        if (state.player && typeof state.player.loadVideoById === 'function') {
            state.player.loadVideoById(videoId);
            return;
        }

        state.player = new YT.Player('player', {
            videoId,
            playerVars: {
                rel: 0,
                modestbranding: 1,
                playsinline: 1,
            },
            events: {
                onReady: () => setInterval(syncCaptionToPlayer, 300),
            },
        });
    }

    function syncCaptionToPlayer() {
        if (!state.player || typeof state.player.getCurrentTime !== 'function') return;
        if (!state.captions.length) return;

        const currentTime = state.player.getCurrentTime();
        const index = state.captions.findIndex(cap => currentTime >= cap.start && currentTime <= cap.end);

        if (index === -1 || index === state.activeCaptionIndex) return;

        state.activeCaptionIndex = index;
        renderCaptionWords(state.captions[index].text);
    }

    function renderCaptionWords(captionText) {
        const words = captionText.split(/(\s+)/).filter(Boolean);
        const html = words.map((token) => {
            if (/^\s+$/.test(token)) {
                return token;
            }
            return `<span class="word" data-word="${escapeHtml(cleanWord(token))}">${escapeHtml(token)}</span>`;
        }).join('');

        elements.captionText.innerHTML = html;

        document.querySelectorAll('.word').forEach((wordElement) => {
            wordElement.addEventListener('click', () => {
                const word = wordElement.dataset.word || '';
                if (!word) return;
                translateAndDisplay(word);
            });
        });
    }

    function cleanWord(token) {
        return token.toLowerCase().replace(/[^\p{L}\p{N}'-]/gu, '');
    }

    async function translateAndDisplay(word) {
        const from = elements.learningLanguage.value;
        const to = elements.nativeLanguage.value;

        const response = await fetch(`?action=translate&word=${encodeURIComponent(word)}&from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`);
        const data = await response.json();

        state.selectedWord = word;
        state.selectedMeaning = data.translation || 'No translation found';

        elements.translationResult.innerHTML = `
            <div><strong>${escapeHtml(word)}</strong></div>
            <div>${escapeHtml(state.selectedMeaning)}</div>
            <small>From ${escapeHtml(from)} to ${escapeHtml(to)} via ${escapeHtml(data.source || 'N/A')}</small>
        `;
        elements.starButton.disabled = false;
    }

    async function refreshVocab() {
        const learningLanguage = elements.learningLanguage.value;
        const nativeLanguage = elements.nativeLanguage.value;

        const response = await fetch(`?action=vocab&learningLanguage=${encodeURIComponent(learningLanguage)}&nativeLanguage=${encodeURIComponent(nativeLanguage)}`);
        const data = await response.json();

        const items = data.items || [];
        if (!items.length) {
            elements.vocabList.innerHTML = '<li>No starred words yet.</li>';
            return;
        }

        elements.vocabList.innerHTML = items
            .map((item) => `<li><strong>${escapeHtml(item.word)}</strong>: ${escapeHtml(item.meaning || '-')}</li>`)
            .join('');
    }

    function escapeHtml(value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    refreshVocab();
</script>
</body>
</html>
