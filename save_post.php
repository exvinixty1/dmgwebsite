<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$raw = file_get_contents("php://input");
if (!$raw) {
    http_response_code(400);
    echo json_encode(["error" => "No input received"]);
    exit;
}
$data = json_decode($raw, true);
if (!$data) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid JSON"]);
    exit;
}

$type = isset($data['type']) ? $data['type'] : null;
if (!$type || !in_array($type, ['post','reply'])) {
    http_response_code(400);
    echo json_encode(["error"=>"Missing or invalid type"]);
    exit;
}

$file = __DIR__ . '/posts.json';

$posts = [];
if (file_exists($file)) {
    $contents = file_get_contents($file);
    $posts = json_decode($contents, true);
    if (!is_array($posts)) $posts = [];
}

$fp = fopen($file, 'c+');
if (!$fp) {
    http_response_code(500);
    echo json_encode(["error" => "Cannot open posts file for writing"]);
    exit;
}

if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    http_response_code(500);
    echo json_encode(["error" => "Could not acquire file lock"]);
    exit;
}

$filesize = filesize($file);
rewind($fp);
$rawExisting = ($filesize > 0) ? stream_get_contents($fp) : '';
$existingPosts = json_decode($rawExisting, true);
if (!is_array($existingPosts)) $existingPosts = [];

function s($str){
    return htmlspecialchars(trim($str), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

date_default_timezone_set('EST');

if ($type === 'post') {
    $author = isset($data['author']) ? s($data['author']) : 'Guest';
    $message = isset($data['message']) ? s($data['message']) : '';
    if ($message === '') {
        flock($fp, LOCK_UN);
        fclose($fp);
        http_response_code(400);
        echo json_encode(["error" => "Message cannot be empty"]);
        exit;
    }
    $maxId = 0;
    foreach ($existingPosts as $p) {
        if (isset($p['id']) && is_numeric($p['id'])) {
            $maxId = max($maxId, intval($p['id']));
        }
    }
    $newId = $maxId + 1;
    $newPost = [
        'id' => $newId,
        'author' => $author,
        'message' => $message,
        'time' => date('Y-m-d H:i:s'),
        'replies' => []
    ];
    $existingPosts[] = $newPost;

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($existingPosts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    echo json_encode(['success' => true, 'id' => $newId]);
    exit;
}

if ($type === 'reply') {
    $parentId = isset($data['parentId']) ? $data['parentId'] : null;
    $author = isset($data['author']) ? s($data['author']) : 'Guest';
    $message = isset($data['message']) ? s($data['message']) : '';
    if ($message === '') {
        flock($fp, LOCK_UN);
        fclose($fp);
        http_response_code(400);
        echo json_encode(["error" => "Reply message cannot be empty"]);
        exit;
    }
    if ($parentId === null) {
        flock($fp, LOCK_UN);
        fclose($fp);
        http_response_code(400);
        echo json_encode(["error" => "Missing parentId for reply"]);
        exit;
    }

    $found = false;
    for ($i = 0; $i < count($existingPosts); $i++) {
        if (isset($existingPosts[$i]['id']) && strval($existingPosts[$i]['id']) === strval($parentId)) {
            if (!isset($existingPosts[$i]['replies']) || !is_array($existingPosts[$i]['replies'])) {
                $existingPosts[$i]['replies'] = [];
            }
            $existingPosts[$i]['replies'][] = [
                'author' => $author,
                'message' => $message,
                'time' => date('Y-m-d H:i:s'),
                'replies' => []
            ];
            $found = true;
            break;
        }
    }

    if (!$found) {
        flock($fp, LOCK_UN);
        fclose($fp);
        http_response_code(400);
        echo json_encode(["error" => "Parent post not found"]);
        exit;
    }

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($existingPosts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    echo json_encode(['success' => true]);
    exit;
}

flock($fp, LOCK_UN);
fclose($fp);
http_response_code(400);
echo json_encode(['error'=>'Unhandled request']);
exit;
?>
