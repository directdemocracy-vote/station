<?php
require_once '../php/database.php';

function error($message) {
  # FIXME: publish refused ballot.
  die("{\"error\":\"$message\"}");
}

function public_key($key) {
  $public_key = "-----BEGIN PUBLIC KEY-----\n";
  $l = strlen($key);
  for($i = 0; $i < $l; $i += 64)
    $public_key .= substr($key, $i, 64) . "\n";
  $public_key.= "-----END PUBLIC KEY-----";
  return $public_key;
}

function stripped_key($public_key) {
  $stripped = str_replace("-----BEGIN PUBLIC KEY-----", "", $public_key);
  $stripped = str_replace("-----END PUBLIC KEY-----", "", $stripped);
  $stripped = str_replace("\r\n", '', $stripped);
  $stripped = str_replace("\n", '', $stripped);
  return $stripped;
}

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: content-type");
$publication = json_decode(file_get_contents("php://input"));
if (!$publication)
  error("Unable to parse JSON post");
if (!isset($publication->schema))
  error("Unable to read schema field");

$url = 'https://directdemocracy.vote/json-schema/';
if (substr($publication->schema, 0, 41) !== $url)
  error("Wrong schema URL: " . substr($publication->schema, 0, 41));

if (substr($publication->schema, -19) != '/ballot.schema.json')
  error("Wrong schema: " . substr($publication->schema, -19));

$directdemocracy_version = substr($publication->schema, 41, -19);
if ($directdemocracy_version !== '0.0.1')
  error("Unsupported version: $directdemocracy_version");

$now = floatval(microtime(true) * 1000);  # milliseconds

# FIXME: published date should match referendum deadline
# and expires date should match the referendum deadline + 1 year.

if ($publication->expires < $now - 60000)  # allowing a 1 minute error
  error("Expiration date in the past: $publication->expires < $now");

$signature = $publication->signature;
if (isset($publication->citizen)) {
  $citizen_key = $publication->citizen->key;
  $citizen_signature = $publication->citizen->signature;
  unset($publication->citizen);
}

$publication->signature = '';
$data = json_encode($publication, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$verify = openssl_verify($data, base64_decode($signature), public_key($publication->key), OPENSSL_ALGO_SHA256);
if ($verify != 1)
  error("Wrong ballot signature");

$publication->signature = $signature;
if (isset($citizen_key)) {
  $publication->citizen = (object)['key' => $citizen_key, 'signature' => ''];
  $data = json_encode($publication, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  $verify = openssl_verify($data, base64_decode($citizen_signature), public_key($citizen_key), OPENSSL_ALGO_SHA256);
  if ($verify != 1)
    error("Wrong citizen signature");
  $publication->citizen->signature = $citizen_signature;
  if ($publication->citizen->key !== $citizen_key)
    error("Mismatch");
}

$station_key = file_get_contents('../id_rsa.pub');
if ($publication->station->key !== stripped_key($station_key))
  error("Wrong station key");

# get trustee url from publisher
$publisher = 'https://publisher.directdemocracy.vote';
$trustee = file_get_contents("$publisher/trustee_url.php?referendum=" . urlencode($publication->referendum));

if (substr($trustee, 0, 8) !== 'https://')
  die("Cannot get referendum trustee: $trustee");

# check if citizen is allowed by trustee to vote to this referendum

$allowed = file_get_contents("$trustee/can_vote.php?referendum=" . urlencode($publication->referendum) .
                             "&citizen=" . urlencode($publication->citizen->key));
if ($allowed !== 'yes')
  die("Citizen is not allowed to vote to this referendum by trustee: $allowed");
$publication->citizen->key = '';
$data = json_encode($publication, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$private_key = openssl_get_privatekey("file://../id_rsa");
if ($private_key == FALSE)
  error("Failed to read private key.");
$signature = '';
$success = openssl_sign($data, $signature, $private_key, OPENSSL_ALGO_SHA256);
openssl_free_key($private_key);
if ($success === FALSE)
  error("Failed to sign ballot.");
$publication->station->signature = base64_encode($signature);

$mysqli = new mysqli($database_host, $database_username, $database_password, $database_name);
if ($mysqli->connect_errno)
  error("Failed to connect to MySQL database: $mysqli->connect_error ($mysqli->connect_errno)");
$mysqli->set_charset('utf8mb4');

$query = "INSERT INTO ballot(`schema`, `key`, signature, published, expires, referendum, citizenKey, citizenSignature) " .
         "VALUES('$publication->schema', '$publication->key', '$publication->signature', " .
         "$publication->published, $publication->expires, '$publication->referendum', '$citizen_key', '$citizen_signature')";
$mysqli->query($query) or error($mysqli->error);

if ($citizen_key != $publication->citizen->key)
  error("Mismatching citizen keys $citizen_key != " . $publication->citizen->key);

$data = json_encode($publication, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
die("{\"ballot\":$data}");
?>
