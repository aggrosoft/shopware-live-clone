<?php
declare(strict_types=1);
$method = $_SERVER['REQUEST_METHOD'];
file_put_contents(getenv('S3_TEST_LOG'), $method . ' ' . $_SERVER['REQUEST_URI'] . "\n", FILE_APPEND);
if (!in_array($method, ['GET', 'HEAD'], true)) { http_response_code(405); exit; }
$objects = ['public/media/product.svg' => '<svg>fixture image</svg>', 'public/thumbnail/product.svg' => '<svg>fixture thumbnail</svg>', 'public/sitemap/live.xml' => '<live/>', 'private/document.pdf' => 'fixture document'];
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if ($path === '/fixture' || $path === '/fixture/') {
    header('Content-Type: application/xml');
    if (isset($_GET['location'])) { echo '<LocationConstraint>us-east-1</LocationConstraint>'; exit; }
    $prefix = $_GET['prefix'] ?? '';
    $delimiter = $_GET['delimiter'] ?? '';
    $directories = [];
    echo '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Name>fixture</Name><Prefix>' . htmlspecialchars($prefix, ENT_XML1) . '</Prefix><MaxKeys>1000</MaxKeys><IsTruncated>false</IsTruncated>';
    foreach ($objects as $key => $value) {
        if (!str_starts_with($key, $prefix)) { continue; }
        $relative = substr($key, strlen($prefix));
        if ($delimiter !== '' && ($position = strpos($relative, $delimiter)) !== false) {
            $directories[$prefix . substr($relative, 0, $position + strlen($delimiter))] = true;
            continue;
        }
        echo '<Contents><Key>' . $key . '</Key><LastModified>2020-01-01T00:00:00.000Z</LastModified><ETag>"' . md5($value) . '"</ETag><Size>' . strlen($value) . '</Size><StorageClass>STANDARD</StorageClass></Contents>';
    }
    foreach (array_keys($directories) as $directory) {
        echo '<CommonPrefixes><Prefix>' . htmlspecialchars($directory, ENT_XML1) . '</Prefix></CommonPrefixes>';
    }
    echo '</ListBucketResult>';
    exit;
}
$key = substr($path, strlen('/fixture/'));
if (!isset($objects[$key])) { http_response_code(404); exit; }
$body = $objects[$key];
header('Content-Length: ' . strlen($body));
header('ETag: "' . md5($body) . '"');
header('Last-Modified: Wed, 01 Jan 2020 00:00:00 GMT');
if ($method === 'GET') { echo $body; }
