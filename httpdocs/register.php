<?php
function error($message) {
  die("{\"error\":\"$message\"}");
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
  error("Wrong schema object" . substr($publication->schema, -19));

$directdemocracy_version = substr($publication->schema, 41, -19);
if ($directdemocracy_version !== '0.0.1')
  error("Unsupported version: $directdemocracy_version");

$now = floatval(microtime(true) * 1000);  # milliseconds
if ($publication->published > $now + 60000)  # allowing a 1 minute error
    error("Publication date in the future: $publication->published > $now");
if ($publication->expires < $now - 60000)  # allowing a 1 minute error
    error("Expiration date in the past: $publication->expires < $now");

$signature_copy = $publication->signature;
$citizen_key_copy = $publication->citizen->key;
$citizen_signature_copy = $publication->citizen->signature;
$publication->signature = '';
$publication->citizen->key = '';
$publication->citizen->signature = '';
$data = json_encode($publication, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$verify = openssl_verify($data, base64_decode($signature_copy), public_key($publication->key), OPENSSL_ALGO_SHA256);
if ($verify != 1)
  error("Wrong ballot signature");

$publication->signature = $signature_copy;
$publication->citizen->key = $citizen_key_copy;
$data = json_encode($publication, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$verify = openssl_verify($data, base64_decode($citizen_signature_copy), public_key($citizen_key_copy), OPENSSL_ALGO_SHA256);
if ($verify != 1)
  error("Wrong citizen signature");

die("{\"status\":\"success\"}");

?>
